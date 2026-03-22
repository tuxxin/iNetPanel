<?php
// FILE: api/packages.php
// iNetPanel — PHP Packages API (install/remove individual php extensions)
// Actions: list, install, remove


Auth::check();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Known PHP extension packages (suffix appended to php{ver}-)
const PHP_EXTENSIONS = [
    'bcmath', 'cli', 'common', 'curl', 'dba', 'dev',
    'enchant', 'fpm', 'gd', 'gmp', 'igbinary', 'imagick',
    'imap', 'intl', 'ldap', 'mbstring', 'memcached', 'msgpack',
    'mysql', 'oauth', 'opcache', 'pgsql', 'phpdbg', 'readline',
    'redis', 'soap', 'sqlite3', 'ssh2', 'tidy', 'tokenizer',
    'uploadprogress', 'uuid', 'vips', 'xdebug', 'xml', 'xmlrpc',
    'xsl', 'yaml', 'zip',
];

function phpIsInstalled(string $ver): bool
{
    return file_exists("/usr/sbin/php-fpm{$ver}");
}

function getInstalledExtensions(string $ver): array
{
    $installed = [];
    foreach (PHP_EXTENSIONS as $ext) {
        $pkg = "php{$ver}-{$ext}";
        exec("dpkg -l {$pkg} 2>/dev/null | grep -q '^ii'", $out, $rc);
        // opcache is bundled in php-common on some versions (no separate package)
        if ($ext === 'opcache' && $rc !== 0) {
            exec("php{$ver} -m 2>/dev/null | grep -qi opcache", $out2, $rc2);
            if ($rc2 === 0) $rc = 0;
        }
        if ($rc === 0) {
            $installed[] = $ext;
        }
    }
    return $installed;
}

switch ($action) {

    case 'list':
        $ver = trim($_GET['version'] ?? '8.4');
        if (!preg_match('/^\d+\.\d+$/', $ver)) {
            echo json_encode(['success' => false, 'error' => 'Invalid version.']); break;
        }
        if (!phpIsInstalled($ver)) {
            echo json_encode(['success' => false, 'error' => "PHP {$ver} is not installed."]); break;
        }

        $packages = [];
        foreach (PHP_EXTENSIONS as $ext) {
            $pkg = "php{$ver}-{$ext}";
            exec("dpkg -l {$pkg} 2>/dev/null | grep -q '^ii'", $out, $rc);
            // opcache is bundled in php-common on some versions (no separate package)
            if ($ext === 'opcache' && $rc !== 0) {
                exec("php{$ver} -m 2>/dev/null | grep -qi opcache", $out2, $rc2);
                if ($rc2 === 0) $rc = 0;
            }
            $packages[] = [
                'extension'   => $ext,
                'package'     => $pkg,
                'installed'   => ($rc === 0),
            ];
        }
        echo json_encode(['success' => true, 'version' => $ver, 'data' => $packages]);
        break;

    case 'install':
    case 'remove':
        Auth::requireAdmin();
        $ver = trim($_POST['version'] ?? '8.4');
        $ext = trim($_POST['extension'] ?? '');

        if (!preg_match('/^\d+\.\d+$/', $ver)) {
            echo json_encode(['success' => false, 'error' => 'Invalid version.']); break;
        }
        if (!in_array($ext, PHP_EXTENSIONS, true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown extension.']); break;
        }

        // Protect core packages whose removal would break PHP-FPM or the panel
        $protected = ['fpm', 'cli', 'common', 'opcache', 'readline', 'sqlite3', 'mysql'];
        if ($action === 'remove' && in_array($ext, $protected, true)) {
            echo json_encode(['success' => false, 'error' => "Extension '{$ext}' is required by the panel and cannot be removed."]); break;
        }

        // opcache may be bundled in php-common with no separate package
        if ($action === 'install' && $ext === 'opcache') {
            exec("php{$ver} -m 2>/dev/null | grep -qi opcache", $chk, $chkRc);
            if ($chkRc === 0) {
                echo json_encode(['success' => true, 'output' => 'OPcache is already loaded (bundled in php-common).']);
                break;
            }
        }

        $pkg  = "php{$ver}-{$ext}";
        $flag = ($action === 'install') ? 'install' : 'remove';
        $statusFile = "/var/www/inetpanel/storage/pkg_{$flag}_{$ver}_{$ext}";
        // Run in a systemd scope so dpkg triggers restarting php-fpm won't kill this
        $aptCmd = "DEBIAN_FRONTEND=noninteractive apt-get {$flag} -y " . escapeshellarg($pkg);
        $wrapper = "echo running > " . escapeshellarg($statusFile)
            . " && chown www-data:www-data " . escapeshellarg($statusFile)
            . " && chmod 0666 " . escapeshellarg($statusFile)
            . " && RESULT=\$({$aptCmd} 2>&1); RC=\$?;"
            . " dpkg --configure -a < /dev/null 2>/dev/null || true;"
            . " if [ \$RC -ne 0 ]; then echo \"error\" > " . escapeshellarg($statusFile)
            . "; echo \"\$RESULT\" >> " . escapeshellarg($statusFile) . "; exit 1; fi;"
            . " rm -f " . escapeshellarg($statusFile) . ";"
            . " systemctl restart php{$ver}-fpm 2>/dev/null";
        $cmd = "sudo systemd-run --scope --quiet bash -c " . escapeshellarg($wrapper) . " >> /var/www/inetpanel/storage/pkg.log 2>&1 &";
        exec($cmd);
        echo json_encode(['success' => true, 'output' => 'started', 'status_file' => basename($statusFile)]);
        break;

    case 'installed_versions':
        // Returns list of installed PHP versions so frontend can populate the dropdown
        $versions = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4','8.5'];
        $result = [];
        foreach ($versions as $v) {
            if (phpIsInstalled($v)) {
                $result[] = $v;
            }
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'pkg_status':
        $file = basename(trim($_GET['file'] ?? ''));
        if (!$file || !preg_match('/^pkg_(install|remove)_[\d.]+_\w+$/', $file)) {
            echo json_encode(['status' => 'done']); break;
        }
        $path = "/var/www/inetpanel/storage/{$file}";
        if (!file_exists($path)) {
            echo json_encode(['status' => 'done']); break;
        }
        $st = trim(file_get_contents($path));
        if ($st === 'running') {
            echo json_encode(['status' => 'running']);
        } elseif (str_starts_with($st, 'error')) {
            echo json_encode(['status' => 'error', 'message' => substr($st, 6)]);
            @unlink($path);
        } else {
            echo json_encode(['status' => 'done']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
