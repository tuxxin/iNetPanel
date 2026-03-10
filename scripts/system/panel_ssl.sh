#!/bin/bash
# ==============================================================================
# panel_ssl.sh — Issue/apply SSL certificates for iNetPanel services
#   - lighttpd (admin/client panel, port 80 → 443)
#   - Apache (phpMyAdmin, port 8888 → 8443)
# Usage: panel_ssl.sh <hostname>
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
SCRIPTS_DIR="/root/scripts"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

HOSTNAME="$1"
[ -z "$HOSTNAME" ] && { echo -e "${RED}Usage: panel_ssl.sh <hostname>${NC}"; exit 1; }

CERT_DIR="/etc/letsencrypt/live/${HOSTNAME}"

# Try Let's Encrypt first (via Cloudflare DNS-01)
echo -e "${BOLD}Attempting Let's Encrypt certificate for ${HOSTNAME}...${NC}"
bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$HOSTNAME" 2>&1

# If LE failed or cert doesn't exist, fall back to self-signed
if [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    echo -e "${YELLOW}Let's Encrypt failed. Generating self-signed certificate...${NC}"
    bash "$SCRIPTS_DIR/ssl_manage.sh" issue "$HOSTNAME" --self-signed 2>&1
fi

if [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    echo -e "${RED}Certificate files not found. SSL setup aborted.${NC}"
    exit 1
fi

# Ensure www-data can read cert files (for SSL status page)
chmod 755 /etc/letsencrypt/archive /etc/letsencrypt/live 2>/dev/null

# Create combined PEM for lighttpd (requires cert+key in one file)
LIGHTTPD_PEM="/etc/lighttpd/ssl/${HOSTNAME}.pem"
mkdir -p /etc/lighttpd/ssl
cat "$CERT_DIR/fullchain.pem" "$CERT_DIR/privkey.pem" > "$LIGHTTPD_PEM"
chmod 600 "$LIGHTTPD_PEM"
echo -e "  Created lighttpd PEM: ${LIGHTTPD_PEM}"

# ── Configure lighttpd for SSL ──────────────────────────────────────────────
LIGHTTPD_CONF="/etc/lighttpd/lighttpd.conf"

# Check if SSL is already configured
if grep -q 'ssl.engine' "$LIGHTTPD_CONF" 2>/dev/null; then
    echo -e "${YELLOW}lighttpd SSL already configured, updating certificate path...${NC}"
    sed -i "s|ssl\.pemfile.*=.*|ssl.pemfile = \"${LIGHTTPD_PEM}\"|" "$LIGHTTPD_CONF"
    # Ensure mod_redirect is loaded
    if ! grep -q 'mod_redirect' "$LIGHTTPD_CONF" 2>/dev/null; then
        sed -i 's/server.modules = (/server.modules = (\n    "mod_redirect",/' "$LIGHTTPD_CONF"
    fi
else
    echo -e "${BOLD}Configuring lighttpd SSL...${NC}"
    # Add mod_openssl and mod_redirect to modules
    sed -i 's/server.modules = (/server.modules = (\n    "mod_openssl",\n    "mod_redirect",/' "$LIGHTTPD_CONF"

    # Add SSL config block
    cat >> "$LIGHTTPD_CONF" <<SSLEOF

# --- SSL Configuration (managed by iNetPanel) ---
\$SERVER["socket"] == ":443" {
    ssl.engine  = "enable"
    ssl.pemfile = "${LIGHTTPD_PEM}"
    ssl.openssl.ssl-conf-cmd = ("MinProtocol" => "TLSv1.2")
}

# Redirect HTTP to HTTPS
\$HTTP["scheme"] == "http" {
    url.redirect = ("" => "https://\${url.authority}\${url.path}\${qsa}")
}
SSLEOF
fi

# Verify config and reload
if lighttpd -t -f "$LIGHTTPD_CONF" > /dev/null 2>&1; then
    systemctl reload lighttpd
    echo -e "${GREEN}lighttpd SSL configured and reloaded.${NC}"
else
    echo -e "${RED}lighttpd config test failed. Reverting...${NC}"
    # Remove the SSL block we just added
    sed -i '/# --- SSL Configuration/,$ d' "$LIGHTTPD_CONF"
    sed -i '/"mod_openssl",/d' "$LIGHTTPD_CONF"
    systemctl reload lighttpd
    exit 1
fi

# ── Configure Apache (phpMyAdmin) for SSL ───────────────────────────────────
# Remove Apache Listen 443 — port 443 belongs to lighttpd
sed -i '/Listen 443/d' /etc/apache2/ports.conf 2>/dev/null

PMA_CONF="/etc/apache2/sites-available/phpmyadmin.conf"

if grep -q 'SSLEngine' "$PMA_CONF" 2>/dev/null; then
    echo -e "${YELLOW}Apache/PMA SSL already configured, updating certificate paths...${NC}"
    sed -i "s|SSLCertificateFile.*|SSLCertificateFile ${CERT_DIR}/fullchain.pem|" "$PMA_CONF"
    sed -i "s|SSLCertificateKeyFile.*|SSLCertificateKeyFile ${CERT_DIR}/privkey.pem|" "$PMA_CONF"
else
    echo -e "${BOLD}Configuring Apache/PMA SSL on port 8443...${NC}"

    # Enable SSL module
    a2enmod ssl > /dev/null 2>&1

    # Add Listen 8443 to ports if not present
    if ! grep -q 'Listen 8443' /etc/apache2/ports.conf 2>/dev/null; then
        echo "Listen 8443" >> /etc/apache2/ports.conf
    fi

    # Add SSL vhost block to existing config
    cat >> "$PMA_CONF" <<PMAEOF

<VirtualHost *:8443>
    ServerAdmin webmaster@localhost
    DocumentRoot /usr/share/phpmyadmin

    SSLEngine on
    SSLCertificateFile ${CERT_DIR}/fullchain.pem
    SSLCertificateKeyFile ${CERT_DIR}/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.5-fpm.sock|fcgi://localhost"
    </FilesMatch>

    <Directory /usr/share/phpmyadmin>
        Options FollowSymLinks
        DirectoryIndex index.php
        AllowOverride All
        Require all granted
        LimitRequestBody 104857600
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/phpmyadmin_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/phpmyadmin_ssl_access.log combined
</VirtualHost>
PMAEOF
fi

if apache2ctl configtest > /dev/null 2>&1; then
    systemctl reload apache2
    echo -e "${GREEN}Apache/PMA SSL configured on port 8443 and reloaded.${NC}"
else
    echo -e "${RED}Apache config test failed. Check ${PMA_CONF}.${NC}"
    exit 1
fi

# ── Open HTTPS ports in firewall ───────────────────────────────────────────
if command -v firewall-cmd &>/dev/null; then
    ZONE=$(firewall-cmd --get-default-zone 2>/dev/null)
    [ -z "$ZONE" ] && ZONE="public"
    firewall-cmd --zone="$ZONE" --add-port=443/tcp --permanent 2>/dev/null
    firewall-cmd --zone="$ZONE" --add-port=8443/tcp --permanent 2>/dev/null
    firewall-cmd --reload 2>/dev/null
    echo -e "  Firewall: ports 443 and 8443 opened (zone: ${ZONE})"
fi

# ── Update MOTD with HTTPS links ──────────────────────────────────────────
B=$'\033[1;34m'; W=$'\033[1;37m'; G=$'\033[1;32m'; D=$'\033[2m'; N=$'\033[0m'
SERVER_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
cat > /etc/motd << MOTD_END

  ${B}░▒▓${N}${W}█${N}${B}▓▒░${N}  ${W}i N e t P a n e l${N}  ${B}░▒▓${N}${W}█${N}${B}▓▒░${N}
             ${D}by Tuxxin.com${N}

  ${D}───────────────────────────────────────${N}

  Server IP:      ${G}${SERVER_IP}${N}
  Admin Panel:    ${G}https://${HOSTNAME}/admin${N}
  Client Portal:  ${G}https://${HOSTNAME}/user${N}
  phpMyAdmin:     ${G}https://${HOSTNAME}:8443${N}

  ${D}───────────────────────────────────────${N}
  Run  ${W}inetp --help${N}  for CLI commands
  ${D}───────────────────────────────────────${N}

MOTD_END
echo -e "  MOTD updated with HTTPS links"

echo ""
echo -e "${GREEN}SSL setup complete for ${HOSTNAME}.${NC}"
echo -e "  Panel:      https://${HOSTNAME}/"
echo -e "  phpMyAdmin: https://${HOSTNAME}:8443/"
