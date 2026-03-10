<?php
// FILE: TiCore/Shell.php
// TiCore PHP Framework - Safe sudo wrapper for privileged commands
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class Shell
{
    /**
     * Whitelist of allowed inetp subcommands.
     * Any command not in this list is rejected before execution.
     */
    private static array $allowedCommands = [
        'create_account',
        'delete_account',
        'create_user',
        'delete_user',
        'add_domain',
        'remove_domain',
        'suspend_account',
        'optimize_images',
        'backup_accounts',
        'update',
        'wireguard_setup',
        'wg_peer',
        'manage_ssh_keys',
        'ssl_manage',
        'list',
    ];

    /**
     * Run a whitelisted inetp command as root via sudo.
     *
     * @param  string $command  inetp subcommand (must be in $allowedCommands)
     * @param  array  $args     Associative or indexed arg pairs, e.g.
     *                          ['--domain' => 'example.com', '--confirm']
     * @return array{success: bool, output: string, error: string, code: int}
     */
    public static function run(string $command, array $args = []): array
    {
        if (!in_array($command, self::$allowedCommands, true)) {
            self::log('ERROR', $command, $args, 'Command not whitelisted', 1);
            return [
                'success' => false,
                'output'  => '',
                'error'   => "Command '{$command}' is not allowed.",
                'code'    => 1,
            ];
        }

        // Build argument string — each value is sanitized with escapeshellarg
        $argString = '';
        foreach ($args as $flag => $value) {
            if (is_int($flag)) {
                // Positional / bare flag like '--confirm'
                $argString .= ' ' . escapeshellarg($value);
            } else {
                // Named flag like '--domain example.com'
                $argString .= ' ' . escapeshellarg($flag) . ' ' . escapeshellarg((string) $value);
            }
        }

        $cmd = 'sudo /usr/local/bin/inetp ' . escapeshellarg($command) . $argString . ' 2>&1';

        $output    = [];
        $exitCode  = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = preg_replace('/\x1B\[[0-9;]*[mGKHF]/', '', implode("\n", $output));
        $success   = ($exitCode === 0);

        self::log(
            $success ? 'INFO' : 'ERROR',
            $command,
            $args,
            $outputStr,
            $exitCode
        );

        return [
            'success' => $success,
            'output'  => $outputStr,
            'error'   => $success ? '' : $outputStr,
            'code'    => $exitCode,
        ];
    }

    /**
     * Run a systemctl action on a whitelisted service.
     *
     * @param  string $action   start | stop | restart | reload | status
     * @param  string $service  Service name (whitelisted)
     */
    public static function systemctl(string $action, string $service): array
    {
        $allowedActions   = ['start', 'stop', 'restart', 'reload', 'is-active', 'status'];
        $allowedServices  = [
            'apache2', 'lighttpd', 'mariadb', 'mysql',
            'php8.5-fpm', 'php8.4-fpm', 'php8.3-fpm', 'php8.2-fpm', 'php8.1-fpm',
            'php8.0-fpm', 'php7.4-fpm', 'php7.3-fpm', 'php7.2-fpm', 'php7.1-fpm',
            'php7.0-fpm', 'php5.6-fpm',
            'vsftpd', 'wg-quick@wg0', 'cron',
            'firewalld', 'fail2ban', 'cloudflared',
        ];

        if (!in_array($action, $allowedActions, true)) {
            return ['success' => false, 'output' => '', 'error' => "Action '{$action}' not allowed.", 'code' => 1];
        }
        if (!in_array($service, $allowedServices, true)) {
            return ['success' => false, 'output' => '', 'error' => "Service '{$service}' not allowed.", 'code' => 1];
        }

        $cmd = 'sudo /bin/systemctl ' . escapeshellarg($action) . ' ' . escapeshellarg($service) . ' 2>&1';
        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = implode("\n", $output);
        self::log(
            $exitCode === 0 ? 'INFO' : 'ERROR',
            "systemctl {$action} {$service}",
            [],
            $outputStr,
            $exitCode
        );

        return [
            'success' => $exitCode === 0,
            'output'  => $outputStr,
            'error'   => $exitCode === 0 ? '' : $outputStr,
            'code'    => $exitCode,
        ];
    }

    /**
     * Check if a systemd service is active.
     */
    public static function isServiceActive(string $service): bool
    {
        $result = self::systemctl('is-active', $service);
        return trim($result['output']) === 'active';
    }

    /**
     * Return 'active', 'inactive', or 'missing' for a service unit.
     * Uses `systemctl status` which exits 4 when the unit does not exist.
     */
    public static function serviceStatus(string $service): string
    {
        // is-active is fast; use it first
        $result = self::systemctl('is-active', $service);
        if (trim($result['output']) === 'active') {
            return 'active';
        }
        // Distinguish "stopped" from "unit not found" via status exit code
        $status = self::systemctl('status', $service);
        return ($status['code'] === 4) ? 'missing' : 'inactive';
    }

    /**
     * Log a shell command execution to the SQLite logs table.
     */
    private static function log(string $level, string $command, array $args, string $output, int $code): void
    {
        try {
            $user = Auth::user();
            DB::insert('logs', [
                'source'     => 'shell',
                'level'      => $level,
                'message'    => "inetp {$command}" . (empty($args) ? '' : ' ' . json_encode($args)),
                'details'    => mb_substr($output, 0, 2000),
                'user'       => $user ? $user['username'] : 'system',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Never let logging break the actual operation
        }
    }
}
