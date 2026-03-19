#!/bin/bash
# ==============================================================================
# benchmark.sh — Quick server benchmark
# Tests: disk I/O, network speed, PHP OPcache, MySQL response time
# Usage: inetp benchmark [--quick]
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; BLUE='\033[1;34m'; CYAN='\033[1;36m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; BLUE=''; CYAN=''; NC=''
fi

QUICK=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --quick) QUICK=1; shift ;;
        *) shift ;;
    esac
done

MYSQL_PASS=$(cat /root/.mysql_root_pass 2>/dev/null)

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  iNetPanel Server Benchmark${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── Disk I/O ──────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Disk I/O${NC}"
BENCH_FILE="/tmp/inetp_bench_$$"

# Write test
echo -n "  Write:      "
WRITE_RESULT=$(dd if=/dev/zero of="$BENCH_FILE" bs=1M count=256 conv=fdatasync 2>&1 | grep -oP '[\d.]+ [MGKT]?B/s' | tail -1)
echo -e "${BOLD}${WRITE_RESULT:-N/A}${NC}"

# Read test (clear cache first)
sync
echo 3 > /proc/sys/vm/drop_caches 2>/dev/null
echo -n "  Read:       "
READ_RESULT=$(dd if="$BENCH_FILE" of=/dev/null bs=1M 2>&1 | grep -oP '[\d.]+ [MGKT]?B/s' | tail -1)
echo -e "${BOLD}${READ_RESULT:-N/A}${NC}"

rm -f "$BENCH_FILE"
echo ""

# ── Network ───────────────────────────────────────────────────────────────────
if [ "$QUICK" -eq 0 ]; then
    echo -e "${CYAN}▸ Network Speed${NC}"

    # Download test — try multiple endpoints
    ENDPOINTS=(
        "http://speedtest.tele2.net/10MB.zip"
        "http://proof.ovh.net/files/10Mb.dat"
        "http://speedtest.ftp.otenet.gr/files/test10Mb.db"
    )

    for URL in "${ENDPOINTS[@]}"; do
        SPEED=$(curl -so /dev/null -w '%{speed_download}' --connect-timeout 5 --max-time 15 "$URL" 2>/dev/null)
        if [ -n "$SPEED" ] && [ "$SPEED" != "0.000" ]; then
            SPEED_MB=$(echo "$SPEED" | awk '{printf "%.1f", $1/1048576}')
            echo -e "  Download:   ${BOLD}${SPEED_MB} MB/s${NC} ${DIM}($(basename "$URL"))${NC}"
            break
        fi
    done

    # Latency
    echo -n "  Latency:    "
    LATENCY=$(curl -so /dev/null -w '%{time_connect}' --connect-timeout 5 "https://1.1.1.1" 2>/dev/null)
    if [ -n "$LATENCY" ]; then
        LATENCY_MS=$(echo "$LATENCY" | awk '{printf "%.0f", $1*1000}')
        echo -e "${BOLD}${LATENCY_MS}ms${NC} ${DIM}(to 1.1.1.1)${NC}"
    else
        echo -e "${DIM}N/A${NC}"
    fi
    echo ""
else
    echo -e "${DIM}  Network test skipped (--quick)${NC}"
    echo ""
fi

# ── PHP ───────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ PHP${NC}"
PHP_VER=$(php -v 2>/dev/null | head -1 | awk '{print $2}')
echo -e "  Version:    ${BOLD}${PHP_VER:-N/A}${NC}"

OPCACHE=$(php -r '
$s = opcache_get_status(false);
if (!$s || !$s["opcache_enabled"]) { echo "disabled"; exit; }
$mem = $s["memory_usage"];
$stats = $s["opcache_statistics"];
$used = round($mem["used_memory"]/1048576, 1);
$free = round($mem["free_memory"]/1048576, 1);
$rate = $stats["opcache_hit_rate"];
printf("enabled | %.1fMB used / %.1fMB free | Hit rate: %.1f%%", $used, $free, $rate);
' 2>/dev/null)
echo -e "  OPcache:    ${BOLD}${OPCACHE:-N/A}${NC}"
echo ""

# ── MySQL ─────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ MariaDB${NC}"
if [ -n "$MYSQL_PASS" ]; then
    MYSQL_VER=$(mysql -u root -p"$MYSQL_PASS" -e "SELECT VERSION();" -sN 2>/dev/null)
    echo -e "  Version:    ${BOLD}${MYSQL_VER:-N/A}${NC}"

    # Query time
    START=$(date +%s%N)
    mysql -u root -p"$MYSQL_PASS" -e "SELECT 1;" -sN >/dev/null 2>&1
    END=$(date +%s%N)
    QUERY_MS=$(( (END - START) / 1000000 ))
    echo -e "  Query time: ${BOLD}${QUERY_MS}ms${NC} ${DIM}(SELECT 1)${NC}"

    # Status
    MYSQL_STATUS=$(mysqladmin -u root -p"$MYSQL_PASS" status 2>/dev/null)
    UPTIME=$(echo "$MYSQL_STATUS" | grep -oP 'Uptime: \d+' | awk '{print $2}')
    THREADS=$(echo "$MYSQL_STATUS" | grep -oP 'Threads: \d+' | awk '{print $2}')
    QUERIES=$(echo "$MYSQL_STATUS" | grep -oP 'Questions: \d+' | awk '{print $2}')
    if [ -n "$UPTIME" ]; then
        UPTIME_H=$(( UPTIME / 3600 ))
        echo -e "  Uptime:     ${BOLD}${UPTIME_H}h${NC}  Threads: ${BOLD}${THREADS}${NC}  Queries: ${BOLD}${QUERIES}${NC}"
    fi
else
    echo -e "  ${DIM}MySQL root password not found${NC}"
fi
echo ""

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  Benchmark complete.${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
