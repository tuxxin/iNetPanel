#!/bin/bash
# ==============================================================================
# cleanup.sh — System cleanup
# Removes temp files, old logs, orphaned FPM pools, stale sessions.
# Usage: inetp cleanup [--dry-run]
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

DRY_RUN=0
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        *) shift ;;
    esac
done

TOTAL_FREED=0

cleanup_files() {
    local label="$1" pattern="$2" path="$3" age="$4"
    local count=0 size=0

    if [ -n "$age" ]; then
        FILES=$(find "$path" -maxdepth 1 -name "$pattern" -type f -mtime +"$age" 2>/dev/null)
    else
        FILES=$(find "$path" -maxdepth 1 -name "$pattern" -type f 2>/dev/null)
    fi

    while IFS= read -r f; do
        [ -z "$f" ] && continue
        FSIZE=$(stat -c%s "$f" 2>/dev/null || echo 0)
        size=$((size + FSIZE))
        count=$((count + 1))
        if [ "$DRY_RUN" -eq 0 ]; then
            rm -f "$f"
        fi
    done <<< "$FILES"

    SIZE_HR=$(numfmt --to=iec "$size" 2>/dev/null || echo "${size}B")
    TOTAL_FREED=$((TOTAL_FREED + size))

    if [ "$count" -gt 0 ]; then
        if [ "$DRY_RUN" -eq 1 ]; then
            echo -e "  ${YELLOW}[DRY]${NC} $label: $count files ($SIZE_HR)"
        else
            echo -e "  ${GREEN}✓${NC} $label: $count files removed ($SIZE_HR)"
        fi
    else
        echo -e "  ${DIM}  $label: clean${NC}"
    fi
}

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
if [ "$DRY_RUN" -eq 1 ]; then
    echo -e "${BOLD}  System Cleanup ${YELLOW}(DRY RUN)${NC}"
else
    echo -e "${BOLD}  System Cleanup${NC}"
fi
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── PHP Sessions ──────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ PHP Sessions${NC}"
cleanup_files "PHP sessions (>24h)" "sess_*" "/var/lib/php/sessions" "1"
echo ""

# ── Temp Files ────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Temp Files${NC}"
cleanup_files "iNetPanel temp files" "inetp_*" "/tmp" ""
cleanup_files "PMA signon tokens" "pma_signon_*" "/tmp" ""
cleanup_files "Hook temp files" "inetp_hook_*" "/tmp" ""
echo ""

# ── Old Rotated Logs ──────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Old Rotated Logs (>30 days)${NC}"
LOG_COUNT=0
LOG_SIZE=0
while IFS= read -r f; do
    [ -z "$f" ] && continue
    FSIZE=$(stat -c%s "$f" 2>/dev/null || echo 0)
    LOG_SIZE=$((LOG_SIZE + FSIZE))
    LOG_COUNT=$((LOG_COUNT + 1))
    [ "$DRY_RUN" -eq 0 ] && rm -f "$f"
done < <(find /var/log -name "*.gz" -type f -mtime +30 2>/dev/null)

LOG_HR=$(numfmt --to=iec "$LOG_SIZE" 2>/dev/null || echo "${LOG_SIZE}B")
TOTAL_FREED=$((TOTAL_FREED + LOG_SIZE))
if [ "$LOG_COUNT" -gt 0 ]; then
    if [ "$DRY_RUN" -eq 1 ]; then
        echo -e "  ${YELLOW}[DRY]${NC} Compressed logs: $LOG_COUNT files ($LOG_HR)"
    else
        echo -e "  ${GREEN}✓${NC} Compressed logs: $LOG_COUNT files removed ($LOG_HR)"
    fi
else
    echo -e "  ${DIM}  Compressed logs: clean${NC}"
fi
echo ""

# ── Orphaned FPM Pools ───────────────────────────────────────────────────────
echo -e "${CYAN}▸ Orphaned PHP-FPM Pools${NC}"
ORPHAN_COUNT=0
for pool_conf in /etc/php/*/fpm/pool.d/*.conf; do
    [ -f "$pool_conf" ] || continue
    POOL_NAME=$(basename "$pool_conf" .conf)
    [ "$POOL_NAME" = "www" ] && continue
    if ! id "$POOL_NAME" &>/dev/null; then
        ORPHAN_COUNT=$((ORPHAN_COUNT + 1))
        if [ "$DRY_RUN" -eq 1 ]; then
            echo -e "  ${YELLOW}[DRY]${NC} Orphaned pool: $POOL_NAME (user doesn't exist)"
        else
            rm -f "$pool_conf"
            echo -e "  ${GREEN}✓${NC} Removed orphaned pool: $POOL_NAME"
        fi
    fi
done
if [ "$ORPHAN_COUNT" -eq 0 ]; then
    echo -e "  ${DIM}  No orphaned pools${NC}"
fi
echo ""

# ── Stale Backup Files ───────────────────────────────────────────────────────
echo -e "${CYAN}▸ Stale Backups${NC}"
RETENTION=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='backup_retention'" 2>/dev/null)
RETENTION=${RETENTION:-3}
BACKUP_DIR=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='backup_destination'" 2>/dev/null)
BACKUP_DIR=${BACKUP_DIR:-/backup}
if [ -d "$BACKUP_DIR" ]; then
    STALE_COUNT=0
    STALE_SIZE=0
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        FSIZE=$(stat -c%s "$f" 2>/dev/null || echo 0)
        STALE_SIZE=$((STALE_SIZE + FSIZE))
        STALE_COUNT=$((STALE_COUNT + 1))
        [ "$DRY_RUN" -eq 0 ] && rm -f "$f"
    done < <(find "$BACKUP_DIR" -name "*.tgz" -type f -mtime +"$RETENTION" 2>/dev/null)
    STALE_HR=$(numfmt --to=iec "$STALE_SIZE" 2>/dev/null || echo "${STALE_SIZE}B")
    TOTAL_FREED=$((TOTAL_FREED + STALE_SIZE))
    if [ "$STALE_COUNT" -gt 0 ]; then
        if [ "$DRY_RUN" -eq 1 ]; then
            echo -e "  ${YELLOW}[DRY]${NC} Stale backups (>${RETENTION}d): $STALE_COUNT files ($STALE_HR)"
        else
            echo -e "  ${GREEN}✓${NC} Stale backups: $STALE_COUNT files removed ($STALE_HR)"
        fi
    else
        echo -e "  ${DIM}  No stale backups${NC}"
    fi
else
    echo -e "  ${DIM}  Backup directory not found${NC}"
fi
echo ""

# ── Summary ───────────────────────────────────────────────────────────────────
TOTAL_HR=$(numfmt --to=iec "$TOTAL_FREED" 2>/dev/null || echo "${TOTAL_FREED}B")
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
if [ "$DRY_RUN" -eq 1 ]; then
    echo -e "${BOLD}  Would free: ${YELLOW}${TOTAL_HR}${NC}"
    echo -e "  ${DIM}Run without --dry-run to apply.${NC}"
else
    echo -e "${BOLD}  Space freed: ${GREEN}${TOTAL_HR}${NC}"
fi
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
