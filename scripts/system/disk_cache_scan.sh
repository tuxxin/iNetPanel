#!/bin/bash
# ==============================================================================
# disk_cache_scan.sh — Populate disk_cache + disk_cache_user tables
# Called by cron: */10 * * * * root /root/scripts/disk_cache_scan.sh
# Also called by api/accounts.php on add/remove_domain with --user flag
#
# Usage:
#   disk_cache_scan.sh                   # full scan (all users, all domains)
#   disk_cache_scan.sh --user <name>     # one user's domains + their DB total
#   disk_cache_scan.sh --domain <name>   # single domain (+ owning user's DB total)
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
[ -f "$PANEL_DB" ] || exit 0

USER_FILTER=""
DOMAIN_FILTER=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --user)   USER_FILTER="$2";   shift 2 ;;
        --domain) DOMAIN_FILTER="$2"; shift 2 ;;
        *) shift ;;
    esac
done

# Validate filter inputs (username/domain charset) to prevent SQL injection via args
is_valid_user() { [[ "$1" =~ ^[a-z][a-z0-9_-]{0,31}$ ]]; }
is_valid_domain() { [[ "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,253}$ ]]; }

if [ -n "$USER_FILTER" ] && ! is_valid_user "$USER_FILTER"; then
    echo "Invalid --user value" >&2; exit 1
fi
if [ -n "$DOMAIN_FILTER" ] && ! is_valid_domain "$DOMAIN_FILTER"; then
    echo "Invalid --domain value" >&2; exit 1
fi

# Ensure tables exist (defensive — migration should have created them)
sqlite3 "$PANEL_DB" <<'SQL' 2>/dev/null
CREATE TABLE IF NOT EXISTS disk_cache (
    username    TEXT NOT NULL,
    domain_name TEXT NOT NULL,
    files_bytes INTEGER DEFAULT 0,
    scanned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (username, domain_name)
);
CREATE INDEX IF NOT EXISTS idx_disk_cache_username ON disk_cache(username);
CREATE TABLE IF NOT EXISTS disk_cache_user (
    username    TEXT PRIMARY KEY,
    db_bytes    INTEGER DEFAULT 0,
    scanned_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL

# Build domain list query based on filters
if [ -n "$DOMAIN_FILTER" ]; then
    DOMAINS_QUERY="SELECT h.username || '|' || d.domain_name FROM domains d JOIN hosting_users h ON d.hosting_user_id=h.id WHERE d.domain_name = '${DOMAIN_FILTER}'"
elif [ -n "$USER_FILTER" ]; then
    DOMAINS_QUERY="SELECT h.username || '|' || d.domain_name FROM domains d JOIN hosting_users h ON d.hosting_user_id=h.id WHERE h.username = '${USER_FILTER}'"
else
    DOMAINS_QUERY="SELECT h.username || '|' || d.domain_name FROM domains d JOIN hosting_users h ON d.hosting_user_id=h.id"
fi

# Scan per-domain files
while IFS='|' read -r user domain; do
    [ -z "$user" ] && continue
    is_valid_user "$user" || continue
    is_valid_domain "$domain" || continue

    path="/home/${user}/${domain}"
    if [ -d "$path" ]; then
        bytes=$(du -sb "$path" 2>/dev/null | cut -f1)
    else
        bytes=0
    fi
    bytes="${bytes:-0}"
    sqlite3 "$PANEL_DB" \
        "INSERT OR REPLACE INTO disk_cache (username, domain_name, files_bytes, scanned_at) \
         VALUES ('${user}', '${domain}', ${bytes}, datetime('now'));" 2>/dev/null
done < <(sqlite3 "$PANEL_DB" "$DOMAINS_QUERY" 2>/dev/null)

# Build user list for DB-total scan
if [ -n "$USER_FILTER" ]; then
    USERS="$USER_FILTER"
elif [ -n "$DOMAIN_FILTER" ]; then
    USERS=$(sqlite3 "$PANEL_DB" "SELECT DISTINCT h.username FROM domains d JOIN hosting_users h ON d.hosting_user_id=h.id WHERE d.domain_name = '${DOMAIN_FILTER}'" 2>/dev/null)
else
    USERS=$(sqlite3 "$PANEL_DB" "SELECT username FROM hosting_users" 2>/dev/null)
fi

# Scan per-user DB totals
DB_ROOT_PASS=""
[ -f /root/.mysql_root_pass ] && DB_ROOT_PASS=$(cat /root/.mysql_root_pass)

while read -r user; do
    [ -z "$user" ] && continue
    is_valid_user "$user" || continue

    db_bytes=$(mysql -u root -p"${DB_ROOT_PASS}" -N -e \
        "SELECT COALESCE(SUM(data_length + index_length), 0) \
         FROM information_schema.tables \
         WHERE table_schema LIKE '${user}\\_%'" 2>/dev/null)
    db_bytes="${db_bytes:-0}"

    sqlite3 "$PANEL_DB" \
        "INSERT OR REPLACE INTO disk_cache_user (username, db_bytes, scanned_at) \
         VALUES ('${user}', ${db_bytes}, datetime('now'));" 2>/dev/null
done <<< "$USERS"

# Orphan cleanup only on full scans
if [ -z "$USER_FILTER" ] && [ -z "$DOMAIN_FILTER" ]; then
    sqlite3 "$PANEL_DB" <<'SQL' 2>/dev/null
DELETE FROM disk_cache
 WHERE NOT EXISTS (
    SELECT 1 FROM domains d
    JOIN hosting_users h ON d.hosting_user_id = h.id
    WHERE h.username = disk_cache.username
      AND d.domain_name = disk_cache.domain_name
 );
DELETE FROM disk_cache_user
 WHERE username NOT IN (SELECT username FROM hosting_users);
SQL
fi

exit 0
