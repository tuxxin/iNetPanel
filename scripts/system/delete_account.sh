#!/bin/bash
# ==============================================================================
# delete_account.sh — Fully removes a hosting account (user + all domains)
#   - Removes all domains via remove_domain.sh
#   - Removes the hosting user via delete_user.sh
#   - Optional backup before deletion
#   - Cloudflare tunnel routes removed via panel API (not handled here)
# Usage (interactive):     inetp delete_account
# Usage (non-interactive): inetp delete_account --username <user> --confirm [--no-backup]
# Legacy compat:           inetp delete_account --domain <domain> --confirm [--no-backup]
# ==============================================================================
SCRIPTS_DIR="/root/scripts"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; RED='\033[1;31m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
USERNAME=""
DOMAIN=""
NO_BACKUP=0
CONFIRM=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)  USERNAME="$2"; shift 2 ;;
        --domain)    DOMAIN="$2";   shift 2 ;;
        --confirm)   CONFIRM="yes"; shift ;;
        --no-backup) NO_BACKUP=1;   shift ;;
        *) shift ;;
    esac
done

echo -e "${BOLD}--- Delete Account ---${NC}"

# If --domain given without --username, resolve the username
if [ -n "$DOMAIN" ] && [ -z "$USERNAME" ]; then
    # Try to find username from Apache vhost DocumentRoot
    VHOST="/etc/apache2/sites-available/${DOMAIN}.conf"
    if [ -f "$VHOST" ]; then
        DOC_ROOT=$(grep -oP 'DocumentRoot\s+\K\S+' "$VHOST" 2>/dev/null)
        if echo "$DOC_ROOT" | grep -qP '^/home/[^/]+/[^/]+/www'; then
            USERNAME=$(echo "$DOC_ROOT" | cut -d/ -f3)
        fi
    fi
    # Fallback: try panel DB
    if [ -z "$USERNAME" ] && [ -f "$PANEL_DB" ]; then
        USERNAME=$(sqlite3 "$PANEL_DB" "SELECT h.username FROM hosting_users h JOIN domains d ON d.hosting_user_id = h.id WHERE d.domain_name = '${DOMAIN}' LIMIT 1" 2>/dev/null)
    fi
    if [ -z "$USERNAME" ]; then
        echo -e "${RED}Could not resolve username for domain '${DOMAIN}'.${NC}"
        exit 1
    fi
fi

# Interactive mode: ask for username
if [ -z "$USERNAME" ]; then
    read -p "Enter username to delete: " USERNAME
fi
[ -z "$USERNAME" ] && { echo -e "${RED}No username provided.${NC}"; exit 1; }

# A missing system user used to be a hard exit here. That was exactly backwards:
# it is the state a partially-completed deletion leaves behind, so the guard made
# the panel structurally unable to clean up after its own failures. An account
# with no passwd entry can still have a panel row, databases, a MariaDB user, a
# crontab and an FTP entry — all of which still need removing.
if ! id "$USERNAME" &>/dev/null; then
    echo -e "${YELLOW}System user '${USERNAME}' does not exist — resuming cleanup of what remains.${NC}"
fi

# Find all domains for this user.
# The home directory alone is not authoritative — if it was already removed (a
# partial delete, a manual rm, or delete_user.sh --force) the glob finds nothing,
# remove_domain.sh never runs, and the Apache vhost survives pointing at a
# DocumentRoot and ErrorLog dir that no longer exist. Apache then refuses to
# start on the *next* restart, taking every site on the box down. So take the
# union of the filesystem, the panel DB, and the vhosts owned by this user.
DOMAINS=()
add_domain_once() {
    local d="$1"
    [ -z "$d" ] && return
    [ "$d" = "tmp" ] && return
    local existing
    for existing in "${DOMAINS[@]}"; do
        [ "$existing" = "$d" ] && return
    done
    DOMAINS+=("$d")
}

if [ -d "/home/$USERNAME" ]; then
    for dir in /home/$USERNAME/*/www; do
        [ -d "$dir" ] || continue
        add_domain_once "$(basename "$(dirname "$dir")")"
    done
fi

if [ -f "$PANEL_DB" ]; then
    while read -r D; do
        add_domain_once "$D"
    done < <(sqlite3 "$PANEL_DB" "SELECT d.domain_name FROM domains d JOIN hosting_users h ON d.hosting_user_id = h.id WHERE h.username = '${USERNAME}'" 2>/dev/null)
fi

for conf in /etc/apache2/sites-available/*.conf; do
    [ -f "$conf" ] || continue
    DOC_ROOT=$(grep -oP 'DocumentRoot\s+\K\S+' "$conf" 2>/dev/null | head -1)
    case "$DOC_ROOT" in
        /home/"$USERNAME"/*) add_domain_once "$(basename "$conf" .conf)" ;;
    esac
done

echo -e "  User:    ${BOLD}$USERNAME${NC}"
echo -e "  Domains: ${BOLD}${#DOMAINS[@]}${NC}"
if [ ${#DOMAINS[@]} -gt 0 ]; then
    for D in "${DOMAINS[@]}"; do
        echo -e "    - $D"
    done
fi

# Confirm
if [ "$CONFIRM" != "yes" ]; then
    echo ""
    echo -e "${YELLOW}WARNING: This permanently deletes user '${USERNAME}', all their domains, files, and databases.${NC}"
    read -p "Type 'yes' to confirm: " CONFIRM
fi
# Aborting is a failure to complete the requested operation, so exit non-zero.
# It used to exit 0, which a caller checking the status reads as success.
[[ "$CONFIRM" != "yes" ]] && { echo "Aborted."; exit 1; }

# The backup prompt used to run unguarded on the supposedly non-interactive
# --confirm path: with stdin an open pipe it blocked forever, and when the read
# failed DO_BACKUP was left unset, so NO_BACKUP became 1 and the account was
# deleted with no backup at all. Only ask when there is a terminal to ask, and
# default to taking the backup. --no-backup is now the only way to skip it.
if [ "$NO_BACKUP" -eq 0 ] && [ -t 0 ]; then
    read -r -p "Create a backup before deleting? (Y/n): " DO_BACKUP
    [[ "$DO_BACKUP" =~ ^[Nn]$ ]] && NO_BACKUP=1
fi
# The per-account backup is taken by delete_user.sh (--final), which writes to
# the retention-exempt /backup/deleted/ and refuses to destroy anything if it
# fails. Taking a second one here would just be a slower duplicate.

# ----------------------------------------------------------------
# Remove each domain
# ----------------------------------------------------------------
# Child exit codes were previously discarded, so a domain that failed to remove
# was indistinguishable from one that succeeded.
STEP_FAILURES=0
for D in "${DOMAINS[@]}"; do
    echo -e "\n${BOLD}Removing domain: ${D}${NC}"
    if ! bash "$SCRIPTS_DIR/remove_domain.sh" --username "$USERNAME" --domain "$D" --no-backup; then
        STEP_FAILURES=$((STEP_FAILURES + 1))
    fi
done

# ----------------------------------------------------------------
# Delete the user
# ----------------------------------------------------------------
# delete_user.sh now owns the whole user-level teardown — final backup,
# databases, MariaDB user, crontab, FTP entry, home, AND the panel rows. The
# block that used to live here deleted `domains` before the subquery that read
# from it, so account_ports rows were never actually removed on this path.
echo -e "\n${BOLD}Deleting user: ${USERNAME}${NC}"
DU_ARGS=(--username "$USERNAME" --force)
[ "$NO_BACKUP" -eq 1 ] && DU_ARGS+=(--no-backup)
if ! bash "$SCRIPTS_DIR/delete_user.sh" "${DU_ARGS[@]}"; then
    STEP_FAILURES=$((STEP_FAILURES + 1))
fi

echo ""
if [ "$STEP_FAILURES" -gt 0 ]; then
    echo -e "${RED}==============================${NC}"
    echo -e "${RED} Account NOT fully deleted${NC}"
    echo -e "${RED}==============================${NC}"
    echo -e "  User:     ${BOLD}$USERNAME${NC}"
    echo -e "  Failures: ${BOLD}${STEP_FAILURES}${NC}"
    echo -e "  ${YELLOW}Re-run this command to resume; it will finish what is left.${NC}"
    echo -e "  ${YELLOW}Detail:  inetp audit_orphans --user ${USERNAME}${NC}"
    exit 1
fi
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Account Deleted!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  User:    ${BOLD}$USERNAME${NC}"
echo -e "  Domains: ${BOLD}${#DOMAINS[@]} removed${NC}"
exit 0
