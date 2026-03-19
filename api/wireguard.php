<?php
// FILE: api/wireguard.php
// iNetPanel — WireGuard API
// Actions: status, toggle, list_peers, add_peer, remove_peer, get_peer_config, auto_configure_all


Auth::requireAdmin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------------
    // STATUS — WireGuard service + server info
    // ------------------------------------------------------------------
    case 'status':
        $active   = Shell::isServiceActive('wg-quick@wg0');
        $pubkey   = '';
        $endpoint = DB::setting('wg_endpoint', '');
        $port     = DB::setting('wg_port', '51820');
        $subnet   = DB::setting('wg_subnet', '10.10.0.0/24');

        if ($active && file_exists('/etc/wireguard/wg0.conf')) {
            $privkey = trim(shell_exec("grep '^PrivateKey' /etc/wireguard/wg0.conf | awk '{print $3}' 2>/dev/null") ?? '');
            if ($privkey) {
                $pubkey = trim(shell_exec("echo " . escapeshellarg($privkey) . " | wg pubkey 2>/dev/null") ?? '');
            }
        }

        $peerCount = DB::fetchOne('SELECT COUNT(*) as cnt FROM wg_peers')['cnt'] ?? 0;

        echo json_encode([
            'success'    => true,
            'active'     => $active,
            'public_key' => $pubkey,
            'endpoint'   => $endpoint,
            'port'       => $port,
            'subnet'     => $subnet,
            'peer_count' => (int)$peerCount,
            'auto_peer'  => (DB::setting('wg_auto_peer', '0') === '1'),
        ]);
        break;

    // ------------------------------------------------------------------
    // TOGGLE — start or stop wg-quick@wg0
    // ------------------------------------------------------------------
    case 'toggle':
        $active = Shell::isServiceActive('wg-quick@wg0');
        $cmd    = $active ? 'stop' : 'start';
        $result = Shell::systemctl($cmd, 'wg-quick@wg0');

        DB::saveSetting('wg_enabled', $active ? '0' : '1');

        echo json_encode([
            'success' => $result['success'],
            'active'  => !$active,
            'output'  => $result['output'],
            'error'   => $result['error'],
        ]);
        break;

    // ------------------------------------------------------------------
    // LIST PEERS
    // ------------------------------------------------------------------
    case 'list_peers':
        $peers = DB::fetchAll('SELECT * FROM wg_peers ORDER BY created_at DESC');
        echo json_encode(['success' => true, 'data' => $peers]);
        break;

    // ------------------------------------------------------------------
    // ADD PEER
    // ------------------------------------------------------------------
    case 'add_peer':
        $name = trim($_POST['name'] ?? '');
        if (!$name || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid peer name.']); break;
        }

        $result = Shell::run('wg_peer', ['--add', '--name', $name]);

        if ($result['success']) {
            // Output is the full client .conf (for QR rendering)
            echo json_encode([
                'success' => true,
                'config'  => $result['output'],
                'name'    => $name,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => $result['error'] ?: 'Failed to add peer.',
                'output'  => $result['output'],
            ]);
        }
        break;

    // ------------------------------------------------------------------
    // REMOVE PEER
    // ------------------------------------------------------------------
    case 'remove_peer':
        $name = trim($_POST['name'] ?? '');
        if (!$name || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid peer name.']); break;
        }

        $existing = DB::fetchOne('SELECT id FROM wg_peers WHERE hosting_user = ?', [$name]);
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'Peer does not exist.']); break;
        }

        $result = Shell::run('wg_peer', ['--remove', '--name', $name]);
        if ($result['success']) {
            DB::delete('wg_peers', 'hosting_user = ?', [$name]);
        }

        echo json_encode([
            'success' => $result['success'],
            'output'  => $result['output'],
            'error'   => $result['error'],
        ]);
        break;

    // ------------------------------------------------------------------
    // GET PEER CONFIG (for QR code / download)
    // ------------------------------------------------------------------
    case 'get_peer_config':
        $name = trim($_GET['name'] ?? '');
        if (!$name || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid peer name.']); break;
        }

        $confPath = '/etc/wireguard/peers/' . $name . '.conf';
        if (!file_exists($confPath)) {
            echo json_encode(['success' => false, 'error' => 'Peer config not found.']); break;
        }

        $config = @file_get_contents($confPath);
        if ($config === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to read peer config file.']); break;
        }
        echo json_encode(['success' => true, 'config' => $config, 'name' => $name]);
        break;

    // ------------------------------------------------------------------
    // AUTO CONFIGURE ALL — generate peers for every domain without one
    // ------------------------------------------------------------------
    case 'auto_configure_all':
        $users = DB::fetchAll("
            SELECT DISTINCT h.username
            FROM hosting_users h
            JOIN domains d ON d.hosting_user_id = h.id
            LEFT JOIN wg_peers w ON w.hosting_user = h.username
            WHERE d.status = 'active' AND w.id IS NULL
        ");

        $added  = [];
        $errors = [];

        foreach ($users as $row) {
            $dname  = $row['username'];
            $result = Shell::run('wg_peer', ['--add', '--name', $dname]);
            if ($result['success']) {
                $added[] = $dname;
            } else {
                $errors[$dname] = $result['error'] ?: 'Unknown error';
            }
        }

        // Save setting
        DB::saveSetting('wg_auto_peer', '1');

        echo json_encode([
            'success' => true,
            'added'   => $added,
            'errors'  => $errors,
            'count'   => count($added),
        ]);
        break;

    // ------------------------------------------------------------------
    // SET AUTO PEER toggle
    // ------------------------------------------------------------------
    case 'set_auto_peer':
        $enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
        DB::saveSetting('wg_auto_peer', $enabled);
        echo json_encode(['success' => true, 'auto_peer' => ($enabled === '1')]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
