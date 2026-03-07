<?php
// FILE: api/accounts.php
// iNetPanel — Accounts API
// Actions: list, create, delete, suspend, resume, detail


$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // -------------------------------------------------------------------------
    case 'list':
        $domains = DB::fetchAll('SELECT * FROM domains ORDER BY created_at DESC');
        $wgPeers = DB::fetchAll('SELECT domain_name, peer_ip FROM wg_peers');
        $wgMap   = array_column($wgPeers, 'peer_ip', 'domain_name');

        foreach ($domains as &$d) {
            // Disk usage
            $path = '/home/' . $d['domain_name'] . '/www';
            $d['disk'] = is_dir($path) ? trim(shell_exec("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1") ?: '—') : '—';
            // WG peer
            $d['wg_ip'] = $wgMap[$d['domain_name']] ?? null;
            // Auth filter for sub-admins
            if (!Auth::isAdmin() && !Auth::canAccessDomain($d['domain_name'])) {
                continue;
            }
        }
        unset($d);

        if (!Auth::isAdmin()) {
            $allowed = Auth::user()['domains'] ?? [];
            $domains = array_values(array_filter($domains, fn($d) => in_array($d['domain_name'], $allowed)));
        }

        echo json_encode(['success' => true, 'data' => $domains]);
        break;

    // -------------------------------------------------------------------------
    case 'create':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain']   ?? '');
        $password = trim($_POST['password'] ?? '');
        $phpVer   = trim($_POST['php_version'] ?? '8.4');

        if (!$domain || !$password) {
            echo json_encode(['success' => false, 'error' => 'Domain and password are required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,61}[a-zA-Z0-9]$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain name.']);
            break;
        }

        $result = Shell::run('create_account', ['--domain' => $domain, '--password' => $password, '--php-version' => $phpVer]);

        if ($result['success']) {
            // Detect assigned port
            $port = null;
            if (preg_match('/port[:\s]+(\d+)/i', $result['output'], $m)) {
                $port = (int)$m[1];
            }
            $docRoot = "/home/{$domain}/www";
            DB::query(
                'INSERT OR IGNORE INTO domains (domain_name, document_root, php_version, port, status, created_at)
                 VALUES (?, ?, ?, ?, \'active\', datetime(\'now\'))',
                [$domain, $docRoot, $phpVer, $port]
            );
            if ($port) {
                DB::query('INSERT OR REPLACE INTO account_ports (domain_name, port) VALUES (?, ?)', [$domain, $port]);
            }
            // Auto-generate WG peer if enabled
            if (DB::setting('wg_auto_peer', '0') === '1') {
                Shell::run('wg_peer', ['--add', '--name' => $domain]);
            }
            // Add Cloudflare Zero Trust tunnel public hostname
            if ($port && DB::setting('cf_enabled', '0') === '1') {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        $cf = new CloudflareAPI();
                        $cf->addTunnelHostname($accountId, $tunnelId, $domain, "http://localhost:{$port}");
                    } catch (Throwable) {}
                }
            }
        }

        // Flush response to browser before reloading PHP-FPM (which would otherwise
        // kill this worker mid-request since create_account.sh triggers a pool reload).
        echo json_encode($result);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($result['success']) {
            shell_exec("sudo /bin/systemctl reload php{$phpVer}-fpm 2>&1");
        }
        break;

    // -------------------------------------------------------------------------
    case 'delete':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain']    ?? '');
        $noBackup = ($_POST['no_backup'] ?? '0') === '1';

        if (!$domain) {
            echo json_encode(['success' => false, 'error' => 'Domain required.']);
            break;
        }

        $args = ['--domain' => $domain, '--confirm'];
        if ($noBackup) {
            $args[] = '--no-backup';
        }
        $result = Shell::run('delete_account', $args);

        if ($result['success']) {
            DB::delete('domains',      'domain_name = ?', [$domain]);
            DB::delete('account_ports', 'domain_name = ?', [$domain]);
            DB::delete('wg_peers',     'domain_name = ?', [$domain]);
            // Remove Cloudflare Zero Trust tunnel public hostname
            if (DB::setting('cf_enabled', '0') === '1') {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        $cf = new CloudflareAPI();
                        $cf->removeTunnelHostname($accountId, $tunnelId, $domain);
                    } catch (Throwable) {}
                }
            }
        }

        echo json_encode($result);
        break;

    // -------------------------------------------------------------------------
    case 'suspend':
        Auth::requireAdmin();
        $domain = trim($_POST['domain'] ?? '');
        if (!$domain) { echo json_encode(['success' => false, 'error' => 'Domain required.']); break; }

        $result = Shell::run('suspend_account', ['--domain' => $domain, '--suspend']);
        if ($result['success']) {
            DB::update('domains', ['status' => 'suspended'], 'domain_name = ?', [$domain]);
        }
        echo json_encode($result);
        break;

    // -------------------------------------------------------------------------
    case 'resume':
        Auth::requireAdmin();
        $domain = trim($_POST['domain'] ?? '');
        if (!$domain) { echo json_encode(['success' => false, 'error' => 'Domain required.']); break; }

        $result = Shell::run('suspend_account', ['--domain' => $domain, '--resume']);
        if ($result['success']) {
            DB::update('domains', ['status' => 'active'], 'domain_name = ?', [$domain]);
        }
        echo json_encode($result);
        break;

    // -------------------------------------------------------------------------
    case 'detail':
        $domain = trim($_GET['domain'] ?? '');
        if (!Auth::canAccessDomain($domain)) {
            echo json_encode(['success' => false, 'error' => 'Access denied.']); break;
        }
        $row = DB::fetchOne('SELECT * FROM domains WHERE domain_name = ?', [$domain]);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Domain not found.']); break; }

        $path = '/home/' . $domain . '/www';
        $row['disk'] = is_dir($path)
            ? trim(shell_exec("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1") ?: '—')
            : '—';
        echo json_encode(['success' => true, 'data' => $row]);
        break;

    // -------------------------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
