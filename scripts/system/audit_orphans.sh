#!/bin/bash
# ==============================================================================
# audit_orphans.sh — reconcile hosting accounts against everything they own
# Usage: inetp audit_orphans [--user <name>] [--quiet]
#
# WHY THIS EXISTS
# ---------------
# Account deletion touches nine different systems and, until recently, reported
# success whether or not any of them worked. When a step silently failed, the
# panel row that named the resource was deleted anyway — so the leftover became
# invisible to the UI and could only be found by hand. Four accounts on one
# server were each left in a different state of partial deletion.
#
# This command asks the opposite question to the panel: not "what should exist?"
# but "what exists that shouldn't, and what should exist that doesn't?" It reads
# every source of truth and reports divergence in BOTH directions.
#
# It is READ-ONLY. It never removes anything, and it deliberately has no --fix
# mode: an auditor that also destroys has a blast radius equal to whatever its
# detection logic happens to believe. To clean up, use the deletion path, which
# validates, locks, takes a final backup and records intent:
#
#     inetp delete_user --username <name> --force
#
# EXIT CODES
#   0  no failures
#   1  at least one FAIL
#   2  could not check (panel DB missing, MariaDB unreachable)
#
# The 2 is load-bearing. Deletion runs this as a post-condition, and must never
# read "I couldn't check" as "everything is clean".
#
# OUTPUT
# ------
# ANSI is stripped before this reaches the panel UI, so the uppercase status
# WORDS carry the meaning, not the colour.
# ==============================================================================

source /root/scripts/lib_account.sh 2>/dev/null || {
    echo "error: /root/scripts/lib_account.sh not found — cannot verify ownership rules safely." >&2
    exit 2
}

SCOPE_USER=""
QUIET=0
IGNORE_PENDING=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --user)   SCOPE_USER="$2"; shift 2 ;;
        --quiet)  QUIET=1; shift ;;
        # Used only by the deletion path when it audits its own work. A deletion
        # still holds its own tombstone at the moment it self-checks, and without
        # this it would fail its own post-condition, refuse to clear the
        # tombstone, and so never be able to succeed. The deletion owns that
        # tombstone's lifecycle; it does not need the audit to report it back.
        --ignore-pending) IGNORE_PENDING=1; shift ;;
        *) shift ;;
    esac
done

PASS=0; WARN=0; FAIL=0

result() {
    local status="$1" msg="$2"
    case "$status" in
        PASS) PASS=$((PASS + 1)); [ "$QUIET" -eq 1 ] && return 0
              printf '  %s✓ PASS%s  %s\n' "$GREEN" "$NC" "$msg" ;;
        WARN) WARN=$((WARN + 1)); printf '  %s! WARN%s  %s\n' "$YELLOW" "$NC" "$msg" ;;
        FAIL) FAIL=$((FAIL + 1)); printf '  %s✗ FAIL%s  %s\n' "$RED" "$NC" "$msg" ;;
    esac
    return 0
}

detail() { [ "$QUIET" -eq 1 ] || printf '          %s%s%s\n' "$DIM" "$*" "$NC"; }

# in_scope — with --user, only that account is examined.
in_scope() { [ -z "$SCOPE_USER" ] || [ "$1" = "$SCOPE_USER" ]; }

RULE='═══════════════════════════════════════════════════'
printf '%s%s%s\n' "$BOLD" "$RULE" "$NC"
if [ -n "$SCOPE_USER" ]; then
    printf '%s  Orphan Audit — %s%s\n' "$BOLD" "$SCOPE_USER" "$NC"
else
    printf '%s  iNetPanel Orphan Audit%s\n' "$BOLD" "$NC"
fi
printf '%s%s%s\n' "$BOLD" "$RULE" "$NC"

# --- Preconditions -----------------------------------------------------------
if [ ! -f "$PANEL_DB" ]; then
    printf '\n%serror:%s panel database not found: %s\n' "$RED" "$NC" "$PANEL_DB" >&2
    exit 2
fi
MYSQL_UP=1
mysql_available || MYSQL_UP=0

KNOWN_USERS=$(hosting_users_all)
PANEL_USERS=$(sqlite3 "$PANEL_DB" "SELECT username FROM hosting_users" 2>/dev/null | sort)

# ==============================================================================
section "System users"
# ==============================================================================
while IFS= read -r u; do
    [ -z "$u" ] && continue
    in_scope "$u" || continue
    missing=""
    id "$u" >/dev/null 2>&1 || missing="${missing} passwd-entry"
    [ -d "/home/$u" ]       || missing="${missing} /home/$u"
    if [ -n "$missing" ]; then
        result FAIL "$u — panel row exists but missing:${missing}"
        detail "the panel believes this account is live; parts of it are gone"
    else
        result PASS "$u — panel row, system user and home all present"
    fi
done <<< "$PANEL_USERS"

# Reverse direction: a home directory or shell account with no panel row.
for dir in /home/*/; do
    [ -d "$dir" ] || continue
    u=$(basename "$dir")
    in_scope "$u" || continue
    grep -qx "$u" <<< "$PANEL_USERS" && continue
    for reserved in $LIB_RESERVED_USERS; do
        [ "$u" = "$reserved" ] && continue 2
    done
    size=$(du -sh "$dir" 2>/dev/null | cut -f1)
    if id "$u" >/dev/null 2>&1; then
        result FAIL "$u — /home/$u exists (${size:-?}) with a system user but NO panel row"
        detail "invisible to the UI; will never be backed up again"
    else
        result FAIL "$u — /home/$u exists (${size:-?}) with no system user and no panel row"
    fi
done

# ==============================================================================
section "FTP / portal allowlist"
# ==============================================================================
# /etc/vsftpd.userlist is not just an FTP list — get_account_hash.sh and
# verify_account_credentials.py both gate the hosting PORTAL login on it. A
# stale entry is a live login capability, not cosmetic cruft.
VSFTPD_LIST=/etc/vsftpd.userlist
if [ -f "$VSFTPD_LIST" ]; then
    while IFS= read -r entry; do
        [ -z "$entry" ] && continue
        in_scope "$entry" || continue
        if ! id "$entry" >/dev/null 2>&1; then
            result FAIL "$entry — in $VSFTPD_LIST with no system user"
            detail "this file also authorizes hosting-portal login; remove the line"
        elif is_hosting_user "$entry"; then
            result PASS "$entry — allowlisted and is a hosting account"
        else
            result WARN "$entry — allowlisted, real system user, but not a hosting account"
            detail "grants FTP and portal login to a non-hosting account"
        fi
    done < "$VSFTPD_LIST"

    while IFS= read -r u; do
        [ -z "$u" ] && continue
        in_scope "$u" || continue
        grep -qxF "$u" "$VSFTPD_LIST" 2>/dev/null \
            || result WARN "$u — hosting account missing from $VSFTPD_LIST (cannot use FTP or the portal)"
    done <<< "$PANEL_USERS"
else
    result WARN "$VSFTPD_LIST does not exist"
fi

# ==============================================================================
section "Crontabs"
# ==============================================================================
# The most dangerous orphan class. The file is keyed by NAME but owned by the
# numeric uid, and userdel returns that uid to the free pool. A future account
# can therefore be created on a uid that inherits a dead account's jobs — and,
# because the file is mode 0600 owned by that uid, the new tenant can read the
# previous tenant's crontab, which routinely contains paths and credentials.
CRON_DIR=/var/spool/cron/crontabs
if [ -d "$CRON_DIR" ]; then
    for ct in "$CRON_DIR"/*; do
        [ -f "$ct" ] || continue
        u=$(basename "$ct")
        in_scope "$u" || continue
        if id "$u" >/dev/null 2>&1; then
            result PASS "$u — crontab belongs to an existing user"
        else
            jobs=$(grep -cvE '^\s*(#|$)' "$ct" 2>/dev/null)
            owner_uid=$(stat -c %u "$ct" 2>/dev/null)
            result FAIL "$u — crontab with ${jobs:-?} job(s) but no system user"
            detail "$ct is owned by uid ${owner_uid:-?}, which is back in the free pool"
            detail "cron keeps firing these against a home directory that is gone"
        fi
    done
else
    result WARN "$CRON_DIR does not exist"
fi

# ==============================================================================
section "Databases"
# ==============================================================================
if [ "$MYSQL_UP" -eq 0 ]; then
    result WARN "MariaDB unreachable — database and grant checks skipped"
else
    while IFS= read -r db; do
        [ -z "$db" ] && continue
        skip=0
        for sys in $LIB_SYSTEM_SCHEMAS; do
            [ "$db" = "$sys" ] && skip=1 && break
        done
        [ "$skip" -eq 1 ] && continue

        owner=$(db_owner "$db")
        if [ -z "$owner" ]; then
            in_scope "" || continue
            [ -n "$SCOPE_USER" ] && case "$db" in "${SCOPE_USER}_"*) ;; *) continue ;; esac
            size=$(mysql_root -N -e "SELECT ROUND(SUM(data_length+index_length)/1024/1024,0)
                   FROM information_schema.TABLES WHERE table_schema='${db}'" 2>/dev/null)
            result FAIL "$db — no hosting account owns this database (${size:-0} MB)"
            detail "no username is a literal '<user>_' prefix of this name"
        else
            in_scope "$owner" || continue
            if grep -qx "$owner" <<< "$PANEL_USERS"; then
                result PASS "$db — owned by $owner"
            else
                result FAIL "$db — owner '$owner' has no panel row"
            fi
        fi
    done < <(mysql_root -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA" 2>/dev/null)
fi

# ==============================================================================
section "MariaDB users"
# ==============================================================================
if [ "$MYSQL_UP" -eq 0 ]; then
    result WARN "MariaDB unreachable — user check skipped"
else
    SYSTEM_MYSQL_USERS="root mysql mariadb.sys phpmyadmin debian-sys-maint"
    while IFS=$'\t' read -r muser mhost; do
        [ -z "$muser" ] && continue
        skip=0
        for s in $SYSTEM_MYSQL_USERS; do
            [ "$muser" = "$s" ] && skip=1 && break
        done
        [ "$skip" -eq 1 ] && continue

        if [ "$mhost" != "localhost" ]; then
            # Not panel-managed. Report once so it is visible and explicitly
            # out of scope — a prefix rule over mysql.user would destroy these.
            [ -n "$SCOPE_USER" ] && continue
            result WARN "${muser}@${mhost} — service account, not managed by the panel"
            detail "never matched by deletion, which uses exact '<user>'@'localhost'"
            continue
        fi

        in_scope "$muser" || continue
        if is_hosting_user "$muser"; then
            result PASS "${muser}@localhost — matches a hosting account"
        else
            result FAIL "${muser}@localhost — MariaDB user with no hosting account"
        fi
    done < <(mysql_root -N -e "SELECT User, Host FROM mysql.user" 2>/dev/null)

    while IFS= read -r u; do
        [ -z "$u" ] && continue
        in_scope "$u" || continue
        n=$(mysql_root -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='${u}' AND Host='localhost'" 2>/dev/null)
        [ "${n:-0}" -eq 0 ] && result WARN "$u — hosting account with no MariaDB user"
    done <<< "$PANEL_USERS"
fi

# ==============================================================================
section "Pending deletions"
# ==============================================================================
found_tomb=0
if [ "$IGNORE_PENDING" -eq 1 ]; then
    found_tomb=1
    result PASS "pending-deletion check skipped (--ignore-pending)"
elif [ -d "$TOMB_DIR" ]; then
    for t in "$TOMB_DIR"/user-*.tomb; do
        [ -f "$t" ] || continue
        u=$(basename "$t" .tomb); u=${u#user-}
        in_scope "$u" || continue
        found_tomb=1
        started=$(grep '^started_at=' "$t" 2>/dev/null | tail -1 | cut -d= -f2-)
        result FAIL "$u — deletion started ${started:-at an unknown time} and never completed"
        detail "resume with:  inetp delete_user --username $u --force"
    done
fi
[ "$found_tomb" -eq 0 ] && result PASS "no interrupted deletions"

# ==============================================================================
# Whole-server checks — skipped when scoped to one account
# ==============================================================================
if [ -z "$SCOPE_USER" ]; then

section "Apache vhosts"
for conf in /etc/apache2/sites-available/*.conf; do
    [ -f "$conf" ] || continue
    name=$(basename "$conf" .conf)
    case "$name" in 000-default|default-ssl|phpmyadmin) continue ;; esac
    doc=$(grep -oP 'DocumentRoot\s+\K\S+' "$conf" 2>/dev/null | head -1)
    case "$doc" in
        /home/*)
            vuser=$(printf '%s' "$doc" | cut -d/ -f3)
            if id "$vuser" >/dev/null 2>&1; then
                result PASS "$name — DocumentRoot owned by existing user $vuser"
            else
                result FAIL "$name — DocumentRoot under /home/$vuser, which has no system user"
                detail "a missing log directory here is fatal at the next Apache restart"
            fi
            ;;
        *) [ -n "$doc" ] && result WARN "$name — DocumentRoot outside /home: $doc" ;;
    esac
done

section "PHP-FPM pools"
for pool in /etc/php/*/fpm/pool.d/*.conf; do
    [ -f "$pool" ] || continue
    pname=$(basename "$pool" .conf)
    [ "$pname" = "www" ] && continue
    puser=$(grep -oP '^\s*user\s*=\s*\K\S+' "$pool" 2>/dev/null | head -1)
    if [ -n "$puser" ] && ! id "$puser" >/dev/null 2>&1; then
        result FAIL "$pname — pool runs as '$puser', which has no system user"
    else
        result PASS "$pname — pool user exists"
    fi
done
for stale in /etc/php/*/fpm/pool.d/*.conf.bak*; do
    [ -f "$stale" ] || continue
    result WARN "$(basename "$stale") — leftover pool backup, never reaped by any glob"
done

section "Certificates"
PANEL_HOST=$(hostname -f 2>/dev/null || hostname)
for cert in /etc/letsencrypt/live/*/; do
    [ -d "$cert" ] || continue
    cdom=$(basename "$cert")
    [ "$cdom" = "README" ] && continue
    [ "$cdom" = "$PANEL_HOST" ] && continue
    if [ -f "/etc/apache2/sites-available/${cdom}.conf" ]; then
        result PASS "$cdom — certificate has a matching vhost"
    else
        n=$(sqlite3 "$PANEL_DB" "SELECT COUNT(*) FROM domains WHERE domain_name='${cdom}'" 2>/dev/null)
        [ "${n:-0}" -gt 0 ] && result WARN "$cdom — certificate and panel row, but no vhost" \
                            || result WARN "$cdom — certificate with no vhost and no panel row"
    fi
done

section "Ports"
PORTS_CONF=/etc/apache2/ports_domains.conf
if [ -f "$PORTS_CONF" ]; then
    # comm compares LEXICALLY, so both sides must be sorted lexically — `sort -un`
    # here silently produces garbage (it reported every port as orphaned).
    # Scan only *.conf: sites-available also holds .conf.bak files, which Apache
    # ignores and which would otherwise contribute phantom ports.
    listen_ports=$(grep -oE '^Listen[[:space:]]+[0-9]+' "$PORTS_CONF" 2>/dev/null | awk '{print $2}' | sort -u)
    vhost_ports=$(cat /etc/apache2/sites-available/*.conf 2>/dev/null \
                  | grep -oE '<VirtualHost \*:[0-9]+>' | grep -oE '[0-9]+' | sort -u)
    orphan_listen=$(comm -23 <(printf '%s\n' "$listen_ports") <(printf '%s\n' "$vhost_ports"))
    if [ -n "$orphan_listen" ]; then
        for p in $orphan_listen; do
            result WARN "port $p — Listen line with no vhost using it (port is burned)"
        done
    else
        result PASS "every Listen line has a matching vhost"
    fi
    while IFS='|' read -r apdomain apprort; do
        [ -z "$apdomain" ] && continue
        [ -f "/etc/apache2/sites-available/${apdomain}.conf" ] && continue
        result WARN "account_ports row for '$apdomain' (port ${apprort}) has no vhost"
    done < <(sqlite3 "$PANEL_DB" "SELECT domain_name, port FROM account_ports" 2>/dev/null)
fi

section "MariaDB plugin configs"
# Issue #22: a partial upgrade leaves mariadb-plugin-provider-* out of step with
# mariadb-server, and the daemon then refuses to start on the next restart —
# "unknown variable 'provider_bzip2=force_plus_permanent'". These .cnf files are
# owned by the Debian packages, NOT written by the panel, so this reports only.
if command -v dpkg-query >/dev/null 2>&1; then
    server_ver=$(dpkg-query -W -f='${Version}' mariadb-server 2>/dev/null)
    if [ -n "$server_ver" ]; then
        mismatch=0
        while IFS=' ' read -r pkg pver; do
            [ -z "$pkg" ] && continue
            if [ "$pver" != "$server_ver" ]; then
                result FAIL "$pkg $pver does not match mariadb-server $server_ver"
                detail "MariaDB will refuse to start on its next restart"
                mismatch=1
            fi
        done < <(dpkg-query -W -f='${Package} ${Version}\n' 'mariadb-plugin-provider-*' 2>/dev/null | grep -v '^$')
        [ "$mismatch" -eq 0 ] && result PASS "MariaDB provider plugins match mariadb-server $server_ver"
        if [ "$mismatch" -eq 1 ]; then
            detail "fix with: apt-get install --reinstall mariadb-server 'mariadb-plugin-provider-*'"
            detail "do NOT rename the .cnf files — they are package-owned and will return"
        fi
    fi
fi

fi  # end whole-server checks

# ==============================================================================
printf '\n%s%s%s\n' "$BOLD" "$RULE" "$NC"
TOTAL=$((PASS + WARN + FAIL))
printf '%s  Orphan Audit: %s%s passed%s  %s%s warnings%s  %s%s failed%s  (%s checks)%s\n' \
    "$BOLD" "$GREEN" "$PASS" "$NC" "$YELLOW" "$WARN" "$NC" "$RED" "$FAIL" "$NC" "$TOTAL" "$NC"
printf '%s%s%s\n' "$BOLD" "$RULE" "$NC"

if [ "$FAIL" -gt 0 ]; then
    printf '\n%sThis audit reports only. To clean an account up safely, use the deletion\n' "$DIM"
    printf 'path, which validates, locks, backs up and records intent:%s\n' "$NC"
    printf '    inetp delete_user --username <name> --force\n'
fi

[ "$FAIL" -gt 0 ] && exit 1
exit 0
