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
                'secure'   => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
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
        $_SESSION['db_pass'] = base64_encode($password);
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

    /**
     * Generate a one-time auto-login token for admin impersonation.
     * Token expires after 30 seconds. Requires the login_tokens table.
     */
    public static function createAutoLoginToken(string $username): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 30);

        // Ensure table exists
        DB::query('CREATE TABLE IF NOT EXISTS login_tokens (
            token TEXT PRIMARY KEY,
            username TEXT NOT NULL,
            expires_at TEXT NOT NULL
        )');

        // Clean expired tokens
        DB::query('DELETE FROM login_tokens WHERE expires_at < datetime(\'now\')');

        DB::query('INSERT INTO login_tokens (token, username, expires_at) VALUES (?, ?, ?)',
            [$token, $username, $expires]);

        return $token;
    }

    /**
     * Consume a one-time auto-login token. Returns username on success, null on failure.
     */
    public static function consumeAutoLoginToken(string $token): ?string
    {
        if (!$token || strlen($token) !== 64) return null;

        $row = DB::fetchOne(
            'SELECT username, expires_at FROM login_tokens WHERE token = ?',
            [$token]
        );

        if (!$row) return null;

        // Delete token immediately (one-time use)
        DB::query('DELETE FROM login_tokens WHERE token = ?', [$token]);

        // Check expiry
        if (strtotime($row['expires_at']) < time()) return null;

        return $row['username'];
    }

    /**
     * Auto-login: consume token, create session, redirect to dashboard.
     */
    public static function autoLogin(string $token): void
    {
        $username = self::consumeAutoLoginToken($token);
        if (!$username) {
            header('Location: /user/login');
            exit;
        }

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['account_domain'] = $username;
        header('Location: /user/dashboard');
        exit;
    }

    /**
     * Bridge to phpMyAdmin signon auth.
     * Renders an auto-submitting form that POSTs credentials to signon.php.
     */
    public static function phpMyAdminSignOn(): void
    {
        self::startSession();
        $username = $_SESSION['account_domain'] ?? null;
        $dbPass   = isset($_SESSION['db_pass']) ? base64_decode($_SESSION['db_pass']) : null;

        // Determine PMA URL: use HTTPS+hostname if SSL cert exists, else HTTP+IP
        $hostname = DB::setting('server_hostname', '');
        $certFile = $hostname ? "/etc/letsencrypt/live/{$hostname}/fullchain.pem" : '';
        if ($hostname && $certFile && file_exists($certFile)) {
            $pmaBase = "https://{$hostname}:8443";
        } else {
            $serverIp = trim((string) shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'"));
            $pmaBase = "http://{$serverIp}:8888";
        }

        if (!$username || !$dbPass) {
            header("Location: {$pmaBase}/");
            exit;
        }

        // Auto-submit credentials to signon.php via POST (avoids cross-port session issues)
        $url  = htmlspecialchars("{$pmaBase}/signon.php");
        $user = htmlspecialchars($username, ENT_QUOTES);
        $pass = htmlspecialchars($dbPass, ENT_QUOTES);
        echo <<<HTML
        <!DOCTYPE html><html><head><title>Connecting...</title></head>
        <body><form id="f" method="post" action="{$url}">
        <input type="hidden" name="user" value="{$user}">
        <input type="hidden" name="password" value="{$pass}">
        </form><script>document.getElementById('f').submit();</script></body></html>
        HTML;
        exit;
    }

    /** Returns the username of the logged-in account holder, or null. */
    public static function username(): ?string
    {
        self::startSession();
        return $_SESSION['account_domain'] ?? null;
    }

    /** @deprecated Use username() — kept for backward compat */
    public static function domain(): ?string
    {
        return self::username();
    }

    /**
     * Returns the logged-in user's info including all their domains.
     * @return array{username: string, domains: array}|null
     */
    public static function user(): ?array
    {
        $username = self::username();
        if (!$username) return null;

        $hostingUser = DB::fetchOne('SELECT * FROM hosting_users WHERE username = ?', [$username]);
        if ($hostingUser) {
            $domains = DB::fetchAll(
                'SELECT domain_name, port, status FROM domains WHERE hosting_user_id = ? ORDER BY domain_name',
                [$hostingUser['id']]
            );
        } else {
            // Legacy fallback: single-domain account where username = domain
            $domains = DB::fetchAll('SELECT domain_name, port, status FROM domains WHERE domain_name = ?', [$username]);
        }

        return [
            'username' => $username,
            'domains'  => $domains,
        ];
    }
}
