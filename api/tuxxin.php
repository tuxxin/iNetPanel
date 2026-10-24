<?php
// FILE: api/tuxxin.php
// iNetPanel — Tuxxin Services API (admin side)
// Actions: status, save_switches, set_key, clear_key, notify_test,
//          worldip, server_ip, site_insights, shot, webshot_refresh,
//          whack_start, whack_status
//
// Everything that reaches a service goes through `inetp tsvc` (root), which
// holds the keys. A module that is switched off answers 'disabled' here and
// the helper refuses it too.

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$json = function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
};

$needFull = function () use ($json): bool {
    if (!Auth::hasFullAccess()) {
        $json(['success' => false, 'error' => 'Only an administrator can do that.'], 403);
        return false;
    }
    return true;
};

/** The domain from the request if this user may see it and it is hosted here. */
$domainArg = function () use ($json): ?string {
    $d = strtolower(trim((string) ($_GET['domain'] ?? $_POST['domain'] ?? '')));
    if ($d === '' || !preg_match('/^[a-z0-9.-]{1,253}$/', $d)) {
        $json(['success' => false, 'error' => 'Invalid domain.'], 400);
        return null;
    }
    if (!Auth::canAccessDomain($d)) {
        $json(['success' => false, 'error' => 'You do not have access to that domain.'], 403);
        return null;
    }
    if (!DB::fetchOne('SELECT 1 FROM domains WHERE lower(domain_name) = ?', [$d])) {
        $json(['success' => false, 'error' => 'That domain is not hosted on this server.'], 404);
        return null;
    }
    return $d;
};

$disabled = fn(string $m) => ['success' => false, 'disabled' => true,
    'error' => TuxxinServices::MODULES[$m]['name'] . ' is turned off in Settings → Tuxxin Services.'];

switch ($action) {

    case 'status':
        if (!$needFull()) break;
        TuxxinServices::ensureMigrated();
        $modules = [];
        foreach (TuxxinServices::MODULES as $key => $meta) {
            $modules[$key] = $meta + [
                'enabled'  => TuxxinServices::enabled($key),
                'switch'   => (string) DB::setting('tsvc_' . $key, $key === 'qsa' ? '1' : '0') === '1',
                'has_key'  => TuxxinServices::hasSecret($meta['key']),
                'quota'    => $key === 'qsa' ? null : TuxxinServices::quota($key),
            ];
        }
        $json([
            'success'      => true,
            'master'       => TuxxinServices::masterEnabled(),
            'modules'      => $modules,
            'links'        => TuxxinServices::LINKS,
            'webhook_set'  => TuxxinServices::hasSecret('webhook_url'),
            'webhook_kind' => (string) DB::setting('tsvc_webhook_kind', ''),
            'worker_last_run' => (string) DB::setting('tsvc_worker_last_run', ''),
            'qsa_tier'     => (string) DB::setting('qsa_token_tier', ''),
        ]);
        break;

    case 'save_switches':
        if (!$needFull() || !TuxxinServices::requireWrite()) break;
        TuxxinServices::save('tsvc_master', ($_POST['master'] ?? '0') === '1' ? '1' : '0');
        foreach (array_keys(TuxxinServices::MODULES) as $m) {
            if (array_key_exists($m, $_POST)) {
                TuxxinServices::save('tsvc_' . $m, $_POST[$m] === '1' ? '1' : '0');
            }
        }
        // The helper owns the crons: the background worker exists only while a
        // module that queues work is on, and qsa.sh monitoring only while qsa is.
        $r = TuxxinServices::helper(['cron'], null, true);
        $json(['success' => true, 'cron' => $r]);
        break;

    case 'set_key':
        if (!$needFull() || !TuxxinServices::requireWrite()) break;
        $name  = (string) ($_POST['name'] ?? '');
        $value = trim((string) ($_POST['value'] ?? ''));
        $allowed = ['qsa_token', 'worldip_key', 'issued_key', 'webshot_key', 'whack_key', 'webhook_url'];
        if (!in_array($name, $allowed, true) || $value === '') {
            $json(['success' => false, 'error' => 'Nothing to save.'], 400);
            break;
        }
        // stdin, never argv: Shell::run() logs arguments and `ps` shows them.
        $r = TuxxinServices::helper(['set-secret', $name], $value);
        $json($r['success'] ? ['success' => true, 'kind' => $r['kind'] ?? '']
                            : ['success' => false, 'error' => $r['error'] ?? 'Could not save the key.']);
        break;

    case 'clear_key':
        if (!$needFull() || !TuxxinServices::requireWrite()) break;
        $name = (string) ($_POST['name'] ?? '');
        if (!in_array($name, ['qsa_token', 'worldip_key', 'issued_key', 'webshot_key', 'whack_key', 'webhook_url'], true)) {
            $json(['success' => false, 'error' => 'Unknown key.'], 400);
            break;
        }
        $r = TuxxinServices::helper(['clear-secret', $name], null, true);
        $json(['success' => $r['success'], 'error' => $r['error'] ?? '']);
        break;

    case 'notify_test':
        if (!$needFull() || !TuxxinServices::requireWrite()) break;
        $r = TuxxinServices::helper(['notify-test'], null, true);
        $json(['success' => $r['success'], 'message' => $r['message'] ?? '', 'error' => $r['error'] ?? '']);
        break;

    case 'worldip':
        if (!$needFull()) break;
        if (!TuxxinServices::enabled('worldip')) { $json($disabled('worldip')); break; }
        $ip = trim((string) ($_GET['ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $json(['success' => false, 'error' => 'Not an IP address.'], 400);
            break;
        }
        // Cache first, without sudo: most lookups on a busy Firewall page repeat.
        $c = TuxxinServices::cached('worldip', $ip, false);
        if ($c && is_array($c['data'])) {
            $json(['success' => true, 'data' => $c['data'], 'cached' => true]);
            break;
        }
        $r = TuxxinServices::helper(['worldip', $ip]);
        $json($r);
        break;

    case 'server_ip':
        if (!$needFull()) break;
        if (!TuxxinServices::enabled('worldip')) { $json($disabled('worldip')); break; }
        $c = TuxxinServices::cached('worldip', 'self', false);
        if ($c && is_array($c['data'])) {
            // A recent failure is cached for an hour, so a dashboard view does not
            // retry an unreachable service every time.
            $json(!empty($c['data']['error'])
                ? ['success' => false, 'error' => 'IP lookup unavailable right now.']
                : ['success' => true, 'data' => $c['data'], 'cached' => true]);
            break;
        }
        $json(TuxxinServices::helper(['worldip', 'self']));
        break;

    case 'site_insights':
        $d = $domainArg();
        if ($d === null) break;
        if (TuxxinServices::enabled('issued') && !TuxxinServices::cached('issued', $d, false)) {
            TuxxinServices::helper(['issued', $d]);     // fast; fills the cache read below
        }
        $json(['success' => true, 'data' => TuxxinServices::siteInsights($d)]);
        break;

    case 'insights_summary':
        // One cached row per domain for list badges (expiring domain or
        // certificate, a flagged site). Cache only — never a request to a service.
        $out = [];
        $issued = TuxxinServices::enabled('issued');
        $whack  = TuxxinServices::enabled('whack');
        if ($issued || $whack) {
            try {
                foreach (DB::fetchAll("SELECT service, item, body FROM tsvc_cache WHERE service IN ('issued','whack')") as $row) {
                    if (!Auth::canAccessDomain($row['item'])) continue;
                    $b = json_decode((string) $row['body'], true);
                    if (!is_array($b)) continue;
                    if ($row['service'] === 'issued' && $issued && !empty($b['found'])) {
                        $out[$row['item']]['expires']     = $b['expires'] ?? null;
                        $out[$row['item']]['ssl_expires'] = $b['ssl_expires'] ?? null;
                    }
                    if ($row['service'] === 'whack' && $whack && ($b['state'] ?? '') === 'finished') {
                        $out[$row['item']]['verdict'] = $b['verdict'] ?? null;
                    }
                }
            } catch (\Throwable) {
            }
        }
        $json(['success' => true, 'data' => (object) $out]);
        break;

    case 'shot':
        // A thumbnail of the public site, served from the panel's own copy.
        $d = $domainArg();
        if ($d === null) break;
        TuxxinServices::serveShot($d);
        break;

    case 'webshot_refresh':
        if (!TuxxinServices::requireWrite()) break;
        $d = $domainArg();
        if ($d === null) break;
        if (!TuxxinServices::enabled('webshot')) { $json($disabled('webshot')); break; }
        $json(TuxxinServices::helper(['webshot', 'queue', $d, '--now']));
        break;

    case 'whack_start':
        if (!TuxxinServices::requireWrite()) break;
        $d = $domainArg();
        if ($d === null) break;
        if (!TuxxinServices::enabled('whack')) { $json($disabled('whack')); break; }
        $json(TuxxinServices::helper(['whack', 'start', $d], null, true));
        break;

    case 'whack_status':
        $d = $domainArg();
        if ($d === null) break;
        if (!TuxxinServices::enabled('whack')) { $json($disabled('whack')); break; }
        $json(TuxxinServices::helper(['whack', 'status', $d]));
        break;

    default:
        $json(['success' => false, 'error' => 'Unknown action.'], 400);
}
