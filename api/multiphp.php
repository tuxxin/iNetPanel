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
        // Build a wrapper script that runs apt detached from PHP-FPM.
        // This survives FPM restarts triggered by dpkg hooks.
        $statusFile = "/tmp/inetp_multiphp_{$action}_{$ver}";
        $wrapper = "/tmp/inetp_multiphp_run_{$ver}.sh";
        $panelDefaultEsc = escapeshellarg($panelDefault);
        $verEsc = escapeshellarg($ver);

        $script = "#!/bin/bash\n";
        $script .= "echo 'running' > " . escapeshellarg($statusFile) . "\n";
        $script .= "dpkg --configure -a 2>/dev/null\n";
        $script .= "{$cmd}\n";
        $script .= "if [ \$? -ne 0 ]; then\n";
        $script .= "  echo 'error' > " . escapeshellarg($statusFile) . "\n";
        $script .= "  exit 1\n";
        $script .= "fi\n";

        if ($action === 'install') {
            $script .= "systemctl enable php{$ver}-fpm 2>/dev/null\n";
            $script .= "systemctl start php{$ver}-fpm 2>/dev/null\n";
            $script .= "sed -i 's/^upload_max_filesize[[:space:]]*=.*/upload_max_filesize = 100M/' /etc/php/{$ver}/fpm/php.ini 2>/dev/null\n";
            $script .= "sed -i 's/^post_max_size[[:space:]]*=.*/post_max_size = 100M/' /etc/php/{$ver}/fpm/php.ini 2>/dev/null\n";
            $script .= "systemctl reload php{$ver}-fpm 2>/dev/null\n";
        }

        if ($action === 'remove') {
            $script .= "apt-get install --reinstall -y php{$panelDefault}-mysql php{$panelDefault}-sqlite3 php{$panelDefault}-common phpmyadmin 2>/dev/null\n";
            $script .= "phpenmod -v {$panelDefault} -s fpm calendar ctype curl dom exif fileinfo ftp gd gettext gmp iconv intl mbstring mysqli pdo_mysql pdo_sqlite phar posix readline shmop simplexml sockets sqlite3 sysvmsg sysvsem sysvshm tokenizer xmlreader xmlwriter xsl zip 2>/dev/null\n";
        }

        $script .= "systemctl restart php{$panelDefault}-fpm 2>/dev/null\n";
        $script .= "rm -f " . escapeshellarg($statusFile) . "\n";
        $script .= "rm -f " . escapeshellarg($wrapper) . "\n";

        file_put_contents($wrapper, $script);
        chmod($wrapper, 0755);
        file_put_contents($statusFile, 'running');

        // Launch detached — nohup + & ensures it survives FPM death
        exec("sudo /bin/bash " . escapeshellarg($wrapper) . " > /tmp/inetp_multiphp.log 2>&1 &");

        echo json_encode(['success' => true, 'output' => "PHP {$ver} {$action} started..."]);
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
