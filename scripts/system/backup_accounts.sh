#!/bin/bash
# ==============================================================================
# backup_accounts.sh — Backs up all hosted accounts (files + MariaDB exports)
#   - /backup/<username>_YYYY-MM-DD.tgz per hosting user (all domains at once)
#   - Exports all MariaDB databases matching the username prefix
#   - Retention policy: removes backups older than RETENTION_DAYS (default: 3)
#   - --single <username> mode: backs up one user (used by remove_domain.sh)
# Usage: inetp backup_accounts
#        backup_accounts.sh --single <username>
# ==============================================================================
BACKUP_DIR="/backup"
RETENTION_DAYS=3        # Change to adjust how many days of backups to keep
if [ -f /root/.mysql_root_pass ]; then
    DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
else
    DB_ROOT_PASS=""
fi
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
DATE=$(date +%Y-%m-%d)

# Disable colors when not running in a terminal (e.g. cron)
if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; RED='\033[1;31m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; YELLOW=''; RED=''; NC=''
fi

SINGLE_MODE=0
SINGLE_ACCOUNT=""
if [ "$1" = "--single" ] && [ -n "$2" ]; then
    SINGLE_MODE=1
    SINGLE_ACCOUNT="$2"
fi

mkdir -p "$BACKUP_DIR"

backup_user() {
    local USERNAME="$1"
    local BACKUP_FILE="${BACKUP_DIR}/${USERNAME}_${DATE}.tgz"
    local TMP_SQL
    TMP_SQL=$(mktemp -d)

    echo -e "  ${YELLOW}Backing up:${NC} $USERNAME"

    # Export all MariaDB databases matching this user's name prefix
    local DB_PREFIX
    DB_PREFIX=$(echo "$USERNAME" | tr '.-' '_')
    local DBS
    DBS=$(mysql -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} -N \
        -e "SHOW DATABASES LIKE '${DB_PREFIX}%'" 2>/dev/null)
    for DB in $DBS; do
        if mysqldump -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} --single-transaction "$DB" \
            > "${TMP_SQL}/${DB}.sql" 2>/dev/null; then
            echo -e "    Exported DB: $DB"
        else
            echo -e "    ${RED}Failed to export DB: $DB${NC}"
            rm -f "${TMP_SQL}/${DB}.sql"
        fi
    done

    # Archive: user's entire home directory + SQL dumps in a single tgz
    tar -czf "$BACKUP_FILE" \
        -C / "home/$USERNAME" \
        -C "$TMP_SQL" . \
        2>/dev/null

    rm -rf "$TMP_SQL"
    SIZE=$(du -sh "$BACKUP_FILE" 2>/dev/null | cut -f1)
    echo -e "    ${GREEN}Saved: $BACKUP_FILE ($SIZE)${NC}"
}

if [ "$SINGLE_MODE" -eq 1 ]; then
    [ -d "/home/$SINGLE_ACCOUNT" ] \
        || { echo -e "${RED}User home not found: $SINGLE_ACCOUNT${NC}"; exit 1; }
    backup_user "$SINGLE_ACCOUNT"
else
    echo -e "${BOLD}--- Account Backup ---${NC}"
    echo -e "  Date: $DATE  |  Retention: ${RETENTION_DAYS} days  |  Destination: $BACKUP_DIR"
    echo ""

    # System configuration backup
    SYS_BACKUP="$BACKUP_DIR/system_config_${DATE}.tgz"
    echo -e "  ${YELLOW}Backing up system configuration files...${NC}"
    tar -czf "$SYS_BACKUP" \
        /etc/apache2/ \
        /etc/php/ \
        /etc/mysql/ \
        /etc/lighttpd/ \
        /etc/fail2ban/ \
        /etc/wireguard/ \
        /etc/ssh/sshd_config \
        /etc/vsftpd.conf \
        /etc/vsftpd.userlist \
        /etc/cron.d/ \
        /var/www/inetpanel/db/inetpanel.db \
        2>/dev/null || true
    SYS_SIZE=$(du -sh "$SYS_BACKUP" 2>/dev/null | cut -f1)
    echo -e "    ${GREEN}Saved: $SYS_BACKUP ($SYS_SIZE)${NC}"
    echo ""

    COUNT=0
    # Collect usernames: from hosting_users table if available, else fall back to vhost scan
    USERS=""
    if command -v sqlite3 &>/dev/null && [ -f "$PANEL_DB" ]; then
        USERS=$(sqlite3 "$PANEL_DB" "SELECT username FROM hosting_users" 2>/dev/null)
    fi

    if [ -n "$USERS" ]; then
        # New multi-domain system: back up by hosting user
        while IFS= read -r USERNAME; do
            [ -z "$USERNAME" ] && continue
            [ -d "/home/$USERNAME" ] || continue
            backup_user "$USERNAME"
            COUNT=$((COUNT + 1))
        done <<< "$USERS"
    else
        # Legacy fallback: back up by domain (1 domain = 1 user)
        for user_home in /home/*/; do
            DOMAIN=$(basename "$user_home")
            if [ -f "/etc/apache2/sites-available/${DOMAIN}.conf" ]; then
                backup_user "$DOMAIN"
                COUNT=$((COUNT + 1))
            fi
        done
    fi

    echo ""
    echo -e "  Accounts backed up: ${GREEN}$COUNT${NC}"

    # Retention policy (not applied in --single mode)
    echo ""
    echo -e "${YELLOW}Applying ${RETENTION_DAYS}-day retention policy...${NC}"
    REMOVED=0
    while IFS= read -r -d '' old; do
        rm -f "$old"
        echo -e "  Removed: $(basename "$old")"
        REMOVED=$((REMOVED + 1))
    done < <(find "$BACKUP_DIR" -name "*.tgz" -mtime +"$RETENTION_DAYS" -print0)
    echo -e "  Removed $REMOVED old backup(s)."
fi

echo ""
echo -e "${GREEN}Backup complete.${NC}"
