<?php
// FILE: api/email.php
// iNetPanel — Cloudflare Email Routing API
// Actions: list_rules, create_rule, delete_rule, list_addresses, create_address


$action = $_GET['action'] ?? $_POST['action'] ?? '';
$cf     = new CloudflareAPI();

switch ($action) {

    case 'list_rules':
        Auth::requireAdmin();
        $zoneId = trim($_GET['zone_id'] ?? '');
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'zone_id required.']); break; }
        $resp = $cf->listEmailRouting($zoneId);
        echo json_encode(['success' => !empty($resp['success']), 'data' => $resp['result'] ?? []]);
        break;

    case 'create_rule':
        Auth::requireAdmin();
        $zoneId = trim($_POST['zone_id'] ?? '');
        $from   = trim($_POST['from']    ?? '');
        $to     = trim($_POST['to']      ?? '');
        if (!$zoneId || !$from || !$to) {
            echo json_encode(['success' => false, 'error' => 'zone_id, from, to required.']); break;
        }
        $data = [
            'actions'  => [['type' => 'forward', 'value' => [$to]]],
            'matchers' => [['type' => 'literal',  'field' => 'to', 'value' => $from]],
            'enabled'  => true,
            'name'     => "Forward {$from} → {$to}",
        ];
        $resp = $cf->createEmailRule($zoneId, $data);
        echo json_encode(['success' => !empty($resp['success']), 'data' => $resp['result'] ?? null, 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    case 'delete_rule':
        Auth::requireAdmin();
        $zoneId = trim($_POST['zone_id'] ?? '');
        $ruleId = trim($_POST['rule_id'] ?? '');
        $resp = $cf->deleteEmailRule($zoneId, $ruleId);
        echo json_encode(['success' => !empty($resp['success'])]);
        break;

    case 'list_addresses':
        Auth::requireAdmin();
        $accountId = DB::setting('cf_account_id', '');
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'Cloudflare account ID not configured.']); break; }
        $resp = $cf->listEmailAddresses($accountId);
        echo json_encode(['success' => !empty($resp['success']), 'data' => $resp['result'] ?? []]);
        break;

    case 'create_address':
        Auth::requireAdmin();
        $accountId = DB::setting('cf_account_id', '');
        $email     = trim($_POST['email'] ?? '');
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'Cloudflare account ID not configured.']); break; }
        $resp = $cf->createEmailAddress($accountId, $email);
        echo json_encode(['success' => !empty($resp['success']), 'data' => $resp['result'] ?? null, 'error' => $resp['errors'][0]['message'] ?? '']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
