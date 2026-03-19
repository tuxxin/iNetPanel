-- Stats history table for dashboard graphs
CREATE TABLE IF NOT EXISTS stats_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER NOT NULL,
    cpu REAL NOT NULL DEFAULT 0,
    mem INTEGER NOT NULL DEFAULT 0,
    net_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_stats_ts ON stats_history(ts);
