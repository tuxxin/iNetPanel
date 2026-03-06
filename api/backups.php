<?php
// FILE: api/backups.php
// iNetPanel — Backups API
// Actions: list, run, settings_get, settings_save


$action = $_GET['action'] ?? $_POST['action'] ?? '';

$backupDir = DB::setting('backup_destination', '/backup');

switch ($action) {

    case 'list':
        $files = glob($backupDir . '/*.tgz') ?: [];
        rsort($files);
        $data = [];
        foreach ($files as $f) {
            $data[] = [
                'filename' => basename($f),
                'path'     => $f,
                'size'     => filesize($f),
                'size_hr'  => round(filesize($f) / 1048576, 1) . ' MB',
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
        DB::saveSetting('backup_enabled',     $_POST['backup_enabled']     ?? '0');
        DB::saveSetting('backup_destination', $_POST['backup_destination'] ?? '/backup');
        DB::saveSetting('backup_retention',   $_POST['backup_retention']   ?? '3');
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
