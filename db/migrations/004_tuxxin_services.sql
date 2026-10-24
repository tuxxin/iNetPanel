-- 004: Tuxxin Services (qsa.sh, worldip.io, issued.live, webshot.site, whack.sh)
-- Cached answers and the background job queue. The helper and the panel also
-- create these on first use, so a fresh install works before its first update.
CREATE TABLE IF NOT EXISTS tsvc_cache (
    service    TEXT NOT NULL,
    item       TEXT NOT NULL,
    status     INTEGER NOT NULL DEFAULT 200,
    body       TEXT,
    fetched_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    PRIMARY KEY (service, item)
);
CREATE TABLE IF NOT EXISTS tsvc_jobs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    service      TEXT NOT NULL,
    item         TEXT NOT NULL,
    priority     INTEGER NOT NULL DEFAULT 5,
    state        TEXT NOT NULL DEFAULT 'queued',
    attempts     INTEGER NOT NULL DEFAULT 0,
    not_before   INTEGER NOT NULL DEFAULT 0,
    requested_by TEXT,
    last_error   TEXT,
    created_at   INTEGER NOT NULL,
    updated_at   INTEGER NOT NULL,
    UNIQUE (service, item)
);
CREATE INDEX IF NOT EXISTS idx_tsvc_jobs_due ON tsvc_jobs (state, priority, not_before);
-- Switches for an existing install: the master switch on and every new module
-- off, so nothing new contacts a service until an admin turns it on. qsa.sh
-- scanning shipped in 1.27 and stays on. OR IGNORE keeps any value already
-- written: install.php seeds a new install with the modules on.
INSERT OR IGNORE INTO settings (key, value, category, updated_at) VALUES ('tsvc_master',  '1', 'tuxxin', datetime('now'));
INSERT OR IGNORE INTO settings (key, value, category, updated_at) VALUES ('tsvc_qsa',     '1', 'tuxxin', datetime('now'));
INSERT OR IGNORE INTO settings (key, value, category, updated_at) VALUES ('tsvc_worldip', '0', 'tuxxin', datetime('now'));
INSERT OR IGNORE INTO settings (key, value, category, updated_at) VALUES ('tsvc_issued',  '0', 'tuxxin', datetime('now'));
INSERT OR IGNORE INTO settings (key, value, category, updated_at) VALUES ('tsvc_webshot', '0', 'tuxxin', datetime('now'));
INSERT OR IGNORE INTO settings (key, value, category, updated_at) VALUES ('tsvc_whack',   '0', 'tuxxin', datetime('now'))
