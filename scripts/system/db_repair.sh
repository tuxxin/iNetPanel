#!/bin/bash
# ==============================================================================
# db_repair.sh — MariaDB table check & repair
# Usage: inetp db_repair [--database dbname]
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

DATABASE=""
MYSQL_PASS=$(cat /root/.mysql_root_pass 2>/dev/null)

while [[ $# -gt 0 ]]; do
    case "$1" in
        --database) DATABASE="$2"; shift 2 ;;
        *) shift ;;
    esac
done

[ -z "$MYSQL_PASS" ] && { echo -e "${RED}MySQL root password not found.${NC}"; exit 1; }

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  MariaDB Table Check & Repair${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

OK_COUNT=0
REPAIRED_COUNT=0
FAILED_COUNT=0

if [ -n "$DATABASE" ]; then
    echo -e "${CYAN}▸ Checking database: ${BOLD}${DATABASE}${NC}"
    echo ""
    RESULT=$(mysqlcheck -u root -p"$MYSQL_PASS" --auto-repair --check "$DATABASE" 2>/dev/null)
else
    echo -e "${CYAN}▸ Checking all databases${NC}"
    echo ""
    # Skip system databases
    RESULT=$(mysqlcheck -u root -p"$MYSQL_PASS" --auto-repair --check --all-databases 2>/dev/null)
fi

while IFS= read -r line; do
    [ -z "$line" ] && continue

    TABLE=$(echo "$line" | awk '{print $1}')
    STATUS=$(echo "$line" | awk '{print $NF}')

    case "$STATUS" in
        OK)
            echo -e "  ${GREEN}✓${NC} ${TABLE}"
            OK_COUNT=$((OK_COUNT + 1))
            ;;
        *repaired*|*Repaired*)
            echo -e "  ${YELLOW}↻${NC} ${TABLE} — repaired"
            REPAIRED_COUNT=$((REPAIRED_COUNT + 1))
            ;;
        *error*|*Error*|*crashed*|*Corrupt*)
            echo -e "  ${RED}✗${NC} ${TABLE} — ${STATUS}"
            FAILED_COUNT=$((FAILED_COUNT + 1))
            ;;
        *)
            # Info lines (checking, repairing, etc.)
            if echo "$line" | grep -qiE 'check|repair|status|note'; then
                continue
            fi
            echo -e "  ${DIM}${line}${NC}"
            ;;
    esac
done <<< "$RESULT"

# Also check panel SQLite database
echo ""
echo -e "${CYAN}▸ Panel SQLite Database${NC}"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
if [ -f "$PANEL_DB" ]; then
    INTEGRITY=$(sqlite3 "$PANEL_DB" "PRAGMA integrity_check;" 2>/dev/null)
    if [ "$INTEGRITY" = "ok" ]; then
        echo -e "  ${GREEN}✓${NC} inetpanel.db — integrity OK"
        # Run VACUUM to optimize
        sqlite3 "$PANEL_DB" "VACUUM;" 2>/dev/null
        echo -e "  ${GREEN}✓${NC} inetpanel.db — vacuumed"
        OK_COUNT=$((OK_COUNT + 2))
    else
        echo -e "  ${RED}✗${NC} inetpanel.db — integrity check failed"
        echo -e "    ${DIM}${INTEGRITY}${NC}"
        FAILED_COUNT=$((FAILED_COUNT + 1))
    fi
else
    echo -e "  ${DIM}Panel database not found${NC}"
fi

echo ""
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "  OK: ${GREEN}${OK_COUNT}${NC}  Repaired: ${YELLOW}${REPAIRED_COUNT}${NC}  Failed: ${RED}${FAILED_COUNT}${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"

[ "$FAILED_COUNT" -gt 0 ] && exit 1
exit 0
