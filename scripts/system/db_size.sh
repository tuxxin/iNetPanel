#!/bin/bash
# ==============================================================================
# db_size.sh — Return total MariaDB database size in bytes for a hosting user
# Usage: db_size.sh --username <name>
# Output: a single integer (bytes), or 0 on error
# ==============================================================================

USERNAME=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        *) shift ;;
    esac
done

if [[ -z "$USERNAME" ]]; then
    echo "0"; exit 1
fi

# Validate: must start with a letter, only lowercase, digits, hyphens, max 32 chars
if ! [[ "$USERNAME" =~ ^[a-z][a-z0-9_-]{0,31}$ ]]; then
    echo "0"; exit 1
fi

DB_ROOT_PASS=""
[[ -f /root/.mysql_root_pass ]] && DB_ROOT_PASS=$(cat /root/.mysql_root_pass)

SIZE=$(mysql -u root -p"${DB_ROOT_PASS}" -N -e \
    "SELECT COALESCE(SUM(data_length + index_length), 0)
     FROM information_schema.tables
     WHERE table_schema LIKE '${USERNAME}\\_%'" 2>/dev/null)

echo "${SIZE:-0}"
