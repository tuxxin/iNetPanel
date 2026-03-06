<?php
// FILE: api/logs.php
// iNetPanel — Logs API
// Actions: tail (system logs), domain (per-domain Apache/PHP logs)

Auth::check();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

$SYSTEM_LOGS = [
    'update'   => '/var/log/lamp_update.log',
    'backup'   => '/var/log/lamp_backup.log',
    'lighttpd' => '/var/log/lighttpd/error.log',
];

function tailFile(string $path, int $lines = 300): string
{
    if (!file_exists($path)) return "(File not found: {$path})";
    $out = [];
    exec('tail -n ' . (int)$lines . ' ' . escapeshellarg($path) . ' 2>&1', $out);
    return implode("\n", $out) ?: '(Log is empty)';
}

switch ($action) {

    case 'tail':
        $key = $_GET['key'] ?? '';
        if (!isset($SYSTEM_LOGS[$key])) {
            echo json_encode(['success' => false, 'error' => 'Unknown log.']); break;
        }
        echo json_encode(['success' => true, 'content' => tailFile($SYSTEM_LOGS[$key])]);
        break;

    case 'domain':
        $domain  = trim($_GET['domain'] ?? '');
        $logtype = $_GET['logtype'] ?? 'apache_error';

        if (!$domain || !preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain.']); break;
        }

        // Sub-admin domain check
        if (!\TiCore\Auth::isAdmin() && !\TiCore\Auth::canAccessDomain($domain)) {
            echo json_encode(['success' => false, 'error' => 'Access denied.']); break;
        }

        $logFiles = [
            'apache_error'  => "/home/{$domain}/logs/apache_error.log",
            'apache_access' => "/home/{$domain}/logs/apache_access.log",
            'php_error'     => "/home/{$domain}/logs/php_error.log",
        ];

        if (!isset($logFiles[$logtype])) {
            echo json_encode(['success' => false, 'error' => 'Unknown log type.']); break;
        }

        echo json_encode(['success' => true, 'content' => tailFile($logFiles[$logtype])]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
