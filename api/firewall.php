<?php
// FILE: api/firewall.php
// iNetPanel — Firewall API (firewalld + fail2ban)
// All actions require admin access.

Auth::requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Classify a webhook URL. Returns 'discord', 'slack', 'generic', or '' if the
 * URL is unusable. Used for validation only — the actual POST is done by
 * qsa_scan.sh so there is exactly one delivery implementation.
 */
function qsa_webhook_kind(string $url): string
{
    if (!preg_match('~^https://~i', $url)) return '';   // plaintext would leak the report
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') return '';
    if (preg_match('/(^|\.)discord(app)?\.com$/', $host))  return 'discord';
    if (preg_match('/(^|\.)slack\.com$/', $host))          return 'slack';
    return 'generic';
}

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
        $lock = fopen('/tmp/inetpanel_jail.lock', 'w');
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
            file_put_contents('/tmp/inetpanel_jail.local', $content);
            Shell::exec('sudo /bin/cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local', 'fail2ban-whitelist-add');
            unlink('/tmp/inetpanel_jail.local');
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
        $lock = fopen('/tmp/inetpanel_jail.lock', 'w');
        if (flock($lock, LOCK_EX)) {
            $content = file_get_contents($jailLocal);
            $content = preg_replace_callback('/^(ignoreip\s*=\s*)(.+)$/m', function($matches) use ($ip) {
                $ips = array_filter(array_map('trim', preg_split('/[\s,]+/', $matches[2])), fn($v) => $v !== $ip);
                return $matches[1] . implode(' ', $ips);
            }, $content);
            file_put_contents('/tmp/inetpanel_jail.local', $content);
            Shell::exec('sudo /bin/cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local', 'fail2ban-whitelist-remove');
            unlink('/tmp/inetpanel_jail.local');
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
        $lock = fopen('/tmp/inetpanel_jail.lock', 'w');
        if (flock($lock, LOCK_EX)) {
            $content = file_get_contents($jailLocal);
            $content = preg_replace('/^bantime\s*=\s*\d+/m',  "bantime  = {$bantime}",  $content);
            $content = preg_replace('/^findtime\s*=\s*\d+/m', "findtime = {$findtime}", $content);
            $content = preg_replace('/^maxretry\s*=\s*\d+/m', "maxretry = {$maxretry}", $content);
            file_put_contents('/tmp/inetpanel_jail.local', $content);
            Shell::exec('sudo /bin/cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local', 'fail2ban-settings-save');
            unlink('/tmp/inetpanel_jail.local');
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
    // from what firewalld was told to open. A port exposed by a container or
    // upstream of this host only shows up here.
    case 'qsa_scan':
        // The free tier takes ~30s and paid tiers minutes, so give PHP room. The
        // script enforces its own per-tier curl deadline; this is just the outer
        // bound so a hung request cannot pin an FPM worker indefinitely.
        @set_time_limit(1260);
        $r = Shell::run('qsa_scan', ['--quiet']);
        $out = $r['output'] ?? '';

        // A server whose /usr/local/bin/inetp predates this feature has no
        // qsa_scan case, so the dispatcher falls through to *) and prints its
        // whole usage listing. Echoing that verbatim dumps the inetp help into
        // the terminal pane and looks like the scan "worked". Detect it and say
        // what is actually wrong instead.
        if (preg_match('/^\s*inetp\s+[-—]\s+Server Account Manager|Usage:\s*inetp\s+<command>/mi', $out)) {
            // Two different faults produce identical symptoms, and they need
            // opposite fixes, so distinguish them instead of guessing. The
            // deploy step copies PANEL_PATH/scripts/system/inetp over
            // /usr/local/bin/inetp — so compare the two directly.
            $src     = '/var/www/inetpanel/scripts/system/inetp';
            $srcHas  = is_readable($src)
                       && strpos((string) file_get_contents($src), 'qsa_scan)') !== false;

            if ($srcHas) {
                $msg = "This server's inetp command is out of date.\n\n"
                     . "The panel files are current — /var/www/inetpanel/scripts/system/inetp\n"
                     . "does support qsa_scan — but the copy of it at /usr/local/bin/inetp was\n"
                     . "never replaced, so the scan request fell through to the usage text you\n"
                     . "would otherwise be reading here.\n\n"
                     . "Use \"Repair CLI\" below to re-run just the file-deployment step.";
                $repair = true;
            } else {
                $msg = "This server's panel files are older than they appear.\n\n"
                     . "/var/www/inetpanel/scripts/system/inetp has no qsa_scan support, so the\n"
                     . "update that added this feature has not fully landed. Repairing the CLI\n"
                     . "would only redeploy the same old file.\n\n"
                     . "Run a full update first:\n"
                     . "    sudo php /var/www/inetpanel/scripts/panel_update.php --force";
                $repair = false;
            }
            echo json_encode([
                'success'   => false,
                'stale_cli' => true,
                'repairable'=> $repair,
                'output'    => $msg,
            ]);
            break;
        }

        // Rate limiting is the common case on the free tier (1 scan / 24h), so
        // surface qsa.sh's own wording rather than a generic failure.
        $limited = (bool) preg_match('/daily scan allowance|rate.?limit|try again in/i', $out);
        echo json_encode([
            'success'      => $r['success'] || $limited,
            'rate_limited' => $limited,
            'output'       => $out,
            'tier'         => DB::setting('qsa_token', '') !== '' ? 'token' : 'free',
        ]);
        break;

    case 'qsa_settings_get':
        $cron = '/etc/cron.d/inetpanel_qsa_monitor';
        echo json_encode([
            'success'      => true,
            // Never return the token itself — only whether one is set.
            'has_token'    => DB::setting('qsa_token', '') !== '',
            'monitor'      => file_exists($cron),
            'schedule'     => DB::setting('qsa_monitor_schedule', 'daily'),
            'last_run'     => DB::setting('qsa_last_run', ''),
            'last_change'  => DB::setting('qsa_last_change', ''),
            'webhook_set'  => DB::setting('qsa_webhook_url', '') !== '',
            'webhook_kind' => DB::setting('qsa_webhook_kind', ''),
            'unacked'      => DB::setting('qsa_change_unacked', '0') === '1',
        ]);
        break;

    case 'qsa_settings_save':
        $token    = trim($_POST['qsa_token'] ?? '');
        $monitor  = ($_POST['monitor'] ?? '0') === '1';
        $schedule = in_array($_POST['schedule'] ?? 'daily', ['hourly', 'daily', 'weekly'], true)
                  ? $_POST['schedule'] : 'daily';

        // An empty field means "leave as-is", so a saved token is not wiped by
        // saving the form back with the masked placeholder still in it.
        if ($token !== '' && $token !== '********') {
            if (!preg_match('/^[A-Za-z0-9._-]{8,128}$/', $token)) {
                echo json_encode(['success' => false, 'error' => 'That does not look like a qsa.sh token.']);
                break;
            }
            DB::saveSetting('qsa_token', $token);
        }
        DB::saveSetting('qsa_monitor_schedule', $schedule);

        // Webhook delivery. There is no MTA on a stock iNetPanel box, so a
        // webhook is the only way a change notice reaches you when you are not
        // looking at the panel. Empty string clears it.
        if (array_key_exists('webhook_url', $_POST)) {
            $hook = trim($_POST['webhook_url']);
            if ($hook === '') {
                DB::saveSetting('qsa_webhook_url', '');
                DB::saveSetting('qsa_webhook_kind', '');
            } else {
                $kind = qsa_webhook_kind($hook);
                if ($kind === '') {
                    echo json_encode(['success' => false,
                        'error' => 'Webhook must be an https:// Discord, Slack, or generic JSON endpoint.']);
                    break;
                }
                DB::saveSetting('qsa_webhook_url', $hook);
                DB::saveSetting('qsa_webhook_kind', $kind);
            }
        }

        // The diff checker is just a cron entry around `qsa_scan --monitor`.
        // Free tier is 1 scan/24h, so refuse a schedule the tier cannot sustain —
        // otherwise every run 429s and the monitor silently never has a baseline.
        $hasToken = DB::setting('qsa_token', '') !== '';
        if ($monitor && $schedule === 'hourly' && !$hasToken) {
            echo json_encode(['success' => false,
                'error' => 'Hourly monitoring needs a qsa.sh token — the free tier allows one scan per 24h.']);
            break;
        }
        $spec = ['hourly' => '17 * * * *', 'daily' => '17 4 * * *', 'weekly' => '17 4 * * 1'][$schedule];
        $cron = '/etc/cron.d/inetpanel_qsa_monitor';
        $tmp  = tempnam('/tmp', 'inetp_cron_');
        if ($monitor) {
            file_put_contents($tmp,
                "# iNetPanel qsa.sh exposure monitor — auto-managed by the Firewall page\n"
              . "{$spec} root /root/scripts/qsa_scan.sh --monitor --quiet >> /var/log/qsa-monitor.log 2>&1\n");
            Shell::exec('sudo /root/scripts/manage_cron.sh write inetpanel_qsa_monitor < ' . escapeshellarg($tmp), 'qsa-cron-write');
        } else {
            Shell::exec('sudo /root/scripts/manage_cron.sh remove inetpanel_qsa_monitor', 'qsa-cron-remove');
        }
        @unlink($tmp);
        echo json_encode(['success' => true, 'monitor' => $monitor && file_exists($cron)]);
        break;

    case 'qsa_repair_cli':
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
        $fixed = strpos((string) $now, 'qsa_scan') !== false;
        echo json_encode([
            'success' => $fixed,
            'message' => $fixed
                ? 'CLI redeployed — run the scan again.'
                : 'Redeploy ran but the CLI still lacks qsa_scan. Check /var/log/inetpanel_update.log.',
            'log'     => implode("\n", array_slice(explode("\n", trim((string) $r['output'])), -8)),
        ]);
        break;

    case 'qsa_webhook_test':
        $hook = trim($_POST['webhook_url'] ?? '');
        if ($hook === '') { $hook = DB::setting('qsa_webhook_url', ''); }
        if ($hook === '') {
            echo json_encode(['success' => false, 'error' => 'No webhook URL configured.']);
            break;
        }
        if (qsa_webhook_kind($hook) === '') {
            echo json_encode(['success' => false, 'error' => 'Not a recognised webhook URL.']);
            break;
        }
        // Deliberately delivered by qsa_scan.sh rather than re-implemented here:
        // the monitor runs from cron as root and posts from bash, so a PHP-side
        // lookalike could pass while the real notification path is broken. The
        // URL is persisted first so the script reads the same value cron will.
        DB::saveSetting('qsa_webhook_url', $hook);
        DB::saveSetting('qsa_webhook_kind', qsa_webhook_kind($hook));
        $r = Shell::run('qsa_scan', ['--test-webhook']);
        echo json_encode([
            'success' => $r['success'],
            'message' => trim($r['output'] ?: 'Test message delivered.'),
            'error'   => $r['success'] ? '' : trim($r['output'] ?: 'Delivery failed.'),
        ]);
        break;

    case 'qsa_ack':
        // Dismiss the panel-wide banner. The logs row stays for the audit trail.
        DB::saveSetting('qsa_change_unacked', '0');
        echo json_encode(['success' => true]);
        break;

    case 'qsa_diff':
        $r = Shell::run('qsa_scan', ['--diff']);
        echo json_encode([
            'success' => true,
            'diff'    => $r['output'] ?? '',
            'has_history' => $r['success'],
        ]);
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
