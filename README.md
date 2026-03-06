# iNetPanel

A self-hosted web hosting control panel for Debian 12. Manage Apache virtual hosts, PHP versions, MariaDB databases, FTP accounts, SSH keys, DNS records, email forwarding, backups, and WireGuard VPN — all from a single admin interface.

---

## The Story

I've always wanted to build a cPanel-like system for home hosting — a proper control panel that gives you full ownership over your server without the cost or complexity of commercial solutions. Before AI-assisted development, the ongoing upkeep and breadth of a project like this simply wasn't feasible as an open-source effort. With the help of AI, it has finally come to fruition.

iNetPanel is the result: a capable, modern hosting panel built for people who want to self-host seriously — with Cloudflare integration, VPN-secured access, and automated management that doesn't require a team to maintain.

---

## Features

- **Account Management** — Create and delete hosting accounts (Apache vhost + PHP-FPM pool + Linux user + FTP) from the panel
- **Multi-PHP** — Install and switch between multiple PHP versions (5.6–8.4) per domain using sury.org packages
- **SSH Key Manager** — Import, generate, and delete SSH keys for any hosting account or the root user without touching the command line
- **Cloudflare Zero Trust Tunnel** — Automatic tunnel created at install; each new hosting account gets a published public hostname routed through the tunnel (no open ports required)
- **Cloudflare DNS** — Full DNS record management via Cloudflare API (A, CNAME, MX, TXT, etc.)
- **Email Forwarding** — Manage Cloudflare Email Routing rules per domain
- **Cloudflare DDNS** — Automatically update a DNS A record when your server's IP changes
- **WireGuard VPN** — Optional VPN with auto-provisioned peer configs per hosting account; locks SSH/FTP to VPN-only when enabled
- **Account Suspension** — Suspend/resume accounts (blocks HTTP, FTP, SSH, and WireGuard peer simultaneously)
- **Backups** — Per-account and full backups to `/backup/` with configurable retention
- **Service Manager** — Start, stop, and restart system services (Apache, PHP-FPM, MariaDB, vsftpd, WireGuard) from the panel
- **Scheduled Jobs** — Configurable cron times for system updates, account backups, and panel auto-update (all written to `/etc/cron.d/` in real time)
- **Panel Updates** — One-click update from GitHub releases with optional auto-update on a schedule
- **Sub-Admin Users** — Create panel users restricted to specific hosting accounts
- **phpMyAdmin** — Served on port 8888 via Apache

---

## Architecture

| Layer | Technology | Port |
|---|---|---|
| Admin panel | lighttpd + PHP 8.4-FPM | 80 |
| phpMyAdmin | Apache2 vhost | 8888 |
| Hosting sites | Apache2 vhosts | 1080+ |
| Panel database | SQLite | — |
| MariaDB | localhost-only | 3306 (internal) |

- Panel source: `/var/www/inetpanel/`
- System scripts: `/root/scripts/`
- Account home dirs: `/home/<domain>/`
- Backups: `/backup/`

---

## Requirements

- Debian 12 (Bookworm) — **clean install recommended**
- Root access
- A domain or static IP (optional but recommended for DDNS/WireGuard)
- Cloudflare account (optional — required for DNS management, email forwarding, and DDNS)

---

## Installation

### Step 1 — Run the server installer

```bash
bash <(curl -s https://inetpanel.tuxxin.com/data/latest)
```

Or download and run manually:

```bash
curl -O https://inetpanel.tuxxin.com/data/latest
bash latest
```

This installs and configures:
- lighttpd (panel web server on port 80)
- Apache2 (phpMyAdmin on port 8888, hosting sites on port 1080+)
- PHP 8.4-FPM with common extensions
- MariaDB (bound to 127.0.0.1 only)
- phpMyAdmin
- vsftpd (FTP server)
- Node.js 22
- All iNetPanel system scripts (`/root/scripts/`)
- Sudoers rules for the panel
- Cron jobs for system updates and account backups

> The installer detects conflicting services and exits safely on non-clean systems.
> To override: `FORCE_INSTALL=1 bash latest`

### Step 2 — Complete setup in the browser

After the installer finishes, open:

```
http://<YOUR_SERVER_IP>/install.php
```

The setup wizard will:
1. Create the admin account
2. Configure server hostname and timezone
3. Optionally connect Cloudflare (API key + email)
4. Optionally enable Cloudflare DDNS
5. Optionally install WireGuard VPN
6. Initialize the SQLite database
7. Write the `.installed` lock file and redirect to login

### Step 3 — Log in

```
http://<YOUR_SERVER_IP>/login
```

---

## Updating

### Manual update
Settings → Updates → **Update Now**

### Automatic update
Settings → Updates → enable **Panel Auto-Update** and set a time.

### CLI update
```bash
php /var/www/inetpanel/scripts/panel_update.php --force
```

The updater downloads the latest release from GitHub, rsyncs the panel files (preserving `db/` and `.installed`), updates the version constant in `TiCore/Version.php`, and redeploys system scripts to `/root/scripts/`.

---

## Repository Structure

```
inetpanel/
├── TiCore/              # Core PHP classes (DB, Auth, Shell, Router, View, etc.)
├── api/                 # AJAX API endpoints (JSON responses)
├── conf/                # Configuration (Config.php)
├── db/                  # SQLite database (gitignored)
├── public/              # Web root (index.php, install.php, assets)
├── scripts/
│   ├── system/          # Bash system scripts (deployed to /root/scripts/ on update)
│   ├── panel_update.php # Self-update script
│   └── ddns_update.php  # DDNS cron script
├── src/                 # Admin page views
└── themes/default/      # Layout templates (header, sidebar, footer, login)
```

---

## Security Notes

- MariaDB is bound to `127.0.0.1` only — not accessible from outside
- All privileged operations run through `sudo` with a strict NOPASSWD whitelist in `/etc/sudoers.d/inetpanel`
- When WireGuard is enabled, SSH and FTP are locked to the VPN subnet; public ports 20/21/22 are closed via CSF
- SSH private keys generated by the panel are returned once and never stored server-side
- Sub-admin users can only access their assigned domains

---

## License

MIT — see [LICENSE](LICENSE)
