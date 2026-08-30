# Working on iNetPanel

Notes for an agent picking this up cold. Read `ROADMAP.md` next — its
**DO THIS FIRST** section is the live queue.

## What this is

A Debian LAMP hosting control panel (PHP "TiCore" framework + bash scripts run
as root via sudo). Repo `tuxxin/iNetPanel`, owner Tuxxin LLC. It ships to real
users, so a broken release costs them confidence — treat every push that way.

## Layout

| Path | Purpose |
|---|---|
| `TiCore/` | Framework: `Shell.php` (sudo wrapper + subcommand allowlist), `DB.php`, `Auth.php`, `Version.php` |
| `api/` | JSON endpoints (`?action=`) |
| `src/` | Page templates |
| `scripts/system/` | Root shell scripts, deployed to `/root/scripts/` |
| `scripts/system/inetp` | CLI dispatcher, deployed to `/usr/local/bin/inetp` |
| `scripts/panel_update.php` | The self-updater. Also writes sudoers and all crons. |
| `install_LAMP.sh` | Installer. Also published to inetpanel.info. |
| `release.sh` | Build + tag + publish. |

## Release process

Two channels:

- **Push to `main` = beta.** Beta installs pull the `main` zipball. Anything you
  push is live for beta users within a day. There is no staging.
- **Tagged release = stable.** `./release.sh --version X.Y.Z --yes --notes "..."`
  runs preflight, CI-mirror lint, gitleaks, bumps `TiCore/Version.php`, tags,
  pushes, builds three assets, publishes the GitHub Release, and syncs
  inetpanel.info. **`--yes` is required non-interactively** — without it the
  script stops at a `Proceed? [y/N]` prompt and silently does nothing.

CI mirrors: `bash -n` and `shellcheck -S error` on every `*.sh`, `php -l` on
every `*.php`, gitleaks over full history. Run all three locally before pushing;
`release.sh` runs them too but failing there wastes a version number.

Credentials live in `/root/.env` (`GITHUB_TOKEN`). Never copy the value into a
file, a commit, or a log line — reference the path.

## Deployment model, and the trap in it

`panel_update.php` rsyncs the panel into `/var/www/inetpanel`, then deploys:
`scripts/system/*.sh` → `/root/scripts/`, `inetp` → `/usr/local/bin/inetp`,
`*.py` → `/root/scripts/`, plus crons and `/etc/sudoers.d/inetpanel`.

**A fix that ships inside `panel_update.php` cannot repair the box it arrives
on.** PHP compiles the whole file at start, so the update that delivers your fix
is still executing the previous version. Anything self-repairing needs a second
pass — that is what `--deploy-only` exists for.

## Hard-won gotchas

These each cost real debugging time. Do not rediscover them.

- **`/etc/cron.d` files do not inherit `PATH` from `/etc/crontab`.** Cron's
  default is `/usr/bin:/bin`, which excludes `/usr/sbin` — so `apache2ctl`,
  `a2enconf`, `a2disconf`, `systemctl`-adjacent tools silently vanish under
  cron. This deleted a working Apache config every Monday (fixed in 1.27.3).
  Every cron this project writes must set `PATH` explicitly and log somewhere
  real, never `>/dev/null`.
- **systemd `ProtectSystem=yes` on php-fpm makes `/usr` read-only** in a mount
  namespace inherited by every child. `sudo` raises privilege but does **not**
  escape it, so a panel-triggered update gets `EROFS` writing
  `/usr/local/bin/inetp` while `/etc` and `/root` succeed. `install_root_file()`
  in `panel_update.php` falls back to `systemd-run`, which has PID 1 spawn the
  process in the host namespace. Verify namespace-sensitive changes with
  `nsenter -t $(systemctl show -p MainPID --value php8.5-fpm) -m`.
- **Never log success outside the failure branch.** This codebase had the
  pattern in three places; each hid a real bug for weeks. `Deployed N scripts`
  was printed whether or not any copy worked.
- **`settings` is `(key, value, category, updated_at)`.** A two-value
  `INSERT OR REPLACE` is rejected. Name the columns.
- **Every hosting tenant is `gid 33 (www-data)`.** A `0770 root:www-data`
  directory is therefore writable by all of them. Owner-only `0700` is what
  excludes them.
- **Never stage files for a privileged command in `/tmp`** (mode 1777). Use
  `Shell::stage()`.
- **Hosted sites run as their own tenant uid, not `www-data`.** A compromised
  site lands at tenant level, which is exactly the privilege these bugs escalate
  from. "Single-user home server" does not mean "no untrusted code" — the
  websites are the untrusted code.
- **The `'8.4'` PHP fallbacks.** ~34 remain across the tree while the installed
  default is 8.5. They fail silently when they fire.

## Testing

Test the **feature**, not the mechanism. Several bugs here passed inspection and
failed in reality:

- Verify in the context that actually fails. A repair tested from a root shell
  proved nothing about the FPM namespace where it ran.
- This box is IPv4-only, which hid a `curl` bug that only appears on dual-stack
  hosts. Check whether your environment can even reproduce the failure.
- When a change moves a path something else references, grep for **every**
  reference. Repointing a sudoers rule without updating three callers silently
  disabled admin hook scripts in 1.27.1.
