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
$ghHeaders = class_exists('Version') ? Version::githubHeaders() : ['User-Agent: iNetPanel/' . $currentVersion];
$ch = curl_init(GH_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => $ghHeaders,
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
    } catch (Throwable $e) { log_msg('WARNING: Failed to cache version in DB - ' . $e->getMessage()); }
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
    CURLOPT_HTTPHEADER     => $ghHeaders,
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
    if (file_put_contents($versionFile, $src) === false) {
        log_msg("ERROR: Failed to write {$versionFile}");
    }
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
        if (!copy($script, $dest)) {
            log_msg("ERROR: Failed to copy {$script} → {$dest}");
        }
        chmod($dest, 0755);
    }
    log_msg('Deployed ' . count($systemScripts) . ' system script(s) to ' . $scriptDest . '/');
}

// Deploy inetp command wrapper to /usr/local/bin/
$inetpSrc = PANEL_PATH . '/scripts/system/inetp';
if (file_exists($inetpSrc)) {
    if (!copy($inetpSrc, '/usr/local/bin/inetp')) {
        log_msg('ERROR: Failed to copy inetp command to /usr/local/bin/inetp');
    }
    chmod('/usr/local/bin/inetp', 0755);
    log_msg('Deployed inetp command to /usr/local/bin/inetp');
}

// Deploy Python scripts to /root/scripts/
$pyScripts = glob(PANEL_PATH . '/scripts/system/*.py');
foreach ($pyScripts as $script) {
    $dest = '/root/scripts/' . basename($script);
    if (!copy($script, $dest)) {
        log_msg("ERROR: Failed to copy {$script} → {$dest}");
    }
    chmod($dest, 0755);
}
if ($pyScripts) {
    log_msg('Deployed ' . count($pyScripts) . ' Python script(s) to /root/scripts/');
}

// Install stats collector cron (every minute)
$statsCron = "/etc/cron.d/inetpanel_stats";
file_put_contents($statsCron, "# iNetPanel stats collector — auto-managed by panel_update.php\n* * * * * root /root/scripts/stats_collector.sh > /dev/null 2>&1\n");
chmod($statsCron, 0644);
log_msg('Installed stats collector cron job');

// Rebuild sudoers file to match current requirements
$sudoersFile = '/etc/sudoers.d/inetpanel';
$sudoersContent = <<<'SUDOERS'
# iNetPanel web panel privilege escalation — auto-managed by panel_update.php
# Do not edit manually; changes will be overwritten on next panel update.
www-data ALL=(root) NOPASSWD: /usr/local/bin/inetp *
www-data ALL=(root) NOPASSWD: /root/scripts/manage_cron.sh
www-data ALL=(root) NOPASSWD: /root/scripts/cloudflared_setup.sh
www-data ALL=(root) NOPASSWD: /root/scripts/update_ssh_port.sh
www-data ALL=(root) NOPASSWD: /root/scripts/manage_ssh_keys.sh
www-data ALL=(root) NOPASSWD: /usr/bin/apt-get
www-data ALL=(root) NOPASSWD: /bin/systemctl
www-data ALL=(root) NOPASSWD: /usr/sbin/a2ensite
www-data ALL=(root) NOPASSWD: /usr/sbin/a2dissite
www-data ALL=(root) NOPASSWD: /usr/bin/wg
www-data ALL=(root) NOPASSWD: /usr/bin/wg-quick
www-data ALL=(root) NOPASSWD: /usr/sbin/usermod
www-data ALL=(root) NOPASSWD: /usr/bin/timedatectl
www-data ALL=(root) NOPASSWD: /usr/bin/hostnamectl
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetpanel_hosts /etc/hosts
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local
www-data ALL=(root) NOPASSWD: /sbin/reboot
www-data ALL=(root) NOPASSWD: /usr/sbin/phpenmod
www-data ALL=(root) NOPASSWD: /usr/sbin/phpdismod
www-data ALL=(root) NOPASSWD: /usr/bin/firewall-cmd
www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client
www-data ALL=(root) NOPASSWD: /usr/bin/tail
www-data ALL=(root) NOPASSWD: /usr/bin/journalctl
www-data ALL=(root) NOPASSWD: /usr/bin/dpkg
www-data ALL=(root) NOPASSWD: /bin/sed
www-data ALL=(root) NOPASSWD: /bin/bash /tmp/inetp_hook_*
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetp_tz.cnf /etc/mysql/mariadb.conf.d/99-timezone.cnf
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetp_motd /etc/motd
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetp_ht_* /home/*
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetp_pw_* /home/*
www-data ALL=(root) NOPASSWD: /bin/cat /home/*/.htaccess
www-data ALL=(root) NOPASSWD: /bin/cat /home/*/.htpasswd
www-data ALL=(root) NOPASSWD: /bin/chown *\:www-data /home/*
www-data ALL=(root) NOPASSWD: /bin/chmod 644 /home/*
www-data ALL=(root) NOPASSWD: /bin/chmod 640 /home/*
www-data ALL=(root) NOPASSWD: /bin/rm -f /home/*/.htpasswd
www-data ALL=(root) NOPASSWD: /bin/cat /root/.mysql_root_pass
www-data ALL=(root) NOPASSWD: /usr/bin/php* /var/www/inetpanel/scripts/panel_update.php *
SUDOERS;

if (file_put_contents($sudoersFile, $sudoersContent . "\n") === false) {
    log_msg('CRITICAL: Failed to write sudoers file — panel may lose sudo access on next boot');
} else {
    chmod($sudoersFile, 0440);
    log_msg('Sudoers file rebuilt with current rules.');
}

// Deploy phpMyAdmin signon.php for auto-login from client portal
$pmaDir = '/usr/share/phpmyadmin';
if (is_dir($pmaDir)) {
    $signonPhp = <<<'SIGNON'
<?php
// phpMyAdmin signon authentication bridge — deployed by iNetPanel
// Accepts: (1) one-time token via GET, or (2) manual POST login

$token = $_GET['token'] ?? '';

// Token-based auto-login from iNetPanel
if ($token && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenFile = '/tmp/pma_signon_' . $token;
    if (file_exists($tokenFile)) {
        $data = json_decode(file_get_contents($tokenFile), true);
        @unlink($tokenFile);
        if ($data && isset($data['user']) && (time() - ($data['created'] ?? 0)) < 30) {
            session_name('SignonSession');
            session_start();
            $_SESSION['PMA_single_signon_user'] = $data['user'];
            $_SESSION['PMA_single_signon_password'] = $data['password'];
            $_SESSION['PMA_single_signon_host'] = 'localhost';
            session_write_close();
            header('Location: index.php');
            exit;
        }
    }
}

// Manual POST login (fallback form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pma_user'])) {
    session_name('SignonSession');
    session_start();
    $_SESSION['PMA_single_signon_user'] = $_POST['pma_user'];
    $_SESSION['PMA_single_signon_password'] = $_POST['pma_pass'] ?? '';
    $_SESSION['PMA_single_signon_host'] = 'localhost';
    session_write_close();
    header('Location: index.php');
    exit;
}

// Fallback: show login form (prevents redirect loop with PMA signon auth)
$prefill = htmlspecialchars($_GET['prefill'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html><head><title>phpMyAdmin Login</title>
<style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f4f4f4;}
.card{background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);width:100%;max-width:360px;}
h2{margin:0 0 20px;text-align:center;color:#333;}
label{display:block;margin-bottom:4px;font-weight:600;font-size:.9rem;color:#555;}
input{width:100%;padding:10px;margin-bottom:16px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;font-size:.95rem;}
button{width:100%;padding:10px;background:#ff7e00;color:#fff;border:none;border-radius:4px;font-size:1rem;font-weight:600;cursor:pointer;}
button:hover{background:#e06f00;}</style></head>
<body><div class="card"><h2>phpMyAdmin</h2>
<form method="post">
<label>Username</label><input type="text" name="pma_user" value="<?= $prefill ?>" required autofocus>
<label>Password</label><input type="password" name="pma_pass">
<button type="submit">Log In</button>
</form></div></body></html>
SIGNON;
    file_put_contents("{$pmaDir}/signon.php", $signonPhp);
    chmod("{$pmaDir}/signon.php", 0644);
    log_msg('Deployed phpMyAdmin signon.php');

    // Patch config for signon auth if still using cookie
    $pmaConfig = '/etc/phpmyadmin/config.inc.php';
    if (file_exists($pmaConfig)) {
        $cfg = file_get_contents($pmaConfig);
        if (strpos($cfg, "'signon'") === false && strpos($cfg, "'cookie'") !== false) {
            $cfg = preg_replace(
                "/\\$cfg\\['Servers'\\]\\[\\$i\\]\\['auth_type'\\]\\s*=\\s*'cookie'/",
                "\$cfg['Servers'][\$i]['auth_type'] = 'signon';\n\$cfg['Servers'][\$i]['SignonSession'] = 'SignonSession';\n\$cfg['Servers'][\$i]['SignonURL'] = '/signon.php';\n// Original: \$cfg['Servers'][\$i]['auth_type'] = 'cookie'",
                $cfg,
                1
            );
            file_put_contents($pmaConfig, $cfg);
            log_msg('Patched phpMyAdmin config for signon auth');
        }
    }

    // Also patch conf.d/pma_secure.php if it overrides auth_type back to cookie
    $pmaSecure = '/etc/phpmyadmin/conf.d/pma_secure.php';
    if (file_exists($pmaSecure)) {
        $sec = file_get_contents($pmaSecure);
        if (preg_match("/\\['auth_type'\\].*=.*'cookie'/", $sec)) {
            $sec = preg_replace(
                "/\\$cfg\\['Servers'\\]\\[1\\]\\['auth_type'\\]\\s*=\\s*'cookie';/",
                "\$cfg['Servers'][1]['auth_type']         = 'signon';\n\$cfg['Servers'][1]['SignonSession']     = 'SignonSession';\n\$cfg['Servers'][1]['SignonURL']         = '/signon.php';",
                $sec,
                1
            );
            file_put_contents($pmaSecure, $sec);
            log_msg('Patched conf.d/pma_secure.php for signon auth');
        }
    }
}

// Fix FTP passive ports in firewall (GitHub issue #8)
// Existing installations may be missing the passive port range
if (shell_exec('command -v firewall-cmd 2>/dev/null')) {
    $ports = shell_exec('firewall-cmd --list-ports 2>/dev/null') ?: '';
    if (strpos($ports, '40000-50000') === false) {
        shell_exec('firewall-cmd --permanent --add-port=40000-50000/tcp 2>/dev/null');
        shell_exec('firewall-cmd --reload 2>/dev/null');
        log_msg('Added FTP passive port range 40000-50000/tcp to firewall');
    }
}

// Fix vsftpd config: add connection limits and timeouts if missing
$vsftpConf = '/etc/vsftpd.conf';
if (file_exists($vsftpConf)) {
    $vsftpContent = file_get_contents($vsftpConf);
    if (strpos($vsftpContent, 'max_clients') === false) {
        $vsftpContent .= "\n# Connection limits and timeouts (added by panel update)\nmax_clients=200\nmax_per_ip=20\ndata_connection_timeout=600\nidle_session_timeout=600\n";
        file_put_contents($vsftpConf, $vsftpContent);
        shell_exec('systemctl restart vsftpd 2>/dev/null');
        log_msg('Added vsftpd connection limits and timeouts');
    }
}

// Update SQLite record
if (class_exists('DB')) {
    try {
        DB::saveSetting('panel_latest_ver', $latestTag);
    } catch (Throwable $e) { log_msg('WARNING: Failed to save version in DB - ' . $e->getMessage()); }
}

// Clean up temp files
@unlink(TMP_ZIP);
shell_exec('rm -rf ' . escapeshellarg(TMP_DIR));

log_msg("Update complete! Panel is now version {$latestTag}.");
exit(0);
