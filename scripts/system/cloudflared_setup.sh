#!/bin/bash
# ==============================================================================
# cloudflared_setup.sh — Install or remove a Cloudflare Zero Trust Tunnel service
#
# Installs cloudflared as a systemd service using a tunnel token.
# Called by install.php after the tunnel is created via Cloudflare API.
#
# Usage:
#   cloudflared_setup.sh --action install --token <TUNNEL_TOKEN>
#   cloudflared_setup.sh --action uninstall
# ==============================================================================

set -euo pipefail

ACTION="install"
TOKEN=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --action) ACTION="$2"; shift 2 ;;
        --token)  TOKEN="$2";  shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done

SERVICE_FILE="/etc/systemd/system/cloudflared.service"

case "$ACTION" in

    install)
        if [[ -z "$TOKEN" ]]; then
            echo '{"success":false,"error":"Missing --token"}' >&2
            exit 1
        fi

        # Write systemd service unit
        cat > "$SERVICE_FILE" << EOF
[Unit]
Description=Cloudflare Zero Trust Tunnel (iNetPanel)
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
TimeoutStartSec=0
Restart=on-failure
RestartSec=5s
ExecStart=$(command -v cloudflared) tunnel --no-autoupdate run --token ${TOKEN}

[Install]
WantedBy=multi-user.target
EOF

        systemctl daemon-reload
        systemctl enable cloudflared
        systemctl restart cloudflared

        echo '{"success":true}'
        ;;

    uninstall)
        systemctl stop    cloudflared 2>/dev/null || true
        systemctl disable cloudflared 2>/dev/null || true
        rm -f "$SERVICE_FILE"
        systemctl daemon-reload
        echo '{"success":true}'
        ;;

    *)
        echo '{"success":false,"error":"Unknown action — use install or uninstall"}' >&2
        exit 1
        ;;
esac
