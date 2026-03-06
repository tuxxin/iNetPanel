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
        if (!Auth::isAdmin()) {
            unset($result['cf_api_key'], $result['cf_email']);
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'save':
        Auth::requireAdmin();
        $allowed = [
            'server_hostname', 'timezone', 'admin_email', 'default_theme',
            'backup_enabled', 'backup_destination', 'backup_retention',
            'cf_enabled', 'cf_email', 'cf_api_key',
            'cf_ddns_enabled', 'cf_ddns_hostname', 'cf_ddns_zone_id', 'cf_ddns_interval',
            'wg_enabled', 'wg_port', 'wg_subnet', 'wg_endpoint', 'wg_auto_peer',
            // Cron schedule settings
            'update_cron_enabled', 'update_cron_time',
            'backup_cron_time',
            'auto_update_enabled', 'auto_update_time',
        ];
        $saved = [];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                DB::saveSetting($key, $_POST[$key]);
                $saved[] = $key;
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
                $cron = "*/{$interval} * * * * www-data php /var/www/inetpanel/scripts/ddns_update.php >> /var/log/inetpanel_ddns.log 2>&1\n";
                @file_put_contents('/etc/cron.d/inetpanel_ddns', $cron);
            } else {
                @unlink('/etc/cron.d/inetpanel_ddns');
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
            @file_put_contents('/etc/cron.d/lamp_update', $cronContent);

            // Backup cron
            $backupTime = DB::setting('backup_cron_time', '03:00');
            [$bHour, $bMin] = array_pad(explode(':', $backupTime), 2, '00');
            $backupCron = "# iNetPanel managed — account backups\n"
                . "{$bMin} {$bHour} * * * root /root/scripts/backup_accounts.sh >> /var/log/lamp_backup.log 2>&1\n";
            @file_put_contents('/etc/cron.d/lamp_backup', $backupCron);

            // Panel auto-update cron
            $autoEnabled = DB::setting('auto_update_enabled', '0');
            $autoTime    = DB::setting('auto_update_time', '02:00');
            [$aHour, $aMin] = array_pad(explode(':', $autoTime), 2, '00');
            if ($autoEnabled === '1') {
                $autoCron = "# iNetPanel managed — panel auto-update\n"
                    . "{$aMin} {$aHour} * * * www-data php /var/www/inetpanel/scripts/panel_update.php >> /var/log/inetpanel_update.log 2>&1\n";
                @file_put_contents('/etc/cron.d/inetpanel_autoupdate', $autoCron);
            } else {
                @unlink('/etc/cron.d/inetpanel_autoupdate');
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

    case 'ddns_test':
        Auth::requireAdmin();
        // Force an immediate DDNS update attempt
        $output = shell_exec('php /var/www/inetpanel/scripts/ddns_update.php 2>&1');
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
            $domains = DB::fetchAll('SELECT domain_name FROM domains WHERE status = \'active\'');
            $existing = array_column(DB::fetchAll('SELECT domain_name FROM wg_peers'), 'domain_name');
            $results  = [];
            foreach ($domains as $d) {
                if (!in_array($d['domain_name'], $existing)) {
                    $r = Shell::run('wg_peer', ['--add', '--name' => $d['domain_name']]);
                    $results[$d['domain_name']] = $r['success'];
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
