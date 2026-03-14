<?php
// FILE: api/backups.php
// iNetPanel — Backups API
// Actions: list, download, run, settings_get, settings_save


$action = $_GET['action'] ?? $_POST['action'] ?? '';

$backupDir = DB::setting('backup_destination', '/backup');
if (str_contains($backupDir, '..')) $backupDir = '/backup';

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

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
