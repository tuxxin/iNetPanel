#!/bin/bash
# ==============================================================================
# wireguard_setup.sh — Install and configure WireGuard VPN server
#
# - Installs wireguard package
# - Generates server keypair
# - Writes /etc/wireguard/wg0.conf
# - Enables IP forwarding
# - Restricts SSH/FTP to WireGuard subnet only (CSF or iptables)
# - Enables wg-quick@wg0 systemd service
#
# Usage: inetp wireguard_setup --port 51820 --subnet 10.10.0.0/24 --endpoint hostname_or_ip
# ==============================================================================

WG_CONF="/etc/wireguard/wg0.conf"
WG_PEERS_DIR="/etc/wireguard/peers"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
WG_PORT=51820
WG_SUBNET="10.10.0.0/24"
WG_ENDPOINT=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --port)     WG_PORT="$2";     shift 2 ;;
        --subnet)   WG_SUBNET="$2";   shift 2 ;;
        --endpoint) WG_ENDPOINT="$2"; shift 2 ;;
        *) shift ;;
    esac
done

# Derive server IP from subnet (x.x.x.1)
WG_SERVER_IP=$(echo "$WG_SUBNET" | sed 's|\([0-9]*\.[0-9]*\.[0-9]*\)\.[0-9]*/.*|\1.1|')
WG_SUBNET_CIDR=$(echo "$WG_SUBNET" | sed 's|.*/|/|')

if [ -z "$WG_ENDPOINT" ]; then
    WG_ENDPOINT=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
    [ -z "$WG_ENDPOINT" ] && WG_ENDPOINT=$(hostname -I | awk '{print $1}')
fi

echo -e "${BOLD}--- WireGuard Setup ---${NC}"
echo -e "  Port:     $WG_PORT"
echo -e "  Subnet:   $WG_SUBNET"
echo -e "  Endpoint: $WG_ENDPOINT"
echo ""

# Check if already installed
if [ -f "$WG_CONF" ]; then
    echo -e "${YELLOW}WireGuard already configured at $WG_CONF${NC}"
    echo -e "To reconfigure, remove $WG_CONF and run again."
    exit 0
fi

# Install WireGuard
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq wireguard wireguard-tools 2>/dev/null

# Generate server keypair
mkdir -p /etc/wireguard
chmod 700 /etc/wireguard
mkdir -p "$WG_PEERS_DIR"

SERVER_PRIVKEY=$(wg genkey)
SERVER_PUBKEY=$(echo "$SERVER_PRIVKEY" | wg pubkey)

# Save public key for reference
echo "$SERVER_PUBKEY" > /etc/wireguard/server_pubkey.txt

# Enable IP forwarding
if ! grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf; then
    echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
fi
sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1

# Detect main network interface
MAIN_IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')
[ -z "$MAIN_IFACE" ] && MAIN_IFACE="eth0"

# Write server config
cat << WGCONF > "$WG_CONF"
[Interface]
Address    = ${WG_SERVER_IP}/24
ListenPort = ${WG_PORT}
PrivateKey = ${SERVER_PRIVKEY}

# NAT: forward VPN traffic to internet
PostUp   = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${MAIN_IFACE} -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${MAIN_IFACE} -j MASQUERADE

# Peers are added below by wg_peer.sh
WGCONF

chmod 600 "$WG_CONF"

# Enable and start WireGuard
systemctl enable wg-quick@wg0 >/dev/null 2>&1
systemctl start  wg-quick@wg0 2>/dev/null

# ==============================================================================
# Firewall: restrict SSH and FTP to WireGuard subnet + localhost only
# ==============================================================================
restrict_public_ports() {
    local WG_NET="${WG_SUBNET}"

    if command -v csf &>/dev/null; then
        # CSF: Remove 20, 21, 22 from public TCP_IN; add WG port to UDP_IN
        CURRENT_TCP=$(grep '^TCP_IN' /etc/csf/csf.conf | cut -d'"' -f2)
        # Remove ports 20, 21, 22 from TCP_IN
        NEW_TCP=$(echo "$CURRENT_TCP" | sed 's/,\?20\b//g; s/,\?21\b//g; s/,\?22\b//g; s/^,//; s/,,/,/g')
        sed -i "s|^TCP_IN = \".*\"|TCP_IN = \"${NEW_TCP}\"|" /etc/csf/csf.conf

        # Add WG port to UDP_IN
        CURRENT_UDP=$(grep '^UDP_IN' /etc/csf/csf.conf | cut -d'"' -f2)
        if ! echo "$CURRENT_UDP" | grep -qw "$WG_PORT"; then
            sed -i "s|^UDP_IN = \".*\"|UDP_IN = \"${CURRENT_UDP},${WG_PORT}\"|" /etc/csf/csf.conf
        fi

        # Allow SSH and FTP from WireGuard subnet
        cat << EOF >> /etc/csf/csf.allow
# iNetPanel WireGuard — allow SSH/FTP from VPN subnet
tcp|in|d=${WG_NET}|d=22
tcp|in|d=${WG_NET}|d=21
tcp|in|d=${WG_NET}|d=20
EOF
        csf -r >/dev/null 2>&1

    else
        # iptables fallback: restrict SSH (22) and FTP (20,21) to WG subnet + lo
        iptables -I INPUT -p tcp --dport 22 -s "$WG_NET" -j ACCEPT
        iptables -I INPUT -p tcp --dport 22 -s 127.0.0.1  -j ACCEPT
        iptables -A INPUT -p tcp --dport 22 -j DROP

        iptables -I INPUT -p tcp --dport 21 -s "$WG_NET" -j ACCEPT
        iptables -I INPUT -p tcp --dport 21 -s 127.0.0.1  -j ACCEPT
        iptables -A INPUT -p tcp --dport 21 -j DROP

        iptables -I INPUT -p tcp --dport 20 -s "$WG_NET" -j ACCEPT
        iptables -I INPUT -p tcp --dport 20 -s 127.0.0.1  -j ACCEPT
        iptables -A INPUT -p tcp --dport 20 -j DROP

        # Allow WG UDP port
        iptables -I INPUT -p udp --dport "$WG_PORT" -j ACCEPT

        # Persist iptables rules
        apt-get install -y -qq iptables-persistent 2>/dev/null
        netfilter-persistent save 2>/dev/null
    fi

    # Restrict vsftpd to WireGuard interface only
    VSFTPD_CONF="/etc/vsftpd.conf"
    if [ -f "$VSFTPD_CONF" ]; then
        if grep -q '^listen_address' "$VSFTPD_CONF"; then
            sed -i "s/^listen_address.*/listen_address=${WG_SERVER_IP}/" "$VSFTPD_CONF"
        else
            echo "listen_address=${WG_SERVER_IP}" >> "$VSFTPD_CONF"
        fi
        systemctl restart vsftpd 2>/dev/null
    fi

    # Restrict SSH to WireGuard interface only
    SSHD_CONF="/etc/ssh/sshd_config"
    if [ -f "$SSHD_CONF" ]; then
        if grep -q '^ListenAddress' "$SSHD_CONF"; then
            sed -i "s/^ListenAddress.*/ListenAddress ${WG_SERVER_IP}/" "$SSHD_CONF"
        else
            echo "ListenAddress ${WG_SERVER_IP}" >> "$SSHD_CONF"
        fi
        # Keep 0.0.0.0 as fallback in case VPN is down — comment it so admin can re-enable
        # Safer approach: use AllowUsers to lock down access
        systemctl reload sshd 2>/dev/null
    fi
}
restrict_public_ports

# Save WG settings to panel DB
if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
    sqlite3 "$PANEL_DB" << SQL 2>/dev/null
INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_enabled', '1');
INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_port', '${WG_PORT}');
INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_subnet', '${WG_SUBNET}');
INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_endpoint', '${WG_ENDPOINT}');
INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_server_pubkey', '${SERVER_PUBKEY}');
SQL
fi

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} WireGuard Configured!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  Interface:    wg0"
echo -e "  Server IP:    ${WG_SERVER_IP}"
echo -e "  Port:         ${WG_PORT}/UDP"
echo -e "  Endpoint:     ${WG_ENDPOINT}:${WG_PORT}"
echo -e "  Public Key:   ${SERVER_PUBKEY}"
echo -e "  Config:       ${WG_CONF}"
echo ""
echo -e "  ${YELLOW}SSH and FTP are now restricted to WireGuard subnet (${WG_SUBNET})${NC}"
echo -e "  Add peers with: ${GREEN}inetp wg_peer --add --name <username>${NC}"
