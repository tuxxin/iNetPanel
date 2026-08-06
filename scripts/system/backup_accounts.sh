#!/bin/bash
# ==============================================================================
# backup_accounts.sh — Backs up all hosted accounts (files + MariaDB exports)
#   - /backup/<username>_YYYY-MM-DD.tgz per hosting user (all domains at once)
#   - Exports all MariaDB databases matching the username prefix
#   - Retention policy: removes backups older than RETENTION_DAYS (default: 3)
#   - --single <username> mode: backs up one user (used by remove_domain.sh)
# Usage: inetp backup_accounts
#        backup_accounts.sh --single <username>
#        backup_accounts.sh --final  <username>   (pre-deletion, retention-exempt)
# ==============================================================================

# Shared ownership rules — dbs_owned_by() decides which databases belong to an
# account. See lib_account.sh for why this must not be a LIKE query.
source /root/scripts/lib_account.sh 2>/dev/null || {
    echo "error: /root/scripts/lib_account.sh not found — refusing to guess database ownership." >&2
    exit 1
}

BACKUP_DIR="/backup"
FINAL_DIR="/backup/deleted"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
# Read retention from panel DB, fallback to 3
RETENTION_DAYS=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='backup_retention'" 2>/dev/null)
RETENTION_DAYS=${RETENTION_DAYS:-3}
if [ -f /root/.mysql_root_pass ]; then
    DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
else
    DB_ROOT_PASS=""
fi
DATE=$(date +%Y-%m-%d)

# Disable colors when not running in a terminal (e.g. cron)
if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; RED='\033[1;31m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; YELLOW=''; RED=''; NC=''
fi

SINGLE_MODE=0
FINAL_MODE=0
SINGLE_ACCOUNT=""
if [ "$1" = "--single" ] && [ -n "$2" ]; then
    SINGLE_MODE=1
    SINGLE_ACCOUNT="$2"
elif [ "$1" = "--final" ] && [ -n "$2" ]; then
    # Last-chance backup taken immediately before deletion. Goes to a directory
    # the retention sweep cannot reach, because this is the only copy that will
    # ever exist — two accounts were deleted and aged past the 3-day window
    # while orphaned, leaving no backup anywhere.
    SINGLE_MODE=1
    FINAL_MODE=1
    SINGLE_ACCOUNT="$2"
fi

# Check if backups are enabled (skip in --single mode which is used by domain removal)
if [ "$SINGLE_MODE" -eq 0 ]; then
    ENABLED=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='backup_enabled'" 2>/dev/null)
    if [ "$ENABLED" = "0" ]; then
        echo "Backups are disabled. Exiting."
        exit 0
    fi
fi

mkdir -p "$BACKUP_DIR"

backup_user() {
    local USERNAME="$1"
    local DEST_DIR="$BACKUP_DIR"
    [ "$FINAL_MODE" -eq 1 ] && DEST_DIR="$FINAL_DIR"
    mkdir -p "$DEST_DIR"
    local BACKUP_FILE="${DEST_DIR}/${USERNAME}_${DATE}.tgz"
    local TMP_SQL
    TMP_SQL=$(mktemp -d)

    echo -e "  ${YELLOW}Backing up:${NC} $USERNAME"

    # Databases owned by this account.
    #
    # This used to be `SHOW DATABASES LIKE '$(echo "$USERNAME" | tr '.-' '_')%'`,
    # which was two bugs cancelling out. Measured on a live box for user
    # `qr-track`, whose database is `qr-track_qr_tuxxin_net`:
    #
    #     LIKE 'qr_track%'   -> found it   (the mangled prefix, '_' as a wildcard)
    #     LIKE 'qr\_track%'  -> found NOTHING   <-- escaping alone silently breaks it
    #     LIKE 'qr-track\_%' -> found it
    #
    # The `tr` mangled the username and the unescaped `_` wildcard matched the
    # `-` back. Escaping the underscore without also dropping the `tr` would end
    # this account's database backups with no error. dbs_owned_by() does a
    # literal prefix comparison in shell instead — no LIKE, no wildcards.
    local DB DUMP_FAILED=0
    while IFS= read -r DB; do
        [ -z "$DB" ] && continue
        if mysqldump -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} --single-transaction "$DB" \
            > "${TMP_SQL}/${DB}.sql" 2>/dev/null; then
            echo -e "    Exported DB: $DB"
        else
            echo -e "    ${RED}Failed to export DB: $DB${NC}"
            rm -f "${TMP_SQL}/${DB}.sql"
            DUMP_FAILED=1
        fi
    done < <(dbs_owned_by "$USERNAME")

    # Archive: home directory (when it still exists) + SQL dumps.
    # In --final mode the home may already be gone — a resumed deletion can have
    # lost it while databases survive — and the databases are then the only
    # thing left worth saving, so still produce an archive.
    local TAR_RC=0
    if [ -d "/home/$USERNAME" ]; then
        tar -czf "$BACKUP_FILE" -C / "home/$USERNAME" -C "$TMP_SQL" . 2>/dev/null
        TAR_RC=$?
    elif [ "$FINAL_MODE" -eq 1 ]; then
        echo -e "    ${YELLOW}/home/$USERNAME is gone — archiving databases only${NC}"
        tar -czf "$BACKUP_FILE" -C "$TMP_SQL" . 2>/dev/null
        TAR_RC=$?
    else
        rm -rf "$TMP_SQL"
        return 1
    fi

    rm -rf "$TMP_SQL"

    # tar's exit status was previously discarded and "Saved:" printed regardless,
    # so a truncated archive looked identical to a good one — and then evicted
    # the good one under retention. 1 means "file changed as we read it", which
    # is routine on a live site; 2 and above is a real failure.
    if [ "$TAR_RC" -ge 2 ]; then
        echo -e "    ${RED}FAILED: tar exited ${TAR_RC} — removing partial archive${NC}"
        rm -f "$BACKUP_FILE"
        log_to_panel "ERROR" "Backup failed for ${USERNAME}" "tar exit ${TAR_RC}"
        return 1
    fi
    if [ ! -s "$BACKUP_FILE" ]; then
        echo -e "    ${RED}FAILED: no archive produced${NC}"
        rm -f "$BACKUP_FILE"
        log_to_panel "ERROR" "Backup produced no archive for ${USERNAME}" ""
        return 1
    fi

    local SIZE
    SIZE=$(du -sh "$BACKUP_FILE" 2>/dev/null | cut -f1)
    if [ "$TAR_RC" -eq 1 ]; then
        echo -e "    ${YELLOW}Saved with warnings: $BACKUP_FILE ($SIZE) — files changed during read${NC}"
    else
        echo -e "    ${GREEN}Saved: $BACKUP_FILE ($SIZE)${NC}"
    fi
    [ "$DUMP_FAILED" -eq 1 ] && return 1
    return 0
}

if [ "$SINGLE_MODE" -eq 1 ]; then
    # --final must proceed even with no home directory: it is the last-chance
    # backup before deletion, and a resumed deletion may already have removed
    # the home while databases survive. --single keeps the old guard.
    if [ "$FINAL_MODE" -eq 0 ] && [ ! -d "/home/$SINGLE_ACCOUNT" ]; then
        echo -e "${RED}User home not found: $SINGLE_ACCOUNT${NC}"
        exit 1
    fi
    backup_user "$SINGLE_ACCOUNT" || exit 1
    exit 0
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
    SKIPPED=0
    FAILED_COUNT=0
    # Collect usernames: from hosting_users table if available, else fall back to vhost scan
    USERS=""
    if command -v sqlite3 &>/dev/null && [ -f "$PANEL_DB" ]; then
        USERS=$(sqlite3 "$PANEL_DB" "SELECT username FROM hosting_users" 2>/dev/null)
    fi

    if [ -n "$USERS" ]; then
        # New multi-domain system: back up by hosting user
        while IFS= read -r USERNAME; do
            [ -z "$USERNAME" ] && continue
            # A missing home used to be a silent `|| continue`. That is how an
            # account stops being backed up while still looking healthy in the
            # UI: the panel row says it exists, so nothing complains, and by the
            # time anyone notices, retention has aged out the last good copy.
            # Two accounts were lost exactly this way.
            if [ ! -d "/home/$USERNAME" ]; then
                echo -e "  ${RED}SKIP${NC}  $USERNAME — hosting_users row exists but /home/$USERNAME does not."
                echo -e "        Nothing was backed up. Investigate: inetp audit_orphans --user $USERNAME"
                log_to_panel "ERROR" "Backup skipped for ${USERNAME}: /home/${USERNAME} missing" \
                             "panel row exists but the home directory is gone"
                SKIPPED=$((SKIPPED + 1))
                continue
            fi
            if backup_user "$USERNAME"; then
                COUNT=$((COUNT + 1))
            else
                FAILED_COUNT=$((FAILED_COUNT + 1))
            fi
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
    [ "$SKIPPED" -gt 0 ] && echo -e "  Accounts skipped:   ${RED}$SKIPPED${NC}  (see SKIP lines above)"
    [ "$FAILED_COUNT" -gt 0 ] && echo -e "  Backups failed:     ${RED}$FAILED_COUNT${NC}"

    # Retention policy (not applied in --single mode)
    echo ""
    echo -e "${YELLOW}Applying ${RETENTION_DAYS}-day retention policy...${NC}"
    REMOVED=0
    MTIME_DAYS=$((RETENTION_DAYS - 1))
    while IFS= read -r -d '' old; do
        rm -f "$old"
        echo -e "  Removed: $(basename "$old")"
        REMOVED=$((REMOVED + 1))
    # -maxdepth 1: without it this recurses into /backup/restore_staging/ (and
    # would sweep an archive mid-restore) and into /backup/deleted/, which holds
    # the only surviving copy of a deleted account and must never be swept.
    done < <(find "$BACKUP_DIR" -maxdepth 1 -name "*.tgz" -mtime +"$MTIME_DAYS" -print0)
    echo -e "  Removed $REMOVED old backup(s)."
fi

echo ""
echo -e "${GREEN}Backup complete.${NC}"
