#!/bin/bash
# ==============================================================================
# suspend_account.sh — Suspend or resume a hosted account
#
# Suspension disables:
#   - Website (Apache vhost replaced with suspended notice, HTTP 503)
#   - FTP/SSH (Linux user account locked via usermod -L)
#   - WireGuard peer (if WG is active, peer block commented out in wg0.conf)
#
# Resume reverses all steps atomically.
#
# Usage (interactive):     inetp suspend_account
# Usage (non-interactive): inetp suspend_account --domain example.com --suspend
#                          inetp suspend_account --domain example.com --resume
# ==============================================================================

CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
SUSPENDED_PAGE="/var/www/inetpanel/suspended/index.html"
WG_CONF="/etc/wireguard/wg0.conf"
WG_PEERS_DIR="/etc/wireguard/peers"
PHP_VER="8.4"

BOLD='\033[1m'; RED='\033[1;31m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
ACTION=""
USERNAME=""
NON_INTERACTIVE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)   DOMAIN="$2";   shift 2 ;;
        --username) USERNAME="$2"; shift 2 ;;
        --suspend)  ACTION="suspend"; shift ;;
        --resume)   ACTION="resume";  shift ;;
        *) shift ;;
    esac
done
[ -n "$DOMAIN" ] && [ -n "$ACTION" ] && NON_INTERACTIVE=1
[ -n "$USERNAME" ] && [ -n "$ACTION" ] && NON_INTERACTIVE=1

# --- Interactive mode ---
if [ "$NON_INTERACTIVE" -eq 0 ]; then
    echo -e "${BOLD}--- Suspend / Resume Account ---${NC}"
    read -p "Enter domain name: " DOMAIN
    [ -z "$DOMAIN" ] && { echo -e "${RED}Domain cannot be empty.${NC}"; exit 1; }
    read -p "Action [suspend/resume]: " ACTION
fi

[ -z "$ACTION" ]  && { echo -e "${RED}Action must be 'suspend' or 'resume'.${NC}"; exit 1; }

# If --username provided without --domain, suspend ALL domains for that user
if [ -n "$USERNAME" ] && [ -z "$DOMAIN" ]; then
    echo -e "${BOLD}${ACTION^}ing all domains for user: ${USERNAME}${NC}"
    # Lock/unlock the user account itself
    if id "$USERNAME" &>/dev/null; then
        if [ "$ACTION" = "suspend" ]; then
            usermod -L "$USERNAME" 2>/dev/null
        else
            usermod -U "$USERNAME" 2>/dev/null
        fi
    fi
    # Find all domains for this user from Apache vhosts
    for conf in /etc/apache2/sites-available/*.conf; do
        NAME=$(basename "$conf" .conf)
        [[ "$NAME" == "000-default" || "$NAME" == "phpmyadmin" ]] && continue
        DOC=$(grep -oP 'DocumentRoot\s+\K\S+' "$conf" 2>/dev/null)
        if echo "$DOC" | grep -q "/home/$USERNAME/"; then
            bash "$0" --domain "$NAME" --$ACTION
        fi
    done
    exit 0
fi

[ -z "$DOMAIN" ]  && { echo -e "${RED}Domain required.${NC}"; exit 1; }

# Auto-detect username from vhost DocumentRoot
if [ -z "$USERNAME" ]; then
    VHOST_CHECK="/etc/apache2/sites-available/${DOMAIN}.conf"
    [ -f "${VHOST_CHECK}.orig" ] && VHOST_CHECK="${VHOST_CHECK}.orig"
    if [ -f "$VHOST_CHECK" ]; then
        DOC_ROOT_LINE=$(grep -oP 'DocumentRoot\s+\K\S+' "$VHOST_CHECK" 2>/dev/null)
        # New format: /home/username/domain/www → extract username
        if echo "$DOC_ROOT_LINE" | grep -qP '^/home/[^/]+/[^/]+/www'; then
            USERNAME=$(echo "$DOC_ROOT_LINE" | cut -d/ -f3)
        else
            # Legacy format: /home/domain/www → username=domain
            USERNAME="$DOMAIN"
        fi
    else
        USERNAME="$DOMAIN"
    fi
fi

VHOST_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"
VHOST_BACKUP="/etc/apache2/sites-available/${DOMAIN}.conf.orig"

if [ ! -f "$VHOST_CONF" ] && [ ! -f "$VHOST_BACKUP" ]; then
    echo -e "${RED}Account '$DOMAIN' not found.${NC}"; exit 1
fi

# ==============================================================================
# SUSPEND
# ==============================================================================
if [ "$ACTION" = "suspend" ]; then

    # Bail if already suspended
    if [ -f "$VHOST_BACKUP" ]; then
        echo -e "${YELLOW}Account '$DOMAIN' is already suspended.${NC}"; exit 0
    fi

    echo -e "${YELLOW}Suspending account: $DOMAIN${NC}"

    # 1. Apache — swap vhost to serve suspended page (HTTP 503)
    PORT=$(grep '<VirtualHost' "$VHOST_CONF" | grep -oP '(?<=:)\d+')
    cp "$VHOST_CONF" "$VHOST_BACKUP"

    cat << VHOST > "$VHOST_CONF"
<VirtualHost *:${PORT}>
    ServerName  ${DOMAIN}
    DocumentRoot /var/www/inetpanel/suspended

    Alias / /var/www/inetpanel/suspended/

    <Directory /var/www/inetpanel/suspended>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    # Return 503 for all requests
    RewriteEngine On
    RewriteRule ^ - [R=503,L]

    ErrorDocument 503 /index.html

    ErrorLog  /home/${USERNAME}/${DOMAIN}/logs/apache_error.log
    CustomLog /home/${USERNAME}/${DOMAIN}/logs/apache_access.log combined
</VirtualHost>
VHOST
    systemctl reload apache2 2>/dev/null

    # 2. FTP/SSH — lock Linux user
    if id "$USERNAME" &>/dev/null; then
        usermod -L "$USERNAME" 2>/dev/null
    fi

    # 3. WireGuard — disable peer (comment out [Peer] block)
    if [ -f "$WG_CONF" ] && command -v wg &>/dev/null; then
        # Check both new (username) and legacy (domain) peer naming
        PEER_CONF="${WG_PEERS_DIR}/${USERNAME}.conf"
        [ -f "$PEER_CONF" ] || PEER_CONF="${WG_PEERS_DIR}/${DOMAIN}.conf"
        if [ -f "$PEER_CONF" ]; then
            # Extract public key from peer conf to find the block in wg0.conf
            WG_PUBKEY=$(grep '^PublicKey' "$PEER_CONF" | awk '{print $3}')
            if [ -n "$WG_PUBKEY" ]; then
                # Comment out the [Peer] block for this public key in wg0.conf
                python3 - "$WG_CONF" "$WG_PUBKEY" << 'PYEOF'
import sys, re
conf_path, pubkey = sys.argv[1], sys.argv[2]
with open(conf_path) as f:
    content = f.read()
# Comment out the peer block matching this pubkey
pattern = r'(\[Peer\][^\[]*PublicKey\s*=\s*' + re.escape(pubkey) + r'[^\[]*)'
def comment_block(m):
    return '\n'.join('# SUSPENDED: ' + l if l.strip() else l for l in m.group(1).split('\n'))
new_content = re.sub(pattern, comment_block, content, flags=re.DOTALL)
with open(conf_path, 'w') as f:
    f.write(new_content)
PYEOF
                # Reload WireGuard config live
                wg syncconf wg0 <(wg-quick strip wg0) 2>/dev/null || true
            fi
        fi
    fi

    # 4. Update SQLite panel DB
    if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
        sqlite3 "$PANEL_DB" "UPDATE domains SET status='suspended' WHERE domain_name='${DOMAIN}';" 2>/dev/null
        sqlite3 "$PANEL_DB" "UPDATE wg_peers SET suspended=1 WHERE hosting_user='${USERNAME}';" 2>/dev/null
    fi

    echo -e "${GREEN}Account '${DOMAIN}' suspended.${NC}"
    echo -e "  Website:    503 Suspended notice"
    echo -e "  FTP/SSH:    Locked"
    [ -f "$WG_CONF" ] && echo -e "  WireGuard:  Peer disabled"
    echo -e ""
    echo -e "  Resume with: ${YELLOW}inetp suspend_account --domain $DOMAIN --resume${NC}"
fi

# ==============================================================================
# RESUME
# ==============================================================================
if [ "$ACTION" = "resume" ]; then

    # Bail if not suspended
    if [ ! -f "$VHOST_BACKUP" ]; then
        echo -e "${YELLOW}Account '$DOMAIN' is not suspended.${NC}"; exit 0
    fi

    echo -e "${YELLOW}Resuming account: $DOMAIN${NC}"

    # 1. Apache — restore original vhost
    cp "$VHOST_BACKUP" "$VHOST_CONF"
    rm -f "$VHOST_BACKUP"
    systemctl reload apache2 2>/dev/null

    # 2. FTP/SSH — unlock Linux user
    if id "$USERNAME" &>/dev/null; then
        usermod -U "$USERNAME" 2>/dev/null
    fi

    # 3. WireGuard — re-enable peer
    if [ -f "$WG_CONF" ] && command -v wg &>/dev/null; then
        PEER_CONF="${WG_PEERS_DIR}/${USERNAME}.conf"
        [ -f "$PEER_CONF" ] || PEER_CONF="${WG_PEERS_DIR}/${DOMAIN}.conf"
        if [ -f "$PEER_CONF" ]; then
            WG_PUBKEY=$(grep '^PublicKey' "$PEER_CONF" | awk '{print $3}')
            if [ -n "$WG_PUBKEY" ]; then
                # Remove suspension comments from wg0.conf
                sed -i "s/^# SUSPENDED: //" "$WG_CONF"
                wg syncconf wg0 <(wg-quick strip wg0) 2>/dev/null || true
            fi
        fi
    fi

    # 4. Update SQLite panel DB
    if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
        sqlite3 "$PANEL_DB" "UPDATE domains SET status='active' WHERE domain_name='${DOMAIN}';" 2>/dev/null
        sqlite3 "$PANEL_DB" "UPDATE wg_peers SET suspended=0 WHERE hosting_user='${USERNAME}';" 2>/dev/null
    fi

    echo -e "${GREEN}Account '${DOMAIN}' reactivated.${NC}"
    echo -e "  Website:    Online"
    echo -e "  FTP/SSH:    Unlocked"
    [ -f "$WG_CONF" ] && echo -e "  WireGuard:  Peer restored"
fi
