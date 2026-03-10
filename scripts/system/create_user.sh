#!/bin/bash
# ==============================================================================
# create_user.sh — Creates a hosting user (Linux user + MariaDB user)
# This does NOT create any domains — use add_domain.sh for that.
# Usage: create_user.sh --username <name> --password <pass> [--shell /bin/bash]
# ==============================================================================

DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
USERNAME=""
PASSWORD=""
SHELL_PATH="/bin/bash"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        --password) PASSWORD="$2"; shift 2 ;;
        --shell)    SHELL_PATH="$2"; shift 2 ;;
        *) shift ;;
    esac
done

[ -z "$USERNAME" ] && { echo -e "${RED}Username is required (--username).${NC}"; exit 1; }
[ -z "$PASSWORD" ] && { echo -e "${RED}Password is required (--password).${NC}"; exit 1; }

# Validate username (alphanumeric + hyphens, max 32 chars, must start with letter)
if ! echo "$USERNAME" | grep -qP '^[a-z][a-z0-9\-]{0,31}$'; then
    echo -e "${RED}Invalid username. Must start with a letter, contain only lowercase letters, numbers, and hyphens, max 32 chars.${NC}"
    exit 1
fi

echo -e "${BOLD}--- Creating Hosting User: ${USERNAME} ---${NC}"

# ----------------------------------------------------------------
# Linux User
# Primary group www-data: FTP-uploaded files will be group www-data
# ----------------------------------------------------------------
if id "$USERNAME" &>/dev/null; then
    echo -e "${YELLOW}System user '${USERNAME}' already exists — updating password.${NC}"
    echo "$USERNAME:$PASSWORD" | chpasswd
else
    useradd -m -d "/home/$USERNAME" -s "$SHELL_PATH" -g www-data "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    printf "\n# Custom Aliases\nalias ll='ls -alh'\n" >> "/home/$USERNAME/.bashrc"
    chown "$USERNAME:www-data" "/home/$USERNAME/.bashrc"
    chmod 750 "/home/$USERNAME"
fi

# ----------------------------------------------------------------
# MariaDB User (shared across all domains for this hosting user)
# ----------------------------------------------------------------
mysql -u root -p"$DB_ROOT_PASS" << MYSQL
CREATE USER IF NOT EXISTS '${USERNAME}'@'localhost' IDENTIFIED BY '${PASSWORD}';
FLUSH PRIVILEGES;
MYSQL

# ----------------------------------------------------------------
# vsftpd Whitelist
# ----------------------------------------------------------------
if ! grep -qx "$USERNAME" /etc/vsftpd.userlist 2>/dev/null; then
    echo "$USERNAME" >> /etc/vsftpd.userlist
    systemctl reload vsftpd 2>/dev/null || systemctl restart vsftpd
fi

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Hosting User Created!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  Username: ${BOLD}$USERNAME${NC}"
echo -e "  Home:     ${BOLD}/home/$USERNAME${NC}"
echo -e "  Shell:    ${BOLD}$SHELL_PATH${NC}"
