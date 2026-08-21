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

/**
 * Root-privileged access to a tenant's .htaccess/.htpasswd.
 *
 * All of it goes through scripts/system/ht_manage.py, which re-derives the
 * document root from the panel database and opens the file with O_NOFOLLOW.
 *
 * This replaced direct `sudo /bin/cp|cat|chown` calls on a path built here as
 * $safePath . '/.htaccess'. validateDirPath() realpath()-resolves the DIRECTORY
 * only, so that trailing component was never resolved — and a tenant owns their
 * document root over SFTP. Replacing .htaccess with a symlink made root cp/cat
 * follow it: arbitrary root read, write and chown, i.e. local privilege
 * escalation from the lowest-privilege account the panel issues.
 * (GHSA-mjmx-xpqq-p2h8, CWE-59.)
 *
 * No caller passes a path any more — only identity plus a relative directory.
 */
function htManage(string $action, string $user, string $domain, string $dir, string $which, ?string $content = null): array
{
    $cmd = 'sudo /root/scripts/ht_manage.py'
         . ' --user '   . escapeshellarg($user)
         . ' --domain ' . escapeshellarg($domain)
         . ' --dir '    . escapeshellarg($dir)
         . ' --file '   . escapeshellarg($which)
         . ' --action ' . escapeshellarg($action);
    $tmp = null;
    if ($action === 'write') {
        $tmp = tempnam('/tmp', 'inetp_ht_');
        file_put_contents($tmp, (string) $content);
        chmod($tmp, 0600);
        $cmd .= ' --content-file ' . escapeshellarg($tmp);
    }
    $out = [];
    $rc  = 0;
    exec($cmd . ' 2>/dev/null', $out, $rc);
    if ($tmp !== null) { @unlink($tmp); }
    return ['ok' => $rc === 0, 'content' => implode("\n", $out)];
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

    // ── Domains ─────────────────────────────────────────────────────────

    case 'list_domains':
        $domains = DB::fetchAll(
            'SELECT d.domain_name, d.document_root, d.php_version, d.port, d.status, d.created_at
             FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id
             WHERE h.username = ? ORDER BY d.domain_name',
            [$username]
        );
        // Add disk usage per domain
        foreach ($domains as &$d) {
            $dr = $d['document_root'] ?? "/home/{$username}/{$d['domain_name']}/www";
            $d['disk'] = trim(shell_exec('du -sh ' . escapeshellarg($dr) . ' 2>/dev/null | cut -f1') ?: '—');
        }
        unset($d);
        echo json_encode(['success' => true, 'domains' => $domains]);
        break;

    // ── Databases ───────────────────────────────────────────────────────

    case 'list_databases':
        $rootPass = trim(shell_exec('sudo /bin/cat /root/.mysql_root_pass 2>/dev/null') ?: '');
        $cmd = "mysql -u root -p" . escapeshellarg($rootPass) . " -N -e \"SHOW DATABASES LIKE '" . addslashes($username) . "\\_%'\" 2>/dev/null";
        $output = [];
        exec($cmd, $output);
        $dbs = [];
        foreach ($output as $dbName) {
            $dbName = trim($dbName);
            if (!$dbName) continue;
            // Get size
            $sizeCmd = "mysql -u root -p" . escapeshellarg($rootPass) . " -N -e \"SELECT IFNULL(ROUND(SUM(data_length + index_length), 0), 0) FROM information_schema.TABLES WHERE table_schema = '" . addslashes($dbName) . "'\" 2>/dev/null";
            $sizeBytes = (int) trim(shell_exec($sizeCmd) ?: '0');
            $dbs[] = ['name' => $dbName, 'size' => $sizeBytes, 'size_h' => $sizeBytes > 0 ? round($sizeBytes / 1024 / 1024, 2) . ' MB' : '0 MB'];
        }
        echo json_encode(['success' => true, 'databases' => $dbs]);
        break;

    case 'create_database':
        $suffix = trim($_POST['suffix'] ?? '');
        if (!$suffix || !preg_match('/^[a-zA-Z0-9_]{1,32}$/', $suffix)) {
            echo json_encode(['success' => false, 'error' => 'Invalid database name. Use letters, numbers, and underscores only (max 32 chars).']);
            break;
        }
        $dbName = $username . '_' . $suffix;
        $rootPass = trim(shell_exec('sudo /bin/cat /root/.mysql_root_pass 2>/dev/null') ?: '');
        // Check if exists
        $exists = trim(shell_exec("mysql -u root -p" . escapeshellarg($rootPass) . " -N -e \"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . addslashes($dbName) . "'\" 2>/dev/null") ?: '');
        if ($exists) {
            echo json_encode(['success' => false, 'error' => "Database '{$dbName}' already exists."]);
            break;
        }
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        $sql = "CREATE DATABASE `{$safeName}`; GRANT ALL PRIVILEGES ON `{$safeName}`.* TO '" . addslashes($username) . "'@'localhost'; FLUSH PRIVILEGES;";
        $result = shell_exec("mysql -u root -p" . escapeshellarg($rootPass) . " -e " . escapeshellarg($sql) . " 2>&1");
        if (stripos($result, 'error') !== false) {
            echo json_encode(['success' => false, 'error' => 'Failed to create database: ' . trim($result)]);
        } else {
            echo json_encode(['success' => true, 'database' => $dbName]);
        }
        break;

    // ── Multi-PHP ───────────────────────────────────────────────────────

    case 'list_php_versions':
        $installed = [];
        foreach (glob('/usr/sbin/php-fpm*') as $bin) {
            if (preg_match('/php-fpm(\d+\.\d+)/', $bin, $m)) {
                $installed[] = $m[1];
            }
        }
        sort($installed);
        $domains = DB::fetchAll(
            'SELECT d.domain_name, d.php_version FROM domains d
             JOIN hosting_users h ON d.hosting_user_id = h.id
             WHERE h.username = ? ORDER BY d.domain_name',
            [$username]
        );
        $defaultVer = DB::setting('php_default_version', '8.4');
        echo json_encode(['success' => true, 'installed' => $installed, 'default' => $defaultVer, 'domains' => $domains]);
        break;

    case 'set_php_version':
        $targetDomain = trim($_POST['domain'] ?? '');
        $newVer = trim($_POST['version'] ?? '');
        if (!$targetDomain || !$newVer) {
            echo json_encode(['success' => false, 'error' => 'Missing domain or version.']);
            break;
        }
        // Verify domain belongs to user
        if (!in_array($targetDomain, $ownedDomains, true)) {
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            break;
        }
        // Verify version is installed
        if (!file_exists("/usr/sbin/php-fpm{$newVer}")) {
            echo json_encode(['success' => false, 'error' => "PHP {$newVer} is not installed."]);
            break;
        }
        $currentDefault = DB::setting('php_default_version', '8.4');
        $domainRow = DB::fetchOne('SELECT php_version FROM domains WHERE domain_name = ?', [$targetDomain]);
        $currentVer = ($domainRow['php_version'] ?? 'inherit') === 'inherit' ? $currentDefault : $domainRow['php_version'];

        // Update domain record
        DB::query('UPDATE domains SET php_version = ? WHERE domain_name = ?', [$newVer, $targetDomain]);

        // Move FPM pool config
        $oldPool = "/etc/php/{$currentVer}/fpm/pool.d/{$targetDomain}.conf";
        $newPool = "/etc/php/{$newVer}/fpm/pool.d/{$targetDomain}.conf";
        if (file_exists($oldPool) && $currentVer !== $newVer) {
            $poolContent = file_get_contents($oldPool);
            $poolContent = str_replace("php{$currentVer}-fpm", "php{$newVer}-fpm", $poolContent);
            // No sudoers grant matches this cp, so it has never actually worked —
            // but leaving the /tmp pattern here is a trap for whoever adds one.
            // Stage it safely so a future grant cannot become the next symlink bug.
            $poolStage = Shell::stage('inetp_pool_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $targetDomain), $poolContent);
            if ($poolStage !== null) {
                shell_exec("sudo /bin/cp " . escapeshellarg($poolStage) . " " . escapeshellarg($newPool) . " 2>/dev/null");
            }
            shell_exec("sudo /bin/rm -f " . escapeshellarg($oldPool) . " 2>/dev/null");
            if ($poolStage !== null) { @unlink($poolStage); }
        }

        // Update Apache vhost socket
        $vhost = "/etc/apache2/sites-available/{$targetDomain}.conf";
        if (file_exists($vhost)) {
            shell_exec("sudo /bin/sed -i 's|php{$currentVer}-fpm|php{$newVer}-fpm|g' " . escapeshellarg($vhost) . " 2>/dev/null");
        }

        // Send response BEFORE reloading — FPM reload kills the current PHP process
        echo json_encode(['success' => true, 'version' => $newVer]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        // Reload services after response is sent
        shell_exec("sudo /bin/systemctl reload php{$currentVer}-fpm 2>/dev/null");
        shell_exec("sudo /bin/systemctl reload php{$newVer}-fpm 2>/dev/null");
        shell_exec("sudo /bin/systemctl reload apache2 2>/dev/null");
        break;

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

    // ── Image Optimizer ──────────────────────────────────────────────────

    case 'optimize_images':
        $dir = trim($_POST['dir'] ?? 'www/');
        // Build the full path: /home/{username}/{domain}/{dir}
        $siteDir = "/home/{$username}/{$reqDomain}";
        $targetDir = realpath($siteDir . '/' . $dir);
        if (!$targetDir || !str_starts_with($targetDir, $siteDir)) {
            echo json_encode(['success' => false, 'error' => 'Invalid directory.']);
            break;
        }
        // Build flags
        $args = ['--dir' => $targetDir];
        if (($_POST['dry_run'] ?? '0') === '1') $args[] = '--dry-run';
        if (($_POST['verbose'] ?? '0') === '1') $args[] = '--verbose';

        // Run optimize_images via inetp (runs as root via sudo)
        $result = Shell::run('optimize_images', $args);
        echo json_encode([
            'success' => $result['success'],
            'output'  => $result['output'] ?? '',
            'error'   => $result['success'] ? null : ($result['output'] ?: 'Optimization failed.'),
        ]);
        break;

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
        $content = htManage('read', $username, $domain, $dir, 'htaccess')['content'];
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
        $rc = htManage('write', $username, $domain, $dir, 'htaccess', $content)['ok'] ? 0 : 1;
        @unlink($tmp);
        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => 'Failed to write file.']);
            break;
        }
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
            $pwLines = explode("
", htManage('read', $username, $domain, $dir, 'htpasswd')['content']);
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
        $pwLines = explode("
", htManage('read', $username, $domain, $dir, 'htpasswd')['content']);
        $remaining = array_filter($pwLines, fn($l) => !str_starts_with(trim($l), "{$delUser}:"));
        if (empty($remaining)) {
            // No users left — remove protection entirely
            htManage('delete', $username, $domain, $dir, 'htpasswd');
            $htFile = $safePath . '/.htaccess';
            if (file_exists($htFile)) {
                $htLines = [];
                $htLines = explode("
", htManage('read', $username, $domain, $dir, 'htaccess')['content']);
                $content = implode("\n", $htLines);
                $content = preg_replace('/# BEGIN iNetPanel Directory Protection.*?# END iNetPanel Directory Protection\n?/s', '', $content);
                $tmp = tempnam('/tmp', 'inetp_ht_');
                file_put_contents($tmp, $content);
                htManage('write', $username, $domain, $dir, 'htaccess', file_get_contents($tmp));
                @unlink($tmp);
            }
        } else {
            $tmp = tempnam('/tmp', 'inetp_pw_');
            file_put_contents($tmp, implode("\n", $remaining) . "\n");
            htManage('write', $username, $domain, $dir, 'htpasswd', file_get_contents($tmp));
            @unlink($tmp);
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
                $pwLines = explode("
", htManage('read', $username, $domain, $dir, 'htpasswd')['content']);
                // Remove existing entry for this user, keep others
                $existingPasswd = implode("\n", array_filter($pwLines, fn($l) => !str_starts_with(trim($l), "{$htUser}:")));
                if ($existingPasswd) $existingPasswd .= "\n";
            }
            $tmp = tempnam('/tmp', 'inetp_pw_');
            file_put_contents($tmp, $existingPasswd . "{$htUser}:{$hash}\n");
            htManage('write', $username, $domain, $dir, 'htpasswd', file_get_contents($tmp));
            @unlink($tmp);

            // Add protection block to .htaccess (replace existing if present)
            $existingHt = '';
            if (file_exists($htFile)) {
                $htLines = [];
                $htLines = explode("
", htManage('read', $username, $domain, $dir, 'htaccess')['content']);
                $existingHt = implode("\n", $htLines);
            }
            $existingHt = preg_replace('/# BEGIN iNetPanel Directory Protection.*?# END iNetPanel Directory Protection\n?/s', '', $existingHt);
            $protBlock = "# BEGIN iNetPanel Directory Protection\nAuthType Basic\nAuthName \"Restricted Area\"\nAuthUserFile {$htpasswdFile}\nRequire valid-user\n# END iNetPanel Directory Protection\n";
            $tmp = tempnam('/tmp', 'inetp_ht_');
            file_put_contents($tmp, $protBlock . $existingHt);
            htManage('write', $username, $domain, $dir, 'htaccess', file_get_contents($tmp));
            @unlink($tmp);
        } else {
            // Remove protection
            htManage('delete', $username, $domain, $dir, 'htpasswd');
            if (file_exists($htFile)) {
                $htLines = [];
                $htLines = explode("
", htManage('read', $username, $domain, $dir, 'htaccess')['content']);
                $content = implode("\n", $htLines);
                $content = preg_replace('/# BEGIN iNetPanel Directory Protection.*?# END iNetPanel Directory Protection\n?/s', '', $content);
                $tmp = tempnam('/tmp', 'inetp_ht_');
                file_put_contents($tmp, $content);
                htManage('write', $username, $domain, $dir, 'htaccess', file_get_contents($tmp));
                @unlink($tmp);
            }
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
