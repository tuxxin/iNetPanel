#!/bin/bash
# ==============================================================================
# restore_helper.sh — privileged steps for the backup-restore workflow
#
# Replaces a pair of "write a shell script, then sudo bash it" hooks in
# api/restore.php. That pattern needed a wildcard sudoers grant:
#
#     www-data ALL=(root) NOPASSWD: /bin/bash /var/lib/inetpanel/staging/inetp_hook_*
#
# which is arbitrary root code execution for anyone who can write a matching
# filename — by design, not by accident. Confirmed on a live box: www-data owns
# the staging directory, so it could drop any script there and run it as uid 0.
# Moving the staging directory out of /tmp closed the tenant path to that grant
# (GHSA-mjmx-xpqq-p2h8 side-effect fix, 1.27.1) but left the grant itself as a
# straight www-data -> root escalation, which matters because compromising the
# panel is exactly what that CVE demonstrated.
#
# Both callers were entirely fixed-purpose — no caller-supplied paths, users or
# arguments — so they become argument-free subcommands here and the wildcard
# grant is dropped. Nothing in this script reads argv beyond the subcommand name.
#
# Usage: inetp restore_helper prepare_staging
#        inetp restore_helper setup_ftp_user
# ==============================================================================

set -u

# Fixed, not configurable. A caller cannot influence where any of this lands.
STAGING_DIR="/backup/restore_staging"
FTP_USER="restore"
VSFTPD_LIST="/etc/vsftpd.userlist"

die() { echo "error: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "must run as root"

case "${1:-}" in
    prepare_staging)
        # Refuse to follow a symlink planted at the staging path.
        if [ -L "$STAGING_DIR" ]; then
            die "$STAGING_DIR is a symlink — refusing to operate on it"
        fi
        mkdir -p "$STAGING_DIR" || die "could not create $STAGING_DIR"
        chown www-data:www-data "$STAGING_DIR"
        chmod 0770 "$STAGING_DIR"
        echo "staging directory ready: $STAGING_DIR"
        ;;

    setup_ftp_user)
        if [ -L "$STAGING_DIR" ]; then
            die "$STAGING_DIR is a symlink — refusing to operate on it"
        fi
        mkdir -p "$STAGING_DIR"
        chown www-data:www-data "$STAGING_DIR"
        chmod 0770 "$STAGING_DIR"

        if id "$FTP_USER" >/dev/null 2>&1; then
            usermod -s /bin/bash -d "$STAGING_DIR" "$FTP_USER" \
                || die "could not update $FTP_USER"
        else
            useradd -d "$STAGING_DIR" -s /bin/bash -g www-data "$FTP_USER" \
                || die "could not create $FTP_USER"
        fi

        # Reuse root's stored hash so no plaintext password is handled anywhere.
        ROOT_HASH=$(getent shadow root | cut -d: -f2)
        [ -n "$ROOT_HASH" ] || die "could not read root's password hash"
        usermod -p "$ROOT_HASH" "$FTP_USER" || die "could not set $FTP_USER password"

        grep -qx "$FTP_USER" "$VSFTPD_LIST" 2>/dev/null \
            || echo "$FTP_USER" >> "$VSFTPD_LIST"
        systemctl reload vsftpd 2>/dev/null || true

        echo "ftp user ready: $FTP_USER"
        ;;

    *)
        die "unknown subcommand: ${1:-<none>} (expected prepare_staging or setup_ftp_user)"
        ;;
esac
