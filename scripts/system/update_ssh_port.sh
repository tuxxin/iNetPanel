#!/bin/bash
# ==============================================================================
# update_ssh_port.sh — Safely change SSH port across sshd, firewalld, fail2ban
#
# Usage: update_ssh_port.sh --port <number>
# ==============================================================================

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

NEW_PORT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --port) NEW_PORT="$2"; shift 2 ;;
        *) shift ;;
    esac
done

if [ -z "$NEW_PORT" ] || ! [[ "$NEW_PORT" =~ ^[0-9]+$ ]] || [ "$NEW_PORT" -lt 1 ] || [ "$NEW_PORT" -gt 65535 ]; then
    echo -e "${RED}Invalid port. Usage: update_ssh_port.sh --port <1-65535>${NC}"
    exit 1
fi

SSHD_CONF="/etc/ssh/sshd_config"

# Detect current SSH port
OLD_PORT=$(grep -E '^Port ' "$SSHD_CONF" 2>/dev/null | awk '{print $2}')
[ -z "$OLD_PORT" ] && OLD_PORT=22

if [ "$OLD_PORT" = "$NEW_PORT" ]; then
    echo -e "${YELLOW}SSH port is already ${NEW_PORT}. No changes needed.${NC}"
    exit 0
fi

echo -e "${BOLD}Changing SSH port: ${OLD_PORT} → ${NEW_PORT}${NC}"

# 1. Update sshd_config
if grep -qE '^Port ' "$SSHD_CONF"; then
    sed -i "s/^Port .*/Port ${NEW_PORT}/" "$SSHD_CONF"
else
    echo "Port ${NEW_PORT}" >> "$SSHD_CONF"
fi
echo -e "  ${GREEN}sshd_config updated${NC}"

# 2. Update firewalld (if running)
if command -v firewall-cmd &>/dev/null && firewall-cmd --state &>/dev/null; then
    # Update default zone
    firewall-cmd --permanent --remove-port=${OLD_PORT}/tcp 2>/dev/null
    firewall-cmd --permanent --add-port=${NEW_PORT}/tcp

    # Update vpn zone if it exists (WireGuard lockdown mode)
    if firewall-cmd --permanent --get-zones 2>/dev/null | grep -qw vpn; then
        firewall-cmd --permanent --zone=vpn --remove-port=${OLD_PORT}/tcp 2>/dev/null
        firewall-cmd --permanent --zone=vpn --add-port=${NEW_PORT}/tcp
    fi

    firewall-cmd --reload
    echo -e "  ${GREEN}Firewalld updated${NC}"
fi

# 3. Update fail2ban jail.local (sshd port)
JAIL_LOCAL="/etc/fail2ban/jail.local"
if [ -f "$JAIL_LOCAL" ]; then
    # Update port in [sshd] section
    sed -i "/^\[sshd\]/,/^\[/ s/^port\s*=.*/port     = ${NEW_PORT}/" "$JAIL_LOCAL"
    systemctl reload fail2ban 2>/dev/null
    echo -e "  ${GREEN}Fail2Ban updated${NC}"
fi

# 4. Validate config and restart SSH
if sshd -t 2>/dev/null; then
    systemctl restart sshd 2>/dev/null
    echo -e "  ${GREEN}SSH restarted on port ${NEW_PORT}${NC}"
else
    echo -e "  ${RED}sshd config test failed! Reverting to port ${OLD_PORT}...${NC}"
    sed -i "s/^Port .*/Port ${OLD_PORT}/" "$SSHD_CONF"
    exit 1
fi

echo -e "${GREEN}Done. SSH port changed to ${NEW_PORT}.${NC}"
