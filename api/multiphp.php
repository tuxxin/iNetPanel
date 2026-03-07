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
        foreach ($supportedVersions as $ver) {
            $installed = phpIsInstalled($ver);
            $data[] = [
                'version'    => $ver,
                'installed'  => $installed,
                'is_default' => ($ver === $defaultVer),
                'socket'     => "/run/php/php{$ver}-fpm.sock",
            ];
        }
        // Per-domain overrides
        $domains = DB::fetchAll('SELECT domain_name, php_version FROM domains ORDER BY domain_name');
        echo json_encode(['success' => true, 'versions' => $data, 'domains' => $domains, 'default' => $defaultVer]);
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
            $cmd = "sudo /usr/bin/apt-get purge -y php{$ver}-fpm php{$ver}-cli php{$ver}-common php{$ver}-mysql php{$ver}-xml php{$ver}-mbstring php{$ver}-curl php{$ver}-zip 2>&1";
        } else {
            $cmd = "sudo /usr/bin/apt-get install -y php{$ver}-fpm php{$ver}-cli php{$ver}-common php{$ver}-mysql php{$ver}-xml php{$ver}-mbstring php{$ver}-curl php{$ver}-zip 2>&1";
        }
        // Ensure dpkg is in a clean state before running apt (a previous interrupted
        // operation would cause apt to fail immediately with a dpkg error otherwise).
        shell_exec("sudo /usr/bin/dpkg --configure -a 2>&1");
        exec($cmd, $outArr, $exitCode);
        $out = implode("\n", $outArr);
        if ($action === 'install') {
            shell_exec("sudo /bin/systemctl enable php{$ver}-fpm 2>&1");
            shell_exec("sudo /bin/systemctl start  php{$ver}-fpm 2>&1");
        }
        // Verify the operation actually took effect before doing anything disruptive
        $nowInstalled = phpIsInstalled($ver);
        $wantInstalled = ($action === 'install');
        if ($nowInstalled !== $wantInstalled) {
            $errMsg = trim($out) ?: "apt-get exited with code {$exitCode}.";
            echo json_encode(['success' => false, 'error' => $errMsg]);
            break;
        }

        // Send the success response to the browser NOW, before restarting FPM.
        // fastcgi_finish_request() flushes and closes the HTTP response while
        // PHP continues executing — prevents the FPM restart from killing the reply.
        echo json_encode(['success' => true, 'output' => trim($out)]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Configure upload limits for the newly installed PHP version
        if ($action === 'install') {
            $ini = "/etc/php/{$ver}/fpm/php.ini";
            shell_exec("sudo /bin/sed -i 's/^upload_max_filesize[[:space:]]*=.*/upload_max_filesize = 100M/' " . escapeshellarg($ini) . " 2>&1");
            shell_exec("sudo /bin/sed -i 's/^post_max_size[[:space:]]*=.*/post_max_size = 100M/' "             . escapeshellarg($ini) . " 2>&1");
            shell_exec("sudo /bin/systemctl reload php{$ver}-fpm 2>&1");
        }

        // After any purge, apt prerm hooks may disable modules for other PHP versions
        // and can corrupt shared library symbols. Reinstall core packages and
        // re-enable all FPM modules for the panel default to restore a clean state.
        if ($action === 'remove') {
            shell_exec("sudo /usr/bin/apt-get install --reinstall -y php{$panelDefault}-mysql php{$panelDefault}-sqlite3 php{$panelDefault}-common phpmyadmin 2>&1");
            shell_exec("sudo /usr/sbin/phpenmod -v {$panelDefault} -s fpm calendar ctype curl dom exif fileinfo ftp gd gettext gmp iconv intl mbstring mysqli pdo_mysql pdo_sqlite phar posix readline shmop simplexml sockets sqlite3 sysvmsg sysvsem sysvshm tokenizer xmlreader xmlwriter xsl zip 2>&1");
        }
        shell_exec("sudo /bin/systemctl restart php{$panelDefault}-fpm 2>&1");
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
            // Move pool config to correct PHP version dir
            $newPool  = "/etc/php/{$ver}/fpm/pool.d/{$domain}.conf";
            if (file_exists($poolConf) && !file_exists($newPool)) {
                shell_exec("sudo cp " . escapeshellarg($poolConf) . " " . escapeshellarg($newPool) . " 2>&1");
                // Update socket path inside new pool config
                shell_exec("sudo sed -i 's|listen = .*|listen = {$newSock}|' " . escapeshellarg($newPool) . " 2>&1");
            }
            // Update Apache vhost to use new socket
            $vhost = "/etc/apache2/sites-available/{$domain}.conf";
            if (file_exists($vhost)) {
                shell_exec("sudo sed -i 's|proxy:unix:.*fpm-{$domain}.*fcgi|proxy:unix:{$newSock}|fcgi|' " . escapeshellarg($vhost) . " 2>&1");
                shell_exec("sudo /usr/sbin/a2ensite " . escapeshellarg("{$domain}.conf") . " 2>&1");
                shell_exec("sudo /bin/systemctl reload apache2 2>&1");
            }
            shell_exec("sudo /bin/systemctl reload php{$ver}-fpm 2>&1");
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
