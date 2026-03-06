<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORRECT PATH LOGIC: 
// __DIR__ = /var/www/inetpanel/public
// dirname(__DIR__) = /var/www/inetpanel
$projectRoot = dirname(__DIR__);
$dbPath = $projectRoot . '/db/inetpanel.db';

echo "<h2>SQLite Creation Test (Secure Path)</h2>";
echo "<strong>Web Root:</strong> " . __DIR__ . "<br>";
echo "<strong>Target Database Path:</strong> <code>" . $dbPath . "</code><br><br>";

try {
    // 1. Connect to Database (Attempts to create if missing)
    $db = new PDO("sqlite:" . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<span style='color:green; font-weight:bold;'>&#10004; Success: Connected to database outside public root.</span><br>";

    // 2. Define Table Schema
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
            FOREIGN KEY(user_id) REFERENCES users(id)
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
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            level TEXT,
            source TEXT,
            message TEXT,
            user_id INTEGER,
            ip_address TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )"
    ];

    // 3. Run Creations
    foreach ($queries as $sql) {
        $db->exec($sql);
    }
    
    echo "<span style='color:green; font-weight:bold;'>&#10004; Success: All tables created.</span>";

} catch (PDOException $e) {
    echo "<div style='background-color:#ffe6e6; border:1px solid red; padding:15px; border-radius:5px;'>";
    echo "<h3 style='margin-top:0; color:#cc0000;'>Database Error</h3>";
    echo "<strong>Message:</strong> " . $e->getMessage() . "<br><br>";
    echo "<strong>Troubleshooting:</strong><br>";
    echo "1. Does the folder <code>" . dirname($dbPath) . "</code> exist?<br>";
    echo "2. Does the web user have write permissions to <code>" . dirname($dbPath) . "</code>?";
    echo "</div>";
}
?>