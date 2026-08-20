#!/usr/bin/env python3
"""
ht_manage.py — the ONLY root-privileged path to a tenant's .htaccess/.htpasswd.

WHY THIS EXISTS
---------------
The account portal used to build these paths in PHP and hand absolute paths to
broad sudo grants:

    www-data ALL=(root) NOPASSWD: /bin/cp /tmp/inetp_ht_* /home/*
    www-data ALL=(root) NOPASSWD: /bin/cat /home/*/.htaccess
    www-data ALL=(root) NOPASSWD: /bin/chown *\\:www-data /home/*

validateDirPath() realpath()-resolved the DIRECTORY, then the caller appended
'/.htaccess' afterwards. That final component was never resolved. A tenant owns
their document root over SFTP, so replacing .htaccess with a symlink made root
`cp`/`cat`/`chown` follow it: arbitrary root read (/etc/shadow,
/root/.mysql_root_pass), arbitrary root write (/etc/cron.d/*,
/root/.ssh/authorized_keys), and arbitrary root chown. Full local privilege
escalation from the lowest-privilege account the panel issues.

Reported privately as GHSA-mjmx-xpqq-p2h8 (CVSS 8.8, CWE-59).

THE RULE THIS ENFORCES
----------------------
The caller supplies an identity and a RELATIVE directory. It never supplies a
path to operate on. This process derives the document root from the panel
database, re-validates ownership, resolves the directory itself, and opens the
final file with O_NOFOLLOW so the kernel refuses a symlink rather than trusting
a check that a tenant could win a race against.

Usage:
    ht_manage.py --user U --domain D --dir REL --file htaccess|htpasswd
                 --action read|write|delete [--content-file PATH]

Exit: 0 ok, 1 refused/failed. `read` writes the content to stdout.
"""

import argparse
import grp
import os
import pwd
import re
import sqlite3
import stat
import sys

PANEL_DB = "/var/www/inetpanel/db/inetpanel.db"
USERNAME_RE = re.compile(r"^[a-z][a-z0-9-]{0,31}$")
# Only these two basenames are ever addressable. Not a caller-supplied filename.
FILES = {"htaccess": (".htaccess", 0o644), "htpasswd": (".htpasswd", 0o640)}


def die(msg):
    print(f"ht_manage: {msg}", file=sys.stderr)
    sys.exit(1)


def main():
    ap = argparse.ArgumentParser(add_help=False)
    ap.add_argument("--user", required=True)
    ap.add_argument("--domain", required=True)
    ap.add_argument("--dir", default="")
    ap.add_argument("--file", required=True, choices=sorted(FILES))
    ap.add_argument("--action", required=True, choices=["read", "write", "delete"])
    ap.add_argument("--content-file")
    args = ap.parse_args()

    if not USERNAME_RE.match(args.user):
        die("invalid username")
    try:
        pw = pwd.getpwnam(args.user)
    except KeyError:
        die("no such system user")
    if pw.pw_uid < 1000:
        die("refusing to operate as a system account")

    # Ownership comes from the panel database, never from the caller. This is
    # what stops one tenant naming another tenant's domain.
    try:
        con = sqlite3.connect(f"file:{PANEL_DB}?mode=ro", uri=True)
        row = con.execute(
            "SELECT d.document_root FROM domains d "
            "JOIN hosting_users h ON d.hosting_user_id = h.id "
            "WHERE d.domain_name = ? AND h.username = ?",
            (args.domain, args.user),
        ).fetchone()
        con.close()
    except sqlite3.Error as e:
        die(f"panel database unreadable: {e}")
    if not row:
        die("domain does not belong to this account")

    docroot = os.path.realpath(row[0] or f"/home/{args.user}/{args.domain}/www")
    home = os.path.realpath(f"/home/{args.user}")
    # The document root itself must sit under the tenant's home, so a tampered
    # database row cannot point the whole operation somewhere else.
    if not (docroot == home or docroot.startswith(home + os.sep)):
        die("document root is outside the account home")

    rel = args.dir.strip().lstrip("/")
    if ".." in rel.split(os.sep):
        die("invalid directory")
    target_dir = os.path.realpath(os.path.join(docroot, rel))
    if not (target_dir == docroot or target_dir.startswith(docroot + os.sep)):
        die("directory escapes the document root")
    if not os.path.isdir(target_dir):
        die("directory does not exist")

    basename, mode = FILES[args.file]
    target = os.path.join(target_dir, basename)

    if args.action == "read":
        try:
            # O_NOFOLLOW is the whole point: the kernel returns ELOOP on a
            # symlink, so there is no window between checking and opening.
            fd = os.open(target, os.O_RDONLY | os.O_NOFOLLOW)
        except FileNotFoundError:
            return 0
        except OSError as e:
            die(f"refusing to read {basename}: {e.strerror}")
        with os.fdopen(fd, "rb") as fh:
            sys.stdout.buffer.write(fh.read())
        return 0

    if args.action == "delete":
        try:
            os.unlink(target)  # unlink removes the link, never the target
        except FileNotFoundError:
            pass
        except OSError as e:
            die(f"could not remove {basename}: {e.strerror}")
        return 0

    # write
    if not args.content_file:
        die("--content-file is required for write")
    try:
        with open(args.content_file, "rb") as fh:
            content = fh.read()
    except OSError as e:
        die(f"cannot read staged content: {e.strerror}")

    # Drop an existing symlink first so the create below cannot be aimed
    # elsewhere. If the tenant re-plants it in between, O_NOFOLLOW fails the
    # open rather than following it.
    try:
        if stat.S_ISLNK(os.lstat(target).st_mode):
            os.unlink(target)
    except FileNotFoundError:
        pass

    try:
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, mode)
    except OSError as e:
        die(f"refusing to write {basename}: {e.strerror}")

    # Everything below acts on the descriptor we just opened, never on the path
    # again — the path could be swapped underneath us between calls.
    try:
        os.write(fd, content)
        os.fchown(fd, pw.pw_uid, grp.getgrnam("www-data").gr_gid)
        os.fchmod(fd, mode)
    finally:
        os.close(fd)
    return 0


if __name__ == "__main__":
    sys.exit(main())
