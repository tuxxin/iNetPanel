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
- **One-Click Account Creation** — Creates Linux user, Apache vhost, PHP-FPM pool, FTP access, Cloudflare tunnel route, DNS CNAME, and SSL certificate in one step
- **Account Suspension** — Suspend/resume accounts (blocks HTTP, FTP, SSH, and WireGuard simultaneously)
- **Client Portal** — Hosting users log in at `/user/` with tabbed dashboard: all-domains overview with FTP/SSH info, database management (list, create, delete), .htaccess file manager with directory password protection, backup downloads, image optimization, Multi-PHP version switching, DNS records, and email routing
- **phpMyAdmin Auto-Login** — Token-based signon authentication from both admin panel (root) and client portal (user-scoped) with fallback login form
- **Hook Scripts** — Custom post-execution bash hooks for domain add/delete with syntax validation, toggle on/off, and one-click TiCore PHP Framework auto-deploy template
- **SSH Key Manager** — Import, generate, and manage SSH keys per hosting account from both admin panel and client portal

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
- **DDoS & Dev Mode** — Toggle Cloudflare Under Attack Mode and Development Mode per zone from admin DNS and client portal
- **Email Routing** — Manage Cloudflare Email Routing rules per domain from the panel
- **DDNS** — Automatically updates your DNS A record when your server IP changes

### Server Management
- **Multi-PHP** — Install and switch between PHP 5.6–8.5 per domain from admin panel or client portal using sury.org packages
- **PHP Package Manager** — Install/remove PHP extensions per version from the panel
- **Service Manager** — Start, stop, restart Apache, PHP-FPM, MariaDB, vsftpd, lighttpd, WireGuard, etc.
- **Service Monitor** — Automatic service health checks with auto-restart and panel logging (configurable cron)
- **System Logs** — View Apache error/access logs, PHP-FPM logs, auth logs from the panel
- **Image Optimizer** — Bulk optimize JPEG/PNG/GIF/WebP images with AVIF generation, ICC profile stripping, and resize options (per-user, per-domain, or per-directory) — accessible from admin panel and client portal
- **Backups** — Per-user automated backups (all domains in one archive) plus system config backups (Apache, PHP, MariaDB, lighttpd, fail2ban, SSH, cron, and panel database) with configurable retention and scheduling
- **MySQL Timezone Sync** — Timezone changes in Settings or installer automatically update MariaDB global timezone and persist to config

### VPN & Remote Access
- **WireGuard VPN** — Optional full server lockdown; only the WireGuard port (1443/UDP) is publicly open
- **Auto-Provisioned Peers** — One VPN peer per hosting user, auto-generated with QR code

### Panel Administration
- **Role-Based Access** — Superadmin, full admin, and sub-admin (domain-restricted) roles
- **Panel Updates** — One-click update from GitHub releases, CLI emergency update (`inetp panel_update`), auto-update scheduling with header notification
- **Schema Migrations** — Numbered SQL migrations run automatically on update; servers that skip versions get all changes applied
- **Scheduled Jobs** — Configurable cron for system updates, backups, and panel auto-update
- **Settings Deep Links** — Direct URL access to settings tabs (e.g., `/admin/settings#general`)
- **Reserved Usernames** — Prevents creation of accounts with system-reserved names (root, admin, www-data, etc.)
- **Dashboard** — Real-time resource monitoring graph (CPU, RAM, disk, network) with configurable polling interval and time-range display
- **Dark Mode** — Toggle between light and dark themes
- **CLI Tool** — `inetp` command with 30+ subcommands for all operations from the terminal

> Complete feature list with screenshots: [inetpanel.tuxxin.com](https://inetpanel.tuxxin.com)

---

## Architecture

| Layer | Technology | Port |
|---|---|---|
| Admin panel | lighttpd + PHP-FPM | 80 |
| Client portal | lighttpd (same) | 80 |
| phpMyAdmin | Apache2 vhost | 8888 / 8443 (SSL) |
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
  ├── <domain>/              Domain directory
  ├── <domain>/www/          Public document root
  └── <domain>/logs/         Apache and PHP error logs

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
| **Account Management** | |
| `create_account` | Create user + first domain in one step |
| `delete_account` | Remove all domains + delete user |
| `create_user` | Create a new hosting user (Linux + MariaDB + FTP) |
| `delete_user` | Delete a hosting user (must have no domains) |
| `add_domain` | Add a domain to an existing user |
| `remove_domain` | Remove a domain from a user |
| `suspend_account` | Suspend or resume an account/user |
| `change_password` | Change FTP/SSH password for a hosting user |
| `reset_password` | Reset FTP/SSH/MySQL password for a hosting user |
| `fix_permissions` | Fix file ownership and permissions for user accounts |
| `list` | List all hosting users and their domains |
| **Server & Services** | |
| `status` | Server health summary (CPU, RAM, disk, services, SSL, backups) |
| `benchmark` | Quick server benchmark (disk I/O, network, PHP, MySQL) |
| `speedtest` | Network bandwidth test with latency checks |
| `service_monitor` | Enable/disable/run automatic service health checks |
| `update` | Run system package update |
| `panel_update` | Update iNetPanel from GitHub releases |
| **Security & Diagnostics** | |
| `audit` | Security audit (permissions, ports, SSH, SSL, PHP, firewall) |
| `malware_scan` | Scan PHP files for backdoors and webshells |
| `dns_check` | DNS, SSL, and connectivity diagnostics for a domain |
| `firewall` | Firewall management (status, flush, ban, unban) |
| `ssl_manage` | SSL certificate management (issue, revoke, renew, status) |
| `panel_ssl` | Panel SSL certificate management |
| **Backup & Maintenance** | |
| `backup_accounts` | Backup all accounts + system configs to /backup |
| `optimize_images` | Optimize images with AVIF/WebP generation |
| `cleanup` | Remove temp files, old logs, orphaned FPM pools |
| `rotate_logs` | Force log rotation for system and user logs |
| `db_repair` | Check and repair MariaDB tables + SQLite vacuum |
| `disk_report` | Disk usage breakdown by user/domain with top files |
| **VPN** | |
| `wireguard_setup` | Set up WireGuard VPN with full server lockdown |
| `wireguard_uninstall` | Remove WireGuard and restore firewall rules |
| `wg_peer` | Manage WireGuard VPN peers |

---

## Requirements

- **Debian 12** (Bookworm) — clean install recommended
- **Root access**
- **Cloudflare account** — recommended for tunnel, DNS management, email routing, DDNS, and SSL (DNS-01 challenge). Manual port-based mode available without Cloudflare

---

## Installation

### 1. Run the installer

```bash
bash <(curl -s https://inetpanel.tuxxin.com/latest)
```

This installs and configures: lighttpd, Apache2, PHP 8.5-FPM, MariaDB, phpMyAdmin, vsftpd, Node.js 22, certbot, WireGuard (optional), all system scripts, sudoers rules, and cron jobs. **SSH is moved to port 1022** — reconnect with `ssh -p 1022` after install.

### 2. Complete setup in the browser

Open `http://<YOUR_SERVER_IP>/install.php` — the 6-step wizard walks through:

1. **Admin Account** — Create admin username and password (with confirmation)
2. **Timezone** — Select server timezone
3. **Cloudflare** — Connect and verify Cloudflare API credentials (or choose manual port-based mode)
4. **DDNS & VPN** — Configure dynamic DNS and WireGuard VPN (optional, Cloudflare required)
5. **Server Hostname** — Set hostname with Cloudflare DNS verification and automatic A record creation
6. **Complete** — Finalize installation

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
