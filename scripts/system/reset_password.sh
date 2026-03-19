#!/bin/bash
# ==============================================================================
# reset_password.sh — Reset FTP/SSH/MySQL password for a hosting user
# Usage: inetp reset_password --username user1 [--password newpass]
#        If --password omitted, a secure random password is generated.
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; NC=''
fi

USERNAME=""
PASSWORD=""
MYSQL_ROOT_PASS=$(cat /root/.mysql_root_pass 2>/dev/null)

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        --password) PASSWORD="$2"; shift 2 ;;
        *) shift ;;
    esac
done

[ -z "$USERNAME" ] && { echo -e "${RED}Username is required (--username).${NC}"; exit 1; }

# Verify user exists
if ! id "$USERNAME" &>/dev/null; then
    echo -e "${RED}System user '${USERNAME}' does not exist.${NC}"
    exit 1
fi

# Generate password if not provided
if [ -z "$PASSWORD" ]; then
    PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
    GENERATED=1
else
    GENERATED=0
fi

# Validate password length
if [ ${#PASSWORD} -lt 8 ]; then
    echo -e "${RED}Password must be at least 8 characters.${NC}"
    exit 1
fi

echo -e "${BOLD}--- Reset Password: ${USERNAME} ---${NC}"
echo ""

ERRORS=0

# 1. Linux user password (FTP/SSH)
echo -n "  Linux user:   "
if echo "${USERNAME}:${PASSWORD}" | chpasswd 2>/dev/null; then
    echo -e "${GREEN}OK${NC}"
else
    echo -e "${RED}FAILED${NC}"
    ERRORS=$((ERRORS + 1))
fi

# 2. MySQL/MariaDB password
echo -n "  MariaDB user: "
if [ -n "$MYSQL_ROOT_PASS" ]; then
    # Check if MySQL user exists
    USER_EXISTS=$(mysql -u root -p"$MYSQL_ROOT_PASS" -sN -e "SELECT COUNT(*) FROM mysql.user WHERE User='${USERNAME}'" 2>/dev/null)
    if [ "$USER_EXISTS" -gt 0 ] 2>/dev/null; then
        if mysql -u root -p"$MYSQL_ROOT_PASS" -e "ALTER USER '${USERNAME}'@'localhost' IDENTIFIED BY '${PASSWORD}';" 2>/dev/null; then
            mysql -u root -p"$MYSQL_ROOT_PASS" -e "FLUSH PRIVILEGES;" 2>/dev/null
            echo -e "${GREEN}OK${NC}"
        else
            echo -e "${RED}FAILED${NC}"
            ERRORS=$((ERRORS + 1))
        fi
    else
        echo -e "${YELLOW}no MySQL user found${NC}"
    fi
else
    echo -e "${YELLOW}MySQL root password not found${NC}"
fi

# 3. Verify vsftpd.userlist
echo -n "  vsftpd list:  "
if grep -qx "$USERNAME" /etc/vsftpd.userlist 2>/dev/null; then
    echo -e "${GREEN}present${NC}"
else
    echo -e "${YELLOW}not in list (FTP may not work)${NC}"
fi

echo ""

if [ "$ERRORS" -gt 0 ]; then
    echo -e "${RED}Completed with ${ERRORS} error(s).${NC}"
    exit 1
fi

echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Password Reset Complete!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  Username: ${BOLD}${USERNAME}${NC}"
if [ "$GENERATED" -eq 1 ]; then
    echo -e "  Password: ${BOLD}${PASSWORD}${NC}"
    echo ""
    echo -e "${YELLOW}Save this password — it will not be shown again.${NC}"
else
    echo -e "  Password: ${BOLD}(as provided)${NC}"
fi
