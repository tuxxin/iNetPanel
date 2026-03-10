<?php
// FILE: api/accounts.php
// iNetPanel — Accounts API (Multi-Domain Username System)
// User-level: create_user, delete_user, list_users, get_user
// Domain-level: add_domain, remove_domain, list_domains
// Combined: create (user+domain), delete (all domains+user), suspend, resume, detail

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Normalize Shell::run result for JSON API response
function shellResult(array $r): array {
    if ($r['success']) return $r;
    return ['success' => false, 'error' => $r['output'] ?: $r['error'] ?: 'Script execution failed.'];
}

switch ($action) {

    // =========================================================================
    // USER-LEVEL ACTIONS
    // =========================================================================

    case 'list_users':
        $users = DB::fetchAll('SELECT * FROM hosting_users ORDER BY created_at DESC');
        foreach ($users as &$u) {
            $u['domains'] = DB::fetchAll(
                'SELECT domain_name, port, status FROM domains WHERE hosting_user_id = ? ORDER BY domain_name',
                [$u['id']]
            );
            $u['domain_count'] = count($u['domains']);
            $path = '/home/' . $u['username'];
            $u['disk'] = is_dir($path) ? trim(shell_exec("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1") ?: '—') : '—';
            $wg = DB::fetchOne('SELECT peer_ip FROM wg_peers WHERE hosting_user = ?', [$u['username']]);
            $u['wg_ip'] = $wg['peer_ip'] ?? null;
        }
        unset($u);

        if (!Auth::hasFullAccess()) {
            $allowed = Auth::user()['domains'] ?? [];
            $users = array_values(array_filter($users, function($u) use ($allowed) {
                foreach ($u['domains'] as $d) {
                    if (in_array($d['domain_name'], $allowed)) return true;
                }
                return false;
            }));
        }

        echo json_encode(['success' => true, 'data' => $users]);
        break;

    case 'get_user':
        $username = trim($_GET['username'] ?? '');
        if (!$username) { echo json_encode(['success' => false, 'error' => 'Username required.']); break; }

        $user = DB::fetchOne('SELECT * FROM hosting_users WHERE username = ?', [$username]);
        if (!$user) { echo json_encode(['success' => false, 'error' => 'User not found.']); break; }

        $user['domains'] = DB::fetchAll(
            'SELECT * FROM domains WHERE hosting_user_id = ? ORDER BY domain_name',
            [$user['id']]
        );
        foreach ($user['domains'] as &$d) {
            $path = '/home/' . $username . '/' . $d['domain_name'] . '/www';
            $d['disk'] = is_dir($path) ? trim(shell_exec("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1") ?: '—') : '—';
            $certFile = "/etc/letsencrypt/live/{$d['domain_name']}/fullchain.pem";
            $d['ssl'] = file_exists($certFile) ? 'active' : 'none';
        }
        unset($d);

        $wg = DB::fetchOne('SELECT peer_ip FROM wg_peers WHERE hosting_user = ?', [$username]);
        $user['wg_ip'] = $wg['peer_ip'] ?? null;
        $user['disk'] = is_dir("/home/$username") ? trim(shell_exec("du -sh " . escapeshellarg("/home/$username") . " 2>/dev/null | cut -f1") ?: '—') : '—';

        echo json_encode(['success' => true, 'data' => $user]);
        break;

    case 'create_user':
        Auth::requireAdmin();
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$username || !$password) {
            echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
            break;
        }
        if (!preg_match('/^[a-z][a-z0-9\-]{0,31}$/', $username)) {
            echo json_encode(['success' => false, 'error' => 'Invalid username. Must start with a letter, lowercase alphanumeric + hyphens, max 32 chars.']);
            break;
        }

        $result = Shell::run('create_user', ['--username' => $username, '--password' => $password]);
        if ($result['success']) {
            DB::query(
                'INSERT OR IGNORE INTO hosting_users (username, created_at) VALUES (?, datetime(\'now\'))',
                [$username]
            );
        }
        echo json_encode(shellResult($result));
        break;

    case 'delete_user':
        Auth::requireAdmin();
        $username = trim($_POST['username'] ?? '');
        if (!$username) { echo json_encode(['success' => false, 'error' => 'Username required.']); break; }

        $remaining = DB::fetchOne('SELECT COUNT(*) as cnt FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id WHERE h.username = ?', [$username]);
        if (($remaining['cnt'] ?? 0) > 0) {
            echo json_encode(['success' => false, 'error' => 'User still has domains. Remove all domains first.']);
            break;
        }

        $result = Shell::run('delete_user', ['--username' => $username]);
        if ($result['success']) {
            DB::delete('hosting_users', 'username = ?', [$username]);
            DB::delete('wg_peers', 'hosting_user = ?', [$username]);
        }
        echo json_encode(shellResult($result));
        break;

    // =========================================================================
    // DOMAIN-LEVEL ACTIONS
    // =========================================================================

    case 'add_domain':
        Auth::requireAdmin();
        $username = trim($_POST['username'] ?? '');
        $domain   = trim($_POST['domain'] ?? '');
        $phpVer   = trim($_POST['php_version'] ?? '');

        if (!$username || !$domain) {
            echo json_encode(['success' => false, 'error' => 'Username and domain are required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,61}[a-zA-Z0-9]$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain name.']);
            break;
        }

        $args = ['--username' => $username, '--domain' => $domain];
        if ($phpVer) $args['--php-version'] = $phpVer;

        $result = Shell::run('add_domain', $args);

        if ($result['success']) {
            $hostingUser = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
            $port = null;
            if (preg_match('/port[:\s]+(\d+)/i', $result['output'], $m)) {
                $port = (int)$m[1];
            }
            $docRoot = "/home/{$username}/{$domain}/www";
            DB::query(
                'INSERT OR IGNORE INTO domains (hosting_user_id, domain_name, document_root, php_version, port, status, created_at)
                 VALUES (?, ?, ?, ?, ?, \'active\', datetime(\'now\'))',
                [$hostingUser['id'] ?? null, $domain, $docRoot, $phpVer ?: 'inherit', $port]
            );
            if ($port) {
                DB::query('INSERT OR REPLACE INTO account_ports (domain_name, port) VALUES (?, ?)', [$domain, $port]);
            }
            $result['port'] = $port;

            if (DB::setting('wg_auto_peer', '0') === '1') {
                $existingPeer = DB::fetchOne('SELECT id FROM wg_peers WHERE hosting_user = ?', [$username]);
                if (!$existingPeer) {
                    Shell::run('wg_peer', ['--add', '--name' => $username]);
                }
            }

            $warnings = [];
            if ($port && DB::setting('cf_enabled', '0') === '1') {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        $cf = new CloudflareAPI();
                        $cfResult = $cf->addTunnelHostname($accountId, $tunnelId, $domain, "https://localhost:{$port}");
                        if (!empty($cfResult['dns_skipped'])) {
                            $warnings[] = "Domain added to tunnel but DNS CNAME was not created — the zone for '{$domain}' is not in your Cloudflare account. Add the domain to Cloudflare and create a CNAME record pointing to {$tunnelId}.cfargotunnel.com";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage() . ". The domain was created locally but is not routed through Cloudflare.";
                    }
                }
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        echo json_encode(shellResult($result));
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($result['success']) {
            $pv = $phpVer ?: '8.4';
            shell_exec("sudo /bin/systemctl reload php{$pv}-fpm 2>&1");
        }
        break;

    case 'remove_domain':
        Auth::requireAdmin();
        $username = trim($_POST['username'] ?? '');
        $domain   = trim($_POST['domain'] ?? '');
        $noBackup = ($_POST['no_backup'] ?? '0') === '1';

        if (!$username || !$domain) {
            echo json_encode(['success' => false, 'error' => 'Username and domain required.']);
            break;
        }

        $args = ['--username' => $username, '--domain' => $domain];
        if ($noBackup) $args[] = '--no-backup';

        $result = Shell::run('remove_domain', $args);
        if ($result['success']) {
            DB::delete('domains', 'domain_name = ?', [$domain]);
            DB::delete('account_ports', 'domain_name = ?', [$domain]);
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
        echo json_encode(shellResult($result));
        break;

    case 'list_domains':
        $username = trim($_GET['username'] ?? '');
        if (!$username) { echo json_encode(['success' => false, 'error' => 'Username required.']); break; }

        $user = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
        if (!$user) { echo json_encode(['success' => false, 'error' => 'User not found.']); break; }

        $domains = DB::fetchAll('SELECT * FROM domains WHERE hosting_user_id = ? ORDER BY domain_name', [$user['id']]);
        echo json_encode(['success' => true, 'data' => $domains]);
        break;

    // =========================================================================
    // COMBINED / CONVENIENCE ACTIONS
    // =========================================================================

    case 'create':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain']   ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $phpVer   = trim($_POST['php_version'] ?? '8.4');

        if (!$domain || !$password) {
            echo json_encode(['success' => false, 'error' => 'Domain and password are required.']);
            break;
        }

        // Auto-generate username from domain if not provided
        if (!$username) {
            $username = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));
            if (!$username || !preg_match('/^[a-z]/', $username)) {
                $username = 'u' . $username;
            }
            $username = substr($username, 0, 32);
            $base = $username;
            $i = 1;
            while (DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username])) {
                $username = substr($base, 0, 29) . $i;
                $i++;
            }
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,61}[a-zA-Z0-9]$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain name.']);
            break;
        }

        // Step 1: Create hosting user if not exists
        $existingUser = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
        if (!$existingUser) {
            $userResult = Shell::run('create_user', ['--username' => $username, '--password' => $password]);
            if (!$userResult['success']) {
                echo json_encode(['success' => false, 'error' => $userResult['output'] ?: $userResult['error'] ?: 'Failed to create user.']);
                break;
            }
            DB::query(
                'INSERT OR IGNORE INTO hosting_users (username, created_at) VALUES (?, datetime(\'now\'))',
                [$username]
            );
        }

        // Step 2: Add domain
        $result = Shell::run('add_domain', ['--username' => $username, '--domain' => $domain, '--php-version' => $phpVer]);

        if ($result['success']) {
            $hostingUser = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
            $port = null;
            if (preg_match('/port[:\s]+(\d+)/i', $result['output'], $m)) {
                $port = (int)$m[1];
            }
            $docRoot = "/home/{$username}/{$domain}/www";
            DB::query(
                'INSERT OR IGNORE INTO domains (hosting_user_id, domain_name, document_root, php_version, port, status, created_at)
                 VALUES (?, ?, ?, ?, ?, \'active\', datetime(\'now\'))',
                [$hostingUser['id'] ?? null, $domain, $docRoot, $phpVer, $port]
            );
            if ($port) {
                DB::query('INSERT OR REPLACE INTO account_ports (domain_name, port) VALUES (?, ?)', [$domain, $port]);
            }
            $result['port'] = $port;
            $result['username'] = $username;

            if (DB::setting('wg_auto_peer', '0') === '1') {
                $existingPeer = DB::fetchOne('SELECT id FROM wg_peers WHERE hosting_user = ?', [$username]);
                if (!$existingPeer) {
                    Shell::run('wg_peer', ['--add', '--name' => $username]);
                }
            }

            $warnings = [];
            if ($port && DB::setting('cf_enabled', '0') === '1') {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        $cf = new CloudflareAPI();
                        $cfResult = $cf->addTunnelHostname($accountId, $tunnelId, $domain, "https://localhost:{$port}");
                        if (!empty($cfResult['dns_skipped'])) {
                            $warnings[] = "Domain added to tunnel but DNS CNAME was not created — the zone for '{$domain}' is not in your Cloudflare account. Add the domain to Cloudflare and create a CNAME record pointing to {$tunnelId}.cfargotunnel.com";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage() . ". The domain was created locally but is not routed through Cloudflare.";
                    }
                }
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        echo json_encode(shellResult($result));
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($result['success']) {
            shell_exec("sudo /bin/systemctl reload php{$phpVer}-fpm 2>&1");
        }
        break;

    case 'delete':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain']    ?? '');
        $noBackup = ($_POST['no_backup'] ?? '0') === '1';

        if (!$domain) {
            echo json_encode(['success' => false, 'error' => 'Domain required.']);
            break;
        }

        $domainRow = DB::fetchOne('SELECT d.*, h.username FROM domains d LEFT JOIN hosting_users h ON d.hosting_user_id = h.id WHERE d.domain_name = ?', [$domain]);
        $username = $domainRow['username'] ?? $domain;

        $args = ['--username' => $username, '--domain' => $domain];
        if ($noBackup) $args[] = '--no-backup';
        $result = Shell::run('remove_domain', $args);

        if ($result['success']) {
            DB::delete('domains', 'domain_name = ?', [$domain]);
            DB::delete('account_ports', 'domain_name = ?', [$domain]);

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

            // If no more domains for this user, delete the user too
            $remaining = DB::fetchOne(
                'SELECT COUNT(*) as cnt FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id WHERE h.username = ?',
                [$username]
            );
            if (($remaining['cnt'] ?? 0) === 0) {
                Shell::run('delete_user', ['--username' => $username]);
                DB::delete('hosting_users', 'username = ?', [$username]);
                DB::delete('wg_peers', 'hosting_user = ?', [$username]);
            }
        }

        echo json_encode(shellResult($result));
        break;

    // =========================================================================
    // SUSPEND / RESUME
    // =========================================================================

    case 'suspend':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if (!$domain && !$username) { echo json_encode(['success' => false, 'error' => 'Domain or username required.']); break; }

        $args = [];
        if ($domain) $args['--domain'] = $domain;
        if ($username) $args['--username'] = $username;
        $args[] = '--suspend';

        $result = Shell::run('suspend_account', $args);
        if ($result['success']) {
            if ($domain) {
                DB::update('domains', ['status' => 'suspended'], 'domain_name = ?', [$domain]);
            } elseif ($username) {
                $user = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
                if ($user) {
                    DB::query('UPDATE domains SET status = \'suspended\' WHERE hosting_user_id = ?', [$user['id']]);
                }
            }
        }
        echo json_encode(shellResult($result));
        break;

    case 'resume':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if (!$domain && !$username) { echo json_encode(['success' => false, 'error' => 'Domain or username required.']); break; }

        $args = [];
        if ($domain) $args['--domain'] = $domain;
        if ($username) $args['--username'] = $username;
        $args[] = '--resume';

        $result = Shell::run('suspend_account', $args);
        if ($result['success']) {
            if ($domain) {
                DB::update('domains', ['status' => 'active'], 'domain_name = ?', [$domain]);
            } elseif ($username) {
                $user = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
                if ($user) {
                    DB::query('UPDATE domains SET status = \'active\' WHERE hosting_user_id = ?', [$user['id']]);
                }
            }
        }
        echo json_encode(shellResult($result));
        break;

    // =========================================================================
    // DETAIL
    // =========================================================================

    case 'detail':
        $domain = trim($_GET['domain'] ?? '');
        if (!Auth::canAccessDomain($domain)) {
            echo json_encode(['success' => false, 'error' => 'Access denied.']); break;
        }
        $row = DB::fetchOne(
            'SELECT d.*, h.username as hosting_username FROM domains d LEFT JOIN hosting_users h ON d.hosting_user_id = h.id WHERE d.domain_name = ?',
            [$domain]
        );
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Domain not found.']); break; }

        $username = $row['hosting_username'] ?? $domain;
        $path = "/home/{$username}/{$domain}/www";
        if (!is_dir($path)) $path = "/home/{$domain}/www";
        $row['disk'] = is_dir($path)
            ? trim(shell_exec("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1") ?: '—')
            : '—';
        $certFile = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        $row['ssl'] = file_exists($certFile) ? 'active' : 'none';

        echo json_encode(['success' => true, 'data' => $row]);
        break;

    // =========================================================================
    // LIST (flat domain list — legacy compat)
    // =========================================================================

    case 'list':
        $domains = DB::fetchAll(
            'SELECT d.*, h.username as hosting_username FROM domains d LEFT JOIN hosting_users h ON d.hosting_user_id = h.id ORDER BY d.created_at DESC'
        );
        $wgPeers = DB::fetchAll('SELECT hosting_user, peer_ip FROM wg_peers');
        $wgMap   = array_column($wgPeers, 'peer_ip', 'hosting_user');

        foreach ($domains as &$d) {
            $username = $d['hosting_username'] ?? $d['domain_name'];
            $path = "/home/{$username}/{$d['domain_name']}/www";
            if (!is_dir($path)) $path = "/home/{$d['domain_name']}/www";
            $d['disk'] = is_dir($path) ? trim(shell_exec("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1") ?: '—') : '—';
            $d['wg_ip'] = $wgMap[$username] ?? null;
            $d['username'] = $username;
        }
        unset($d);

        if (!Auth::hasFullAccess()) {
            $allowed = Auth::user()['domains'] ?? [];
            $domains = array_values(array_filter($domains, fn($d) => in_array($d['domain_name'], $allowed)));
        }

        echo json_encode(['success' => true, 'data' => $domains]);
        break;

    // =========================================================================
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
