#!/bin/bash
# ==============================================================================
# service_monitor.sh — Auto-restart stopped services and log to panel
# Subcommands:
#   (none)    Run the monitor check (called by cron)
#   enable    Install cron job for every-2-minute checks
#   disable   Remove cron job
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
CRON_FILE="/etc/cron.d/inetpanel_monitor"
COMMAND="${1:-run}"

case "$COMMAND" in
    enable)
        cat > "$CRON_FILE" <<'EOF'
# iNetPanel Service Monitor — auto-restart stopped services
*/2 * * * * root /usr/local/bin/inetp service_monitor run > /dev/null 2>&1
EOF
        chmod 644 "$CRON_FILE"
        echo "Service monitor enabled."
        exit 0
        ;;
    disable)
        rm -f "$CRON_FILE"
        echo "Service monitor disabled."
        exit 0
        ;;
    run)
        # Fall through to monitoring logic below
        ;;
    *)
        echo "Usage: service_monitor.sh [enable|disable|run]"
        exit 1
        ;;
esac

# ── Monitor check ─────────────────────────────────────────────────────────────

# Read enabled flag from DB — skip if disabled
ENABLED=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='service_monitor'" 2>/dev/null)
[ "$ENABLED" = "0" ] && exit 0

get_monitored_services() {
    local custom
    custom=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='monitored_services'" 2>/dev/null)
    if [ -n "$custom" ]; then
        echo "$custom"
    else
        echo "apache2 mariadb vsftpd cron fail2ban firewalld cloudflared"
    fi
}

get_php_fpm() {
    local ver
    ver=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='php_default_version'" 2>/dev/null)
    [ -z "$ver" ] && ver="8.4"
    echo "php${ver}-fpm"
}

log_to_panel() {
    local level="$1" message="$2" details="$3"
    sqlite3 "$PANEL_DB" "INSERT INTO logs (source, level, message, details, user, created_at) VALUES ('monitor', '${level}', '$(echo "$message" | sed "s/'/''/g")', '$(echo "$details" | sed "s/'/''/g")', 'system', datetime('now'));" 2>/dev/null
}

SERVICES=$(get_monitored_services)
PHP_FPM=$(get_php_fpm)
SERVICES="$SERVICES $PHP_FPM"

RESTARTED=0
FAILED=0

for svc in $SERVICES; do
    # Skip if service unit doesn't exist
    systemctl list-unit-files "${svc}.service" &>/dev/null || \
    systemctl list-unit-files "${svc}" &>/dev/null || continue

    STATUS=$(systemctl is-active "$svc" 2>/dev/null)
    if [ "$STATUS" != "active" ]; then
        systemctl start "$svc" 2>&1
        sleep 2
        NEW_STATUS=$(systemctl is-active "$svc" 2>/dev/null)
        if [ "$NEW_STATUS" = "active" ]; then
            log_to_panel "WARNING" "Service auto-restarted: ${svc}" "Was ${STATUS}, now active"
            RESTARTED=$((RESTARTED + 1))
        else
            log_to_panel "ERROR" "Service failed to start: ${svc}" "Was ${STATUS}, still ${NEW_STATUS} after restart attempt"
            FAILED=$((FAILED + 1))
        fi
    fi
done

if [ $RESTARTED -gt 0 ] || [ $FAILED -gt 0 ]; then
    echo "Monitor: ${RESTARTED} restarted, ${FAILED} failed"
fi
