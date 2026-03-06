<?php
// FILE: TiCore/Auth.php
// TiCore PHP Framework - Session Authentication
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class Auth
{
    private static bool $started = false;

    private static function startSession(): void
    {
        if (!self::$started && session_status() === PHP_SESSION_NONE) {
            session_name('inetpanel_sess');
            session_set_cookie_params([
                'lifetime' => 86400,
                'path'     => '/',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
            self::$started = true;
        }
    }

    /**
     * Check that a valid admin session exists.
     * Redirects to /login if not authenticated.
     * Called at the top of every /admin route handler.
     */
    public static function check(): void
    {
        self::startSession();

        // Redirect to install if panel hasn't been set up yet
        $lockFile = defined('ROOT_PATH') ? ROOT_PATH . '/.installed' : '/var/www/inetpanel/.installed';
        if (!file_exists($lockFile)) {
            header('Location: /install.php');
            exit;
        }

        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Attempt login. Returns true on success, false on failure.
     * On success, populates $_SESSION with user data.
     */
    public static function login(string $username, string $password): bool
    {
        self::startSession();

        // Check main admin users table first
        $user = DB::fetchOne(
            'SELECT * FROM users WHERE username = ? LIMIT 1',
            [$username]
        );

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = 'admin';
            $_SESSION['domains']   = null; // admin sees all
            return true;
        }

        // Check panel_users table (sub-admins)
        $panelUser = DB::fetchOne(
            'SELECT * FROM panel_users WHERE username = ? LIMIT 1',
            [$username]
        );

        if ($panelUser && password_verify($password, $panelUser['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = 'p_' . $panelUser['id'];
            $_SESSION['username']  = $panelUser['username'];
            $_SESSION['role']      = $panelUser['role'] ?? 'subadmin';
            $_SESSION['domains']   = json_decode($panelUser['assigned_domains'] ?? '[]', true);
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: /login');
        exit;
    }

    /** Returns the current user array or null. */
    public static function user(): ?array
    {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id'       => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role'     => $_SESSION['role'],
            'domains'  => $_SESSION['domains'],
        ];
    }

    public static function isAdmin(): bool
    {
        self::startSession();
        return ($_SESSION['role'] ?? '') === 'admin';
    }

    /**
     * Returns true if the current user may access the given domain.
     * Admins can access everything; sub-admins only see assigned domains.
     */
    public static function canAccessDomain(string $domain): bool
    {
        self::startSession();
        if (self::isAdmin()) {
            return true;
        }
        $allowed = $_SESSION['domains'] ?? [];
        return in_array($domain, $allowed, true);
    }

    /**
     * Require admin role. Redirects to dashboard with 403 if not admin.
     */
    public static function requireAdmin(): void
    {
        self::check();
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Location: /admin/dashboard');
            exit;
        }
    }
}
