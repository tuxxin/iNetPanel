#!/bin/bash
# ==============================================================================
# rotate_logs.sh — Force log rotation
# Rotates system logs + per-user site logs, compresses old logs.
# Usage: inetp rotate_logs
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  Log Rotation${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── System Logrotate ──────────────────────────────────────────────────────────
echo -e "${CYAN}▸ System Logs${NC}"
if logrotate -f /etc/logrotate.conf 2>/dev/null; then
    echo -e "  ${GREEN}✓${NC} System logrotate completed"
else
    echo -e "  ${YELLOW}!${NC} System logrotate had warnings (non-fatal)"
fi
echo ""

# ── User Site Logs ────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ User Site Logs${NC}"
ROTATED=0
COMPRESSED=0
SAVED=0

for logdir in /home/*/*/logs; do
    [ -d "$logdir" ] || continue
    USERNAME=$(echo "$logdir" | cut -d/ -f3)
    DOMAIN=$(echo "$logdir" | cut -d/ -f4)

    for logfile in "$logdir"/*.log; do
        [ -f "$logfile" ] || continue
        BASENAME=$(basename "$logfile")
        SIZE=$(stat -c%s "$logfile" 2>/dev/null || echo 0)

        # Skip small logs (<1KB)
        [ "$SIZE" -lt 1024 ] && continue

        # Rotate: rename current → .1, compress previous .1 → .1.gz
        if [ -f "${logfile}.1" ]; then
            gzip -f "${logfile}.1" 2>/dev/null
            COMPRESSED=$((COMPRESSED + 1))
        fi

        cp "$logfile" "${logfile}.1"
        : > "$logfile"
        chown "$USERNAME:www-data" "$logfile" "${logfile}.1" 2>/dev/null

        SIZE_HR=$(numfmt --to=iec "$SIZE" 2>/dev/null || echo "${SIZE}B")
        echo -e "  ${GREEN}✓${NC} ${DIM}${USERNAME}/${DOMAIN}/${NC}${BASENAME} (${SIZE_HR})"
        ROTATED=$((ROTATED + 1))
        SAVED=$((SAVED + SIZE))
    done

    # Clean up old compressed logs (>14 days)
    find "$logdir" -name "*.gz" -mtime +14 -delete 2>/dev/null
done

if [ "$ROTATED" -eq 0 ]; then
    echo -e "  ${DIM}No user logs needed rotation${NC}"
fi
echo ""

# ── Summary ───────────────────────────────────────────────────────────────────
SAVED_HR=$(numfmt --to=iec "$SAVED" 2>/dev/null || echo "${SAVED}B")
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "  Rotated: ${BOLD}${ROTATED}${NC} log files  Compressed: ${BOLD}${COMPRESSED}${NC}  Cleared: ${BOLD}${SAVED_HR}${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
