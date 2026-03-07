#!/bin/bash
# ==============================================================================
# create_account.sh — Creates a hosted account
#   - Linux user (primary group: www-data for correct FTP file ownership)
#   - PHP-FPM pool (per-user isolation with error log + open_basedir)
#   - Apache vhost (IP:port, proxied to per-user FPM socket)
#   - MariaDB user + database
#   - vsftpd whitelist entry
#   - CSF port rule (if CSF is installed)
# Usage (interactive):     inetp create_account
# Usage (non-interactive): inetp create_account --domain example.com --password secret
# ==============================================================================
CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
BASE_PORT=1080
PHP_VER=""
SCRIPTS_DIR="/root/scripts"
WELCOME_TEMPLATE="$SCRIPTS_DIR/welcome-default.php"
DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
SERVER_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
[ -z "$SERVER_IP" ] && SERVER_IP=$(hostname -I | awk '{print $1}')

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse non-interactive flags ---
NON_INTERACTIVE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)       DOMAIN="$2";   shift 2 ;;
        --password)     PASSWORD="$2"; shift 2 ;;
        --php-version)  PHP_VER="$2";  shift 2 ;;
        *) shift ;;
    esac
done
[ -n "$DOMAIN" ] && [ -n "$PASSWORD" ] && NON_INTERACTIVE=1
# Default PHP version to the highest installed FPM if not specified
if [ -z "$PHP_VER" ]; then
    for v in 8.5 8.4 8.3 8.2 8.1 8.0 7.4 7.3 7.2 7.1 7.0 5.6; do
        [ -f "/usr/sbin/php-fpm${v}" ] && PHP_VER="$v" && break
    done
    PHP_VER="${PHP_VER:-8.4}"
fi

echo -e "${BOLD}--- Create New Account ---${NC}"

if [ "$NON_INTERACTIVE" -eq 0 ]; then
    read -p "Enter Domain Name (e.g. example.com): " DOMAIN
fi
[ -z "$DOMAIN" ] && { echo -e "${RED}Domain cannot be empty.${NC}"; exit 1; }
id "$DOMAIN" &>/dev/null && { echo -e "${RED}Account '$DOMAIN' already exists.${NC}"; exit 1; }

if [ "$NON_INTERACTIVE" -eq 0 ]; then
    read -s -p "Enter FTP/SSH Password: " PASSWORD
    echo ""
fi
[ -z "$PASSWORD" ] && { echo -e "${RED}Password cannot be empty.${NC}"; exit 1; }

# Find next available port
LAST_PORT=$(grep "^Listen" "$CUSTOM_PORTS_CONF" 2>/dev/null | awk '{print $2}' | sort -n | tail -1)
PORT=${LAST_PORT:+$((LAST_PORT + 1))}
PORT=${PORT:-$BASE_PORT}
echo -e "${YELLOW}Assigning port: $PORT${NC}"

# ----------------------------------------------------------------
# Linux User
# Primary group www-data: FTP-uploaded files will be group www-data
# with local_umask=022 set in vsftpd (files=644, dirs=755)
# ----------------------------------------------------------------
useradd -m -d "/home/$DOMAIN" -s /bin/bash -g www-data "$DOMAIN"
echo "$DOMAIN:$PASSWORD" | chpasswd

# ll alias for the new user
printf "\n# Custom Aliases\nalias ll='ls -alh'\n" >> "/home/$DOMAIN/.bashrc"
chown "$DOMAIN:www-data" "/home/$DOMAIN/.bashrc"

# ----------------------------------------------------------------
# Directory Structure
# ----------------------------------------------------------------
DOC_ROOT="/home/$DOMAIN/www"
LOG_DIR="/home/$DOMAIN/logs"
TMP_DIR="/home/$DOMAIN/tmp"
mkdir -p "$DOC_ROOT" "$LOG_DIR" "$TMP_DIR"

chown -R "$DOMAIN:www-data" "/home/$DOMAIN"
chmod 750 "/home/$DOMAIN"
chmod 755 "$DOC_ROOT"
chmod 750 "$LOG_DIR" "$TMP_DIR"

# ----------------------------------------------------------------
# PHP-FPM Pool (per-user socket, error log, upload tmp, open_basedir)
# ----------------------------------------------------------------
FPM_SOCK="/run/php/php${PHP_VER}-fpm-${DOMAIN}.sock"
POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/${DOMAIN}.conf"

cat << POOL > "$POOL_CONF"
[${DOMAIN}]
user  = ${DOMAIN}
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

php_admin_value[error_log]      = ${LOG_DIR}/php_error.log
php_admin_flag[log_errors]      = on
php_admin_value[upload_tmp_dir] = ${TMP_DIR}
php_value[session.save_path]    = ${TMP_DIR}
php_admin_value[open_basedir]   = /home/${DOMAIN}/:/tmp/
POOL

# PHP-FPM reload is deferred — the caller (panel API) sends the HTTP response
# first via fastcgi_finish_request(), then reloads FPM so the new pool's socket
# is created without killing the in-flight API request.

# ----------------------------------------------------------------
# Apache VHost
# ----------------------------------------------------------------
VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"

cat << VHOST > "$VHOST_CONF"
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@${DOMAIN}
    ServerName  ${DOMAIN}
    DocumentRoot ${DOC_ROOT}

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:${FPM_SOCK}|fcgi://localhost"
    </FilesMatch>

    <Directory ${DOC_ROOT}>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  ${LOG_DIR}/apache_error.log
    CustomLog ${LOG_DIR}/apache_access.log combined
</VirtualHost>
VHOST

echo "Listen $PORT" >> "$CUSTOM_PORTS_CONF"
a2ensite "${DOMAIN}.conf" > /dev/null 2>&1
systemctl reload apache2

# ----------------------------------------------------------------
# MariaDB — sanitize domain for DB name (dots/hyphens → underscores)
# ----------------------------------------------------------------
DB_NAME=$(echo "$DOMAIN" | tr '.-' '_')

mysql -u root -p"$DB_ROOT_PASS" << MYSQL
CREATE USER IF NOT EXISTS '${DOMAIN}'@'localhost' IDENTIFIED BY '${PASSWORD}';
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.*   TO '${DOMAIN}'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`${DB_NAME}_%\`.* TO '${DOMAIN}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
MYSQL

# ----------------------------------------------------------------
# vsftpd Whitelist
# ----------------------------------------------------------------
echo "$DOMAIN" >> /etc/vsftpd.userlist
systemctl reload vsftpd 2>/dev/null || systemctl restart vsftpd

# ----------------------------------------------------------------
# Welcome index page
# ----------------------------------------------------------------
cp "$WELCOME_TEMPLATE" "$DOC_ROOT/index.php"
sed -i "s/{{DOMAIN}}/$DOMAIN/g"     "$DOC_ROOT/index.php"
sed -i "s/{{PORT}}/$PORT/g"         "$DOC_ROOT/index.php"
sed -i "s|{{DOC_ROOT}}|$DOC_ROOT|g" "$DOC_ROOT/index.php"
chown "$DOMAIN:www-data" "$DOC_ROOT/index.php"
chmod 644 "$DOC_ROOT/index.php"

# ----------------------------------------------------------------
# CSF: Open port (if CSF is installed)
# ----------------------------------------------------------------
if command -v csf &>/dev/null; then
    CURRENT_TCP=$(grep '^TCP_IN' /etc/csf/csf.conf | cut -d'"' -f2)
    if ! echo "$CURRENT_TCP" | grep -qw "$PORT"; then
        sed -i "s|^TCP_IN = \".*\"|TCP_IN = \"${CURRENT_TCP},${PORT}\"|" /etc/csf/csf.conf
        csf -r > /dev/null 2>&1
        echo -e "${GREEN}CSF: Port $PORT opened.${NC}"
    fi
fi

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Account Created!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  Domain:   ${BOLD}$DOMAIN${NC}"
echo -e "  URL:      ${BOLD}http://$SERVER_IP:$PORT${NC}"
echo -e "  Web Root: ${BOLD}$DOC_ROOT${NC}"
echo -e "  DB Name:  ${BOLD}$DB_NAME${NC}"
echo -e "  DB User:  ${BOLD}$DOMAIN${NC}"
echo -e "  Logs:     ${BOLD}$LOG_DIR${NC}"
