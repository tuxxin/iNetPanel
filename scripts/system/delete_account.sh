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

# Verify user exists
if ! id "$USERNAME" &>/dev/null; then
    echo -e "${RED}System user '${USERNAME}' does not exist.${NC}"
    exit 1
fi

# Find all domains for this user
DOMAINS=()
if [ -d "/home/$USERNAME" ]; then
    for dir in /home/$USERNAME/*/www; do
        [ -d "$dir" ] || continue
        D=$(basename "$(dirname "$dir")")
        # Skip tmp and other non-domain dirs
        [ "$D" = "tmp" ] && continue
        DOMAINS+=("$D")
    done
fi

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
[[ "$CONFIRM" != "yes" ]] && { echo "Aborted."; exit 0; }

# Optional backup
if [ "$NO_BACKUP" -eq 0 ]; then
    if [ "$CONFIRM" = "yes" ] && [ -z "$DOMAIN" ]; then
        # Non-interactive with --confirm but no --no-backup
        read -p "Create a backup before deleting? (y/n): " DO_BACKUP 2>/dev/null || DO_BACKUP="n"
        [[ "$DO_BACKUP" != "y" ]] && NO_BACKUP=1
    fi
fi
if [ "$NO_BACKUP" -eq 0 ] && [ ${#DOMAINS[@]} -gt 0 ]; then
    echo -e "${YELLOW}Backing up ${USERNAME}...${NC}"
    bash "$SCRIPTS_DIR/backup_accounts.sh" --single "$USERNAME" 2>/dev/null
    echo -e "${GREEN}Backup complete.${NC}"
fi

# ----------------------------------------------------------------
# Remove each domain
# ----------------------------------------------------------------
for D in "${DOMAINS[@]}"; do
    echo -e "\n${BOLD}Removing domain: ${D}${NC}"
    bash "$SCRIPTS_DIR/remove_domain.sh" --username "$USERNAME" --domain "$D" --no-backup
done

# ----------------------------------------------------------------
# Delete the user
# ----------------------------------------------------------------
echo -e "\n${BOLD}Deleting user: ${USERNAME}${NC}"
bash "$SCRIPTS_DIR/delete_user.sh" --username "$USERNAME" --force

# ----------------------------------------------------------------
# Clean up panel DB if accessible
# ----------------------------------------------------------------
if [ -f "$PANEL_DB" ]; then
    sqlite3 "$PANEL_DB" "DELETE FROM wg_peers WHERE hosting_user = '${USERNAME}';" 2>/dev/null
    USER_ID=$(sqlite3 "$PANEL_DB" "SELECT id FROM hosting_users WHERE username = '${USERNAME}';" 2>/dev/null)
    if [ -n "$USER_ID" ]; then
        sqlite3 "$PANEL_DB" "DELETE FROM domains WHERE hosting_user_id = ${USER_ID};" 2>/dev/null
        sqlite3 "$PANEL_DB" "DELETE FROM account_ports WHERE domain_name IN (SELECT domain_name FROM domains WHERE hosting_user_id = ${USER_ID});" 2>/dev/null
        sqlite3 "$PANEL_DB" "DELETE FROM hosting_users WHERE id = ${USER_ID};" 2>/dev/null
    fi
fi

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Account Deleted!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  User:    ${BOLD}$USERNAME${NC}"
echo -e "  Domains: ${BOLD}${#DOMAINS[@]} removed${NC}"
