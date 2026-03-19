#!/bin/bash
# ==============================================================================
# stats_collector.sh — Collect system stats every minute for dashboard history
# Called by cron: * * * * * root /root/scripts/stats_collector.sh
# Stores: CPU load, RAM %, total network bytes in SQLite stats_history table
# Auto-trims entries older than 7 days
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
[ -f "$PANEL_DB" ] || exit 0

# Ensure table exists
sqlite3 "$PANEL_DB" "CREATE TABLE IF NOT EXISTS stats_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER NOT NULL,
    cpu REAL NOT NULL DEFAULT 0,
    mem INTEGER NOT NULL DEFAULT 0,
    net_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);" 2>/dev/null
sqlite3 "$PANEL_DB" "CREATE INDEX IF NOT EXISTS idx_stats_ts ON stats_history(ts);" 2>/dev/null

# CPU load (1-minute avg)
CPU=$(awk '{printf "%.2f", $1}' /proc/loadavg)

# Memory % used
read -r MEM_TOTAL MEM_FREE MEM_BUFFERS MEM_CACHED <<< $(awk '
    /^MemTotal:/  { t=$2 }
    /^MemFree:/   { f=$2 }
    /^Buffers:/   { b=$2 }
    /^Cached:/    { c=$2 }
    END { print t, f, b, c }
' /proc/meminfo)
if [ "$MEM_TOTAL" -gt 0 ] 2>/dev/null; then
    MEM_USED=$((MEM_TOTAL - MEM_FREE - MEM_BUFFERS - MEM_CACHED))
    MEM_PCT=$((MEM_USED * 100 / MEM_TOTAL))
else
    MEM_PCT=0
fi

# Network: total rx + tx bytes across all interfaces
NET_BYTES=$(awk '/:/ { rx=$2; split($0, a); tx=a[10]; total+=rx+tx } END { printf "%d", total }' /proc/net/dev 2>/dev/null)
[ -z "$NET_BYTES" ] && NET_BYTES=0

# Insert
NOW=$(date +%s)
sqlite3 "$PANEL_DB" "INSERT INTO stats_history (ts, cpu, mem, net_bytes) VALUES ($NOW, $CPU, $MEM_PCT, $NET_BYTES);"

# Trim entries older than 7 days
CUTOFF=$((NOW - 604800))
sqlite3 "$PANEL_DB" "DELETE FROM stats_history WHERE ts < $CUTOFF;"
