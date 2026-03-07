#!/bin/bash
# ==============================================================================
# delete_account.sh — Fully removes a hosted account
#   - Optional backup before deletion
#   - Apache vhost + port entry
#   - PHP-FPM pool config
#   - Linux user + home directory
#   - MariaDB databases + user
#   - vsftpd whitelist entry
#   - CSF port rule (if CSF is installed)
# Usage (interactive):     inetp delete_account
# Usage (non-interactive): inetp delete_account --domain example.com --confirm [--no-backup]
# ==============================================================================
CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
PHP_VER="8.4"
SCRIPTS_DIR="/root/scripts"

BOLD='\033[1m'; RED='\033[1;31m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse non-interactive flags ---
NON_INTERACTIVE=0
NO_BACKUP=0
CONFIRM=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)    DOMAIN="$2";  shift 2 ;;
        --confirm)   CONFIRM="yes"; shift ;;
        --no-backup) NO_BACKUP=1;   shift ;;
        *) shift ;;
    esac
done
[ -n "$DOMAIN" ] && [ "$CONFIRM" = "yes" ] && NON_INTERACTIVE=1

echo -e "${BOLD}--- Delete Account ---${NC}"

if [ "$NON_INTERACTIVE" -eq 0 ]; then
    read -p "Enter Domain to delete: " DOMAIN
fi
[ -z "$DOMAIN" ] && exit 1

if ! id "$DOMAIN" &>/dev/null && [ ! -f "/etc/apache2/sites-available/${DOMAIN}.conf" ]; then
    echo -e "${RED}Account '$DOMAIN' not found.${NC}"; exit 1
fi

if [ "$NON_INTERACTIVE" -eq 0 ]; then
    echo -e "${YELLOW}WARNING: This permanently deletes all files and databases for '$DOMAIN'.${NC}"
    read -p "Type 'yes' to confirm: " CONFIRM
fi
[[ "$CONFIRM" != "yes" ]] && { echo "Aborted."; exit 0; }

# ----------------------------------------------------------------
# Optional backup before deletion
# ----------------------------------------------------------------
if [ "$NON_INTERACTIVE" -eq 0 ]; then
    read -p "Create a backup before deleting? (y/n): " DO_BACKUP
    [[ "$DO_BACKUP" == "y" ]] && NO_BACKUP=0 || NO_BACKUP=1
fi
if [ "$NO_BACKUP" -eq 0 ]; then
    echo -e "${YELLOW}Backing up $DOMAIN...${NC}"
    bash "$SCRIPTS_DIR/backup_accounts.sh" --single "$DOMAIN"
    echo -e "${GREEN}Backup complete. Proceeding with deletion.${NC}"
fi

# ----------------------------------------------------------------
# Apache VHost
# ----------------------------------------------------------------
VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"
if [ -f "$VHOST_CONF" ]; then
    PORT=$(grep '<VirtualHost' "$VHOST_CONF" | grep -oP '(?<=:)\d+')
    a2dissite "${DOMAIN}.conf" > /dev/null 2>&1
    rm -f "$VHOST_CONF"
    if [ -n "$PORT" ]; then
        sed -i "/^Listen ${PORT}$/d" "$CUSTOM_PORTS_CONF"
        # CSF: Close port
        if command -v csf &>/dev/null; then
            CURRENT=$(grep '^TCP_IN' /etc/csf/csf.conf | cut -d'"' -f2)
            UPDATED=$(echo "$CURRENT" | sed "s/,${PORT}//g; s/${PORT},//g; s/^${PORT}$//g")
            sed -i "s|^TCP_IN = \".*\"|TCP_IN = \"${UPDATED}\"|" /etc/csf/csf.conf
            csf -r > /dev/null 2>&1
            echo -e "${GREEN}CSF: Port $PORT closed.${NC}"
        fi
    fi
    systemctl reload apache2
fi

# ----------------------------------------------------------------
# PHP-FPM Pool
# ----------------------------------------------------------------
POOL_CONF="/etc/php/${PHP_VER}/fpm/pool.d/${DOMAIN}.conf"
if [ -f "$POOL_CONF" ]; then
    rm -f "$POOL_CONF"
    systemctl reload php${PHP_VER}-fpm
fi

# ----------------------------------------------------------------
# Linux User
# ----------------------------------------------------------------
if id "$DOMAIN" &>/dev/null; then
    killall -u "$DOMAIN" 2>/dev/null
    sleep 1
    userdel -r "$DOMAIN" 2>/dev/null
fi

# ----------------------------------------------------------------
# MariaDB
# ----------------------------------------------------------------
DB_NAME=$(echo "$DOMAIN" | tr '.-' '_')
DBS=$(mysql -u root -p"$DB_ROOT_PASS" -N -e "SHOW DATABASES LIKE '${DB_NAME}%'" 2>/dev/null)
mysql -u root -p"$DB_ROOT_PASS" << MYSQL 2>/dev/null
DROP USER IF EXISTS '${DOMAIN}'@'localhost';
FLUSH PRIVILEGES;
MYSQL
for DB in $DBS; do
    mysql -u root -p"$DB_ROOT_PASS" -e "DROP DATABASE \`$DB\`;" 2>/dev/null
    echo -e "  Dropped DB: ${YELLOW}$DB${NC}"
done

# ----------------------------------------------------------------
# vsftpd Whitelist
# ----------------------------------------------------------------
sed -i "/^${DOMAIN}$/d" /etc/vsftpd.userlist
systemctl reload vsftpd 2>/dev/null || systemctl restart vsftpd

echo ""
echo -e "${GREEN}Account '${DOMAIN}' fully deleted.${NC}"
