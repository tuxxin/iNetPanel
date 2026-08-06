#!/bin/bash
# ==============================================================================
# delete_user.sh — Delete a hosting user and everything it owns
#
# Usage: delete_user.sh --username <name> [--force] [--no-backup]
#
#   --force      delete even if domains remain (delete_account.sh passes this
#                once it has already removed them)
#   --no-backup  skip the final pre-deletion backup
#
# ==============================================================================
# WHAT CHANGED AND WHY
# ==============================================================================
# This script used to end on an `echo`, so it exited 0 no matter how many steps
# failed. PHP gates its panel-DB deletes on that exit code, so it deleted the
# hosting_users row — the only record naming what still needed destroying — and
# the leftover became invisible to the UI. Four accounts on one server were each
# left in a different state of partial deletion, and had to be found by hand.
#
# So every step here is: ATTEMPT -> VERIFY THE POST-CONDITION -> CLASSIFY.
# Never `cmd && step_ok`. Always re-observe the world. That is what makes
# idempotency and honest exit codes the same mechanism: a step whose
# post-condition already holds is OK on a resumed run, and a step that silently
# no-opped is FAIL on the first.
#
# It is therefore safe — and expected — to re-run this against a partially
# deleted account. It finishes the job rather than bailing.
#
# It also used to drop no databases at all. Only remove_domain.sh dropped any,
# and only ones named after the domain, so databases created through the panel
# portal (`<user>_<anything>`) survived every deletion. That was the single
# biggest orphan generator.
#
# `set -e` is deliberately NOT used: it would abort the cleanup at the first
# failure, which is the opposite of what a resumable teardown needs.
# ==============================================================================

source /root/scripts/lib_account.sh 2>/dev/null || {
    echo "error: /root/scripts/lib_account.sh not found — refusing to guess what to delete." >&2
    exit 1
}

SCRIPTS_DIR="/root/scripts"
USERNAME=""
FORCE=0
NO_BACKUP=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)  USERNAME="$2"; shift 2 ;;
        --force)     FORCE=1; shift ;;
        --no-backup) NO_BACKUP=1; shift ;;
        *) shift ;;
    esac
done

# --- 0. Validate ------------------------------------------------------------
# Everything below interpolates $USERNAME into rm -rf paths and database
# prefixes. Previously only a non-empty check stood in front of that.
validate_username "$USERNAME" || exit 1

# --- 1. Serialize -----------------------------------------------------------
# Two concurrent deletes of the same account interleave userdel and rm -rf, and
# each run's verification can fire part-way through the other's destruction.
lock_account "$USERNAME" || exit 3

echo -e "${BOLD}--- Deleting Hosting User: ${USERNAME} ---${NC}"

# --- 2. Inventory, taken before anything is destroyed -----------------------
# From the union of every source, because no single one stays authoritative once
# a partial delete has happened. This also goes into the tombstone, so a resumed
# run still knows what the account owned after the panel rows and the home
# directory are gone.
mapfile -t OWNED_DOMAINS < <(domains_owned_by "$USERNAME")
if mysql_available; then
    mapfile -t OWNED_DBS < <(dbs_owned_by "$USERNAME")
else
    OWNED_DBS=()
    echo -e "${YELLOW}warning: MariaDB is unreachable — databases cannot be enumerated.${NC}"
fi

# The remaining-domain guard now uses that union. The old check looked only at
# the filesystem and disagreed with the panel's own DB-only check: an account
# whose home was already gone passed both, and one with a home but no rows was
# rejected here and accepted by the API.
if [ "${#OWNED_DOMAINS[@]}" -gt 0 ] && [ "$FORCE" -eq 0 ]; then
    echo -e "${RED}User '${USERNAME}' still has ${#OWNED_DOMAINS[@]} domain(s):${NC}"
    printf '    %s\n' "${OWNED_DOMAINS[@]}"
    echo -e "${YELLOW}Remove them first, or pass --force.${NC}"
    exit 1
fi

if ! id "$USERNAME" &>/dev/null; then
    echo -e "${YELLOW}System user '${USERNAME}' does not exist — resuming cleanup of what remains.${NC}"
fi

echo -e "  Databases: ${BOLD}${#OWNED_DBS[@]}${NC}   Domains: ${BOLD}${#OWNED_DOMAINS[@]}${NC}"

# --- 3. Record intent BEFORE destroying anything ----------------------------
if tomb_exists "$USERNAME"; then
    echo -e "${YELLOW}Resuming an earlier deletion (started $(tomb_get "$USERNAME" started_at)).${NC}"
else
    tomb_write "$USERNAME" "username"   "$USERNAME"
    tomb_write "$USERNAME" "started_at" "$(date -Is)"
    tomb_write "$USERNAME" "started_by" "${SUDO_USER:-root}"
    tomb_write "$USERNAME" "home"       "/home/${USERNAME}"
    [ "${#OWNED_DOMAINS[@]}" -gt 0 ] && tomb_write "$USERNAME" "domains"   "${OWNED_DOMAINS[*]}"
    [ "${#OWNED_DBS[@]}" -gt 0 ]     && tomb_write "$USERNAME" "databases" "${OWNED_DBS[*]}"
    log_to_panel "WARNING" "Deletion started for ${USERNAME}" \
                 "${#OWNED_DBS[@]} database(s), ${#OWNED_DOMAINS[@]} domain(s)"
fi

# --- 4. Final backup --------------------------------------------------------
# The last chance to keep this data. Two accounts were deleted, orphaned, and
# then aged past the 3-day retention window with no backup anywhere. This writes
# to /backup/deleted/, which the retention sweep cannot reach.
#
# A failure here aborts with NOTHING destroyed — the strongest data-loss guard
# in the script, and the only step that stops the run.
section "Final backup"
EXISTING_BACKUP=$(tomb_get "$USERNAME" final_backup)
if [ "$NO_BACKUP" -eq 1 ]; then
    step_warn "skipped (--no-backup)"
elif [ -n "$EXISTING_BACKUP" ]; then
    # Already taken on an earlier attempt. Re-running it after destruction has
    # begun would produce a small, valid-looking archive.
    step_warn "already taken on an earlier run: ${EXISTING_BACKUP}"
elif bash "$SCRIPTS_DIR/backup_accounts.sh" --final "$USERNAME"; then
    ARCHIVE="/backup/deleted/${USERNAME}_$(date +%Y-%m-%d).tgz"
    tomb_write "$USERNAME" "final_backup" "$ARCHIVE"
    step_ok "saved ${ARCHIVE}"
else
    step_fail "final backup failed"
    echo ""
    echo -e "${RED}Refusing to delete '${USERNAME}' without a backup.${NC}"
    echo -e "${YELLOW}Nothing has been destroyed. Fix the backup, or re-run with --no-backup${NC}"
    echo -e "${YELLOW}if you accept losing this account's data.${NC}"
    exit 1
fi

# --- 5. Revoke access first -------------------------------------------------
# Capabilities before data, so that if the run dies part-way, what is left
# behind is inert rather than reachable.
section "Access"

# /etc/vsftpd.userlist gates FTP *and* the hosting portal (get_account_hash.sh,
# verify_account_credentials.py), so a surviving line is a live login.
#
# The old one-liner was `grep -vxF u file > /tmp/x && mv /tmp/x file`. Two bugs:
# grep exits 1 when the result is empty — i.e. when this user was the only entry,
# exactly when removal matters most — so the && skipped the mv; and /tmp/x was a
# predictable path. mktemp in /etc keeps the rename atomic and same-filesystem.
VSFTPD_LIST=/etc/vsftpd.userlist
if [ -f "$VSFTPD_LIST" ] && grep -qxF "$USERNAME" "$VSFTPD_LIST" 2>/dev/null; then
    VTMP=$(mktemp /etc/vsftpd.userlist.XXXXXX)
    grep -vxF "$USERNAME" "$VSFTPD_LIST" > "$VTMP"
    chmod --reference="$VSFTPD_LIST" "$VTMP" 2>/dev/null
    mv -f "$VTMP" "$VSFTPD_LIST"
    systemctl reload vsftpd 2>/dev/null || systemctl restart vsftpd 2>/dev/null
fi
if [ -f "$VSFTPD_LIST" ] && grep -qxF "$USERNAME" "$VSFTPD_LIST" 2>/dev/null; then
    step_fail "still listed in ${VSFTPD_LIST} — FTP and portal login remain open"
else
    step_ok "removed from ${VSFTPD_LIST}"
fi

# Lock before killing processes, so a later userdel failure leaves a locked
# account rather than a live one.
if id "$USERNAME" &>/dev/null; then
    usermod -L "$USERNAME" 2>/dev/null
    step_ok "password locked"
fi

# --- 6. Crontab, BEFORE userdel ---------------------------------------------
# Order matters twice over: `crontab -r -u` needs the passwd entry to exist, and
# `userdel -r` only removes the spool file while that entry still exists. The
# old fallback path (rm -rf /home) bypassed userdel entirely, so the crontab
# outlived the account.
#
# Why this is the most dangerous orphan: the file is keyed by NAME but owned by
# the numeric uid, and userdel returns that uid to the free pool. A future
# account created on that uid inherits the jobs — and, the file being mode 0600
# owned by that uid, can read the previous tenant's crontab.
section "Scheduled jobs"
CRONTAB_FILE="/var/spool/cron/crontabs/${USERNAME}"
if [ -e "$CRONTAB_FILE" ]; then
    JOB_COUNT=$(grep -cvE '^\s*(#|$)' "$CRONTAB_FILE" 2>/dev/null)
    crontab -r -u "$USERNAME" 2>/dev/null
    rm -f "$CRONTAB_FILE"
    if [ -e "$CRONTAB_FILE" ]; then
        step_fail "crontab still present: ${CRONTAB_FILE}"
    else
        step_ok "removed crontab (${JOB_COUNT:-0} job(s))"
    fi
else
    step_ok "no crontab"
fi

# --- 7. Stop the account's processes ----------------------------------------
if id "$USERNAME" &>/dev/null; then
    pkill -u "$USERNAME" 2>/dev/null
    sleep 2
    pkill -9 -u "$USERNAME" 2>/dev/null
    sleep 1
fi

# --- 8. MariaDB user, and who else can reach the data -----------------------
section "MariaDB"
if ! mysql_available; then
    step_fail "MariaDB unreachable — user and databases NOT removed"
else
    # Foreign grants are reported, never acted on. A service account can hold
    # grants on a database owned by a different hosting account, and a prefix
    # rule over mysql.user would destroy it while deleting an unrelated user.
    for DB in "${OWNED_DBS[@]}"; do
        [ -z "$DB" ] && continue
        while IFS= read -r grantee; do
            [ -z "$grantee" ] && continue
            step_warn "${grantee} also holds grants on ${DB} — dropping it will break them"
        done < <(foreign_grants_on "$DB" "$USERNAME")
    done

    # Exact match only. Never a prefix.
    mysql_root -e "DROP USER IF EXISTS '${USERNAME}'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
    STILL=$(mysql_root -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='${USERNAME}' AND Host='localhost'" 2>/dev/null)
    if [ "${STILL:-1}" = "0" ]; then
        step_ok "dropped MariaDB user '${USERNAME}'@'localhost'"
    else
        step_fail "MariaDB user '${USERNAME}'@'localhost' still present"
    fi

    # --- 9. Databases ------------------------------------------------------
    [ "${#OWNED_DBS[@]}" -eq 0 ] && step_ok "no databases owned by this account"
    for DB in "${OWNED_DBS[@]}"; do
        [ -z "$DB" ] && continue
        mysql_root -e "DROP DATABASE IF EXISTS \`${DB}\`;" 2>/dev/null
        GONE=$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB}'" 2>/dev/null)
        if [ "${GONE:-1}" = "0" ]; then
            step_ok "dropped database ${DB}"
        else
            step_fail "database ${DB} still present"
        fi
    done
fi

# --- 10. Identity and files -------------------------------------------------
section "System account"
if id "$USERNAME" &>/dev/null; then
    userdel -r "$USERNAME" 2>/dev/null
fi
if id "$USERNAME" &>/dev/null; then
    step_fail "system user still exists (processes may still be holding it)"
else
    step_ok "system user removed"
fi

# Verified independently of userdel: `userdel -r` can fail on busy files while
# still removing the passwd entry, and the old code printed success either way.
# ${USERNAME:?} makes an empty expansion abort rather than target /home.
[ -d "/home/$USERNAME" ] && rm -rf "/home/${USERNAME:?}" 2>/dev/null
if [ -d "/home/$USERNAME" ]; then
    step_fail "/home/${USERNAME} still present"
else
    step_ok "home directory removed"
fi

# --- 11. Panel records ------------------------------------------------------
# Deleted LAST, and only when everything above succeeded: they are the resume
# input, and destroying them first is what made orphans unfindable.
#
# The CLI path previously wrote nothing here at all, so `inetp delete_user` left
# hosting_users, domains and account_ports rows behind. PHP also deletes these
# on the API path; a second DELETE of an already-gone row is a harmless no-op.
section "Panel records"
PANEL_ROWS_KEPT=0
if [ "$FAILED" -gt 0 ]; then
    PANEL_ROWS_KEPT=1
    step_warn "left in place — ${FAILED} step(s) failed, so the account stays visible for a retry"
elif [ -f "$PANEL_DB" ]; then
    USER_ID=$(sqlite3 "$PANEL_DB" "SELECT id FROM hosting_users WHERE username='${USERNAME}'" 2>/dev/null)
    # Driven from the inventory, not from a subquery on `domains` — that also
    # reaps account_ports rows whose domains row was lost to an earlier partial
    # delete, and avoids the ordering bug where domains was deleted first.
    for D in "${OWNED_DOMAINS[@]}"; do
        [ -z "$D" ] && continue
        sqlite3 "$PANEL_DB" "DELETE FROM account_ports WHERE domain_name='${D}';" 2>/dev/null
    done
    [ -n "$USER_ID" ] && sqlite3 "$PANEL_DB" "DELETE FROM domains WHERE hosting_user_id=${USER_ID};" 2>/dev/null
    sqlite3 "$PANEL_DB" "DELETE FROM disk_cache WHERE username='${USERNAME}';"      2>/dev/null
    sqlite3 "$PANEL_DB" "DELETE FROM disk_cache_user WHERE username='${USERNAME}';" 2>/dev/null
    sqlite3 "$PANEL_DB" "DELETE FROM wg_peers WHERE hosting_user='${USERNAME}';"    2>/dev/null
    sqlite3 "$PANEL_DB" "DELETE FROM hosting_users WHERE username='${USERNAME}';"   2>/dev/null
    LEFT=$(sqlite3 "$PANEL_DB" "SELECT COUNT(*) FROM hosting_users WHERE username='${USERNAME}'" 2>/dev/null)
    if [ "${LEFT:-1}" = "0" ]; then
        step_ok "panel records removed"
    else
        step_fail "hosting_users row still present"
    fi
fi

# --- 12. Post-condition -----------------------------------------------------
# The same checker an operator would run by hand decides whether this worked.
# Audit exit 2 means "could not check", which must never be read as "clean".
section "Verification"
if [ -f "$SCRIPTS_DIR/audit_orphans.sh" ]; then
    # --ignore-pending: this run still holds its own tombstone at this moment.
    # Without it the audit would flag that tombstone, fail the post-condition,
    # and so prevent the tombstone from ever being cleared — a deletion that can
    # never succeed no matter how many times it is resumed.
    AUDIT_OUT=$(bash "$SCRIPTS_DIR/audit_orphans.sh" --user "$USERNAME" --quiet --ignore-pending 2>&1)
    AUDIT_RC=$?
    case "$AUDIT_RC" in
        0) step_ok "audit_orphans reports nothing left behind" ;;
        1) step_fail "audit_orphans still finds leftovers:"
           printf '%s\n' "$AUDIT_OUT" | grep -E 'FAIL|WARN' | sed 's/^/      /' ;;
        *) step_fail "audit_orphans could not complete (exit ${AUDIT_RC}) — treat as unverified" ;;
    esac
fi

# --- Result -----------------------------------------------------------------
echo ""
if [ "$FAILED" -gt 0 ]; then
    echo -e "${RED}${FAILED} step(s) failed — '${USERNAME}' is NOT fully deleted.${NC}"
    echo -e "${YELLOW}Intent kept at $(tomb_path "$USERNAME")${NC}"
    if [ "$PANEL_ROWS_KEPT" -eq 1 ]; then
        echo -e "${YELLOW}Panel records left in place on purpose, so it stays visible in the UI.${NC}"
    else
        echo -e "${YELLOW}Panel records were already removed; the tombstone is now the only record.${NC}"
    fi
    echo -e "${YELLOW}Re-run to resume:  inetp delete_user --username ${USERNAME} --force${NC}"
    log_to_panel "ERROR" "Deletion incomplete for ${USERNAME}" "${FAILED} step(s) failed"
    exit 1
fi

tomb_clear "$USERNAME"
[ "$WARNED" -gt 0 ] && echo -e "${YELLOW}${WARNED} warning(s) — review the output above.${NC}"
echo -e "${GREEN}Hosting user '${USERNAME}' fully deleted.${NC}"
log_to_panel "INFO" "Deleted hosting user ${USERNAME}" \
             "${#OWNED_DBS[@]} database(s), ${#OWNED_DOMAINS[@]} domain(s)"
exit 0
