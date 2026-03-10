#!/bin/bash
# ==============================================================================
# delete_user.sh — Deletes a hosting user completely
#   - Checks that no domains remain (refuses if any do)
#   - Removes MariaDB user
#   - Removes vsftpd whitelist entry
#   - Removes Linux user + home directory
# Usage: delete_user.sh --username <name> [--force]
# ==============================================================================

DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
BOLD='\033[1m'; RED='\033[1;31m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

USERNAME=""
FORCE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        --force)    FORCE=1; shift ;;
        *) shift ;;
    esac
done

[ -z "$USERNAME" ] && { echo -e "${RED}Username required (--username).${NC}"; exit 1; }

# Check user exists
if ! id "$USERNAME" &>/dev/null; then
    echo -e "${RED}System user '${USERNAME}' does not exist.${NC}"
    exit 1
fi

# Check for remaining domains (directories under /home/username/ that contain www/)
REMAINING=0
if [ -d "/home/$USERNAME" ]; then
    for dir in /home/$USERNAME/*/www; do
        [ -d "$dir" ] && REMAINING=$((REMAINING + 1))
    done
fi

if [ "$REMAINING" -gt 0 ] && [ "$FORCE" -eq 0 ]; then
    echo -e "${RED}User '${USERNAME}' still has ${REMAINING} domain(s). Remove all domains first, or use --force.${NC}"
    exit 1
fi

echo -e "${BOLD}--- Deleting Hosting User: ${USERNAME} ---${NC}"

# ----------------------------------------------------------------
# MariaDB User
# ----------------------------------------------------------------
mysql -u root -p"$DB_ROOT_PASS" << MYSQL 2>/dev/null
DROP USER IF EXISTS '${USERNAME}'@'localhost';
FLUSH PRIVILEGES;
MYSQL
echo -e "  MariaDB user dropped."

# ----------------------------------------------------------------
# vsftpd Whitelist
# ----------------------------------------------------------------
sed -i "/^${USERNAME}$/d" /etc/vsftpd.userlist 2>/dev/null
systemctl reload vsftpd 2>/dev/null || systemctl restart vsftpd

# ----------------------------------------------------------------
# Linux User + Home Directory
# ----------------------------------------------------------------
killall -u "$USERNAME" 2>/dev/null
sleep 1
userdel -r "$USERNAME" 2>/dev/null
echo -e "  Linux user and home directory removed."

echo ""
echo -e "${GREEN}Hosting user '${USERNAME}' fully deleted.${NC}"
