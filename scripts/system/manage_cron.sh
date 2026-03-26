#!/bin/bash
# iNetPanel — Cron file manager (runs as root via sudo)
# Usage: manage_cron.sh write <name>   (content via stdin)
#        manage_cron.sh remove <name>

ACTION="$1"
NAME="$2"

ALLOWED=("inetpanel_ddns" "inetpanel_autoupdate" "inetpanel_stats" "lamp_update" "lamp_backup")

VALID=0
for a in "${ALLOWED[@]}"; do [[ "$NAME" == "$a" ]] && VALID=1; done
if [[ $VALID -eq 0 ]]; then
    echo "Error: '$NAME' is not an allowed cron file name." >&2
    exit 1
fi

CRONFILE="/etc/cron.d/$NAME"

case "$ACTION" in
    write)
        cat > "$CRONFILE"
        chmod 644 "$CRONFILE"
        ;;
    remove)
        rm -f "$CRONFILE"
        ;;
    *)
        echo "Error: unknown action '$ACTION'" >&2
        exit 1
        ;;
esac
