<?php
// FILE: api/multiphp.php
// iNetPanel — Multi-PHP API
// Actions: list, install, remove, set_default, set_domain


$action = $_GET['action'] ?? $_POST['action'] ?? '';

$supportedVersions = ['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

function phpIsInstalled(string $ver): bool
{
    return file_exists("/usr/sbin/php-fpm{$ver}");
}

switch ($action) {

    case 'list':
        $defaultVer = DB::setting('php_default_version', '8.4');
        $data = [];
        $bgStatus = null;
        foreach ($supportedVersions as $ver) {
            $installed = phpIsInstalled($ver);
            $data[] = [
                'version'    => $ver,
                'installed'  => $installed,
                'is_default' => ($ver === $defaultVer),
                'socket'     => "/run/php/php{$ver}-fpm.sock",
            ];
            // Check for background operation status
            foreach (['install', 'remove'] as $op) {
                $sf = "/tmp/inetp_multiphp_{$op}_{$ver}";
                if (file_exists($sf)) {
                    $st = trim(file_get_contents($sf));
                    if ($st === 'running') {
                        $bgStatus = ['version' => $ver, 'action' => $op, 'status' => 'running'];
                    } elseif (str_starts_with($st, 'error')) {
                        $bgStatus = ['version' => $ver, 'action' => $op, 'status' => 'error', 'message' => substr($st, 6)];
                        @unlink($sf);
                    } elseif ($st === 'done') {
                        @unlink($sf);
                    }
                }
            }
        }
        // Per-domain overrides
        $domains = DB::fetchAll('SELECT domain_name, php_version FROM domains ORDER BY domain_name');
        $result = ['success' => true, 'versions' => $data, 'domains' => $domains, 'default' => $defaultVer];
        if ($bgStatus) $result['bg_status'] = $bgStatus;
        echo json_encode($result);
        break;

    case 'install':
    case 'remove':
        Auth::requireAdmin();
        set_time_limit(0);
        $ver = trim($_POST['version'] ?? '');
        if (!in_array($ver, $supportedVersions)) {
            echo json_encode(['success' => false, 'error' => 'Invalid PHP version.']); break;
        }
        $panelDefault = DB::setting('php_default_version', '8.4');
        if ($action === 'remove' && $ver === $panelDefault) {
            echo json_encode(['success' => false, 'error' => "PHP {$ver} is the panel default and cannot be removed. Set a different default version first."]); break;
        }
        if ($action === 'remove') {
            $cmd = "sudo /usr/bin/apt-get purge -y 'php{$ver}-*' 2>&1 && sudo /usr/bin/apt-get autoremove -y 2>&1";
        } else {
            $cmd = "sudo /usr/bin/apt-get install -y php{$ver}-fpm php{$ver}-cli php{$ver}-common php{$ver}-mysql php{$ver}-xml php{$ver}-mbstring php{$ver}-curl php{$ver}-zip 2>&1";
        }
        // Send response BEFORE running apt — apt may restart php-fpm which
        // kills the socket and causes a 500 if the response hasn't been sent.
        $statusFile = "/tmp/inetp_multiphp_{$action}_{$ver}";
        file_put_contents($statusFile, 'running');
        echo json_encode(['success' => true, 'output' => "PHP {$ver} {$action} started..."]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Ensure dpkg is in a clean state before running apt
        Shell::exec("sudo /usr/bin/dpkg --configure -a", 'multiphp-dpkg');
        exec($cmd, $outArr, $exitCode);
        $out = implode("\n", $outArr);
        if ($exitCode !== 0) {
            error_log("[multiphp-apt-error] exit={$exitCode} output=" . implode(' ', $outArr));
            file_put_contents($statusFile, "error\n" . $out);
            break;
        }
        // Mark done immediately — subsequent FPM restarts may kill this worker
        @unlink($statusFile);
        if ($action === 'install') {
            Shell::exec("sudo /bin/systemctl enable php{$ver}-fpm", 'multiphp-fpm-enable');
            Shell::exec("sudo /bin/systemctl start  php{$ver}-fpm", 'multiphp-fpm-start');
        }

        // Configure upload limits for the newly installed PHP version
        if ($action === 'install') {
            $ini = "/etc/php/{$ver}/fpm/php.ini";
            Shell::exec("sudo /bin/sed -i 's/^upload_max_filesize[[:space:]]*=.*/upload_max_filesize = 100M/' " . escapeshellarg($ini), 'multiphp-sed');
            Shell::exec("sudo /bin/sed -i 's/^post_max_size[[:space:]]*=.*/post_max_size = 100M/' " . escapeshellarg($ini), 'multiphp-sed');
            Shell::exec("sudo /bin/systemctl reload php{$ver}-fpm", 'multiphp-fpm-reload');
        }

        // After any purge, apt prerm hooks may disable modules for other PHP versions
        // and can corrupt shared library symbols. Reinstall core packages and
        // re-enable all FPM modules for the panel default to restore a clean state.
        if ($action === 'remove') {
            Shell::exec("sudo /usr/bin/apt-get install --reinstall -y php{$panelDefault}-mysql php{$panelDefault}-sqlite3 php{$panelDefault}-common phpmyadmin", 'multiphp-reinstall');
            Shell::exec("sudo /usr/sbin/phpenmod -v {$panelDefault} -s fpm calendar ctype curl dom exif fileinfo ftp gd gettext gmp iconv intl mbstring mysqli pdo_mysql pdo_sqlite phar posix readline shmop simplexml sockets sqlite3 sysvmsg sysvsem sysvshm tokenizer xmlreader xmlwriter xsl zip", 'multiphp-phpenmod');
        }
        Shell::exec("sudo /bin/systemctl restart php{$panelDefault}-fpm", 'multiphp-fpm-reload');
        break;

    case 'set_default':
        Auth::requireAdmin();
        $ver = trim($_POST['version'] ?? '');
        if (!in_array($ver, $supportedVersions) || !phpIsInstalled($ver)) {
            echo json_encode(['success' => false, 'error' => 'Version not installed.']); break;
        }
        DB::saveSetting('php_default_version', $ver);
        echo json_encode(['success' => true]);
        break;

    case 'set_domain':
        Auth::requireAdmin();
        $domain = trim($_POST['domain']  ?? '');
        $ver    = trim($_POST['version'] ?? '');
        if (!$domain) { echo json_encode(['success' => false, 'error' => 'Domain required.']); break; }
        if ($ver && !in_array($ver, $supportedVersions)) {
            echo json_encode(['success' => false, 'error' => 'Invalid PHP version.']); break;
        }
        // Update domains table
        DB::update('domains', ['php_version' => $ver ?: 'inherit'], 'domain_name = ?', [$domain]);

        if ($ver && $ver !== 'inherit') {
            // Rewrite the FPM pool config to point to the new version's socket
            $currentDefault = DB::setting('php_default_version', '8.4');
            $poolConf = "/etc/php/{$currentDefault}/fpm/pool.d/{$domain}.conf";
            $newSock  = "/run/php/php{$ver}-fpm-{$domain}.sock";
            $escapedSock = str_replace(['|', '&', '\\'], ['\\|', '\\&', '\\\\'], $newSock);
            // Move pool config to correct PHP version dir
            $newPool  = "/etc/php/{$ver}/fpm/pool.d/{$domain}.conf";
            if (file_exists($poolConf) && !file_exists($newPool)) {
                Shell::exec("sudo /bin/cp " . escapeshellarg($poolConf) . " " . escapeshellarg($newPool), 'multiphp-copy-pool');
                // Update socket path inside new pool config
                Shell::exec("sudo /bin/sed -i 's|listen = .*|listen = " . $escapedSock . "|' " . escapeshellarg($newPool), 'multiphp-sed');
            }
            // Update Apache vhost to use new socket
            $vhost = "/etc/apache2/sites-available/{$domain}.conf";
            if (file_exists($vhost)) {
                Shell::exec("sudo /bin/sed -i 's|proxy:unix:.*fpm-{$domain}.*fcgi|proxy:unix:" . $escapedSock . "|fcgi|' " . escapeshellarg($vhost), 'multiphp-sed');
                Shell::exec("sudo /usr/sbin/a2ensite " . escapeshellarg("{$domain}.conf"), 'multiphp-a2ensite');
                Shell::exec("sudo /bin/systemctl reload apache2", 'multiphp-apache-reload');
            }
            Shell::exec("sudo /bin/systemctl reload php{$ver}-fpm", 'multiphp-fpm-reload');
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
