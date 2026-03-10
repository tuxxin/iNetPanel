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

// Logs that require sudo to read (root-owned, not readable by www-data)
$RESTRICTED_LOGS = ['auth', 'fail2ban'];

function tailFile(string $path, int $lines = 300, bool $useSudo = false): string
{
    if (!$useSudo && !file_exists($path)) return "(Log file not found: {$path})";
    $cmd = ($useSudo ? 'sudo ' : '') . 'tail -n ' . (int)$lines . ' ' . escapeshellarg($path) . ' 2>&1';
    $out = [];
    exec($cmd, $out);
    $result = implode("\n", $out);
    return $result ?: '(Log is empty)';
}

switch ($action) {

    case 'tail':
        $key = $_GET['key'] ?? '';
        if (!isset($SYSTEM_LOGS[$key])) {
            echo json_encode(['success' => false, 'error' => 'Unknown log.']); break;
        }
        $useSudo = in_array($key, $RESTRICTED_LOGS);
        echo json_encode(['success' => true, 'content' => tailFile($SYSTEM_LOGS[$key], 300, $useSudo)]);
        break;

    case 'panel':
        $limit = min((int)($_GET['limit'] ?? 200), 500);
        $rows = DB::fetchAll(
            'SELECT created_at, level, source, message, details, user, ip_address FROM logs ORDER BY id DESC LIMIT ?',
            [$limit]
        );
        $lines = [];
        foreach ($rows as $r) {
            $ts   = $r['created_at'] ?? '';
            $lvl  = $r['level'] ?? 'INFO';
            $src  = $r['source'] ?? 'panel';
            $msg  = $r['message'] ?? '';
            $usr  = $r['user'] ? " ({$r['user']})" : '';
            $det  = $r['details'] ? " — {$r['details']}" : '';
            $lines[] = "{$ts} [{$lvl}] {$src}: {$msg}{$usr}{$det}";
        }
        echo json_encode(['success' => true, 'content' => implode("\n", $lines) ?: '(No activity logged yet.)']);
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
