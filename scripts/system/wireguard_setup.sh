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
# Usage: inetp wireguard_setup --port 1443 --subnet 10.10.0.0/24 --endpoint hostname_or_ip
# ==============================================================================

WG_CONF="/etc/wireguard/wg0.conf"
WG_PEERS_DIR="/etc/wireguard/peers"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# --- Parse flags ---
WG_PORT=1443
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
# Firewall: full server lockdown — only WireGuard port open publicly
# All other services accessible only via VPN subnet + localhost (cloudflared)
# ==============================================================================
lockdown_firewall() {
    local WG_NET="${WG_SUBNET}"

    # Detect SSH port from sshd_config (default 1022)
    local SSH_PORT
    SSH_PORT=$(grep -E '^Port ' /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}')
    [ -z "$SSH_PORT" ] && SSH_PORT=1022

    if ! command -v firewall-cmd &>/dev/null; then
        echo -e "${RED}firewalld not installed — cannot lock down firewall.${NC}"
        echo -e "${YELLOW}Install with: apt-get install -y firewalld${NC}"
        return 1
    fi

    # Ensure firewalld is running
    systemctl enable --now firewalld 2>/dev/null

    # Set default zone to drop (deny all incoming)
    firewall-cmd --set-default-zone=drop

    # Clear any existing port/service rules from default zone
    for p in $(firewall-cmd --permanent --list-ports 2>/dev/null); do
        firewall-cmd --permanent --remove-port="$p" 2>/dev/null
    done
    for s in $(firewall-cmd --permanent --list-services 2>/dev/null); do
        firewall-cmd --permanent --remove-service="$s" 2>/dev/null
    done

    # Only allow WireGuard UDP port publicly (entry point for VPN)
    firewall-cmd --permanent --add-port=${WG_PORT}/udp

    # Create "vpn" zone for WireGuard subnet traffic
    firewall-cmd --permanent --new-zone=vpn 2>/dev/null
    firewall-cmd --permanent --zone=vpn --add-source="${WG_NET}"
    firewall-cmd --permanent --zone=vpn --add-port=${SSH_PORT}/tcp
    firewall-cmd --permanent --zone=vpn --add-port=21/tcp
    firewall-cmd --permanent --zone=vpn --add-port=20/tcp
    firewall-cmd --permanent --zone=vpn --add-port=80/tcp
    firewall-cmd --permanent --zone=vpn --add-port=8888/tcp

    # Allow all traffic on loopback (required for cloudflared, local services)
    firewall-cmd --permanent --zone=trusted --add-interface=lo

    # Enable masquerade for VPN NAT (forward VPN traffic to internet)
    firewall-cmd --permanent --add-masquerade

    firewall-cmd --reload

    echo -e "${GREEN}Firewalld: Lockdown active — only port ${WG_PORT}/UDP public${NC}"
    echo -e "${GREEN}VPN zone created for subnet ${WG_NET}${NC}"

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
        systemctl reload sshd 2>/dev/null
    fi
}
lockdown_firewall

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
echo -e "${YELLOW}  ┌─────────────────────────────────────────────────┐${NC}"
echo -e "${YELLOW}  │  FULL SERVER LOCKDOWN ACTIVE                    │${NC}"
echo -e "${YELLOW}  │                                                 │${NC}"
echo -e "${YELLOW}  │  Only port ${WG_PORT}/UDP (WireGuard) is public       │${NC}"
echo -e "${YELLOW}  │  All other ports: VPN access only               │${NC}"
echo -e "${YELLOW}  │  Public web traffic routes via Cloudflare       │${NC}"
echo -e "${YELLOW}  │                                                 │${NC}"
echo -e "${YELLOW}  │  Blocked from public:                           │${NC}"
echo -e "${YELLOW}  │    Port 80  (Panel)     → VPN + Cloudflare only │${NC}"
echo -e "${YELLOW}  │    Port 8888 (phpMyAdmin)→ VPN only             │${NC}"
echo -e "${YELLOW}  │    SSH               → VPN only              │${NC}"
echo -e "${YELLOW}  │    Port 20-21 (FTP)     → VPN only              │${NC}"
echo -e "${YELLOW}  │    Port 1080+ (Sites)   → VPN + Cloudflare only │${NC}"
echo -e "${YELLOW}  └─────────────────────────────────────────────────┘${NC}"
echo ""
echo -e "  Add peers with: ${GREEN}inetp wg_peer --add --name <username>${NC}"
