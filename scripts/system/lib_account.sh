#!/bin/bash
# ==============================================================================
# lib_account.sh — shared account-resource rules
#
# SOURCE THIS, DO NOT EXECUTE IT:
#     source /root/scripts/lib_account.sh || exit 1
#
# Every other script in this tree is deliberately standalone and duplicates its
# helpers. This file is the one exception, and the reason is narrow: the rules
# below decide what `DROP DATABASE` and `rm -rf` are pointed at. They are needed
# identically by delete_user.sh, remove_domain.sh, backup_accounts.sh and
# audit_orphans.sh, and four copies of a destruction rule is four chances to
# drift. Everything that is NOT a destruction rule stays duplicated per script.
#
# ==============================================================================
# THE OWNERSHIP RULE, AND WHY IT IS SAFE
# ==============================================================================
#
# Database D belongs to hosting user U if and only if D begins with the literal
# string U + "_".
#
# This is provably unambiguous. Usernames are validated `^[a-z][a-z0-9-]{0,31}$`
# (create_user.sh, api/accounts.php) and therefore cannot contain an underscore.
# So for two distinct usernames u1 != u2, "u1_" can be a prefix of "u2_" only if
# u2 = u1 + "_" + something — impossible. At most one known username can match a
# given database name.
#
#     tuxxin_cruise  ->  owner is tuxxin. Never cruise.
#
# ==============================================================================
# WHY THIS DOES NOT USE `LIKE`
# ==============================================================================
#
# In MySQL `LIKE`, `_` is a single-character wildcard. Every historical bug in
# this area came from that. Measured on a live box with user `qr-track`, whose
# database is `qr-track_qr_tuxxin_net`:
#
#     LIKE 'qr_track%'     -> qr-track_qr_tuxxin_net   the old code
#     LIKE 'qr\_track%'    -> (nothing)                escaping ALONE
#     LIKE 'qr-track\_%'   -> qr-track_qr_tuxxin_net   correct
#
# The old code did `tr '.-' '_'` on the username, producing the prefix
# `qr_track`, and only found the database because the unescaped `_` acted as a
# wildcard matching the `-`. Two bugs cancelling out. Escaping the underscore
# without also removing the `tr` silently stops that account's databases from
# being backed up — a fix that causes data loss.
#
# So: no LIKE, no wildcards, no username mangling. Enumerate
# information_schema.SCHEMATA and compare literal prefixes in shell, where `_`
# and `-` mean themselves. If you are editing this and reaching for LIKE, re-read
# the table above.
#
# ==============================================================================

# --- Shared configuration ----------------------------------------------------
PANEL_DB="${PANEL_DB:-/var/www/inetpanel/db/inetpanel.db}"
TOMB_DIR="${TOMB_DIR:-/var/lib/inetpanel/deleting}"
LOCK_DIR="${LOCK_DIR:-/run/lock/inetp}"

if [ -f /root/.mysql_root_pass ]; then
    DB_ROOT_PASS=$(cat /root/.mysql_root_pass)
else
    DB_ROOT_PASS=""
fi

# Schemas that belong to the server, never to an account.
LIB_SYSTEM_SCHEMAS="information_schema mysql performance_schema sys phpmyadmin"

# Accounts that must never be treated as hosting accounts or deleted.
LIB_RESERVED_USERS="root daemon bin sys sync games man lp mail news uucp proxy
www-data backup list irc gnats nobody systemd-network systemd-resolve messagebus
sshd mysql restore android"

# --- Colours (only if the caller has not already set them) -------------------
if [ -z "${NC:-}" ]; then
    if [ -t 1 ]; then
        BOLD=$'\033[1m'; GREEN=$'\033[1;32m'; RED=$'\033[1;31m'
        YELLOW=$'\033[1;33m'; CYAN=$'\033[1;36m'; DIM=$'\033[2m'; NC=$'\033[0m'
    else
        BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
    fi
fi

# ==============================================================================
# Step accounting
# ==============================================================================
# The deletion scripts historically ended on an `echo`, so they exited 0 no
# matter how many steps failed — and PHP, which gates its panel-DB deletes on
# that exit code, deleted the rows that were the only record of what still
# needed destroying. Hence: every step reports, and FAILED drives the exit code.
#
# Always classify by RE-OBSERVING the world, never by the command's own status:
#
#     rm -f "$thing"
#     [ -e "$thing" ] && step_fail "still present: $thing" || step_ok "removed"
#
# That is what makes idempotency and honest exit codes the same mechanism: a
# step whose post-condition already holds is OK on a resumed run, and a step
# that silently no-opped is FAIL on the first.
FAILED=0
WARNED=0

step_ok()   { printf '  %sOK%s      %s\n'   "$GREEN"  "$NC" "$*"; }
step_warn() { printf '  %sWARN%s    %s\n'   "$YELLOW" "$NC" "$*"; WARNED=$((WARNED + 1)); }
step_fail() { printf '  %sFAIL%s    %s\n'   "$RED"    "$NC" "$*"; FAILED=$((FAILED + 1)); }
step_info() { printf '  %s        %s%s\n'   "$DIM"    "$*" "$NC"; }
section()   { printf '\n%s▸ %s%s\n'         "$CYAN"   "$*" "$NC"; }

# ==============================================================================
# MariaDB
# ==============================================================================
# `mysql`, `mysqldump` and friends are compatibility symlinks to the real
# `mariadb*` binaries. Under MariaDB 11.x packaging (Debian 13) those symlinks moved
# out of mariadb-client into separate mariadb-*-compat packages, which are not
# guaranteed to be installed. Resolve the real name once, preferring it.
#
# This is not cosmetic: if `mysql` is missing, mysql_available() returns false and
# the deletion path only *warns* before skipping every database and MariaDB user —
# silently recreating the orphan class that 1.25.0 exists to fix.
MARIADB_BIN=$(command -v mariadb 2>/dev/null || command -v mysql 2>/dev/null || echo mysql)
MARIADB_DUMP_BIN=$(command -v mariadb-dump 2>/dev/null || command -v mysqldump 2>/dev/null || echo mysqldump)

# Unquoted ${DB_ROOT_PASS:+...} on purpose: it must vanish entirely when empty
# so socket auth is used. This is the idiom used throughout the tree.
# shellcheck disable=SC2086
mysql_root() { "$MARIADB_BIN" -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} "$@"; }

# shellcheck disable=SC2086
mysqldump_root() { "$MARIADB_DUMP_BIN" -u root ${DB_ROOT_PASS:+-p"$DB_ROOT_PASS"} "$@"; }

mysql_available() { mysql_root -N -e "SELECT 1" >/dev/null 2>&1; }

# ==============================================================================
# Username validation — the gate in front of every destructive operation
# ==============================================================================
# Before this existed, only a non-empty check stood between an empty $USERNAME
# and `rm -rf /home/$USERNAME`.
validate_username() {
    local u="$1"

    if [ -z "$u" ]; then
        printf '%serror:%s no username given\n' "$RED" "$NC" >&2
        return 1
    fi

    if ! [[ "$u" =~ ^[a-z][a-z0-9-]{0,31}$ ]]; then
        printf '%serror:%s refusing to operate on invalid username %s\n' "$RED" "$NC" "'$u'" >&2
        return 1
    fi

    local reserved
    for reserved in $LIB_RESERVED_USERS; do
        if [ "$u" = "$reserved" ]; then
            printf '%serror:%s refusing to operate on reserved account %s\n' "$RED" "$NC" "'$u'" >&2
            return 1
        fi
    done

    # A uid below 1000 is a system account regardless of what the panel thinks.
    local uid
    uid=$(id -u "$u" 2>/dev/null)
    if [ -n "$uid" ] && [ "$uid" -lt 1000 ]; then
        printf '%serror:%s refusing to operate on system account %s (uid %s)\n' "$RED" "$NC" "'$u'" "$uid" >&2
        return 1
    fi

    return 0
}

# ==============================================================================
# Who is a hosting account
# ==============================================================================
# The panel DB is authoritative when it has a row, but it is exactly the thing
# that goes missing in a partial delete — so fall back to the filesystem shape
# the panel creates: uid >= 1000 with a home under /home/.
#
# That rule, not a hardcoded name, is what excludes `restore` (uid 1002, home
# /backup/restore_staging): a real shell account that is not a hosting account.
is_hosting_user() {
    local u="$1"
    [ -z "$u" ] && return 1

    local reserved
    for reserved in $LIB_RESERVED_USERS; do
        [ "$u" = "$reserved" ] && return 1
    done

    if [ -f "$PANEL_DB" ]; then
        local n
        n=$(sqlite3 "$PANEL_DB" "SELECT COUNT(*) FROM hosting_users WHERE username='${u}'" 2>/dev/null)
        [ "${n:-0}" -gt 0 ] && return 0
    fi

    local uid home
    uid=$(id -u "$u" 2>/dev/null) || return 1
    [ -z "$uid" ] && return 1
    [ "$uid" -lt 1000 ] && return 1
    home=$(getent passwd "$u" 2>/dev/null | cut -d: -f6)
    case "$home" in
        /home/*) return 0 ;;
        *)       return 1 ;;
    esac
}

# Every known hosting account, from the panel DB and /etc/passwd combined.
hosting_users_all() {
    {
        [ -f "$PANEL_DB" ] && sqlite3 "$PANEL_DB" "SELECT username FROM hosting_users" 2>/dev/null
        while IFS=: read -r name _ uid _ _ home _; do
            [ "$uid" -ge 1000 ] 2>/dev/null || continue
            case "$home" in /home/*) printf '%s\n' "$name" ;; esac
        done < /etc/passwd
    } | sort -u | while IFS= read -r u; do
        [ -z "$u" ] && continue
        is_hosting_user "$u" && printf '%s\n' "$u"
    done
}

# ==============================================================================
# Databases owned by an account
# ==============================================================================
# Literal prefix comparison. See the header for why this is not a LIKE query.
dbs_owned_by() {
    local owner="$1" db sys skip
    [ -z "$owner" ] && return 1

    while IFS= read -r db; do
        [ -z "$db" ] && continue
        skip=0
        for sys in $LIB_SYSTEM_SCHEMAS; do
            [ "$db" = "$sys" ] && skip=1 && break
        done
        [ "$skip" -eq 1 ] && continue
        # Quoted prefix, unquoted trailing * — "${owner}_" is matched literally,
        # so '_' and '-' are themselves and there is no wildcard overmatch.
        case "$db" in
            "${owner}_"*) printf '%s\n' "$db" ;;
        esac
    done < <(mysql_root -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA" 2>/dev/null)
}

# Resolve a database name back to its owning username, or nothing if unowned.
db_owner() {
    local db="$1" u
    [ -z "$db" ] && return 1
    while IFS= read -r u; do
        [ -z "$u" ] && continue
        case "$db" in
            "${u}_"*) printf '%s\n' "$u"; return 0 ;;
        esac
    done < <(hosting_users_all)
    return 1
}

# Grants held on a database by anyone other than its owner. Reported, never
# acted on: seoda_scanner@10.10.0.101 holds live grants on tuxxin_wst, and a
# prefix rule over mysql.user would destroy it while deleting `seoda`.
foreign_grants_on() {
    local db="$1" owner="$2"
    [ -z "$db" ] && return 1
    mysql_root -N -e "SELECT CONCAT(User,'@',Host) FROM mysql.db
        WHERE Db = '${db}' AND NOT (User = '${owner}' AND Host = 'localhost')" 2>/dev/null
}

# ==============================================================================
# Domains owned by an account — the union of every source
# ==============================================================================
# Generalized from the block that already fixed the Apache-down bug: the home
# directory alone is not authoritative, because a partial delete removes it and
# then the vhost survives pointing at a DocumentRoot that no longer exists,
# which stops Apache from starting at the next restart. Take the union of the
# filesystem, the panel DB, and the vhosts whose DocumentRoot is under the
# user's home.
domains_owned_by() {
    local u="$1" dir d conf doc
    [ -z "$u" ] && return 1

    {
        if [ -d "/home/$u" ]; then
            for dir in "/home/$u"/*/www; do
                [ -d "$dir" ] || continue
                d=$(basename "$(dirname "$dir")")
                [ "$d" = "tmp" ] && continue
                printf '%s\n' "$d"
            done
        fi

        if [ -f "$PANEL_DB" ]; then
            sqlite3 "$PANEL_DB" "SELECT d.domain_name FROM domains d
                JOIN hosting_users h ON d.hosting_user_id = h.id
                WHERE h.username = '${u}'" 2>/dev/null
        fi

        for conf in /etc/apache2/sites-available/*.conf; do
            [ -f "$conf" ] || continue
            doc=$(grep -oP 'DocumentRoot\s+\K\S+' "$conf" 2>/dev/null | head -1)
            case "$doc" in
                /home/"$u"/*) basename "$conf" .conf ;;
            esac
        done
    } | sort -u | grep -v '^$'
}

# ==============================================================================
# Deletion tombstones
# ==============================================================================
# A root-owned file, not a database row. The panel DB is itself a participant in
# the deletion and is restored from system_config_*.tgz, so a DB-resident
# tombstone can be rolled back by a restore that resurrects the very rows it was
# tracking. A file also stays writable when SQLite is WAL-locked, and www-data
# can read but never forge or clear a deletion intent.
#
# The tombstone is an INVENTORY and an advisory record. It is never a skip-list:
# a resumed run re-executes every step, because a ledger that says DONE is
# exactly how you build a resume path that skips the step that failed silently.
tomb_path() { printf '%s/user-%s.tomb\n' "$TOMB_DIR" "$1"; }

tomb_exists() { [ -f "$(tomb_path "$1")" ]; }

tomb_write() {
    local u="$1" key="$2" value="$3"
    mkdir -p "$TOMB_DIR" 2>/dev/null
    printf '%s=%s\n' "$key" "$value" >> "$(tomb_path "$u")"
}

tomb_get() {
    local u="$1" key="$2" f
    f=$(tomb_path "$u")
    [ -f "$f" ] || return 1
    grep "^${key}=" "$f" 2>/dev/null | tail -1 | cut -d= -f2-
}

tomb_clear() { rm -f "$(tomb_path "$1")"; }

# ==============================================================================
# Per-account lock
# ==============================================================================
# /run is tmpfs, so a lock can never outlive a crash — correct, because the
# tombstone is what is meant to survive, not the lock. Non-blocking on purpose:
# a blocking wait inside an FPM request just burns the request timeout, and a
# double-clicked delete button should be a no-op rather than two racing
# `userdel`/`rm -rf` runs.
lock_account() {
    local u="$1"
    mkdir -p "$LOCK_DIR" 2>/dev/null
    exec 200>>"${LOCK_DIR}/account-${u}.lock"
    if ! flock -n 200; then
        printf '%serror:%s another operation is already running for %s\n' "$RED" "$NC" "'$u'" >&2
        return 1
    fi
    return 0
}

# ==============================================================================
# Panel log
# ==============================================================================
# Mirrors service_monitor.sh's log_to_panel, including its SQL-quote escaping.
# Shell scripts otherwise log nowhere: output is captured only when PHP invoked
# them, and is discarded entirely on CLI runs.
log_to_panel() {
    local level="$1" message="$2" details="${3:-}"
    [ -f "$PANEL_DB" ] || return 0
    sqlite3 "$PANEL_DB" "INSERT INTO logs (source, level, message, details, user, created_at)
        VALUES ('account', '${level}',
                '$(printf '%s' "$message" | sed "s/'/''/g")',
                '$(printf '%s' "$details" | sed "s/'/''/g")',
                'system', datetime('now'));" 2>/dev/null
    return 0
}
