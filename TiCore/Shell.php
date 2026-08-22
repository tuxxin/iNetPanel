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
        'change_password',
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
        'panel_ssl',
        'service_monitor',
        'db_size',
        'multiphp_manage',
        'restore_account',
        'disk_cache_scan',
        'audit_orphans',
        'cf_remoteip',
        'session_reaper',
        'qsa_scan',
        'restore_helper',
        'list',
    ];

    /** Directory for files handed to a privileged command. Never /tmp. */
    public const STAGE_DIR = '/var/lib/inetpanel/staging';

    /**
     * Write content to a staging file that is safe to hand to a root command.
     *
     * The panel used to stage these in /tmp under fixed, predictable names
     * (/tmp/inetp_motd, /tmp/inetpanel_hosts, /tmp/inetp_hook_*, ...). /tmp is
     * mode 1777, so any local user — including an unprivileged hosting tenant —
     * could create those names first and the privileged step would act on what
     * they left behind:
     *
     *   - `sudo cp <staged> /etc/motd` follows a symlink AS ROOT, copying any
     *     root-only file into a world-readable one. Verified: /root/.mysql_root_pass
     *     lands in /etc/motd (0644) and every tenant can read it.
     *   - `sudo bash <staged>` is worse. A tenant creates the name as a file they
     *     own at mode 0444; www-data cannot overwrite it and the sticky bit stops
     *     www-data unlinking it, so root executes the tenant's script.
     *
     * This is the same root cause as GHSA-mjmx-xpqq-p2h8 (attacker-influenceable
     * path handed to a privileged file operation), in a different set of sinks.
     *
     * The fix is the directory, not the filename: STAGE_DIR is owned by www-data
     * at mode 0700, so only the panel (and root) can create, replace or list
     * entries. Note the mode carefully — every hosting tenant on this panel has
     * gid 33 (www-data), so a group-writable 0770 root:www-data directory would
     * hand every tenant write access and be strictly worse than /tmp. Owner-only
     * is what excludes them: they match the group, and the group bits are zero.
     *
     * Writes also unlink first and create with O_EXCL, so a pre-existing entry of
     * any kind — including a symlink — is replaced by a fresh regular file rather
     * than followed.
     *
     * @param  string $name    Basename only; [A-Za-z0-9._-], no path separators.
     * @param  string $content
     * @param  int    $mode
     * @return string|null     Absolute path to hand to the privileged command,
     *                         or null if it could not be staged safely.
     */
    public static function stage(string $name, string $content, int $mode = 0640): ?string
    {
        // Reject anything that could escape the directory. No separators, no dot-dot.
        if (!preg_match('/^[A-Za-z0-9._-]{1,96}$/', $name) || str_contains($name, '..')) {
            self::log('ERROR', 'stage', ['name' => $name], 'illegal staging name', 1);
            return null;
        }

        if (!is_dir(self::STAGE_DIR)) {
            // 0700 before anything is written: never briefly group- or world-accessible.
            if (!@mkdir(self::STAGE_DIR, 0700, true) && !is_dir(self::STAGE_DIR)) {
                self::log('ERROR', 'stage', [], 'cannot create ' . self::STAGE_DIR, 1);
                return null;
            }
            @chmod(self::STAGE_DIR, 0700);
        }

        $path = self::STAGE_DIR . '/' . $name;

        // Unlink then create exclusively. O_EXCL refuses to follow a symlink, so
        // even if something raced in between, the write cannot be redirected.
        @unlink($path);
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            self::log('ERROR', 'stage', ['name' => $name], 'could not create staging file', 1);
            return null;
        }
        $written = fwrite($fh, $content);
        fflush($fh);
        // Set the mode on the descriptor we already hold, not on the path.
        @chmod($path, $mode);
        fclose($fh);

        if ($written === false || $written !== strlen($content)) {
            @unlink($path);
            self::log('ERROR', 'stage', ['name' => $name], 'short write', 1);
            return null;
        }
        return $path;
    }

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
     * Execute a direct system command with logging on failure.
     * Use this instead of raw shell_exec/exec for audit trail.
     *
     * @param  string $cmd      Full shell command to execute
     * @param  string $context  Short label for log entries (e.g. 'firewall-open-port')
     * @return array{success: bool, output: string, code: int}
     */
    public static function exec(string $cmd, string $context = ''): array
    {
        $output   = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        $outputStr = preg_replace('/\x1B\[[0-9;]*[mGKHF]/', '', implode("\n", $output));
        $success   = ($exitCode === 0);

        if (!$success) {
            self::log('ERROR', $context ?: $cmd, [], $outputStr, $exitCode);
        }

        return [
            'success' => $success,
            'output'  => $outputStr,
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
