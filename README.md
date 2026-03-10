# iNetPanel

**A modern, self-hosted web hosting control panel built for home servers and VPS.**

iNetPanel uses **Cloudflare Zero Trust Tunnels** to expose multiple domains from a single machine — no open ports, no exposed IP, no port forwarding required. Each domain you add is automatically routed through the tunnel, making it publicly accessible while keeping your server completely private.

Manage hosting accounts, domains, SSL certificates, PHP versions, databases, DNS, email routing, backups, firewalls, and WireGuard VPN — all from a clean admin interface or CLI.

> **Full documentation, screenshots, and FAQ:** [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)

---

## Quick Install

```bash
bash <(curl -s https://inetpanel.tuxxin.com/latest)
```

Then open `http://<YOUR_SERVER_IP>/install.php` to complete setup.

> Requires a **clean Debian 12** server with root access.
> Full installation guide at [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)

---

## Key Features

### Hosting & Account Management
- **Multi-Domain Users** — One hosting user can own multiple domains, each with its own vhost, database, and SSL certificate
- **One-Click Account Creation** — Creates Linux user, Apache vhost, PHP-FPM pool, MariaDB database, FTP access, and SSL certificate in one step
- **Account Suspension** — Suspend/resume accounts (blocks HTTP, FTP, SSH, and WireGuard simultaneously)
- **Client Portal** — Hosting users log in at `/user/` to view their account info, DNS records, email routing, and connection details

### SSL & Security
- **Automatic SSL** — Let's Encrypt certificates issued via DNS-01 challenge (Cloudflare API) for every domain
- **Self-Signed Fallback** — If Let's Encrypt fails, a self-signed cert is generated so the site still works through Cloudflare
- **SSL Dashboard** — View all certificates, expiry dates, issue/revoke/renew from the admin panel
- **Automatic Renewal** — Certbot cron renews certificates daily
- **Firewall Management** — firewalld + fail2ban with SSH brute-force protection, managed from the panel
- **Security Headers** — X-Frame-Options, X-Content-Type-Options, noindex/nofollow on all panel pages

### Cloudflare Integration
- **Zero Trust Tunnel** — Created automatically during install; every domain is published through the tunnel
- **DNS Management** — Full Cloudflare DNS record management (A, AAAA, CNAME, MX, TXT, SRV, etc.)
- **Email Routing** — Manage Cloudflare Email Routing rules per domain from the panel
- **DDNS** — Automatically updates your DNS A record when your server IP changes

### Server Management
- **Multi-PHP** — Install and switch between PHP 5.6–8.5 per domain using sury.org packages
- **PHP Package Manager** — Install/remove PHP extensions per version from the panel
- **Service Manager** — Start, stop, restart Apache, PHP-FPM, MariaDB, vsftpd, lighttpd, WireGuard, etc
- **System Logs** — View Apache error/access logs, PHP-FPM logs, auth logs from the panel
- **Image Optimizer** — Bulk optimize JPEG/PNG/GIF images with WebP generation (per-user, per-domain, or per-directory)
- **Backups** — Per-user automated backups (all domains in one archive) with configurable retention and scheduling

### VPN & Remote Access
- **WireGuard VPN** — Optional full server lockdown; only the WireGuard port (1443/UDP) is publicly open
- **Auto-Provisioned Peers** — One VPN peer per hosting user, auto-generated with QR code
- **SSH Key Manager** — Import, generate, and manage SSH keys per hosting account

### Panel Administration
- **Role-Based Access** — Superadmin, full admin, and sub-admin (domain-restricted) roles
- **Panel Updates** — One-click update from GitHub releases, CLI emergency update (`inetp panel_update`), auto-update scheduling
- **Schema Migrations** — Numbered SQL migrations run automatically on update; servers that skip versions get all changes applied
- **Scheduled Jobs** — Configurable cron for system updates, backups, and panel auto-update
- **Dark Mode** — Toggle between light and dark themes
- **CLI Tool** — `inetp` command for all operations from the terminal

> Complete feature list with screenshots: [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)

---

## Architecture

| Layer | Technology | Port |
|---|---|---|
| Admin panel | lighttpd + PHP-FPM | 80 |
| Client portal | lighttpd (same) | 80 |
| phpMyAdmin | Apache2 vhost | 8888 |
| Hosting sites | Apache2 SSL vhosts | 1080+ |
| Panel database | SQLite | — |
| Site databases | MariaDB (localhost) | 3306 |
| VPN | WireGuard | 1443/UDP |

### Directory Layout

```
/var/www/inetpanel/          Panel installation
  ├── public/                Web root (only dir served by lighttpd)
  ├── TiCore/                Core PHP classes
  ├── api/                   JSON API endpoints
  ├── src/                   Admin page views
  ├── themes/                Layout templates (admin + client portal)
  └── db/                    SQLite database

/home/<username>/            Hosting user home
  ├── <domain>/www/          Document root
  ├── <domain>/logs/         Apache logs
  └── tmp/                   PHP upload/session temp

/root/scripts/               System scripts (deployed from repo)
/backup/                     Automated backups
```

> **Security:** Only `public/` is web-accessible. All other directories (`TiCore/`, `api/`, `src/`, `scripts/`) are loaded internally by PHP and are unreachable via HTTP.

---

## CLI Tool

```
inetp --help
```

| Command | Description |
|---|---|
| `create_user` | Create a new hosting user (Linux + MariaDB + FTP) |
| `delete_user` | Delete a hosting user (must have no domains) |
| `add_domain` | Add a domain to an existing user |
| `remove_domain` | Remove a domain from a user |
| `create_account` | Create user + first domain in one step |
| `delete_account` | Remove all domains + delete user |
| `suspend_account` | Suspend or resume an account/user |
| `ssl_manage` | SSL certificate management (issue, revoke, renew, status) |
| `optimize_images` | Optimize images (per-user, per-domain, or directory) |
| `backup_accounts` | Backup all accounts to /backup |
| `firewall` | Firewall management (status, flush, ban, unban) |
| `wireguard_setup` | Set up WireGuard VPN with full server lockdown |
| `wg_peer` | Manage WireGuard VPN peers |
| `update` | Run system package update |
| `panel_update` | Update iNetPanel from GitHub |
| `list` | List all hosting users and their domains |

---

## Requirements

- **Debian 12** (Bookworm) — clean install recommended
- **Root access**
- **Cloudflare account** — required for tunnel, DNS management, email routing, DDNS, and SSL (DNS-01 challenge)

---

## Installation

### 1. Run the installer

```bash
bash <(curl -s https://inetpanel.tuxxin.com/latest)
```

This installs and configures: lighttpd, Apache2, PHP 8.5-FPM, MariaDB, phpMyAdmin, vsftpd, Node.js 22, certbot, WireGuard (optional), all system scripts, sudoers rules, and cron jobs. **SSH is moved to port 1022** — reconnect with `ssh -p 1022` after install.

### 2. Complete setup in the browser

Open `http://<YOUR_SERVER_IP>/install.php` — the wizard walks through admin account creation, Cloudflare connection, timezone, WireGuard setup, and database initialization.

### 3. Log in

- **Admin:** `http://<SERVER_IP>/admin`
- **Client portal:** `http://<SERVER_IP>/user`

> Step-by-step guide with screenshots: [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)

---

## Updating

| Method | How |
|---|---|
| **Web UI** | Settings > Updates > Update Now |
| **Auto-update** | Settings > Updates > enable Panel Auto-Update |
| **CLI** | `inetp panel_update` (or `inetp panel_update --force`) |

---

## Security

- All hosting sites served over **HTTPS** with Let's Encrypt certificates
- Cloudflare Tunnel means **no ports exposed** to the public internet
- **SSH port changed to 1022** during install (configurable from panel Settings > SSH Port)
- MariaDB bound to `127.0.0.1` only
- All privileged operations use `sudo` with a strict NOPASSWD whitelist
- WireGuard lockdown restricts all TCP ports to VPN subnet + localhost
- SSH keys generated by the panel are returned once and never stored
- Panel enforces `X-Frame-Options`, `X-Content-Type-Options`, `noindex/nofollow`, and `Referrer-Policy` headers
- Sub-admin users can only access their assigned domains
- Fail2ban protects SSH and panel login

> Security details: [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)

---

## License

MIT — see [LICENSE](LICENSE)

---

Created by [Tuxxin](https://tuxxin.com) | [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)
