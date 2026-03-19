#!/bin/bash
# ==============================================================================
# speedtest.sh — Server bandwidth test
# Tests download speed, latency to common endpoints. No external dependencies.
# Usage: inetp speedtest [--quick]
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

QUICK=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --quick) QUICK=1; shift ;;
        *) shift ;;
    esac
done

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  Network Speed Test${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── Latency ───────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Latency${NC}"

TARGETS=(
    "1.1.1.1|Cloudflare"
    "8.8.8.8|Google DNS"
    "9.9.9.9|Quad9"
)

if [ "$QUICK" -eq 1 ]; then
    TARGETS=("1.1.1.1|Cloudflare")
fi

for entry in "${TARGETS[@]}"; do
    IP=$(echo "$entry" | cut -d'|' -f1)
    NAME=$(echo "$entry" | cut -d'|' -f2)

    LATENCY=$(curl -so /dev/null -w '%{time_connect}' --connect-timeout 5 "http://${IP}" 2>/dev/null)
    if [ -n "$LATENCY" ] && [ "$LATENCY" != "0.000000" ]; then
        MS=$(echo "$LATENCY" | awk '{printf "%.1f", $1*1000}')
        if (( $(echo "$MS < 50" | bc -l 2>/dev/null || echo 0) )); then COLOR="$GREEN"
        elif (( $(echo "$MS < 150" | bc -l 2>/dev/null || echo 0) )); then COLOR="$YELLOW"
        else COLOR="$RED"; fi
        printf "  %-16s ${COLOR}%sms${NC}\n" "$NAME" "$MS"
    else
        printf "  %-16s ${RED}timeout${NC}\n" "$NAME"
    fi
done
echo ""

# ── Download Speed ────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Download Speed${NC}"

ENDPOINTS=(
    "http://speedtest.tele2.net/10MB.zip|Tele2 (EU)|10"
    "http://proof.ovh.net/files/10Mb.dat|OVH (EU)|10"
    "http://speedtest.ftp.otenet.gr/files/test10Mb.db|OTEnet (EU)|10"
)

if [ "$QUICK" -eq 0 ]; then
    ENDPOINTS+=(
        "http://speedtest.tele2.net/100MB.zip|Tele2 100MB (EU)|100"
    )
fi

BEST_SPEED=0
BEST_NAME=""

for entry in "${ENDPOINTS[@]}"; do
    URL=$(echo "$entry" | cut -d'|' -f1)
    NAME=$(echo "$entry" | cut -d'|' -f2)
    SIZE=$(echo "$entry" | cut -d'|' -f3)

    echo -ne "  ${DIM}Testing ${NAME}...${NC}\r"
    SPEED=$(curl -so /dev/null -w '%{speed_download}' --connect-timeout 5 --max-time 30 "$URL" 2>/dev/null)

    if [ -n "$SPEED" ] && [ "$SPEED" != "0.000" ]; then
        SPEED_MBPS=$(echo "$SPEED" | awk '{printf "%.1f", ($1 * 8) / 1048576}')
        SPEED_MBS=$(echo "$SPEED" | awk '{printf "%.1f", $1 / 1048576}')

        if (( $(echo "$SPEED > $BEST_SPEED" | bc -l 2>/dev/null || echo 0) )); then
            BEST_SPEED="$SPEED"
            BEST_NAME="$NAME"
        fi

        if (( $(echo "$SPEED_MBS > 50" | bc -l 2>/dev/null || echo 0) )); then COLOR="$GREEN"
        elif (( $(echo "$SPEED_MBS > 10" | bc -l 2>/dev/null || echo 0) )); then COLOR="$YELLOW"
        else COLOR="$RED"; fi

        printf "  %-24s ${COLOR}%s MB/s${NC} (%s Mbps)\n" "$NAME" "$SPEED_MBS" "$SPEED_MBPS"
    else
        printf "  %-24s ${RED}failed${NC}\n" "$NAME"
    fi
done
echo ""

# ── Server IP ─────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Server Info${NC}"
SERVER_IP=$(curl -s --connect-timeout 5 https://api.ipify.org 2>/dev/null || ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
echo -e "  Public IP:  ${BOLD}${SERVER_IP:-unknown}${NC}"

# Get location info
GEO=$(curl -s --connect-timeout 5 "http://ip-api.com/line/${SERVER_IP}?fields=country,regionName,city,isp" 2>/dev/null)
if [ -n "$GEO" ]; then
    COUNTRY=$(echo "$GEO" | sed -n '1p')
    REGION=$(echo "$GEO" | sed -n '2p')
    CITY=$(echo "$GEO" | sed -n '3p')
    ISP=$(echo "$GEO" | sed -n '4p')
    echo -e "  Location:   ${BOLD}${CITY}, ${REGION}, ${COUNTRY}${NC}"
    echo -e "  ISP:        ${BOLD}${ISP}${NC}"
fi
echo ""

# ── Summary ───────────────────────────────────────────────────────────────────
if [ -n "$BEST_NAME" ]; then
    BEST_MBS=$(echo "$BEST_SPEED" | awk '{printf "%.1f", $1 / 1048576}')
    BEST_MBPS=$(echo "$BEST_SPEED" | awk '{printf "%.1f", ($1 * 8) / 1048576}')
    echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
    echo -e "  Best download: ${GREEN}${BOLD}${BEST_MBS} MB/s${NC} (${BEST_MBPS} Mbps) via ${BEST_NAME}"
    echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
fi
