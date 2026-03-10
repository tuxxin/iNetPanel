-- Migration 001: Initial schema (baseline)
-- Matches install.php schema — idempotent via IF NOT EXISTS

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    category TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS hosting_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    shell TEXT DEFAULT '/bin/bash',
    disk_quota INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hosting_user_id INTEGER,
    domain_name TEXT NOT NULL UNIQUE,
    document_root TEXT NOT NULL,
    php_version TEXT DEFAULT 'inherit',
    port INTEGER,
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(hosting_user_id) REFERENCES hosting_users(id)
);

CREATE TABLE IF NOT EXISTS panel_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT DEFAULT 'subadmin',
    assigned_domains TEXT DEFAULT '[]',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS php_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    php_version TEXT NOT NULL,
    package_name TEXT NOT NULL,
    is_installed INTEGER DEFAULT 0,
    UNIQUE(php_version, package_name)
);

CREATE TABLE IF NOT EXISTS account_ports (
    domain_name TEXT PRIMARY KEY,
    port INTEGER
);

CREATE TABLE IF NOT EXISTS wg_peers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hosting_user TEXT UNIQUE,
    public_key TEXT,
    peer_ip TEXT,
    config_path TEXT,
    suspended INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS php_versions (
    version TEXT PRIMARY KEY,
    is_installed INTEGER DEFAULT 0,
    is_system_default INTEGER DEFAULT 0,
    install_path TEXT,
    ini_path TEXT
);

CREATE TABLE IF NOT EXISTS services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    display_name TEXT NOT NULL,
    service_name TEXT NOT NULL,
    icon_class TEXT,
    is_locked INTEGER DEFAULT 0,
    auto_start INTEGER DEFAULT 1,
    current_status TEXT DEFAULT 'offline'
);

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT,
    level TEXT,
    message TEXT,
    details TEXT,
    user TEXT,
    ip_address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
