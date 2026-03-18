<?php
// FILE: api/dns.php
// iNetPanel — Cloudflare DNS API
// Actions: zones, list, create, update, delete


$action = $_GET['action'] ?? $_POST['action'] ?? '';
$cf     = new CloudflareAPI();

switch ($action) {

    case 'zones':
        Auth::requireAdmin();
        $result = $cf->listZones();
        echo json_encode([
            'success' => $result['success'] ?? false,
            'data'    => $result['result']  ?? [],
        ]);
        break;

    case 'list':
        Auth::requireAdmin();
        $zoneId = trim($_GET['zone_id'] ?? '');
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'zone_id required.']); break; }
        $result = $cf->listDNSRecords($zoneId);
        echo json_encode([
            'success' => $result['success'] ?? false,
            'data'    => $result['result']  ?? [],
        ]);
        break;

    case 'create':
        Auth::requireAdmin();
        $zoneId = trim($_POST['zone_id'] ?? '');
        $data   = [
            'type'    => strtoupper($_POST['type']    ?? 'A'),
            'name'    => trim($_POST['name']          ?? ''),
            'content' => trim($_POST['content']       ?? ''),
            'ttl'     => (int)($_POST['ttl']          ?? 3600),
            'proxied' => ($_POST['proxied'] ?? '0') === '1',
        ];
        if (!$zoneId || !$data['name'] || !$data['content']) {
            echo json_encode(['success' => false, 'error' => 'zone_id, name, content required.']); break;
        }
        echo json_encode($cf->createDNSRecord($zoneId, $data));
        break;

    case 'update':
        Auth::requireAdmin();
        $zoneId   = trim($_POST['zone_id']   ?? '');
        $recordId = trim($_POST['record_id'] ?? '');
        $data     = [
            'type'    => strtoupper($_POST['type']    ?? 'A'),
            'name'    => trim($_POST['name']          ?? ''),
            'content' => trim($_POST['content']       ?? ''),
            'ttl'     => (int)($_POST['ttl']          ?? 3600),
            'proxied' => ($_POST['proxied'] ?? '0') === '1',
        ];
        echo json_encode($cf->updateDNSRecord($zoneId, $recordId, $data));
        break;

    case 'delete':
        Auth::requireAdmin();
        $zoneId   = trim($_POST['zone_id']   ?? '');
        $recordId = trim($_POST['record_id'] ?? '');
        echo json_encode($cf->deleteDNSRecord($zoneId, $recordId));
        break;

    case 'zone_settings':
        Auth::requireAdmin();
        $zoneId = trim($_GET['zone_id'] ?? '');
        if (!$zoneId) { echo json_encode(['success' => false, 'error' => 'zone_id required.']); break; }
        $sec = $cf->getZoneSetting($zoneId, 'security_level');
        $dev = $cf->getZoneSetting($zoneId, 'development_mode');
        echo json_encode([
            'success'          => ($sec['success'] ?? false) && ($dev['success'] ?? false),
            'security_level'   => $sec['result']['value'] ?? 'medium',
            'development_mode' => $dev['result']['value'] ?? 'off',
        ]);
        break;

    case 'set_ddos_mode':
        Auth::requireAdmin();
        $zoneId  = trim($_POST['zone_id'] ?? '');
        $enabled = ($_POST['enabled'] ?? '0') === '1';
        $result  = $cf->setSecurityLevel($zoneId, $enabled ? 'under_attack' : 'medium');
        echo json_encode(['success' => $result['success'] ?? false, 'error' => $result['errors'][0]['message'] ?? null]);
        break;

    case 'set_dev_mode':
        Auth::requireAdmin();
        $zoneId  = trim($_POST['zone_id'] ?? '');
        $enabled = ($_POST['enabled'] ?? '0') === '1';
        $result  = $cf->setDevelopmentMode($zoneId, $enabled ? 'on' : 'off');
        echo json_encode(['success' => $result['success'] ?? false, 'error' => $result['errors'][0]['message'] ?? null]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
