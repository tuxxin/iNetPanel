# iNetPanel Roadmap

Planned work, in rough priority order. Nothing here is committed to a release
date. Items move out of this file when they ship — the release notes are the
record of what actually landed.

Current release: **1.27.1**

---

## Planned features

### ClamAV integration

Replace `inetp malware_scan` (pattern-matching for known PHP backdoor
signatures) with real antivirus, or run both.

Scope to settle before starting:

- **On-demand vs. on-access.** On-demand (`clamscan` / `clamdscan` from cron or
  the panel) is straightforward. On-access scanning needs `clamonacc` plus
  fanotify and will touch every file write on the box — that is a real
  performance decision on a home server, not a checkbox.
- **Memory.** `clamd` holds the signature database resident: roughly 1–1.5 GB.
  That is significant on the low-RAM boxes iNetPanel targets. `clamscan` without
  the daemon avoids it but reloads signatures per invocation and is far slower.
  Whichever way this goes, the installer must not silently push a 2 GB VPS into
  swap — measure before choosing a default.
- **Quarantine.** Where infected tenant files go, who can restore them, and how
  that interacts with the backup retention sweep.
- **Upload scanning.** The obvious high-value hook is the client-portal file
  manager and the backup-restore upload path.
- **freshclam** scheduling, and what happens when signature updates fail.

Panel surface: a Security page section with last-scan time, findings, quarantine
list, and a per-account scan action. CLI: `inetp clamav_scan`, mirroring the
`qsa_scan` shape (`--quiet` for the panel, cron-friendly).

### Proton Mail integration (affiliate)

Give accounts real mailboxes via Proton, with an affiliate referral.

**Verify this before designing anything.** Proton does not expose a general
send/receive API for Mail. Access from third-party software goes through **Proton
Mail Bridge**, which:

- requires a **paid** Proton Mail plan,
- runs as a **local daemon** that exposes IMAP/SMTP on loopback,
- is per-user — each mailbox needs its own Bridge session, so it does not
  naturally serve N hosting tenants from one server,
- is designed for desktop use, and running it headless on a server is possible
  but not a supported first-class path.

That last point is the one that decides the shape of this feature. If Bridge is
still per-user-session at implementation time, "iNetPanel sends and receives mail
through Proton for every tenant" is not achievable as stated, and the realistic
scope is narrower:

1. **Panel-level outbound only** — one Proton account belonging to the operator,
   used for panel notifications (exposure-change alerts, backup failures, account
   provisioning). This is genuinely useful today: the panel currently has *no*
   mail path at all, which is why change monitoring had to use webhooks.
2. **Affiliate referral + guided setup** — the panel links tenants to Proton with
   the referral ID and helps them point their domain's MX at Proton via the
   existing Cloudflare DNS integration. No mail flows through iNetPanel; the
   panel does DNS and documentation. Lowest effort, and the affiliate revenue is
   identical.
3. **Full per-tenant send/receive** — only if Bridge or an API makes it viable.

Recommendation: build (2) first — it is mostly Cloudflare DNS work the panel
already does — and add (1) alongside, since a working outbound mail path removes
a real limitation. Treat (3) as conditional on what Proton actually supports when
we get there.

Also to settle:

- **Affiliate ID** — not yet recorded. It needs somewhere sensible to live: not
  hardcoded in a template, and it must survive `panel_update` overwriting files.
- Affiliate links must be honestly disclosed in the UI. A referral link presented
  as a neutral recommendation is the kind of thing that costs more trust than the
  commission is worth.
- Whether the referral is per-install (operator's own ID) or fixed to Tuxxin's.

### Tailscale support

Alongside WireGuard rather than replacing it. WireGuard's manual peer
configuration is the single fiddliest part of the current lockdown mode;
Tailscale removes that at the cost of a third-party coordination server.

- Install and `tailscale up` from the panel, with an auth key entered in the UI.
- Show node status, the tailnet IP, and connected peers on the dashboard.
- **Lockdown mode via Tailscale** — the equivalent of the existing WireGuard
  full-lockdown option: panel, client portal, phpMyAdmin, FTP and SSH reachable
  only over the tailnet. This is the real prize; it makes lockdown accessible to
  people who will never hand-configure a WireGuard peer.
- MagicDNS and whether panel hostnames should resolve over it.
- Interaction with firewalld and with the existing WireGuard setup — running both
  at once must not produce conflicting rules. The `nft` port from the Debian 13
  work is the right base.
- Cloudflare Tunnel already covers "no open ports" for **inbound web**; Tailscale
  covers **administrative** access. Worth being clear in the docs that they solve
  different problems, or users will assume they are alternatives.

---

## Queued engineering work

Not features. Known issues and debt, recorded so they are not rediscovered.

### Security

- **`sudo /bin/bash <staging>/inetp_hook_*`** — admin hook scripts run as root by
  design, so this grant stays. Worth revisiting whether the hook body could be
  passed to a helper that reads it from the panel database instead, removing the
  last path-based grant. Low priority: it does not change what a compromised
  panel can do, since the attacker could write a hook into the database anyway.
- **`restore` FTP user carries root's password hash** (`restore_helper.sh`). It
  avoids handling a plaintext password, but it means anyone who knows the root
  password gets a shell as `restore`. Not an escalation, but a dedicated
  credential would be cleaner.
- **mod_remoteip trusts loopback, and so does every local user.** cloudflared
  hands off over loopback, so `RemoteIPTrustedProxy 127.0.0.1` is required for
  real client IPs to work at all. But every hosting tenant also reaches loopback,
  so any of them can set `CF-Connecting-IP` and have Apache believe it —
  defeating every IP-keyed control on the box, including fail2ban and any
  per-app rate limiting. Verified on web01 via the Apache access log (`%h`),
  which is an Apache-level sink with no application code in the path.

  Apache cannot distinguish the tunnel from a local process by address alone, so
  this is not fixable by editing the trust list. The realistic options are an
  nftables rule restricting loopback access to the origin ports by UID (only
  cloudflared, root and www-data), or accepting it and ensuring nothing
  security-critical trusts the rewritten address. The first needs care: local
  loopback traffic is legitimate for WordPress cron, health checks and the
  panel's own dns_check, so a blanket block would break working sites.

  Do not attempt this as a hotfix. It changes request handling for every hosted
  site and needs a maintenance window.
- Audit whether any other privileged call site still builds a path from
  caller-supplied input. The `.htaccess` finding and the `/tmp` staging fixes
  were the same root cause in two different places; assume there is a third.

### Correctness

- **The `'8.4'` literals.** `api/settings.php` now falls back to the running PHP
  version, but the Debian 13 audit counted roughly 34 hardcoded `8.4` fallbacks
  across the tree while `settings.php_default_version` is `8.5`. Several fail
  silently — `api/accounts.php:375` reloads a non-existent `php8.4-fpm`, so a new
  domain's socket is never created and the site 503s.
- **Single source of truth for supported PHP versions.** Eleven separate
  enumerations must be edited in lockstep to add a version. Deliberately deferred
  during the Debian 13 port to keep that change's regression surface small.
- **Unconditional success logging.** `panel_update.php`'s inetp and script-deploy
  steps were fixed, but the pattern (log success outside the failure branch)
  should be swept for across the codebase. It is what hid the `ProtectSystem`
  bug for weeks.
- **Fresh installs** do not get the logrotate, session-reaper or cf_remoteip
  crons until the first nightly `panel_update`. They should be installed by the
  installer.

### Platform

- **PHP 8.6** — currently `8.6.0~alpha1` on sury. Add to the supported lists when
  it ships stable; do not make it the default. Requires the 11-site change above.
- Debian 12 stays supported until its LTS ends in June 2028.

---

## Not planned

- **Dropping Debian 12.** Still LTS, still has live installs.
- **A general-purpose mail server.** Running an MTA well on a residential IP is a
  losing battle — reputation, PTR records, port 25 blocks. Routing mail to a
  provider is the right answer, which is what the Proton work is about.
