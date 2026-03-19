#!/bin/bash
# ==============================================================================
# wireguard_uninstall.sh — Remove WireGuard and restore public access
#
# - Stops and disables wg-quick@wg0
# - Removes firewall lockdown (restores public access to all ports)
# - Restores SSH to listen on all interfaces
# - Restores vsftpd to listen on all interfaces
# - Removes WireGuard config and keys
# - Updates panel DB
#
# Usage: inetp wireguard_uninstall
# ==============================================================================

WG_CONF="/etc/wireguard/wg0.conf"
WG_PEERS_DIR="/etc/wireguard/peers"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

# Read WG port and server IP from config before we delete it
WG_PORT=""
WG_SERVER_IP=""
WG_PORT_FROM_CONF=0
if [ -f "$WG_CONF" ]; then
    WG_PORT=$(grep '^ListenPort' "$WG_CONF" | awk '{print $3}')
    # Extract server IP from Address line (e.g. "Address = 10.0.0.1/24" → "10.0.0.1")
    WG_SERVER_IP=$(grep '^Address' "$WG_CONF" | awk '{print $3}' | cut -d'/' -f1)
fi
if [ -n "$WG_PORT" ]; then
    WG_PORT_FROM_CONF=1
else
    echo -e "  ${YELLOW}Warning: Could not read ListenPort from ${WG_CONF} — firewall rule removal skipped for safety${NC}"
fi

echo -e "${BOLD}--- WireGuard Uninstall ---${NC}"
echo ""

# Stop and disable WireGuard
if systemctl is-active --quiet wg-quick@wg0 2>/dev/null; then
    echo -e "  Stopping wg-quick@wg0..."
    if ! systemctl stop wg-quick@wg0; then
        echo -e "  ${YELLOW}Warning: Failed to stop wg-quick@wg0 (exit code $?)${NC}"
    fi
fi
systemctl disable wg-quick@wg0 2>/dev/null

# ==============================================================================
# Restore firewall: remove lockdown, open all service ports publicly
# ==============================================================================
restore_firewall() {
    if ! command -v firewall-cmd &>/dev/null; then
        echo -e "  ${YELLOW}firewalld not installed — skipping firewall restore${NC}"
        return 0
    fi

    # Detect SSH port from sshd_config (default 1022)
    local SSH_PORT
    SSH_PORT=$(grep -E '^Port ' /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}')
    [ -z "$SSH_PORT" ] && SSH_PORT=1022

    # Remove vpn zone (WireGuard subnet access)
    firewall-cmd --permanent --delete-zone=vpn 2>/dev/null

    # Restore public access: add service ports back to default zone
    firewall-cmd --permanent --add-port=${SSH_PORT}/tcp
    firewall-cmd --permanent --add-port=21/tcp
    firewall-cmd --permanent --add-port=20/tcp
    firewall-cmd --permanent --add-port=80/tcp
    firewall-cmd --permanent --add-port=8888/tcp

    # Remove WG UDP port (only if we successfully read it from config)
    if [ "$WG_PORT_FROM_CONF" -eq 1 ]; then
        firewall-cmd --permanent --remove-port=${WG_PORT}/udp 2>/dev/null
    fi

    # Remove masquerade (no longer needed without VPN)
    firewall-cmd --permanent --remove-masquerade 2>/dev/null

    firewall-cmd --reload
    echo -e "  ${GREEN}Firewalld: Lockdown removed, service ports restored${NC}"
}
restore_firewall

# Restore SSH: remove only the WireGuard-specific ListenAddress
SSHD_CONF="/etc/ssh/sshd_config"
if [ -f "$SSHD_CONF" ]; then
    if [ -n "$WG_SERVER_IP" ]; then
        sed -i "/^ListenAddress ${WG_SERVER_IP}$/d" "$SSHD_CONF"
    else
        echo -e "  ${YELLOW}Warning: WG server IP unknown — cannot remove specific ListenAddress from sshd_config${NC}"
    fi
    if ! systemctl reload sshd 2>/dev/null; then
        echo -e "  ${YELLOW}Warning: Failed to reload sshd (exit code $?)${NC}"
    else
        echo -e "  ${GREEN}SSH: Removed WireGuard ListenAddress${NC}"
    fi
fi

# Restore vsftpd to listen on all interfaces
VSFTPD_CONF="/etc/vsftpd.conf"
if [ -f "$VSFTPD_CONF" ]; then
    sed -i '/^listen_address/d' "$VSFTPD_CONF"
    if ! systemctl restart vsftpd 2>/dev/null; then
        echo -e "  ${YELLOW}Warning: Failed to restart vsftpd (exit code $?)${NC}"
    else
        echo -e "  ${GREEN}vsftpd: Restored to listen on all interfaces${NC}"
    fi
fi

# Remove WireGuard config and keys
rm -f "$WG_CONF"
rm -f /etc/wireguard/server_pubkey.txt
rm -f /etc/wireguard/server_privkey
if [ -d "$WG_PEERS_DIR" ]; then
    rm -rf "$WG_PEERS_DIR"
    echo -e "  ${YELLOW}Removed all peer configs from ${WG_PEERS_DIR}${NC}"
fi

# Remove IP forwarding (only if we added it)
sed -i '/^net.ipv4.ip_forward=1$/d' /etc/sysctl.conf
sysctl -w net.ipv4.ip_forward=0 >/dev/null 2>&1

# Update panel DB
if [ -f "$PANEL_DB" ] && command -v sqlite3 &>/dev/null; then
    sqlite3 "$PANEL_DB" << SQL 2>/dev/null
INSERT OR REPLACE INTO settings (key, value) VALUES ('wg_enabled', '0');
DELETE FROM settings WHERE key IN ('wg_port', 'wg_subnet', 'wg_endpoint', 'wg_server_pubkey');
DELETE FROM wg_peers;
SQL
    echo -e "  ${GREEN}Panel DB: WireGuard settings cleared${NC}"
fi

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} WireGuard Removed${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e ""
echo -e "  ${YELLOW}Server lockdown has been lifted.${NC}"
echo -e "  ${YELLOW}All ports are now publicly accessible.${NC}"
echo -e "  ${YELLOW}SSH and FTP listen on all interfaces.${NC}"
echo ""
