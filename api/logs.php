<?php
// FILE: api/logs.php
// iNetPanel — Logs API
// Actions: tail (system logs), domain (per-domain Apache/PHP logs)

Auth::check();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

$SYSTEM_LOGS = [
    'update'     => '/var/log/lamp_update.log',
    'backup'     => '/var/log/lamp_backup.log',
    'lighttpd'   => '/var/log/lighttpd/error.log',
    'ssl'        => '/var/log/letsencrypt/letsencrypt.log',
    'ssl_renew'  => '/var/log/certbot_renew.log',
    'auth'       => '/var/log/auth.log',
    'fail2ban'   => '/var/log/fail2ban.log',
    'panel_auth' => '/var/log/inetpanel_auth.log',
];

function tailFile(string $path, int $lines = 300): string
{
    if (!file_exists($path)) return "(No log entries yet.)";
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
        if (!Auth::hasFullAccess() && !Auth::canAccessDomain($domain)) {
            echo json_encode(['success' => false, 'error' => 'Access denied.']); break;
        }

        // Resolve log directory: new multi-domain structure, fallback to legacy
        $domainRow = DB::fetchOne(
            'SELECT h.username FROM hosting_users h JOIN domains d ON d.hosting_user_id = h.id WHERE d.domain_name = ?',
            [$domain]
        );
        $username = $domainRow['username'] ?? null;
        $logBase = ($username && is_dir("/home/{$username}/{$domain}/logs"))
            ? "/home/{$username}/{$domain}/logs"
            : "/home/{$domain}/logs";

        $logFiles = [
            'apache_error'  => "{$logBase}/error.log",
            'apache_access' => "{$logBase}/access.log",
            'php_error'     => "{$logBase}/php_error.log",
        ];

        if (!isset($logFiles[$logtype])) {
            echo json_encode(['success' => false, 'error' => 'Unknown log type.']); break;
        }

        echo json_encode(['success' => true, 'content' => tailFile($logFiles[$logtype])]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
