#!/bin/bash
# ==============================================================================
# rebuild_vhosts.sh — Regenerate missing Apache vhosts and flag broken ones
# Usage: inetp rebuild_vhosts [--check] [--disable-orphans]
#
# Two jobs, both driven by the panel database:
#
#   1. MISSING  — a domain the panel knows about that has no vhost in
#                 sites-available. The site's files and FPM pool are fine, but
#                 Apache has no idea it exists, so it 404s or falls through to
#                 another vhost. Regenerated from the same template add_domain.sh
#                 uses. Existing vhosts are never touched.
#
#   2. ORPHANED — an *enabled* vhost whose DocumentRoot or ErrorLog directory no
#                 longer exists, usually left behind by an interrupted delete.
#                 Apache keeps serving on its already-loaded config, so nothing
#                 looks wrong until the next full restart (an apt upgrade, a
#                 reboot) — at which point Apache refuses to start and *every*
#                 site on the box goes down. Reported by default; pass
#                 --disable-orphans to a2dissite them.
#
# Nothing is reloaded unless `apache2ctl configtest` passes.
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

CHECK_ONLY=0
DISABLE_ORPHANS=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --check)            CHECK_ONLY=1; shift ;;
        --disable-orphans)  DISABLE_ORPHANS=1; shift ;;
        *) shift ;;
    esac
done

[ ! -f "$PANEL_DB" ] && { echo -e "${RED}Panel database not found: ${PANEL_DB}${NC}"; exit 1; }

DEFAULT_PHP=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='php_default_version'" 2>/dev/null)
DEFAULT_PHP=${DEFAULT_PHP:-8.5}

echo -e "${BOLD}Rebuilding Apache VHosts${NC}"
echo ""

CREATED=0; SKIPPED=0; ERRORS=0; ORPHANS=0; CHANGED=0

# ── 1. Missing vhosts ─────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Missing vhosts${NC}"

while IFS="|" read -r DOMAIN USERNAME DOC_ROOT PHP_VER PORT; do
    [ -z "$DOMAIN" ] && continue
    VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"

    if [ -f "$VHOST_CONF" ]; then
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    if ! id "$USERNAME" &>/dev/null; then
        echo -e "  ${RED}ERROR${NC}   ${DOMAIN} — system user '${USERNAME}' does not exist"
        ERRORS=$((ERRORS + 1))
        continue
    fi

    [ -z "$DOC_ROOT" ] && DOC_ROOT="/home/${USERNAME}/${DOMAIN}/www"
    LOG_DIR="/home/${USERNAME}/${DOMAIN}/logs"

    # Port: prefer domains.port, fall back to account_ports, then auto-assign.
    if [ -z "$PORT" ] || [ "$PORT" = "0" ]; then
        PORT=$(sqlite3 "$PANEL_DB" "SELECT port FROM account_ports WHERE domain_name = '${DOMAIN}'" 2>/dev/null)
    fi
    if [ -z "$PORT" ]; then
        USED=$( { grep -hoE '^Listen[[:space:]]+[0-9]+' "$CUSTOM_PORTS_CONF" 2>/dev/null | awk '{print $2}'
                  grep -rhoE '<VirtualHost \*:[0-9]+>' /etc/apache2/sites-available/ 2>/dev/null | grep -oE '[0-9]+'
                  printf '%s\n' 80 443 1443 8443 8888; } | sort -un )
        PORT=1080
        while printf '%s\n' "$USED" | grep -qx "$PORT"; do PORT=$((PORT + 1)); done
        echo -e "  ${DIM}${DOMAIN} — no port on record, assigning ${PORT}${NC}"
    fi

    if [ "$CHECK_ONLY" -eq 1 ]; then
        echo -e "  ${YELLOW}MISSING${NC} ${DOMAIN} (${USERNAME}, port ${PORT})"
        CREATED=$((CREATED + 1))
        continue
    fi

    POOL_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
    FPM_SOCK="/run/php/php${PHP_VER}-fpm-${POOL_NAME}.sock"
    SSL_CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    SSL_KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"

    if [ ! -f "$SSL_CERT" ]; then
        echo -e "  ${RED}ERROR${NC}   ${DOMAIN} — no certificate at ${SSL_CERT}"
        echo -e "          ${DIM}issue one first: inetp ssl_manage issue ${DOMAIN} --self-signed${NC}"
        ERRORS=$((ERRORS + 1))
        continue
    fi

    mkdir -p "$DOC_ROOT" "$LOG_DIR"
    chown "${USERNAME}:www-data" "$DOC_ROOT" "$LOG_DIR" 2>/dev/null

    # ServerAlias www. only for apex domains — matches add_domain.sh / CF tunnel logic
    DOT_COUNT=$(echo "$DOMAIN" | tr -cd '.' | wc -c)
    if [ "$DOT_COUNT" -eq 1 ] && [[ "$DOMAIN" != www.* ]]; then
        SERVER_ALIAS="    ServerAlias www.${DOMAIN}"
    else
        SERVER_ALIAS=""
    fi

    cat << VHOST > "$VHOST_CONF"
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@${DOMAIN}
    ServerName  ${DOMAIN}
${SERVER_ALIAS}
    DocumentRoot ${DOC_ROOT}

    SSLEngine on
    SSLCertificateFile    ${SSL_CERT}
    SSLCertificateKeyFile ${SSL_KEY}

    # Redirect plain HTTP requests to HTTPS (when someone hits the port without TLS)
    ErrorDocument 400 "<!DOCTYPE html><html><head><script>location.replace(location.href.replace('http:','https:'))</script></head><body>Redirecting to HTTPS...</body></html>"

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:${FPM_SOCK}|fcgi://localhost"
    </FilesMatch>

    <Directory ${DOC_ROOT}>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        LimitRequestBody 104857600
    </Directory>

    ErrorLog  ${LOG_DIR}/apache_error.log
    CustomLog ${LOG_DIR}/apache_access.log combined
</VirtualHost>
VHOST

    grep -qx "Listen ${PORT}" "$CUSTOM_PORTS_CONF" 2>/dev/null || echo "Listen ${PORT}" >> "$CUSTOM_PORTS_CONF"
    a2ensite "${DOMAIN}.conf" > /dev/null 2>&1

    echo -e "  ${GREEN}CREATED${NC} ${DOMAIN} → ${VHOST_CONF} (port ${PORT})"
    CREATED=$((CREATED + 1))
    CHANGED=1
done < <(sqlite3 "$PANEL_DB" "SELECT d.domain_name, h.username, d.document_root,
             COALESCE(NULLIF(d.php_version,'inherit'), '$DEFAULT_PHP'), d.port
         FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id" 2>/dev/null)

[ "$CREATED" -eq 0 ] && echo -e "  ${DIM}None — all ${SKIPPED} domain(s) have a vhost.${NC}"
echo ""

# ── 2. Orphaned vhosts ────────────────────────────────────────────────────────
echo -e "${CYAN}▸ Orphaned vhosts${NC}"

for conf in /etc/apache2/sites-enabled/*.conf; do
    [ -e "$conf" ] || continue
    NAME=$(basename "$conf")
    DOC=$(grep -oP 'DocumentRoot\s+\K\S+' "$conf" 2>/dev/null | head -1)
    ERR=$(grep -oP 'ErrorLog\s+\K\S+' "$conf" 2>/dev/null | head -1)

    REASONS=""
    # Skip paths built from Apache env vars (${APACHE_LOG_DIR} etc.) — not resolvable here.
    case "$DOC" in *'${'*) DOC="" ;; esac
    case "$ERR" in *'${'*) ERR="" ;; esac
    [ -n "$DOC" ] && [ ! -d "$DOC" ] && REASONS="${REASONS}\n            DocumentRoot missing: ${DOC}"
    [ -n "$ERR" ] && [ ! -d "$(dirname "$ERR")" ] && REASONS="${REASONS}\n            log dir missing:      $(dirname "$ERR")"

    [ -z "$REASONS" ] && continue

    ORPHANS=$((ORPHANS + 1))
    echo -e "  ${RED}ORPHAN${NC}  ${NAME}${REASONS}"

    if [ "$DISABLE_ORPHANS" -eq 1 ] && [ "$CHECK_ONLY" -eq 0 ]; then
        a2dissite "$NAME" > /dev/null 2>&1
        echo -e "          ${YELLOW}disabled${NC}"
        CHANGED=1
    fi
done

if [ "$ORPHANS" -eq 0 ]; then
    echo -e "  ${DIM}None.${NC}"
elif [ "$DISABLE_ORPHANS" -eq 0 ]; then
    echo ""
    echo -e "  ${YELLOW}A missing log directory is fatal at Apache startup. These will keep${NC}"
    echo -e "  ${YELLOW}serving until something restarts Apache, then it will refuse to start${NC}"
    echo -e "  ${YELLOW}and every site goes down. Re-run with --disable-orphans to clear them.${NC}"
fi
echo ""

# ── Reload ────────────────────────────────────────────────────────────────────
if [ "$CHECK_ONLY" -eq 1 ]; then
    echo -e "${DIM}--check: nothing was modified.${NC}"
elif [ "$CHANGED" -eq 1 ]; then
    if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
        systemctl reload apache2 && echo -e "  ${GREEN}Reloaded apache2${NC}"
    else
        echo -e "${RED}Apache config is invalid — NOT reloading.${NC}"
        apache2ctl configtest 2>&1 | sed 's/^/    /'
        exit 1
    fi
fi

echo ""
echo -e "Created: ${BOLD}${CREATED}${NC}   Skipped: ${BOLD}${SKIPPED}${NC}   Orphaned: ${BOLD}${ORPHANS}${NC}   Errors: ${BOLD}${ERRORS}${NC}"
