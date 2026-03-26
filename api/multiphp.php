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
                $sf = "/var/www/inetpanel/storage/multiphp_{$op}_{$ver}";
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
        $ver = trim($_POST['version'] ?? '');
        if (!in_array($ver, $supportedVersions)) {
            echo json_encode(['success' => false, 'error' => 'Invalid PHP version.']); break;
        }
        $panelDefault = DB::setting('php_default_version', '8.4');
        if ($action === 'remove' && $ver === $panelDefault) {
            echo json_encode(['success' => false, 'error' => "PHP {$ver} is the panel default and cannot be removed. Set a different default version first."]); break;
        }

        // Ensure storage directory exists for status files and logs
        $storageDir = '/var/www/inetpanel/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
            @chown($storageDir, 'www-data');
            @chgrp($storageDir, 'www-data');
        }
        if (!is_dir($storageDir) || !is_writable($storageDir)) {
            echo json_encode(['success' => false, 'error' => 'Storage directory missing and could not be created. Run: mkdir -p /var/www/inetpanel/storage && chown www-data:www-data /var/www/inetpanel/storage']);
            break;
        }

        // Block concurrent operations — apt/dpkg can only run one at a time
        $busy = false;
        foreach ($supportedVersions as $v) {
            foreach (['install', 'remove'] as $op) {
                $sf = "{$storageDir}/multiphp_{$op}_{$v}";
                if (file_exists($sf) && trim(file_get_contents($sf)) === 'running') {
                    $busy = true; break 2;
                }
            }
        }
        if ($busy) {
            echo json_encode(['success' => false, 'error' => "Another operation is in progress. Please wait."]); break;
        }

        // Launch via inetp as a detached background process.
        // This runs entirely outside PHP-FPM and survives FPM restarts.
        $logFile = '/var/www/inetpanel/storage/multiphp.log';
        $cmd = 'sudo /usr/local/bin/inetp multiphp_manage'
             . ' --action ' . escapeshellarg($action)
             . ' --version ' . escapeshellarg($ver)
             . ' </dev/null >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);

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
