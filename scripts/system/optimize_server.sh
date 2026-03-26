#!/bin/bash
# ==============================================================================
# optimize_server.sh — Auto-tune Apache2 and MariaDB based on server specs
# Usage: inetp optimize_server [--apply] [--quiet]
#
# Detects RAM, CPU cores, disk type, and hosted domain count, then calculates
# optimal settings for Apache2 mpm_event and MariaDB InnoDB/buffers.
# Default mode is dry-run (preview only). Pass --apply to write changes.
# ==============================================================================

APPLY=0
QUIET=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --apply) APPLY=1; shift ;;
        --quiet) QUIET=1; shift ;;
        *) shift ;;
    esac
done

# Colors (strip if not a terminal)
if [ -t 1 ] && [ "$QUIET" -eq 0 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'
    YELLOW='\033[1;33m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

BACKUP_DIR="/root/config-backups/$(date +%Y%m%d-%H%M%S)"
CHANGES_MADE=0

# ==============================================================================
# Server Detection
# ==============================================================================

# RAM (in MB)
TOTAL_RAM_MB=$(awk '/^MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
TOTAL_RAM_GB=$(awk "BEGIN {printf \"%.1f\", ${TOTAL_RAM_MB}/1024}")

# CPU cores
CPU_CORES=$(nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo)

# Disk type (SSD vs HDD)
ROOT_DISK=$(lsblk -ndo NAME,MOUNTPOINT 2>/dev/null | awk '$2=="/" {print $1}')
[ -z "$ROOT_DISK" ] && ROOT_DISK=$(lsblk -ndo NAME,MOUNTPOINT 2>/dev/null | awk '$2~"^/$" {print $1}')
[ -z "$ROOT_DISK" ] && ROOT_DISK=$(findmnt -no SOURCE / 2>/dev/null | sed 's|/dev/||;s/[0-9]*$//')

# Resolve to the physical disk (strip partition number, handle nvme)
PHYS_DISK=$(echo "$ROOT_DISK" | sed 's/p[0-9]*$//;s/[0-9]*$//')
ROTATIONAL=$(cat "/sys/block/${PHYS_DISK}/queue/rotational" 2>/dev/null)

if [ "$ROTATIONAL" = "0" ]; then
    DISK_TYPE="SSD"
elif [ "$ROTATIONAL" = "1" ]; then
    DISK_TYPE="HDD"
else
    # VPS/cloud — assume SSD if we can't detect
    DISK_TYPE="SSD"
fi

# Hosted domains (Apache vhosts minus defaults)
DOMAIN_COUNT=$(ls /etc/apache2/sites-available/*.conf 2>/dev/null | grep -vcE '000-default|default-ssl|phpmyadmin')
[ "$DOMAIN_COUNT" -lt 1 ] && DOMAIN_COUNT=1

# Apache MPM type
if [ -f /etc/apache2/mods-enabled/mpm_event.load ]; then
    MPM="event"
elif [ -f /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    MPM="prefork"
else
    MPM="event"
fi

MPM_CONF="/etc/apache2/mods-available/mpm_${MPM}.conf"

# ==============================================================================
# Calculate Optimal Values
# ==============================================================================

# --- Memory budget ---
# Reserve for OS + lighttpd + cloudflared + vsftpd + fail2ban + misc
OS_RESERVE_MB=384
AVAILABLE_MB=$((TOTAL_RAM_MB - OS_RESERVE_MB))
[ "$AVAILABLE_MB" -lt 256 ] && AVAILABLE_MB=256

# PHP-FPM budget: ~30MB per idle pool, estimate based on domain count
FPM_ESTIMATE_MB=$(( DOMAIN_COUNT * 30 ))

# Remaining split: ~40% MariaDB, rest for Apache headroom
DB_BUDGET_MB=$(( (AVAILABLE_MB - FPM_ESTIMATE_MB) * 40 / 100 ))
[ "$DB_BUDGET_MB" -lt 128 ] && DB_BUDGET_MB=128

# --- Apache mpm_event tuning ---
# ThreadsPerChild: 25 for small servers, 64 for large
if [ "$TOTAL_RAM_MB" -le 1024 ]; then
    A_THREADS_PER_CHILD=25
    A_START_SERVERS=1
    A_MIN_SPARE_THREADS=25
    A_MAX_SPARE_THREADS=75
    A_MAX_REQUEST_WORKERS=75
    A_MAX_CONN_PER_CHILD=5000
elif [ "$TOTAL_RAM_MB" -le 2048 ]; then
    A_THREADS_PER_CHILD=25
    A_START_SERVERS=2
    A_MIN_SPARE_THREADS=25
    A_MAX_SPARE_THREADS=75
    A_MAX_REQUEST_WORKERS=150
    A_MAX_CONN_PER_CHILD=10000
elif [ "$TOTAL_RAM_MB" -le 4096 ]; then
    A_THREADS_PER_CHILD=25
    A_START_SERVERS=2
    A_MIN_SPARE_THREADS=50
    A_MAX_SPARE_THREADS=150
    A_MAX_REQUEST_WORKERS=200
    A_MAX_CONN_PER_CHILD=10000
elif [ "$TOTAL_RAM_MB" -le 8192 ]; then
    A_THREADS_PER_CHILD=64
    A_START_SERVERS=2
    A_MIN_SPARE_THREADS=75
    A_MAX_SPARE_THREADS=250
    A_MAX_REQUEST_WORKERS=320
    A_MAX_CONN_PER_CHILD=10000
else
    A_THREADS_PER_CHILD=64
    A_START_SERVERS=3
    A_MIN_SPARE_THREADS=75
    A_MAX_SPARE_THREADS=250
    A_MAX_REQUEST_WORKERS=$(( CPU_CORES * 64 ))
    # Cap at 1024
    [ "$A_MAX_REQUEST_WORKERS" -gt 1024 ] && A_MAX_REQUEST_WORKERS=1024
    A_MAX_CONN_PER_CHILD=10000
fi

A_THREAD_LIMIT=$A_THREADS_PER_CHILD
A_SERVER_LIMIT=$(( A_MAX_REQUEST_WORKERS / A_THREADS_PER_CHILD ))
[ "$A_SERVER_LIMIT" -lt 1 ] && A_SERVER_LIMIT=1

# --- MariaDB tuning ---
# InnoDB buffer pool: primary memory consumer
# Round down to nearest power-of-2-friendly value (128M increments)
INNODB_POOL_MB=$(( (DB_BUDGET_MB / 128) * 128 ))
[ "$INNODB_POOL_MB" -lt 128 ] && INNODB_POOL_MB=128

# Buffer pool instances: 1 per GB, min 1, max 8
INNODB_POOL_INSTANCES=$(( INNODB_POOL_MB / 1024 ))
[ "$INNODB_POOL_INSTANCES" -lt 1 ] && INNODB_POOL_INSTANCES=1
[ "$INNODB_POOL_INSTANCES" -gt 8 ] && INNODB_POOL_INSTANCES=8

# Log file size: 25% of buffer pool, min 48M, max 2G
INNODB_LOG_MB=$(( INNODB_POOL_MB / 4 ))
[ "$INNODB_LOG_MB" -lt 48 ] && INNODB_LOG_MB=48
[ "$INNODB_LOG_MB" -gt 2048 ] && INNODB_LOG_MB=2048

# Format sizes for config (use G suffix when >= 1024M)
if [ "$INNODB_POOL_MB" -ge 1024 ]; then
    INNODB_POOL_FMT="$(( INNODB_POOL_MB / 1024 ))G"
else
    INNODB_POOL_FMT="${INNODB_POOL_MB}M"
fi
if [ "$INNODB_LOG_MB" -ge 1024 ]; then
    INNODB_LOG_FMT="$(( INNODB_LOG_MB / 1024 ))G"
else
    INNODB_LOG_FMT="${INNODB_LOG_MB}M"
fi

# max_connections: scale with RAM
if [ "$TOTAL_RAM_MB" -le 1024 ]; then
    DB_MAX_CONN=50
    DB_THREAD_CACHE=8
    DB_TABLE_OPEN_CACHE=1024
    DB_TABLE_DEF_CACHE=800
    DB_TMP_TABLE_SIZE="16M"
    DB_SORT_BUFFER="1M"
    DB_READ_BUFFER="256K"
    DB_READ_RND_BUFFER="512K"
    DB_JOIN_BUFFER="512K"
    DB_KEY_BUFFER="16M"
elif [ "$TOTAL_RAM_MB" -le 2048 ]; then
    DB_MAX_CONN=100
    DB_THREAD_CACHE=12
    DB_TABLE_OPEN_CACHE=2000
    DB_TABLE_DEF_CACHE=1000
    DB_TMP_TABLE_SIZE="32M"
    DB_SORT_BUFFER="2M"
    DB_READ_BUFFER="512K"
    DB_READ_RND_BUFFER="1M"
    DB_JOIN_BUFFER="512K"
    DB_KEY_BUFFER="24M"
elif [ "$TOTAL_RAM_MB" -le 4096 ]; then
    DB_MAX_CONN=150
    DB_THREAD_CACHE=16
    DB_TABLE_OPEN_CACHE=3000
    DB_TABLE_DEF_CACHE=1500
    DB_TMP_TABLE_SIZE="48M"
    DB_SORT_BUFFER="2M"
    DB_READ_BUFFER="1M"
    DB_READ_RND_BUFFER="1M"
    DB_JOIN_BUFFER="1M"
    DB_KEY_BUFFER="32M"
elif [ "$TOTAL_RAM_MB" -le 8192 ]; then
    DB_MAX_CONN=200
    DB_THREAD_CACHE=16
    DB_TABLE_OPEN_CACHE=4000
    DB_TABLE_DEF_CACHE=2000
    DB_TMP_TABLE_SIZE="64M"
    DB_SORT_BUFFER="4M"
    DB_READ_BUFFER="1M"
    DB_READ_RND_BUFFER="1M"
    DB_JOIN_BUFFER="1M"
    DB_KEY_BUFFER="32M"
else
    DB_MAX_CONN=300
    DB_THREAD_CACHE=24
    DB_TABLE_OPEN_CACHE=6000
    DB_TABLE_DEF_CACHE=3000
    DB_TMP_TABLE_SIZE="128M"
    DB_SORT_BUFFER="4M"
    DB_READ_BUFFER="2M"
    DB_READ_RND_BUFFER="2M"
    DB_JOIN_BUFFER="2M"
    DB_KEY_BUFFER="64M"
fi

# IO capacity: SSD vs HDD
if [ "$DISK_TYPE" = "SSD" ]; then
    DB_IO_CAP=2000
    DB_IO_CAP_MAX=4000
    DB_FLUSH_NEIGHBORS=0
    DB_FLUSH_METHOD="O_DIRECT"
else
    DB_IO_CAP=200
    DB_IO_CAP_MAX=400
    DB_FLUSH_NEIGHBORS=1
    DB_FLUSH_METHOD="O_DIRECT"
fi

# IO threads: match CPU cores, min 2, max 8
DB_READ_IO_THREADS=$CPU_CORES
[ "$DB_READ_IO_THREADS" -gt 8 ] && DB_READ_IO_THREADS=8
[ "$DB_READ_IO_THREADS" -lt 2 ] && DB_READ_IO_THREADS=2
DB_WRITE_IO_THREADS=$DB_READ_IO_THREADS

# ==============================================================================
# Output Report
# ==============================================================================

if [ "$QUIET" -eq 0 ]; then
    echo ""
    echo -e "${BOLD}Server Optimization Report${NC}"
    echo -e "${DIM}$(printf '%.0s─' {1..60})${NC}"
    echo ""
    echo -e "  ${BOLD}Detected Hardware:${NC}"
    echo -e "    ${GREEN}RAM${NC}            ${TOTAL_RAM_GB} GB  (${TOTAL_RAM_MB} MB)"
    echo -e "    ${GREEN}CPU Cores${NC}      ${CPU_CORES}"
    echo -e "    ${GREEN}Disk${NC}           ${DISK_TYPE}"
    echo -e "    ${GREEN}Domains${NC}        ${DOMAIN_COUNT} hosted"
    echo -e "    ${GREEN}Apache MPM${NC}     ${MPM}"
    echo ""
    echo -e "  ${BOLD}Memory Budget:${NC}"
    echo -e "    ${DIM}OS/services reserve${NC}   ${OS_RESERVE_MB} MB"
    echo -e "    ${DIM}PHP-FPM estimate${NC}      ${FPM_ESTIMATE_MB} MB  (${DOMAIN_COUNT} pools)"
    echo -e "    ${DIM}MariaDB allocation${NC}    ${INNODB_POOL_MB} MB  (InnoDB buffer pool)"
    echo ""

    echo -e "  ${BOLD}Apache2 mpm_${MPM}:${NC}  ${DIM}${MPM_CONF}${NC}"
    echo -e "    StartServers            ${A_START_SERVERS}"
    echo -e "    MinSpareThreads         ${A_MIN_SPARE_THREADS}"
    echo -e "    MaxSpareThreads         ${A_MAX_SPARE_THREADS}"
    echo -e "    ThreadLimit             ${A_THREAD_LIMIT}"
    echo -e "    ThreadsPerChild         ${A_THREADS_PER_CHILD}"
    echo -e "    ServerLimit             ${A_SERVER_LIMIT}"
    echo -e "    MaxRequestWorkers       ${A_MAX_REQUEST_WORKERS}"
    echo -e "    MaxConnectionsPerChild  ${A_MAX_CONN_PER_CHILD}"
    echo ""

    echo -e "  ${BOLD}MariaDB:${NC}  ${DIM}/etc/mysql/mariadb.conf.d/50-server.cnf${NC}"
    echo -e "    innodb_buffer_pool_size      ${INNODB_POOL_FMT}"
    echo -e "    innodb_log_file_size         ${INNODB_LOG_FMT}"
    echo -e "    innodb_buffer_pool_instances ${INNODB_POOL_INSTANCES}"
    echo -e "    innodb_flush_log_at_trx_commit  2"
    echo -e "    innodb_flush_method          ${DB_FLUSH_METHOD}"
    echo -e "    innodb_flush_neighbors       ${DB_FLUSH_NEIGHBORS}"
    echo -e "    innodb_io_capacity           ${DB_IO_CAP}"
    echo -e "    innodb_io_capacity_max       ${DB_IO_CAP_MAX}"
    echo -e "    innodb_read_io_threads       ${DB_READ_IO_THREADS}"
    echo -e "    innodb_write_io_threads      ${DB_WRITE_IO_THREADS}"
    echo -e "    innodb_file_per_table        1"
    echo -e "    innodb_open_files            ${DB_TABLE_OPEN_CACHE}"
    echo -e "    max_connections              ${DB_MAX_CONN}"
    echo -e "    thread_cache_size            ${DB_THREAD_CACHE}"
    echo -e "    table_open_cache             ${DB_TABLE_OPEN_CACHE}"
    echo -e "    table_definition_cache       ${DB_TABLE_DEF_CACHE}"
    echo -e "    tmp_table_size               ${DB_TMP_TABLE_SIZE}"
    echo -e "    max_heap_table_size          ${DB_TMP_TABLE_SIZE}"
    echo -e "    sort_buffer_size             ${DB_SORT_BUFFER}"
    echo -e "    read_buffer_size             ${DB_READ_BUFFER}"
    echo -e "    read_rnd_buffer_size         ${DB_READ_RND_BUFFER}"
    echo -e "    join_buffer_size             ${DB_JOIN_BUFFER}"
    echo -e "    key_buffer_size              ${DB_KEY_BUFFER}"
    echo ""
fi

if [ "$APPLY" -eq 0 ]; then
    if [ "$QUIET" -eq 0 ]; then
        echo -e "  ${YELLOW}Dry run — no changes made.${NC}"
        echo -e "  Run with ${BOLD}--apply${NC} to write these settings."
        echo ""
    fi
    exit 0
fi

# ==============================================================================
# Apply Changes
# ==============================================================================

mkdir -p "$BACKUP_DIR"

# --- Apache MPM ---
if [ -f "$MPM_CONF" ]; then
    cp "$MPM_CONF" "${BACKUP_DIR}/mpm_${MPM}.conf"

    cat > "$MPM_CONF" <<MPMEOF
# ${MPM} MPM — auto-tuned for ${CPU_CORES} vCPU / ${TOTAL_RAM_GB} GB RAM / ${DISK_TYPE}
# Generated by: inetp optimize_server ($(date +%Y-%m-%d))
# Backup: ${BACKUP_DIR}/mpm_${MPM}.conf
StartServers            ${A_START_SERVERS}
MinSpareThreads         ${A_MIN_SPARE_THREADS}
MaxSpareThreads         ${A_MAX_SPARE_THREADS}
ThreadLimit             ${A_THREAD_LIMIT}
ThreadsPerChild         ${A_THREADS_PER_CHILD}
ServerLimit             ${A_SERVER_LIMIT}
MaxRequestWorkers       ${A_MAX_REQUEST_WORKERS}
MaxConnectionsPerChild  ${A_MAX_CONN_PER_CHILD}
MPMEOF

    echo -e "  ${GREEN}✓${NC} Apache mpm_${MPM}.conf updated"
    CHANGES_MADE=1
else
    echo -e "  ${RED}✗${NC} Apache MPM config not found: ${MPM_CONF}"
fi

# --- MariaDB ---
MYCNF="/etc/mysql/mariadb.conf.d/50-server.cnf"
if [ -f "$MYCNF" ]; then
    cp "$MYCNF" "${BACKUP_DIR}/50-server.cnf"

    # Build the tuned [mysqld] section while preserving structure
    # We replace the Fine Tuning and InnoDB blocks entirely
    cat > "$MYCNF" <<DBEOF
#
# These groups are read by MariaDB server.
# Use it for options that only the server (but not clients) should see

# this is read by the standalone daemon and embedded servers
[server]

# this is only for the mysqld standalone daemon
[mysqld]

#
# * Basic Settings
#

pid-file                = /run/mysqld/mysqld.pid
basedir                 = /usr

bind-address = 127.0.0.1

#
# * Fine Tuning — auto-tuned for ${CPU_CORES} vCPU / ${TOTAL_RAM_GB} GB RAM / ${DISK_TYPE}
# Generated by: inetp optimize_server ($(date +%Y-%m-%d))
#

skip-name-resolve
max_connections         = ${DB_MAX_CONN}
thread_cache_size       = ${DB_THREAD_CACHE}
max_allowed_packet      = 64M
thread_stack            = 256K
table_open_cache        = ${DB_TABLE_OPEN_CACHE}
table_definition_cache  = ${DB_TABLE_DEF_CACHE}
open_files_limit        = 65535

# Per-session buffers
join_buffer_size        = ${DB_JOIN_BUFFER}
sort_buffer_size        = ${DB_SORT_BUFFER}
read_buffer_size        = ${DB_READ_BUFFER}
read_rnd_buffer_size    = ${DB_READ_RND_BUFFER}
tmp_table_size          = ${DB_TMP_TABLE_SIZE}
max_heap_table_size     = ${DB_TMP_TABLE_SIZE}
key_buffer_size         = ${DB_KEY_BUFFER}

#
# * Logging and Replication
#

expire_logs_days        = 10

#
# * Character sets
#

character-set-server  = utf8mb4
collation-server      = utf8mb4_general_ci

#
# * InnoDB — auto-tuned for ${DISK_TYPE}
#

innodb_buffer_pool_size   = ${INNODB_POOL_FMT}
innodb_log_file_size      = ${INNODB_LOG_FMT}
innodb_flush_log_at_trx_commit = 2
innodb_flush_method       = ${DB_FLUSH_METHOD}
innodb_flush_neighbors    = ${DB_FLUSH_NEIGHBORS}
innodb_io_capacity        = ${DB_IO_CAP}
innodb_io_capacity_max    = ${DB_IO_CAP_MAX}
innodb_read_io_threads    = ${DB_READ_IO_THREADS}
innodb_write_io_threads   = ${DB_WRITE_IO_THREADS}
innodb_buffer_pool_instances = ${INNODB_POOL_INSTANCES}
innodb_file_per_table     = 1
innodb_open_files         = ${DB_TABLE_OPEN_CACHE}

# this is only for embedded server
[embedded]

# This group is only read by MariaDB servers, not by MySQL.
[mariadb]

# This group is only read by MariaDB-10.11 servers.
[mariadb-10.11]
DBEOF

    echo -e "  ${GREEN}✓${NC} MariaDB 50-server.cnf updated"
    CHANGES_MADE=1
else
    echo -e "  ${RED}✗${NC} MariaDB config not found: ${MYCNF}"
fi

# --- Restart services ---
if [ "$CHANGES_MADE" -eq 1 ]; then
    echo ""

    # Test Apache config before restarting
    if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
        systemctl reload apache2 2>/dev/null
        echo -e "  ${GREEN}✓${NC} Apache2 reloaded"
    else
        echo -e "  ${RED}✗${NC} Apache config test failed — restoring backup"
        cp "${BACKUP_DIR}/mpm_${MPM}.conf" "$MPM_CONF" 2>/dev/null
        systemctl reload apache2 2>/dev/null
    fi

    # Restart MariaDB
    if systemctl restart mariadb 2>/dev/null; then
        echo -e "  ${GREEN}✓${NC} MariaDB restarted"
    else
        echo -e "  ${RED}✗${NC} MariaDB restart failed — restoring backup"
        cp "${BACKUP_DIR}/50-server.cnf" "$MYCNF" 2>/dev/null
        systemctl restart mariadb 2>/dev/null
    fi

    echo ""
    echo -e "  ${GREEN}Backups saved to:${NC} ${BACKUP_DIR}/"
    echo ""
fi
