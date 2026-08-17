#!/bin/bash

# ==============================================================================
# iNetPanel — LAMP Stack Installer
# by Tuxxin (https://tuxxin.com)
#
# Apache 2.4 + PHP 8.5 FPM, MariaDB, phpMyAdmin, vsftpd
# CLI: inetp --help  (create_user, add_domain, ssl_manage, backup, etc.)
# Crons: midnight = system updates, 3am = account backups, 4am = SSL renewal
#
# Usage:  bash install_LAMP.sh                  (interactive)
#         bash install_LAMP.sh --unattended     (auto-generate MySQL password, no prompts)
#         curl -sL <url> | bash -s -- -y        (piped unattended install)
#
# https://inetpanel.tuxxin.com
# ==============================================================================

# --- SUPPORTED OS CHECK ---
# Debian 12 (bookworm) and 13 (trixie). 12 left regular support on 2026-07-11 but
# is on LTS until June 2028, so both are supported rather than forcing a migration.
#
# OS_CODENAME is captured once here and reused for every third-party apt suite,
# so adding a future release means changing this list and nothing else.
if [[ ! -f /etc/os-release ]]; then
    echo "Error: /etc/os-release not found — cannot identify this system."
    exit 1
fi
# shellcheck disable=SC1091
. /etc/os-release
OS_CODENAME="${VERSION_CODENAME:-}"
case "${ID:-}:${VERSION_ID:-}" in
    debian:12) OS_CODENAME="${OS_CODENAME:-bookworm}" ;;
    debian:13) OS_CODENAME="${OS_CODENAME:-trixie}"  ;;
    *)
        echo "Error: iNetPanel requires Debian 12 (Bookworm) or 13 (Trixie)."
        echo "       Detected: ${PRETTY_NAME:-unknown}"
        exit 1
        ;;
esac

# --- ALREADY INSTALLED CHECK ---
if [[ -f /var/www/inetpanel/db/.installed ]]; then
    echo "iNetPanel is already installed on this system. Exiting."
    exit 0
fi

# --- CONFIGURATION ---
START_PORT=1080
SCRIPTS_DIR="/root/scripts"
LOG_FILE="/root/lamp_install.log"
CUSTOM_PORTS_CONF="/etc/apache2/ports_domains.conf"
BACKUP_DIR="/backup"
BACKUP_RETENTION_DAYS=3
PHP_VER="8.5"
PHP_FPM_SOCK_DEFAULT="/run/php/php${PHP_VER}-fpm.sock"

# --- UNATTENDED MODE ---
# Usage: bash install_LAMP.sh --unattended   (or -y)
# Skips the MySQL password prompt and auto-generates a secure password.
UNATTENDED=0
for arg in "$@"; do
    case "$arg" in
        --unattended|-y) UNATTENDED=1 ;;
    esac
done

export DEBIAN_FRONTEND=noninteractive
# Ensure sbin paths are reachable in every subshell spawned by exec_cmd
# (a clean Debian install does not include /usr/sbin in non-login shells — breaks a2enmod etc.)
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# --- Auto-detect server IP ---
SERVER_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
[ -z "$SERVER_IP" ] && SERVER_IP=$(hostname -I | awk '{print $1}')

# --- COLORS ---
BOLD='\033[1m'
RED='\033[1;31m'
GREEN='\033[1;32m'
YELLOW='\033[1;33m'
BLUE='\033[1;34m'
WHITE='\033[1;37m'
DIM='\033[2m'
NC='\033[0m'

# --- ROOT CHECK ---
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}This script must be run as root${NC}"
    exit 1
fi

# --- FRESH SERVER CHECK ---
# Abort if critical services are already installed to prevent clobbering
# an existing setup. This installer is designed for a clean Debian 12 or 13 install.
check_fresh_server() {
    local CONFLICTS=()

    command -v apache2   &>/dev/null && CONFLICTS+=("apache2")
    command -v nginx     &>/dev/null && CONFLICTS+=("nginx")
    command -v lighttpd  &>/dev/null && CONFLICTS+=("lighttpd")
    command -v mysqld    &>/dev/null && CONFLICTS+=("mysql/mariadb (mysqld)")
    command -v mariadbd  &>/dev/null && CONFLICTS+=("mariadb")
    dpkg -l mariadb-server &>/dev/null 2>&1 && CONFLICTS+=("mariadb-server (dpkg)")
    dpkg -l mysql-server   &>/dev/null 2>&1 && CONFLICTS+=("mysql-server (dpkg)")

    if [ ${#CONFLICTS[@]} -gt 0 ]; then
        echo ""
        echo -e "${RED}======================================================${NC}"
        echo -e "${RED}   ERROR: This does not appear to be a fresh server.${NC}"
        echo -e "${RED}======================================================${NC}"
        echo -e "${YELLOW}The following conflicting packages/services were detected:${NC}"
        for pkg in "${CONFLICTS[@]}"; do
            echo -e "  ${RED}✗${NC}  $pkg"
        done
        echo ""
        echo -e "This installer is designed for a ${BOLD}clean Debian 12 or 13${NC} server."
        echo -e "Running it on an existing setup may overwrite configurations"
        echo -e "or break services currently in use."
        echo ""
        echo -e "To override this check and proceed anyway (${RED}not recommended${NC}):"
        echo -e "  ${YELLOW}FORCE_INSTALL=1 bash install_LAMP.sh${NC}"
        echo ""
        exit 1
    fi
}

if [[ "${FORCE_INSTALL:-0}" != "1" ]]; then
    check_fresh_server
fi

clear
echo ""
echo -e "  ${BLUE}░▒▓${NC}${WHITE}█${NC}${BLUE}▓▒░${NC}  ${WHITE}${BOLD}i N e t P a n e l${NC}  ${BLUE}░▒▓${NC}${WHITE}█${NC}${BLUE}▓▒░${NC}"
echo -e "             ${DIM}by Tuxxin.com${NC}"
echo ""
echo -e "  ${DIM}───────────────────────────────────────${NC}"
echo -e "       ${GREEN}Web Hosting Panel Installer${NC}"
echo -e "  ${DIM}───────────────────────────────────────${NC}"
echo ""
echo -e "  Detected Server IP: ${GREEN}${SERVER_IP}${NC}"
echo -e "  Log File: $LOG_FILE"
echo "" > "$LOG_FILE"

# --- HELPER: wait for dpkg lock (avoids race between sequential apt calls) ---
wait_for_apt() {
    local max_wait=60
    local waited=0
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
        sleep 1
        waited=$((waited + 1))
        if [ $waited -ge $max_wait ]; then
            echo -e "${YELLOW}[WARN]${NC} dpkg lock held for ${max_wait}s — continuing anyway"
            break
        fi
    done
}

# --- HELPER: exec_cmd with spinner ---
exec_cmd() {
    local description="$1"
    shift
    echo -ne "${BLUE}[INFO]${NC} $description... "
    # Wait for dpkg lock if this is an apt command
    [[ "$1" == "apt-get" ]] && wait_for_apt
    "$@" >> "$LOG_FILE" 2>&1 &
    local pid=$!
    local spinstr='|/-\'
    while kill -0 $pid 2>/dev/null; do
        local temp=${spinstr#?}
        printf "[%c]" "$spinstr"
        local spinstr=$temp${spinstr%"$temp"}
        sleep 0.1
        printf "\b\b\b"
    done
    wait $pid
    local exit_code=$?
    printf "   \b\b\b"
    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}[OK]${NC}"
    else
        echo -e "${RED}[FAIL]${NC}"
        echo -e "${RED}Last 5 lines of log:${NC}"
        tail -n 5 "$LOG_FILE"
        exit 1
    fi
}

# ==============================================================================
# 0. INPUTS
# ==============================================================================
if [ "$UNATTENDED" -eq 1 ]; then
    DB_ROOT_PASS=$(openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c 64)
    echo -e "${GREEN}[UNATTENDED]${NC} Auto-generated MySQL root password → /root/.mysql_root_pass"
else
    echo ""
    echo -e "${YELLOW}MySQL Root Password Setup${NC}"
    echo "Leave blank to auto-generate a secure 64-char password."
    read -s -p "Enter Password: " INPUT_PASS < /dev/tty
    echo ""

    if [ -z "$INPUT_PASS" ]; then
        DB_ROOT_PASS=$(openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c 64)
        echo -e "${GREEN}Generated Password:${NC} $DB_ROOT_PASS"
    else
        DB_ROOT_PASS="$INPUT_PASS"
    fi
fi

echo "$DB_ROOT_PASS" > /root/.mysql_root_pass
chmod 600 /root/.mysql_root_pass

# ==============================================================================
# 1. SYSTEM PREP
# ==============================================================================

# Set Cloudflare DNS as primary nameserver
echo -e "nameserver 1.1.1.1\nnameserver 1.0.0.1" > /etc/resolv.conf

echo -ne "${BLUE}[INFO]${NC} Updating Repository Lists... "
if apt-get update -q >> "$LOG_FILE" 2>&1; then
    echo -e "${GREEN}[OK]${NC}"
else
    echo -e "${YELLOW}[WARN]${NC} Some repository updates failed (see log). Continuing..."
fi
exec_cmd "Upgrading Existing Packages"     apt-get upgrade -y -qq
# bind9-dnsutils, not dnsutils: `dnsutils` was a transitional package and is GONE
#   from trixie. It exists in both 12 and 13 under the real name, so no conditional.
#   This was the first thing to fail on Debian 13 — before anything was installed.
# psmisc: wait_for_apt() calls `fuser`, which nothing else pulls in early enough.
# gnupg (not gnupg2): gnupg2 is a transitional dummy; `gpg --dearmor` needs the real one.
# Dropped apt-transport-https (a no-op since apt 1.5) and lsb-release (never invoked —
#   the codename now comes from /etc/os-release in the OS check above).
exec_cmd "Installing Base Dependencies"    apt-get install -y -qq \
    curl rsync wget unzip gnupg debconf-utils ca-certificates cron \
    bind9-dnsutils psmisc python3-pam pamtester sudo

# ==============================================================================
# 1a. PHP 8.5 REPOSITORY (sury.org)
#     Debian ships an older PHP (8.2 on bookworm, 8.4 on trixie) — sury provides ${PHP_VER}
# ==============================================================================
add_php_repo() {
    # Must track the running release. sury publishes a suite per Debian release, and
    # both exist — so pointing trixie at the bookworm suite does NOT fail at
    # `apt update`. It fails later and confusingly: sury's bookworm build depends on
    # libssl3, which trixie does not have (it has libssl3t64), so the PHP install
    # below dies on unsatisfiable dependencies instead.
    # /etc/apt/keyrings is the correct home for third-party keys; /usr/share/keyrings
    # is reserved for distro-shipped ones. The dir is not guaranteed to exist.
    install -d -m 0755 /etc/apt/keyrings
    # -f so a failed fetch is an error rather than a valid-looking empty keyring,
    # which would surface later as an unhelpful "repository is not signed".
    curl -fsSL https://packages.sury.org/php/apt.gpg \
        | gpg --dearmor -o /etc/apt/keyrings/deb.sury.org-php.gpg || return 1
    echo "deb [signed-by=/etc/apt/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
        > /etc/apt/sources.list.d/php.list
    apt-get update -q
}
exec_cmd "Adding PHP ${PHP_VER} Repository (sury.org)" add_php_repo

# Pin PHP so nothing drags in a second stack alongside the one we install.
# Allows patch updates within the chosen version (8.5.x → 8.5.y).
#
# Two separate hazards:
#  1. A future major (8.6, 9.x) auto-upgrading us.
#  2. The DISTRO's own PHP arriving as a dependency. phpmyadmin Depends on
#     php-cli/php-mysql/php-xml/php-mbstring, and those resolve to a real versioned
#     stack — 8.2 on bookworm, 8.4 on trixie. Two complete PHP stacks then coexist,
#     /usr/bin/php may point at the wrong one via update-alternatives, and
#     phpenmod/phpdismod become ambiguous.
#
# Built dynamically so the blocklist can never contain PHP_VER itself — hardcoding
# "php8.4*" would silently blocklist the panel's own PHP the day PHP_VER becomes 8.4.
BLOCK_VERS=""
for _v in 8.2 8.3 8.4 8.6 9.0 9.1 9.2; do
    [ "$_v" = "$PHP_VER" ] && continue
    BLOCK_VERS="${BLOCK_VERS}${BLOCK_VERS:+ }php${_v}*"
done
cat > /etc/apt/preferences.d/php << PINEOF
# Prefer PHP ${PHP_VER} packages — installed by iNetPanel
Package: php${PHP_VER}*
Pin: origin packages.sury.org
Pin-Priority: 600

# Block every other PHP version, from ANY origin (no origin restriction here —
# it has to catch Debian's copies as well as sury's).
Package: ${BLOCK_VERS}
Pin: release *
Pin-Priority: -1
PINEOF
unset _v


# ==============================================================================
# 2. APACHE
# ==============================================================================
exec_cmd "Installing Apache2" apt-get install -y -qq apache2

enable_apache_modules() {
    /usr/sbin/a2enmod rewrite proxy proxy_fcgi setenvif headers ssl
}
exec_cmd "Enabling Apache Modules (rewrite, proxy, proxy_fcgi, headers)" enable_apache_modules

# Force HTTP/1.1 on the Cloudflare -> Apache origin hop so cloudflared cannot
# coalesce HTTP/2 streams across name-based vhosts that share a TLS port (which
# serves one site's sitemap.xml/content under another domain). Visitors keep
# HTTP/2+3 from Cloudflare's edge; only the origin hop drops to HTTP/1.1, which
# routes strictly by Host. Global server-scope conf inherited by every vhost.
harden_apache_origin() {
    cat > /etc/apache2/conf-available/inetpanel-origin.conf << 'OCONF'
# iNetPanel origin-hop hardening - auto-managed (install + panel_update).
# Forces the Cloudflare->Apache origin hop to HTTP/1.1 so HTTP/2 connection
# coalescing cannot serve one vhost's content under another domain.
# Visitors still get HTTP/2/3 from Cloudflare; only the origin hop is 1.1.
# Do not edit; this file is overwritten on panel updates.
Protocols http/1.1
OCONF
    /usr/sbin/a2enconf inetpanel-origin
}
exec_cmd "Hardening Apache origin (force HTTP/1.1, stop HTTP/2 vhost coalescing)" harden_apache_origin

if [ ! -f "$CUSTOM_PORTS_CONF" ]; then
    touch "$CUSTOM_PORTS_CONF"
    if ! grep -q "ports_domains.conf" /etc/apache2/apache2.conf; then
        echo "" >> /etc/apache2/apache2.conf
        echo "Include $CUSTOM_PORTS_CONF" >> /etc/apache2/apache2.conf
    fi
fi

systemctl enable apache2 >> "$LOG_FILE" 2>&1

# ==============================================================================
# 3. MARIADB
# ==============================================================================
exec_cmd "Installing MariaDB Server" apt-get install -y -qq mariadb-server
systemctl enable mariadb >> "$LOG_FILE" 2>&1

secure_mariadb() {
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_ROOT_PASS');"
    mysql -u root -p"$DB_ROOT_PASS" -e "DELETE FROM mysql.user WHERE User='';"
    mysql -u root -p"$DB_ROOT_PASS" -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
    mysql -u root -p"$DB_ROOT_PASS" -e "DROP DATABASE IF EXISTS test;"
    mysql -u root -p"$DB_ROOT_PASS" -e "FLUSH PRIVILEGES;"
}
exec_cmd "Securing MariaDB" secure_mariadb

bind_mariadb_localhost() {
    local CONF="/etc/mysql/mariadb.conf.d/50-server.cnf"
    if grep -q '^bind-address' "$CONF"; then
        sed -i 's/^bind-address.*/bind-address = 127.0.0.1/' "$CONF"
    else
        sed -i '/^\[mysqld\]/a bind-address = 127.0.0.1' "$CONF"
    fi
    systemctl restart mariadb
}
exec_cmd "Binding MariaDB to localhost only (127.0.0.1)" bind_mariadb_localhost

# ==============================================================================
# 4. PHP 8.5 + EXTENSIONS
# ==============================================================================
exec_cmd "Installing PHP ${PHP_VER} and Extensions" apt-get install -y -qq \
    php${PHP_VER}-bcmath   \
    php${PHP_VER}-bz2      \
    php${PHP_VER}-cli      \
    php${PHP_VER}-common   \
    php${PHP_VER}-curl     \
    php${PHP_VER}-fpm      \
    php${PHP_VER}-gd       \
    php${PHP_VER}-gmp      \
    php${PHP_VER}-imagick  \
    php${PHP_VER}-imap     \
    php${PHP_VER}-intl     \
    php${PHP_VER}-mbstring \
    php${PHP_VER}-mysql    \
    php${PHP_VER}-readline \
    php${PHP_VER}-sqlite3  \
    php${PHP_VER}-xml      \
    php${PHP_VER}-zip      \
    composer

# Relax php-fpm's ProtectSystem so the panel's sudo-spawned root helpers can write
# /etc (useradd, apache vhosts, php-fpm pools, vsftpd.userlist, letsencrypt). The
# default unit sets ProtectSystem=full -> /etc read-only for php-fpm's process tree,
# and sudo does not escape that namespace, so account/domain creation would fail.
# Created before first start so the namespace is correct. (ReadWritePaths=/etc does
# NOT work for top-level /etc; ProtectSystem=true keeps /usr and /boot read-only.)
mkdir -p "/etc/systemd/system/php${PHP_VER}-fpm.service.d"
cat > "/etc/systemd/system/php${PHP_VER}-fpm.service.d/inetpanel-etc-writable.conf" << 'FPMDROP'
# iNetPanel: relax ProtectSystem so panel root helpers can write /etc
# (useradd, apache vhosts, php-fpm pools, vsftpd.userlist, letsencrypt).
# Auto-managed; do not edit.
[Service]
ProtectSystem=true
FPMDROP
systemctl daemon-reload >> "$LOG_FILE" 2>&1

systemctl enable php${PHP_VER}-fpm >> "$LOG_FILE" 2>&1
systemctl start  php${PHP_VER}-fpm >> "$LOG_FILE" 2>&1

# Set upload limits to match the Cloudflare 100 MB cap
configure_php_limits() {
    local ini="/etc/php/${PHP_VER}/fpm/php.ini"
    sed -i 's/^upload_max_filesize\s*=.*/upload_max_filesize = 100M/' "$ini"
    sed -i 's/^post_max_size\s*=.*/post_max_size = 100M/'             "$ini"
    systemctl reload "php${PHP_VER}-fpm"
}
exec_cmd "Configuring PHP upload limits (100 MB)" configure_php_limits

# ==============================================================================
# 5. PHPMYADMIN
# ==============================================================================
configure_pma_debconf() {
    echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    # Leave webserver field blank — Apache is configured manually below
    echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections
}
exec_cmd "Pre-configuring phpMyAdmin" configure_pma_debconf
exec_cmd "Installing phpMyAdmin"      apt-get install -y -qq phpmyadmin

PMA_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9')
PMA_DB="phpmyadmin"

build_pma_storage() {
    mysql -u root -p"$DB_ROOT_PASS" -e "CREATE DATABASE IF NOT EXISTS ${PMA_DB};"
    if [ -f /usr/share/doc/phpmyadmin/examples/create_tables.sql.gz ]; then
        zcat /usr/share/doc/phpmyadmin/examples/create_tables.sql.gz \
            | mysql -u root -p"$DB_ROOT_PASS" "${PMA_DB}"
    fi
    mysql -u root -p"$DB_ROOT_PASS" <<EOF
CREATE USER IF NOT EXISTS 'phpmyadmin'@'localhost' IDENTIFIED BY '${PMA_PASS}';
ALTER USER 'phpmyadmin'@'localhost' IDENTIFIED BY '${PMA_PASS}';
GRANT ALL PRIVILEGES ON ${PMA_DB}.* TO 'phpmyadmin'@'localhost';
GRANT SELECT ON mysql.user TO 'phpmyadmin'@'localhost';
GRANT SELECT ON mysql.db TO 'phpmyadmin'@'localhost';
GRANT SELECT ON mysql.tables_priv TO 'phpmyadmin'@'localhost';
FLUSH PRIVILEGES;
EOF
}
exec_cmd "Building phpMyAdmin Storage Database" build_pma_storage

write_pma_config() {
    cat << EOF > /etc/phpmyadmin/conf.d/pma_secure.php
<?php
\$cfg['Servers'][1]['auth_type']         = 'cookie';
\$cfg['Servers'][1]['controluser']       = 'phpmyadmin';
\$cfg['Servers'][1]['controlpass']       = '${PMA_PASS}';
\$cfg['Servers'][1]['pmadb']             = 'phpmyadmin';
\$cfg['Servers'][1]['bookmarktable']     = 'pma__bookmark';
\$cfg['Servers'][1]['relation']          = 'pma__relation';
\$cfg['Servers'][1]['table_info']        = 'pma__table_info';
\$cfg['Servers'][1]['table_coords']      = 'pma__table_coords';
\$cfg['Servers'][1]['pdf_pages']         = 'pma__pdf_pages';
\$cfg['Servers'][1]['column_info']       = 'pma__column_info';
\$cfg['Servers'][1]['history']           = 'pma__history';
\$cfg['Servers'][1]['table_uiprefs']     = 'pma__table_uiprefs';
\$cfg['Servers'][1]['tracking']          = 'pma__tracking';
\$cfg['Servers'][1]['userconfig']        = 'pma__userconfig';
\$cfg['Servers'][1]['recent']            = 'pma__recent';
\$cfg['Servers'][1]['favorite']          = 'pma__favorite';
\$cfg['Servers'][1]['users']             = 'pma__users';
\$cfg['Servers'][1]['usergroups']        = 'pma__usergroups';
\$cfg['Servers'][1]['navigationhiding']  = 'pma__navigationhiding';
\$cfg['Servers'][1]['savedsearches']     = 'pma__savedsearches';
\$cfg['Servers'][1]['central_columns']   = 'pma__central_columns';
\$cfg['Servers'][1]['designer_settings'] = 'pma__designer_settings';
\$cfg['Servers'][1]['export_templates']  = 'pma__export_templates';
EOF
}
exec_cmd "Writing phpMyAdmin Config" write_pma_config

configure_apache_pma() {
    # phpMyAdmin on port 8888 — lighttpd owns port 80 for the panel
    # Remove default Listen 80 (lighttpd will own port 80)
    sed -i 's/^Listen 80$/# Listen 80 (lighttpd)/' /etc/apache2/ports.conf 2>/dev/null || true
    # Add port 8888
    if ! grep -q '^Listen 8888' /etc/apache2/ports.conf; then
        echo "Listen 8888" >> /etc/apache2/ports.conf
    fi
    # Disable default vhost (was on port 80)
    /usr/sbin/a2dissite 000-default 2>/dev/null || true
    [ -f /etc/apache2/conf-enabled/phpmyadmin.conf ] && /usr/sbin/a2disconf phpmyadmin 2>/dev/null || true

    cat << EOF > /etc/apache2/sites-available/phpmyadmin.conf
<VirtualHost *:8888>
    ServerAdmin webmaster@localhost
    DocumentRoot /usr/share/phpmyadmin

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:${PHP_FPM_SOCK_DEFAULT}|fcgi://localhost"
    </FilesMatch>

    <Directory /usr/share/phpmyadmin>
        Options FollowSymLinks
        DirectoryIndex index.php
        AllowOverride All
        Require all granted
        LimitRequestBody 104857600
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/phpmyadmin_error.log
    CustomLog \${APACHE_LOG_DIR}/phpmyadmin_access.log combined
</VirtualHost>
EOF
    /usr/sbin/a2ensite phpmyadmin.conf > /dev/null 2>&1
    systemctl reload apache2
}
exec_cmd "Configuring Apache for phpMyAdmin (Port 8888, PHP-FPM)" configure_apache_pma

# ==============================================================================
# 5b. LIGHTTPD — iNetPanel admin panel web server (Port 80)
# ==============================================================================
exec_cmd "Installing lighttpd" apt-get install -y -qq lighttpd

configure_lighttpd() {
    mkdir -p /var/www/inetpanel/public
    chown -R www-data:www-data /var/www/inetpanel

    cat << 'LCONF' > /etc/lighttpd/lighttpd.conf
server.modules = (
    "mod_fastcgi",
    "mod_rewrite",
    "mod_access",
    "mod_accesslog",
    "mod_setenv",
    "mod_openssl",
    "mod_redirect"
)

server.document-root = "/var/www/inetpanel/public"
server.port          = 80
server.username      = "www-data"
server.groupname     = "www-data"
server.errorlog      = "/var/log/lighttpd/error.log"
server.pid-file      = "/run/lighttpd.pid"

index-file.names = ( "index.php" )
url.access-deny  = ( "~", ".inc", ".htaccess" )
static-file.exclude-extensions = ( ".php" )

mimetype.assign = (
    ".html"  => "text/html",
    ".htm"   => "text/html",
    ".css"   => "text/css",
    ".js"    => "application/javascript",
    ".json"  => "application/json",
    ".png"   => "image/png",
    ".jpg"   => "image/jpeg",
    ".jpeg"  => "image/jpeg",
    ".gif"   => "image/gif",
    ".ico"   => "image/x-icon",
    ".svg"   => "image/svg+xml",
    ".woff"  => "font/woff",
    ".woff2" => "font/woff2",
    ".ttf"   => "font/ttf",
    ".txt"   => "text/plain"
)

# PHP 8.5 via FPM (default www pool, runs as www-data)
fastcgi.server += (
    ".php" => ((
        "socket"      => "@PHP_FPM_SOCK@",
        "check-local" => "disable",
    ))
)

# Route all non-file requests through index.php (TiCore router)
# Files that physically exist (assets/, install.php) are served directly
url.rewrite-if-not-file = ( "^" => "/index.php" )

accesslog.filename = "/var/log/lighttpd/access.log"
LCONF

    # The heredoc above is quoted ('LCONF') so lighttpd's own $var syntax survives
    # verbatim — which also means ${PHP_VER} cannot expand inside it. The socket is
    # therefore written as a placeholder and substituted here. It used to be the
    # literal "php8.5-fpm.sock": changing PHP_VER left lighttpd pointing at a socket
    # that never gets created, and the panel 503s on port 80 while every other
    # service looks healthy.
    sed -i "s|@PHP_FPM_SOCK@|${PHP_FPM_SOCK_DEFAULT}|" /etc/lighttpd/lighttpd.conf
    if grep -q '@PHP_FPM_SOCK@' /etc/lighttpd/lighttpd.conf; then
        echo "  ERROR: failed to set the PHP-FPM socket in lighttpd.conf"
        return 1
    fi

    # --- TLS from the very first request ------------------------------------
    # The setup wizard at /install.php collects the admin password AND, a few
    # steps later, the Cloudflare API key. Serving that over plaintext HTTP put
    # both on the wire, and there was no HTTPS option at all until someone ran
    # panel_ssl.sh by hand — which is after the credentials have been sent.
    #
    # A self-signed certificate generated here is not trusted by browsers, but it
    # encrypts the setup wizard, which is the part that matters. panel_ssl.sh can
    # replace it with a real Let's Encrypt certificate later; it writes the same
    # $SERVER["socket"] == ":443" block, so the shapes match.
    # Port 443 belongs to lighttpd (the panel). Apache's ssl module ships a
    # `Listen 443` in ports.conf and grabs it first, so lighttpd's bind fails with
    # status 255 — and it fails at BIND time, not config-parse time, so
    # `lighttpd -tt` still passes and the failure only shows up as a dead service.
    # panel_ssl.sh already does this; the installer has to do it too now that TLS
    # is configured up front. Per-domain vhosts use their own high ports.
    if grep -qE '^\s*Listen\s+443' /etc/apache2/ports.conf 2>/dev/null; then
        sed -i '/^\s*Listen\s\+443/d' /etc/apache2/ports.conf
        if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
            systemctl reload apache2 2>/dev/null
        fi
    fi

    mkdir -p /etc/lighttpd/ssl
    if [ ! -s /etc/lighttpd/ssl/panel.pem ]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
            -keyout /etc/lighttpd/ssl/panel.key \
            -out    /etc/lighttpd/ssl/panel.crt \
            -subj   "/CN=${SERVER_IP}" \
            -addext "subjectAltName=IP:${SERVER_IP},DNS:$(hostname -f 2>/dev/null || hostname)" \
            >/dev/null 2>&1
        # lighttpd wants key and cert concatenated in one pemfile.
        cat /etc/lighttpd/ssl/panel.key /etc/lighttpd/ssl/panel.crt \
            > /etc/lighttpd/ssl/panel.pem
        chmod 600 /etc/lighttpd/ssl/panel.pem /etc/lighttpd/ssl/panel.key
    fi

    cat >> /etc/lighttpd/lighttpd.conf << 'SSLCONF'

# --- SSL Configuration (managed by iNetPanel) ---
$SERVER["socket"] == ":443" {
    ssl.engine  = "enable"
    ssl.pemfile = "/etc/lighttpd/ssl/panel.pem"
    ssl.openssl.ssl-conf-cmd = ("MinProtocol" => "TLSv1.2")
}

# Redirect HTTP to HTTPS so the setup wizard is never served in the clear.
$HTTP["scheme"] == "http" {
    url.redirect = ("" => "https://${url.authority}${url.path}${qsa}")
}
SSLCONF

    # Validate before restarting — a bad config here aborts the whole installer.
    if ! lighttpd -tt -f /etc/lighttpd/lighttpd.conf >/dev/null 2>&1; then
        echo "  ERROR: generated lighttpd.conf failed validation:"
        lighttpd -tt -f /etc/lighttpd/lighttpd.conf 2>&1 | sed 's/^/    /'
        return 1
    fi

    systemctl enable lighttpd
    systemctl restart lighttpd
}
exec_cmd "Configuring lighttpd (TLS on 443, iNetPanel)" configure_lighttpd

# ==============================================================================
# 6. VSFTPD
#    Fixes: chroot to home dir, www-data group ownership on new files (via
#    primary group), whitelist-only logins, umask 022 (files=644, dirs=755)
# ==============================================================================
exec_cmd "Installing VSFTPD" apt-get install -y -qq vsftpd

configure_vsftpd() {
    cp /etc/vsftpd.conf /etc/vsftpd.conf.bak
    # Whitelist file — accounts are added/removed by create/delete_account.sh
    touch /etc/vsftpd.userlist
    cat << 'EOF' > /etc/vsftpd.conf
listen=NO
listen_ipv6=YES
anonymous_enable=NO
local_enable=YES
write_enable=YES
dirmessage_enable=YES
use_localtime=YES
xferlog_enable=YES
connect_from_port_20=YES

# Chroot each user to their own home directory
chroot_local_user=YES
allow_writeable_chroot=YES
secure_chroot_dir=/var/run/vsftpd/empty

# Whitelist mode: only users listed in userlist_file can log in
# (userlist_deny=NO means the list is an allow-list, not a deny-list)
userlist_enable=YES
userlist_deny=NO
userlist_file=/etc/vsftpd.userlist

# New files = 644, new dirs = 755
# Users have www-data as their primary group so all new files are group www-data
local_umask=022

# Passive mode port range (must match firewall rules)
pasv_min_port=40000
pasv_max_port=50000

# Connection limits and timeouts
max_clients=200
max_per_ip=20
data_connection_timeout=600
idle_session_timeout=600

pam_service_name=vsftpd
rsa_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem
rsa_private_key_file=/etc/ssl/private/ssl-cert-snakeoil.key
ssl_enable=NO
EOF
    systemctl enable vsftpd
    systemctl restart vsftpd
}
exec_cmd "Configuring VSFTPD (Chroot + Whitelist + umask 022)" configure_vsftpd

# ==============================================================================
# 6b. SSH — Change default port to 1022 for security
# ==============================================================================
configure_ssh() {
    local SSHD_CONF="/etc/ssh/sshd_config"
    if grep -qE '^Port ' "$SSHD_CONF"; then
        sed -i 's/^Port .*/Port 1022/' "$SSHD_CONF"
    elif grep -qE '^#Port ' "$SSHD_CONF"; then
        sed -i 's/^#Port .*/Port 1022/' "$SSHD_CONF"
    else
        echo "Port 1022" >> "$SSHD_CONF"
    fi

    # Debian 12+ enables ssh.socket by default, which hardcodes port 22
    # and ignores sshd_config Port. Disable and mask it so sshd uses 1022.
    # Check both is-active and is-enabled — in LXC containers the socket
    # may be enabled but not yet active at install time.
    if systemctl list-unit-files ssh.socket &>/dev/null; then
        systemctl disable --now ssh.socket 2>/dev/null
        systemctl mask ssh.socket 2>/dev/null
    fi

    # Ensure ssh service (not socket) is enabled and running
    if systemctl list-unit-files ssh.service &>/dev/null; then
        systemctl enable ssh.service
        systemctl restart ssh.service
    else
        systemctl restart sshd 2>/dev/null
    fi
}
exec_cmd "Changing SSH port to 1022" configure_ssh


# Install cloudflared (Cloudflare Zero Trust Tunnel)
install_cloudflared() {
    # DELIBERATELY PINNED TO bookworm — DO NOT change this to ${OS_CODENAME}.
    #
    # Cloudflare publishes no trixie suite: dists/trixie returns 404 while
    # dists/bookworm returns 200. The package is a single static Go binary with an
    # empty Depends field, so the bookworm build runs correctly on trixie.
    #
    # This is the trap in the Debian 13 port. The instinctive fix — replacing every
    # "bookworm" in this file — repairs the sury repo above and breaks this one. The
    # failure is nasty: `apt-get update` on a 404 suite exits non-zero, exec_cmd
    # aborts the installer, and it does so AFTER SSH has been moved to port 1022 but
    # BEFORE the firewall is configured and the panel deployed.
    #
    # Revisit only when dists/trixie starts returning 200.
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
        | gpg --dearmor -o /etc/apt/keyrings/cloudflare-main.gpg || return 1
    echo "deb [signed-by=/etc/apt/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared bookworm main" \
        > /etc/apt/sources.list.d/cloudflared.list

    if apt-get update -q && apt-get install -y -qq cloudflared; then
        return 0
    fi

    # Fallback: the repo is unreachable or the suite has gone away. Take the .deb
    # straight from GitHub releases so a repo outage cannot abort the install at the
    # worst possible moment.
    echo "  cloudflared apt repo unavailable — falling back to direct .deb download"
    rm -f /etc/apt/sources.list.d/cloudflared.list
    local arch deb
    case "$(dpkg --print-architecture)" in
        amd64) arch=amd64 ;;
        arm64) arch=arm64 ;;
        *)     echo "  unsupported architecture for cloudflared fallback"; return 1 ;;
    esac
    deb=$(mktemp /tmp/cloudflared.XXXXXX.deb)
    curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${arch}.deb" \
        -o "$deb" || { rm -f "$deb"; return 1; }
    dpkg -i "$deb" || { rm -f "$deb"; return 1; }
    rm -f "$deb"
    command -v cloudflared >/dev/null 2>&1
}
exec_cmd "Installing cloudflared (Zero Trust Tunnel)" install_cloudflared

# ==============================================================================
# 8. ADDITIONAL TOOLS
# ==============================================================================
exec_cmd "Installing Image & Utility Tools" apt-get install -y -qq \
    tree        \
    imagemagick \
    jpegoptim   \
    pngquant    \
    webp        \
    gifsicle    \
    ghostscript \
    zip unzip   \
    plocate     \
    net-tools   \
    bwm-ng      \
    sqlite3

exec_cmd "Installing Certbot (Let's Encrypt SSL)" apt-get install -y -qq \
    certbot \
    python3-certbot-dns-cloudflare

# ==============================================================================
# 9. ll ALIAS — root, /etc/skel (future users), all existing home users
# ==============================================================================
setup_ll_alias() {
    local ALIAS_LINE="alias ll='ls -alh'"

    # /etc/skel — inherited by every new user created with useradd -m
    if ! grep -qF "$ALIAS_LINE" /etc/skel/.bashrc 2>/dev/null; then
        printf "\n# Custom Aliases\n%s\n" "$ALIAS_LINE" >> /etc/skel/.bashrc
    fi

    # root
    if ! grep -qF "$ALIAS_LINE" /root/.bashrc 2>/dev/null; then
        printf "\n# Custom Aliases\n%s\n" "$ALIAS_LINE" >> /root/.bashrc
    fi

    # Existing home users
    for user_home in /home/*/; do
        local bashrc="${user_home}.bashrc"
        if [ -f "$bashrc" ] && ! grep -qF "$ALIAS_LINE" "$bashrc"; then
            printf "\n# Custom Aliases\n%s\n" "$ALIAS_LINE" >> "$bashrc"
        fi
    done
}
exec_cmd "Setting Up ll Alias (root, skel, existing users)" setup_ll_alias

# ==============================================================================
# 9b. MOTD — iNetPanel ASCII banner + inetp command reference
# ==============================================================================
setup_motd() {
    # Build MOTD with real ANSI escape characters (not literal \033)
    local B=$'\033[1;34m'   # Blue
    local W=$'\033[1;37m'   # White bold
    local G=$'\033[1;32m'   # Green
    local D=$'\033[2m'      # Dim
    local N=$'\033[0m'      # Reset

    cat > /etc/motd << MOTD_END

  ${B}░▒▓${N}${W}█${N}${B}▓▒░${N}  ${W}i N e t P a n e l${N}  ${B}░▒▓${N}${W}█${N}${B}▓▒░${N}
             ${D}by Tuxxin.com${N}

  ${D}───────────────────────────────────────${N}

  Server IP:      ${G}${SERVER_IP}${N}
  Admin Panel:    ${G}http://${SERVER_IP}/admin${N}
  Client Portal:  ${G}http://${SERVER_IP}/user${N}
  phpMyAdmin:     ${G}http://${SERVER_IP}:8888${N}

  ${D}───────────────────────────────────────${N}
  Run  ${W}inetp --help${N}  for CLI commands
  ${D}───────────────────────────────────────${N}

MOTD_END

    # Disable the default dynamic MOTD scripts to avoid duplicate output
    chmod -x /etc/update-motd.d/* 2>/dev/null || true
}
exec_cmd "Setting Up MOTD (iNetPanel banner + inetp reference)" setup_motd

# ==============================================================================
# 10. BACKUP DIRECTORY
# ==============================================================================
exec_cmd "Creating Backup Directory ($BACKUP_DIR)" mkdir -p "$BACKUP_DIR"

# ==============================================================================
# 11. DOWNLOAD INETPANEL RELEASE
# ==============================================================================
download_inetpanel() {
    local ZIP_URL="https://github.com/tuxxin/iNetPanel/releases/latest/download/inetpanel-latest.zip"
    local TMP_ZIP="/tmp/inetpanel-latest.zip"
    local TMP_DIR="/tmp/inetpanel-extract"

    curl -fsSL -o "$TMP_ZIP" "$ZIP_URL" || { echo "Download failed"; return 1; }
    rm -rf "$TMP_DIR"
    mkdir -p "$TMP_DIR"
    unzip -qo "$TMP_ZIP" -d "$TMP_DIR"

    # Find the root dir inside the zip (build_release.sh creates inetpanel/)
    local SRC_DIR
    if [ -d "$TMP_DIR/inetpanel" ]; then
        SRC_DIR="$TMP_DIR/inetpanel"
    else
        # Fallback: first directory inside extract
        SRC_DIR=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    fi
    [ -d "$SRC_DIR" ] || { echo "Extract failed — no source dir found"; return 1; }

    rm -rf /root/inetpanel
    mv "$SRC_DIR" /root/inetpanel
    rm -rf "$TMP_DIR" "$TMP_ZIP"
}
exec_cmd "Downloading iNetPanel release" download_inetpanel

# ==============================================================================
# 11b. DEPLOY SYSTEM SCRIPTS FROM REPO
# ==============================================================================
deploy_scripts() {
    mkdir -p "$SCRIPTS_DIR"
    # Copy all scripts and templates from scripts/system/
    for f in /root/inetpanel/scripts/system/*.sh /root/inetpanel/scripts/system/*.php /root/inetpanel/scripts/system/*.py; do
        [ -f "$f" ] || continue
        dest="$SCRIPTS_DIR/$(basename "$f")"
        cp "$f" "$dest"
        chmod +x "$dest"
    done
    # Install the inetp CLI dispatcher
    cp /root/inetpanel/scripts/system/inetp /usr/local/bin/inetp
    chmod +x /usr/local/bin/inetp
}
exec_cmd "Deploying system scripts from repo" deploy_scripts


# ==============================================================================
# 11c. SUDO RULES — www-data can run inetp scripts as root (for iNetPanel)
# ==============================================================================
setup_sudoers() {
    mkdir -p /etc/sudoers.d
    cat << 'SUDOERS' > /etc/sudoers.d/inetpanel
# iNetPanel web panel privilege escalation
# Allows www-data (lighttpd/PHP-FPM) to run server management scripts as root
www-data ALL=(root) NOPASSWD: /usr/local/bin/inetp *
www-data ALL=(root) NOPASSWD: /root/scripts/manage_cron.sh
www-data ALL=(root) NOPASSWD: /root/scripts/cloudflared_setup.sh
www-data ALL=(root) NOPASSWD: /root/scripts/update_ssh_port.sh
www-data ALL=(root) NOPASSWD: /root/scripts/manage_ssh_keys.sh
www-data ALL=(root) NOPASSWD: /usr/bin/apt-get
www-data ALL=(root) NOPASSWD: /bin/systemctl
www-data ALL=(root) NOPASSWD: /usr/sbin/a2ensite
www-data ALL=(root) NOPASSWD: /usr/sbin/a2dissite
www-data ALL=(root) NOPASSWD: /usr/bin/wg
www-data ALL=(root) NOPASSWD: /usr/bin/wg-quick
www-data ALL=(root) NOPASSWD: /usr/sbin/usermod
www-data ALL=(root) NOPASSWD: /usr/bin/timedatectl
www-data ALL=(root) NOPASSWD: /usr/bin/hostnamectl
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetpanel_hosts /etc/hosts
www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetpanel_jail.local /etc/fail2ban/jail.local
www-data ALL=(root) NOPASSWD: /sbin/reboot
www-data ALL=(root) NOPASSWD: /usr/sbin/phpenmod
www-data ALL=(root) NOPASSWD: /usr/sbin/phpdismod
www-data ALL=(root) NOPASSWD: /usr/bin/firewall-cmd
www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client
www-data ALL=(root) NOPASSWD: /usr/bin/tail
www-data ALL=(root) NOPASSWD: /usr/bin/journalctl
www-data ALL=(root) NOPASSWD: /usr/bin/dpkg
www-data ALL=(root) NOPASSWD: /bin/sed
www-data ALL=(root) NOPASSWD: /usr/bin/php* /var/www/inetpanel/scripts/panel_update.php *
SUDOERS
    chmod 440 /etc/sudoers.d/inetpanel
}
exec_cmd "Creating sudo rules for iNetPanel (www-data → inetp)" setup_sudoers

# ==============================================================================
# 11c. INETPANEL — Deploy panel files to /var/www/inetpanel
# ==============================================================================
deploy_inetpanel() {
    PANEL_SRC="/root/inetpanel"
    PANEL_DEST="/var/www/inetpanel"

    if [ -d "$PANEL_SRC" ]; then
        mkdir -p "$PANEL_DEST"
        rsync -a --exclude='.git' --exclude='db/*.db' "$PANEL_SRC/" "$PANEL_DEST/"
    fi

    # Ensure required directories exist with correct permissions
    mkdir -p "$PANEL_DEST/public"
    mkdir -p "$PANEL_DEST/db"
    mkdir -p "$PANEL_DEST/api"
    mkdir -p "$PANEL_DEST/scripts"

    chown -R www-data:www-data "$PANEL_DEST"
    chmod 775 "$PANEL_DEST/db"

    # Suspended account page — served when an account is suspended via Apache Alias
    mkdir -p "$PANEL_DEST/suspended"
    cat << 'SUSPENDED_HTML' > "$PANEL_DEST/suspended/index.html"
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0050d5, #7a00d5);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 16px;
            padding: 48px 40px;
            text-align: center;
            max-width: 480px;
            width: 90%;
        }
        .icon { font-size: 64px; margin-bottom: 24px; }
        h1 { font-size: 28px; font-weight: 700; margin-bottom: 12px; }
        p { font-size: 16px; opacity: 0.85; line-height: 1.6; }
        .badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 4px 16px;
            font-size: 13px;
            margin-top: 24px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#9888;</div>
        <h1>Account Suspended</h1>
        <p>This account has been suspended. If you believe this is an error, please contact your hosting administrator.</p>
        <div class="badge">iNetPanel by Tuxxin</div>
    </div>
</body>
</html>
SUSPENDED_HTML

    chown -R www-data:www-data "$PANEL_DEST"

    # Copy all system scripts from repo to /root/scripts/
    mkdir -p /root/scripts
    for f in "$PANEL_DEST/scripts/system"/*.sh "$PANEL_DEST/scripts/system"/*.php; do
        [ -f "$f" ] || continue
        dest="/root/scripts/$(basename "$f")"
        cp "$f" "$dest"
        chmod +x "$dest"
    done
    if [ -f "$PANEL_DEST/scripts/system/inetp" ]; then
        cp "$PANEL_DEST/scripts/system/inetp" /usr/local/bin/inetp
        chmod +x /usr/local/bin/inetp
    fi
}
exec_cmd "Deploying iNetPanel to /var/www/inetpanel" deploy_inetpanel

# ==============================================================================
# 12. CRONJOBS
#     00:00 — system update (inetp-update.sh)
#     03:00 — account backups (backup_accounts.sh)
# ==============================================================================
setup_cronjobs() {
    # Ensure cron is enabled and running
    systemctl enable cron
    systemctl start cron

    # System updates at midnight
    echo "0 0 * * * root /root/scripts/inetp-update.sh" \
        > /etc/cron.d/lamp_update
    chmod 644 /etc/cron.d/lamp_update

    # Account backups at 3am
    echo "0 3 * * * root /usr/local/bin/inetp backup_accounts >> /var/log/lamp_backup.log 2>&1" \
        > /etc/cron.d/lamp_backup
    chmod 644 /etc/cron.d/lamp_backup

    # SSL certificate renewal at 4am
    echo "0 4 * * * root certbot renew --quiet --deploy-hook 'systemctl reload apache2' >> /var/log/certbot_renew.log 2>&1" \
        > /etc/cron.d/certbot_renew
    chmod 644 /etc/cron.d/certbot_renew
}
exec_cmd "Setting Up Cronjobs (updates 12am, backups 3am)" setup_cronjobs

# ==============================================================================
# 13. FIREWALL (Firewalld + Fail2Ban)
# ==============================================================================
install_firewall() {
    apt-get install -y -qq firewalld fail2ban 2>/dev/null

    # Start and enable firewalld
    systemctl enable --now firewalld

    # Detect SSH port from sshd_config (default 1022)
    SSH_PORT=$(grep -E '^Port ' /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}')
    [ -z "$SSH_PORT" ] && SSH_PORT=1022

    # Default zone: drop (deny all incoming by default)
    firewall-cmd --set-default-zone=drop

    # Allow essential services
    firewall-cmd --permanent --add-port=${SSH_PORT}/tcp   # SSH
    firewall-cmd --permanent --add-port=20/tcp            # FTP data
    firewall-cmd --permanent --add-port=21/tcp            # FTP control
    firewall-cmd --permanent --add-port=40000-50000/tcp   # FTP passive range
    firewall-cmd --permanent --add-port=80/tcp            # Panel — redirects to 443
    firewall-cmd --permanent --add-port=443/tcp           # Panel (lighttpd, TLS)
    firewall-cmd --permanent --add-port=8888/tcp          # phpMyAdmin

    # Allow loopback (for cloudflared, local services)
    firewall-cmd --permanent --zone=trusted --add-interface=lo

    firewall-cmd --reload

    # Fail2Ban: SSH + vsftpd + panel login jails
    cat > /etc/fail2ban/jail.local << F2B
[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 5
ignoreip = 127.0.0.1/8 ::1
backend  = systemd
# Pin the ban backend explicitly. Left unset, fail2ban inherits jail.conf's default,
# which has historically been an iptables action — and firewalld runs on nftables on
# both Debian 12 and 13. The two then manage separate rule sets, and bans can land
# in a table nothing consults, so fail2ban reports a ban that is not enforced.
banaction      = nftables-multiport
banaction_allports = nftables-allports

[sshd]
enabled  = true
port     = ${SSH_PORT}
maxretry = 5

[vsftpd]
enabled  = true
port     = 21
maxretry = 5

[inetpanel-auth]
enabled  = true
port     = 80
filter   = inetpanel-auth
logpath  = /var/log/inetpanel_auth.log
maxretry = 10
bantime  = 1800
F2B

    # Custom filter for panel login failures
    cat > /etc/fail2ban/filter.d/inetpanel-auth.conf << 'F2BFILTER'
[Definition]
failregex = ^<HOST> - LOGIN_FAILED .*$
ignoreregex =
F2BFILTER

    # Create log file for panel auth if it doesn't exist
    touch /var/log/inetpanel_auth.log
    chown www-data:www-data /var/log/inetpanel_auth.log

    systemctl enable --now fail2ban
}
exec_cmd "Installing Firewalld + Fail2Ban" install_firewall

# ==============================================================================
# 14. FINAL SERVICE RELOAD
# ==============================================================================
exec_cmd "Final Service Reload" bash -c \
    "systemctl reload apache2 && systemctl restart php${PHP_VER}-fpm && systemctl restart vsftpd && systemctl restart lighttpd"

# ==============================================================================
# 15. SUMMARY
# ==============================================================================
echo ""
echo -e "${BOLD}======================================================${NC}"
echo -e "${GREEN}   Installation Complete!${NC}"
echo -e "${BOLD}======================================================${NC}"
echo -e "  ${BOLD}Server IP:${NC}    $SERVER_IP"
echo -e "  ${BOLD}iNetPanel:${NC}    ${GREEN}https://$SERVER_IP/install.php${NC}  ← complete setup here"
echo -e "  ${BOLD}phpMyAdmin:${NC}   http://$SERVER_IP:8888"
echo -e "  ${BOLD}PHP:${NC}          $PHP_VER (FPM)"
echo -e "  ${BOLD}Scripts:${NC}      $SCRIPTS_DIR"
echo -e "  ${BOLD}Panel:${NC}        /var/www/inetpanel"
echo -e "  ${BOLD}Backups:${NC}      $BACKUP_DIR  (daily 3am, ${BACKUP_RETENTION_DAYS}-day retention)"
echo -e "  ${BOLD}Updates:${NC}      Daily midnight  →  /var/log/lamp_update.log"
echo -e "  ${BOLD}Log:${NC}          $LOG_FILE"
echo -e "  ${BOLD}Firewall:${NC}     firewalld + fail2ban (active)"
echo ""
echo -e "  ${BOLD}Commands:${NC}"
echo -e "    ${GREEN}inetp create_account${NC}"
echo -e "    ${GREEN}inetp delete_account${NC}"
echo -e "    ${GREEN}inetp suspend_account${NC}"
echo -e "    ${GREEN}inetp optimize_images${NC}"
echo -e "    ${GREEN}inetp backup_accounts${NC}"
echo -e "    ${GREEN}inetp update${NC}"
echo -e "    ${GREEN}inetp list${NC}"
echo ""
echo -e "  ${YELLOW}MySQL root password saved to: /root/.mysql_root_pass${NC}"
echo -e ""
echo -e "  ${BLUE}Open https://$SERVER_IP/install.php to complete iNetPanel setup.${NC}"
echo -e "  ${DIM}The certificate is self-signed, so your browser will warn once — that is${NC}"
echo -e "  ${DIM}expected. It exists so the admin password and Cloudflare API key you${NC}"
echo -e "  ${DIM}enter next are not sent in the clear. Replace it with a trusted cert${NC}"
echo -e "  ${DIM}later:  inetp panel_ssl <hostname>${NC}"
echo -e "${BOLD}======================================================${NC}"
