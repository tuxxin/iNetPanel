#!/bin/bash
# ==============================================================================
# wg_peer.sh — Manage WireGuard peers
#
# Actions:
#   --add  --name <name>   Generate keypair, add [Peer] to wg0.conf, save config
#   --remove --name <name> Remove [Peer] block from wg0.conf, delete config file
#   --list                 List all peers with their assigned IPs
#
# Peer configs saved to: /etc/wireguard/peers/<name>.conf
# Output for --add: prints the full client .conf (for QR code rendering in panel)
#
# Usage: inetp wg_peer --add --name example.com
#        inetp wg_peer --remove --name example.com
#        inetp wg_peer --list
# ==============================================================================

WG_CONF="/etc/wireguard/wg0.conf"
WG_PEERS_DIR="/etc/wireguard/peers"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
ACTION=""
PEER_NAME=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --add)    ACTION="add";    shift ;;
        --remove) ACTION="remove"; shift ;;
        --list)   ACTION="list";   shift ;;
        --name)   PEER_NAME="$2";  shift 2 ;;
        *) shift ;;
    esac
done

[ -z "$ACTION" ] && {
    echo -e "Usage: inetp wg_peer --add --name <name>"
    echo -e "       inetp wg_peer --remove --name <name>"
    echo -e "       inetp wg_peer --list"
    exit 1
}

if [ ! -f "$WG_CONF" ]; then
    echo -e "${RED}WireGuard not configured. Run: inetp wireguard_setup${NC}"; exit 1
fi

mkdir -p "$WG_PEERS_DIR"

# Read server info from wg0.conf
SERVER_PRIVKEY=$(grep '^PrivateKey' "$WG_CONF" | awk '{print $3}')
SERVER_PUBKEY=$(echo "$SERVER_PRIVKEY" | wg pubkey)
WG_PORT=$(grep '^ListenPort' "$WG_CONF" | awk '{print $3}')
SERVER_IP=$(grep '^Address' "$WG_CONF" | awk '{print $3}' | cut -d'/' -f1)
WG_NET=$(echo "$SERVER_IP" | sed 's/\.[0-9]*$//')  # e.g. 10.10.0

# Read endpoint from panel DB or fall back to detecting server IP
WG_ENDPOINT=""
if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
    WG_ENDPOINT=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='wg_endpoint';" 2>/dev/null)
fi
[ -z "$WG_ENDPOINT" ] && WG_ENDPOINT=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')

# ==============================================================================
# ADD
# ==============================================================================
if [ "$ACTION" = "add" ]; then
    [ -z "$PEER_NAME" ] && { echo -e "${RED}--name required${NC}"; exit 1; }

    PEER_CONF="${WG_PEERS_DIR}/${PEER_NAME}.conf"
    if [ -f "$PEER_CONF" ]; then
        echo -e "${YELLOW}Peer '${PEER_NAME}' already exists.${NC}"
        cat "$PEER_CONF"
        exit 0
    fi

    # Assign next available IP in subnet
    LAST_OCTET=1
    while IFS= read -r existing; do
        OCT=$(echo "$existing" | grep -oP "${WG_NET}\.\K[0-9]+" | sort -n | tail -1)
        [ -n "$OCT" ] && [ "$OCT" -gt "$LAST_OCTET" ] && LAST_OCTET="$OCT"
    done < <(grep 'AllowedIPs' "$WG_CONF" 2>/dev/null)
    PEER_IP="${WG_NET}.$((LAST_OCTET + 1))"

    # Generate peer keypair
    PEER_PRIVKEY=$(wg genkey)
    PEER_PUBKEY=$(echo "$PEER_PRIVKEY" | wg pubkey)
    PEER_PSK=$(wg genpsk)

    # Write peer config file (client-side .conf)
    cat << PEERCONF > "$PEER_CONF"
[Interface]
PrivateKey = ${PEER_PRIVKEY}
Address    = ${PEER_IP}/32
DNS        = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey    = ${SERVER_PUBKEY}
PresharedKey = ${PEER_PSK}
Endpoint     = ${WG_ENDPOINT}:${WG_PORT}
AllowedIPs   = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
PEERCONF
    chmod 600 "$PEER_CONF"

    # Append [Peer] block to server wg0.conf
    cat << SERVERBLOCK >> "$WG_CONF"

# Peer: ${PEER_NAME}
[Peer]
PublicKey    = ${PEER_PUBKEY}
PresharedKey = ${PEER_PSK}
AllowedIPs   = ${PEER_IP}/32
SERVERBLOCK

    # Reload WireGuard config live (no full restart needed)
    if systemctl is-active --quiet wg-quick@wg0; then
        wg addconf wg0 <(echo -e "[Peer]\nPublicKey = ${PEER_PUBKEY}\nPresharedKey = ${PEER_PSK}\nAllowedIPs = ${PEER_IP}/32") 2>/dev/null
    fi

    # Save to panel DB (escape single quotes for SQL safety)
    if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
        local safe_name="${PEER_NAME//\'/\'\'}"
        local safe_pubkey="${PEER_PUBKEY//\'/\'\'}"
        local safe_ip="${PEER_IP//\'/\'\'}"
        local safe_conf="${PEER_CONF//\'/\'\'}"
        sqlite3 "$PANEL_DB" << SQL 2>/dev/null
INSERT OR REPLACE INTO wg_peers (hosting_user, public_key, peer_ip, config_path, created_at, suspended)
VALUES ('${safe_name}', '${safe_pubkey}', '${safe_ip}', '${safe_conf}', datetime('now'), 0);
SQL
    fi

    # Output client config to stdout (panel reads this for QR code)
    cat "$PEER_CONF"

    echo -e "" >&2
    echo -e "${GREEN}Peer '${PEER_NAME}' added: ${PEER_IP}${NC}" >&2
fi

# ==============================================================================
# REMOVE
# ==============================================================================
if [ "$ACTION" = "remove" ]; then
    [ -z "$PEER_NAME" ] && { echo -e "${RED}--name required${NC}"; exit 1; }

    PEER_CONF="${WG_PEERS_DIR}/${PEER_NAME}.conf"
    if [ ! -f "$PEER_CONF" ]; then
        echo -e "${YELLOW}Peer '${PEER_NAME}' not found.${NC}"; exit 0
    fi

    PEER_PUBKEY=$(grep '^PublicKey' "$PEER_CONF" | awk '{print $3}')

    # Remove [Peer] block from wg0.conf
    python3 - "$WG_CONF" "$PEER_PUBKEY" << 'PYEOF'
import sys, re
conf_path, pubkey = sys.argv[1], sys.argv[2]
with open(conf_path) as f:
    content = f.read()
# Remove the [Peer] block with this pubkey (including preceding # Peer: comment)
pattern = r'(\n# Peer: [^\n]*\n)?\[Peer\][^\[]*PublicKey\s*=\s*' + re.escape(pubkey) + r'[^\[]*'
new_content = re.sub(pattern, '', content, flags=re.DOTALL)
with open(conf_path, 'w') as f:
    f.write(new_content)
PYEOF

    # Remove peer from live WireGuard config
    if systemctl is-active --quiet wg-quick@wg0 && [ -n "$PEER_PUBKEY" ]; then
        wg set wg0 peer "$PEER_PUBKEY" remove 2>/dev/null
    fi

    # Remove peer config file
    rm -f "$PEER_CONF"

    # Remove from panel DB
    if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
        local safe_name="${PEER_NAME//\'/\'\'}"
        sqlite3 "$PANEL_DB" "DELETE FROM wg_peers WHERE hosting_user='${safe_name}';" 2>/dev/null
    fi

    echo -e "${GREEN}Peer '${PEER_NAME}' removed.${NC}"
fi

# ==============================================================================
# LIST
# ==============================================================================
if [ "$ACTION" = "list" ]; then
    echo -e "${BOLD}WireGuard Peers:${NC}"
    FOUND=0
    for conf in "${WG_PEERS_DIR}"/*.conf; do
        [ -f "$conf" ] || continue
        NAME=$(basename "$conf" .conf)
        IP=$(grep '^Address' "$conf" | awk '{print $3}')
        PUBKEY=$(grep '^PublicKey' "${WG_PEERS_DIR}/../wg0.conf" 2>/dev/null | grep -A1 "# Peer: ${NAME}" | grep PublicKey | awk '{print $3}')
        echo -e "  ${GREEN}${NAME}${NC}  →  ${IP}"
        FOUND=$((FOUND + 1))
    done
    [ "$FOUND" -eq 0 ] && echo "  No peers configured."
fi
