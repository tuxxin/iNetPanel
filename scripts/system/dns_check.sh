#!/bin/bash
# ==============================================================================
# dns_check.sh — DNS, SSL, and connectivity diagnostics
# Usage: inetp dns_check --domain example.com
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

DOMAIN=""
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        *) shift ;;
    esac
done

[ -z "$DOMAIN" ] && { echo -e "${RED}Domain is required (--domain).${NC}"; exit 1; }

PASS=0; FAIL=0

result() {
    local status="$1" msg="$2"
    case "$status" in
        PASS) echo -e "  ${GREEN}✓ PASS${NC}  $msg"; PASS=$((PASS + 1)) ;;
        FAIL) echo -e "  ${RED}✗ FAIL${NC}  $msg"; FAIL=$((FAIL + 1)) ;;
        INFO) echo -e "  ${DIM}  INFO${NC}  $msg" ;;
    esac
}

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  DNS & Connectivity Check: ${DOMAIN}${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── DNS Resolution ────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ DNS Resolution${NC}"

# DNS lookup helper — uses `host` (common on Debian), falls back to `dig`
dns_lookup() {
    local domain="$1" server="$2"
    if command -v host &>/dev/null; then
        # Parse `host` output for addresses
        local out=$(host "$domain" "$server" 2>/dev/null)
        local addrs=$(echo "$out" | grep -oP 'has (IPv6 )?address \K\S+' | head -2)
        local cname=$(echo "$out" | grep -oP 'is an alias for \K\S+' | head -1)
        local mx=$(echo "$out" | grep 'mail is handled' | head -1 | sed 's/.*handled by /MX: /')
        if [ -n "$addrs" ]; then
            echo "$addrs"
        elif [ -n "$cname" ]; then
            echo "CNAME: $cname"
        fi
    elif command -v dig &>/dev/null; then
        local out=$(dig +short A "$domain" @"$server" 2>/dev/null)
        [ -z "$out" ] && out=$(dig +short AAAA "$domain" @"$server" 2>/dev/null)
        [ -z "$out" ] && out=$(dig +short CNAME "$domain" @"$server" 2>/dev/null)
        echo "$out" | head -2
    else
        echo ""
    fi
}

# Check from multiple public resolvers
DNS_SERVERS=("8.8.8.8" "1.1.1.1" "9.9.9.9")
DNS_NAMES=("Google" "Cloudflare" "Quad9")

for i in "${!DNS_SERVERS[@]}"; do
    SERVER="${DNS_SERVERS[$i]}"
    NAME="${DNS_NAMES[$i]}"
    RECORDS=$(dns_lookup "$DOMAIN" "$SERVER")

    if [ -n "$RECORDS" ]; then
        FIRST=$(echo "$RECORDS" | head -1)
        result PASS "${NAME} (${SERVER}): ${FIRST}"
    else
        result FAIL "${NAME} (${SERVER}): no records"
    fi
done

# MX records
if command -v host &>/dev/null; then
    MX_OUT=$(host -t MX "$DOMAIN" 1.1.1.1 2>/dev/null | grep 'mail is handled' | head -2 | sed 's/.*handled by //')
elif command -v dig &>/dev/null; then
    MX_OUT=$(dig +short MX "$DOMAIN" @1.1.1.1 2>/dev/null | head -2)
fi
if [ -n "$MX_OUT" ]; then
    result INFO "MX: $(echo "$MX_OUT" | tr '\n' ', ' | sed 's/,$//')"
fi

echo ""

# ── HTTP Response ─────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ HTTP Response${NC}"

# Follow redirects, show chain
HTTP_CODE=$(curl -sIL -o /dev/null -w '%{http_code}' --connect-timeout 10 --max-time 15 "https://${DOMAIN}" 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    result PASS "HTTPS response: ${HTTP_CODE}"
elif [ -n "$HTTP_CODE" ] && [ "$HTTP_CODE" != "000" ]; then
    result FAIL "HTTPS response: ${HTTP_CODE}"
else
    result FAIL "HTTPS: connection failed"
fi

# Check HTTP → HTTPS redirect
HTTP_REDIRECT=$(curl -sI -o /dev/null -w '%{redirect_url}' --connect-timeout 5 "http://${DOMAIN}" 2>/dev/null)
if echo "$HTTP_REDIRECT" | grep -qi "https://"; then
    result PASS "HTTP → HTTPS redirect active"
else
    result INFO "No HTTP → HTTPS redirect detected"
fi

# Response time
TTFB=$(curl -so /dev/null -w '%{time_starttransfer}' --connect-timeout 10 "https://${DOMAIN}" 2>/dev/null)
if [ -n "$TTFB" ]; then
    TTFB_MS=$(echo "$TTFB" | awk '{printf "%.0f", $1*1000}')
    if [ "$TTFB_MS" -lt 500 ]; then
        result PASS "TTFB: ${TTFB_MS}ms"
    elif [ "$TTFB_MS" -lt 2000 ]; then
        result INFO "TTFB: ${TTFB_MS}ms (could be faster)"
    else
        result FAIL "TTFB: ${TTFB_MS}ms (slow)"
    fi
fi
echo ""

# ── SSL Certificate ───────────────────────────────────────────────────────────
echo -e "${CYAN}▸ SSL Certificate${NC}"

SSL_INFO=$(echo | openssl s_client -servername "$DOMAIN" -connect "${DOMAIN}:443" 2>/dev/null)
if [ -n "$SSL_INFO" ]; then
    ISSUER=$(echo "$SSL_INFO" | openssl x509 -noout -issuer 2>/dev/null | sed 's/issuer= *//')
    EXPIRY=$(echo "$SSL_INFO" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
    SUBJECT=$(echo "$SSL_INFO" | openssl x509 -noout -subject 2>/dev/null | sed 's/subject= *//')

    if [ -n "$EXPIRY" ]; then
        EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s 2>/dev/null || echo 0)
        DAYS_LEFT=$(( (EXPIRY_EPOCH - $(date +%s)) / 86400 ))
        if [ "$DAYS_LEFT" -gt 30 ]; then
            result PASS "Valid for $DAYS_LEFT days (expires: $EXPIRY)"
        elif [ "$DAYS_LEFT" -gt 0 ]; then
            result FAIL "Expires in $DAYS_LEFT days! (expires: $EXPIRY)"
        else
            result FAIL "Certificate EXPIRED ($EXPIRY)"
        fi
    fi

    result INFO "Issuer: $ISSUER"

    # Verify chain
    VERIFY=$(echo | openssl s_client -servername "$DOMAIN" -connect "${DOMAIN}:443" 2>&1 | grep "Verify return code")
    if echo "$VERIFY" | grep -q "0 (ok)"; then
        result PASS "Certificate chain valid"
    else
        CODE=$(echo "$VERIFY" | grep -oP '\d+ \(.*?\)')
        result FAIL "Chain verification: $CODE"
    fi
else
    result FAIL "Could not connect to SSL on port 443"
fi
echo ""

# ── Apache vhost ──────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Local Configuration${NC}"

VHOST_FILE="/etc/apache2/sites-available/${DOMAIN}.conf"
if [ -f "$VHOST_FILE" ]; then
    result PASS "Apache vhost exists: $VHOST_FILE"
    if apache2ctl -S 2>/dev/null | grep -q "$DOMAIN"; then
        result PASS "Apache vhost is enabled"
    else
        result FAIL "Apache vhost exists but not enabled"
    fi
else
    result INFO "No Apache vhost file (may use Cloudflare tunnel)"
fi

# Cloudflare tunnel check
if [ -f "$PANEL_DB" ]; then
    IN_TUNNEL=$(sqlite3 "$PANEL_DB" "SELECT COUNT(*) FROM domains WHERE domain_name='$DOMAIN'" 2>/dev/null)
    if [ "$IN_TUNNEL" -gt 0 ]; then
        result PASS "Domain registered in panel database"
    else
        result FAIL "Domain not found in panel database"
    fi
fi
echo ""

# ── Summary ───────────────────────────────────────────────────────────────────
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "  ${GREEN}${PASS} passed${NC}  ${RED}${FAIL} failed${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"

[ "$FAIL" -gt 0 ] && exit 1
exit 0
