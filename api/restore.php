<?php
// FILE: api/restore.php
// iNetPanel — Backup Restore API
// Actions: upload, upload_status, ftp_info, parse, cf_check, execute

Auth::requireAdmin();
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$stagingDir = '/backup/restore_staging';

// Ensure staging directory exists and is writable by www-data
if (!is_dir($stagingDir) || !is_writable($stagingDir)) {
    // Use the inetp_hook sudoers rule (allows: sudo /bin/bash /tmp/inetp_hook_*)
    $hook = '/tmp/inetp_hook_restore_staging_' . getmypid();
    file_put_contents($hook, "#!/bin/bash\nmkdir -p {$stagingDir}\nchown www-data:www-data {$stagingDir}\nchmod 0770 {$stagingDir}\n");
    chmod($hook, 0755);
    exec('sudo /bin/bash ' . escapeshellarg($hook) . ' 2>/dev/null');
    @unlink($hook);
}

switch ($action) {

// ── Upload a .tgz backup via web form ────────────────────────────────────────
case 'upload':
    if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
            UPLOAD_ERR_PARTIAL    => 'Upload was interrupted.',
            UPLOAD_ERR_NO_FILE    => 'No file selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server cannot write to disk.',
        ];
        $code = $_FILES['backup']['error'] ?? UPLOAD_ERR_NO_FILE;
        echo json_encode(['success' => false, 'error' => $errMap[$code] ?? 'Upload failed (code ' . $code . ').']);
        break;
    }

    $file = $_FILES['backup'];
    $name = basename($file['name']);

    if (!preg_match('/\.tgz$/', $name)) {
        echo json_encode(['success' => false, 'error' => 'Only .tgz backup files are accepted.']);
        break;
    }

    $dest = $stagingDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $reason = '';
        if (!is_dir($stagingDir))          $reason = 'Staging directory does not exist: ' . $stagingDir;
        elseif (!is_writable($stagingDir)) $reason = 'Staging directory not writable by web server: ' . $stagingDir;
        elseif (!is_uploaded_file($file['tmp_name'])) $reason = 'Temp file missing — upload may have been interrupted.';
        else $reason = 'move_uploaded_file() failed. tmp=' . $file['tmp_name'] . ', dest=' . $dest;
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file. ' . $reason]);
        break;
    }

    $cfProxy = !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    echo json_encode([
        'success'  => true,
        'filename' => $name,
        'size'     => filesize($dest),
        'size_hr'  => formatBytes(filesize($dest)),
        'cf_proxy' => $cfProxy,
    ]);
    break;

// ── Poll staging directory for FTP/SCP uploads ───────────────────────────────
case 'upload_status':
    $files = [];
    foreach (glob($stagingDir . '/*.tgz') as $f) {
        $files[] = [
            'filename' => basename($f),
            'size'     => filesize($f),
            'size_hr'  => formatBytes(filesize($f)),
            'mtime'    => filemtime($f),
            'age'      => time() - filemtime($f),
        ];
    }
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    echo json_encode(['success' => true, 'files' => $files]);
    break;

// ── Return permanent restore FTP account info ────────────────────────────────
case 'ftp_info':
    // Ensure restore user exists with root's password hash (no plaintext needed)
    // Uses inetp_hook pattern (allowed in sudoers: sudo /bin/bash /tmp/inetp_hook_*)
    exec('id restore 2>/dev/null', $ftpIdOut, $ftpIdCode);
    $hook = '/tmp/inetp_hook_restore_ftp_' . getmypid();
    $script = "#!/bin/bash\n";
    if ($ftpIdCode !== 0) {
        $script .= "useradd -d " . escapeshellarg($stagingDir) . " -s /bin/bash -g www-data restore\n";
    } else {
        $script .= "usermod -s /bin/bash -d " . escapeshellarg($stagingDir) . " restore\n";
    }
    // Copy root's password hash directly — no plaintext password needed
    $script .= "ROOT_HASH=\$(getent shadow root | cut -d: -f2)\n";
    $script .= "usermod -p \"\$ROOT_HASH\" restore\n";
    // Ensure in vsftpd whitelist
    $script .= "grep -qx restore /etc/vsftpd.userlist 2>/dev/null || echo restore >> /etc/vsftpd.userlist\n";
    $script .= "systemctl reload vsftpd 2>/dev/null\n";
    file_put_contents($hook, $script);
    chmod($hook, 0755);
    exec('sudo /bin/bash ' . escapeshellarg($hook) . ' 2>&1', $hookOut, $hookCode);
    @unlink($hook);

    // Detect server IP
    $serverIp = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
    if (!$serverIp) $serverIp = trim(shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'") ?: 'YOUR_SERVER_IP');

    echo json_encode([
        'success'   => true,
        'host'      => $serverIp,
        'port'      => 21,
        'username'  => 'restore',
        'password_hint' => 'ROOT PASSWORD',
        'directory' => $stagingDir,
    ]);
    break;

// ── Parse a staged backup file (can be slow on large archives) ───────────────
case 'parse':
    set_time_limit(300); // large archives can take time to list
    $filename = basename($_POST['filename'] ?? '');
    if (!$filename || !preg_match('/^[\w.\-]+\.tgz$/', $filename)) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename.']);
        break;
    }
    $filepath = $stagingDir . '/' . $filename;
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => 'File not found in staging directory.']);
        break;
    }

    // Stream tar listing through grep pipes — constant memory for any archive size
    $esc = escapeshellarg($filepath);

    // Detect username from first home/USER/ path
    $archiveUser = trim(shell_exec("tar -tzf {$esc} 2>/dev/null | grep -oP '^home/\\K[^/]+' | head -1") ?: '');

    // Detect domains: unique directories containing a www/ subdir
    $domainLines = trim(shell_exec("tar -tzf {$esc} 2>/dev/null | grep -oP '^home/[^/]+/\\K[^/]+(?=/www/)' | sort -u") ?: '');
    $domains = $domainLines ? array_filter(array_unique(explode("\n", $domainLines)), fn($d) => $d !== 'tmp') : [];

    // Detect SQL files with sizes at tar root (not inside home/)
    $sqlFiles = [];
    $sqlLines = trim(shell_exec("tar -tvzf {$esc} 2>/dev/null | grep -E '^\\S+\\s+\\S+\\s+[0-9]+\\s+\\S+\\s+\\S+\\s+\\.?/?[^/]+\\.sql\$'") ?: '');
    foreach (array_filter(explode("\n", $sqlLines)) as $dline) {
        if (preg_match('/(\d+)\s+[\d-]+\s+[\d:]+\s+\.?\/?([^\/]+\.sql)$/', $dline, $m)) {
            $sqlFiles[] = [
                'name'    => $m[2],
                'db_name' => preg_replace('/\.sql$/', '', $m[2]),
                'size'    => (int)$m[1],
                'size_hr' => formatBytes((int)$m[1]),
            ];
        }
    }

    // Count files under home/ (non-directory entries)
    $fileCount = (int) trim(shell_exec("tar -tzf {$esc} 2>/dev/null | grep -c '^home/.*[^/]\$'") ?: '0');

    // Auto-assign ports for each domain
    $portsConf = file_get_contents('/etc/apache2/ports_domains.conf') ?: '';
    preg_match_all('/^Listen\s+(\d+)/m', $portsConf, $portMatches);
    $usedPorts = array_map('intval', $portMatches[1] ?? []);
    $nextPort  = $usedPorts ? max($usedPorts) + 1 : 1080;
    if ($nextPort === 1443) $nextPort = 1444;

    $domainDetails = [];
    foreach ($domains as $domain) {
        // Check conflicts
        $vhostExists = file_exists("/etc/apache2/sites-available/{$domain}.conf");
        $dbExists    = false;
        try {
            $pdo = DB::get();
            $stmt = $pdo->prepare('SELECT id FROM domains WHERE domain_name = ?');
            $stmt->execute([$domain]);
            $dbExists = (bool) $stmt->fetch();
        } catch (Throwable $e) {}

        // Find old port if vhost exists
        $oldPort = null;
        if ($vhostExists) {
            $vhostContent = file_get_contents("/etc/apache2/sites-available/{$domain}.conf") ?: '';
            if (preg_match('/<VirtualHost\s+\*:(\d+)>/', $vhostContent, $pm)) {
                $oldPort = (int)$pm[1];
            }
        }

        $domainDetails[] = [
            'domain'       => $domain,
            'port'         => $nextPort,
            'old_port'     => $oldPort,
            'vhost_exists' => $vhostExists,
            'db_exists'    => $dbExists,
            'conflict'     => $vhostExists || $dbExists,
        ];

        // Advance port for next domain
        $usedPorts[] = $nextPort;
        $nextPort++;
        if ($nextPort === 1443) $nextPort = 1444;
    }

    // Check if username exists on system
    exec('id ' . escapeshellarg($archiveUser) . ' 2>/dev/null', $idOut, $idCode);
    $userExists = ($idCode === 0);

    // Get installed PHP versions for dropdown
    $phpVersions = [];
    foreach (glob('/usr/sbin/php-fpm*') as $fpm) {
        if (preg_match('/(\d+\.\d+)/', basename($fpm), $vm)) {
            $phpVersions[] = $vm[1];
        }
    }
    sort($phpVersions);

    echo json_encode([
        'success'      => true,
        'archive_user' => $archiveUser,
        'user_exists'  => $userExists,
        'domains'      => $domainDetails,
        'databases'    => $sqlFiles,
        'file_count'   => $fileCount,
        'archive_size' => filesize($filepath),
        'archive_size_hr' => formatBytes(filesize($filepath)),
        'php_versions' => $phpVersions,
    ]);
    break;

// ── Check Cloudflare routing for domains ─────────────────────────────────────
case 'cf_check':
    set_time_limit(120); // searching multiple tunnels makes many API calls
    $domainsJson = $_POST['domains'] ?? '[]';
    $domainList  = json_decode($domainsJson, true) ?: [];

    if (empty($domainList)) {
        echo json_encode(['success' => false, 'error' => 'No domains provided.']);
        break;
    }

    $cfEnabled = DB::setting('cf_enabled', '0') === '1';
    if (!$cfEnabled) {
        echo json_encode(['success' => true, 'cf_enabled' => false, 'domains' => []]);
        break;
    }

    $accountId = DB::setting('cf_account_id', '');
    $tunnelId  = DB::setting('cf_tunnel_id', '');
    $cf        = new CloudflareAPI();

    // Get all currently routed hostnames on our tunnel
    $routed = ($accountId && $tunnelId) ? $cf->getRoutedHostnames($accountId, $tunnelId) : [];

    $results = [];
    foreach ($domainList as $domain) {
        $info = [
            'domain'           => $domain,
            'zone_found'       => false,
            'zone_id'          => null,
            'currently_routed' => false,
            'current_service'  => null,
            'dns_records'      => [],
        ];

        // Check zone
        $zoneId = $cf->findZoneForHostname($domain);
        if ($zoneId) {
            $info['zone_found'] = true;
            $info['zone_id']    = $zoneId;

            // Check DNS records
            $records = $cf->listDNSRecords($zoneId, ['name' => $domain]);
            foreach ($records['result'] ?? [] as $rec) {
                $info['dns_records'][] = [
                    'type'    => $rec['type'],
                    'content' => $rec['content'],
                    'proxied' => $rec['proxied'] ?? false,
                ];
            }
        }

        // Check tunnel routing — first our tunnel, then search ALL account tunnels
        if (isset($routed[$domain])) {
            $info['currently_routed'] = true;
            $info['current_service']  = $routed[$domain];
            $info['routed_here']      = true;
        } else {
            // Search all tunnels on the account for this domain's ingress rule
            $found = $cf->findDomainInTunnels($accountId, $domain);
            if ($found) {
                $info['currently_routed'] = true;
                $info['routed_here']      = false;
                $info['current_service']  = $found['service'] . ' (' . $found['tunnel_name'] . ')';
                $info['other_tunnel_id']  = $found['tunnel_id'];
            }
        }

        $results[$domain] = $info;
    }

    echo json_encode(['success' => true, 'cf_enabled' => true, 'domains' => $results]);
    break;

// ── Execute the restore ──────────────────────────────────────────────────────
case 'execute':
    set_time_limit(0); // restore can take minutes for large backups
    $restoreLog = '/var/log/inetpanel_restore.log';
    $logRestore = function(string $msg) use ($restoreLog) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        file_put_contents($restoreLog, $line, FILE_APPEND);
    };
    $logRestore('=== Restore started ===');

    $filename    = basename($_POST['filename'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $domainsJson = $_POST['domains'] ?? '[]';
    $cfOverJson      = $_POST['cf_override'] ?? '[]';
    $cfOldTunnelJson = $_POST['cf_old_tunnels'] ?? '{}';
    $phpVersion  = trim($_POST['php_version'] ?? '');
    $importDb    = ($_POST['import_db'] ?? '1') === '1';

    // Validate
    if (!$filename || !preg_match('/^[\w.\-]+\.tgz$/', $filename)) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename.']);
        break;
    }
    $filepath = $stagingDir . '/' . $filename;
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => 'Backup file not found.']);
        break;
    }
    if (!$username || !preg_match('/^[a-z][a-z0-9\-]{0,31}$/', $username)) {
        echo json_encode(['success' => false, 'error' => 'Invalid username.']);
        break;
    }
    if (!$password) {
        echo json_encode(['success' => false, 'error' => 'Password is required.']);
        break;
    }

    $domainData    = json_decode($domainsJson, true) ?: [];
    $cfOverride    = json_decode($cfOverJson, true) ?: [];
    $cfOldTunnels  = json_decode($cfOldTunnelJson, true) ?: [];

    if (empty($domainData)) {
        echo json_encode(['success' => false, 'error' => 'No domains specified.']);
        break;
    }

    // Build domain and port lists
    $domainList = [];
    $portList   = [];
    foreach ($domainData as $d) {
        $domainList[] = $d['domain'];
        $portList[]   = (int) $d['port'];
    }

    // Build shell args
    $args = [
        '--backup-file' => $filepath,
        '--username'    => $username,
        '--password'    => $password,
        '--domains'     => implode(',', $domainList),
        '--ports'       => implode(',', $portList),
    ];
    if ($phpVersion) $args['--php-version'] = $phpVersion;
    if ($importDb)   $args[] = '--import-db';

    $cfEnabled = DB::setting('cf_enabled', '0') === '1';
    if (!$cfEnabled || empty($cfOverride)) {
        $args[] = '--no-cf';
    }

    // Run the restore script
    $logRestore("Running restore_account for user={$username} domains=" . implode(',', $domainList));
    $result = Shell::run('restore_account', $args);
    $logRestore("Shell result: code={$result['code']} success=" . ($result['success'] ? 'yes' : 'no'));
    $logRestore("Output: " . substr($result['output'], 0, 2000));

    if (!$result['success']) {
        $logRestore("FAILED: " . ($result['error'] ?: $result['output']));
        echo json_encode(['success' => false, 'error' => 'Restore failed: ' . ($result['error'] ?: $result['output'])]);
        break;
    }

    // Insert panel DB records
    $pdo = DB::get();
    try {
        // hosting_users
        $stmt = $pdo->prepare('SELECT id FROM hosting_users WHERE username = ?');
        $stmt->execute([$username]);
        $hostingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hostingUser) {
            $hostingUserId = $hostingUser['id'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO hosting_users (username, shell) VALUES (?, ?)');
            $stmt->execute([$username, '/bin/bash']);
            $hostingUserId = $pdo->lastInsertId();
        }

        // domains + account_ports
        foreach ($domainData as $d) {
            $domain = $d['domain'];
            $port   = (int) $d['port'];
            $phpVer = $d['php_version'] ?? $phpVersion ?: 'inherit';

            // Upsert domain
            $stmt = $pdo->prepare('SELECT id FROM domains WHERE domain_name = ?');
            $stmt->execute([$domain]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare('UPDATE domains SET hosting_user_id = ?, port = ?, php_version = ?, document_root = ?, status = ? WHERE domain_name = ?');
                $stmt->execute([$hostingUserId, $port, $phpVer, "/home/{$username}/{$domain}/www", 'active', $domain]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO domains (hosting_user_id, domain_name, document_root, php_version, port, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$hostingUserId, $domain, "/home/{$username}/{$domain}/www", $phpVer, $port, 'active']);
            }

            // Upsert account_ports
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO account_ports (domain_name, port) VALUES (?, ?)');
            $stmt->execute([$domain, $port]);
        }
    } catch (Throwable $e) {
        // Log but don't fail — the account is functional even if DB records fail
        error_log('Restore DB insert error: ' . $e->getMessage());
    }

    $logRestore("DB records inserted for user={$username}");

    // Cloudflare tunnel override
    $cfResults = [];
    if ($cfEnabled && !empty($cfOverride)) {
        $logRestore("Updating CF tunnel for: " . implode(', ', $cfOverride));
        $accountId = DB::setting('cf_account_id', '');
        $tunnelId  = DB::setting('cf_tunnel_id', '');
        if ($accountId && $tunnelId) {
            $cf = new CloudflareAPI();
            foreach ($cfOverride as $domain) {
                // Find the port for this domain
                $port = null;
                foreach ($domainData as $d) {
                    if ($d['domain'] === $domain) {
                        $port = (int) $d['port'];
                        break;
                    }
                }
                if (!$port) continue;

                try {
                    $cfResult = $cf->addTunnelHostname($accountId, $tunnelId, $domain, "https://localhost:{$port}");
                    $cfResults[$domain] = ['success' => true, 'dns_skipped' => !empty($cfResult['dns_skipped'])];
                    $logRestore("CF route added: {$domain} → localhost:{$port}");

                    // Remove the route from the old tunnel (if migrating from another server)
                    $oldTunnelId = $cfOldTunnels[$domain] ?? '';
                    if ($oldTunnelId && $oldTunnelId !== $tunnelId) {
                        try {
                            $cf->removeTunnelHostname($accountId, $oldTunnelId, $domain);
                            $logRestore("CF old route removed: {$domain} from tunnel {$oldTunnelId}");
                        } catch (Throwable $e2) {
                            $logRestore("CF old route removal failed for {$domain}: " . $e2->getMessage());
                        }
                    }
                } catch (Throwable $e) {
                    $cfResults[$domain] = ['success' => false, 'error' => $e->getMessage()];
                    $logRestore("CF route FAILED for {$domain}: " . $e->getMessage());
                }
            }
        }
    }

    // Clean up staging file
    @unlink($filepath);

    $logRestore("Restore complete for {$username}. Sending response and deferring FPM reload.");

    // Send response FIRST, then reload FPM (which kills this worker)
    $responseData = [
        'success'    => true,
        'username'   => $username,
        'password'   => $password,
        'domains'    => $domainData,
        'cf_results' => $cfResults,
        'output'     => $result['output'],
    ];

    if (function_exists('fastcgi_finish_request')) {
        echo json_encode($responseData);
        fastcgi_finish_request();
        // NOW safe to reload FPM — response already sent to client
        sleep(1); // brief delay to ensure response is flushed
        foreach (glob('/usr/sbin/php-fpm*') as $fpm) {
            if (preg_match('/(\d+\.\d+)/', basename($fpm), $vm)) {
                exec("sudo /bin/systemctl reload php{$vm[1]}-fpm 2>/dev/null");
            }
        }
        $logRestore("FPM reloaded after response sent.");
    } else {
        echo json_encode($responseData);
    }
    break;

// ── Max upload size info ─────────────────────────────────────────────────────
case 'upload_limits':
    $cfProxy  = !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    $phpLimit = ini_get('upload_max_filesize') ?: '100M';
    echo json_encode([
        'success'    => true,
        'php_limit'  => $phpLimit,
        'cf_proxy'   => $cfProxy,
        'effective'  => $cfProxy ? '100M' : $phpLimit,
    ]);
    break;

default:
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    break;
}

// ── Helper ───────────────────────────────────────────────────────────────────
function formatBytes(int $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow   = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
