-- Per-domain file usage cache (populated by disk_cache_scan.sh via cron)
CREATE TABLE IF NOT EXISTS disk_cache (
    username    TEXT NOT NULL,
    domain_name TEXT NOT NULL,
    files_bytes INTEGER DEFAULT 0,
    scanned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (username, domain_name)
);
CREATE INDEX IF NOT EXISTS idx_disk_cache_username ON disk_cache(username);

-- Per-user DB total (DBs aren't per-domain — users have GRANT ON username_%)
CREATE TABLE IF NOT EXISTS disk_cache_user (
    username    TEXT PRIMARY KEY,
    db_bytes    INTEGER DEFAULT 0,
    scanned_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
