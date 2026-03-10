<?php
// FILE: api/settings.php
// iNetPanel — Settings API
// Actions: get, save, wg_toggle, ddns_test


$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'get':
        // Return all settings (mask sensitive values for sub-admins)
        $rows   = DB::fetchAll('SELECT key, value FROM settings');
        $result = [];
        foreach ($rows as $r) {
            $result[$r['key']] = $r['value'];
        }
        if (!Auth::hasFullAccess()) {
            unset($result['cf_api_key'], $result['cf_email']);
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
        ];
        $saved = [];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                // Don't overwrite CF API key with the masked placeholder value
                if ($key === 'cf_api_key' && preg_match('/^\*+$/', trim($_POST[$key]))) {
                    continue;
                }
                DB::saveSetting($key, $_POST[$key]);
                $saved[] = $key;
            }
        }
        // Apply timezone to PHP runtime and OS if changed
        if (in_array('timezone', $saved)) {
            $tz = DB::setting('timezone', 'UTC');
            if (in_array($tz, DateTimeZone::listIdentifiers())) {
                date_default_timezone_set($tz);
                shell_exec('sudo /usr/bin/timedatectl set-timezone ' . escapeshellarg($tz) . ' 2>&1');
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
                $proc = popen('sudo /root/scripts/manage_cron.sh write inetpanel_ddns', 'w');
                fwrite($proc, $cron); pclose($proc);
            } else {
                shell_exec('sudo /root/scripts/manage_cron.sh remove inetpanel_ddns 2>&1');
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
            $proc = popen('sudo /root/scripts/manage_cron.sh write lamp_update', 'w');
            fwrite($proc, $cronContent); pclose($proc);

            // Backup cron
            $backupTime = DB::setting('backup_cron_time', '03:00');
            [$bHour, $bMin] = array_pad(explode(':', $backupTime), 2, '00');
            $backupCron = "# iNetPanel managed — account backups\n"
                . "{$bMin} {$bHour} * * * root /root/scripts/backup_accounts.sh >> /var/log/lamp_backup.log 2>&1\n";
            $proc = popen('sudo /root/scripts/manage_cron.sh write lamp_backup', 'w');
            fwrite($proc, $backupCron); pclose($proc);

            // Panel auto-update cron
            $autoEnabled = DB::setting('auto_update_enabled', '0');
            $autoTime    = DB::setting('auto_update_time', '02:00');
            [$aHour, $aMin] = array_pad(explode(':', $autoTime), 2, '00');
            if ($autoEnabled === '1') {
                $phpBin2  = 'php' . DB::setting('php_default_version', '8.4');
                $autoCron = "# iNetPanel managed — panel auto-update\n"
                    . "{$aMin} {$aHour} * * * www-data {$phpBin2} /var/www/inetpanel/scripts/panel_update.php >> /var/log/inetpanel_update.log 2>&1\n";
                $proc = popen('sudo /root/scripts/manage_cron.sh write inetpanel_autoupdate', 'w');
                fwrite($proc, $autoCron); pclose($proc);
            } else {
                shell_exec('sudo /root/scripts/manage_cron.sh remove inetpanel_autoupdate 2>&1');
            }
        }
        echo json_encode(['success' => true, 'saved' => $saved]);
        break;

    case 'update_now':
        Auth::requireAdmin();
        $output = shell_exec('php /var/www/inetpanel/scripts/panel_update.php --force 2>&1');
        echo json_encode(['success' => true, 'output' => trim($output ?: 'No output.')]);
        break;

    case 'check_updates':
        Auth::requireAdmin();
        // Force refresh version cache from GitHub
        $ch = curl_init('https://api.github.com/repos/tuxxin/inetpanel/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['User-Agent: iNetPanel/' . Version::get()],
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
                shell_exec('sudo /root/scripts/cloudflared_setup.sh --action install --token ' . escapeshellarg($tunnelToken) . ' 2>&1');
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
        shell_exec('sudo /usr/bin/hostnamectl set-hostname ' . escapeshellarg($hostname) . ' 2>&1');
        // Update /etc/hosts — replace old hostname with new one
        $oldHostname = gethostname();
        if ($oldHostname && $oldHostname !== $hostname) {
            $hosts = file_get_contents('/etc/hosts');
            if ($hosts !== false) {
                $hosts = str_replace($oldHostname, $hostname, $hosts);
                file_put_contents('/tmp/inetpanel_hosts', $hosts);
                shell_exec('sudo cp /tmp/inetpanel_hosts /etc/hosts 2>&1');
                unlink('/tmp/inetpanel_hosts');
            }
        }
        DB::saveSetting('server_hostname', $hostname);
        echo json_encode(['success' => true]);
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
        $output = shell_exec("{$phpBin} /var/www/inetpanel/scripts/ddns_update.php 2>&1");
        echo json_encode(['success' => true, 'output' => trim($output ?: 'No output')]);
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
