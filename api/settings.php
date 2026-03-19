<?php
// FILE: api/settings.php
// iNetPanel — Settings API
// Actions: get, save, wg_toggle, ddns_test


$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper: write a cron file via manage_cron.sh with error logging
$writeCron = function(string $name, string $content): bool {
    $proc = popen('sudo /root/scripts/manage_cron.sh write ' . escapeshellarg($name), 'w');
    if ($proc === false) {
        error_log("iNetPanel: failed to open pipe for cron {$name}");
        return false;
    }
    fwrite($proc, $content);
    $exit = pclose($proc);
    if ($exit !== 0) {
        error_log("iNetPanel: cron write for {$name} exited with {$exit}");
        return false;
    }
    return true;
};

switch ($action) {

    case 'get':
        // Return all settings (mask sensitive values for sub-admins)
        $rows   = DB::fetchAll('SELECT key, value FROM settings');
        $result = [];
        foreach ($rows as $r) {
            $result[$r['key']] = $r['value'];
        }
        if (!Auth::hasFullAccess()) {
            unset($result['cf_api_key'], $result['cf_email'], $result['github_token']);
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'save':
        Auth::requireAdmin();
        $allowed = [
            'panel_name', 'server_hostname', 'timezone', 'admin_email', 'default_theme', 'cf_account_id',
            'backup_enabled', 'backup_destination', 'backup_retention',
            'cf_enabled', 'cf_email', 'cf_api_key',
            'cf_ddns_enabled', 'cf_ddns_hostname', 'cf_ddns_zone_id', 'cf_ddns_interval',
            'wg_enabled', 'wg_port', 'wg_subnet', 'wg_endpoint', 'wg_auto_peer', 'ssh_port',
            // Cron schedule settings
            'update_cron_enabled', 'update_cron_time',
            'backup_cron_time',
            'auto_update_enabled', 'auto_update_time',
            'github_token',
        ];
        $saved = [];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                // Don't overwrite sensitive keys with the masked placeholder value
                if (in_array($key, ['cf_api_key', 'github_token']) && preg_match('/^\*+$/', trim($_POST[$key]))) {
                    continue;
                }
                DB::saveSetting($key, $_POST[$key]);
                $saved[] = $key;
            }
        }
        // Apply timezone to PHP runtime, OS, and MariaDB if changed
        if (in_array('timezone', $saved)) {
            $tz = DB::setting('timezone', 'UTC');
            if (in_array($tz, DateTimeZone::listIdentifiers())) {
                date_default_timezone_set($tz);
                Shell::exec('sudo /usr/bin/timedatectl set-timezone ' . escapeshellarg($tz), 'timezone');
                // Update MariaDB timezone (runtime + persistent config)
                $mysqlPass = trim(@file_get_contents('/root/.mysql_root_pass') ?: '');
                Shell::exec('mysql -u root -p' . escapeshellarg($mysqlPass) . ' -e ' . escapeshellarg("SET GLOBAL time_zone = '{$tz}'") . ' 2>&1', 'mysql-timezone');
                @file_put_contents('/tmp/inetp_tz.cnf', "[mysqld]\ndefault_time_zone = {$tz}\n");
                Shell::exec('sudo /bin/cp /tmp/inetp_tz.cnf /etc/mysql/mariadb.conf.d/99-timezone.cnf', 'mysql-tz-conf');
            }
        }
        // If CF credentials were saved, validate them
        if (in_array('cf_api_key', $saved)) {
            $cf = new CloudflareAPI();
            if (!$cf->validateCredentials()) {
                echo json_encode(['success' => false, 'error' => 'Cloudflare credentials are invalid.']);
                break;
            }
        }
        // Update DDNS cron if interval/enabled changed
        if (in_array('cf_ddns_interval', $saved) || in_array('cf_ddns_enabled', $saved)) {
            $enabled  = DB::setting('cf_ddns_enabled', '0');
            $interval = (int)DB::setting('cf_ddns_interval', '5');
            if ($enabled === '1' && $interval > 0) {
                $phpBin = 'php' . DB::setting('php_default_version', '8.4');
                $cron = "*/{$interval} * * * * www-data {$phpBin} /var/www/inetpanel/scripts/ddns_update.php >> /var/log/inetpanel_ddns.log 2>&1\n";
                $writeCron('inetpanel_ddns', $cron);
            } else {
                Shell::exec('sudo /root/scripts/manage_cron.sh remove inetpanel_ddns', 'cron-ddns-remove');
            }
        }
        // Rebuild system update cron (/etc/cron.d/lamp_update) if schedule changed
        $cronKeys = ['update_cron_enabled', 'update_cron_time', 'backup_cron_time', 'auto_update_enabled', 'auto_update_time'];
        if (array_intersect($cronKeys, $saved)) {
            // System update cron
            $updateEnabled = DB::setting('update_cron_enabled', '1');
            $updateTime    = DB::setting('update_cron_time', '00:00');
            [$uHour, $uMin] = array_pad(explode(':', $updateTime), 2, '00');
            if ($updateEnabled === '1') {
                $cronContent = "# iNetPanel managed — system package updates\n"
                    . "{$uMin} {$uHour} * * * root /root/scripts/inetp-update.sh >> /var/log/lamp_update.log 2>&1\n";
            } else {
                $cronContent = "# iNetPanel managed — system package updates (disabled)\n";
            }
            $writeCron('lamp_update', $cronContent);

            // Backup cron
            $backupTime = DB::setting('backup_cron_time', '03:00');
            [$bHour, $bMin] = array_pad(explode(':', $backupTime), 2, '00');
            $backupCron = "# iNetPanel managed — account backups\n"
                . "{$bMin} {$bHour} * * * root /root/scripts/backup_accounts.sh >> /var/log/lamp_backup.log 2>&1\n";
            $writeCron('lamp_backup', $backupCron);

            // Panel auto-update cron
            $autoEnabled = DB::setting('auto_update_enabled', '0');
            $autoTime    = DB::setting('auto_update_time', '02:00');
            [$aHour, $aMin] = array_pad(explode(':', $autoTime), 2, '00');
            if ($autoEnabled === '1') {
                $phpBin2  = 'php' . DB::setting('php_default_version', '8.4');
                $autoCron = "# iNetPanel managed — panel auto-update\n"
                    . "{$aMin} {$aHour} * * * root {$phpBin2} /var/www/inetpanel/scripts/panel_update.php >> /var/log/inetpanel_update.log 2>&1\n";
                $writeCron('inetpanel_autoupdate', $autoCron);
            } else {
                Shell::exec('sudo /root/scripts/manage_cron.sh remove inetpanel_autoupdate', 'cron-autoupdate-remove');
            }
        }
        echo json_encode(['success' => true, 'saved' => $saved]);
        break;

    case 'update_now':
        Auth::requireAdmin();
        $phpBin = 'php' . DB::setting('php_default_version', '8.4');
        $updateResult = Shell::exec('sudo ' . escapeshellarg($phpBin) . ' /var/www/inetpanel/scripts/panel_update.php --force', 'panel-update');
        $output = $updateResult['output'];
        $changelog = DB::setting('panel_latest_changelog', '');
        echo json_encode(['success' => true, 'output' => trim($output ?: 'No output.'), 'changelog' => $changelog]);
        break;

    case 'check_updates':
        Auth::requireAdmin();
        // Force refresh version cache from GitHub
        $ch = curl_init('https://api.github.com/repos/tuxxin/inetpanel/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => Version::githubHeaders(),
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            echo json_encode(['success' => false, 'error' => 'GitHub API unreachable.']);
            break;
        }
        $release = json_decode($raw, true);
        $latest  = ltrim($release['tag_name'] ?? '', 'v');
        if (!$latest) {
            echo json_encode(['success' => false, 'error' => 'Invalid GitHub response.']);
            break;
        }
        DB::saveSetting('panel_latest_ver', $latest);
        DB::saveSetting('panel_check_ts',   (string) time());
        $current = Version::get();
        echo json_encode([
            'success'          => true,
            'current'          => $current,
            'latest'           => $latest,
            'update_available' => version_compare($latest, $current, '>'),
        ]);
        break;

    case 'setup_tunnel':
        Auth::requireAdmin();
        $accountId = DB::setting('cf_account_id', '');
        if (!$accountId) {
            echo json_encode(['success' => false, 'error' => 'CF Account ID not configured.']);
            break;
        }
        $cf = new CloudflareAPI();
        try {
            $hostname   = DB::setting('server_hostname', gethostname());
            $tunnelName = 'iNetPanel_' . (preg_replace('/[^a-zA-Z0-9_-]/', '', $hostname) ?: 'panel');
            $tunnel     = $cf->createTunnel($accountId, $tunnelName);
            $tunnelId   = $tunnel['result']['id'] ?? '';
            if (!$tunnelId) {
                $msg = $tunnel['errors'][0]['message'] ?? 'Tunnel creation failed.';
                echo json_encode(['success' => false, 'error' => $msg]);
                break;
            }
            $tunnelToken = $cf->getTunnelToken($accountId, $tunnelId) ?: '';
            DB::saveSetting('cf_tunnel_id',    $tunnelId);
            DB::saveSetting('cf_tunnel_token', $tunnelToken);
            if ($tunnelToken) {
                Shell::exec('sudo /root/scripts/cloudflared_setup.sh --action install --token ' . escapeshellarg($tunnelToken), 'cloudflared-install');
            }
            echo json_encode(['success' => true, 'tunnel_id' => $tunnelId]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'set_hostname':
        Auth::requireAdmin();
        $hostname = trim($_POST['hostname'] ?? '');
        if (!$hostname || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]*$/', $hostname)) {
            echo json_encode(['success' => false, 'error' => 'Invalid hostname.']);
            break;
        }
        // Update system hostname
        $oldHostname = gethostname();
        Shell::exec('sudo /usr/bin/hostnamectl set-hostname ' . escapeshellarg($hostname), 'hostname');
        // Update /etc/hosts — replace old hostname with new one
        if ($oldHostname && $oldHostname !== $hostname) {
            $hosts = file_get_contents('/etc/hosts');
            if ($hosts !== false) {
                $hosts = str_replace($oldHostname, $hostname, $hosts);
                file_put_contents('/tmp/inetpanel_hosts', $hosts);
                Shell::exec('sudo /bin/cp /tmp/inetpanel_hosts /etc/hosts', 'hostname-hosts');
                @unlink('/tmp/inetpanel_hosts');
            }
        }
        DB::saveSetting('server_hostname', $hostname);

        // Auto-issue SSL for panel services (LE first, self-signed fallback)
        $sslIssued = false;
        if (strpos($hostname, '.') !== false) {
            $result = Shell::run('panel_ssl', [$hostname]);
            $sslIssued = $result['success'];
        }
        // Update MOTD with new hostname
        // Use hostname -I for internal/local IP (not the public-facing route IP)
        $serverIp = trim(explode(' ', shell_exec("hostname -I 2>/dev/null") ?: '')[0]);
        if (!$serverIp) $serverIp = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
        $motdHost = $hostname ?: $serverIp;
        $motdContent = "\n"
            . "  iNetPanel — by Tuxxin.com\n"
            . "  ───────────────────────────────\n"
            . "  Admin Panel:    https://{$motdHost}/admin\n"
            . "  Client Portal:  https://{$motdHost}/user\n"
            . "  phpMyAdmin:     https://{$motdHost}:8443\n"
            . "  ───────────────────────────────\n"
            . "  Run  inetp --help  for CLI commands\n"
            . "  ───────────────────────────────\n\n";
        file_put_contents('/tmp/inetp_motd', $motdContent);
        shell_exec('sudo /bin/cp /tmp/inetp_motd /etc/motd 2>/dev/null');
        @unlink('/tmp/inetp_motd');

        echo json_encode(['success' => true, 'ssl_issued' => $sslIssued]);
        break;

    case 'detect_ip':
        Auth::requireAdmin();
        $ip = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
        if (!$ip) $ip = trim(shell_exec("hostname -I | awk '{print \$1}'") ?: '');
        $isPrivate = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        echo json_encode(['success' => true, 'ip' => $ip, 'is_private' => $isPrivate]);
        break;

    case 'verify_hostname':
        Auth::requireAdmin();
        $hostname = trim($_POST['hostname'] ?? '');
        $cfEnabled = DB::setting('cf_enabled', '0') === '1';
        if (!$hostname || !$cfEnabled) {
            echo json_encode(['exists' => false, 'zone_found' => false]);
            break;
        }
        $cf = new CloudflareAPI();
        try {
            $zoneId = $cf->findZoneForHostname($hostname);
            if (!$zoneId) {
                echo json_encode(['exists' => false, 'zone_found' => false]);
                break;
            }
            $records = $cf->listDNSRecords($zoneId, ['type' => 'A', 'name' => $hostname]);
            if (!empty($records['result'])) {
                echo json_encode(['exists' => true, 'zone_found' => true, 'ip' => $records['result'][0]['content'], 'zone_id' => $zoneId]);
            } else {
                echo json_encode(['exists' => false, 'zone_found' => true, 'zone_id' => $zoneId]);
            }
        } catch (Throwable $e) {
            echo json_encode(['exists' => false, 'zone_found' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'create_hostname_dns':
        Auth::requireAdmin();
        $hostname = trim($_POST['hostname'] ?? '');
        $cfEnabled = DB::setting('cf_enabled', '0') === '1';
        if (!$hostname || !$cfEnabled) {
            echo json_encode(['success' => false, 'message' => 'Cloudflare not configured.']);
            break;
        }
        $cf = new CloudflareAPI();
        $serverIp = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
        try {
            $zoneId = $cf->findZoneForHostname($hostname);
            if (!$zoneId) {
                echo json_encode(['success' => false, 'message' => 'Zone not found.']);
                break;
            }
            $result = $cf->createDNSRecord($zoneId, [
                'type' => 'A', 'name' => $hostname, 'content' => $serverIp, 'proxied' => true, 'ttl' => 1,
            ]);
            echo json_encode(['success' => $result['success'] ?? false, 'message' => $result['errors'][0]['message'] ?? '']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'reboot':
        Auth::requireAdmin();
        echo json_encode(['success' => true]);
        // Flush output before reboot
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        shell_exec('sudo /sbin/reboot &');
        break;

    case 'ddns_test':
        Auth::requireAdmin();
        // Force an immediate DDNS update attempt
        $phpBin = 'php' . DB::setting('php_default_version', '8.4');
        if (!preg_match('/^php\d+\.\d+$/', $phpBin)) {
            echo json_encode(['success' => false, 'error' => 'Invalid PHP version in settings.']);
            break;
        }
        $result = Shell::exec(escapeshellarg($phpBin) . ' /var/www/inetpanel/scripts/ddns_update.php', 'ddns-test');
        echo json_encode(['success' => true, 'output' => trim($result['output'] ?: 'No output')]);
        break;

    case 'wg_toggle':
        Auth::requireAdmin();
        $enable = ($_POST['enable'] ?? '0') === '1';
        if ($enable) {
            $result = Shell::systemctl('start', 'wg-quick@wg0');
        } else {
            $result = Shell::systemctl('stop', 'wg-quick@wg0');
        }
        DB::saveSetting('wg_enabled', $enable ? '1' : '0');
        echo json_encode($result);
        break;

    case 'wg_auto_peer':
        Auth::requireAdmin();
        $enable = ($_POST['enable'] ?? '0') === '1';
        DB::saveSetting('wg_auto_peer', $enable ? '1' : '0');

        if ($enable) {
            // Generate peers for all existing accounts that don't have one
            $users = DB::fetchAll('SELECT DISTINCT h.username FROM hosting_users h JOIN domains d ON d.hosting_user_id = h.id WHERE d.status = \'active\'');
            $existing = array_column(DB::fetchAll('SELECT hosting_user FROM wg_peers'), 'hosting_user');
            $results  = [];
            foreach ($users as $u) {
                if (!in_array($u['username'], $existing)) {
                    $r = Shell::run('wg_peer', ['--add', '--name' => $u['username']]);
                    $results[$u['username']] = $r['success'];
                }
            }
            echo json_encode(['success' => true, 'generated' => $results]);
        } else {
            echo json_encode(['success' => true]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
