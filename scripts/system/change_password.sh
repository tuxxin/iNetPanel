#!/bin/bash
# ==============================================================================
# change_password.sh — Changes the password for a hosting user
#   - Linux user password (FTP/SSH)
#   - MariaDB user password (phpMyAdmin)
# Usage: change_password.sh --username <user> --password <pass>
# ==============================================================================

DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
BOLD='\033[1m'; RED='\033[1;31m'; GREEN='\033[1;32m'; NC='\033[0m'

USERNAME=""
PASSWORD=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        --password) PASSWORD="$2"; shift 2 ;;
        *) shift ;;
    esac
done

[ -z "$USERNAME" ] && { echo -e "${RED}Username required (--username).${NC}"; exit 1; }
[ -z "$PASSWORD" ] && { echo -e "${RED}Password required (--password).${NC}"; exit 1; }

if ! id "$USERNAME" &>/dev/null; then
    echo -e "${RED}System user '${USERNAME}' does not exist.${NC}"
    exit 1
fi

echo -e "${BOLD}--- Changing Password for: ${USERNAME} ---${NC}"

# Linux user password (covers FTP/SSH and user portal via PAM)
echo "$USERNAME:$PASSWORD" | chpasswd
echo -e "  Linux password updated."

# MariaDB user password (covers phpMyAdmin)
mysql -u root -p"$DB_ROOT_PASS" -e "ALTER USER '${USERNAME}'@'localhost' IDENTIFIED BY '${PASSWORD}'; FLUSH PRIVILEGES;" 2>/dev/null
echo -e "  MariaDB password updated."

echo ""
echo -e "${GREEN}Password changed for '${USERNAME}'.${NC}"
