#!/bin/bash
# ==============================================================================
# ssl_manage.sh — Let's Encrypt SSL certificate management via Cloudflare DNS-01
# Subcommands:
#   issue   <domain>              Issue a certificate for a domain
#   revoke  <domain>              Revoke and delete a certificate
#   renew                         Renew all certificates
#   status  [domain]              Show certificate status (all or specific)
#   write-credentials <token>     Write Cloudflare API credentials file
# ==============================================================================

CRED_FILE="/root/.cloudflare-credentials"
PANEL_DB="/var/www/inetpanel/db/inetpanel.db"

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

COMMAND="$1"; shift

# Get admin email from panel DB or fall back to a default
get_admin_email() {
    local email
    email=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='admin_email'" 2>/dev/null)
    [ -z "$email" ] && email="admin@$(hostname -f 2>/dev/null || echo 'localhost')"
    echo "$email"
}

# Ensure Cloudflare credentials file exists
ensure_credentials() {
    if [ ! -f "$CRED_FILE" ]; then
        # Try to read from panel DB
        local token
        token=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='cf_api_key'" 2>/dev/null)
        if [ -z "$token" ]; then
            echo -e "${RED}No Cloudflare API credentials found.${NC}"
            echo "Run: ssl_manage.sh write-credentials <api_key>"
            exit 1
        fi
        echo "dns_cloudflare_api_key = ${token}" > "$CRED_FILE"
        local email
        email=$(sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='cf_email'" 2>/dev/null)
        [ -n "$email" ] && echo "dns_cloudflare_email = ${email}" >> "$CRED_FILE"
        chmod 600 "$CRED_FILE"
    fi
}

# Generate a self-signed fallback certificate
generate_self_signed() {
    if ! command -v openssl &>/dev/null; then
        echo -e "${RED}openssl not installed.${NC}"
        return 1
    fi
    local DOMAIN="$1"
    local CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
    mkdir -p "$CERT_DIR"
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -addext "basicConstraints=CA:FALSE" \
        -keyout "${CERT_DIR}/privkey.pem" \
        -out "${CERT_DIR}/fullchain.pem" \
        -subj "/CN=${DOMAIN}" 2>/dev/null
    echo -e "${YELLOW}Self-signed certificate generated as fallback.${NC}"
}

case "$COMMAND" in
    issue)
        DOMAIN="$1"; shift
        if ! command -v certbot &>/dev/null; then
            echo -e "${RED}certbot not installed. Install with: apt-get install certbot python3-certbot-dns-cloudflare${NC}"
            exit 1
        fi
        FORCE_SELF_SIGNED=0
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --self-signed) FORCE_SELF_SIGNED=1; shift ;;
                *) shift ;;
            esac
        done
        [ -z "$DOMAIN" ] && { echo -e "${RED}Usage: ssl_manage.sh issue <domain> [--self-signed]${NC}"; exit 1; }

        # Check if cert already exists and is valid
        if [ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
            EXPIRY=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" 2>/dev/null | cut -d= -f2)
            if [ -n "$EXPIRY" ]; then
                EXPIRY_EPOCH=$(date -d "$EXPIRY" +%s 2>/dev/null)
                NOW_EPOCH=$(date +%s)
                if [ -n "$EXPIRY_EPOCH" ] && [ "$EXPIRY_EPOCH" -gt "$NOW_EPOCH" ]; then
                    echo -e "${GREEN}Valid certificate already exists for ${DOMAIN} (expires: ${EXPIRY})${NC}"
                    exit 0
                fi
            fi
        fi

        # Self-signed only mode (no Cloudflare / no Let's Encrypt)
        if [ "$FORCE_SELF_SIGNED" -eq 1 ]; then
            echo -e "${BOLD}Generating self-signed certificate for ${DOMAIN}...${NC}"
            generate_self_signed "$DOMAIN"
            exit 0
        fi

        ensure_credentials
        EMAIL=$(get_admin_email)

        # If a self-signed cert previously failed into LE's live dir, clean it
        # so certbot can manage the directory properly
        if [ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
            ISSUER=$(openssl x509 -issuer -noout -in "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" 2>/dev/null)
            if ! echo "$ISSUER" | grep -qi "Let's Encrypt"; then
                rm -rf "/etc/letsencrypt/live/${DOMAIN}"
                rm -rf "/etc/letsencrypt/archive/${DOMAIN}"
                rm -f "/etc/letsencrypt/renewal/${DOMAIN}.conf"
            fi
        fi

        echo -e "${BOLD}Issuing SSL certificate for ${DOMAIN}...${NC}"
        certbot certonly \
            --dns-cloudflare \
            --dns-cloudflare-credentials "$CRED_FILE" \
            -d "$DOMAIN" \
            --non-interactive \
            --agree-tos \
            --email "$EMAIL" \
            --preferred-challenges dns-01 \
            --dns-cloudflare-propagation-seconds 30 \
            2>&1

        if [ $? -eq 0 ] && [ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
            # Ensure www-data can read cert files (for SSL status page)
            chmod 755 /etc/letsencrypt/archive /etc/letsencrypt/live 2>/dev/null
            echo -e "${GREEN}SSL certificate issued successfully for ${DOMAIN}.${NC}"
        else
            echo -e "${RED}Let's Encrypt certificate issuance failed. Generating self-signed fallback.${NC}"
            generate_self_signed "$DOMAIN"
        fi
        ;;

    revoke)
        DOMAIN="$1"
        [ -z "$DOMAIN" ] && { echo -e "${RED}Usage: ssl_manage.sh revoke <domain>${NC}"; exit 1; }
        if ! command -v certbot &>/dev/null; then
            echo -e "${RED}certbot not installed. Install with: apt-get install certbot python3-certbot-dns-cloudflare${NC}"
            exit 1
        fi

        if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
            # Check if it's a real LE cert or self-signed
            ISSUER=$(openssl x509 -issuer -noout -in "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" 2>/dev/null)
            if echo "$ISSUER" | grep -qi "Let's Encrypt"; then
                timeout 30 certbot revoke --cert-name "$DOMAIN" --non-interactive 2>/dev/null
            fi
            timeout 15 certbot delete --cert-name "$DOMAIN" --non-interactive 2>/dev/null
            # Clean up self-signed fallback dirs that certbot doesn't know about
            rm -rf "/etc/letsencrypt/live/${DOMAIN}" 2>/dev/null
            rm -rf "/etc/letsencrypt/archive/${DOMAIN}" 2>/dev/null
            rm -f "/etc/letsencrypt/renewal/${DOMAIN}.conf" 2>/dev/null
            echo -e "${GREEN}Certificate removed for ${DOMAIN}.${NC}"
        else
            echo -e "${YELLOW}No certificate found for ${DOMAIN}.${NC}"
        fi
        ;;

    renew)
        if ! command -v certbot &>/dev/null; then
            echo -e "${RED}certbot not installed. Install with: apt-get install certbot python3-certbot-dns-cloudflare${NC}"
            exit 1
        fi
        echo -e "${BOLD}Renewing all certificates...${NC}"
        certbot renew --quiet --deploy-hook "systemctl reload apache2" 2>&1
        echo -e "${GREEN}Certificate renewal complete.${NC}"
        ;;

    status)
        DOMAIN="$1"
        if [ -n "$DOMAIN" ]; then
            CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
            if [ -f "$CERT" ]; then
                EXPIRY=$(openssl x509 -enddate -noout -in "$CERT" 2>/dev/null | cut -d= -f2)
                ISSUER=$(openssl x509 -issuer -noout -in "$CERT" 2>/dev/null)
                TYPE="Let's Encrypt"
                echo "$ISSUER" | grep -qi "Let's Encrypt" || TYPE="Self-signed"
                echo -e "${GREEN}${DOMAIN}${NC}: ${TYPE} — expires ${EXPIRY}"
            else
                echo -e "${RED}${DOMAIN}${NC}: No certificate found"
            fi
        else
            # List all certificates
            if command -v certbot &>/dev/null; then
                certbot certificates 2>/dev/null
            fi
            # Also check for self-signed certs not managed by certbot
            for dir in /etc/letsencrypt/live/*/; do
                [ -d "$dir" ] || continue
                D=$(basename "$dir")
                CERT="${dir}fullchain.pem"
                [ -f "$CERT" ] || continue
                ISSUER=$(openssl x509 -issuer -noout -in "$CERT" 2>/dev/null)
                if ! echo "$ISSUER" | grep -qi "Let's Encrypt"; then
                    EXPIRY=$(openssl x509 -enddate -noout -in "$CERT" 2>/dev/null | cut -d= -f2)
                    echo -e "  ${YELLOW}${D}${NC}: Self-signed — expires ${EXPIRY}"
                fi
            done
        fi
        ;;

    write-credentials)
        TOKEN="$1"
        EMAIL="$2"
        [ -z "$TOKEN" ] && { echo -e "${RED}Usage: ssl_manage.sh write-credentials <api_key> [email]${NC}"; exit 1; }
        echo "dns_cloudflare_api_key = ${TOKEN}" > "$CRED_FILE"
        [ -n "$EMAIL" ] && echo "dns_cloudflare_email = ${EMAIL}" >> "$CRED_FILE"
        chmod 600 "$CRED_FILE"
        echo -e "${GREEN}Cloudflare credentials written to ${CRED_FILE}.${NC}"
        ;;

    *)
        echo -e "${BOLD}ssl_manage.sh — SSL Certificate Manager${NC}"
        echo ""
        echo "  Usage: ssl_manage.sh <command> [args]"
        echo ""
        echo "  Commands:"
        echo -e "    ${GREEN}issue${NC}   <domain>              Issue a Let's Encrypt certificate"
        echo -e "    ${GREEN}revoke${NC}  <domain>              Revoke and delete a certificate"
        echo -e "    ${GREEN}renew${NC}                         Renew all certificates"
        echo -e "    ${GREEN}status${NC}  [domain]              Show certificate status"
        echo -e "    ${GREEN}write-credentials${NC} <key> [email]  Write Cloudflare API credentials"
        echo ""
        ;;
esac
