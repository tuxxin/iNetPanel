#!/bin/bash
# ==============================================================================
# create_account.sh — Creates a hosting account (user + first domain)
#   Wrapper around create_user.sh + add_domain.sh for CLI convenience.
# Usage (interactive):     inetp create_account
# Usage (non-interactive): inetp create_account --domain example.com --password secret [--username user] [--php-version 8.4] [--no-cf]
# ==============================================================================
SCRIPTS_DIR="/root/scripts"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
USERNAME=""
DOMAIN=""
PASSWORD=""
PHP_VER=""
NO_CF=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)    USERNAME="$2";  shift 2 ;;
        --domain)      DOMAIN="$2";    shift 2 ;;
        --password)    PASSWORD="$2";  shift 2 ;;
        --php-version) PHP_VER="$2";   shift 2 ;;
        --no-cf)       NO_CF=1;        shift ;;
        *) shift ;;
    esac
done

echo -e "${BOLD}--- Create New Account ---${NC}"

# Interactive prompts if not fully specified
if [ -z "$DOMAIN" ]; then
    read -p "Enter Domain Name (e.g. example.com): " DOMAIN
fi
[ -z "$DOMAIN" ] && { echo -e "${RED}Domain cannot be empty.${NC}"; exit 1; }

if [ -z "$USERNAME" ]; then
    # Auto-generate from domain: first label, lowercase, strip non-alnum
    USERNAME=$(echo "$DOMAIN" | cut -d. -f1 | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9')
    # Must start with a letter
    [[ "$USERNAME" =~ ^[0-9] ]] && USERNAME="u${USERNAME}"
    USERNAME="${USERNAME:0:32}"
    echo -e "  Username: ${BOLD}${USERNAME}${NC} (auto-generated from domain)"
fi

if [ -z "$PASSWORD" ]; then
    read -s -p "Enter FTP/SSH Password: " PASSWORD
    echo ""
fi
[ -z "$PASSWORD" ] && { echo -e "${RED}Password cannot be empty.${NC}"; exit 1; }

# Check if user already exists
if id "$USERNAME" &>/dev/null; then
    echo -e "${YELLOW}User '${USERNAME}' already exists. Adding domain to existing user.${NC}"
else
    # Step 1: Create user
    echo -e "\n${BOLD}Creating user: ${USERNAME}${NC}"
    bash "$SCRIPTS_DIR/create_user.sh" --username "$USERNAME" --password "$PASSWORD"
    if [ $? -ne 0 ]; then
        echo -e "${RED}Failed to create user.${NC}"
        exit 1
    fi
fi

# Step 2: Add domain
echo -e "\n${BOLD}Adding domain: ${DOMAIN}${NC}"
ADD_ARGS="--username $USERNAME --domain $DOMAIN"
[ -n "$PHP_VER" ] && ADD_ARGS="$ADD_ARGS --php-version $PHP_VER"
[ "$NO_CF" -eq 1 ] && ADD_ARGS="$ADD_ARGS --no-cf"

bash "$SCRIPTS_DIR/add_domain.sh" $ADD_ARGS
if [ $? -ne 0 ]; then
    echo -e "${RED}Failed to add domain.${NC}"
    exit 1
fi
