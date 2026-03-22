#!/bin/bash
# ==============================================================================
# rebuild_pools.sh — Regenerate PHP-FPM pool configs for all domains
# Usage: inetp rebuild_pools
#
# Reads all domains from the panel database and creates any missing
# FPM pool configs. Existing pools are left untouched.
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
DEFAULT_PHP=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='php_default_version'" 2>/dev/null)
DEFAULT_PHP=${DEFAULT_PHP:-8.4}

echo -e "\033[1mRebuilding PHP-FPM Pools\033[0m"
echo ""

CREATED=0
SKIPPED=0
ERRORS=0

sqlite3 "$PANEL_DB" "SELECT d.domain_name, h.username, COALESCE(NULLIF(d.php_version,'inherit'), '$DEFAULT_PHP') FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id" 2>/dev/null | while IFS="|" read -r DOMAIN USERNAME PHP_VER; do
    POOL_NAME="${USERNAME}_$(echo "$DOMAIN" | tr '.-' '_')"
    FPM_SOCK="/run/php/php${PHP_VER}-fpm-${POOL_NAME}.sock"
    LOG_DIR="/home/${USERNAME}/${DOMAIN}/logs"
    TMP_DIR="/home/${USERNAME}/tmp"
    POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/${POOL_NAME}.conf"

    if [ -f "$POOL_CONF" ]; then
        echo "  SKIP  ${DOMAIN} (${PHP_VER}) — pool exists"
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    # Verify PHP version is installed
    if [ ! -d "/etc/php/${PHP_VER}/fpm/pool.d" ]; then
        echo "  ERROR ${DOMAIN} — PHP ${PHP_VER} not installed"
        ERRORS=$((ERRORS + 1))
        continue
    fi

    mkdir -p "$LOG_DIR" "$TMP_DIR"
    chown "${USERNAME}:www-data" "$LOG_DIR" "$TMP_DIR" 2>/dev/null

    cat > "$POOL_CONF" << POOL
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

    echo "  CREATED ${DOMAIN} → ${POOL_CONF}"
    CREATED=$((CREATED + 1))
done

echo ""

# Reload all active PHP-FPM services
for fpm in /run/php/php*-fpm.sock; do
    ver=$(echo "$fpm" | grep -oP 'php\K[\d.]+')
    if [ -n "$ver" ]; then
        systemctl reload "php${ver}-fpm" 2>/dev/null && echo "  Reloaded php${ver}-fpm"
    fi
done

# Also reload based on pool.d directories
for pooldir in /etc/php/*/fpm/pool.d; do
    ver=$(echo "$pooldir" | grep -oP '/etc/php/\K[\d.]+')
    if systemctl is-active "php${ver}-fpm" >/dev/null 2>&1; then
        systemctl reload "php${ver}-fpm" 2>/dev/null
    fi
done

systemctl reload apache2 2>/dev/null && echo "  Reloaded apache2"

echo ""
echo "Done."
