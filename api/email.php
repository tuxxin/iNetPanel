<?php
// FILE: api/email.php
// iNetPanel — Cloudflare Email Routing API
// Actions: list_rules, create_rule, delete_rule, list_addresses, create_address


$action = $_GET['action'] ?? $_POST['action'] ?? '';
$cf     = new CloudflareAPI();

switch ($action) {

    case 'list_rules':
        $zoneId = trim($_GET['zone_id'] ?? '');
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'zone_id required.']); break; }
        echo json_encode($cf->listEmailRouting($zoneId));
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
        echo json_encode($cf->createEmailRule($zoneId, $data));
        break;

    case 'delete_rule':
        Auth::requireAdmin();
        $zoneId = trim($_POST['zone_id'] ?? '');
        $ruleId = trim($_POST['rule_id'] ?? '');
        echo json_encode($cf->deleteEmailRule($zoneId, $ruleId));
        break;

    case 'list_addresses':
        $zoneId = trim($_GET['zone_id'] ?? '');
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'zone_id required.']); break; }
        echo json_encode($cf->listEmailAddresses($zoneId));
        break;

    case 'create_address':
        Auth::requireAdmin();
        $zoneId = trim($_POST['zone_id'] ?? '');
        $email  = trim($_POST['email']   ?? '');
        echo json_encode($cf->createEmailAddress($zoneId, $email));
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
