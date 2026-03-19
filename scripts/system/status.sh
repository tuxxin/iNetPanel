#!/bin/bash
# ==============================================================================
# status.sh — Server health summary
# Displays: uptime, load, CPU/RAM/swap/disk, service statuses, SSL expiry,
#           backup age, pending updates, account count.
# Usage: inetp status
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; BLUE='\033[1;34m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; BLUE=''; CYAN=''; DIM=''; NC=''
fi

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  iNetPanel Server Status${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── System ────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ System${NC}"
echo -e "  Hostname:   ${BOLD}$(hostname)${NC}"
echo -e "  OS:         $(lsb_release -ds 2>/dev/null || cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d'"' -f2)"
echo -e "  Kernel:     $(uname -r)"
echo -e "  Uptime:     $(uptime -p 2>/dev/null || uptime | sed 's/.*up //' | sed 's/,.*load.*//')"

LOAD=$(cat /proc/loadavg | awk '{print $1, $2, $3}')
CORES=$(nproc 2>/dev/null || echo 1)
LOAD1=$(echo "$LOAD" | awk '{print $1}')
LOAD_STATUS="${GREEN}OK${NC}"
if (( $(echo "$LOAD1 > $CORES" | bc -l 2>/dev/null || echo 0) )); then
    LOAD_STATUS="${RED}HIGH${NC}"
elif (( $(echo "$LOAD1 > $CORES * 0.7" | bc -l 2>/dev/null || echo 0) )); then
    LOAD_STATUS="${YELLOW}MODERATE${NC}"
fi
echo -e "  Load:       ${BOLD}${LOAD}${NC} (${CORES} cores) [$LOAD_STATUS]"
echo ""

# ── Memory ────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Memory${NC}"
MEM_TOTAL=$(free -m | awk '/Mem:/ {print $2}')
MEM_USED=$(free -m | awk '/Mem:/ {print $3}')
MEM_PCT=$((MEM_USED * 100 / MEM_TOTAL))
if [ "$MEM_PCT" -ge 90 ]; then MEM_COLOR="$RED"; elif [ "$MEM_PCT" -ge 70 ]; then MEM_COLOR="$YELLOW"; else MEM_COLOR="$GREEN"; fi
echo -e "  RAM:        ${MEM_COLOR}${MEM_USED}MB / ${MEM_TOTAL}MB (${MEM_PCT}%)${NC}"

SWAP_TOTAL=$(free -m | awk '/Swap:/ {print $2}')
SWAP_USED=$(free -m | awk '/Swap:/ {print $3}')
if [ "$SWAP_TOTAL" -gt 0 ]; then
    SWAP_PCT=$((SWAP_USED * 100 / SWAP_TOTAL))
    if [ "$SWAP_PCT" -ge 50 ]; then SWAP_COLOR="$YELLOW"; else SWAP_COLOR="$GREEN"; fi
    echo -e "  Swap:       ${SWAP_COLOR}${SWAP_USED}MB / ${SWAP_TOTAL}MB (${SWAP_PCT}%)${NC}"
else
    echo -e "  Swap:       ${DIM}None${NC}"
fi
echo ""

# ── Disk ──────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Disk${NC}"
df -h / /home /backup 2>/dev/null | awk 'NR==1{next} !seen[$1]++ {
    pct=$5; gsub(/%/,"",pct);
    color="\033[1;32m"; if(pct>=80) color="\033[1;33m"; if(pct>=90) color="\033[1;31m";
    printf "  %-12s %s%s / %s (%s)%s\n", $6, color, $3, $2, $5, "\033[0m"
}'
echo ""

# ── Services ──────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Services${NC}"

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.4")
SERVICES="apache2 lighttpd php${PHP_VER}-fpm mariadb vsftpd cron fail2ban cloudflared wg-quick@wg0"

for svc in $SERVICES; do
    DISPLAY_NAME="$svc"
    # Clean up display names
    case "$svc" in
        php*-fpm)         DISPLAY_NAME="PHP-FPM (${PHP_VER})" ;;
        wg-quick@wg0)     DISPLAY_NAME="WireGuard" ;;
    esac

    if ! systemctl list-unit-files "${svc}.service" 2>/dev/null | grep -q "${svc}"; then
        printf "  %-20s ${DIM}not installed${NC}\n" "$DISPLAY_NAME"
        continue
    fi

    STATUS=$(systemctl is-active "$svc" 2>/dev/null)
    if [ "$STATUS" = "active" ]; then
        printf "  %-20s ${GREEN}●${NC} running\n" "$DISPLAY_NAME"
    else
        printf "  %-20s ${RED}●${NC} %s\n" "$DISPLAY_NAME" "$STATUS"
    fi
done
echo ""

# ── SSL Certificates ─────────────────────────────────────────────────────────
echo -e "${CYAN}▸ SSL Certificates${NC}"
CERT_COUNT=0
NEAREST_EXPIRY=""
NEAREST_DOMAIN=""
for cert in /etc/letsencrypt/live/*/fullchain.pem; do
    [ -f "$cert" ] || continue
    CERT_COUNT=$((CERT_COUNT + 1))
    DOMAIN=$(basename "$(dirname "$cert")")
    EXPIRY=$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2)
    EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s 2>/dev/null || echo 0)
    if [ -z "$NEAREST_EXPIRY" ] || [ "$EXPIRY_EPOCH" -lt "$NEAREST_EXPIRY" ]; then
        NEAREST_EXPIRY="$EXPIRY_EPOCH"
        NEAREST_DOMAIN="$DOMAIN"
    fi
done
echo -e "  Certificates: ${BOLD}${CERT_COUNT}${NC}"
if [ -n "$NEAREST_DOMAIN" ]; then
    DAYS_LEFT=$(( (NEAREST_EXPIRY - $(date +%s)) / 86400 ))
    if [ "$DAYS_LEFT" -lt 7 ]; then CERT_COLOR="$RED"; elif [ "$DAYS_LEFT" -lt 30 ]; then CERT_COLOR="$YELLOW"; else CERT_COLOR="$GREEN"; fi
    echo -e "  Next expiry: ${CERT_COLOR}${NEAREST_DOMAIN} (${DAYS_LEFT} days)${NC}"
fi
echo ""

# ── Backups ───────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Backups${NC}"
BACKUP_DIR=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='backup_destination'" 2>/dev/null)
[ -z "$BACKUP_DIR" ] && BACKUP_DIR="/backup"
if [ -d "$BACKUP_DIR" ]; then
    BACKUP_COUNT=$(ls "$BACKUP_DIR"/*.tgz 2>/dev/null | wc -l)
    BACKUP_SIZE=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)
    NEWEST=$(ls -t "$BACKUP_DIR"/*.tgz 2>/dev/null | head -1)
    if [ -n "$NEWEST" ]; then
        NEWEST_AGE=$(( ($(date +%s) - $(stat -c %Y "$NEWEST")) / 3600 ))
        echo -e "  Count:      ${BOLD}${BACKUP_COUNT}${NC} files (${BACKUP_SIZE})"
        echo -e "  Newest:     ${BOLD}$(basename "$NEWEST")${NC} (${NEWEST_AGE}h ago)"
    else
        echo -e "  ${DIM}No backups found${NC}"
    fi
else
    echo -e "  ${DIM}Backup directory not found${NC}"
fi
echo ""

# ── Accounts ──────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Accounts${NC}"
if [ -f "$PANEL_DB" ]; then
    USER_COUNT=$(sqlite3 "$PANEL_DB" "SELECT COUNT(*) FROM hosting_users" 2>/dev/null || echo 0)
    DOMAIN_COUNT=$(sqlite3 "$PANEL_DB" "SELECT COUNT(*) FROM domains" 2>/dev/null || echo 0)
    echo -e "  Users:      ${BOLD}${USER_COUNT}${NC}"
    echo -e "  Domains:    ${BOLD}${DOMAIN_COUNT}${NC}"
fi

# ── Panel Version ─────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}▸ Panel${NC}"
CURRENT_VER=$(grep "APP_VERSION" /var/www/inetpanel/TiCore/Version.php 2>/dev/null | grep -oP "'[^']+'" | tr -d "'")
LATEST_VER=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='panel_latest_ver'" 2>/dev/null)
echo -e "  Version:    ${BOLD}${CURRENT_VER:-unknown}${NC}"
if [ -n "$LATEST_VER" ] && [ "$LATEST_VER" != "$CURRENT_VER" ]; then
    echo -e "  Update:     ${YELLOW}v${LATEST_VER} available${NC}"
else
    echo -e "  Update:     ${GREEN}up to date${NC}"
fi
echo ""
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
