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

        $pkg  = "php{$ver}-{$ext}";
        $flag = ($action === 'install') ? 'install' : 'remove';
        $cmd  = "DEBIAN_FRONTEND=noninteractive sudo /usr/bin/apt-get {$flag} -y " . escapeshellarg($pkg) . " 2>&1";
        exec($cmd, $lines, $rc);
        $output = implode("\n", $lines);

        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => "apt-get failed (exit {$rc})", 'output' => $output]); break;
        }

        // Restart FPM after package change so extensions are loaded/unloaded
        Shell::systemctl('restart', "php{$ver}-fpm");

        echo json_encode(['success' => true, 'output' => $output]);
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

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
