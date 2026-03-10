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
        $fwState = trim(shell_exec('sudo firewall-cmd --state 2>/dev/null') ?? '');
        $fw['firewalld'] = [
            'running'      => $fwState === 'running',
            'default_zone' => trim(shell_exec('sudo firewall-cmd --get-default-zone 2>/dev/null') ?? ''),
            'ports'        => array_filter(explode(' ', trim(shell_exec('sudo firewall-cmd --list-ports 2>/dev/null') ?? ''))),
            'services'     => array_filter(explode(' ', trim(shell_exec('sudo firewall-cmd --list-services 2>/dev/null') ?? ''))),
        ];

        // Active zones
        $zonesRaw = trim(shell_exec('sudo firewall-cmd --get-active-zones 2>/dev/null') ?? '');
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
        $vpnExists = str_contains(shell_exec('sudo firewall-cmd --permanent --get-zones 2>/dev/null') ?? '', 'vpn');
        $fw['vpn_lockdown'] = $vpnExists;
        if ($vpnExists) {
            $fw['vpn_ports'] = array_filter(explode(' ', trim(shell_exec('sudo firewall-cmd --zone=vpn --list-ports 2>/dev/null') ?? '')));
            $fw['vpn_sources'] = array_filter(explode(' ', trim(shell_exec('sudo firewall-cmd --zone=vpn --list-sources 2>/dev/null') ?? '')));
        }

        // Fail2Ban status
        $f2bRunning = trim(shell_exec('systemctl is-active fail2ban 2>/dev/null') ?? '') === 'active';
        $fw['fail2ban'] = ['running' => $f2bRunning, 'jails' => []];

        if ($f2bRunning) {
            $jailsRaw = trim(shell_exec('sudo fail2ban-client status 2>/dev/null') ?? '');
            if (preg_match('/Jail list:\s*(.+)$/m', $jailsRaw, $m)) {
                $jailNames = array_map('trim', explode(',', $m[1]));
                foreach ($jailNames as $jail) {
                    if (!$jail) continue;
                    $jStatus = shell_exec('sudo fail2ban-client status ' . escapeshellarg($jail) . ' 2>/dev/null') ?? '';
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
        shell_exec('sudo systemctl enable --now firewalld 2>&1');
        shell_exec('sudo firewall-cmd --set-default-zone=drop 2>&1');
        foreach ($ports as $p) {
            shell_exec('sudo firewall-cmd --permanent --add-port=' . escapeshellarg($p) . ' 2>&1');
        }
        shell_exec('sudo firewall-cmd --permanent --zone=trusted --add-interface=lo 2>&1');
        shell_exec('sudo firewall-cmd --reload 2>&1');
        shell_exec('sudo systemctl enable --now fail2ban 2>&1');
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
        $out = shell_exec('sudo firewall-cmd --permanent --add-port=' . escapeshellarg("{$port}/{$proto}") . ' 2>&1');
        shell_exec('sudo firewall-cmd --reload 2>&1');
        echo json_encode(['success' => true, 'output' => trim($out ?? '')]);
        break;

    // -------------------------------------------------------------------------
    case 'close_port':
        $port = trim($_POST['port'] ?? '');
        $proto = trim($_POST['protocol'] ?? 'tcp');
        if (!$port || !preg_match('/^\d+$/', $port) || !in_array($proto, ['tcp', 'udp'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid port or protocol.']);
            break;
        }
        $out = shell_exec('sudo firewall-cmd --permanent --remove-port=' . escapeshellarg("{$port}/{$proto}") . ' 2>&1');
        shell_exec('sudo firewall-cmd --reload 2>&1');
        echo json_encode(['success' => true, 'output' => trim($out ?? '')]);
        break;

    // -------------------------------------------------------------------------
    case 'reload':
        $out = shell_exec('sudo firewall-cmd --reload 2>&1');
        echo json_encode(['success' => true, 'output' => trim($out ?? '')]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_ban':
        $ip   = trim($_POST['ip'] ?? '');
        $jail = trim($_POST['jail'] ?? 'sshd');
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'error' => 'Invalid IP address.']);
            break;
        }
        $out = shell_exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' banip ' . escapeshellarg($ip) . ' 2>&1');
        echo json_encode(['success' => true, 'output' => trim($out ?? '')]);
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
            $out = shell_exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' unbanip ' . escapeshellarg($ip) . ' 2>&1');
        } else {
            // Unban from all jails
            $out = shell_exec('sudo fail2ban-client unban ' . escapeshellarg($ip) . ' 2>&1');
        }
        echo json_encode(['success' => true, 'output' => trim($out ?? '')]);
        break;

    // -------------------------------------------------------------------------
    case 'f2b_flush':
        $out = shell_exec('sudo fail2ban-client unban --all 2>&1');
        echo json_encode(['success' => true, 'output' => trim($out ?? '')]);
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
        shell_exec('sudo cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local 2>&1');
        unlink('/tmp/inetpanel_jail.local');
        shell_exec('sudo systemctl reload fail2ban 2>&1');
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
        $content = file_get_contents($jailLocal);
        $content = preg_replace_callback('/^(ignoreip\s*=\s*)(.+)$/m', function($matches) use ($ip) {
            $ips = array_filter(array_map('trim', preg_split('/[\s,]+/', $matches[2])), fn($v) => $v !== $ip);
            return $matches[1] . implode(' ', $ips);
        }, $content);
        file_put_contents('/tmp/inetpanel_jail.local', $content);
        shell_exec('sudo cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local 2>&1');
        unlink('/tmp/inetpanel_jail.local');
        shell_exec('sudo systemctl reload fail2ban 2>&1');
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
        $content = file_get_contents($jailLocal);
        $content = preg_replace('/^bantime\s*=\s*\d+/m',  "bantime  = {$bantime}",  $content);
        $content = preg_replace('/^findtime\s*=\s*\d+/m', "findtime = {$findtime}", $content);
        $content = preg_replace('/^maxretry\s*=\s*\d+/m', "maxretry = {$maxretry}", $content);
        file_put_contents('/tmp/inetpanel_jail.local', $content);
        shell_exec('sudo cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local 2>&1');
        unlink('/tmp/inetpanel_jail.local');
        shell_exec('sudo systemctl reload fail2ban 2>&1');
        echo json_encode(['success' => true]);
        break;

    // -------------------------------------------------------------------------
    case 'set_ssh_port':
        $port = (int)($_POST['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            echo json_encode(['success' => false, 'error' => 'Invalid port (1-65535).']);
            break;
        }
        $out = shell_exec('sudo /root/scripts/update_ssh_port.sh --port ' . escapeshellarg($port) . ' 2>&1');
        if ($out !== null) {
            DB::saveSetting('ssh_port', (string)$port);
            echo json_encode(['success' => true, 'output' => trim($out)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update SSH port.']);
        }
        break;

    // -------------------------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
