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
        $skipCf   = ($_POST['skip_cf'] ?? '0') === '1';
        $cfActive = DB::setting('cf_enabled', '0') === '1' && !$skipCf;

        if (!$username || !$domain) {
            echo json_encode(['success' => false, 'error' => 'Username and domain are required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,61}[a-zA-Z0-9]$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain name.']);
            break;
        }

        // Pre-flight: check tunnel route conflict
        if ($cfActive) {
            $tunnelId  = DB::setting('cf_tunnel_id',  '');
            $accountId = DB::setting('cf_account_id', '');
            if ($tunnelId && $accountId) {
                try {
                    $cf = new CloudflareAPI();
                    $routed = $cf->getRoutedHostnames($accountId, $tunnelId);
                    if (isset($routed[$domain])) {
                        echo json_encode(['success' => false, 'error' => "Domain '{$domain}' is already routed on this Cloudflare tunnel."]);
                        break;
                    }
                } catch (Throwable) {}
            }
        }

        $args = ['--username' => $username, '--domain' => $domain];
        if ($phpVer) $args['--php-version'] = $phpVer;
        if (!$cfActive) $args[] = '--no-cf';

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
            if ($port && $cfActive) {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        if (!isset($cf)) $cf = new CloudflareAPI();
                        $cfResult = $cf->addTunnelHostname($accountId, $tunnelId, $domain, "https://localhost:{$port}");
                        if (!empty($cfResult['dns_skipped'])) {
                            $warnings[] = "Domain added to tunnel but DNS CNAME was not created — the zone for '{$domain}' is not in your Cloudflare account. Add the domain to Cloudflare and create a CNAME record pointing to {$tunnelId}.cfargotunnel.com";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage() . ". The domain was created locally but is not routed through Cloudflare.";
                    }
                }
            } elseif ($port && !$cfActive) {
                // Not using CF — open firewall port for direct access
                shell_exec("sudo /usr/bin/firewall-cmd --permanent --add-port={$port}/tcp 2>&1");
                shell_exec("sudo /usr/bin/firewall-cmd --reload 2>&1");
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

        $skipCf   = ($_POST['skip_cf'] ?? '0') === '1';
        $cfActive = DB::setting('cf_enabled', '0') === '1' && !$skipCf;

        // Pre-flight: check if domain is already routed via CF tunnel
        if ($cfActive) {
            $tunnelId  = DB::setting('cf_tunnel_id',  '');
            $accountId = DB::setting('cf_account_id', '');
            if ($tunnelId && $accountId) {
                try {
                    $cf = new CloudflareAPI();
                    $routed = $cf->getRoutedHostnames($accountId, $tunnelId);
                    if (isset($routed[$domain])) {
                        echo json_encode(['success' => false, 'error' => "Domain '{$domain}' is already routed on this Cloudflare tunnel."]);
                        break;
                    }
                } catch (Throwable) {}
            }
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
        $addArgs = ['--username' => $username, '--domain' => $domain, '--php-version' => $phpVer];
        if (!$cfActive) $addArgs[] = '--no-cf';
        $result = Shell::run('add_domain', $addArgs);

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
            if ($port && $cfActive) {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        if (!isset($cf)) $cf = new CloudflareAPI();
                        $cfResult = $cf->addTunnelHostname($accountId, $tunnelId, $domain, "https://localhost:{$port}");
                        if (!empty($cfResult['dns_skipped'])) {
                            $warnings[] = "Domain added to tunnel but DNS CNAME was not created — the zone for '{$domain}' is not in your Cloudflare account. Add the domain to Cloudflare and create a CNAME record pointing to {$tunnelId}.cfargotunnel.com";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage() . ". The domain was created locally but is not routed through Cloudflare.";
                    }
                }
            } elseif ($port && !$cfActive) {
                // Not using CF — open firewall port for direct access
                shell_exec("sudo /usr/bin/firewall-cmd --permanent --add-port={$port}/tcp 2>&1");
                shell_exec("sudo /usr/bin/firewall-cmd --reload 2>&1");
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        // Reload FPM after all work is done (deferred so the response gets sent first)
        $fpmService = 'php' . preg_replace('/[^0-9.]/', '', $phpVer) . '-fpm';
        shell_exec("(sleep 2 && systemctl reload " . escapeshellarg($fpmService) . ") > /dev/null 2>&1 &");

        echo json_encode(shellResult($result));
        break;

    case 'add_domain':
        Auth::requireAdmin();
        $domain   = trim($_POST['domain']   ?? '');
        $username = trim($_POST['username'] ?? '');
        $phpVer   = trim($_POST['php_version'] ?? '8.4');

        if (!$domain || !$username) {
            echo json_encode(['success' => false, 'error' => 'Domain and username are required.']);
            break;
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,61}[a-zA-Z0-9]$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain name.']);
            break;
        }

        // Verify user exists
        $existingUser = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
        if (!$existingUser) {
            echo json_encode(['success' => false, 'error' => "Hosting user '{$username}' not found."]);
            break;
        }

        $skipCf   = ($_POST['skip_cf'] ?? '0') === '1';
        $cfActive = DB::setting('cf_enabled', '0') === '1' && !$skipCf;

        // Pre-flight: check if domain is already routed via CF tunnel
        if ($cfActive) {
            $tunnelId  = DB::setting('cf_tunnel_id',  '');
            $accountId = DB::setting('cf_account_id', '');
            if ($tunnelId && $accountId) {
                try {
                    $cf = new CloudflareAPI();
                    $routed = $cf->getRoutedHostnames($accountId, $tunnelId);
                    if (isset($routed[$domain])) {
                        echo json_encode(['success' => false, 'error' => "Domain '{$domain}' is already routed on this Cloudflare tunnel."]);
                        break;
                    }
                } catch (Throwable) {}
            }
        }

        // Add domain to existing user
        $addArgs = ['--username' => $username, '--domain' => $domain, '--php-version' => $phpVer];
        if (!$cfActive) $addArgs[] = '--no-cf';
        $result = Shell::run('add_domain', $addArgs);

        if ($result['success']) {
            $port = null;
            if (preg_match('/port[:\s]+(\d+)/i', $result['output'], $m)) {
                $port = (int)$m[1];
            }
            $docRoot = "/home/{$username}/{$domain}/www";
            DB::query(
                'INSERT OR IGNORE INTO domains (hosting_user_id, domain_name, document_root, php_version, port, status, created_at)
                 VALUES (?, ?, ?, ?, ?, \'active\', datetime(\'now\'))',
                [$existingUser['id'], $domain, $docRoot, $phpVer, $port]
            );
            if ($port) {
                DB::query('INSERT OR REPLACE INTO account_ports (domain_name, port) VALUES (?, ?)', [$domain, $port]);
            }
            $result['port'] = $port;

            $warnings = [];
            if ($port && $cfActive) {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        if (!isset($cf)) $cf = new CloudflareAPI();
                        $cfResult = $cf->addTunnelHostname($accountId, $tunnelId, $domain, "https://localhost:{$port}");
                        if (!empty($cfResult['dns_skipped'])) {
                            $warnings[] = "Domain added to tunnel but DNS CNAME was not created — the zone for '{$domain}' is not in your Cloudflare account.";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage();
                    }
                }
            } elseif ($port && !$cfActive) {
                shell_exec("sudo /usr/bin/firewall-cmd --permanent --add-port={$port}/tcp 2>&1");
                shell_exec("sudo /usr/bin/firewall-cmd --reload 2>&1");
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        // Reload FPM after all work is done
        $fpmService = 'php' . preg_replace('/[^0-9.]/', '', $phpVer) . '-fpm';
        shell_exec("(sleep 2 && systemctl reload " . escapeshellarg($fpmService) . ") > /dev/null 2>&1 &");

        echo json_encode(shellResult($result));
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
    // CLOUDFLARE DOMAIN HELPERS
    // =========================================================================

    case 'domain_options':
        Auth::requireAdmin();
        $cfEnabled = DB::setting('cf_enabled', '0') === '1';
        if (!$cfEnabled) {
            echo json_encode(['success' => true, 'cf_enabled' => false, 'zones' => [], 'routed_hostnames' => []]);
            break;
        }

        $tunnelId  = DB::setting('cf_tunnel_id',  '');
        $accountId = DB::setting('cf_account_id', '');
        $force     = ($_GET['force'] ?? '0') === '1';
        $cacheTs   = (int)DB::setting('cf_domain_cache_ts', '0');

        // Return cached data if fresh (30 min) and not forced
        if (!$force && $cacheTs && (time() - $cacheTs < 1800)) {
            $cached = DB::setting('cf_domain_cache_json', '');
            if ($cached) {
                echo $cached;
                break;
            }
        }

        try {
            $cf = new CloudflareAPI();

            // Get zones
            $zonesRaw = $cf->listZones();
            $zones = [];
            foreach ($zonesRaw['result'] ?? [] as $z) {
                $zones[] = ['id' => $z['id'], 'name' => $z['name'], 'status' => $z['status'] ?? 'unknown'];
            }

            // Get routed hostnames from our tunnel
            $routedHostnames = [];
            if ($tunnelId && $accountId) {
                $routedHostnames = $cf->getRoutedHostnames($accountId, $tunnelId);
            }

            // Mark routed zones (CNAME conflict check is done on-demand per zone)
            foreach ($zones as &$z) {
                $z['routed']         = isset($routedHostnames[$z['name']]);
                $z['available']      = !$z['routed'];
                $z['cname_conflict'] = false;
            }
            unset($z);

            $response = json_encode([
                'success'           => true,
                'cf_enabled'        => true,
                'tunnel_id'         => $tunnelId,
                'zones'             => $zones,
                'routed_hostnames'  => array_keys($routedHostnames),
            ]);

            DB::saveSetting('cf_domain_cache_json', $response);
            DB::saveSetting('cf_domain_cache_ts', (string)time());

            echo $response;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Cloudflare API error: ' . $e->getMessage()]);
        }
        break;

    case 'auto_login_token':
        Auth::requireAdmin();
        $username = trim($_GET['username'] ?? '');
        if (!$username) {
            echo json_encode(['success' => false, 'error' => 'Username required.']);
            break;
        }
        $user = DB::fetchOne('SELECT id FROM hosting_users WHERE username = ?', [$username]);
        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'Hosting user not found.']);
            break;
        }
        $token = AccountAuth::createAutoLoginToken($username);
        echo json_encode(['success' => true, 'token' => $token]);
        break;

    case 'check_domain':
        Auth::requireAdmin();
        $domain = trim($_GET['domain'] ?? '');
        if (!$domain) {
            echo json_encode(['success' => false, 'error' => 'Domain required.']);
            break;
        }

        // Check if already hosted on this server
        $existing = DB::fetchOne('SELECT id FROM domains WHERE domain_name = ?', [$domain]);
        $vhostExists = file_exists("/etc/apache2/sites-available/{$domain}.conf");
        if ($existing || $vhostExists) {
            echo json_encode(['success' => true, 'available' => false, 'reason' => 'Domain already exists on this server.', 'cf_managed' => false]);
            break;
        }

        $cfEnabled = DB::setting('cf_enabled', '0') === '1';
        if (!$cfEnabled) {
            echo json_encode(['success' => true, 'available' => true, 'cf_enabled' => false, 'cf_managed' => false]);
            break;
        }

        $tunnelId  = DB::setting('cf_tunnel_id',  '');
        $accountId = DB::setting('cf_account_id', '');
        $cf = new CloudflareAPI();

        // Check our tunnel ingress
        $routed = [];
        if ($tunnelId && $accountId) {
            $routed = $cf->getRoutedHostnames($accountId, $tunnelId);
        }
        if (isset($routed[$domain])) {
            echo json_encode(['success' => true, 'available' => false, 'reason' => 'Already routed on this Cloudflare tunnel.', 'cf_managed' => true]);
            break;
        }

        // Check if zone exists in CF
        $zoneId = $cf->findZoneForHostname($domain);
        if (!$zoneId) {
            echo json_encode(['success' => true, 'available' => true, 'cf_managed' => false,
                'warning' => 'Domain zone not found in Cloudflare. It will be created locally only unless added to Cloudflare first.']);
            break;
        }

        // Check CNAME records for tunnel conflicts
        $cnames = $cf->listDNSRecords($zoneId, ['type' => 'CNAME', 'name' => $domain]);
        foreach ($cnames['result'] ?? [] as $r) {
            if (str_contains($r['content'] ?? '', 'cfargotunnel.com')) {
                echo json_encode(['success' => true, 'available' => false,
                    'reason' => 'A CNAME record for this domain already points to a Cloudflare tunnel.', 'cf_managed' => true]);
                break 2;
            }
        }

        echo json_encode(['success' => true, 'available' => true, 'cf_managed' => true]);
        break;


    // =========================================================================
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
