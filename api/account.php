<?php
// FILE: api/account.php
// iNetPanel — Account Portal API (for hosting account holders)
// Requires AccountAuth session. Returns data scoped to the logged-in user's domains only.

$username = AccountAuth::username();
if (!$username) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$reqDomain = trim($_GET['domain'] ?? $_POST['domain'] ?? '');

// Verify the requested domain belongs to the logged-in user
$accountUser = AccountAuth::user();
$ownedDomains = array_column($accountUser['domains'] ?? [], 'domain_name');
if ($reqDomain && !in_array($reqDomain, $ownedDomains, true)) {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$domain = $reqDomain ?: ($ownedDomains[0] ?? $username);

$cf = new CloudflareAPI();

// Helper: find Cloudflare zone ID for domain (or parent domain)
function findZoneId(CloudflareAPI $cf, string $domain): ?string {
    return $cf->findZoneForHostname($domain);
}

// Helper: run manage_ssh_keys.sh for a hosting user
function runAccountKeyScript(string $username, string $action, array $extra = []): array
{
    $cmd = 'sudo /root/scripts/manage_ssh_keys.sh'
        . ' --domain ' . escapeshellarg($username)
        . ' --action ' . escapeshellarg($action);
    foreach ($extra as $flag => $val) {
        $cmd .= ' ' . escapeshellarg($flag) . ' ' . escapeshellarg($val);
    }
    $cmd .= ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $json = implode('', $output);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Script error: ' . $json, 'code' => $exitCode];
    }
    return $data;
}

// Helper: validate directory path is under doc root (prevents traversal)
function validateDirPath(string $docRoot, string $dir): string|false
{
    if ($dir === '') return $docRoot;
    // Block .. and absolute paths
    if (str_contains($dir, '..') || str_starts_with($dir, '/')) return false;
    $full = realpath($docRoot . '/' . $dir);
    if (!$full || !str_starts_with($full, $docRoot)) return false;
    if (!is_dir($full)) return false;
    return $full;
}

switch ($action) {

    // ── DNS ──────────────────────────────────────────────────────────────

    case 'dns':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zone found for this domain.']);
            break;
        }
        $result = $cf->listDNSRecords($zoneId);
        echo json_encode([
            'success' => true,
            'zone_id' => $zoneId,
            'records' => $result['result'] ?? [],
        ]);
        break;

    case 'dns_create':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $data = [
            'type'    => strtoupper($_POST['type'] ?? 'A'),
            'name'    => trim($_POST['name'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'ttl'     => (int)($_POST['ttl'] ?? 3600),
            'proxied' => ($_POST['proxied'] ?? '0') === '1',
        ];
        if (!$data['name'] || !$data['content']) {
            echo json_encode(['success' => false, 'error' => 'Name and content required.']); break;
        }
        $resp = $cf->createDNSRecord($zoneId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'dns_update':
        $zoneId   = findZoneId($cf, $domain);
        $recordId = trim($_POST['record_id'] ?? '');
        if (!$zoneId || !$recordId) { echo json_encode(['success' => false, 'error' => 'Zone or record not found.']); break; }
        $data = [
            'type'    => strtoupper($_POST['type'] ?? 'A'),
            'name'    => trim($_POST['name'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'ttl'     => (int)($_POST['ttl'] ?? 3600),
            'proxied' => ($_POST['proxied'] ?? '0') === '1',
        ];
        $resp = $cf->updateDNSRecord($zoneId, $recordId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'zone_settings':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $sec = $cf->getZoneSetting($zoneId, 'security_level');
        $dev = $cf->getZoneSetting($zoneId, 'development_mode');
        echo json_encode([
            'success'          => ($sec['success'] ?? false) && ($dev['success'] ?? false),
            'security_level'   => $sec['result']['value'] ?? 'medium',
            'development_mode' => $dev['result']['value'] ?? 'off',
        ]);
        break;

    case 'set_ddos_mode':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $enabled = ($_POST['enabled'] ?? '0') === '1';
        $result = $cf->setSecurityLevel($zoneId, $enabled ? 'under_attack' : 'medium');
        echo json_encode(['success' => $result['success'] ?? false, 'error' => $result['errors'][0]['message'] ?? null]);
        break;

    case 'set_dev_mode':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $enabled = ($_POST['enabled'] ?? '0') === '1';
        $result = $cf->setDevelopmentMode($zoneId, $enabled ? 'on' : 'off');
        echo json_encode(['success' => $result['success'] ?? false, 'error' => $result['errors'][0]['message'] ?? null]);
        break;

    case 'dns_delete':
        $zoneId   = findZoneId($cf, $domain);
        $recordId = trim($_POST['record_id'] ?? '');
        if (!$zoneId || !$recordId) { echo json_encode(['success' => false, 'error' => 'Zone or record not found.']); break; }
        $resp = $cf->deleteDNSRecord($zoneId, $recordId);
        echo json_encode(['success' => !empty($resp['success'])]);
        break;

    // ── Email Routing ────────────────────────────────────────────────────

    case 'email':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zone found for this domain.']);
            break;
        }
        $result = $cf->listEmailRouting($zoneId);
        echo json_encode([
            'success' => !empty($result['success']),
            'zone_id' => $zoneId,
            'rules'   => $result['result'] ?? [],
        ]);
        break;

    case 'email_create_rule':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $from = trim($_POST['from'] ?? '');
        $to   = trim($_POST['to'] ?? '');
        if (!$from || !$to) { echo json_encode(['success' => false, 'error' => 'From and to required.']); break; }
        $data = [
            'actions'  => [['type' => 'forward', 'value' => [$to]]],
            'matchers' => [['type' => 'literal', 'field' => 'to', 'value' => $from]],
            'enabled'  => true,
            'name'     => "Forward {$from} → {$to}",
        ];
        $resp = $cf->createEmailRule($zoneId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'email_delete_rule':
        $zoneId = findZoneId($cf, $domain);
        $ruleId = trim($_POST['rule_id'] ?? '');
        if (!$zoneId || !$ruleId) { echo json_encode(['success' => false, 'error' => 'Zone or rule not found.']); break; }
        $resp = $cf->deleteEmailRule($zoneId, $ruleId);
        echo json_encode(['success' => !empty($resp['success'])]);
        break;

    case 'email_addresses':
        $accountId = DB::setting('cf_account_id', '');
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'CF account not configured.']); break; }
        $resp = $cf->listEmailAddresses($accountId);
        echo json_encode(['success' => !empty($resp['success']), 'addresses' => $resp['result'] ?? []]);
        break;

    case 'email_create_address':
        $accountId = DB::setting('cf_account_id', '');
        $email     = trim($_POST['email'] ?? '');
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'CF account not configured.']); break; }
        if (!$email) { echo json_encode(['success' => false, 'error' => 'Email required.']); break; }
        $resp = $cf->createEmailAddress($accountId, $email);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    // ── Backups ──────────────────────────────────────────────────────────

    case 'backups_list':
        $backupDir = DB::setting('backup_destination', '/backup');
        $files = [];
        if (is_dir($backupDir)) {
            foreach (glob("{$backupDir}/{$username}_*.tgz") as $f) {
                $files[] = [
                    'filename' => basename($f),
                    'size'     => filesize($f),
                    'size_h'   => trim(shell_exec('du -sh ' . escapeshellarg($f) . ' 2>/dev/null | cut -f1') ?: '—'),
                    'date'     => date('Y-m-d H:i', filemtime($f)),
                ];
            }
        }
        usort($files, fn($a, $b) => strcmp($b['filename'], $a['filename']));
        echo json_encode(['success' => true, 'backups' => $files]);
        break;

    case 'backup_download':
        $file = trim($_GET['file'] ?? '');
        $backupDir = DB::setting('backup_destination', '/backup');
        // Validate: filename must match username_date.tgz pattern and belong to this user
        if (!$file || !preg_match('/^' . preg_quote($username, '/') . '_\d{4}-\d{2}-\d{2}\.tgz$/', $file)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            break;
        }
        $path = $backupDir . '/' . $file;
        if (!file_exists($path)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Backup not found.']);
            break;
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    // ── SSH Keys ──────────────────────────────────────────────────────────

    case 'ssh_keys_list':
        $result = runAccountKeyScript($username, 'list');
        echo json_encode($result);
        break;

    case 'ssh_keys_add':
        $key = trim($_POST['key'] ?? '');
        if (!$key) { echo json_encode(['success' => false, 'error' => 'No key provided.']); break; }
        $result = runAccountKeyScript($username, 'add', ['--key' => $key]);
        echo json_encode($result);
        break;

    case 'ssh_keys_delete':
        $fp = trim($_POST['fingerprint'] ?? '');
        if (!$fp) { echo json_encode(['success' => false, 'error' => 'No fingerprint.']); break; }
        $result = runAccountKeyScript($username, 'delete', ['--key' => $fp]);
        echo json_encode($result);
        break;

    case 'ssh_keys_generate':
        $result = runAccountKeyScript($username, 'generate');
        echo json_encode($result);
        break;

    // ── File Manager (.htaccess) ────────────────────────────────────────

    case 'list_dirs':
        $domainRow = DB::fetchOne('SELECT document_root FROM domains WHERE domain_name = ?', [$domain]);
        $docRoot = $domainRow['document_root'] ?? "/home/{$username}/{$domain}/www";
        if (!is_dir($docRoot)) { echo json_encode(['success' => false, 'error' => 'Document root not found.']); break; }
        $dirs = [['path' => '', 'has_htaccess' => file_exists("{$docRoot}/.htaccess"), 'is_protected' => file_exists("{$docRoot}/.htpasswd")]];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $iter->setMaxDepth(3);
        foreach ($iter as $file) {
            if (!$file->isDir()) continue;
            $rel = ltrim(str_replace($docRoot, '', $file->getPathname()), '/');
            if (strpos($rel, '.') === 0) continue; // skip hidden dirs
            $dirs[] = [
                'path' => $rel,
                'has_htaccess' => file_exists($file->getPathname() . '/.htaccess'),
                'is_protected' => file_exists($file->getPathname() . '/.htpasswd'),
            ];
        }
        usort($dirs, fn($a, $b) => strcmp($a['path'], $b['path']));
        echo json_encode(['success' => true, 'dirs' => $dirs]);
        break;

    case 'htaccess_read':
        $dir = trim($_POST['dir'] ?? '');
        $domainRow = DB::fetchOne('SELECT document_root FROM domains WHERE domain_name = ?', [$domain]);
        $docRoot = $domainRow['document_root'] ?? "/home/{$username}/{$domain}/www";
        $safePath = validateDirPath($docRoot, $dir);
        if ($safePath === false) { echo json_encode(['success' => false, 'error' => 'Invalid directory path.']); break; }
        $htFile = $safePath . '/.htaccess';
        $content = file_exists($htFile) ? (exec('sudo /bin/cat ' . escapeshellarg($htFile), $lines) !== false ? implode("\n", $lines) : '') : '';
        $isProtected = file_exists($safePath . '/.htpasswd');
        echo json_encode(['success' => true, 'content' => $content, 'is_protected' => $isProtected]);
        break;

    case 'htaccess_save':
        $dir = trim($_POST['dir'] ?? '');
        $content = $_POST['content'] ?? '';
        $domainRow = DB::fetchOne('SELECT document_root FROM domains WHERE domain_name = ?', [$domain]);
        $docRoot = $domainRow['document_root'] ?? "/home/{$username}/{$domain}/www";
        $safePath = validateDirPath($docRoot, $dir);
        if ($safePath === false) { echo json_encode(['success' => false, 'error' => 'Invalid directory path.']); break; }
        if (preg_match('/<\?php/i', $content)) {
            echo json_encode(['success' => false, 'error' => 'PHP code is not allowed in .htaccess files.']);
            break;
        }
        $htFile = $safePath . '/.htaccess';
        // Write via temp file + sudo cp (www-data can't write to user dirs)
        $tmp = tempnam('/tmp', 'inetp_ht_');
        file_put_contents($tmp, $content);
        exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($htFile) . ' 2>&1', $out, $rc);
        @unlink($tmp);
        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => 'Failed to write file.']);
            break;
        }
        exec('sudo /bin/chown ' . escapeshellarg("{$username}:www-data") . ' ' . escapeshellarg($htFile));
        exec('sudo /bin/chmod 644 ' . escapeshellarg($htFile));
        echo json_encode(['success' => true]);
        break;

    case 'htpasswd_users':
        $dir = trim($_POST['dir'] ?? '');
        $domainRow = DB::fetchOne('SELECT document_root FROM domains WHERE domain_name = ?', [$domain]);
        $docRoot = $domainRow['document_root'] ?? "/home/{$username}/{$domain}/www";
        $safePath = validateDirPath($docRoot, $dir);
        if ($safePath === false) { echo json_encode(['success' => false, 'error' => 'Invalid path.']); break; }
        $htpasswdFile = $safePath . '/.htpasswd';
        $users = [];
        if (file_exists($htpasswdFile)) {
            $pwLines = [];
            exec('sudo /bin/cat ' . escapeshellarg($htpasswdFile), $pwLines);
            foreach ($pwLines as $line) {
                $line = trim($line);
                if ($line && str_contains($line, ':')) {
                    $users[] = explode(':', $line, 2)[0];
                }
            }
        }
        echo json_encode(['success' => true, 'users' => $users]);
        break;

    case 'htpasswd_delete_user':
        $dir = trim($_POST['dir'] ?? '');
        $delUser = trim($_POST['ht_user'] ?? '');
        if (!$delUser) { echo json_encode(['success' => false, 'error' => 'No user specified.']); break; }
        $domainRow = DB::fetchOne('SELECT document_root FROM domains WHERE domain_name = ?', [$domain]);
        $docRoot = $domainRow['document_root'] ?? "/home/{$username}/{$domain}/www";
        $safePath = validateDirPath($docRoot, $dir);
        if ($safePath === false) { echo json_encode(['success' => false, 'error' => 'Invalid path.']); break; }
        $htpasswdFile = $safePath . '/.htpasswd';
        if (!file_exists($htpasswdFile)) { echo json_encode(['success' => true]); break; }
        $pwLines = [];
        exec('sudo /bin/cat ' . escapeshellarg($htpasswdFile), $pwLines);
        $remaining = array_filter($pwLines, fn($l) => !str_starts_with(trim($l), "{$delUser}:"));
        if (empty($remaining)) {
            // No users left — remove protection entirely
            exec('sudo /bin/rm -f ' . escapeshellarg($htpasswdFile));
            $htFile = $safePath . '/.htaccess';
            if (file_exists($htFile)) {
                $htLines = [];
                exec('sudo /bin/cat ' . escapeshellarg($htFile), $htLines);
                $content = implode("\n", $htLines);
                $content = preg_replace('/# BEGIN iNetPanel Directory Protection.*?# END iNetPanel Directory Protection\n?/s', '', $content);
                $tmp = tempnam('/tmp', 'inetp_ht_');
                file_put_contents($tmp, $content);
                exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($htFile));
                @unlink($tmp);
                exec('sudo /bin/chown ' . escapeshellarg("{$username}:www-data") . ' ' . escapeshellarg($htFile));
            }
        } else {
            $tmp = tempnam('/tmp', 'inetp_pw_');
            file_put_contents($tmp, implode("\n", $remaining) . "\n");
            exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($htpasswdFile));
            @unlink($tmp);
            exec('sudo /bin/chown ' . escapeshellarg("{$username}:www-data") . ' ' . escapeshellarg($htpasswdFile));
        }
        echo json_encode(['success' => true]);
        break;

    case 'dir_protect':
        $dir = trim($_POST['dir'] ?? '');
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $domainRow = DB::fetchOne('SELECT document_root FROM domains WHERE domain_name = ?', [$domain]);
        $docRoot = $domainRow['document_root'] ?? "/home/{$username}/{$domain}/www";
        $safePath = validateDirPath($docRoot, $dir);
        if ($safePath === false) { echo json_encode(['success' => false, 'error' => 'Invalid directory path.']); break; }

        $htFile = $safePath . '/.htaccess';
        $htpasswdFile = $safePath . '/.htpasswd';

        if ($enabled) {
            $htUser = trim($_POST['ht_user'] ?? '');
            $htPass = trim($_POST['ht_pass'] ?? '');
            if (!$htUser || !$htPass) {
                echo json_encode(['success' => false, 'error' => 'Username and password required.']);
                break;
            }
            // Create/append .htpasswd via temp file
            $hash = password_hash($htPass, PASSWORD_BCRYPT);
            $existingPasswd = '';
            if (file_exists($htpasswdFile)) {
                exec('sudo /bin/cat ' . escapeshellarg($htpasswdFile), $pwLines);
                // Remove existing entry for this user, keep others
                $existingPasswd = implode("\n", array_filter($pwLines, fn($l) => !str_starts_with(trim($l), "{$htUser}:")));
                if ($existingPasswd) $existingPasswd .= "\n";
            }
            $tmp = tempnam('/tmp', 'inetp_pw_');
            file_put_contents($tmp, $existingPasswd . "{$htUser}:{$hash}\n");
            exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($htpasswdFile));
            @unlink($tmp);
            exec('sudo /bin/chown ' . escapeshellarg("{$username}:www-data") . ' ' . escapeshellarg($htpasswdFile));
            exec('sudo /bin/chmod 640 ' . escapeshellarg($htpasswdFile));

            // Add protection block to .htaccess (replace existing if present)
            $existingHt = '';
            if (file_exists($htFile)) {
                $htLines = [];
                exec('sudo /bin/cat ' . escapeshellarg($htFile), $htLines);
                $existingHt = implode("\n", $htLines);
            }
            $existingHt = preg_replace('/# BEGIN iNetPanel Directory Protection.*?# END iNetPanel Directory Protection\n?/s', '', $existingHt);
            $protBlock = "# BEGIN iNetPanel Directory Protection\nAuthType Basic\nAuthName \"Restricted Area\"\nAuthUserFile {$htpasswdFile}\nRequire valid-user\n# END iNetPanel Directory Protection\n";
            $tmp = tempnam('/tmp', 'inetp_ht_');
            file_put_contents($tmp, $protBlock . $existingHt);
            exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($htFile));
            @unlink($tmp);
            exec('sudo /bin/chown ' . escapeshellarg("{$username}:www-data") . ' ' . escapeshellarg($htFile));
            exec('sudo /bin/chmod 644 ' . escapeshellarg($htFile));
        } else {
            // Remove protection
            exec('sudo /bin/rm -f ' . escapeshellarg($htpasswdFile));
            if (file_exists($htFile)) {
                $htLines = [];
                exec('sudo /bin/cat ' . escapeshellarg($htFile), $htLines);
                $content = implode("\n", $htLines);
                $content = preg_replace('/# BEGIN iNetPanel Directory Protection.*?# END iNetPanel Directory Protection\n?/s', '', $content);
                $tmp = tempnam('/tmp', 'inetp_ht_');
                file_put_contents($tmp, $content);
                exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($htFile));
                @unlink($tmp);
                exec('sudo /bin/chown ' . escapeshellarg("{$username}:www-data") . ' ' . escapeshellarg($htFile));
            }
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
