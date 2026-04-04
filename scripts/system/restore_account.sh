#!/bin/bash
# ==============================================================================
# restore_account.sh — Restores a hosting account from a backup archive
#   - Creates Linux user + MariaDB user
#   - Extracts home directory (handles username mismatch)
#   - Imports SQL dumps
#   - Creates PHP-FPM pool, Apache vhost, SSL cert per domain
#   - Registers ports, reloads services
#
# Usage: restore_account.sh \
#          --backup-file /backup/restore_staging/user_2025-01-01.tgz \
#          --username <user> --password <pass> \
#          --domains domain1.com,domain2.com \
#          --ports 1085,1086 \
#          [--php-version 8.4] [--import-db] [--no-cf]
# ==============================================================================

CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
SCRIPTS_DIR="/root/scripts"
if [ -f /root/.mysql_root_pass ]; then
    DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
else
    DB_ROOT_PASS=""
fi

# --- Parse flags ---
BACKUP_FILE=""
USERNAME=""
PASSWORD=""
DOMAINS=""
PORTS=""
PHP_VER=""
IMPORT_DB=0
NO_CF=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --backup-file) BACKUP_FILE="$2"; shift 2 ;;
        --username)    USERNAME="$2";    shift 2 ;;
        --password)    PASSWORD="$2";    shift 2 ;;
        --domains)     DOMAINS="$2";     shift 2 ;;
        --ports)       PORTS="$2";       shift 2 ;;
        --php-version) PHP_VER="$2";     shift 2 ;;
        --import-db)   IMPORT_DB=1;      shift ;;
        --no-cf)       NO_CF=1;          shift ;;
        *) shift ;;
    esac
done

# Validate required args
[ -z "$BACKUP_FILE" ] && { echo '{"success":false,"error":"--backup-file required"}'; exit 1; }
[ -f "$BACKUP_FILE" ] || { echo '{"success":false,"error":"Backup file not found"}'; exit 1; }
[ -z "$USERNAME" ]    && { echo '{"success":false,"error":"--username required"}'; exit 1; }
[ -z "$PASSWORD" ]    && { echo '{"success":false,"error":"--password required"}'; exit 1; }
[ -z "$DOMAINS" ]     && { echo '{"success":false,"error":"--domains required"}'; exit 1; }
[ -z "$PORTS" ]       && { echo '{"success":false,"error":"--ports required"}'; exit 1; }

# Default PHP version
if [ -z "$PHP_VER" ]; then
    for v in 8.5 8.4 8.3 8.2 8.1 8.0 7.4 5.6; do
        [ -f "/usr/sbin/php-fpm${v}" ] && PHP_VER="$v" && break
    done
    PHP_VER="${PHP_VER:-8.4}"
fi

# Split domains and ports into arrays
IFS=',' read -ra DOMAIN_ARR <<< "$DOMAINS"
IFS=',' read -ra PORT_ARR   <<< "$PORTS"

if [ "${#DOMAIN_ARR[@]}" -ne "${#PORT_ARR[@]}" ]; then
    echo '{"success":false,"error":"Domain and port count mismatch"}'
    exit 1
fi

# Track FPM versions to reload
declare -A FPM_RELOAD

# ── Step 1: Create Linux User ────────────────────────────────────────────────
if id "$USERNAME" &>/dev/null; then
    echo "STAGE:user_exists"
    echo "$USERNAME:$PASSWORD" | chpasswd
else
    echo "STAGE:creating_user"
    useradd -m -d "/home/$USERNAME" -s /bin/bash -g www-data "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    printf "\n# Custom Aliases\nalias ll='ls -alh'\n" >> "/home/$USERNAME/.bashrc"
    chown "$USERNAME:www-data" "/home/$USERNAME/.bashrc"
    chmod 750 "/home/$USERNAME"
fi

# ── Step 2: Extract Home Directory ───────────────────────────────────────────
echo "STAGE:extracting_files"
TMP_EXTRACT=$(mktemp -d)

# Detect the original username from the archive
ARCHIVE_USER=$(tar -tzf "$BACKUP_FILE" 2>/dev/null | grep -oP '^home/\K[^/]+' | head -1)

if [ -z "$ARCHIVE_USER" ]; then
    echo '{"success":false,"error":"Cannot detect username from archive — no home/ directory found"}'
    rm -rf "$TMP_EXTRACT"
    exit 1
fi

# Extract home directory to temp location
tar -xzf "$BACKUP_FILE" -C "$TMP_EXTRACT" "home/$ARCHIVE_USER/" 2>/dev/null

if [ "$ARCHIVE_USER" = "$USERNAME" ]; then
    # Same username — move directly
    rsync -a "$TMP_EXTRACT/home/$ARCHIVE_USER/" "/home/$USERNAME/"
else
    # Username mismatch — move contents to new home
    rsync -a "$TMP_EXTRACT/home/$ARCHIVE_USER/" "/home/$USERNAME/"
fi

# Fix ownership and permissions
chown -R "$USERNAME:www-data" "/home/$USERNAME/"
chmod 750 "/home/$USERNAME"
mkdir -p "/home/$USERNAME/tmp"
chown "$USERNAME:www-data" "/home/$USERNAME/tmp"
chmod 750 "/home/$USERNAME/tmp"

rm -rf "$TMP_EXTRACT"

# ── Step 3: MariaDB User ────────────────────────────────────────────────────
echo "STAGE:creating_db_user"
SAFE_PASS="${PASSWORD//\\/\\\\}"
SAFE_PASS="${SAFE_PASS//\'/\'\'}"
mysql -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} <<MYSQL 2>/dev/null
CREATE USER IF NOT EXISTS '${USERNAME}'@'localhost' IDENTIFIED BY '${SAFE_PASS}';
ALTER USER '${USERNAME}'@'localhost' IDENTIFIED BY '${SAFE_PASS}';
GRANT ALL PRIVILEGES ON \`${USERNAME}_%\`.* TO '${USERNAME}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
MYSQL

# ── Step 4: Import SQL Dumps ────────────────────────────────────────────────
IMPORTED_DBS=""
if [ "$IMPORT_DB" -eq 1 ]; then
    echo "STAGE:importing_databases"
    TMP_SQL=$(mktemp -d)

    # Extract SQL files from tar root (not inside home/)
    SQL_FILES=$(tar -tzf "$BACKUP_FILE" 2>/dev/null | grep -E '^\./[^/]+\.sql$|^[^/]+\.sql$')

    for SQL_ENTRY in $SQL_FILES; do
        tar -xzf "$BACKUP_FILE" -C "$TMP_SQL" "$SQL_ENTRY" 2>/dev/null
        SQL_FILE="$TMP_SQL/$SQL_ENTRY"
        [ -f "$SQL_FILE" ] || continue

        DB_NAME=$(basename "$SQL_FILE" .sql)

        # Create database and import
        mysql -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} \
            -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`" 2>/dev/null
        mysql -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} "$DB_NAME" < "$SQL_FILE" 2>/dev/null

        if [ $? -eq 0 ]; then
            echo "  Imported: $DB_NAME"
            IMPORTED_DBS="${IMPORTED_DBS:+$IMPORTED_DBS,}$DB_NAME"
        else
            echo "  WARN: Failed to import $DB_NAME"
        fi
    done
    rm -rf "$TMP_SQL"
fi

# ── Step 5: Create FPM Pool + Vhost + SSL per Domain ────────────────────────
echo "STAGE:configuring_services"
CONFIGURED_DOMAINS=""

for i in "${!DOMAIN_ARR[@]}"; do
    DOMAIN="${DOMAIN_ARR[$i]}"
    PORT="${PORT_ARR[$i]}"

    DOC_ROOT="/home/$USERNAME/$DOMAIN/www"
    LOG_DIR="/home/$USERNAME/$DOMAIN/logs"
    TMP_DIR="/home/$USERNAME/tmp"

    # Ensure directories exist
    mkdir -p "$DOC_ROOT" "$LOG_DIR"
    chown -R "$USERNAME:www-data" "/home/$USERNAME/$DOMAIN"
    chmod 750 "/home/$USERNAME/$DOMAIN"
    chmod 755 "$DOC_ROOT"
    chmod 750 "$LOG_DIR"

    # ── PHP-FPM Pool ──
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
    FPM_RELOAD["$PHP_VER"]=1

    # ── SSL Certificate ──
    if [ "$NO_CF" -eq 1 ]; then
        bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$DOMAIN" 2>/dev/null
    else
        bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$DOMAIN" --self-signed 2>/dev/null
    fi
    SSL_CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    SSL_KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"

    # ── Apache VHost ──
    # Remove old vhost if exists
    if [ -f "/etc/apache2/sites-available/${DOMAIN}.conf" ]; then
        OLD_PORT=$(grep '<VirtualHost' "/etc/apache2/sites-available/${DOMAIN}.conf" | grep -oP '(?<=:)\d+')
        a2dissite "${DOMAIN}.conf" > /dev/null 2>&1
        rm -f "/etc/apache2/sites-available/${DOMAIN}.conf"
        [ -n "$OLD_PORT" ] && sed -i "/^Listen ${OLD_PORT}$/d" "$CUSTOM_PORTS_CONF"
    fi

    VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"
    cat << VHOST > "$VHOST_CONF"
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@${DOMAIN}
    ServerName  ${DOMAIN}
    DocumentRoot ${DOC_ROOT}

    SSLEngine on
    SSLCertificateFile    ${SSL_CERT}
    SSLCertificateKeyFile ${SSL_KEY}

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

    # Remove any existing Listen for this port, then add it
    sed -i "/^Listen ${PORT}$/d" "$CUSTOM_PORTS_CONF" 2>/dev/null
    echo "Listen $PORT" >> "$CUSTOM_PORTS_CONF"
    a2ensite "${DOMAIN}.conf" > /dev/null 2>&1

    CONFIGURED_DOMAINS="${CONFIGURED_DOMAINS:+$CONFIGURED_DOMAINS,}$DOMAIN"
    echo "  Domain ready: $DOMAIN (port $PORT)"
done

# ── Step 6: vsftpd Whitelist ─────────────────────────────────────────────────
if ! grep -qx "$USERNAME" /etc/vsftpd.userlist 2>/dev/null; then
    echo "$USERNAME" >> /etc/vsftpd.userlist
fi

# ── Step 7: Reload Services ──────────────────────────────────────────────────
# NOTE: Do NOT reload PHP-FPM here — it kills the panel's own FPM worker
# that is waiting for this script to finish. FPM reload is handled by
# the API after fastcgi_finish_request().
echo "STAGE:reloading_services"
systemctl reload apache2 2>/dev/null
systemctl reload vsftpd 2>/dev/null || systemctl restart vsftpd 2>/dev/null

# ── Done — output JSON summary ───────────────────────────────────────────────
echo "STAGE:complete"
cat <<EOF
RESTORE_RESULT:{"success":true,"username":"$USERNAME","domains":"$CONFIGURED_DOMAINS","ports":"$PORTS","databases":"$IMPORTED_DBS","php_version":"$PHP_VER"}
EOF
