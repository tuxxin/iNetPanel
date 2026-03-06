<?php
// --- LOCK FILE CHECK: redirect to login if already installed ---
$projectRoot = dirname(__DIR__);
$lockFile    = $projectRoot . '/.installed';
if (file_exists($lockFile) && ($_SERVER['REQUEST_METHOD'] !== 'POST')) {
    header('Location: /login');
    exit;
}

// --- BACKEND PROCESSING LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // PATH CONFIGURATION
    // dirname(__DIR__) = /var/www/inetpanel (Project Root)
    $projectRoot = dirname(__DIR__);
    $lockFile    = $projectRoot . '/.installed';
    $dbDir  = $projectRoot . '/db';
    $dbFile = $dbDir . '/inetpanel.db';

    // 1. VALIDATE CLOUDFLARE
    if ($_POST['action'] === 'validate_cf') {
        $email = $_POST['email'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudflare.com/client/v4/user");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Auth-Email: " . $email,
            "X-Auth-Key: " . $apiKey,
            "Content-Type: application/json"
        ]);
        
        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($curlError) {
            echo json_encode(['success' => false, 'message' => 'Connection Error: ' . $curlError]);
            exit;
        }

        $data = json_decode($result, true);

        if ($httpCode === 200 && isset($data['success']) && $data['success']) {
            echo json_encode(['success' => true]);
        } else {
            $msg = $data['errors'][0]['message'] ?? 'Invalid Email or Global API Key.';
            echo json_encode(['success' => false, 'message' => $msg]);
        }
        exit;
    }

    // 2. INSTALL ACTION
    if ($_POST['action'] === 'install') {
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
            } catch (PDOException $e) {
                throw new Exception("Database Connection Failed: " . $e->getMessage());
            }

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
                "CREATE TABLE IF NOT EXISTS domains (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    domain_name TEXT NOT NULL UNIQUE,
                    document_root TEXT NOT NULL,
                    php_version TEXT DEFAULT 'inherit',
                    port INTEGER,
                    status TEXT DEFAULT 'active',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(user_id) REFERENCES users(id)
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
                    domain_name TEXT UNIQUE,
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
                )"
            ];

            foreach ($queries as $sql) {
                $db->exec($sql);
            }

            // --- STEP D: DATA SEEDING ---
            
            // Admin User
            $user = $_POST['username'];
            $passHash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
            $stmt->execute([':u' => $user]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
                $stmt->execute([':u' => $user, ':p' => $passHash]);
            }

            // Cloudflare Setup
            $cfEnabled = ($_POST['dns_mode'] === 'cloudflare') ? 1 : 0;
            $tunnelId = null;
            if ($cfEnabled) {
                $tunnelId = "inetpanel-" . preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['hostname']);
            }

            // Settings
            $ddnsEnabled  = isset($_POST['ddns_enabled'])  && $_POST['ddns_enabled']  === '1' ? '1' : '0';
            $wgEnabled    = isset($_POST['wg_enabled'])    && $_POST['wg_enabled']    === '1' ? '1' : '0';
            $ddnsHostname = trim($_POST['ddns_hostname'] ?? '');
            $ddnsZoneId   = trim($_POST['ddns_zone_id']  ?? '');
            $ddnsInterval = (int)($_POST['ddns_interval'] ?? 5);
            $wgPort       = (int)($_POST['wg_port']    ?? 51820);
            $wgSubnet     = trim($_POST['wg_subnet']   ?? '10.10.0.0/24');
            $wgEndpoint   = trim($_POST['wg_endpoint'] ?? '');

            $settings = [
                'server_hostname'   => $_POST['hostname'],
                'timezone'          => $_POST['timezone'],
                'admin_email'       => 'admin@' . $_POST['hostname'],
                'default_theme'     => 'light',
                'backup_enabled'    => '0',
                'backup_destination'=> '/backup',
                'backup_retention'  => '3',
                'cf_enabled'        => $cfEnabled,
                'cf_email'          => ($cfEnabled) ? $_POST['cf_email'] : '',
                'cf_api_key'        => ($cfEnabled) ? $_POST['cf_key']   : '',
                'cf_tunnel_id'      => $tunnelId ?? '',
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
                'auto_update_enabled' => '0',
                'auto_update_time'    => '02:00',
            ];

            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value, category) VALUES (:k, :v, 'general')");
            foreach ($settings as $key => $val) {
                $stmt->execute([':k' => $key, ':v' => $val]);
            }

            // Write TiCore/.env with correct paths
            $envContent = "APP_NAME=iNetPanel\n"
                        . "APP_VERSION=1.0\n"
                        . "APP_ENV=production\n"
                        . "APP_DEBUG=false\n"
                        . "APP_URL=http://localhost\n\n"
                        . "DB_DRIVER=sqlite\n"
                        . "DB_PATH=../db/inetpanel.db\n\n"
                        . "TICORE_VERSION=1.0\n";
            file_put_contents($projectRoot . '/TiCore/.env', $envContent);

            // Write lock file — prevents install.php from being accessed again
            file_put_contents($lockFile, date('Y-m-d H:i:s'));

            // Set up DDNS cron if enabled
            if ($ddnsEnabled === '1' && $ddnsInterval > 0) {
                $cronLine = "*/{$ddnsInterval} * * * * www-data php /var/www/inetpanel/scripts/ddns_update.php >> /var/log/inetpanel_ddns.log 2>&1\n";
                file_put_contents('/etc/cron.d/inetpanel_ddns', $cronLine);
                @chmod('/etc/cron.d/inetpanel_ddns', 0644);
            }

            echo json_encode(['success' => true]);

        } catch (Exception $e) {
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

            <div class="mb-4">
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
                    <i class="fas fa-info-circle me-1"></i> Used for your <strong>Zero Trust Tunnel</strong> name.
                    <br>Allowed: <strong>A-Z, a-z, 0-9, _</strong> only.
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
                
                <div class="alert alert-danger d-none" id="cfErrorMsg"></div>
                <div class="alert alert-success d-none" id="cfSuccessMsg"><i class="fas fa-check-circle me-2"></i> Connection Verified!</div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="#" class="small text-decoration-none" data-bs-toggle="modal" data-bs-target="#cfHelpModal">
                        <i class="fas fa-question-circle me-1"></i> Get API Key
                    </a>
                    <button class="btn btn-sm btn-outline-primary" onclick="testCloudflareConnection()">
                        <i class="fas fa-plug me-1"></i> Test Connection
                    </button>
                </div>
            </div>

            <div id="manualContent" style="display: none;">
                <div class="alert alert-warning border-0 shadow-sm">
                    <h6 class="alert-heading fw-bold"><i class="fas fa-exclamation-triangle me-2"></i> Limited Mode</h6>
                    <p class="small mb-0">System will operate in <strong>Port-Based Mode</strong>. Email and DNS automation will be disabled.</p>
                </div>
            </div>

            <!-- CF DDNS -->
            <hr class="my-3">
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

            <!-- WireGuard VPN -->
            <hr class="my-3">
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <label class="form-label mb-0">WireGuard VPN</label>
                        <div class="form-text mt-0">Secure VPN for SSH/FTP access. Closes public ports 20, 21, 22.</div>
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
                        <input type="number" class="form-control form-control-sm" id="wgPort" value="51820" placeholder="VPN Port">
                    </div>
                    <div class="col-7">
                        <input type="text" class="form-control form-control-sm" id="wgSubnet" value="10.10.0.0/24" placeholder="VPN Subnet">
                    </div>
                </div>
                <input type="text" class="form-control form-control-sm" id="wgEndpoint" placeholder="Endpoint hostname (leave blank to use server IP)">
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-light border w-50" onclick="prevStep(2)">Back</button>
                <button class="btn btn-brand w-50" id="btnStep3Next" onclick="validateStep3()">Next Step</button>
            </div>
        </div>

        <div class="step-content" id="step4">
            <div id="installSpinner" class="text-center py-5">
                <div class="spinner-border text-primary mb-3" style="width: 4rem; height: 4rem;" role="status"></div>
                <h4 class="fw-bold">Installing iNetPanel...</h4>
                <p class="text-muted" id="installStatusText">Initializing database structure...</p>
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
        wg_enabled: '0', wg_port: '51820', wg_subnet: '10.10.0.0/24', wg_endpoint: ''
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
            if(!u || !p) { alert('Please enter username and password.'); return; }
            if(p.length < 8) { alert('Password must be at least 8 characters.'); return; }
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

    // --- API & Install Logic ---

    function getCfFormData() {
        const e = document.getElementById('cfEmail').value;
        const k = document.getElementById('cfApiKey').value;
        if(!e || !k) { 
            document.getElementById('cfErrorMsg').textContent = "Email & Key required.";
            document.getElementById('cfErrorMsg').classList.remove('d-none');
            return null; 
        }
        const fd = new FormData();
        fd.append('action', 'validate_cf'); fd.append('email', e); fd.append('api_key', k);
        return fd;
    }

    async function testCloudflareConnection() {
        const fd = getCfFormData(); if(!fd) return;
        const btn = event.target; const txt = btn.innerHTML;
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
        if(installData.dns_mode === 'cloudflare') {
            const fd = getCfFormData(); if(!fd) return;
            const btn = document.getElementById('btnStep3Next');
            btn.disabled = true; btn.innerHTML = 'Verifying...';
            try {
                const req = await fetch('install.php', { method:'POST', body:fd });
                const res = await req.json();
                if(res.success) {
                    installData.cf_email = document.getElementById('cfEmail').value;
                    installData.cf_key = document.getElementById('cfApiKey').value;
                    runInstallation();
                } else {
                    document.getElementById('cfErrorMsg').textContent = res.message;
                    document.getElementById('cfErrorMsg').classList.remove('d-none');
                    btn.disabled = false; btn.innerHTML = 'Next Step';
                }
            } catch(e) { alert("Error"); btn.disabled = false; btn.innerHTML = 'Next Step'; }
        } else { runInstallation(); }
    }

    async function runInstallation() {
        // Collect DDNS + WG values before going to step 4
        if (document.getElementById('ddnsEnabled').checked) {
            installData.ddns_enabled  = '1';
            installData.ddns_hostname = document.getElementById('ddnsHostname').value;
            installData.ddns_zone_id  = document.getElementById('ddnsZoneId').value;
            installData.ddns_interval = document.getElementById('ddnsInterval').value;
        }
        if (document.getElementById('wgEnabled').checked) {
            installData.wg_enabled  = '1';
            installData.wg_port     = document.getElementById('wgPort').value;
            installData.wg_subnet   = document.getElementById('wgSubnet').value;
            installData.wg_endpoint = document.getElementById('wgEndpoint').value;
        }

        showStep(4);
        // Reset Error State
        document.getElementById('installSpinner').classList.remove('d-none');
        document.getElementById('installError').classList.add('d-none');

        const fd = new FormData();
        fd.append('action', 'install');
        for (const k in installData) fd.append(k, installData[k]);

        try {
            const req = await fetch('install.php', { method: 'POST', body: fd });
            const res = await req.json();

            if(res.success) {
                document.getElementById('installStatusText').textContent = "Complete! Redirecting to login...";
                setTimeout(() => window.location.href = '/login', 1500);
            } else {
                document.getElementById('installSpinner').classList.add('d-none');
                document.getElementById('installError').classList.remove('d-none');
                document.getElementById('installErrorMessage').innerHTML = res.message || 'Unknown Error';
            }
        } catch(e) {
            alert("Critical Error: " + e.message);
        }
    }
</script>
</body>
</html>