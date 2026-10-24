<?php
// FILE: api/firewall.php
// iNetPanel — Firewall API (firewalld + fail2ban)
// All actions require admin access.

Auth::requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // -------------------------------------------------------------------------
    case 'status':
        $fw = [];

        // Firewalld status
        $fwState = trim(Shell::exec('sudo firewall-cmd --state 2>/dev/null', 'firewall-state')['output']);
        $defaultZone = trim(Shell::exec('sudo firewall-cmd --get-default-zone 2>/dev/null', 'firewall-default-zone')['output']);
        // Query permanent config for the default zone to avoid runtime warnings
        $portsRaw = trim(Shell::exec('sudo firewall-cmd --permanent --zone=' . escapeshellarg($defaultZone) . ' --list-ports 2>/dev/null', 'firewall-list-ports')['output']);
        $servicesRaw = trim(Shell::exec('sudo firewall-cmd --permanent --zone=' . escapeshellarg($defaultZone) . ' --list-services 2>/dev/null', 'firewall-list-services')['output']);
        // Filter out any non-port entries (firewalld warnings)
        $ports = array_values(array_filter(explode(' ', $portsRaw), fn($p) => preg_match('#^\d+(-\d+)?/(tcp|udp)$#', $p)));
        $services = array_values(array_filter(explode(' ', $servicesRaw), fn($s) => $s && !str_contains($s, ' ') && !str_contains($s, "'")));
        $fw['firewalld'] = [
            'running'      => $fwState === 'running',
            'default_zone' => $defaultZone,
            'ports'        => $ports,
            'services'     => $services,
        ];

        // Active zones
        $zonesRaw = trim(Shell::exec('sudo firewall-cmd --get-active-zones 2>/dev/null', 'firewall-active-zones')['output']);
        $zones = [];
        $currentZone = null;
        foreach (explode("\n", $zonesRaw) as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (!str_starts_with($line, 'interfaces:') && !str_starts_with($line, 'sources:')) {
                $currentZone = $line;
                $zones[$currentZone] = ['interfaces' => [], 'sources' => []];
            } elseif ($currentZone) {
                if (str_starts_with($line, 'interfaces:')) {
                    $zones[$currentZone]['interfaces'] = array_filter(explode(' ', trim(substr($line, 11))));
                } elseif (str_starts_with($line, 'sources:')) {
                    $zones[$currentZone]['sources'] = array_filter(explode(' ', trim(substr($line, 8))));
                }
            }
        }
        $fw['zones'] = $zones;

        // VPN zone ports (if exists)
        $vpnExists = str_contains(Shell::exec('sudo firewall-cmd --permanent --get-zones 2>/dev/null', 'firewall-get-zones')['output'], 'vpn');
        $fw['vpn_lockdown'] = $vpnExists;
        if ($vpnExists) {
            $fw['vpn_ports'] = array_filter(explode(' ', trim(Shell::exec('sudo firewall-cmd --zone=vpn --list-ports 2>/dev/null', 'firewall-vpn-ports')['output'])));
            $fw['vpn_sources'] = array_filter(explode(' ', trim(Shell::exec('sudo firewall-cmd --zone=vpn --list-sources 2>/dev/null', 'firewall-vpn-sources')['output'])));
        }

        // Fail2Ban status
        $f2bRunning = trim(Shell::exec('systemctl is-active fail2ban 2>/dev/null', 'fail2ban-status')['output']) === 'active';
        $fw['fail2ban'] = ['running' => $f2bRunning, 'jails' => []];

        if ($f2bRunning) {
            $jailsRaw = trim(Shell::exec('sudo fail2ban-client status 2>/dev/null', 'fail2ban-jail-list')['output']);
            if (preg_match('/Jail list:\s*(.+)$/m', $jailsRaw, $m)) {
                $jailNames = array_map('trim', explode(',', $m[1]));
                foreach ($jailNames as $jail) {
                    if (!$jail) continue;
                    $jStatus = Shell::exec('sudo fail2ban-client status ' . escapeshellarg($jail) . ' 2>/dev/null', 'fail2ban-jail-status')['output'];
                    $banned = 0;
                    $total = 0;
                    $bannedIps = [];
                    if (preg_match('/Currently banned:\s*(\d+)/', $jStatus, $bm)) $banned = (int)$bm[1];
                    if (preg_match('/Total banned:\s*(\d+)/', $jStatus, $tm)) $total = (int)$tm[1];
                    if (preg_match('/Banned IP list:\s*(.+)$/m', $jStatus, $ipm)) {
                        $bannedIps = array_filter(array_map('trim', explode(' ', $ipm[1])));
                    }
                    $fw['fail2ban']['jails'][$jail] = [
                        'banned'     => $banned,
                        'total'      => $total,
                        'banned_ips' => array_values($bannedIps),
                    ];
                }
            }
        }

        echo json_encode(['success' => true, 'data' => $fw]);
        break;

    // -------------------------------------------------------------------------
    case 'auto_configure':
        $sshPort = DB::setting('ssh_port', '1022');
        $ports = [
            "{$sshPort}/tcp",
            '20/tcp',
            '21/tcp',
            '80/tcp',
            '8888/tcp',
        ];
        if (DB::setting('wg_enabled', '0') === '1') {
            $wgPort = DB::setting('wg_port', '1443');
            $ports[] = "{$wgPort}/udp";
        }
        Shell::exec('sudo /bin/systemctl enable --now firewalld', 'firewall-enable');
        Shell::exec('sudo firewall-cmd --set-default-zone=drop', 'firewall-set-default-zone');
        foreach ($ports as $p) {
            Shell::exec('sudo firewall-cmd --permanent --add-port=' . escapeshellarg($p) . '', 'firewall-open-port');
        }
        Shell::exec('sudo firewall-cmd --permanent --zone=trusted --add-interface=lo', 'firewall-trust-loopback');
        Shell::exec('sudo firewall-cmd --reload', 'firewall-reload');
        Shell::exec('sudo /bin/systemctl enable --now fail2ban', 'fail2ban-enable');
        echo json_encode(['success' => true, 'ports' => $ports]);
        break;

    // -------------------------------------------------------------------------
    case 'open_port':
        $port = trim($_POST['port'] ?? '');
        $proto = trim($_POST['protocol'] ?? 'tcp');
        if (!$port || !preg_match('/^\d+$/', $port) || !in_array($proto, ['tcp', 'udp'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid port or protocol.']);
            break;
        }
        $out = Shell::exec('sudo firewall-cmd --permanent --add-port=' . escapeshellarg("{$port}/{$proto}"), 'firewall-open-port');
        Shell::exec('sudo firewall-cmd --reload', 'firewall-reload');
        echo json_encode(['success' => $out['success'], 'output' => trim($out['output'])]);
        break;

    // -------------------------------------------------------------------------
    case 'close_port':
        $port = trim($_POST['port'] ?? '');
        $proto = trim($_POST['protocol'] ?? 'tcp');
        if (!$port || !preg_match('/^\d+$/', $port) || !in_array($proto, ['tcp', 'udp'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid port or protocol.']);
            break;
        }
        $out = Shell::exec('sudo firewall-cmd --permanent --remove-port=' . escapeshellarg("{$port}/{$proto}"), 'firewall-close-port');
        Shell::exec('sudo firewall-cmd --reload', 'firewall-reload');
        echo json_encode(['success' => $out['success'], 'output' => trim($out['output'])]);
        break;

    // -------------------------------------------------------------------------
    case 'reload':
        $out = Shell::exec('sudo firewall-cmd --reload', 'firewall-reload');
        echo json_encode(['success' => $out['success'], 'output' => trim($out['output'])]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_ban':
        $ip   = trim($_POST['ip'] ?? '');
        $jail = trim($_POST['jail'] ?? 'sshd');
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP address.']);
            break;
        }
        $out = Shell::exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' banip ' . escapeshellarg($ip), 'fail2ban-ban');
        echo json_encode(['success' => $out['success'], 'output' => trim($out['output'])]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_unban':
        $ip   = trim($_POST['ip'] ?? '');
        $jail = trim($_POST['jail'] ?? '');
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP address.']);
            break;
        }
        if ($jail) {
            $out = Shell::exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' unbanip ' . escapeshellarg($ip), 'fail2ban-unban');
        } else {
            // Unban from all jails
            $out = Shell::exec('sudo fail2ban-client unban ' . escapeshellarg($ip), 'fail2ban-unban-all');
        }
        echo json_encode(['success' => $out['success'], 'output' => trim($out['output'])]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_flush':
        $out = Shell::exec('sudo fail2ban-client unban --all', 'fail2ban-flush');
        echo json_encode(['success' => $out['success'], 'output' => trim($out['output'])]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_whitelist_get':
        $whitelist = [];
        $jailLocal = '/etc/fail2ban/jail.local';
        if (file_exists($jailLocal)) {
            $content = file_get_contents($jailLocal);
            if (preg_match('/^\[DEFAULT\].*?^ignoreip\s*=\s*(.+)$/ms', $content, $m)) {
                $whitelist = array_filter(array_map('trim', preg_split('/[\s,]+/', $m[1])));
            }
        }
        echo json_encode(['success' => true, 'data' => array_values($whitelist)]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_whitelist_add':
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip || (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('#^\d+\.\d+\.\d+\.\d+/\d+$#', $ip))) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP or CIDR.']);
            break;
        }
        $jailLocal = '/etc/fail2ban/jail.local';
        if (!file_exists($jailLocal)) {
            echo json_encode(['success' => false, 'error' => 'jail.local not found.']);
            break;
        }
        $lock = fopen(Shell::STAGE_DIR . '/inetpanel_jail.lock', 'c');
        if (flock($lock, LOCK_EX)) {
            $content = file_get_contents($jailLocal);
            if (preg_match('/^(ignoreip\s*=\s*)(.+)$/m', $content, $m)) {
                $existing = trim($m[2]);
                if (!str_contains($existing, $ip)) {
                    $newLine = $m[1] . $existing . ' ' . $ip;
                    $content = str_replace($m[0], $newLine, $content);
                }
            } else {
                // Add ignoreip after [DEFAULT]
                $content = preg_replace('/^(\[DEFAULT\].*)$/m', "$1\nignoreip = 127.0.0.1/8 ::1 {$ip}", $content, 1);
            }
            $jailStage = Shell::stage('inetpanel_jail.local', $content);
            Shell::exec('sudo /bin/cp ' . escapeshellarg($jailStage) . ' /etc/fail2ban/jail.local', 'jail-write');
            @unlink($jailStage);
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        Shell::exec('sudo /bin/systemctl reload fail2ban', 'fail2ban-reload');
        echo json_encode(['success' => true]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_whitelist_remove':
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip) {
            echo json_encode(['success' => false, 'error' => 'IP required.']);
            break;
        }
        $jailLocal = '/etc/fail2ban/jail.local';
        if (!file_exists($jailLocal)) {
            echo json_encode(['success' => false, 'error' => 'jail.local not found.']);
            break;
        }
        $lock = fopen(Shell::STAGE_DIR . '/inetpanel_jail.lock', 'c');
        if (flock($lock, LOCK_EX)) {
            $content = file_get_contents($jailLocal);
            $content = preg_replace_callback('/^(ignoreip\s*=\s*)(.+)$/m', function($matches) use ($ip) {
                $ips = array_filter(array_map('trim', preg_split('/[\s,]+/', $matches[2])), fn($v) => $v !== $ip);
                return $matches[1] . implode(' ', $ips);
            }, $content);
            $jailStage = Shell::stage('inetpanel_jail.local', $content);
            Shell::exec('sudo /bin/cp ' . escapeshellarg($jailStage) . ' /etc/fail2ban/jail.local', 'jail-write');
            @unlink($jailStage);
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        Shell::exec('sudo /bin/systemctl reload fail2ban', 'fail2ban-reload');
        echo json_encode(['success' => true]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_settings_get':
        $settings = ['bantime' => '3600', 'findtime' => '600', 'maxretry' => '5'];
        $jailLocal = '/etc/fail2ban/jail.local';
        if (file_exists($jailLocal)) {
            $content = file_get_contents($jailLocal);
            // Extract from [DEFAULT] section
            if (preg_match('/^bantime\s*=\s*(\d+)/m', $content, $m))  $settings['bantime']  = $m[1];
            if (preg_match('/^findtime\s*=\s*(\d+)/m', $content, $m)) $settings['findtime'] = $m[1];
            if (preg_match('/^maxretry\s*=\s*(\d+)/m', $content, $m)) $settings['maxretry'] = $m[1];
        }
        echo json_encode(['success' => true, 'data' => $settings]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_settings_save':
        $bantime  = (int)($_POST['bantime']  ?? 3600);
        $findtime = (int)($_POST['findtime'] ?? 600);
        $maxretry = (int)($_POST['maxretry'] ?? 5);
        if ($bantime < 60 || $findtime < 60 || $maxretry < 1) {
            echo json_encode(['success' => false, 'error' => 'Invalid values.']);
            break;
        }
        $jailLocal = '/etc/fail2ban/jail.local';
        if (!file_exists($jailLocal)) {
            echo json_encode(['success' => false, 'error' => 'jail.local not found.']);
            break;
        }
        $lock = fopen(Shell::STAGE_DIR . '/inetpanel_jail.lock', 'c');
        if (flock($lock, LOCK_EX)) {
            $content = file_get_contents($jailLocal);
            $content = preg_replace('/^bantime\s*=\s*\d+/m',  "bantime  = {$bantime}",  $content);
            $content = preg_replace('/^findtime\s*=\s*\d+/m', "findtime = {$findtime}", $content);
            $content = preg_replace('/^maxretry\s*=\s*\d+/m', "maxretry = {$maxretry}", $content);
            $jailStage = Shell::stage('inetpanel_jail.local', $content);
            Shell::exec('sudo /bin/cp ' . escapeshellarg($jailStage) . ' /etc/fail2ban/jail.local', 'jail-write');
            @unlink($jailStage);
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        Shell::exec('sudo /bin/systemctl reload fail2ban', 'fail2ban-reload');
        echo json_encode(['success' => true]);
        break;

    // -------------------------------------------------------------------------
    // ===================== qsa.sh exposure scan =====================
    // qsa.sh scans the public IP that calls it, so running it from this server
    // shows what the internet actually sees of this box — a different question
    // from what firewalld was told to open. The work is done by `inetp tsvc qsa`
    // (scripts/system/tuxxin_services.py): the scan runs in its own systemd unit,
    // and the archive is root-only, so every read goes through the helper too.
    case 'qsa_scan':
        if (!TuxxinServices::requireWrite()) break;
        if (!TuxxinServices::enabled('qsa')) {
            echo json_encode(['success' => false, 'disabled' => true,
                'error' => 'The External Security Audit is turned off in Settings → Tuxxin Services.']);
            break;
        }
        $r = TuxxinServices::helper(['qsa', 'start'], null, true);
        if (!empty($r['stale_cli'])) {
            // The deploy step copies PANEL_PATH/scripts/system/inetp over
            // /usr/local/bin/inetp. If the panel's copy knows tsvc, only the
            // deploy step needs re-running; otherwise the update never landed.
            $src = '/var/www/inetpanel/scripts/system/inetp';
            $r['repairable'] = is_readable($src) && strpos((string) file_get_contents($src), 'tsvc)') !== false;
        }
        echo json_encode($r);
        break;

    case 'qsa_status':
        // Polled every two seconds while a scan runs: Shell::query(), which does
        // not copy each answer — live scan output — into the logs table.
        echo json_encode(TuxxinServices::helper(['qsa', 'status']));
        break;

    case 'qsa_settings_get':
        TuxxinServices::ensureMigrated();
        echo json_encode([
            'success'      => true,
            'enabled'      => TuxxinServices::enabled('qsa'),
            // Never the token or the URL — only whether one is saved.
            'has_token'    => TuxxinServices::hasSecret('qsa_token'),
            'token_tier'   => (string) DB::setting('qsa_token_tier', ''),
            'monitor'      => DB::setting('qsa_monitor', '0') === '1',
            'monitor_active' => file_exists('/etc/cron.d/inetpanel_qsa_monitor'),
            'schedule'     => DB::setting('qsa_monitor_schedule', 'daily'),
            'last_run'     => DB::setting('qsa_last_run', ''),
            'last_change'  => DB::setting('qsa_last_change', ''),
            'webhook_set'  => TuxxinServices::hasSecret('webhook_url'),
            'webhook_kind' => DB::setting('tsvc_webhook_kind', ''),
            'unacked'      => DB::setting('qsa_change_unacked', '0') === '1',
        ]);
        break;

    case 'qsa_settings_save':
        if (!TuxxinServices::requireWrite()) break;
        $token    = trim($_POST['qsa_token'] ?? '');
        $monitor  = ($_POST['monitor'] ?? '0') === '1';
        $schedule = in_array($_POST['schedule'] ?? 'daily', ['hourly', 'daily', 'weekly'], true)
                  ? $_POST['schedule'] : 'daily';

        // An empty field means "leave as-is", so a saved token is not wiped by
        // saving the form back with the masked placeholder still in it.
        if ($token !== '' && $token !== '********') {
            $r = TuxxinServices::helper(['set-secret', 'qsa_token'], $token);
            if (!$r['success']) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Could not save the token.']);
                break;
            }
        }
        if (array_key_exists('webhook_url', $_POST)) {
            $hook = trim($_POST['webhook_url']);
            $r = $hook === ''
                ? TuxxinServices::helper(['clear-secret', 'webhook_url'], null, true)
                : TuxxinServices::helper(['set-secret', 'webhook_url'], $hook);
            if (!$r['success']) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Could not save the webhook.']);
                break;
            }
        }

        // Which schedules a token can sustain (free: 1 scan / 24h; a Deep token
        // spends a credit per scan; an unconfirmed token might be Deep) is decided
        // in one place, the helper's sync-cron. The admin's choice is saved either
        // way and the page shows why it is not scheduled.
        DB::query("INSERT INTO settings (key, value, category, updated_at) VALUES ('qsa_monitor', ?, 'security', datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at", [$monitor ? '1' : '0']);
        DB::query("INSERT INTO settings (key, value, category, updated_at) VALUES ('qsa_monitor_schedule', ?, 'security', datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at", [$schedule]);
        $c = TuxxinServices::helper(['qsa', 'sync-cron'], null, true);
        echo json_encode(['success' => true, 'monitor' => !empty($c['active']), 'reason' => $c['reason'] ?? '']);
        break;

    case 'qsa_clear_token':
        if (!TuxxinServices::requireWrite()) break;
        $r = TuxxinServices::helper(['clear-secret', 'qsa_token'], null, true);
        echo json_encode(['success' => $r['success'], 'error' => $r['error'] ?? '']);
        break;

    case 'qsa_history':
        echo json_encode(TuxxinServices::helper(['qsa', 'history']));
        break;

    case 'qsa_view':
        // The helper validates the id against its own archive listing; no path
        // is ever built from user input.
        $want = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
        if (!preg_match('/^scan-\d{8}T\d{6}z$/', $want)) {
            echo json_encode(['success' => false, 'error' => 'No such scan in the archive.']);
            break;
        }
        echo json_encode(TuxxinServices::helper(['qsa', 'show', $want]));
        break;

    case 'qsa_repair_cli':
        if (!TuxxinServices::requireWrite()) break;
        // Re-runs only the deployment phase of panel_update.php — no download,
        // no rsync, no migrations. Already covered by the existing sudoers
        // grant for panel_update.php, so this adds no new privilege.
        @set_time_limit(300);
        // Mirror api/settings.php's update action exactly. Do NOT use PHP_BINARY:
        // under FPM that resolves to /usr/sbin/php-fpm8.x, which does not match
        // the sudoers rule (/usr/bin/php* ... panel_update.php *) and the repair
        // would be denied. A bare "php8.5" lets sudo resolve it via PATH to
        // /usr/bin/php8.5, which does match.
        $phpBin = 'php' . DB::setting('php_default_version', '8.5');
        if (!preg_match('/^php\d+\.\d+$/', $phpBin)) {
            $phpBin = 'php';
        }
        $r = Shell::exec('sudo ' . escapeshellarg($phpBin)
             . ' /var/www/inetpanel/scripts/panel_update.php --deploy-only', 'qsa-repair-cli');
        $now = shell_exec('/usr/local/bin/inetp 2>&1');
        $fixed = strpos((string) $now, 'tsvc') !== false;
        echo json_encode([
            'success' => $fixed,
            'message' => $fixed
                ? 'CLI redeployed — run the scan again.'
                : 'Redeploy ran but the CLI still lacks tsvc. Check /var/log/inetpanel_update.log.',
            'log'     => implode("\n", array_slice(explode("\n", trim((string) $r['output'])), -8)),
        ]);
        break;

    case 'qsa_webhook_test':
        if (!TuxxinServices::requireWrite()) break;
        // Delivered by the same helper the monitor uses, so the test exercises the
        // real path. A URL typed into the field is saved first.
        $hook = trim($_POST['webhook_url'] ?? '');
        if ($hook !== '') {
            $r = TuxxinServices::helper(['set-secret', 'webhook_url'], $hook);
            if (!$r['success']) {
                echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Not a usable webhook URL.']);
                break;
            }
        }
        $r = TuxxinServices::helper(['notify-test'], null, true);
        echo json_encode(['success' => $r['success'], 'message' => $r['message'] ?? '', 'error' => $r['error'] ?? '']);
        break;

    case 'qsa_ack':
        if (!TuxxinServices::requireWrite()) break;
        // Dismiss the panel-wide banner. The logs row stays for the audit trail.
        DB::saveSetting('qsa_change_unacked', '0');
        echo json_encode(['success' => true]);
        break;

    case 'qsa_diff':
        $r = TuxxinServices::helper(['qsa', 'diff']);
        echo json_encode(['success' => true, 'diff' => $r['diff'] ?? '', 'has_history' => !empty($r['has_history'])]);
        break;

    case 'set_ssh_port':
        $port = (int)($_POST['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            echo json_encode(['success' => false, 'error' => 'Invalid port (1-65535).']);
            break;
        }
        $out = Shell::exec('sudo /root/scripts/update_ssh_port.sh --port ' . escapeshellarg($port), 'update-ssh-port');
        if ($out['success']) {
            DB::saveSetting('ssh_port', (string)$port);
            echo json_encode(['success' => true, 'output' => trim($out['output'])]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update SSH port.', 'output' => trim($out['output'])]);
        }
        break;

    // -------------------------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
