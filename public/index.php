<?php
// FILE: public/index.php
// iNetPanel - Main Entry Point
// Bootstrapped via TiCore PHP Framework
// https://github.com/tuxxin/iNetPanel

// -------------------------------------------------------------------
// 1. ERROR REPORTING (disable in production via TiCore/.env APP_DEBUG)
// -------------------------------------------------------------------
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// -------------------------------------------------------------------
// 2. PATH DEFINITIONS
// -------------------------------------------------------------------
define('ROOT_PATH',   dirname(__DIR__));
define('TICORE_PATH', ROOT_PATH . '/TiCore');
define('THEME_PATH',  ROOT_PATH . '/themes/default');
define('SRC_PATH',    ROOT_PATH . '/src');
define('CONF_PATH',   ROOT_PATH . '/conf');
define('API_PATH',    ROOT_PATH . '/api');

// -------------------------------------------------------------------
// 3. TICORE CLASS LOADER
// -------------------------------------------------------------------
require_once TICORE_PATH . '/Config.php';
require_once TICORE_PATH . '/Router.php';
require_once TICORE_PATH . '/DB.php';
require_once TICORE_PATH . '/Auth.php';
require_once TICORE_PATH . '/Shell.php';
require_once TICORE_PATH . '/View.php';
require_once TICORE_PATH . '/CloudflareAPI.php';
require_once TICORE_PATH . '/Version.php';
require_once TICORE_PATH . '/App.php';

// -------------------------------------------------------------------
// 4. BOOTSTRAP APPLICATION
// -------------------------------------------------------------------
$app    = App::getInstance();
$router = $app->getRouter();
$config = $app->getConfig();

// Enable debug mode from .env
if ($config->get('APP_DEBUG') === 'true') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// -------------------------------------------------------------------
// 5. VIEW HELPER
// -------------------------------------------------------------------
$view = new View(THEME_PATH);

// -------------------------------------------------------------------
// 6. ROUTE DEFINITIONS
// -------------------------------------------------------------------

// Root redirect
$router->add('/', function () {
    header('Location: /admin/dashboard');
    exit;
});

// Login — GET shows form; POST handles auth
$router->add('/login', function () use ($view) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (Auth::login($user, $pass)) {
            header('Location: /admin/dashboard');
        } else {
            $view->render('login.php', ['error' => 'Invalid username or password.']);
        }
        exit;
    }
    $view->render('login.php');
});

// Logout
$router->add('/logout', function () {
    Auth::logout();
});

// -------------------------------------------------------------------
// ADMIN ROUTES — all guarded by Auth::check()
// -------------------------------------------------------------------

$router->add('/admin', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Dashboard', SRC_PATH . '/dashboard.php');
});
$router->add('/admin/dashboard', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Dashboard', SRC_PATH . '/dashboard.php');
});

// Accounts
$router->add('/admin/add-account', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Add Account', SRC_PATH . '/add_account.php');
});
$router->add('/admin/accounts', function () use ($view) {
    Auth::check();
    $view->renderAdmin('List Accounts', SRC_PATH . '/accounts.php');
});

// Settings
$router->add('/admin/settings', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Settings', SRC_PATH . '/settings.php');
});

// Services
$router->add('/admin/services', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Services Status', SRC_PATH . '/services.php');
});

// Multi-PHP
$router->add('/admin/multi-php', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Multi-PHP Manager', SRC_PATH . '/multiphp.php');
});

// PHP Packages
$router->add('/admin/php-packages', function () use ($view) {
    Auth::check();
    $view->renderAdmin('PHP Packages', SRC_PATH . '/php_packages.php');
});

// Backups
$router->add('/admin/backups', function () use ($view) {
    Auth::check();
    $view->renderAdmin('System Backups', SRC_PATH . '/backups.php');
});

// Logs
$router->add('/admin/logs', function () use ($view) {
    Auth::check();
    $view->renderAdmin('System Logs', SRC_PATH . '/logs.php');
});

// DNS (Cloudflare)
$router->add('/admin/dns', function () use ($view) {
    Auth::check();
    $view->renderAdmin('DNS Settings', SRC_PATH . '/dns.php');
});

// Email Routing (Cloudflare)
$router->add('/admin/email', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Email Routing', SRC_PATH . '/email.php');
});

// Panel Users (admin only)
$router->add('/admin/panel-users', function () use ($view) {
    Auth::requireAdmin();
    $view->renderAdmin('Panel Users', SRC_PATH . '/panel_users.php');
});

// -------------------------------------------------------------------
// API ROUTES — session-protected JSON endpoints
// -------------------------------------------------------------------
foreach ([
    '/api/accounts'    => 'accounts.php',
    '/api/services'    => 'services.php',
    '/api/multiphp'    => 'multiphp.php',
    '/api/packages'    => 'packages.php',
    '/api/dns'         => 'dns.php',
    '/api/email'       => 'email.php',
    '/api/backups'     => 'backups.php',
    '/api/settings'    => 'settings.php',
    '/api/panel-users' => 'panel_users.php',
    '/api/wireguard'      => 'wireguard.php',
    '/api/logs'           => 'logs.php',
    '/api/update_check'   => 'update_check.php',
    '/api/ssh-keys'       => 'ssh_keys.php',
] as $route => $file) {
    $router->add($route, function () use ($file) {
        Auth::check();
        header('Content-Type: application/json');
        $path = API_PATH . '/' . $file;
        if (file_exists($path)) {
            require $path;
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'API endpoint not found']);
        }
        exit;
    });
}

// -------------------------------------------------------------------
// 7. DISPATCH REQUEST
// -------------------------------------------------------------------
$app->run();
