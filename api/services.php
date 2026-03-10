<?php
// FILE: api/services.php
// iNetPanel — Services API
// Actions: list, restart, start, stop


$action = $_GET['action'] ?? $_POST['action'] ?? '';

$phpDefault = DB::setting('php_default_version', '8.4');
$serviceList = [
    ['name' => 'apache2',                  'label' => 'Apache2',                    'icon' => 'fab fa-firefox-browser'],
    ['name' => 'lighttpd',                 'label' => 'lighttpd (iNetPanel Admin)', 'icon' => 'fas fa-bolt', 'locked' => true],
    ['name' => "php{$phpDefault}-fpm",     'label' => "PHP {$phpDefault}-FPM",      'icon' => 'fab fa-php'],
    ['name' => 'mariadb',      'label' => 'MariaDB',                    'icon' => 'fas fa-database'],
    ['name' => 'vsftpd',       'label' => 'vsftpd (FTP)',               'icon' => 'fas fa-folder-open'],
    ['name' => 'wg-quick@wg0', 'label' => 'WireGuard',                 'icon' => 'fas fa-shield-halved'],
    ['name' => 'firewalld',    'label' => 'Firewalld',                  'icon' => 'fas fa-shield-halved'],
    ['name' => 'fail2ban',     'label' => 'Fail2Ban',                   'icon' => 'fas fa-ban'],
    ['name' => 'cloudflared',  'label' => 'Cloudflared',                'icon' => 'fas fa-cloud'],
    ['name' => 'cron',         'label' => 'Cron',                       'icon' => 'fas fa-clock'],
];

switch ($action) {

    case 'list':
        $data = [];
        foreach ($serviceList as $svc) {
            $data[] = [
                'name'   => $svc['name'],
                'label'  => $svc['label'],
                'icon'   => $svc['icon'],
                'status' => Shell::serviceStatus($svc['name']),
                'locked' => !empty($svc['locked']),
            ];
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'restart':
    case 'start':
    case 'stop':
        Auth::requireAdmin();
        $service = trim($_POST['service'] ?? '');
        if (!$service) { echo json_encode(['success' => false, 'error' => 'Service required.']); break; }
        // Enforce lock server-side — locked services cannot be stopped/restarted via panel
        $lockedServices = array_column(array_filter($serviceList, fn($s) => !empty($s['locked'])), 'name');
        if (in_array($service, $lockedServices, true)) {
            echo json_encode(['success' => false, 'error' => "Service '{$service}' is managed by iNetPanel and cannot be controlled here."]); break;
        }
        $result = Shell::systemctl($action, $service);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
