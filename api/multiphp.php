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
        // $domain flows into root-run file operations — validate strictly and
        // confirm it actually exists rather than trusting the POST value.
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{1,253}[a-zA-Z0-9]$/', $domain)) {
            echo json_encode(['success' => false, 'error' => 'Invalid domain.']); break;
        }
        if (!DB::fetchOne('SELECT id FROM domains WHERE domain_name = ?', [$domain])) {
            echo json_encode(['success' => false, 'error' => 'Unknown domain.']); break;
        }
        if ($ver && $ver !== 'inherit' && (!in_array($ver, $supportedVersions) || !phpIsInstalled($ver))) {
            echo json_encode(['success' => false, 'error' => 'PHP version not installed.']); break;
        }

        if ($ver && $ver !== 'inherit') {
            // Delegate the privileged pool move + vhost handler rewrite to the root
            // multiphp_manage script (correct pool naming, writes /etc/php/*/pool.d
            // as root). Replaces the old inline sudo cp/sed, which used the wrong
            // pool name, a malformed sed delimiter, and a cp not permitted by sudoers.
            $res = Shell::run('multiphp_manage', [
                '--action'  => 'set_domain',
                '--domain'  => $domain,
                '--version' => $ver,
            ]);
            if (!$res['success']) {
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Version switch failed.']); break;
            }
        }
        // Record the choice (the script handles files/services; DB stays in PHP).
        DB::update('domains', ['php_version' => $ver ?: 'inherit'], 'domain_name = ?', [$domain]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
