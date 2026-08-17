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
DROPIN_DIR="/etc/ssh/sshd_config.d"
DROPIN="${DROPIN_DIR}/99-inetpanel.conf"

# Prefer a drop-in when the main config includes that directory. A Port line in
# /etc/ssh/sshd_config does not survive an openssh-server upgrade: that file is
# ucf-managed, and accepting the maintainer's version on the upgrade prompt
# silently reverts the port — which locks you out on the next reconnect.
USE_DROPIN=0
if grep -qE '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' "$SSHD_CONF" 2>/dev/null; then
    USE_DROPIN=1
    mkdir -p "$DROPIN_DIR"
fi

# Detect the port sshd is ACTUALLY using — the running config is authoritative,
# and it may come from a drop-in rather than the main file.
OLD_PORT=$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')
[ -z "$OLD_PORT" ] && OLD_PORT=$(grep -hE '^[[:space:]]*Port ' "$DROPIN" "$SSHD_CONF" 2>/dev/null | head -1 | awk '{print $2}')
[ -z "$OLD_PORT" ] && OLD_PORT=22

if [ "$OLD_PORT" = "$NEW_PORT" ]; then
    echo -e "${YELLOW}SSH port is already ${NEW_PORT}. No changes needed.${NC}"
    exit 0
fi

echo -e "${BOLD}Changing SSH port: ${OLD_PORT} → ${NEW_PORT}${NC}"

# 1. Update sshd config (keep a copy of everything we touch, for the revert path)
HAD_DROPIN=0
cp "$SSHD_CONF" "${SSHD_CONF}.inetp-bak"
[ -f "$DROPIN" ] && { HAD_DROPIN=1; cp "$DROPIN" "${DROPIN}.inetp-bak"; }

if [ "$USE_DROPIN" -eq 1 ]; then
    cat > "$DROPIN" << DROPIN_EOF
# Managed by iNetPanel — do not edit by hand.
# Kept here instead of /etc/ssh/sshd_config so an openssh-server upgrade cannot
# revert the port. Change it from the panel's Firewall page, or with:
#   sudo /root/scripts/update_ssh_port.sh --port <n>
Port ${NEW_PORT}
DROPIN_EOF
    chmod 644 "$DROPIN"
    # One source of truth: neutralise any Port left in the main file. (Port is not
    # a valid keyword inside a Match block, so this cannot hit a conditional one.)
    sed -i 's/^[[:space:]]*Port /#Port /' "$SSHD_CONF"
    echo -e "  ${GREEN}${DROPIN} updated${NC}"
else
    if grep -qE '^Port ' "$SSHD_CONF"; then
        sed -i "s/^Port .*/Port ${NEW_PORT}/" "$SSHD_CONF"
    else
        echo "Port ${NEW_PORT}" >> "$SSHD_CONF"
    fi
    echo -e "  ${GREEN}sshd_config updated${NC}"
fi

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

# 3b. Socket activation overrides sshd_config entirely.
# When ssh.socket is enabled, the listening port comes from its ListenStream=, and
# `Port` in sshd_config is ignored. Editing the config would then appear to work —
# `sshd -T` reports the new port because it parses the CONFIG — while the daemon
# keeps listening on the old one. Combined with the firewall change above, that is a
# lockout. Debian 13 is more likely to ship with the socket enabled.
if systemctl is-enabled ssh.socket >/dev/null 2>&1 || systemctl is-active ssh.socket >/dev/null 2>&1; then
    echo -e "  ${YELLOW}ssh.socket is enabled — sshd_config's Port is ignored under socket activation.${NC}"
    SOCKET_DROPIN=/etc/systemd/system/ssh.socket.d/99-inetpanel.conf
    install -d -m 0755 /etc/systemd/system/ssh.socket.d
    printf '# Managed by iNetPanel — keeps the socket in step with sshd_config.\n[Socket]\nListenStream=\nListenStream=%s\n' \
        "$NEW_PORT" > "$SOCKET_DROPIN"
    systemctl daemon-reload
    systemctl restart ssh.socket 2>/dev/null
    echo -e "  ${GREEN}ssh.socket ListenStream set to ${NEW_PORT}${NC}"
fi

# 4. Validate config and restart SSH
if sshd -t 2>/dev/null && systemctl restart sshd 2>/dev/null; then
    # Confirm sshd really came back on the new port before declaring success —
    # reporting a port change that did not take effect is how people get locked out.
    #
    # Check the ACTUAL listener, not `sshd -T`. `sshd -T` parses the config, so under
    # socket activation it happily reports a port nothing is listening on.
    ACTUAL=$(ss -H -tlnp 2>/dev/null | awk '/sshd|systemd/ {split($4,a,":"); print a[length(a)]}' | sort -u | grep -Fx "$NEW_PORT" | head -1)
    [ -z "$ACTUAL" ] && ACTUAL=$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')
    if [ "$ACTUAL" = "$NEW_PORT" ]; then
        rm -f "${SSHD_CONF}.inetp-bak" "${DROPIN}.inetp-bak"
        echo -e "  ${GREEN}SSH restarted on port ${NEW_PORT}${NC}"
    else
        echo -e "  ${YELLOW}Config written, but sshd reports port '${ACTUAL:-unknown}'.${NC}"
        echo -e "  ${YELLOW}Keep your current session open and verify before reconnecting.${NC}"
    fi
else
    echo -e "  ${RED}sshd config test or restart failed! Reverting to port ${OLD_PORT}...${NC}"
    cp "${SSHD_CONF}.inetp-bak" "$SSHD_CONF"
    if [ "$USE_DROPIN" -eq 1 ]; then
        if [ "$HAD_DROPIN" -eq 1 ]; then mv "${DROPIN}.inetp-bak" "$DROPIN"; else rm -f "$DROPIN"; fi
    fi
    rm -f "${SSHD_CONF}.inetp-bak"
    systemctl restart sshd 2>/dev/null
    exit 1
fi

echo -e "${GREEN}Done. SSH port changed to ${NEW_PORT}.${NC}"
