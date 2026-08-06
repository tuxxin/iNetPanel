#!/bin/bash
# ==============================================================================
# remove_domain.sh — Removes a single domain from a hosting user
#   - Apache vhost + port entry
#   - PHP-FPM pool
#   - SSL certificate
#   - MariaDB database (domain-specific, NOT the user)
#   - Domain directory
#   Does NOT delete the Linux user or their other domains.
# Usage: remove_domain.sh --username <user> --domain <domain> [--no-backup]
# ==============================================================================

# Every step below is ATTEMPT -> VERIFY THE POST-CONDITION -> CLASSIFY, and the
# script exits non-zero if any of them failed. It used to end on an `echo` and
# so exited 0 regardless, which is why PHP would delete the domains row — the
# only record of what still needed removing — after a step had silently failed.
source /root/scripts/lib_account.sh 2>/dev/null || {
    echo "error: /root/scripts/lib_account.sh not found — refusing to guess what to delete." >&2
    exit 1
}

CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
SCRIPTS_DIR="/root/scripts"

USERNAME=""
DOMAIN=""
NO_BACKUP=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)  USERNAME="$2"; shift 2 ;;
        --domain)    DOMAIN="$2";   shift 2 ;;
        --no-backup) NO_BACKUP=1;   shift ;;
        *) shift ;;
    esac
done

validate_username "$USERNAME" || exit 1
[ -z "$DOMAIN" ] && { echo -e "${RED}Domain required (--domain).${NC}"; exit 1; }
# The domain is interpolated into rm -rf paths and a database name.
if ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$ ]]; then
    echo -e "${RED}Refusing to operate on invalid domain '${DOMAIN}'.${NC}"
    exit 1
fi

echo -e "${BOLD}--- Removing Domain: ${DOMAIN} from ${USERNAME} ---${NC}"

# ----------------------------------------------------------------
# Optional backup
# ----------------------------------------------------------------
if [ "$NO_BACKUP" -eq 0 ] && [ -d "/home/$USERNAME/$DOMAIN" ]; then
    echo -e "${YELLOW}Backing up domain files...${NC}"
    timeout 120 bash "$SCRIPTS_DIR/backup_accounts.sh" --single "$USERNAME" 2>/dev/null || echo -e "${YELLOW}Backup timed out or failed, continuing with deletion.${NC}"
fi

# ----------------------------------------------------------------
# Apache VHost
# ----------------------------------------------------------------
section "Apache"
VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"
PORT=""
if [ -f "$VHOST_CONF" ]; then
    PORT=$(grep '<VirtualHost' "$VHOST_CONF" | grep -oE ':[0-9]+' | tr -d ':')
    a2dissite "${DOMAIN}.conf" > /dev/null 2>&1
    rm -f "$VHOST_CONF"
fi
if [ -e "$VHOST_CONF" ] || [ -e "/etc/apache2/sites-enabled/${DOMAIN}.conf" ]; then
    step_fail "vhost still present for ${DOMAIN}"
else
    step_ok "vhost removed"
fi

# The Listen line is removed outside the `if [ -f $VHOST_CONF ]` block it used
# to live in, so a vhost that was already gone no longer burns its port forever.
if [ -n "$PORT" ]; then
    # Same flock convention add_domain.sh uses, so a concurrent add cannot
    # allocate this port while the old vhost is still being torn down.
    exec 9>>"$CUSTOM_PORTS_CONF"
    flock 9
    sed -i "/^Listen ${PORT}$/d" "$CUSTOM_PORTS_CONF"
    flock -u 9
    exec 9>&-
    if grep -qx "Listen ${PORT}" "$CUSTOM_PORTS_CONF" 2>/dev/null; then
        step_fail "Listen ${PORT} still in ${CUSTOM_PORTS_CONF}"
    else
        step_ok "released port ${PORT}"
    fi

    # Best effort only — the firewall must never be able to block a deletion.
    if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        firewall-cmd --permanent --remove-port="${PORT}/tcp" >/dev/null 2>&1 \
            && firewall-cmd --reload >/dev/null 2>&1 \
            && step_ok "closed firewall port ${PORT}/tcp" \
            || step_warn "could not close firewall port ${PORT}/tcp"
    fi
fi

# Validate before touching Apache. Never escalate a failed reload into a
# restart: a reload that fails leaves the running (good) config serving,
# but a restart on a broken config takes every site on the box down.
if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    systemctl reload apache2 2>/dev/null
    step_ok "apache config valid, reloaded"
else
    step_fail "Apache config is invalid — NOT reloading"
    apache2ctl configtest 2>&1 | sed 's/^/      /'
    echo -e "${YELLOW}      Sites keep serving on the old config. Fix, then: systemctl reload apache2${NC}"
fi

# ----------------------------------------------------------------
# PHP-FPM Pool
# ----------------------------------------------------------------
section "PHP-FPM pool"
POOL_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
POOL_REMOVED_VER=""
# No `break` in either loop. The same pool name can exist under more than one
# PHP version — a version switch that half-completed leaves both — and stopping
# at the first hit left the others behind for the orphan audit to find later.
for PHP_VER in 8.5 8.4 8.3 8.2 8.1 8.0 7.4 7.3 7.2 7.1 7.0 5.6; do
    for POOL_CONF in "/etc/php/${PHP_VER}/fpm/pool.d/${POOL_NAME}.conf" \
                     "/etc/php/${PHP_VER}/fpm/pool.d/${DOMAIN}.conf"; do
        [ -f "$POOL_CONF" ] || continue
        rm -f "$POOL_CONF"
        if [ -e "$POOL_CONF" ]; then
            step_fail "pool still present: ${POOL_CONF}"
        else
            step_ok "removed pool ${POOL_CONF}"
            POOL_REMOVED_VER="$PHP_VER"
        fi
    done
done
[ -z "$POOL_REMOVED_VER" ] && step_ok "no FPM pool for this domain"
# NOTE: Do NOT reload PHP-FPM here — it kills the panel's own FPM worker
# that is waiting for this script to finish. FPM reload is handled by
# the API after fastcgi_finish_request() sends the response first.

# ----------------------------------------------------------------
# SSL Certificate
# ----------------------------------------------------------------
section "Certificate"
timeout 60 bash "$SCRIPTS_DIR/ssl_manage.sh" revoke "$DOMAIN" >/dev/null 2>&1
if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
    step_warn "certificate directory still present: /etc/letsencrypt/live/${DOMAIN}"
else
    step_ok "certificate removed"
fi

# ----------------------------------------------------------------
# MariaDB — this domain's database only
# ----------------------------------------------------------------
section "Database"
# EXACT name match. This was `SHOW DATABASES LIKE '${DB_NAME}%'`, which is two
# separate hazards: in MySQL LIKE, '_' is a single-character wildcard, and the
# trailing '%' matched anything sharing the prefix. Removing tuxxin.com would
# therefore have dropped tuxxin_tuxxin_com_dev, _backup, and so on.
#
# Databases the user created through the portal are deliberately NOT dropped
# here — they are not this domain's to remove. delete_user.sh sweeps those when
# the whole account goes.
DB_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
if ! mysql_available; then
    step_fail "MariaDB unreachable — ${DB_NAME} NOT removed"
else
    EXISTS=$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}'" 2>/dev/null)
    if [ "${EXISTS:-0}" = "0" ]; then
        step_ok "no database named ${DB_NAME}"
    else
        mysql_root -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" 2>/dev/null
        GONE=$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}'" 2>/dev/null)
        [ "${GONE:-1}" = "0" ] && step_ok "dropped database ${DB_NAME}" \
                               || step_fail "database ${DB_NAME} still present"
    fi
fi

# ----------------------------------------------------------------
# Domain Directory
# ----------------------------------------------------------------
section "Files"
[ -d "/home/$USERNAME/$DOMAIN" ] && rm -rf "/home/${USERNAME:?}/${DOMAIN:?}" 2>/dev/null
if [ -d "/home/$USERNAME/$DOMAIN" ]; then
    step_fail "/home/${USERNAME}/${DOMAIN} still present"
else
    step_ok "removed /home/${USERNAME}/${DOMAIN}"
fi

# ----------------------------------------------------------------
echo ""
if [ "$FAILED" -gt 0 ]; then
    echo -e "${RED}${FAILED} step(s) failed — '${DOMAIN}' is NOT fully removed.${NC}"
    echo -e "${YELLOW}Panel records are left in place so the domain stays visible for a retry.${NC}"
    echo -e "${YELLOW}Re-run:  inetp remove_domain --username ${USERNAME} --domain ${DOMAIN}${NC}"
    log_to_panel "ERROR" "Domain removal incomplete for ${DOMAIN}" "${FAILED} step(s) failed"
    exit 1
fi
[ "$WARNED" -gt 0 ] && echo -e "${YELLOW}${WARNED} warning(s) — review the output above.${NC}"
echo -e "${GREEN}Domain '${DOMAIN}' removed from user '${USERNAME}'.${NC}"
exit 0
