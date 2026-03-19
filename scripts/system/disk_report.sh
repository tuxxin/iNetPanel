#!/bin/bash
# ==============================================================================
# disk_report.sh — Disk usage breakdown by user/domain
# Usage: inetp disk_report [--username user1]
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; BLUE='\033[1;34m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; BLUE=''; CYAN=''; DIM=''; NC=''
fi

USERNAME=""
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
MYSQL_PASS=$(cat /root/.mysql_root_pass 2>/dev/null)

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        *) shift ;;
    esac
done

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  Disk Usage Report${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# System overview
echo -e "${CYAN}▸ System Disk${NC}"
df -h / | awk 'NR==2 {printf "  Total: %s  Used: %s  Free: %s  (%s)\n", $2, $3, $4, $5}'
echo ""

# Get user list
if [ -n "$USERNAME" ]; then
    USERS="$USERNAME"
else
    USERS=$(sqlite3 "$PANEL_DB" "SELECT username FROM hosting_users ORDER BY username" 2>/dev/null)
    if [ -z "$USERS" ]; then
        USERS=$(ls /home/ 2>/dev/null | grep -v lost+found)
    fi
fi

TOTAL_FILES=0
TOTAL_DB=0

echo -e "${CYAN}▸ Per-User Breakdown${NC}"
echo -e "  ${DIM}%-20s %10s %10s %10s${NC}" "USER" "FILES" "DATABASE" "TOTAL"
echo -e "  ${DIM}$(printf '─%.0s' {1..55})${NC}"

for user in $USERS; do
    [ -d "/home/$user" ] || continue

    # File size
    FILE_SIZE=$(du -sb "/home/$user" 2>/dev/null | cut -f1)
    FILE_SIZE=${FILE_SIZE:-0}
    FILE_HR=$(numfmt --to=iec "$FILE_SIZE" 2>/dev/null || echo "${FILE_SIZE}B")

    # Database size
    DB_SIZE=0
    if [ -n "$MYSQL_PASS" ]; then
        DB_SIZE=$(mysql -u root -p"$MYSQL_PASS" -sN -e "
            SELECT COALESCE(SUM(data_length + index_length), 0)
            FROM information_schema.tables
            WHERE table_schema LIKE '${user}_%'
        " 2>/dev/null)
        DB_SIZE=${DB_SIZE:-0}
    fi
    DB_HR=$(numfmt --to=iec "$DB_SIZE" 2>/dev/null || echo "${DB_SIZE}B")

    COMBINED=$((FILE_SIZE + DB_SIZE))
    COMBINED_HR=$(numfmt --to=iec "$COMBINED" 2>/dev/null || echo "${COMBINED}B")

    TOTAL_FILES=$((TOTAL_FILES + FILE_SIZE))
    TOTAL_DB=$((TOTAL_DB + DB_SIZE))

    printf "  %-20s %10s %10s %10s\n" "$user" "$FILE_HR" "$DB_HR" "$COMBINED_HR"

    # Per-domain breakdown if single user
    if [ -n "$USERNAME" ]; then
        for domain_dir in /home/"$user"/*/; do
            [ -d "$domain_dir" ] || continue
            DOMAIN=$(basename "$domain_dir")
            DSIZE=$(du -sh "$domain_dir" 2>/dev/null | cut -f1)
            echo -e "    ${DIM}└─ %-16s %s${NC}" "$DOMAIN" "$DSIZE"
        done
    fi
done

echo -e "  ${DIM}$(printf '─%.0s' {1..55})${NC}"
TOTAL_FILES_HR=$(numfmt --to=iec "$TOTAL_FILES" 2>/dev/null || echo "${TOTAL_FILES}B")
TOTAL_DB_HR=$(numfmt --to=iec "$TOTAL_DB" 2>/dev/null || echo "${TOTAL_DB}B")
GRAND_TOTAL=$((TOTAL_FILES + TOTAL_DB))
GRAND_HR=$(numfmt --to=iec "$GRAND_TOTAL" 2>/dev/null || echo "${GRAND_TOTAL}B")
printf "  ${BOLD}%-20s %10s %10s %10s${NC}\n" "TOTAL" "$TOTAL_FILES_HR" "$TOTAL_DB_HR" "$GRAND_HR"
echo ""

# Top 10 largest files
if [ -n "$USERNAME" ]; then
    SEARCH_DIR="/home/$USERNAME"
else
    SEARCH_DIR="/home"
fi

echo -e "${CYAN}▸ Top 10 Largest Files${NC}"
find "$SEARCH_DIR" -type f -size +1M -exec ls -lhS {} \; 2>/dev/null | \
    sort -k5 -hr | head -10 | \
    awk '{printf "  %-8s %s\n", $5, $NF}'
echo ""

# Backup size
BACKUP_DIR=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='backup_destination'" 2>/dev/null)
[ -z "$BACKUP_DIR" ] && BACKUP_DIR="/backup"
if [ -d "$BACKUP_DIR" ]; then
    BACKUP_TOTAL=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)
    echo -e "${CYAN}▸ Backups${NC}"
    echo -e "  Total backup size: ${BOLD}${BACKUP_TOTAL}${NC}"
    echo ""
fi

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
