#!/usr/bin/env php
<?php
// FILE: scripts/panel_update.php
// iNetPanel — Panel Self-Update Script
// Run via cron or "Update Now" button (api/settings.php action=update_now)
// Usage: php /var/www/inetpanel/scripts/panel_update.php [--force]

define('PANEL_PATH',  '/var/www/inetpanel');
define('LOG_FILE',    '/var/log/inetpanel_update.log');
define('TMP_ZIP',     '/tmp/inetpanel-update.zip');
define('TMP_DIR',     '/tmp/inetpanel-update-extract');
define('GH_API_URL',  'https://api.github.com/repos/tuxxin/inetpanel/releases/latest');

$force = in_array('--force', $argv ?? [], true);

function log_msg(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function abort(string $msg, int $code = 1): never
{
    log_msg('ERROR: ' . $msg);
    exit($code);
}

// Load TiCore classes to access DB and Version
$ticore = PANEL_PATH . '/TiCore';
foreach (['Config.php', 'Router.php', 'DB.php', 'Auth.php', 'Shell.php',
          'View.php', 'CloudflareAPI.php', 'Version.php', 'App.php'] as $f) {
    if (file_exists($ticore . '/' . $f)) {
        require_once $ticore . '/' . $f;
    }
}

// Define constants expected by TiCore
if (!defined('ROOT_PATH'))   define('ROOT_PATH',   PANEL_PATH);
if (!defined('TICORE_PATH')) define('TICORE_PATH', PANEL_PATH . '/TiCore');
if (!defined('CONF_PATH'))   define('CONF_PATH',   PANEL_PATH . '/conf');
if (!defined('THEME_PATH'))  define('THEME_PATH',  PANEL_PATH . '/themes/default');
if (!defined('SRC_PATH'))    define('SRC_PATH',    PANEL_PATH . '/src');
if (!defined('API_PATH'))    define('API_PATH',    PANEL_PATH . '/api');

$currentVersion = class_exists('Version') ? Version::get() : '0.000';
log_msg("iNetPanel update check — current version: {$currentVersion}");

// Fetch latest release info from GitHub
$ch = curl_init(GH_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['User-Agent: iNetPanel/' . $currentVersion],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $code !== 200) {
    abort('Failed to reach GitHub API (HTTP ' . $code . ').');
}

$release = json_decode($raw, true);
if (!is_array($release) || empty($release['tag_name'])) {
    abort('Invalid response from GitHub API.');
}

$latestTag = ltrim($release['tag_name'], 'v');
log_msg("Latest release on GitHub: {$latestTag}");

// Update SQLite cache
if (class_exists('DB')) {
    try {
        DB::saveSetting('panel_latest_ver', $latestTag);
        DB::saveSetting('panel_check_ts',   (string) time());
    } catch (Throwable) {}
}

// Check if update is needed
if (!$force && version_compare($latestTag, $currentVersion, '<=')) {
    log_msg('Already up to date. No update needed.');
    exit(0);
}

// Find download URL (prefer named asset, fall back to zipball)
$downloadUrl = '';
foreach ($release['assets'] ?? [] as $asset) {
    if ($asset['name'] === 'inetpanel-latest.zip') {
        $downloadUrl = $asset['browser_download_url'];
        break;
    }
}
if (!$downloadUrl) {
    $downloadUrl = $release['zipball_url'] ?? '';
}
if (!$downloadUrl) {
    abort('No download URL found in release.');
}

log_msg("Downloading from: {$downloadUrl}");

// Download zip
$fh = fopen(TMP_ZIP, 'wb');
if (!$fh) {
    abort('Cannot open ' . TMP_ZIP . ' for writing.');
}
$ch = curl_init($downloadUrl);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_HTTPHEADER     => ['User-Agent: iNetPanel/' . $currentVersion],
]);
curl_exec($ch);
$curlErr  = curl_error($ch);
$curlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fh);

if ($curlErr || $curlCode >= 400) {
    abort('Download failed: ' . ($curlErr ?: "HTTP {$curlCode}"));
}

log_msg('Download complete. Extracting...');

// Extract zip
if (is_dir(TMP_DIR)) {
    shell_exec('rm -rf ' . escapeshellarg(TMP_DIR));
}
mkdir(TMP_DIR, 0755, true);

$zip = new ZipArchive();
if ($zip->open(TMP_ZIP) !== true) {
    abort('Failed to open zip archive.');
}
$zip->extractTo(TMP_DIR);
$zip->close();

// Find the root directory inside the zip (GitHub adds a wrapper dir)
$dirs = glob(TMP_DIR . '/*', GLOB_ONLYDIR);
$srcDir = (count($dirs) === 1) ? $dirs[0] : TMP_DIR;

log_msg("Applying update from: {$srcDir}");

// rsync panel files, excluding protected paths
$rsyncCmd = sprintf(
    'rsync -a --delete --exclude=%s --exclude=%s %s %s',
    escapeshellarg('db/'),
    escapeshellarg('.installed'),
    escapeshellarg(rtrim($srcDir, '/') . '/'),
    escapeshellarg(PANEL_PATH . '/')
);
exec($rsyncCmd, $rsyncOut, $rsyncCode);

if ($rsyncCode !== 0) {
    abort('rsync failed with code ' . $rsyncCode . ': ' . implode("\n", $rsyncOut));
}

// Run pending DB migrations
$migrationsDir = PANEL_PATH . '/db/migrations';
if (is_dir($migrationsDir) && class_exists('DB')) {
    try {
        $currentSchema = (int) DB::setting('schema_version', '0');
    } catch (Throwable) {
        $currentSchema = 0;
    }

    $files = glob($migrationsDir . '/*.sql');
    sort($files);
    $ran = 0;

    foreach ($files as $file) {
        $num = (int) basename($file);
        if ($num <= $currentSchema) continue;

        $sql = file_get_contents($file);
        $pdo = DB::get();
        try {
            $pdo->beginTransaction();
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt) $pdo->exec($stmt);
            }
            $pdo->commit();
            $currentSchema = $num;
            $ran++;
            log_msg("Migration {$num} applied: " . basename($file));
        } catch (Throwable $e) {
            $pdo->rollBack();
            log_msg("Migration {$num} FAILED: " . $e->getMessage());
        }
    }

    if ($ran > 0) {
        DB::saveSetting('schema_version', (string) $currentSchema);
        log_msg("Schema version: {$currentSchema} ({$ran} migration(s)).");
    } else {
        log_msg('Schema is up to date (version ' . $currentSchema . ').');
    }
}

// Write new version into Version.php constant
$versionFile = PANEL_PATH . '/TiCore/Version.php';
if (file_exists($versionFile)) {
    $src = file_get_contents($versionFile);
    $src = preg_replace(
        "/const APP_VERSION = '[^']+';/",
        "const APP_VERSION = '{$latestTag}';",
        $src
    );
    file_put_contents($versionFile, $src);
}

// Deploy system shell scripts to /root/scripts/
$systemScripts = glob(PANEL_PATH . '/scripts/system/*.sh');
if ($systemScripts) {
    $scriptDest = '/root/scripts';
    if (!is_dir($scriptDest)) {
        mkdir($scriptDest, 0755, true);
    }
    foreach ($systemScripts as $script) {
        $dest = $scriptDest . '/' . basename($script);
        copy($script, $dest);
        chmod($dest, 0755);
    }
    log_msg('Deployed ' . count($systemScripts) . ' system script(s) to ' . $scriptDest . '/');
}

// Update SQLite record
if (class_exists('DB')) {
    try {
        DB::saveSetting('panel_latest_ver', $latestTag);
    } catch (Throwable) {}
}

// Clean up temp files
@unlink(TMP_ZIP);
shell_exec('rm -rf ' . escapeshellarg(TMP_DIR));

log_msg("Update complete! Panel is now version {$latestTag}.");
exit(0);
