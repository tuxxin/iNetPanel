#!/bin/bash
# ==============================================================================
# ssl_migrate.sh — Migrate existing HTTP-only accounts to HTTPS
# Issues Let's Encrypt certs for all hosted domains, rewrites Apache vhosts
# to SSL, and updates Cloudflare tunnel to use HTTPS origins.
# Usage: ssl_migrate.sh [--dry-run]
# ==============================================================================

SCRIPTS_DIR="/root/scripts"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

DRY_RUN=0
[ "$1" = "--dry-run" ] && DRY_RUN=1

# Ensure Apache SSL module is enabled
a2enmod ssl 2>/dev/null

MIGRATED=0
FAILED=0

for VHOST_CONF in /etc/apache2/sites-available/*.conf; do
    DOMAIN=$(basename "$VHOST_CONF" .conf)
    [[ "$DOMAIN" == "000-default" || "$DOMAIN" == "phpmyadmin" ]] && continue

    # Check if vhost already has SSL
    if grep -q "SSLEngine on" "$VHOST_CONF" 2>/dev/null; then
        echo -e "  ${GREEN}${DOMAIN}${NC}: Already SSL-enabled, skipping."
        continue
    fi

    PORT=$(grep '<VirtualHost' "$VHOST_CONF" | grep -oP '(?<=:)\d+')
    [ -z "$PORT" ] && continue

    echo -e "${BOLD}Migrating: ${DOMAIN} (port ${PORT})${NC}"

    if [ "$DRY_RUN" -eq 1 ]; then
        echo "  [DRY RUN] Would issue cert, rewrite vhost, update tunnel"
        continue
    fi

    # 1. Issue SSL certificate
    bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$DOMAIN"
    SSL_CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    SSL_KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"

    if [ ! -f "$SSL_CERT" ]; then
        echo -e "  ${RED}Certificate not available, skipping vhost rewrite.${NC}"
        FAILED=$((FAILED + 1))
        continue
    fi

    # 2. Rewrite Apache vhost to include SSL directives
    # Insert SSLEngine directives after ServerName line
    if ! grep -q "SSLEngine" "$VHOST_CONF"; then
        sed -i "/ServerName/a\\
    SSLEngine on\\
    SSLCertificateFile    ${SSL_CERT}\\
    SSLCertificateKeyFile ${SSL_KEY}" "$VHOST_CONF"
    fi

    echo -e "  ${GREEN}Vhost updated with SSL.${NC}"

    # 3. Update Cloudflare tunnel origin from http to https
    if command -v sqlite3 &>/dev/null && [ -f "$PANEL_DB" ]; then
        CF_ENABLED=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='cf_enabled'" 2>/dev/null)
        if [ "$CF_ENABLED" = "1" ]; then
            TUNNEL_ID=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='cf_tunnel_id'" 2>/dev/null)
            ACCOUNT_ID=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='cf_account_id'" 2>/dev/null)
            if [ -n "$TUNNEL_ID" ] && [ -n "$ACCOUNT_ID" ]; then
                # Use PHP to update tunnel config via CloudflareAPI
                php -r "
                    require '/var/www/inetpanel/TiCore/Config.php';
                    require '/var/www/inetpanel/TiCore/DB.php';
                    require '/var/www/inetpanel/TiCore/CloudflareAPI.php';
                    \$cf = new CloudflareAPI();
                    try {
                        \$cf->addTunnelHostname('${ACCOUNT_ID}', '${TUNNEL_ID}', '${DOMAIN}', 'https://localhost:${PORT}');
                        echo '  Tunnel updated to HTTPS origin.' . PHP_EOL;
                    } catch (Throwable \$e) {
                        echo '  Tunnel update failed: ' . \$e->getMessage() . PHP_EOL;
                    }
                " 2>/dev/null
            fi
        fi
    fi

    MIGRATED=$((MIGRATED + 1))
done

# Reload Apache to apply all vhost changes
systemctl reload apache2

echo ""
echo -e "${BOLD}Migration complete.${NC}"
echo -e "  Migrated: ${GREEN}${MIGRATED}${NC}"
echo -e "  Failed:   ${RED}${FAILED}${NC}"
