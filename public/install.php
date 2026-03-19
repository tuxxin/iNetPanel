<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: strict-origin-when-cross-origin');

// --- LOCK FILE CHECK: redirect to login if already installed ---
$projectRoot = dirname(__DIR__);
$lockFile    = $projectRoot . '/db/.installed';
if (file_exists($lockFile)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Already installed.']);
    } else {
        header('Location: /login');
    }
    exit;
}

// --- BACKEND PROCESSING LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // PATH CONFIGURATION
    // dirname(__DIR__) = /var/www/inetpanel (Project Root)
    $projectRoot = dirname(__DIR__);
    $lockFile    = $projectRoot . '/db/.installed';
    $dbDir  = $projectRoot . '/db';
    $dbFile = $dbDir . '/inetpanel.db';

    // 1. VALIDATE CLOUDFLARE
    if ($_POST['action'] === 'validate_cf') {
        $email     = $_POST['email']      ?? '';
        $apiKey    = $_POST['api_key']    ?? '';
        $accountId = trim($_POST['account_id'] ?? '');

        $cfHeaders = [
            "X-Auth-Email: " . $email,
            "X-Auth-Key: "   . $apiKey,
            "Content-Type: application/json",
        ];

        // Helper: make a CF API GET request
        $cfGet = function(string $url) use ($cfHeaders): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => $cfHeaders,
            ]);
            $result    = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            return ['body' => $result, 'code' => $httpCode, 'error' => $curlError];
        };

        // Step 1: validate email + API key via /user
        $r = $cfGet("https://api.cloudflare.com/client/v4/user");
        if ($r['error']) {
            echo json_encode(['success' => false, 'message' => 'Connection error: ' . $r['error']]);
            exit;
        }
        $data = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !($data['success'] ?? false)) {
            $msg = $data['errors'][0]['message'] ?? 'Invalid Email or Global API Key.';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        // Step 2: validate account ID if provided
        if ($accountId) {
            $r2   = $cfGet("https://api.cloudflare.com/client/v4/accounts/{$accountId}");
            $data2 = json_decode($r2['body'], true);
            if ($r2['code'] !== 200 || !($data2['success'] ?? false)) {
                $msg = $data2['errors'][0]['message'] ?? 'Account ID not found or not accessible.';
                echo json_encode(['success' => false, 'message' => 'Account ID invalid: ' . $msg]);
                exit;
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // 2. DETECT SERVER IP
    if ($_POST['action'] === 'detect_ip') {
        $ip = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
        if (!$ip) $ip = trim(shell_exec("hostname -I | awk '{print \$1}'") ?: '');
        $isPrivate = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        echo json_encode(['ip' => $ip, 'is_private' => $isPrivate]);
        exit;
    }

    // 3. VERIFY HOSTNAME DNS
    if ($_POST['action'] === 'verify_hostname') {
        $hostname = trim($_POST['hostname'] ?? '');
        $email = $_POST['email'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';

        if (!$hostname || !$email || !$apiKey) {
            echo json_encode(['exists' => false, 'zone_found' => false, 'error' => 'Missing parameters']);
            exit;
        }

        $cfHeaders = ["X-Auth-Email: {$email}", "X-Auth-Key: {$apiKey}", "Content-Type: application/json"];

        // Extract zone from hostname (last two parts: example.com from panel.example.com)
        $parts = explode('.', $hostname);
        $zone = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $hostname;

        // Find zone ID
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones?name={$zone}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $cfHeaders, CURLOPT_TIMEOUT => 15]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!($resp['success'] ?? false) || empty($resp['result'])) {
            echo json_encode(['exists' => false, 'zone_found' => false]);
            exit;
        }

        $zoneId = $resp['result'][0]['id'];

        // Check for A record
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records?type=A&name={$hostname}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $cfHeaders, CURLOPT_TIMEOUT => 15]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($resp['result'])) {
            echo json_encode(['exists' => true, 'zone_found' => true, 'ip' => $resp['result'][0]['content'], 'zone_id' => $zoneId]);
        } else {
            echo json_encode(['exists' => false, 'zone_found' => true, 'zone_id' => $zoneId]);
        }
        exit;
    }

    // 4. CREATE HOSTNAME DNS RECORD
    if ($_POST['action'] === 'create_hostname_dns') {
        $hostname = trim($_POST['hostname'] ?? '');
        $email = $_POST['email'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';

        $cfHeaders = ["X-Auth-Email: {$email}", "X-Auth-Key: {$apiKey}", "Content-Type: application/json"];

        // Detect server IP for DNS record
        $serverIp = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
        if (!$serverIp) $serverIp = trim(shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'") ?: '');
        if (!$serverIp) {
            echo json_encode(['success' => false, 'message' => 'Could not detect server IP.']);
            exit;
        }
        // Private IPs can't be proxied by Cloudflare
        $isPrivateIp = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        // Find zone ID
        $parts = explode('.', $hostname);
        $zone = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $hostname;

        $ch = curl_init("https://api.cloudflare.com/client/v4/zones?name={$zone}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $cfHeaders, CURLOPT_TIMEOUT => 15]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (empty($resp['result'])) {
            echo json_encode(['success' => false, 'message' => 'Zone not found']);
            exit;
        }

        $zoneId = $resp['result'][0]['id'];

        // Create A record
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $cfHeaders,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'type' => 'A',
                'name' => $hostname,
                'content' => $serverIp,
                'proxied' => !$isPrivateIp,
                'ttl' => $isPrivateIp ? 300 : 1,
            ]),
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        echo json_encode(['success' => $resp['success'] ?? false, 'message' => $resp['errors'][0]['message'] ?? '']);
        exit;
    }

    // 5. INSTALL ACTION
    if ($_POST['action'] === 'install') {
        if (file_exists($lockFile)) {
            echo json_encode(['success' => false, 'message' => 'Panel is already installed.']);
            exit;
        }
        try {
            // --- STEP A: STRICT PERMISSION CHECKS ---
            
            // Check 1: Does the folder exist?
            if (!is_dir($dbDir)) {
                throw new Exception("The directory <code>$dbDir</code> does not exist.<br>Please create it manually.");
            }

            // Check 2: Is the folder writable?
            if (!is_writable($dbDir)) {
                $user = exec('whoami');
                throw new Exception("Permission Denied: The web user (<code>$user</code>) cannot write to <code>$dbDir</code>.<br>Please run: <code>sudo chmod 775 $dbDir</code>");
            }

            // --- STEP B: CONNECT & INITIALIZE ---
            // Because the folder is writable, PDO will successfully create the file if missing.
            try {
                $db = new PDO("sqlite:" . $dbFile);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->exec('PRAGMA journal_mode=WAL');
            } catch (PDOException $e) {
                throw new Exception("Database Connection Failed: " . $e->getMessage());
            }

            $db->beginTransaction();

            // --- STEP C: SCHEMA CREATION ---
            $queries = [
                "CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    category TEXT,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS hosting_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    shell TEXT DEFAULT '/bin/bash',
                    disk_quota INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS domains (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hosting_user_id INTEGER,
                    domain_name TEXT NOT NULL UNIQUE,
                    document_root TEXT NOT NULL,
                    php_version TEXT DEFAULT 'inherit',
                    port INTEGER,
                    status TEXT DEFAULT 'active',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(hosting_user_id) REFERENCES hosting_users(id)
                )",
                "CREATE TABLE IF NOT EXISTS panel_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    role TEXT DEFAULT 'subadmin',
                    assigned_domains TEXT DEFAULT '[]',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS php_packages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    php_version TEXT NOT NULL,
                    package_name TEXT NOT NULL,
                    is_installed INTEGER DEFAULT 0,
                    UNIQUE(php_version, package_name)
                )",
                "CREATE TABLE IF NOT EXISTS account_ports (
                    domain_name TEXT PRIMARY KEY,
                    port INTEGER
                )",
                "CREATE TABLE IF NOT EXISTS wg_peers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hosting_user TEXT UNIQUE,
                    public_key TEXT,
                    peer_ip TEXT,
                    config_path TEXT,
                    suspended INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS php_versions (
                    version TEXT PRIMARY KEY,
                    is_installed INTEGER DEFAULT 0,
                    is_system_default INTEGER DEFAULT 0,
                    install_path TEXT,
                    ini_path TEXT
                )",
                "CREATE TABLE IF NOT EXISTS services (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    display_name TEXT NOT NULL,
                    service_name TEXT NOT NULL,
                    icon_class TEXT,
                    is_locked INTEGER DEFAULT 0,
                    auto_start INTEGER DEFAULT 1,
                    current_status TEXT DEFAULT 'offline'
                )",
                "CREATE TABLE IF NOT EXISTS logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source TEXT,
                    level TEXT,
                    message TEXT,
                    details TEXT,
                    user TEXT,
                    ip_address TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS stats_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ts INTEGER NOT NULL,
                    cpu REAL NOT NULL DEFAULT 0,
                    mem INTEGER NOT NULL DEFAULT 0,
                    net_bytes INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT ''
                )",
                "CREATE INDEX IF NOT EXISTS idx_stats_ts ON stats_history(ts)"
            ];

            foreach ($queries as $sql) {
                $db->exec($sql);
            }

            // Set schema version to latest migration
            $db->exec("INSERT OR REPLACE INTO settings (key, value, category) VALUES ('schema_version', '2', 'system')");

            // --- STEP D: DATA SEEDING ---
            
            // Admin User — server-side validation
            $user = trim($_POST['username'] ?? '');
            $pass = $_POST['password'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $user)) {
                throw new Exception('Username must be 3-32 alphanumeric characters or underscores.');
            }
            if (strlen($pass) < 8) {
                throw new Exception('Password must be at least 8 characters.');
            }
            $passHash = password_hash($pass, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
            $stmt->execute([':u' => $user]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
                $stmt->execute([':u' => $user, ':p' => $passHash]);
            }

            // Cloudflare Setup
            $cfEnabled   = (($_POST['dns_mode'] ?? '') === 'cloudflare') ? 1 : 0;
            $cfAccountId = trim($_POST['cf_account_id'] ?? '');

            // Settings
            $ddnsEnabled  = isset($_POST['ddns_enabled'])  && $_POST['ddns_enabled']  === '1' ? '1' : '0';
            $wgEnabled    = isset($_POST['wg_enabled'])    && $_POST['wg_enabled']    === '1' ? '1' : '0';
            $ddnsHostname = trim($_POST['ddns_hostname'] ?? '');
            $ddnsZoneId   = trim($_POST['ddns_zone_id']  ?? '');
            $ddnsInterval = (int)($_POST['ddns_interval'] ?? 5);
            $wgPort       = (int)($_POST['wg_port']    ?? 1443);
            $wgSubnet     = trim($_POST['wg_subnet']   ?? '10.10.0.0/24');
            $wgEndpoint   = trim($_POST['wg_endpoint'] ?? '');

            // Auto-detect installed PHP version (newest first, dynamic)
            $detectedPhpVer = '8.4';
            $phpFpmBins = glob('/usr/sbin/php-fpm*') ?: [];
            $phpVersions = [];
            foreach ($phpFpmBins as $bin) {
                if (preg_match('/php-fpm(\d+\.\d+)$/', $bin, $m)) {
                    $phpVersions[] = $m[1];
                }
            }
            if (empty($phpVersions)) {
                $phpVersions = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4','8.5'];
            }
            foreach (array_reverse($phpVersions) as $_v) {
                if (file_exists("/usr/sbin/php-fpm{$_v}") || file_exists("/usr/bin/php{$_v}")) {
                    $detectedPhpVer = $_v;
                    break;
                }
            }

            $serverHostnameDns = trim($_POST['server_hostname_dns'] ?? '');

            $settings = [
                'server_hostname'   => ($serverHostnameDns !== '') ? $serverHostnameDns : (($ddnsHostname !== '') ? $ddnsHostname : $_POST['hostname']),
                'timezone'          => $_POST['timezone'],
                'admin_email'       => ($cfEnabled && !empty($_POST['cf_email'])) ? $_POST['cf_email'] : 'admin@' . $_POST['hostname'],
                'default_theme'     => 'light',
                'backup_enabled'    => '0',
                'backup_destination'=> '/backup',
                'backup_retention'  => '3',
                'cf_enabled'        => $cfEnabled,
                'cf_email'          => ($cfEnabled) ? $_POST['cf_email'] : '',
                'cf_api_key'        => ($cfEnabled) ? $_POST['cf_key']   : '',
                'cf_account_id'     => $cfAccountId,
                'cf_tunnel_id'      => '',
                'cf_tunnel_token'   => '',
                // DDNS
                'cf_ddns_enabled'   => $ddnsEnabled,
                'cf_ddns_hostname'  => $ddnsHostname,
                'cf_ddns_zone_id'   => $ddnsZoneId,
                'cf_ddns_interval'  => (string)$ddnsInterval,
                // WireGuard
                'wg_enabled'        => $wgEnabled,
                'wg_port'           => (string)$wgPort,
                'wg_subnet'         => $wgSubnet,
                'wg_endpoint'       => $wgEndpoint,
                'wg_auto_peer'      => '0',
                // Update system
                'panel_latest_ver'    => '',
                'panel_check_ts'      => '0',
                'panel_download_url'  => '',
                'update_cron_enabled' => '1',
                'update_cron_time'    => '00:00',
                'backup_cron_time'    => '03:00',
                'auto_update_enabled' => '1',
                'auto_update_time'    => '02:00',
                // PHP
                'php_default_version' => $detectedPhpVer,
                // SSH
                'ssh_port' => '1022',
            ];

            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value, category) VALUES (:k, :v, 'general')");
            foreach ($settings as $key => $val) {
                $stmt->execute([':k' => $key, ':v' => $val]);
            }

            // Commit all DB writes atomically — lock file written only on success
            $db->commit();
            file_put_contents($lockFile, date('Y-m-d H:i:s'));

            // Helper: write a cron file via manage_cron.sh with error logging
            $writeCron = function(string $name, string $content): void {
                $proc = popen('sudo /root/scripts/manage_cron.sh write ' . escapeshellarg($name), 'w');
                if ($proc === false) {
                    error_log("iNetPanel install: failed to open pipe for cron {$name}");
                    return;
                }
                fwrite($proc, $content);
                $exit = pclose($proc);
                if ($exit !== 0) {
                    error_log("iNetPanel install: cron write for {$name} exited with {$exit}");
                }
            };

            // Apply timezone to PHP runtime and OS
            $tz = $_POST['timezone'] ?? 'UTC';
            if (!in_array($tz, DateTimeZone::listIdentifiers())) { $tz = 'UTC'; }
            date_default_timezone_set($tz);
            exec('sudo /usr/bin/timedatectl set-timezone ' . escapeshellarg($tz) . ' 2>&1', $tzOut, $tzExit);
            if ($tzExit !== 0) {
                error_log('iNetPanel install: timedatectl failed: ' . implode("\n", $tzOut));
            }
            // Apply timezone to MariaDB (load tz tables first, then set runtime + persistent)
            $mysqlPass = trim(@file_get_contents('/root/.mysql_root_pass') ?: '');
            exec('mysql_tzinfo_to_sql /usr/share/zoneinfo 2>/dev/null | mysql -u root -p' . escapeshellarg($mysqlPass) . ' mysql 2>&1');
            exec('mysql -u root -p' . escapeshellarg($mysqlPass) . ' -e ' . escapeshellarg("SET GLOBAL time_zone = '{$tz}'") . ' 2>&1', $mtzOut, $mtzExit);
            if ($mtzExit === 0) {
                @file_put_contents('/tmp/inetp_tz.cnf', "[mysqld]\ndefault_time_zone = {$tz}\n");
                exec('sudo /bin/cp /tmp/inetp_tz.cnf /etc/mysql/mariadb.conf.d/99-timezone.cnf 2>&1');
            }

            // Apply system hostname (prefer server_hostname_dns, then DDNS hostname)
            $sysHostname = ($serverHostnameDns !== '') ? $serverHostnameDns : (($ddnsHostname !== '') ? $ddnsHostname : ($_POST['hostname'] ?? ''));
            if ($sysHostname && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]*$/', $sysHostname)) {
                $oldHostname = gethostname();
                exec('sudo /usr/bin/hostnamectl set-hostname ' . escapeshellarg($sysHostname) . ' 2>&1', $hnOut, $hnExit);
                if ($hnExit !== 0) {
                    error_log('iNetPanel install: hostnamectl failed: ' . implode("\n", $hnOut));
                }
                // Update /etc/hosts to match new hostname
                if ($oldHostname && $oldHostname !== $sysHostname) {
                    $hosts = file_get_contents('/etc/hosts');
                    if ($hosts !== false) {
                        $hosts = str_replace($oldHostname, $sysHostname, $hosts);
                        file_put_contents('/tmp/inetpanel_hosts', $hosts);
                        exec('sudo /bin/cp /tmp/inetpanel_hosts /etc/hosts 2>&1');
                        @unlink('/tmp/inetpanel_hosts');
                    }
                }
            }

            // Generate MOTD — use internal IP, not public-facing route IP
            $serverIp = trim(explode(' ', shell_exec("hostname -I 2>/dev/null") ?: '')[0]);
            if (!$serverIp) $serverIp = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
            $motdHost = $serverHostnameDns ?: $serverIp;
            $motdContent = "\n"
                . "  iNetPanel — by Tuxxin.com\n"
                . "  ───────────────────────────────\n"
                . "  Admin Panel:    https://{$motdHost}/admin\n"
                . "  Client Portal:  https://{$motdHost}/user\n"
                . "  phpMyAdmin:     https://{$motdHost}:8443\n"
                . "  ───────────────────────────────\n"
                . "  Run  inetp --help  for CLI commands\n"
                . "  ───────────────────────────────\n\n";
            file_put_contents('/tmp/inetp_motd', $motdContent);
            shell_exec('sudo /bin/cp /tmp/inetp_motd /etc/motd 2>/dev/null');
            @unlink('/tmp/inetp_motd');

            // Create Cloudflare Zero Trust Tunnel if CF is enabled + account ID provided
            if ($cfEnabled && $cfAccountId) {
                $tunnelName = 'iNetPanel_' . (preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['hostname'] ?? '') ?: 'panel');
                $cfEmail  = $_POST['cf_email'] ?? '';
                $cfApiKey = $_POST['cf_key']   ?? '';

                // Helper: make a CF API request
                $cfRequest = function(string $method, string $path, ?array $body = null) use ($cfEmail, $cfApiKey): array {
                    $ch = curl_init('https://api.cloudflare.com/client/v4' . $path);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 20,
                        CURLOPT_CUSTOMREQUEST  => $method,
                        CURLOPT_HTTPHEADER     => [
                            'X-Auth-Email: ' . $cfEmail,
                            'X-Auth-Key: '   . $cfApiKey,
                            'Content-Type: application/json',
                        ],
                    ]);
                    if ($body !== null) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                    }
                    $raw = curl_exec($ch);
                    curl_close($ch);
                    $decoded = json_decode($raw, true);
                    return is_array($decoded) ? $decoded : ['success' => false];
                };

                // Create tunnel
                $createResp = $cfRequest('POST', "/accounts/{$cfAccountId}/cfd_tunnel", [
                    'name'          => $tunnelName,
                    'tunnel_secret' => base64_encode(random_bytes(32)),
                    'config_src'    => 'cloudflare',
                ]);

                if (!empty($createResp['result']['id'])) {
                    $tunnelId = $createResp['result']['id'];

                    // Get cloudflared token
                    $tokenResp = $cfRequest('GET', "/accounts/{$cfAccountId}/cfd_tunnel/{$tunnelId}/token");
                    $tunnelToken = $tokenResp['result'] ?? '';

                    // Save tunnel info to settings (separate connection since main transaction is committed)
                    $db->exec("UPDATE settings SET value = " . $db->quote($tunnelId)    . " WHERE key = 'cf_tunnel_id'");
                    $db->exec("UPDATE settings SET value = " . $db->quote($tunnelToken) . " WHERE key = 'cf_tunnel_token'");

                    // Install cloudflared as a systemd service
                    if ($tunnelToken) {
                        exec('sudo /root/scripts/cloudflared_setup.sh --action install --token ' . escapeshellarg($tunnelToken) . ' 2>&1', $cfOut, $cfExit);
                        if ($cfExit !== 0) {
                            error_log('iNetPanel install: cloudflared_setup failed: ' . implode("\n", $cfOut));
                        }
                    }
                }
            }

            // Set up panel auto-update cron (daily at 2am by default)
            $phpBin2 = 'php' . $detectedPhpVer;
            $autoUpdateCron = "# iNetPanel managed — panel auto-update\n"
                . "00 02 * * * root {$phpBin2} /var/www/inetpanel/scripts/panel_update.php >> /var/log/inetpanel_update.log 2>&1\n";
            $writeCron('inetpanel_autoupdate', $autoUpdateCron);

            // Stats collector — populates dashboard graph (every minute)
            $statsCron = "# iNetPanel stats collector — auto-managed\n"
                . "* * * * * root /root/scripts/stats_collector.sh > /dev/null 2>&1\n";
            $writeCron('inetpanel_stats', $statsCron);

            // Backup cron (daily at configured time, default 03:00)
            $backupTime = $_POST['backup_cron_time'] ?? '03:00';
            $bParts = explode(':', $backupTime);
            $bHour = intval($bParts[0] ?? 3);
            $bMin  = intval($bParts[1] ?? 0);
            $backupCron = "# iNetPanel backup — auto-managed\n"
                . "{$bMin} {$bHour} * * * root /root/scripts/backup_accounts.sh >> /var/log/lamp_backup.log 2>&1\n";
            $writeCron('lamp_backup', $backupCron);

            // Set up DDNS cron if enabled
            if ($ddnsEnabled === '1' && $ddnsInterval > 0) {
                $cronLine = "*/{$ddnsInterval} * * * * www-data {$phpBin2} /var/www/inetpanel/scripts/ddns_update.php >> /var/log/inetpanel_ddns.log 2>&1\n";
                $writeCron('inetpanel_ddns', $cronLine);
            }

            // Issue SSL for panel services (LE first, self-signed fallback)
            $sslHostname = ($serverHostnameDns !== '') ? $serverHostnameDns : (($ddnsHostname !== '') ? $ddnsHostname : ($_POST['hostname'] ?? ''));
            if ($sslHostname && strpos($sslHostname, '.') !== false) {
                exec('sudo /usr/local/bin/inetp panel_ssl ' . escapeshellarg($sslHostname) . ' 2>&1', $sslOut, $sslExit);
                if ($sslExit !== 0) {
                    error_log('iNetPanel install: panel_ssl failed (exit ' . $sslExit . '): ' . implode("\n", $sslOut));
                }
            }

            $redirectUrl = ($sslHostname && strpos($sslHostname, '.') !== false)
                ? "https://{$sslHostname}/login"
                : '/login';
            echo json_encode(['success' => true, 'redirectUrl' => $redirectUrl]);

        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
                // Remove partial DB file so install can be retried cleanly
                if (file_exists($dbFile) && !file_exists($lockFile)) {
                    @unlink($dbFile);
                }
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>iNetPanel Installation</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --brand-cyan: #00e0ff;
            --brand-blue: #0050d5;
            --brand-purple: #7a00d5;
            --active-gradient: linear-gradient(135deg, var(--brand-blue) 0%, var(--brand-purple) 100%);
            --body-bg: #f4f7f6;
        }

        body {
            background-color: var(--body-bg);
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .install-card {
            background: #fff;
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 600px;
            overflow: hidden;
            position: relative;
        }

        .logo-area { text-align: center; padding: 40px 0 20px; }
        .logo-area img { height: 60px; width: auto; }

        .step-indicator {
            display: flex; justify-content: space-between; padding: 0 50px 30px; position: relative;
        }

        .step-dot {
            width: 35px; height: 35px; background: #e9ecef; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; color: #6c757d; z-index: 2; transition: all 0.3s ease;
        }

        .step-dot.active {
            background: var(--active-gradient); color: #fff;
            box-shadow: 0 4px 10px rgba(122, 0, 213, 0.4);
        }

        .step-dot.completed { background: #198754; color: #fff; }

        .step-progress-line {
            position: absolute; top: 17px; left: 60px; right: 60px;
            height: 2px; background: #e9ecef; z-index: 1;
        }

        .step-content { padding: 0 40px 40px; display: none; }
        .step-content.active { display: block; animation: fadeIn 0.4s ease-out; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-label { font-weight: 600; color: #2c3e50; font-size: 0.9rem; }
        .form-control, .form-select { padding: 12px; border-radius: 8px; border: 1px solid #dee2e6; }
        .form-control:focus, .form-select:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(0, 80, 213, 0.1); }
        
        .btn-brand {
            background: var(--active-gradient); border: none; color: white;
            padding: 12px 30px; border-radius: 8px; font-weight: 600; transition: opacity 0.2s;
        }
        .btn-brand:hover { opacity: 0.9; color: white; }
        .btn-brand:disabled { background: #ccc; cursor: not-allowed; }

        .option-card {
            border: 2px solid #e9ecef; border-radius: 10px; padding: 20px;
            cursor: pointer; transition: all 0.2s;
        }
        .option-card:hover { border-color: #adb5bd; }
        .option-card.selected { border-color: var(--brand-blue); background-color: #f8fbff; }
        
        .strength-bar { height: 4px; border-radius: 2px; transition: width 0.3s; margin-top: 5px; }
        .strength-weak { width: 33%; background: #dc3545; }
        .strength-medium { width: 66%; background: #ffc107; }
        .strength-strong { width: 100%; background: #198754; }
    </style>
</head>
<body>

<div class="install-card">
    
    <div class="logo-area">
        <img src="/assets/img/iNetPanel-Logo.webp" alt="iNetPanel">
        <p class="text-muted mt-2 small">System Installation Wizard</p>
    </div>

    <div class="step-indicator">
        <div class="step-progress-line"></div>
        <div class="step-dot active" id="dot1">1</div>
        <div class="step-dot" id="dot2">2</div>
        <div class="step-dot" id="dot3">3</div>
        <div class="step-dot" id="dot4">4</div>
        <div class="step-dot" id="dot5">5</div>
        <div class="step-dot" id="dot6">6</div>
    </div>

    <form id="installForm" onsubmit="return false;">
        
        <div class="step-content active" id="step1">
            <h4 class="mb-4 text-center">Administrator Setup</h4>
            
            <div class="mb-3">
                <label class="form-label">Admin Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" placeholder="e.g. admin" required id="adminUser">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" placeholder="Secure Password" required id="adminPass" onkeyup="checkStrength(this.value)">
                </div>
                <div class="progress mt-2" style="height: 4px;">
                    <div class="progress-bar" id="passStrengthBar" role="progressbar" style="width: 0%"></div>
                </div>
                <div class="form-text">Must be at least 8 characters long.</div>
            </div>

            <div class="mb-4">
                <label class="form-label">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" placeholder="Re-enter Password" required id="adminPassConfirm">
                </div>
            </div>

            <button class="btn btn-brand w-100" onclick="nextStep(2)">
                Next Step <i class="fas fa-arrow-right ms-2"></i>
            </button>
        </div>

        <div class="step-content" id="step2">
            <h4 class="mb-4 text-center">Panel Configuration</h4>
            
            <div class="mb-3">
                <label class="form-label">Panel Name</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-tag"></i></span>
                    <input type="text" class="form-control" placeholder="e.g. My_Home_Panel" 
                           value="iNetPanel_01" id="serverHostname" maxlength="32">
                </div>
                <div class="form-text text-muted small">
                    This name appears in the browser title bar and is used as your Cloudflare Tunnel identifier. Allowed: A-Z, a-z, 0-9, _ only.
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Server Timezone</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-clock"></i></span>
                    <select class="form-select" id="serverTimezone">
                        <?php 
                        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
                        foreach($timezones as $tz) {
                            $selected = ($tz == date_default_timezone_get()) ? 'selected' : '';
                            echo "<option value='{$tz}' {$selected}>{$tz}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-light border w-50" onclick="prevStep(1)">Back</button>
                <button class="btn btn-brand w-50" onclick="nextStep(3)">Next Step</button>
            </div>
        </div>

        <div class="step-content" id="step3">
            <h4 class="mb-4 text-center">DNS & Network</h4>
            
            <div class="row g-3 mb-4">
                <div class="col-6">
                    <div class="option-card h-100 text-center selected" id="optCloudflare" onclick="selectDnsOption('cloudflare')">
                        <i class="fas fa-cloud fa-2x text-warning mb-3"></i>
                        <h6 class="fw-bold">Cloudflare</h6>
                        <small class="text-muted" style="font-size: 0.75rem;">Full Automation</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="option-card h-100 text-center" id="optManual" onclick="selectDnsOption('manual')">
                        <i class="fas fa-network-wired fa-2x text-secondary mb-3"></i>
                        <h6 class="fw-bold">Manual</h6>
                        <small class="text-muted" style="font-size: 0.75rem;">Port-based (Local)</small>
                    </div>
                </div>
            </div>

            <div id="cloudflareContent">
                <div class="mb-3">
                    <label class="form-label">Cloudflare Email</label>
                    <input type="email" class="form-control" id="cfEmail" placeholder="e.g. user@example.com">
                </div>
                <div class="mb-3">
                    <label class="form-label">Global API Key</label>
                    <input type="text" class="form-control" id="cfApiKey" placeholder="Enter Global API Key">
                </div>
                <div class="mb-3">
                    <label class="form-label">Account ID</label>
                    <input type="text" class="form-control" id="cfAccountId" placeholder="32-character Account ID">
                    <div class="form-text">Found in the Cloudflare dashboard sidebar on any domain overview page.</div>
                </div>
                
                <div class="alert alert-danger d-none" id="cfErrorMsg"></div>
                <div class="alert alert-success d-none" id="cfSuccessMsg"><i class="fas fa-check-circle me-2"></i> Connection Verified!</div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="#" class="small text-decoration-none" data-bs-toggle="modal" data-bs-target="#cfHelpModal">
                        <i class="fas fa-question-circle me-1"></i> Get API Key
                    </a>
                    <button class="btn btn-sm btn-outline-primary" onclick="testCloudflareConnection(this)">
                        <i class="fas fa-plug me-1"></i> Test Connection
                    </button>
                </div>
            </div>

            <div id="manualContent" style="display: none;">
                <div class="alert alert-warning border-0 shadow-sm">
                    <h6 class="alert-heading fw-bold"><i class="fas fa-exclamation-triangle me-2"></i> Limited Mode <span class="fw-normal">(Not Recommended)</span></h6>
                    <p class="small mb-0">System will operate in <strong>Port-Based Mode</strong>. Email and DNS automation will be disabled.</p>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-light border w-50" onclick="prevStep(2)">Back</button>
                <button class="btn btn-brand w-50" id="btnStep3Next" onclick="validateStep3()">Next Step</button>
            </div>
        </div>

        <!-- Step 4: DDNS & VPN (only shown when Cloudflare selected) -->
        <div class="step-content" id="step4">
            <h4 class="mb-4 text-center">DDNS & VPN</h4>

            <!-- CF DDNS -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <label class="form-label mb-0">Cloudflare DDNS</label>
                        <div class="form-text mt-0">Auto-update a DNS A record with your server's public IP.</div>
                    </div>
                    <div class="form-check form-switch ms-3">
                        <input class="form-check-input" type="checkbox" id="ddnsEnabled" onchange="toggleDDNS(this.checked)" style="width:2.5em;height:1.4em;">
                    </div>
                </div>
            </div>
            <div id="ddnsFields" style="display:none;">
                <div class="row g-2 mb-2">
                    <div class="col-7">
                        <input type="text" class="form-control form-control-sm" id="ddnsHostname" placeholder="home.example.com">
                    </div>
                    <div class="col-5">
                        <select class="form-select form-select-sm" id="ddnsInterval">
                            <option value="5" selected>Every 5 min</option>
                            <option value="10">Every 10 min</option>
                            <option value="30">Every 30 min</option>
                            <option value="60">Hourly</option>
                        </select>
                    </div>
                </div>
                <input type="text" class="form-control form-control-sm mb-2" id="ddnsZoneId" placeholder="Cloudflare Zone ID (optional — auto-detected if blank)">
            </div>
            <div class="alert alert-info border-0 py-2 small"><i class="fas fa-info-circle me-1"></i> DDNS is recommended only when VPN is enabled — it keeps your dynamic IP updated for VPN endpoint resolution.</div>

            <!-- WireGuard VPN -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <label class="form-label mb-0">WireGuard VPN</label>
                        <div class="form-text mt-0">Enable VPN for secure remote access to the Admin Panel, Client Portal, FTP, and SSH. Without VPN, these services are only accessible locally or via Cloudflare Tunnel.</div>
                    </div>
                    <div class="form-check form-switch ms-3">
                        <input class="form-check-input" type="checkbox" id="wgEnabled" onchange="toggleWireGuard(this.checked)" style="width:2.5em;height:1.4em;">
                    </div>
                </div>
            </div>
            <div id="wgFields" style="display:none;">
                <div id="wgDdnsRecommend" class="alert alert-warning py-2 px-3 small d-none">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <strong>Highly recommended:</strong> Enable Cloudflare DDNS above to keep your VPN endpoint hostname private and always reachable.
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-5">
                        <input type="number" class="form-control form-control-sm" id="wgPort" value="1443" placeholder="VPN Port">
                    </div>
                    <div class="col-7">
                        <input type="text" class="form-control form-control-sm" id="wgSubnet" value="10.10.0.0/24" placeholder="VPN Subnet">
                    </div>
                </div>
                <input type="text" class="form-control form-control-sm" id="wgEndpoint" placeholder="Endpoint hostname (leave blank to use server IP)">
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-light border w-50" onclick="prevStep(3)">Back</button>
                <button class="btn btn-brand w-50" onclick="validateStep4()">Next Step</button>
            </div>
        </div>

        <!-- Step 5: Server Hostname -->
        <div class="step-content" id="step5">
            <h4 class="mb-4 text-center">Server Hostname</h4>

            <div class="mb-3">
                <label class="form-label">Hostname</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-globe"></i></span>
                    <input type="text" class="form-control" id="serverHostnameDns" placeholder="e.g. panel.example.com">
                    <button class="btn btn-outline-primary" type="button" id="btnVerifyHostname" onclick="verifyHostname()" style="display:none;">
                        <i class="fas fa-check-circle me-1"></i> Verify
                    </button>
                </div>
                <div class="form-text">The DNS hostname used to access your panel. SSL certificate will be issued for this hostname.</div>
                <div id="hostnameStatus"></div>
            </div>

            <div class="mb-3" id="serverIpInfo">
                <!-- Populated by JS on step load -->
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-light border w-50" onclick="prevStep(prevStepFromStep5())">Back</button>
                <button class="btn btn-brand w-50" id="btnStep5Next" onclick="validateStep5()">Complete Installation</button>
            </div>
        </div>

        <!-- Step 6: Installation -->
        <div class="step-content" id="step6">
            <div id="installSpinner" class="text-center py-4">
                <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem" role="status"></div>
                <h5 class="fw-bold mb-2">Installing iNetPanel...</h5>
                <p class="small text-muted mb-3" id="installStage">Setting up your panel — this may take up to a minute...</p>
            </div>

            <div id="installError" class="d-none text-center">
                <div class="alert alert-danger border-0 shadow-sm text-start">
                    <h6 class="alert-heading fw-bold"><i class="fas fa-times-circle me-2"></i> Installation Failed</h6>
                    <p class="mb-0" id="installErrorMessage">Unknown Error</p>
                </div>
                <button class="btn btn-primary px-4 mt-3" onclick="runInstallation()">
                    <i class="fas fa-redo me-2"></i> Retry Installation
                </button>
            </div>
        </div>

    </form>
</div>

<div class="modal fade" id="cfHelpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Cloudflare API Help</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>We require your <strong>Global API Key</strong> to manage DNS.</p>
                <ol class="small text-muted">
                    <li>Log in to Cloudflare Dashboard.</li>
                    <li>Go to <strong>My Profile</strong> > <strong>API Tokens</strong>.</li>
                    <li>Scroll to "API Keys".</li>
                    <li>Click <strong>View</strong> next to "Global API Key".</li>
                </ol>
                <div class="text-center mt-3">
                    <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" class="btn btn-primary btn-sm">Open Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let installData = {
        username: '', password: '', hostname: '', timezone: '',
        dns_mode: 'cloudflare', cf_email: '', cf_key: '',
        ddns_enabled: '0', ddns_hostname: '', ddns_zone_id: '', ddns_interval: '5',
        wg_enabled: '0', wg_port: '1443', wg_subnet: '10.10.0.0/24', wg_endpoint: '', cf_account_id: '',
        server_hostname_dns: ''
    };

    function toggleDDNS(on) {
        document.getElementById('ddnsFields').style.display = on ? 'block' : 'none';
        installData.ddns_enabled = on ? '1' : '0';
        // Show/hide WG DDNS recommendation
        if (document.getElementById('wgEnabled').checked) {
            document.getElementById('wgDdnsRecommend').classList.toggle('d-none', on);
        }
    }

    function toggleWireGuard(on) {
        document.getElementById('wgFields').style.display = on ? 'block' : 'none';
        installData.wg_enabled = on ? '1' : '0';
        const recommend = document.getElementById('wgDdnsRecommend');
        if (on && !document.getElementById('ddnsEnabled').checked) {
            recommend.classList.remove('d-none');
        } else {
            recommend.classList.add('d-none');
        }
    }

    function nextStep(step) {
        if(step === 2) {
            const u = document.getElementById('adminUser').value;
            const p = document.getElementById('adminPass').value;
            const pc = document.getElementById('adminPassConfirm').value;
            if(!u || !p) { alert('Please enter username and password.'); return; }
            if(!/^[a-zA-Z0-9_]{3,32}$/.test(u)) { alert('Username must be 3-32 alphanumeric characters or underscores.'); return; }
            if(p.length < 8) { alert('Password must be at least 8 characters.'); return; }
            if(p !== pc) { alert('Passwords do not match.'); return; }
            installData.username = u;
            installData.password = p;
        }
        if(step === 3) {
            const host = document.getElementById('serverHostname').value;
            const regex = /^[a-zA-Z0-9_]+$/;
            if(!regex.test(host)) { alert("Panel Name can only contain letters, numbers, and underscores."); return; }
            installData.hostname = host;
            installData.timezone = document.getElementById('serverTimezone').value;
        }
        showStep(step);
    }

    function prevStep(step) { showStep(step); }

    function showStep(step) {
        document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
        document.getElementById('step' + step).classList.add('active');
        document.querySelectorAll('.step-dot').forEach(d => {
            d.classList.remove('active', 'completed');
            d.innerHTML = d.id.replace('dot', '');
        });
        for(let i=1; i<step; i++) {
            let d = document.getElementById('dot'+i);
            d.classList.add('completed');
            d.innerHTML = '<i class="fas fa-check"></i>';
        }
        document.getElementById('dot'+step).classList.add('active');

        // Step 5 initialization
        if (step === 5) {
            // Show/hide verify button based on CF mode
            document.getElementById('btnVerifyHostname').style.display = installData.dns_mode === 'cloudflare' ? '' : 'none';

            // Detect server IP
            fetch('install.php', { method: 'POST', body: new URLSearchParams({ action: 'detect_ip' }) })
                .then(r => r.json())
                .then(data => {
                    const ipDiv = document.getElementById('serverIpInfo');
                    const ip = data.ip || 'Unknown';
                    const isPrivate = data.is_private;
                    if (isPrivate) {
                        ipDiv.innerHTML = `<div class="alert alert-warning border-0 py-2 small">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong>Server IP: ${ip}</strong> (Private/Local Address)
                            <br>This hostname will only resolve within your local network. For remote access to the Admin Panel, Client Portal, and phpMyAdmin, go back and enable the VPN in Step 4.
                        </div>`;
                    } else {
                        ipDiv.innerHTML = `<div class="alert alert-success border-0 py-2 small">
                            <i class="fas fa-check-circle me-1"></i>
                            <strong>Server IP: ${ip}</strong> (Public Address)
                        </div>`;
                    }
                });
        }
    }

    function selectDnsOption(option) {
        installData.dns_mode = option;
        document.getElementById('optCloudflare').classList.toggle('selected', option === 'cloudflare');
        document.getElementById('optManual').classList.toggle('selected', option !== 'cloudflare');
        document.getElementById('cloudflareContent').style.display = option === 'cloudflare' ? 'block' : 'none';
        document.getElementById('manualContent').style.display = option !== 'cloudflare' ? 'block' : 'none';
    }

    function checkStrength(p) {
        let s = 0; if(p.length > 5) s+=33; if(p.length > 8) s+=33; if(p.match(/[A-Z0-9]/)) s+=34;
        const b = document.getElementById('passStrengthBar');
        b.style.width = s+'%'; b.className = 'progress-bar ' + (s<50?'bg-danger':s<80?'bg-warning':'bg-success');
    }

    function prevStepFromStep5() {
        return installData.dns_mode === 'cloudflare' ? 4 : 3;
    }

    // --- API & Install Logic ---

    function getCfFormData() {
        const e = document.getElementById('cfEmail').value;
        const k = document.getElementById('cfApiKey').value;
        const a = document.getElementById('cfAccountId').value;
        if(!e || !k) {
            document.getElementById('cfErrorMsg').textContent = "Email & Key required.";
            document.getElementById('cfErrorMsg').classList.remove('d-none');
            return null;
        }
        const fd = new FormData();
        fd.append('action', 'validate_cf'); fd.append('email', e); fd.append('api_key', k); fd.append('account_id', a);
        return fd;
    }

    async function testCloudflareConnection(btn) {
        const fd = getCfFormData(); if(!fd) return;
        const txt = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = 'Testing...';
        try {
            const req = await fetch('install.php', { method:'POST', body:fd });
            const res = await req.json();
            if(res.success) {
                document.getElementById('cfSuccessMsg').classList.remove('d-none');
                document.getElementById('cfErrorMsg').classList.add('d-none');
            } else {
                document.getElementById('cfErrorMsg').textContent = res.message;
                document.getElementById('cfErrorMsg').classList.remove('d-none');
                document.getElementById('cfSuccessMsg').classList.add('d-none');
            }
        } catch(e) { alert("Connection Failed"); }
        btn.disabled = false; btn.innerHTML = txt;
    }

    async function validateStep3() {
        if (installData.dns_mode === 'cloudflare') {
            const fd = getCfFormData(); if (!fd) return;
            const btn = document.getElementById('btnStep3Next');
            btn.disabled = true; btn.innerHTML = 'Verifying...';
            try {
                const req = await fetch('install.php', { method: 'POST', body: fd });
                const res = await req.json();
                if (res.success) {
                    installData.cf_email = document.getElementById('cfEmail').value;
                    installData.cf_key = document.getElementById('cfApiKey').value;
                    installData.cf_account_id = document.getElementById('cfAccountId').value;
                    showStep(4); // Go to DDNS/VPN step
                } else {
                    document.getElementById('cfErrorMsg').textContent = res.message;
                    document.getElementById('cfErrorMsg').classList.remove('d-none');
                    btn.disabled = false; btn.innerHTML = 'Next Step';
                }
            } catch(e) { alert('Error'); btn.disabled = false; btn.innerHTML = 'Next Step'; }
        } else {
            showStep(5); // Skip DDNS/VPN, go directly to hostname
        }
    }

    function validateStep4() {
        // Collect DDNS values
        if (document.getElementById('ddnsEnabled').checked) {
            installData.ddns_enabled = '1';
            installData.ddns_hostname = document.getElementById('ddnsHostname').value;
            installData.ddns_zone_id = document.getElementById('ddnsZoneId').value;
            installData.ddns_interval = document.getElementById('ddnsInterval').value;
        }
        // Collect WG values
        if (document.getElementById('wgEnabled').checked) {
            installData.wg_enabled = '1';
            installData.wg_port = document.getElementById('wgPort').value;
            installData.wg_subnet = document.getElementById('wgSubnet').value;
            installData.wg_endpoint = document.getElementById('wgEndpoint').value;
        }
        showStep(5);
    }

    function validateStep5() {
        installData.server_hostname_dns = document.getElementById('serverHostnameDns').value.trim();
        runInstallation();
    }

    async function verifyHostname() {
        const hostname = document.getElementById('serverHostnameDns').value.trim();
        if (!hostname) { return; }
        const btn = document.getElementById('btnVerifyHostname');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        const fd = new FormData();
        fd.append('action', 'verify_hostname');
        fd.append('hostname', hostname);
        fd.append('email', installData.cf_email);
        fd.append('api_key', installData.cf_key);

        try {
            const req = await fetch('install.php', { method: 'POST', body: fd });
            const res = await req.json();
            const statusDiv = document.getElementById('hostnameStatus');
            if (res.exists) {
                statusDiv.innerHTML = '<div class="alert alert-success py-2 mt-2 small"><i class="fas fa-check-circle me-1"></i> DNS record found — points to ' + res.ip + '</div>';
            } else if (res.zone_found) {
                statusDiv.innerHTML = '<div class="alert alert-warning py-2 mt-2 small"><i class="fas fa-exclamation-triangle me-1"></i> Zone found but no A record for this hostname. <button class="btn btn-sm btn-outline-success ms-2" onclick="createHostnameDns()"><i class="fas fa-plus me-1"></i>Create A Record</button></div>';
            } else {
                statusDiv.innerHTML = '<div class="alert alert-danger py-2 mt-2 small"><i class="fas fa-times-circle me-1"></i> Zone not found in your Cloudflare account.</div>';
            }
        } catch(e) {
            document.getElementById('hostnameStatus').innerHTML = '<div class="alert alert-danger py-2 mt-2 small">Verification failed.</div>';
        }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Verify';
    }

    async function createHostnameDns() {
        const hostname = document.getElementById('serverHostnameDns').value.trim();
        const fd = new FormData();
        fd.append('action', 'create_hostname_dns');
        fd.append('hostname', hostname);
        fd.append('email', installData.cf_email);
        fd.append('api_key', installData.cf_key);

        try {
            const req = await fetch('install.php', { method: 'POST', body: fd });
            const res = await req.json();
            if (res.success) {
                document.getElementById('hostnameStatus').innerHTML = '<div class="alert alert-success py-2 mt-2 small"><i class="fas fa-check-circle me-1"></i> A record created successfully!</div>';
            } else {
                document.getElementById('hostnameStatus').innerHTML = '<div class="alert alert-danger py-2 mt-2 small">' + (res.message || 'Failed to create DNS record.') + '</div>';
            }
        } catch(e) {
            document.getElementById('hostnameStatus').innerHTML = '<div class="alert alert-danger py-2 mt-2 small">Failed to create DNS record.</div>';
        }
    }

    async function runInstallation() {
        showStep(6);
        document.getElementById('installSpinner').classList.remove('d-none');
        document.getElementById('installError').classList.add('d-none');

        const fd = new FormData();
        fd.append('action', 'install');
        for (const k in installData) fd.append(k, installData[k]);

        try {
            const req = await fetch('install.php', { method: 'POST', body: fd });
            const res = await req.json();

            if(res.success) {
                document.getElementById('installStage').textContent = 'Complete! Redirecting...';
                setTimeout(() => window.location.href = res.redirectUrl || '/login', 1500);
            } else {
                document.getElementById('installSpinner').classList.add('d-none');
                document.getElementById('installError').classList.remove('d-none');
                document.getElementById('installErrorMessage').innerHTML = res.message || 'Unknown Error';
            }
        } catch(e) {
            document.getElementById('installSpinner').classList.add('d-none');
            document.getElementById('installError').classList.remove('d-none');
            document.getElementById('installErrorMessage').innerHTML = "Critical Error: " + e.message;
        }
    }
</script>
</body>
</html>