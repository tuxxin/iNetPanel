<?php
// FILE: TiCore/AccountAuth.php
// iNetPanel — Session authentication for hosting account holders.
// Credentials are verified against Linux system users (same as FTP/SSH).
// Only users listed in /etc/vsftpd.userlist (hosting accounts) may log in.

class AccountAuth
{
    private static bool $started = false;

    private static function startSession(): void
    {
        if (!self::$started && session_status() === PHP_SESSION_NONE) {
            session_name('inetp_account_sess');
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
     * Attempt login using Linux system credentials (same as FTP/SSH).
     * Verification is delegated to a root-privileged PAM helper so that any
     * hash algorithm (SHA-512, yescrypt, etc.) is handled transparently.
     * Returns true on success and populates the account session.
     */
    public static function login(string $username, string $password): bool
    {
        self::startSession();

        // Basic sanity check before shelling out
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username) || $password === '') {
            return false;
        }

        // Verify credentials via PAM using the inetp sudo helper.
        // Exit code 0 = authenticated, 1 = rejected.
        $cmd = 'sudo /usr/local/bin/inetp verify_account '
            . escapeshellarg($username) . ' '
            . escapeshellarg($password)
            . ' 2>/dev/null';

        $exitCode = null;
        system($cmd, $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['account_domain'] = $username;
        return true;
    }

    /**
     * Require a valid account session, redirect to /user/login otherwise.
     */
    public static function check(): void
    {
        self::startSession();
        if (empty($_SESSION['account_domain'])) {
            header('Location: /user/login');
            exit;
        }
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
        header('Location: /user/login');
        exit;
    }

    /** Returns the domain/username of the logged-in account holder, or null. */
    public static function domain(): ?string
    {
        self::startSession();
        return $_SESSION['account_domain'] ?? null;
    }
}
