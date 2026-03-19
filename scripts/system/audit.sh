#!/bin/bash
# ==============================================================================
# audit.sh — Security audit
# Checks file permissions, open ports, SSH config, SSL expiry, PHP versions, etc.
# Usage: inetp audit
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

PASS=0; WARN=0; FAIL=0

result() {
    local status="$1" msg="$2"
    case "$status" in
        PASS) echo -e "  ${GREEN}✓ PASS${NC}  $msg"; PASS=$((PASS + 1)) ;;
        WARN) echo -e "  ${YELLOW}! WARN${NC}  $msg"; WARN=$((WARN + 1)) ;;
        FAIL) echo -e "  ${RED}✗ FAIL${NC}  $msg"; FAIL=$((FAIL + 1)) ;;
    esac
}

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  iNetPanel Security Audit${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo ""

# ── File Permissions ──────────────────────────────────────────────────────────
echo -e "${CYAN}▸ File Permissions${NC}"

# World-writable files in /home
WW_COUNT=$(find /home -type f -perm -o+w 2>/dev/null | wc -l)
if [ "$WW_COUNT" -eq 0 ]; then
    result PASS "No world-writable files in /home"
elif [ "$WW_COUNT" -lt 10 ]; then
    result WARN "Found $WW_COUNT world-writable files in /home"
else
    result FAIL "Found $WW_COUNT world-writable files in /home"
fi

# Root-owned files in user dirs — only flag executable PHP/shell files (actual risk)
ROOT_EXEC=$(find /home -mindepth 2 -user root -type f \( -name "*.php" -o -name "*.sh" \) -perm /u+x 2>/dev/null | wc -l)
ROOT_TOTAL=$(find /home -mindepth 2 -user root -type f 2>/dev/null | wc -l)
if [ "$ROOT_EXEC" -eq 0 ]; then
    if [ "$ROOT_TOTAL" -eq 0 ]; then
        result PASS "No root-owned files in user directories"
    else
        result PASS "Root-owned files in /home: $ROOT_TOTAL (none executable — no risk)"
    fi
else
    result WARN "Found $ROOT_EXEC executable root-owned scripts in /home — review for security"
fi

# Panel DB permissions
if [ -f "/var/www/inetpanel/db/inetpanel.db" ]; then
    DB_PERMS=$(stat -c "%a" /var/www/inetpanel/db/inetpanel.db)
    DB_OWNER=$(stat -c "%U:%G" /var/www/inetpanel/db/inetpanel.db)
    if [ "$DB_OWNER" = "www-data:www-data" ] && [ "$DB_PERMS" = "664" ] || [ "$DB_PERMS" = "644" ] || [ "$DB_PERMS" = "660" ]; then
        result PASS "Panel database permissions OK ($DB_PERMS, $DB_OWNER)"
    else
        result WARN "Panel database: $DB_PERMS owned by $DB_OWNER (expected www-data:www-data 664)"
    fi
fi
echo ""

# ── SSH Configuration ─────────────────────────────────────────────────────────
echo -e "${CYAN}▸ SSH Configuration${NC}"

SSH_PORT=$(grep -E "^Port " /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}')
SSH_PORT=${SSH_PORT:-22}
if [ "$SSH_PORT" != "22" ]; then
    result PASS "SSH port: $SSH_PORT (non-default)"
else
    result WARN "SSH port: 22 (default — consider changing)"
fi

ROOT_LOGIN=$(grep -E "^PermitRootLogin" /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}')
if [ "$ROOT_LOGIN" = "no" ] || [ "$ROOT_LOGIN" = "prohibit-password" ]; then
    result PASS "Root login: $ROOT_LOGIN"
else
    result WARN "Root login: ${ROOT_LOGIN:-yes (default)} — consider restricting"
fi

PASS_AUTH=$(grep -E "^PasswordAuthentication" /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}')
if [ "$PASS_AUTH" = "no" ]; then
    result PASS "Password auth: disabled (key-only)"
else
    result WARN "Password auth: ${PASS_AUTH:-yes} — consider disabling for key-only"
fi
echo ""

# ── Open Ports ────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Open Ports${NC}"

# Build expected ports: core services + all Apache vhost ports + WireGuard
# Core services + common system ports (DNS, SMTP, mDNS, cloudflared metrics)
EXPECTED_PORTS="80 443 ${SSH_PORT} 3306 8888 8443 21 1443 25 53 5353 5355"
# Add cloudflared metrics port (localhost only)
CF_PORT=$(ss -tlnp 2>/dev/null | grep cloudflared | grep -oP ':\K\d+' | head -1)
[ -n "$CF_PORT" ] && EXPECTED_PORTS="$EXPECTED_PORTS $CF_PORT"

# Add all ports from Apache vhost configs (hosting sites use 1080+)
for vhost in /etc/apache2/sites-available/*.conf; do
    [ -f "$vhost" ] || continue
    grep -oP '(?<=<VirtualHost \*:)\d+' "$vhost" 2>/dev/null
done | sort -un | while read p; do EXPECTED_PORTS="$EXPECTED_PORTS $p"; done

# Also read ports from panel DB
if [ -f "$PANEL_DB" ]; then
    DB_PORTS=$(sqlite3 "$PANEL_DB" "SELECT DISTINCT port FROM domains WHERE port IS NOT NULL" 2>/dev/null)
    EXPECTED_PORTS="$EXPECTED_PORTS $DB_PORTS"
fi

# Collect Apache vhost ports into a variable (subshell workaround)
APACHE_PORTS=$(grep -ohP '(?<=<VirtualHost \*:)\d+' /etc/apache2/sites-available/*.conf 2>/dev/null | sort -un | tr '\n' ' ')
EXPECTED_PORTS="$EXPECTED_PORTS $APACHE_PORTS"

OPEN_PORTS=$(ss -tlnp 2>/dev/null | awk 'NR>1 {split($4,a,":"); print a[length(a)]}' | sort -un)

UNEXPECTED=""
for port in $OPEN_PORTS; do
    if ! echo "$EXPECTED_PORTS" | grep -qw "$port"; then
        UNEXPECTED="$UNEXPECTED $port"
    fi
done

if [ -z "$UNEXPECTED" ]; then
    result PASS "All open ports are expected"
else
    result WARN "Unexpected open ports:$UNEXPECTED"
fi
echo ""

# ── Firewall ──────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Firewall${NC}"
if systemctl is-active firewalld &>/dev/null; then
    result PASS "firewalld is running"
else
    result FAIL "firewalld is not running"
fi

if systemctl is-active fail2ban &>/dev/null; then
    BANNED=$(fail2ban-client status 2>/dev/null | grep -oP 'Number of jail:\s+\K\d+')
    TOTAL_BANNED=$(fail2ban-client status sshd 2>/dev/null | grep -oP 'Currently banned:\s+\K\d+')
    result PASS "fail2ban running ($BANNED jails, $TOTAL_BANNED banned IPs in sshd)"
else
    result FAIL "fail2ban is not running"
fi
echo ""

# ── PHP Versions ──────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ PHP Versions${NC}"
EOL_VERSIONS="5.6 7.0 7.1 7.2 7.3 7.4 8.0 8.1"
EOL_FOUND=0

# Check domains assigned to EOL PHP versions via panel DB
if [ -f "$PANEL_DB" ]; then
    while IFS='|' read -r domain php_ver username; do
        if echo "$EOL_VERSIONS" | grep -qw "$php_ver"; then
            result WARN "PHP $php_ver assigned to $domain (user: $username) — EOL"
            EOL_FOUND=$((EOL_FOUND + 1))
        fi
    done < <(sqlite3 "$PANEL_DB" "SELECT d.domain_name, d.php_version, h.username FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id WHERE d.php_version IS NOT NULL" 2>/dev/null)
fi

# Also check for EOL FPM pool configs (skip default 'www' pool)
for pool_conf in /etc/php/*/fpm/pool.d/*.conf; do
    [ -f "$pool_conf" ] || continue
    POOL_NAME=$(basename "$pool_conf" .conf)
    [ "$POOL_NAME" = "www" ] && continue
    VER=$(echo "$pool_conf" | grep -oP '/php/\K[^/]+')
    if echo "$EOL_VERSIONS" | grep -qw "$VER"; then
        result WARN "PHP $VER FPM pool '$POOL_NAME' active (EOL)"
        EOL_FOUND=$((EOL_FOUND + 1))
    fi
done

if [ "$EOL_FOUND" -eq 0 ]; then
    result PASS "No domains or pools using EOL PHP versions"
fi

# Check default CLI version
DEFAULT_PHP=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
if echo "$EOL_VERSIONS" | grep -qw "$DEFAULT_PHP"; then
    result FAIL "Default PHP version $DEFAULT_PHP is EOL"
else
    result PASS "Default PHP version $DEFAULT_PHP is supported"
fi
echo ""

# ── SSL Certificates ─────────────────────────────────────────────────────────
echo -e "${CYAN}▸ SSL Certificates${NC}"
EXPIRING_SOON=0
for cert in /etc/letsencrypt/live/*/fullchain.pem; do
    [ -f "$cert" ] || continue
    DOMAIN=$(basename "$(dirname "$cert")")
    EXPIRY=$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2)
    EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s 2>/dev/null || echo 0)
    DAYS_LEFT=$(( (EXPIRY_EPOCH - $(date +%s)) / 86400 ))
    if [ "$DAYS_LEFT" -lt 7 ]; then
        result FAIL "SSL cert for $DOMAIN expires in $DAYS_LEFT days"
        EXPIRING_SOON=$((EXPIRING_SOON + 1))
    elif [ "$DAYS_LEFT" -lt 30 ]; then
        result WARN "SSL cert for $DOMAIN expires in $DAYS_LEFT days"
        EXPIRING_SOON=$((EXPIRING_SOON + 1))
    fi
done
if [ "$EXPIRING_SOON" -eq 0 ]; then
    CERT_COUNT=$(ls /etc/letsencrypt/live/*/fullchain.pem 2>/dev/null | wc -l)
    result PASS "All $CERT_COUNT SSL certificates valid (>30 days)"
fi
echo ""

# ── Sudoers ───────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Sudoers${NC}"
if visudo -cf /etc/sudoers.d/inetpanel &>/dev/null; then
    result PASS "Sudoers file syntax OK"
else
    result FAIL "Sudoers file has syntax errors"
fi
SUDOERS_PERMS=$(stat -c "%a" /etc/sudoers.d/inetpanel 2>/dev/null)
if [ "$SUDOERS_PERMS" = "440" ]; then
    result PASS "Sudoers file permissions correct (440)"
else
    result WARN "Sudoers file permissions: $SUDOERS_PERMS (expected 440)"
fi
echo ""

# ── Summary ───────────────────────────────────────────────────────────────────
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
TOTAL=$((PASS + WARN + FAIL))
echo -e "${BOLD}  Audit Summary: ${GREEN}${PASS} passed${NC}  ${YELLOW}${WARN} warnings${NC}  ${RED}${FAIL} failed${NC}  (${TOTAL} checks)"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"

[ "$FAIL" -gt 0 ] && exit 1
exit 0
