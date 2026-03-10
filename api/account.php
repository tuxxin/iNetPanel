<?php
// FILE: api/account.php
// iNetPanel — Account Portal API (for hosting account holders)
// Requires AccountAuth session. Returns data scoped to the logged-in user's domains only.

$username = AccountAuth::username();
if (!$username) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$reqDomain = trim($_GET['domain'] ?? $_POST['domain'] ?? '');

// Verify the requested domain belongs to the logged-in user
$accountUser = AccountAuth::user();
$ownedDomains = array_column($accountUser['domains'] ?? [], 'domain_name');
if ($reqDomain && !in_array($reqDomain, $ownedDomains, true)) {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$domain = $reqDomain ?: ($ownedDomains[0] ?? $username);

$cf = new CloudflareAPI();

// Helper: find Cloudflare zone ID for domain (or parent domain)
function findZoneId(CloudflareAPI $cf, string $domain): ?string {
    return $cf->findZoneForHostname($domain);
}

switch ($action) {

    // ── DNS ──────────────────────────────────────────────────────────────

    case 'dns':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zone found for this domain.']);
            break;
        }
        $result = $cf->listDNSRecords($zoneId);
        echo json_encode([
            'success' => true,
            'zone_id' => $zoneId,
            'records' => $result['result'] ?? [],
        ]);
        break;

    case 'dns_create':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $data = [
            'type'    => strtoupper($_POST['type'] ?? 'A'),
            'name'    => trim($_POST['name'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'ttl'     => (int)($_POST['ttl'] ?? 3600),
            'proxied' => ($_POST['proxied'] ?? '0') === '1',
        ];
        if (!$data['name'] || !$data['content']) {
            echo json_encode(['success' => false, 'error' => 'Name and content required.']); break;
        }
        $resp = $cf->createDNSRecord($zoneId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'dns_update':
        $zoneId   = findZoneId($cf, $domain);
        $recordId = trim($_POST['record_id'] ?? '');
        if (!$zoneId || !$recordId) { echo json_encode(['success' => false, 'error' => 'Zone or record not found.']); break; }
        $data = [
            'type'    => strtoupper($_POST['type'] ?? 'A'),
            'name'    => trim($_POST['name'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'ttl'     => (int)($_POST['ttl'] ?? 3600),
            'proxied' => ($_POST['proxied'] ?? '0') === '1',
        ];
        $resp = $cf->updateDNSRecord($zoneId, $recordId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'dns_delete':
        $zoneId   = findZoneId($cf, $domain);
        $recordId = trim($_POST['record_id'] ?? '');
        if (!$zoneId || !$recordId) { echo json_encode(['success' => false, 'error' => 'Zone or record not found.']); break; }
        $resp = $cf->deleteDNSRecord($zoneId, $recordId);
        echo json_encode(['success' => !empty($resp['success'])]);
        break;

    // ── Email Routing ────────────────────────────────────────────────────

    case 'email':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zone found for this domain.']);
            break;
        }
        $result = $cf->listEmailRouting($zoneId);
        echo json_encode([
            'success' => !empty($result['success']),
            'zone_id' => $zoneId,
            'rules'   => $result['result'] ?? [],
        ]);
        break;

    case 'email_create_rule':
        $zoneId = findZoneId($cf, $domain);
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'Zone not found.']); break; }
        $from = trim($_POST['from'] ?? '');
        $to   = trim($_POST['to'] ?? '');
        if (!$from || !$to) { echo json_encode(['success' => false, 'error' => 'From and to required.']); break; }
        $data = [
            'actions'  => [['type' => 'forward', 'value' => [$to]]],
            'matchers' => [['type' => 'literal', 'field' => 'to', 'value' => $from]],
            'enabled'  => true,
            'name'     => "Forward {$from} → {$to}",
        ];
        $resp = $cf->createEmailRule($zoneId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'email_delete_rule':
        $zoneId = findZoneId($cf, $domain);
        $ruleId = trim($_POST['rule_id'] ?? '');
        if (!$zoneId || !$ruleId) { echo json_encode(['success' => false, 'error' => 'Zone or rule not found.']); break; }
        $resp = $cf->deleteEmailRule($zoneId, $ruleId);
        echo json_encode(['success' => !empty($resp['success'])]);
        break;

    case 'email_addresses':
        $accountId = DB::setting('cf_account_id', '');
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'CF account not configured.']); break; }
        $resp = $cf->listEmailAddresses($accountId);
        echo json_encode(['success' => !empty($resp['success']), 'addresses' => $resp['result'] ?? []]);
        break;

    case 'email_create_address':
        $accountId = DB::setting('cf_account_id', '');
        $email     = trim($_POST['email'] ?? '');
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'CF account not configured.']); break; }
        if (!$email) { echo json_encode(['success' => false, 'error' => 'Email required.']); break; }
        $resp = $cf->createEmailAddress($accountId, $email);
        echo json_encode(['success' => !empty($resp['success']), 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
