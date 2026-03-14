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

// lighttpd url.rewrite-if-not-file does not forward QUERY_STRING to PHP-FPM.
// Parse it from REQUEST_URI so $_GET is populated correctly for API routes.
if (empty($_GET) && !empty($_SERVER['REQUEST_URI'])) {
    $qs = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    if ($qs) {
        parse_str($qs, $_GET);
    }
}

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
require_once TICORE_PATH . '/AccountAuth.php';
require_once TICORE_PATH . '/Shell.php';
require_once TICORE_PATH . '/View.php';
require_once TICORE_PATH . '/CloudflareAPI.php';
require_once TICORE_PATH . '/Version.php';
require_once TICORE_PATH . '/App.php';

define('ACCOUNT_THEME_PATH', ROOT_PATH . '/themes/account');

// -------------------------------------------------------------------
// 4. BOOTSTRAP APPLICATION
// -------------------------------------------------------------------
$app    = App::getInstance();
$router = $app->getRouter();
$config = $app->getConfig();

// -------------------------------------------------------------------
// SECURITY HEADERS — applied to every response
// -------------------------------------------------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// Apply saved timezone to PHP runtime (after App init so DB can connect)
try {
    date_default_timezone_set(DB::setting('timezone', 'UTC'));
} catch (\Throwable) {}

// Auto-detect php_default_version if unset or the stored version is no longer installed
try {
    $__phpVer = DB::setting('php_default_version', '');
    if (!$__phpVer || !file_exists("/usr/sbin/php-fpm{$__phpVer}")) {
        $__bins = glob('/usr/sbin/php-fpm*') ?: [];
        $__vers = [];
        foreach ($__bins as $__bin) {
            if (preg_match('/php-fpm(\d+\.\d+)$/', $__bin, $__m)) $__vers[] = $__m[1];
        }
        if (empty($__vers)) $__vers = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4','8.5'];
        foreach (array_reverse($__vers) as $__v) {
            if (file_exists("/usr/sbin/php-fpm{$__v}") || file_exists("/usr/bin/php{$__v}")) {
                DB::saveSetting('php_default_version', $__v);
                break;
            }
        }
    }
    unset($__phpVer, $__v, $__bins, $__vers, $__bin, $__m);
} catch (\Throwable) {}

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
            // Fire background version check so header notification is current
            @file_get_contents(
                'http://127.0.0.1/api/update_check?action=check',
                false,
                stream_context_create(['http' => ['timeout' => 2]])
            );
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
$router->add('/admin/add-domain', function () use ($view) {
    Auth::check();
    $view->renderAdmin('Add Domain', SRC_PATH . '/add_domain.php');
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

// Firewall
$router->add('/admin/firewall', function () use ($view) {
    Auth::check();
    Auth::requireAdmin();
    $view->renderAdmin('Firewall', SRC_PATH . '/firewall.php');
});

// SSL Certificates
$router->add('/admin/ssl', function () use ($view) {
    Auth::check();
    Auth::requireAdmin();
    $view->renderAdmin('SSL Certificates', SRC_PATH . '/ssl.php');
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

// Hook Scripts
$router->add('/admin/hook-scripts', function () use ($view) {
    Auth::check();
    Auth::requireAdmin();
    $view->renderAdmin('Hook Scripts', SRC_PATH . '/hook_scripts.php');
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

// Panel Users (superadmin only)
$router->add('/admin/panel-users', function () use ($view) {
    Auth::requireSuperAdmin();
    $view->renderAdmin('Panel Users', SRC_PATH . '/panel_users.php');
});

// Profile (all authenticated users)
$router->add('/admin/profile', function () use ($view) {
    Auth::check();
    $view->renderAdmin('My Profile', SRC_PATH . '/profile.php');
});

// -------------------------------------------------------------------
// ACCOUNT PORTAL — hosting account holders log in with FTP credentials
// -------------------------------------------------------------------
$router->add('/user', function () {
    header('Location: /user/login');
    exit;
});

$router->add('/user/login', function () {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username && $password && AccountAuth::login($username, $password)) {
            header('Location: /user/dashboard');
            exit;
        }
        $error = 'Invalid credentials. Use your FTP/SSH username and password.';
    } else {
        $error = '';
    }
    require ACCOUNT_THEME_PATH . '/login.php';
    exit;
});

$router->add('/user/dashboard', function () {
    AccountAuth::check();
    $GLOBALS['_page_title'] = 'My Account';
    require ACCOUNT_THEME_PATH . '/header.php';
    require SRC_PATH . '/account/portal.php';
    require ACCOUNT_THEME_PATH . '/footer.php';
    exit;
});

$router->add('/user/auto-login', function () {
    $token = trim($_GET['token'] ?? '');
    AccountAuth::autoLogin($token);
});

$router->add('/user/phpmyadmin', function () {
    AccountAuth::check();
    AccountAuth::phpMyAdminSignOn();
});

$router->add('/user/logout', function () {
    AccountAuth::logout();
});

// Account Portal API (scoped to the logged-in domain, no admin session required)
$router->add('/api/account', function () {
    AccountAuth::check();
    header('Content-Type: application/json');
    require API_PATH . '/account.php';
    exit;
});

// -------------------------------------------------------------------
// API ROUTES — session-protected JSON endpoints
// -------------------------------------------------------------------
foreach ([
    '/api/accounts'    => 'accounts.php',
    '/api/services'    => 'services.php',
    '/api/firewall'    => 'firewall.php',
    '/api/ssl'         => 'ssl.php',
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
    '/api/stats'          => 'stats.php',
    '/api/profile'        => 'profile.php',
    '/api/hook-scripts'   => 'hook_scripts.php',
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
