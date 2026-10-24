<?php
// FILE: api/backups.php
// iNetPanel — Backups API
// Actions: list, download, run, settings_get, settings_save,
//          s3_status, s3_save, s3_test, s3_upload  (remote backup — see scripts/system/s3_backup.py)


$action = $_GET['action'] ?? $_POST['action'] ?? '';

$backupDir = DB::setting('backup_destination', '/backup');
if (str_contains($backupDir, '..')) $backupDir = '/backup';

// Remote (S3) form fields, validated the same way s3_backup.py re-validates
// them before acting as root. Returns [fields, error|null]. The secret key is
// not among them: it goes to root over stdin and is never stored here.
// Every pattern is anchored with \z (or the D modifier): a bare `$` would also
// accept a trailing newline.
$s3Form = function (): array {
    $f = [
        'endpoint'          => rtrim(trim($_POST['s3_endpoint'] ?? ''), '/'),
        'region'            => trim($_POST['s3_region'] ?? '') ?: 'us-east-1',
        'bucket'            => trim($_POST['s3_bucket'] ?? ''),
        'prefix'            => trim(trim($_POST['s3_prefix'] ?? ''), '/'),
        'access_key'        => trim($_POST['s3_access_key'] ?? ''),
        'path_style'        => trim($_POST['s3_path_style'] ?? 'auto'),
        'retention_enabled' => ($_POST['s3_retention_enabled'] ?? '0') === '1' ? '1' : '0',
        'retention_days'    => trim($_POST['s3_retention_days'] ?? '30'),
        'bandwidth_mbit'    => trim($_POST['s3_bandwidth_mbit'] ?? '0') ?: '0',
        'include_system'    => ($_POST['s3_include_system'] ?? '1') === '0' ? '0' : '1',
    ];
    if ($f['endpoint'] !== '') {
        $m = [];
        if (!preg_match('#^https?://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::(\d{1,5}))?\z#', $f['endpoint'], $m)
            || (isset($m[2]) && $m[2] !== '' && ((int)$m[2] < 1 || (int)$m[2] > 65535))) {
            return [$f, 'Endpoint must be a URL like https://host or https://host:port, with no path.'];
        }
    }
    if (!preg_match('/^[a-z0-9-]{1,32}\z/', $f['region'])) {
        return [$f, 'Region must be lowercase letters, digits and dashes (e.g. us-east-1, or auto for Cloudflare R2).'];
    }
    if ($f['bucket'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,61}[A-Za-z0-9]\z/', $f['bucket'])) {
        return [$f, 'Bucket name must be 3-63 characters: letters, digits, dots, dashes, underscores.'];
    }
    if ($f['prefix'] !== '') {
        foreach (explode('/', $f['prefix']) as $seg) {
            if ($seg === '.' || $seg === '..' || !preg_match('/^[A-Za-z0-9._-]+\z/', $seg)
                || strlen($f['prefix']) > 200) {
                return [$f, 'Folder may use letters, digits, ".", "_", "-" and "/" between folders.'];
            }
        }
    }
    if ($f['access_key'] !== '' && !preg_match('/^[\x21-\x7e]{3,128}\z/', $f['access_key'])) {
        return [$f, 'Access key ID must not contain spaces.'];
    }
    if (!in_array($f['path_style'], ['auto', 'path', 'virtual'], true)) {
        return [$f, 'Invalid addressing style.'];
    }
    if (!ctype_digit($f['retention_days']) || (int)$f['retention_days'] < 1 || (int)$f['retention_days'] > 3650) {
        return [$f, 'Retention must be between 1 and 3650 days.'];
    }
    if (!ctype_digit($f['bandwidth_mbit']) || (int)$f['bandwidth_mbit'] > 100000) {
        return [$f, 'Speed limit must be a whole number of Mbit/s (0 for no limit).'];
    }
    return [$f, null];
};

// s3_backup.py --json prints one JSON object as its last line. Anything else
// usually means /usr/local/bin/inetp predates this feature (it then prints its
// help text), so say that rather than show the help text as an error.
$s3Json = function (array $r): ?array {
    foreach (array_reverse(explode("\n", trim($r['output'] ?? ''))) as $line) {
        if (str_starts_with(ltrim($line), '{')) {
            $j = json_decode($line, true);
            if (is_array($j)) return $j;
        }
    }
    return null;
};
$s3NoJson = function (array $r, string $what): string {
    if (str_contains($r['output'] ?? '', 'inetp — Server Account Manager')) {
        return $what . ': the inetp command on this server is out of date. As root, run: '
             . 'php /var/www/inetpanel/scripts/panel_update.php --deploy-only';
    }
    return $what . ': ' . (trim($r['output'] ?? '') ?: 'no output');
};

// The remote-backup actions change state or start work: POST only, so a
// link or an <img> cannot trigger them.
$s3PostOnly = function (): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required.']);
        return false;
    }
    return true;
};

switch ($action) {

    case 'download':
        Auth::requireAdmin();
        $filename = basename(trim($_GET['file'] ?? ''));
        $filepath = $backupDir . '/' . $filename;
        if (!$filename || !preg_match('/^[\w.\-]+\.tgz$/', $filename) || !is_file($filepath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'File not found.']);
            break;
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;

    case 'list':
        Auth::requireAdmin();
        $files = glob($backupDir . '/*.tgz') ?: [];
        rsort($files);
        $data = [];
        foreach ($files as $f) {
            $bytes = filesize($f);
            if ($bytes < 1024)          $sizeHr = $bytes . ' B';
            elseif ($bytes < 1048576)   $sizeHr = round($bytes / 1024, 1) . ' KB';
            elseif ($bytes < 1073741824)$sizeHr = round($bytes / 1048576, 1) . ' MB';
            else                        $sizeHr = round($bytes / 1073741824, 2) . ' GB';
            $data[] = [
                'filename' => basename($f),
                'path'     => $f,
                'size'     => $bytes,
                'size_hr'  => $sizeHr,
                'date'     => date('Y-m-d H:i:s', filemtime($f)),
            ];
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'run':
        Auth::requireAdmin();
        $domain = trim($_POST['domain'] ?? '');
        if ($domain) {
            $result = Shell::run('backup_accounts', ['--single' => $domain]);
        } else {
            $result = Shell::run('backup_accounts', []);
        }
        echo json_encode($result);
        break;

    case 'settings_get':
        Auth::requireAdmin();
        echo json_encode([
            'success' => true,
            'data'    => [
                'backup_enabled'     => DB::setting('backup_enabled',     '0'),
                'backup_destination' => DB::setting('backup_destination', '/backup'),
                'backup_retention'   => DB::setting('backup_retention',   '3'),
            ],
        ]);
        break;

    case 'settings_save':
        Auth::requireAdmin();
        $dest = trim($_POST['backup_destination'] ?? '/backup');
        if (!str_starts_with($dest, '/') || str_contains($dest, '..')) {
            echo json_encode(['success' => false, 'error' => 'Invalid backup destination path.']);
            break;
        }
        if (!is_dir($dest)) {
            echo json_encode(['success' => false, 'error' => 'Backup destination directory does not exist.']);
            break;
        }
        $retention = max(1, min(365, (int)($_POST['backup_retention'] ?? 3)));
        DB::saveSetting('backup_enabled',     $_POST['backup_enabled'] ?? '0');
        DB::saveSetting('backup_destination', $dest);
        DB::saveSetting('backup_retention',   (string)$retention);
        echo json_encode(['success' => true]);
        break;

    // ── Remote backup (S3) ──────────────────────────────────────────────────

    case 's3_status':
        Auth::requireAdmin();
        $status = json_decode(DB::setting('s3_status', '') ?: 'null', true);
        $running = false;
        if (is_array($status) && ($status['state'] ?? '') === 'running') {
            // The worker pings s3_heartbeat every 30 s from its own thread, even
            // while it waits out an outage; silence means it was killed (reboot,
            // OOM) without recording why. The hourly job then picks it up.
            $beat = max((int)($status['heartbeat_ts'] ?? 0), (int)DB::setting('s3_heartbeat', '0'));
            $running = (time() - $beat) < 180;
            if (!$running) $status['state'] = 'interrupted';
        }
        echo json_encode(['success' => true, 'data' => [
            'enabled'    => DB::setting('s3_enabled', '0') === '1',
            'secret_set' => DB::setting('s3_secret_set', '0') === '1',
            'running'    => $running,
            'status'     => is_array($status) ? $status : null,
        ]]);
        break;

    case 's3_save':
        Auth::requireAdmin();
        if (!$s3PostOnly()) break;
        [$f, $err] = $s3Form();
        $enabled   = ($_POST['s3_enabled'] ?? '0') === '1';
        $secret    = trim((string)($_POST['s3_secret'] ?? ''));
        $secretSet = DB::setting('s3_secret_set', '0') === '1';
        if (!$err && $secret !== '' && !preg_match('/^[\x21-\x7e]{8,256}\z/', $secret)) {
            $err = 'Secret access key must be 8-256 characters with no spaces.';
        }
        if (!$err && $enabled) {
            if ($f['bucket'] === '')         $err = 'Enter the bucket name.';
            elseif ($f['access_key'] === '') $err = 'Enter the access key ID.';
            elseif ($secret === '' && !$secretSet) $err = 'Enter the secret access key.';
        }
        if ($err) {
            echo json_encode(['success' => false, 'error' => $err]);
            break;
        }
        // Store the secret first: if root cannot take it, nothing is saved and
        // the form does not claim a key that does not exist.
        if ($secret !== '') {
            $r = Shell::runWithInput('s3_backup', ['set-secret', '--json'], $secret);
            $j = $s3Json($r);
            if (!$r['success'] || !($j['success'] ?? false)) {
                echo json_encode(['success' => false,
                    'error' => $j['error'] ?? $s3NoJson($r, 'Could not store the secret key')]);
                break;
            }
            DB::saveSetting('s3_secret_set', '1');
        }
        DB::saveSetting('s3_enabled',           $enabled ? '1' : '0');
        DB::saveSetting('s3_endpoint',          $f['endpoint']);
        DB::saveSetting('s3_region',            $f['region']);
        DB::saveSetting('s3_bucket',            $f['bucket']);
        DB::saveSetting('s3_prefix',            $f['prefix']);
        DB::saveSetting('s3_access_key',        $f['access_key']);
        DB::saveSetting('s3_path_style',        $f['path_style']);
        DB::saveSetting('s3_retention_enabled', $f['retention_enabled']);
        DB::saveSetting('s3_retention_days',    (string)(int)$f['retention_days']);
        DB::saveSetting('s3_bandwidth_mbit',    (string)(int)$f['bandwidth_mbit']);
        DB::saveSetting('s3_include_system',    $f['include_system']);
        // Install or remove the hourly retry job to match. 'deferred' means
        // /etc is read-only in php-fpm's sandbox here; the next upload run,
        // which runs outside it, puts the job right.
        $c = $s3Json(Shell::run('s3_backup', ['cron', '--json']));
        echo json_encode(['success' => true, 'secret_set' => DB::setting('s3_secret_set', '0') === '1',
                          'cron' => $c['cron'] ?? 'unknown']);
        break;

    case 's3_test':
        // Tests what is in the form, saved or not. A secret left blank means
        // "use the saved one", which only root can read.
        Auth::requireAdmin();
        if (!$s3PostOnly()) break;
        [$f, $err] = $s3Form();
        $secret = trim((string)($_POST['s3_secret'] ?? ''));
        if (!$err && $f['bucket'] === '')     $err = 'Enter the bucket name.';
        if (!$err && $f['access_key'] === '') $err = 'Enter the access key ID.';
        if (!$err && $secret === '' && DB::setting('s3_secret_set', '0') !== '1') $err = 'Enter the secret access key.';
        if ($err) {
            echo json_encode(['success' => false, 'error' => $err]);
            break;
        }
        $r = Shell::runWithInput('s3_backup', ['test', '--json'], json_encode($f + ['secret' => $secret]));
        echo json_encode($s3Json($r) ?? ['success' => false, 'error' => $s3NoJson($r, 'The test did not run')]);
        break;

    case 's3_upload':
        Auth::requireAdmin();
        if (!$s3PostOnly()) break;
        if (DB::setting('s3_enabled', '0') !== '1') {
            echo json_encode(['success' => false, 'error' => 'Turn on remote backup and save first.']);
            break;
        }
        // --manual: also retry archives that are waiting out a back-off.
        $r = Shell::run('s3_backup', ['upload', '--manual', '--json']);
        echo json_encode($s3Json($r) ?? ['success' => false, 'error' => $s3NoJson($r, 'Could not start the upload')]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
