<?php
// FILE: TiCore/TuxxinServices.php
// iNetPanel — optional integrations with Tuxxin services.
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel
//
// The panel side of scripts/system/tuxxin_services.py (`inetp tsvc`). Everything
// that talks to a service, or touches a key, goes through that root helper:
// keys live in /root/.inetpanel_tsvc/ (root, 0600), out of reach of tenants and
// of www-data's own file access. What the panel reads directly is only what is not secret — the
// module switches, cached answers (tsvc_cache) and last-seen quotas.
//
// A module that is off does not run: no request leaves the server for it and
// nothing it would add is shown. enabled() is the single test every page uses.

class TuxxinServices
{
    /**
     * The modules, in the order the settings page lists them.
     * 'sends' is shown next to the switch, so an admin knows exactly what leaves
     * the server before turning a module on.
     */
    public const MODULES = [
        'qsa' => [
            'name'    => 'External Security Audit',
            'service' => 'qsa.sh',
            'url'     => 'https://qsa.sh',
            'icon'    => 'fa-shield-halved',
            'summary' => 'Scans this server\'s public IPv4 from the outside and reports what the internet '
                       . 'can actually reach. iNetPanel is built to need no open ports, so anything found '
                       . 'is usually a router port-forward or DMZ.',
            'sends'   => 'Nothing beyond the request itself: qsa.sh scans the public IP the request comes from.',
            'where'   => 'Firewall &rarr; Exposure Scan, and a server-security badge in the client portal.',
            'key'     => 'qsa_token',
            'key_label' => 'qsa.sh token',
            'key_hint'  => '32 hexadecimal characters. Full scans all 65,535 ports; Deep adds the complete check set.',
            'pricing' => 'https://qsa.sh/pricing',
            'free'    => 'Top 1,000 TCP ports, one scan per 24 hours.',
        ],
        'worldip' => [
            'name'    => 'IP Intelligence',
            'service' => 'worldip.io',
            'url'     => 'https://worldip.io',
            'icon'    => 'fa-location-dot',
            'summary' => 'Country, network (ASN), reverse DNS and CIDR for the IP addresses the panel shows: '
                       . 'banned IPs, log entries, and this server\'s own public address.',
            'sends'   => 'The IP address being looked up — only when you click to look one up, or once a day '
                       . 'for this server\'s own address.',
            'where'   => 'Firewall, Logs, Dashboard, and server details in the client portal.',
            'key'     => 'worldip_key',
            'key_label' => 'worldip.io API key',
            'key_hint'  => 'A key adds VPN / proxy / Tor classification, trust score and higher limits.',
            'pricing' => 'https://worldip.io/developers',
            'free'    => '1,000 lookups per day, per server.',
        ],
        'issued' => [
            'name'    => 'Domain & Certificate Watch',
            'service' => 'issued.live',
            'url'     => 'https://issued.live',
            'icon'    => 'fa-certificate',
            'summary' => 'Domain registration expiry and the certificate the world sees for each hosted '
                       . 'domain, so an expiring domain is noticed before it lapses.',
            'sends'   => 'The names of the domains hosted here, about once a day each.',
            'where'   => 'Accounts (site details) and the client portal overview.',
            'key'     => 'issued_key',
            'key_label' => 'issued.live API key',
            'key_hint'  => 'Optional. Raises the daily lookup limit.',
            'pricing' => 'https://issued.live/pricing',
            'free'    => 'Free API, no key needed.',
        ],
        'webshot' => [
            'name'    => 'Site Screenshots',
            'service' => 'webshot.site',
            'url'     => 'https://webshot.site',
            'icon'    => 'fa-camera',
            'summary' => 'A thumbnail of each hosted site, refreshed weekly — a quick visual check that a '
                       . 'site still renders.',
            'sends'   => 'The public URL of each hosted site, about once a week each.',
            'where'   => 'Accounts (site details) and the client portal overview.',
            'key'     => 'webshot_key',
            'key_label' => 'webshot.site API key',
            'key_hint'  => 'Optional. Raises the screenshot rate limit.',
            'pricing' => 'https://webshot.site',
            'free'    => '5 screenshots per 15 minutes.',
        ],
        'whack' => [
            'name'    => 'Malware & Phishing Scan',
            'service' => 'whack.sh',
            'url'     => 'https://whack.sh',
            'icon'    => 'fa-bug',
            'summary' => 'Scans a hosted site from datacenter, residential and mobile networks at once, '
                       . 'catching compromised sites that cloak themselves from ordinary scanners.',
            'sends'   => 'The public URL of a hosted site — only when someone asks for a scan.',
            'where'   => 'Accounts (site details) and "Scan my site" in the client portal.',
            'key'     => 'whack_key',
            'key_label' => 'whack.sh API key',
            'key_required' => true,
            'key_hint'  => 'Required: whack.sh has no keyless tier. A free account\'s key covers datacenter scans at no cost.',
            'pricing' => 'https://whack.sh',
            'free'    => 'Needs a key from a free whack.sh account.',
        ],
    ];

    /** Other Tuxxin services, linked from the settings page only. */
    public const LINKS = [
        ['name' => 'webfetch.io',  'url' => 'https://webfetch.io',
         'text' => 'Fetch any URL as rendered HTML, text, markdown or JSON. ScrapingBee-compatible API.'],
        ['name' => 'HeskAPI',      'url' => 'https://heskapi.com',
         'text' => 'A REST API add-on for the HESK help desk: tickets, replies and attachments.'],
        ['name' => 'curlhub.sh',   'url' => 'https://curlhub.sh',
         'text' => 'Developer utilities you can curl: pastebin, file drop, JSON, JWT, cron and more.'],
        ['name' => 'Tuxxin',       'url' => 'https://tuxxin.com',
         'text' => 'Outsourced IT and cybersecurity — the company behind iNetPanel.'],
    ];

    /** Value used when a switch has never been written (see migrations/004). */
    private const DEFAULTS = ['qsa' => '1', 'worldip' => '0', 'issued' => '0', 'webshot' => '0', 'whack' => '0'];

    private static ?array $states = null;

    /**
     * Gate for every action that changes something or spends a service's
     * allowance: POST only, and only from the panel's own pages. SameSite=Strict
     * cookies still travel with a request from a *same-site* page — a tenant's
     * site on a sibling subdomain of the panel's domain — so the browser's
     * Sec-Fetch-Site / Origin headers are checked as well.
     */
    public static function requireWrite(): bool
    {
        $bad = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $bad = [405, 'This action needs a POST request.'];
        } else {
            $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
            if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
                $bad = [403, 'Cross-site request refused.'];
            }
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            if ($bad === null && $origin !== '' && $origin !== 'null') {
                $oh = strtolower((string) parse_url($origin, PHP_URL_HOST));
                $op = parse_url($origin, PHP_URL_PORT);
                $hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
                $want = $oh . ($op ? ':' . $op : '');
                if ($hostHeader === '' || ($want !== $hostHeader && $oh !== $hostHeader)) {
                    $bad = [403, 'Cross-origin request refused.'];
                }
            }
        }
        if ($bad !== null) {
            http_response_code($bad[0]);
            echo json_encode(['success' => false, 'error' => $bad[1]]);
            return false;
        }
        return true;
    }

    /**
     * The update that ships Tuxxin Services is run by the previous
     * panel_update.php, which does not know to convert an install. The helper
     * converts it the first time anything calls it; this makes sure a page that
     * reads settings straight from the database sees the converted state.
     */
    public static function ensureMigrated(): void
    {
        if ((string) DB::setting('tsvc_migrated', '') !== '1') {
            self::helper(['migrate'], null, true);
            self::$states = null;
        }
    }

    public static function masterEnabled(): bool
    {
        return (string) DB::setting('tsvc_master', '1') === '1';
    }

    /** True only when the master switch and the module's own switch are both on. */
    public static function enabled(string $module): bool
    {
        return self::states()[$module] ?? false;
    }

    /** ['qsa' => bool, ...] — cached for the request. */
    public static function states(): array
    {
        if (self::$states === null) {
            $master = self::masterEnabled();
            $out = [];
            foreach (self::DEFAULTS as $m => $default) {
                $out[$m] = $master && (string) DB::setting('tsvc_' . $m, $default) === '1';
            }
            self::$states = $out;
        }
        return self::$states;
    }

    public static function anyEnabled(): bool
    {
        return in_array(true, self::states(), true);
    }

    /** Write a switch or other module setting with its category. */
    public static function save(string $key, string $value): void
    {
        DB::query(
            "INSERT INTO settings (key, value, category, updated_at) VALUES (?, ?, 'tuxxin', datetime('now'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            [$key, $value]
        );
        self::$states = null;
    }

    /** Whether a key has been saved, without reading it (see store_secret()). */
    public static function hasSecret(string $name): bool
    {
        return (string) DB::setting($name . '_set', '0') === '1';
    }

    /** Last-seen quota for a service: ['limit' => int, 'remaining' => int, 'reset' => epoch] or null. */
    public static function quota(string $service): ?array
    {
        $q = json_decode((string) DB::setting('tsvc_quota_' . $service, ''), true);
        return is_array($q) ? $q : null;
    }

    /**
     * A cached answer, read straight from the database (no sudo).
     * Returns ['data' => mixed, 'fetched_at' => int, 'expires_at' => int, 'stale' => bool] or null.
     */
    public static function cached(string $service, string $item, bool $allowStale = true): ?array
    {
        try {
            $row = DB::fetchOne(
                'SELECT status, body, fetched_at, expires_at FROM tsvc_cache WHERE service = ? AND item = ?',
                [$service, $item]
            );
        } catch (\Throwable) {
            return null;            // table not created yet on a server that has not updated
        }
        if (!$row) return null;
        $stale = (int) $row['expires_at'] < time();
        if ($stale && !$allowStale) return null;
        return [
            'status'     => (int) $row['status'],
            'data'       => json_decode((string) $row['body'], true),
            'fetched_at' => (int) $row['fetched_at'],
            'expires_at' => (int) $row['expires_at'],
            'stale'      => $stale,
        ];
    }

    /**
     * Run `inetp tsvc <args> --json` and decode the answer.
     *
     * Read-only calls go through Shell::query(), which logs failures only;
     * Shell::run() would copy each answer — scan reports included — into the
     * logs table.
     *
     * @return array decoded JSON, always with a 'success' key
     */
    public static function helper(array $args, ?string $stdin = null, bool $logSuccess = false): array
    {
        $args[] = '--json';
        if ($stdin !== null) {
            $r = Shell::runWithInput('tsvc', $args, $stdin);
        } elseif ($logSuccess) {
            $r = Shell::run('tsvc', $args);
        } else {
            $r = Shell::query('tsvc', $args);
        }
        $out = trim((string) ($r['output'] ?? ''));

        // A server whose /usr/local/bin/inetp predates this feature falls through
        // to the dispatcher's usage text. Say what is wrong instead of failing
        // with "invalid JSON".
        if (preg_match('/^\s*inetp\s+[-—]\s+Server Account Manager|Usage:\s*inetp\s+<command>/mi', $out)) {
            return ['success' => false, 'stale_cli' => true,
                    'error' => 'This server\'s inetp command predates Tuxxin Services. '
                             . 'Run a panel update (Settings → Updates) to finish installing it.'];
        }
        // The helper prints exactly one JSON object, last.
        $lines = preg_split('/\R/', $out);
        $json  = null;
        for ($i = count($lines) - 1; $i >= 0 && $json === null; $i--) {
            $try = json_decode(trim($lines[$i]), true);
            if (is_array($try)) $json = $try;
        }
        if ($json === null) {
            return ['success' => false, 'error' => $out !== '' ? mb_substr($out, 0, 500) : 'No answer from the helper.'];
        }
        $json['success'] = (bool) ($json['success'] ?? false);
        return $json;
    }

    public const SHOTS_DIR = '/var/lib/inetpanel/tsvc/shots';

    /**
     * Site details for one hosted domain, from cache only — nothing here sends a
     * request to a service. Keys of modules that are off are left out.
     */
    public static function siteInsights(string $d): array
    {
        $out = ['domain' => $d];
        if (self::enabled('issued')) {
            $c = self::cached('issued', $d);
            $out['issued'] = ($c && is_array($c['data']))
                ? $c['data'] + ['_fetched_at' => $c['fetched_at'], '_stale' => $c['stale']] : null;
        }
        if (self::enabled('webshot')) {
            $c = self::cached('webshot', $d);
            $file = self::SHOTS_DIR . '/' . $d . '.jpg';
            $job = false;
            try {
                $job = DB::fetchOne("SELECT state, not_before FROM tsvc_jobs WHERE service = 'webshot' AND item = ?", [$d]);
            } catch (\Throwable) {
            }
            $out['webshot'] = [
                'has_image'   => is_file($file),
                'captured_at' => $c['data']['captured_at'] ?? (is_file($file) ? filemtime($file) : null),
                'error'       => $c['data']['error'] ?? '',
                // Due now, or waiting out a backoff after a failure (the button
                // stays usable then: a click resets the backoff).
                'queued'      => $job && $job['state'] === 'queued' && (int) $job['not_before'] <= time(),
                'retry_at'    => ($job && $job['state'] === 'queued' && (int) $job['not_before'] > time())
                                 ? (int) $job['not_before'] : null,
            ];
        }
        if (self::enabled('whack')) {
            $c = self::cached('whack', $d);
            $out['whack'] = $c['data'] ?? null;
            $out['whack_has_key'] = self::hasSecret('whack_key');
        }
        return $out;
    }

    /** Send a domain's thumbnail, or a 404. The caller has already checked access. */
    public static function serveShot(string $d): void
    {
        $file = self::SHOTS_DIR . '/' . $d . '.jpg';
        if (!self::enabled('webshot') || !preg_match('/^[a-z0-9.-]{1,253}$/', $d) || !is_file($file)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    /**
     * Known services behind this server's own ports, for explaining qsa.sh
     * findings. Every hosted site is behind the Cloudflare Tunnel, so a tenant
     * port answering from the internet means the tunnel is being bypassed.
     *
     * @return array<int, array{label: string, kind: string}>
     */
    public static function portMap(): array
    {
        $map = [
            20   => ['label' => 'FTP data', 'kind' => 'panel'],
            21   => ['label' => 'FTP', 'kind' => 'panel'],
            80   => ['label' => 'Panel (lighttpd, HTTP)', 'kind' => 'panel'],
            443  => ['label' => 'Panel (lighttpd, HTTPS)', 'kind' => 'panel'],
            1443 => ['label' => 'Panel (alternate HTTPS)', 'kind' => 'panel'],
            3306 => ['label' => 'MariaDB / MySQL', 'kind' => 'panel'],
            8443 => ['label' => 'phpMyAdmin (HTTPS)', 'kind' => 'panel'],
            8888 => ['label' => 'phpMyAdmin', 'kind' => 'panel'],
        ];
        $ssh = (int) DB::setting('ssh_port', '1022');
        if ($ssh > 0) {
            $map[$ssh] = ['label' => 'SSH', 'kind' => 'panel'];
        }
        try {
            foreach (DB::fetchAll('SELECT domain_name, port FROM domains WHERE port IS NOT NULL') as $d) {
                $p = (int) $d['port'];
                if ($p > 0) {
                    $map[$p] = ['label' => $d['domain_name'] . ' (site, bypassing the tunnel)', 'kind' => 'site'];
                }
            }
        } catch (\Throwable) {
        }
        return $map;
    }

    /** Label for one open port; FTP passive ports are a range. */
    public static function describePort(int $port, ?array $map = null): array
    {
        $map ??= self::portMap();
        if (isset($map[$port])) return $map[$port];
        if ($port >= 40000 && $port <= 50000) return ['label' => 'FTP passive range', 'kind' => 'panel'];
        return ['label' => 'Not opened by iNetPanel', 'kind' => 'unknown'];
    }
}
