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

CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
if [ -f /root/.mysql_root_pass ]; then
    DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
else
    DB_ROOT_PASS=""
fi
SCRIPTS_DIR="/root/scripts"

BOLD='\033[1m'; RED='\033[1;31m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

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

[ -z "$USERNAME" ] && { echo -e "${RED}Username required (--username).${NC}"; exit 1; }
[ -z "$DOMAIN" ]   && { echo -e "${RED}Domain required (--domain).${NC}"; exit 1; }

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
VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"
if [ -f "$VHOST_CONF" ]; then
    PORT=$(grep '<VirtualHost' "$VHOST_CONF" | grep -oE ':[0-9]+' | tr -d ':')
    a2dissite "${DOMAIN}.conf" > /dev/null 2>&1
    rm -f "$VHOST_CONF"
    if [ -n "$PORT" ]; then
        sed -i "/^Listen ${PORT}$/d" "$CUSTOM_PORTS_CONF"
    fi
    systemctl reload apache2 || systemctl restart apache2
fi

# ----------------------------------------------------------------
# PHP-FPM Pool
# ----------------------------------------------------------------
POOL_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
POOL_REMOVED_VER=""
for PHP_VER in 8.5 8.4 8.3 8.2 8.1 8.0 7.4 7.3 7.2 7.1 7.0 5.6; do
    POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/${POOL_NAME}.conf"
    if [ -f "$POOL_CONF" ]; then
        rm -f "$POOL_CONF"
        POOL_REMOVED_VER="$PHP_VER"
        break
    fi
done
# Also check for legacy single-domain pool naming
for PHP_VER in 8.5 8.4 8.3 8.2 8.1 8.0 7.4 7.3 7.2 7.1 7.0 5.6; do
    POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/${DOMAIN}.conf"
    if [ -f "$POOL_CONF" ]; then
        rm -f "$POOL_CONF"
        POOL_REMOVED_VER="$PHP_VER"
        break
    fi
done
# NOTE: Do NOT reload PHP-FPM here — it kills the panel's own FPM worker
# that is waiting for this script to finish. FPM reload is handled by
# the API after fastcgi_finish_request() sends the response first.

# ----------------------------------------------------------------
# SSL Certificate
# ----------------------------------------------------------------
timeout 60 bash "$SCRIPTS_DIR/ssl_manage.sh" revoke "$DOMAIN" 2>/dev/null || echo -e "${YELLOW}SSL revoke timed out, continuing.${NC}"

# ----------------------------------------------------------------
# MariaDB — drop domain database (keep the user for other domains)
# ----------------------------------------------------------------
DB_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
DBS=$(mysql -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} -N -e "SHOW DATABASES LIKE '${DB_NAME}%'" 2>/dev/null)
for DB in $DBS; do
    mysql -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} -e "DROP DATABASE \`$DB\`;" 2>/dev/null
    echo -e "  Dropped DB: ${YELLOW}$DB${NC}"
done

# ----------------------------------------------------------------
# Domain Directory
# ----------------------------------------------------------------
if [ -d "/home/$USERNAME/$DOMAIN" ]; then
    rm -rf "/home/$USERNAME/$DOMAIN"
    echo -e "  Removed: /home/$USERNAME/$DOMAIN"
fi

echo ""
echo -e "${GREEN}Domain '${DOMAIN}' removed from user '${USERNAME}'.${NC}"
