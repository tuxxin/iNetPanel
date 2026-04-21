<?php
// FILE: api/accounts.php
// iNetPanel — Accounts API (Multi-Domain Username System)
// User-level: create_user, delete_user, list_users, get_user
// Domain-level: add_domain, remove_domain, list_domains
// Combined: create (user+domain), delete (all domains+user), suspend, resume, detail

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Reserved usernames that cannot be used for hosting accounts
const BANNED_USERNAMES = [
    'root', 'admin', 'administrator', 'www', 'www-data', 'system', 'daemon',
    'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp',
    'proxy', 'backup', 'list', 'irc', 'gnats', 'nobody', 'systemd-network',
    'systemd-resolve', 'messagebus', 'sshd', 'mysql', 'mariadb', 'postgres',
    'apache', 'apache2', 'nginx', 'lighttpd', 'ftp', 'ftpuser', 'vsftpd',
    'postfix', 'dovecot', 'cron', 'ntp', 'ssh', 'git', 'svn',
    'test', 'guest', 'user', 'ubuntu', 'debian', 'centos',
    'webmaster', 'hostmaster', 'postmaster', 'abuse', 'noc', 'security',
    'info', 'support', 'sales', 'contact',
    'inetpanel', 'panel', 'cpanel', 'plesk', 'whm',
    'cloudflare', 'wireguard', 'fail2ban', 'certbot', 'letsencrypt',
    'phpmyadmin', 'adminer', 'supervisor',
];

// Normalize Shell::run result for JSON API response
function shellResult(array $r): array {
    if ($r['success']) return $r;
    return ['success' => false, 'error' => $r['output'] ?: $r['error'] ?: 'Script execution failed.'];
}

// Format a byte count as human-readable (e.g. "10.6 GB")
function formatDiskBytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
}

// Per-domain file disk usage (reads from disk_cache table populated by cron).
// Returns ['bytes'=>int,'hr'=>string,'scanned'=>datetime] or null if not scanned yet.
function accountDomainFiles(string $username, string $domain): ?array {
    try {
        $row = DB::fetchOne(
            'SELECT files_bytes, scanned_at FROM disk_cache WHERE username = ? AND domain_name = ?',
            [$username, $domain]
        );
    } catch (Throwable $e) { return null; }
    if (!$row) return null;
    $b = (int)$row['files_bytes'];
    return ['bytes' => $b, 'hr' => formatDiskBytes($b), 'scanned' => $row['scanned_at']];
}

// Per-user total = sum of user's domain files + user's DB total (cached).
// Returns ['bytes','hr','files_bytes','db_bytes'] or null if nothing cached yet.
function accountUserTotal(string $username): ?array {
    try {
        $files = DB::fetchOne(
            'SELECT COALESCE(SUM(files_bytes), 0) AS b FROM disk_cache WHERE username = ?',
            [$username]
        );
        $dbRow = DB::fetchOne(
            'SELECT db_bytes FROM disk_cache_user WHERE username = ?',
            [$username]
        );
    } catch (Throwable $e) { return null; }
    $filesBytes = (int)($files['b'] ?? 0);
    $dbBytes    = (int)($dbRow['db_bytes'] ?? 0);
    $total      = $filesBytes + $dbBytes;
    if ($total === 0) return null;
    return [
        'bytes'       => $total,
        'hr'          => formatDiskBytes($total),
        'files_bytes' => $filesBytes,
        'db_bytes'    => $dbBytes,
    ];
}

// Backward-compat wrapper — used by list_users / get_user actions.
// Returns the user total as a formatted string, or '—' if not cached.
function accountDisk(string $username): string {
    $t = accountUserTotal($username);
    return $t ? $t['hr'] : '—';
}

// Execute a hook script (add_domain or delete_domain) after domain operations
function executeHook(string $hookType, array $vars): void
{
    try {
        if (DB::setting("hook_{$hookType}_enabled", '0') !== '1') return;
        $code = str_replace("\r", '', DB::setting("hook_{$hookType}_code", ''));
        if (trim($code) === '') return;

        $script = "#!/bin/bash\nset -e\n";
        foreach ($vars as $k => $v) {
            $script .= "export {$k}=" . escapeshellarg($v) . "\n";
        }
        $script .= $code;

        $tmp = tempnam('/tmp', 'inetp_hook_');
        file_put_contents($tmp, $script);
        chmod($tmp, 0700);

        $result = Shell::exec('sudo /bin/bash ' . escapeshellarg($tmp), "hook-{$hookType}");

        DB::insert('logs', [
            'source'     => 'hook',
            'level'      => $result['success'] ? 'info' : 'error',
            'message'    => "Hook {$hookType} " . ($result['success'] ? 'completed' : 'failed'),
            'details'    => $result['output'] ?? '',
            'user'       => Auth::user()['username'] ?? 'system',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        @unlink($tmp);
    } catch (Throwable $e) {
        // Hook failure must never break the domain operation
        try {
            DB::insert('logs', [
                'source'     => 'hook',
                'level'      => 'error',
                'message'    => "Hook {$hookType} exception: " . $e->getMessage(),
                'details'    => $e->getTraceAsString(),
                'user'       => Auth::user()['username'] ?? 'system',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {}
    }
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
            $u['disk'] = accountDisk($u['username']);
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
        $user['disk'] = accountDisk($username);

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
        if (in_array($username, BANNED_USERNAMES, true)) {
            echo json_encode(['success' => false, 'error' => "The username '{$username}' is reserved and cannot be used."]);
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
            // Clean up WireGuard peer config files + live interface before DB delete
            $wgPeer = DB::fetchOne('SELECT id FROM wg_peers WHERE hosting_user = ?', [$username]);
            if ($wgPeer) {
                Shell::run('wg_peer', ['--remove', '--name' => $username]);
            }
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

        // Pre-flight: check tunnel route conflict (all tunnels on the account)
        if ($cfActive) {
            $tunnelId  = DB::setting('cf_tunnel_id',  '');
            $accountId = DB::setting('cf_account_id', '');
            if ($tunnelId && $accountId) {
                try {
                    $cf = new CloudflareAPI();
                    // Check this tunnel first (fast)
                    $routed = $cf->getRoutedHostnames($accountId, $tunnelId);
                    if (isset($routed[$domain])) {
                        echo json_encode(['success' => false, 'error' => "Domain '{$domain}' is already routed on this server's tunnel."]);
                        break;
                    }
                    // Check all other tunnels on the account
                    $found = $cf->findDomainInTunnels($accountId, $domain);
                    if ($found && $found['tunnel_id'] !== $tunnelId) {
                        echo json_encode(['success' => false, 'error' => "Domain '{$domain}' is routed on tunnel '{$found['tunnel_name']}'. Remove it there first or use Restore with CF override."]);
                        break;
                    }
                } catch (Throwable $e) {
                    error_log('iNetPanel: CF API error in accounts.php - ' . $e->getMessage());
                }
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
                    $peerResult = Shell::run('wg_peer', ['--add', '--name' => $username]);
                    if (!$peerResult['success']) {
                        DB::insert('logs', ['source' => 'wireguard', 'level' => 'ERROR', 'message' => "Auto-peer creation failed for {$username}", 'details' => $peerResult['output'] ?? '', 'user' => Auth::user()['username'] ?? 'system', 'created_at' => date('Y-m-d H:i:s')]);
                    }
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
                        if (!empty($cfResult['www_skipped'])) {
                            $warnings[] = "www.{$domain} DNS record already exists — skipped automatic www routing.";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage() . ". The domain was created locally but is not routed through Cloudflare.";
                    }
                }
            } elseif ($port && !$cfActive) {
                // Not using CF — open firewall port for direct access
                Shell::exec('sudo /usr/bin/firewall-cmd --permanent --add-port=' . escapeshellarg("{$port}/tcp"), 'firewall');
                Shell::exec('sudo /usr/bin/firewall-cmd --reload', 'firewall');
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        echo json_encode(shellResult($result));
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($result['success']) {
            executeHook('add_domain', [
                'DOMAIN'    => $domain,
                'USERNAME'  => $username,
                'PORT'      => $port ?? '',
                'DOC_ROOT'  => "/home/{$username}/{$domain}/www",
                'WEB_ROOT'  => "/home/{$username}/{$domain}/www",
                'LOG_DIR'   => "/home/{$username}/{$domain}/logs",
                'SERVER_IP' => trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: ''),
                'PHP_VER'   => $phpVer ?: '8.4',
                'DB_NAME'   => $username . '_' . str_replace(['.', '-'], '_', $domain),
                'DB_USER'   => $username,
                'DB_PASS'   => trim(@file_get_contents('/root/.mysql_root_pass') ?: ''),
            ]);
            $pv = $phpVer ?: '8.4';
            $fpmService = "php{$pv}-fpm";
            Shell::exec('sudo /bin/systemctl reload ' . escapeshellarg($fpmService), 'fpm-reload');
            // Refresh disk usage cache for the affected user (background, non-blocking)
            Shell::exec('sudo /usr/local/bin/inetp disk_cache_scan --user ' . escapeshellarg($username) . ' >/dev/null 2>&1 &', 'disk-cache-refresh');
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

        // Capture domain info before deletion (rows are deleted after Shell::run)
        $hookPort = '';
        $hookPhpVer = '';
        $hookDomainRow = DB::fetchOne('SELECT port, php_version FROM domains WHERE domain_name = ?', [$domain]);
        if ($hookDomainRow) {
            $hookPort = $hookDomainRow['port'] ?? '';
            $hookPhpVer = $hookDomainRow['php_version'] ?? '';
        }

        $args = ['--username' => $username, '--domain' => $domain];
        if ($noBackup) $args[] = '--no-backup';

        $result = Shell::run('remove_domain', $args);
        if ($result['success']) {
            DB::delete('domains', 'domain_name = ?', [$domain]);
            DB::delete('account_ports', 'domain_name = ?', [$domain]);
            // Purge disk_cache row for this domain; user's DB total gets refreshed below.
            DB::query('DELETE FROM disk_cache WHERE username = ? AND domain_name = ?', [$username, $domain]);
            if (DB::setting('cf_enabled', '0') === '1') {
                $tunnelId  = DB::setting('cf_tunnel_id',  '');
                $accountId = DB::setting('cf_account_id', '');
                if ($tunnelId && $accountId) {
                    try {
                        $cf = new CloudflareAPI();
                        $cf->removeTunnelHostname($accountId, $tunnelId, $domain);
                    } catch (Throwable $e) {
                        error_log('iNetPanel: CF API error in accounts.php - ' . $e->getMessage());
                    }
                }
            }
        }
        echo json_encode(shellResult($result));
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($result['success']) {
            executeHook('delete_domain', [
                'DOMAIN'    => $domain,
                'USERNAME'  => $username,
                'PORT'      => $hookPort,
                'DOC_ROOT'  => "/home/{$username}/{$domain}/www",
                'SERVER_IP' => trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: ''),
            ]);
            // Reload FPM to drop the removed pool's workers
            $delPhpVer = $hookPhpVer ?: DB::setting('php_default_version', '8.4');
            $fpmService = 'php' . preg_replace('/[^0-9.]/', '', $delPhpVer) . '-fpm';
            Shell::exec('sudo /bin/systemctl reload ' . escapeshellarg($fpmService), 'fpm-reload');
            // Refresh the user's DB total (domain row was already deleted above)
            Shell::exec('sudo /usr/local/bin/inetp disk_cache_scan --user ' . escapeshellarg($username) . ' >/dev/null 2>&1 &', 'disk-cache-refresh');
        }
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
            // Avoid reserved usernames
            if (in_array($username, BANNED_USERNAMES, true)) {
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

        if (in_array($username, BANNED_USERNAMES, true)) {
            echo json_encode(['success' => false, 'error' => "The username '{$username}' is reserved and cannot be used."]);
            break;
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
                } catch (Throwable $e) {
                        error_log('iNetPanel: CF API error in accounts.php - ' . $e->getMessage());
                    }
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
                    $peerResult = Shell::run('wg_peer', ['--add', '--name' => $username]);
                    if (!$peerResult['success']) {
                        DB::insert('logs', ['source' => 'wireguard', 'level' => 'ERROR', 'message' => "Auto-peer creation failed for {$username}", 'details' => $peerResult['output'] ?? '', 'user' => Auth::user()['username'] ?? 'system', 'created_at' => date('Y-m-d H:i:s')]);
                    }
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
                        if (!empty($cfResult['www_skipped'])) {
                            $warnings[] = "www.{$domain} DNS record already exists — skipped automatic www routing.";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage() . ". The domain was created locally but is not routed through Cloudflare.";
                    }
                }
            } elseif ($port && !$cfActive) {
                // Not using CF — open firewall port for direct access
                Shell::exec('sudo /usr/bin/firewall-cmd --permanent --add-port=' . escapeshellarg("{$port}/tcp"), 'firewall');
                Shell::exec('sudo /usr/bin/firewall-cmd --reload', 'firewall');
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        echo json_encode(shellResult($result));
        // Flush response to client, then reload FPM to activate the new pool socket.
        // Must happen AFTER response is sent — reloading FPM recycles the panel worker too.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($result['success']) {
            executeHook('add_domain', [
                'DOMAIN'    => $domain,
                'USERNAME'  => $username,
                'PORT'      => $port ?? '',
                'DOC_ROOT'  => "/home/{$username}/{$domain}/www",
                'WEB_ROOT'  => "/home/{$username}/{$domain}/www",
                'LOG_DIR'   => "/home/{$username}/{$domain}/logs",
                'SERVER_IP' => trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: ''),
                'PHP_VER'   => $phpVer ?: '8.4',
                'DB_NAME'   => $username . '_' . str_replace(['.', '-'], '_', $domain),
                'DB_USER'   => $username,
                'DB_PASS'   => trim(@file_get_contents('/root/.mysql_root_pass') ?: ''),
            ]);
            // Refresh disk usage cache for the new user+domain (background)
            Shell::exec('sudo /usr/local/bin/inetp disk_cache_scan --user ' . escapeshellarg($username) . ' >/dev/null 2>&1 &', 'disk-cache-refresh');
        }
        $fpmService = 'php' . preg_replace('/[^0-9.]/', '', $phpVer) . '-fpm';
        Shell::exec('sudo /bin/systemctl reload ' . escapeshellarg($fpmService), 'fpm-reload');
        break;

    case 'change_password':
        Auth::requireAdmin();
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$username || !$password) {
            echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
            break;
        }
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
            break;
        }

        $result = Shell::run('change_password', ['--username' => $username, '--password' => $password]);
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
                } catch (Throwable $e) {
                        error_log('iNetPanel: CF API error in accounts.php - ' . $e->getMessage());
                    }
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
                        if (!empty($cfResult['www_skipped'])) {
                            $warnings[] = "www.{$domain} DNS record already exists — skipped automatic www routing.";
                        }
                    } catch (Throwable $e) {
                        $warnings[] = "Cloudflare tunnel setup failed: " . $e->getMessage();
                    }
                }
            } elseif ($port && !$cfActive) {
                Shell::exec('sudo /usr/bin/firewall-cmd --permanent --add-port=' . escapeshellarg("{$port}/tcp"), 'firewall');
                Shell::exec('sudo /usr/bin/firewall-cmd --reload', 'firewall');
            }
            if ($warnings) $result['warnings'] = $warnings;
        }

        echo json_encode(shellResult($result));
        // Flush response to client, then reload FPM to activate the new pool socket.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($result['success']) {
            executeHook('add_domain', [
                'DOMAIN'    => $domain,
                'USERNAME'  => $username,
                'PORT'      => $port ?? '',
                'DOC_ROOT'  => "/home/{$username}/{$domain}/www",
                'WEB_ROOT'  => "/home/{$username}/{$domain}/www",
                'LOG_DIR'   => "/home/{$username}/{$domain}/logs",
                'SERVER_IP' => trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: ''),
                'PHP_VER'   => $phpVer ?: '8.4',
                'DB_NAME'   => $username . '_' . str_replace(['.', '-'], '_', $domain),
                'DB_USER'   => $username,
                'DB_PASS'   => trim(@file_get_contents('/root/.mysql_root_pass') ?: ''),
            ]);
            // Refresh disk usage cache for the affected user (background)
            Shell::exec('sudo /usr/local/bin/inetp disk_cache_scan --user ' . escapeshellarg($username) . ' >/dev/null 2>&1 &', 'disk-cache-refresh');
        }
        $fpmService = 'php' . preg_replace('/[^0-9.]/', '', $phpVer) . '-fpm';
        Shell::exec('sudo /bin/systemctl reload ' . escapeshellarg($fpmService), 'fpm-reload');
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

        // Remove CF tunnel route first — most likely to fail, acts as safety net
        if (DB::setting('cf_enabled', '0') === '1') {
            $tunnelId  = DB::setting('cf_tunnel_id',  '');
            $accountId = DB::setting('cf_account_id', '');
            if ($tunnelId && $accountId) {
                try {
                    $cf = new CloudflareAPI();
                    $cf->removeTunnelHostname($accountId, $tunnelId, $domain);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to remove Cloudflare route: ' . $e->getMessage()]);
                    break;
                }
            }
        }

        $args = ['--username' => $username, '--domain' => $domain];
        if ($noBackup) $args[] = '--no-backup';
        $result = Shell::run('remove_domain', $args);

        if ($result['success']) {
            DB::delete('domains', 'domain_name = ?', [$domain]);
            DB::delete('account_ports', 'domain_name = ?', [$domain]);
            // Purge disk_cache row for this domain
            DB::query('DELETE FROM disk_cache WHERE username = ? AND domain_name = ?', [$username, $domain]);

            // If no more domains for this user, delete the user too
            $remaining = DB::fetchOne(
                'SELECT COUNT(*) as cnt FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id WHERE h.username = ?',
                [$username]
            );
            $userDeleted = false;
            if (($remaining['cnt'] ?? 0) === 0) {
                $delResult = Shell::run('delete_user', ['--username' => $username]);
                if ($delResult['success']) {
                    DB::delete('hosting_users', 'username = ?', [$username]);
                    $wgPeer = DB::fetchOne('SELECT id FROM wg_peers WHERE hosting_user = ?', [$username]);
                    if ($wgPeer) {
                        Shell::run('wg_peer', ['--remove', '--name' => $username]);
                    }
                    DB::delete('wg_peers', 'hosting_user = ?', [$username]);
                    // Full cleanup — drop both disk cache tables for this user
                    DB::query('DELETE FROM disk_cache WHERE username = ?', [$username]);
                    DB::query('DELETE FROM disk_cache_user WHERE username = ?', [$username]);
                    $userDeleted = true;
                }
            }
        }

        echo json_encode(shellResult($result));
        // Flush response to client, then reload FPM to unload the removed pool.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($result['success']) {
            executeHook('delete_domain', [
                'DOMAIN'    => $domain,
                'USERNAME'  => $username,
                'PORT'      => $domainRow['port'] ?? '',
                'DOC_ROOT'  => "/home/{$username}/{$domain}/www",
                'SERVER_IP' => trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: ''),
            ]);
            // If user still exists (had more domains), refresh their DB total cache
            if (empty($userDeleted)) {
                Shell::exec('sudo /usr/local/bin/inetp disk_cache_scan --user ' . escapeshellarg($username) . ' >/dev/null 2>&1 &', 'disk-cache-refresh');
            }
        }
        $delPhpVer = $domainRow['php_version'] ?? '8.4';
        $fpmService = 'php' . preg_replace('/[^0-9.]/', '', $delPhpVer) . '-fpm';
        Shell::exec('sudo /bin/systemctl reload ' . escapeshellarg($fpmService), 'fpm-reload');
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
        // Optional query params:
        //   ?limit=N      — cap rows (used by dashboard for its 6-row preview)
        //   ?skip_disk=1  — skip the per-user du/db_size calls (expensive with many domains)
        $limit    = max(0, (int)($_GET['limit'] ?? 0));
        $skipDisk = ($_GET['skip_disk'] ?? '0') === '1';

        $sql = 'SELECT d.*, h.username as hosting_username FROM domains d LEFT JOIN hosting_users h ON d.hosting_user_id = h.id ORDER BY d.created_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $domains = DB::fetchAll($sql);
        $wgPeers = DB::fetchAll('SELECT hosting_user, peer_ip FROM wg_peers');
        $wgMap   = array_column($wgPeers, 'peer_ip', 'hosting_user');

        // Per-row disk now comes from the cached disk_cache table (populated by cron).
        // $userTotals caches the per-user total (files across all domains + user's DB bytes)
        // so the UI can render a total under the username on that user's first row.
        $userTotals = [];
        foreach ($domains as &$d) {
            $username = $d['hosting_username'] ?? $d['domain_name'];
            if ($skipDisk) {
                $d['disk'] = null;
                $d['disk_bytes'] = 0;
                $d['user_total'] = null;
            } else {
                $files = accountDomainFiles($username, $d['domain_name']);
                $d['disk'] = $files['hr'] ?? '—';
                $d['disk_bytes'] = $files['bytes'] ?? 0;

                if (!array_key_exists($username, $userTotals)) {
                    $userTotals[$username] = accountUserTotal($username);
                }
                $d['user_total']    = $userTotals[$username]['hr'] ?? null;
                $d['user_db_bytes'] = $userTotals[$username]['db_bytes'] ?? 0;
            }
            $d['wg_ip']    = $wgMap[$username] ?? null;
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

        // Check ALL tunnels on the account for this domain (not just CNAMEs)
        if ($accountId) {
            $found = $cf->findDomainInTunnels($accountId, $domain);
            if ($found) {
                echo json_encode(['success' => true, 'available' => false,
                    'reason' => "Domain is routed on tunnel '{$found['tunnel_name']}' → {$found['service']}.", 'cf_managed' => true]);
                break;
            }
        }

        echo json_encode(['success' => true, 'available' => true, 'cf_managed' => true]);
        break;


    // =========================================================================
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
