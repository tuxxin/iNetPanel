<?php
// FILE: api/services.php
// iNetPanel — Services API
// Actions: list, restart, start, stop


$action = $_GET['action'] ?? $_POST['action'] ?? '';

$serviceList = [
    ['name' => 'apache2',      'label' => 'Apache2',      'icon' => 'fab fa-firefox-browser'],
    ['name' => 'lighttpd',     'label' => 'lighttpd',     'icon' => 'fas fa-bolt'],
    ['name' => 'php8.4-fpm',   'label' => 'PHP 8.4-FPM',  'icon' => 'fab fa-php'],
    ['name' => 'mariadb',      'label' => 'MariaDB',       'icon' => 'fas fa-database'],
    ['name' => 'vsftpd',       'label' => 'vsftpd (FTP)',  'icon' => 'fas fa-folder-open'],
    ['name' => 'wg-quick@wg0', 'label' => 'WireGuard',    'icon' => 'fas fa-shield-halved'],
    ['name' => 'cron',         'label' => 'Cron',          'icon' => 'fas fa-clock'],
];

switch ($action) {

    case 'list':
        $data = [];
        foreach ($serviceList as $svc) {
            $active = Shell::isServiceActive($svc['name']);
            $data[] = [
                'name'   => $svc['name'],
                'label'  => $svc['label'],
                'icon'   => $svc['icon'],
                'status' => $active ? 'active' : 'inactive',
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
        $result = Shell::systemctl($action, $service);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
