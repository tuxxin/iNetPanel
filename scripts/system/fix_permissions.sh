#!/bin/bash
# ==============================================================================
# fix_permissions.sh — Reset file/directory permissions for a hosting user
# Usage: fix_permissions.sh <username>
# ==============================================================================

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; NC='\033[0m'

USERNAME="$1"
if [ -z "$USERNAME" ]; then
    echo -e "${RED}Usage:${NC} fix_permissions.sh <username>"
    exit 1
fi

HOME_DIR="/home/$USERNAME"
if [ ! -d "$HOME_DIR" ]; then
    echo -e "${RED}Error:${NC} Home directory $HOME_DIR does not exist."
    exit 1
fi

if ! id "$USERNAME" &>/dev/null; then
    echo -e "${RED}Error:${NC} User '$USERNAME' does not exist."
    exit 1
fi

echo -e "${BOLD}Fixing permissions for user: ${GREEN}$USERNAME${NC}"

# 1. User home directory
chown "$USERNAME:www-data" "$HOME_DIR"
chmod 750 "$HOME_DIR"

# 2. Shared tmp directory
if [ -d "$HOME_DIR/tmp" ]; then
    chown -R "$USERNAME:www-data" "$HOME_DIR/tmp"
    chmod 750 "$HOME_DIR/tmp"
fi

# 3. Dotfiles
for f in "$HOME_DIR"/.bashrc "$HOME_DIR"/.profile "$HOME_DIR"/.bash_logout; do
    [ -f "$f" ] && chown "$USERNAME:www-data" "$f"
done

# 4. Per-domain directories
FIXED=0
for DOMAIN_DIR in "$HOME_DIR"/*/; do
    [ -d "$DOMAIN_DIR" ] || continue
    DOMAIN=$(basename "$DOMAIN_DIR")

    # Skip tmp and hidden dirs
    [[ "$DOMAIN" == "tmp" || "$DOMAIN" == .* ]] && continue

    # Must have www/ subdir to be a domain
    [ -d "$DOMAIN_DIR/www" ] || continue

    echo -e "  ${GREEN}→${NC} $DOMAIN"

    # Domain root
    chown "$USERNAME:www-data" "$DOMAIN_DIR"
    chmod 750 "$DOMAIN_DIR"

    # Web root — directories 755, files 644
    chown -R "$USERNAME:www-data" "$DOMAIN_DIR/www"
    find "$DOMAIN_DIR/www" -type d -exec chmod 755 {} +
    find "$DOMAIN_DIR/www" -type f -exec chmod 644 {} +

    # Logs directory
    if [ -d "$DOMAIN_DIR/logs" ]; then
        chown -R "$USERNAME:www-data" "$DOMAIN_DIR/logs"
        chmod 750 "$DOMAIN_DIR/logs"
        find "$DOMAIN_DIR/logs" -type f -exec chmod 640 {} +
    fi

    FIXED=$((FIXED + 1))
done

if [ "$FIXED" -eq 0 ]; then
    echo -e "  ${RED}No domains found for user $USERNAME.${NC}"
else
    echo -e "${GREEN}Done.${NC} Fixed permissions for $FIXED domain(s)."
fi
