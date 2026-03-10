#!/bin/bash
# ==============================================================================
# add_domain.sh — Adds a domain to an existing hosting user
#   - Directory structure: /home/{username}/{domain}/www/ and logs/
#   - PHP-FPM pool (per user+domain)
#   - Apache SSL vhost
#   - MariaDB database
#   - SSL certificate (Let's Encrypt via Cloudflare DNS)
# Usage: add_domain.sh --username <user> --domain <domain> [--port <N>] [--php-version 8.4]
# ==============================================================================

CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
BASE_PORT=1080
SCRIPTS_DIR="/root/scripts"
WELCOME_TEMPLATE="$SCRIPTS_DIR/welcome-default.php"
DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
SERVER_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
[ -z "$SERVER_IP" ] && SERVER_IP=$(hostname -I | awk '{print $1}')

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
USERNAME=""
DOMAIN=""
PORT=""
PHP_VER=""
NO_CF=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)    USERNAME="$2";  shift 2 ;;
        --domain)      DOMAIN="$2";    shift 2 ;;
        --port)        PORT="$2";      shift 2 ;;
        --php-version) PHP_VER="$2";   shift 2 ;;
        --no-cf)       NO_CF=1;        shift ;;
        *) shift ;;
    esac
done

[ -z "$USERNAME" ] && { echo -e "${RED}Username required (--username).${NC}"; exit 1; }
[ -z "$DOMAIN" ]   && { echo -e "${RED}Domain required (--domain).${NC}"; exit 1; }

# Verify user exists
if ! id "$USERNAME" &>/dev/null; then
    echo -e "${RED}Hosting user '${USERNAME}' does not exist. Create it first with create_user.sh.${NC}"
    exit 1
fi

# Check domain not already configured
if [ -f "/etc/apache2/sites-available/${DOMAIN}.conf" ]; then
    echo -e "${RED}Domain '${DOMAIN}' already has a vhost configured.${NC}"
    exit 1
fi

# Default PHP version
if [ -z "$PHP_VER" ]; then
    for v in 8.5 8.4 8.3 8.2 8.1 8.0 7.4 7.3 7.2 7.1 7.0 5.6; do
        [ -f "/usr/sbin/php-fpm${v}" ] && PHP_VER="$v" && break
    done
    PHP_VER="${PHP_VER:-8.4}"
fi

# Auto-assign port if not provided (skip 1443 — reserved for WireGuard)
if [ -z "$PORT" ]; then
    LAST_PORT=$(grep "^Listen" "$CUSTOM_PORTS_CONF" 2>/dev/null | awk '{print $2}' | sort -n | tail -1)
    PORT=${LAST_PORT:+$((LAST_PORT + 1))}
    PORT=${PORT:-$BASE_PORT}
    [ "$PORT" -eq 1443 ] && PORT=1444
fi

echo -e "${BOLD}--- Adding Domain: ${DOMAIN} → ${USERNAME} (port ${PORT}) ---${NC}"

# ----------------------------------------------------------------
# Directory Structure
# ----------------------------------------------------------------
DOC_ROOT="/home/$USERNAME/$DOMAIN/www"
LOG_DIR="/home/$USERNAME/$DOMAIN/logs"
TMP_DIR="/home/$USERNAME/tmp"
mkdir -p "$DOC_ROOT" "$LOG_DIR" "$TMP_DIR"

chown -R "$USERNAME:www-data" "/home/$USERNAME/$DOMAIN"
chown "$USERNAME:www-data" "$TMP_DIR"
chmod 750 "/home/$USERNAME/$DOMAIN"
chmod 755 "$DOC_ROOT"
chmod 750 "$LOG_DIR" "$TMP_DIR"

# ----------------------------------------------------------------
# PHP-FPM Pool (per user+domain — socket named username_domain)
# ----------------------------------------------------------------
POOL_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
FPM_SOCK="/run/php/php${PHP_VER}-fpm-${POOL_NAME}.sock"
POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/${POOL_NAME}.conf"

cat << POOL > "$POOL_CONF"
[${POOL_NAME}]
user  = ${USERNAME}
group = www-data

listen       = ${FPM_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

pm                   = dynamic
pm.max_children      = 5
pm.start_servers     = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3

php_admin_value[error_log]           = ${LOG_DIR}/php_error.log
php_admin_flag[log_errors]           = on
php_admin_value[upload_tmp_dir]      = ${TMP_DIR}
php_value[session.save_path]         = ${TMP_DIR}
php_admin_value[open_basedir]        = /home/${USERNAME}/:/tmp/
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size]       = 100M
POOL

# Reload FPM so the new pool socket is created before Apache tries to use it
systemctl reload php${PHP_VER}-fpm
sleep 1

# ----------------------------------------------------------------
# SSL Certificate
# ----------------------------------------------------------------
if [ "$NO_CF" -eq 1 ]; then
    bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$DOMAIN" --self-signed
else
    bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$DOMAIN"
fi
SSL_CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
SSL_KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"

# ----------------------------------------------------------------
# Apache VHost (SSL-enabled)
# ----------------------------------------------------------------
VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"

cat << VHOST > "$VHOST_CONF"
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@${DOMAIN}
    ServerName  ${DOMAIN}
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

echo "Listen $PORT" >> "$CUSTOM_PORTS_CONF"
a2ensite "${DOMAIN}.conf" > /dev/null 2>&1
systemctl reload apache2

# ----------------------------------------------------------------
# MariaDB — database per domain, granted to hosting user
# ----------------------------------------------------------------
DB_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"

mysql -u root -p"$DB_ROOT_PASS" << MYSQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.*   TO '${USERNAME}'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`${DB_NAME}_%\`.* TO '${USERNAME}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
MYSQL

# ----------------------------------------------------------------
# Welcome index page
# ----------------------------------------------------------------
if [ -f "$WELCOME_TEMPLATE" ]; then
    cp "$WELCOME_TEMPLATE" "$DOC_ROOT/index.php"
    sed -i "s/{{DOMAIN}}/$DOMAIN/g"     "$DOC_ROOT/index.php"
    sed -i "s/{{PORT}}/$PORT/g"         "$DOC_ROOT/index.php"
    sed -i "s|{{DOC_ROOT}}|$DOC_ROOT|g" "$DOC_ROOT/index.php"
    chown "$USERNAME:www-data" "$DOC_ROOT/index.php"
    chmod 644 "$DOC_ROOT/index.php"
fi

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Domain Added!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  Domain:   ${BOLD}$DOMAIN${NC}"
echo -e "  User:     ${BOLD}$USERNAME${NC}"
echo -e "  URL:      ${BOLD}https://$SERVER_IP:$PORT${NC}"
echo -e "  Web Root: ${BOLD}$DOC_ROOT${NC}"
echo -e "  DB Name:  ${BOLD}$DB_NAME${NC}"
echo -e "  DB User:  ${BOLD}$USERNAME${NC}"
echo -e "  Logs:     ${BOLD}$LOG_DIR${NC}"
