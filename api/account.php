<?php
// FILE: api/account.php
// iNetPanel — Account Portal API (for hosting account holders)
// Requires AccountAuth session. Returns data scoped to the logged-in domain only.

$domain = AccountAuth::domain();
if (!$domain) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

$action = $_GET['action'] ?? '';
$reqDomain = trim($_GET['domain'] ?? '');

// Verify the requested domain belongs to the logged-in account
if ($reqDomain !== $domain) {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$cf = new CloudflareAPI();

switch ($action) {

    case 'dns':
        // Find the Cloudflare zone for this domain (or parent domain)
        $zones = $cf->listZones();
        if (!$zones['success'] || empty($zones['data'])) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zones found.']); break;
        }
        // Match zone by domain name or parent domain (e.g. sub.example.com → example.com)
        $zoneId = null;
        foreach ($zones['data'] as $z) {
            if ($domain === $z['name'] || str_ends_with($domain, '.' . $z['name'])) {
                $zoneId = $z['id'];
                break;
            }
        }
        if (!$zoneId) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zone found for this domain.']); break;
        }
        $result  = $cf->listDnsRecords($zoneId, $domain);
        $records = $result['data'] ?? [];
        echo json_encode(['success' => true, 'records' => $records]);
        break;

    case 'email':
        $zones = $cf->listZones();
        if (!$zones['success'] || empty($zones['data'])) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zones found.']); break;
        }
        $zoneId = null;
        foreach ($zones['data'] as $z) {
            if ($domain === $z['name'] || str_ends_with($domain, '.' . $z['name'])) {
                $zoneId = $z['id'];
                break;
            }
        }
        if (!$zoneId) {
            echo json_encode(['success' => false, 'error' => 'No Cloudflare zone found for this domain.']); break;
        }
        $result = $cf->listEmailRouting($zoneId);
        echo json_encode(['success' => $result['success'], 'rules' => $result['data'] ?? []]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
