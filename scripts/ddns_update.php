<?php
// FILE: scripts/ddns_update.php
// iNetPanel — Cloudflare DDNS Updater
// Run via cron: */5 * * * * www-data php /var/www/inetpanel/scripts/ddns_update.php
// Checks public IP and updates the configured CF A record if it has changed.

define('ROOT_PATH',   dirname(__DIR__));
define('TICORE_PATH', ROOT_PATH . '/TiCore');

require_once TICORE_PATH . '/Config.php';
require_once TICORE_PATH . '/Router.php';
require_once TICORE_PATH . '/App.php';
require_once TICORE_PATH . '/DB.php';
require_once TICORE_PATH . '/CloudflareAPI.php';

$app = App::getInstance();

$lastIpFile = ROOT_PATH . '/db/last_ip.txt';
$logPrefix  = '[' . date('Y-m-d H:i:s') . '] DDNS: ';

// Check if DDNS is enabled
$enabled = DB::setting('cf_ddns_enabled', '0');
if ($enabled !== '1') {
    exit(0);
}

$hostname = DB::setting('cf_ddns_hostname', '');
$zoneId   = DB::setting('cf_ddns_zone_id',  '');

if (empty($hostname)) {
    echo $logPrefix . "No DDNS hostname configured.\n";
    exit(1);
}

// Detect current public IP
$currentIp = trim(file_get_contents('https://api.ipify.org') ?: '');
if (!filter_var($currentIp, FILTER_VALIDATE_IP)) {
    // Fallback
    $currentIp = trim(file_get_contents('https://checkip.amazonaws.com') ?: '');
}
if (!filter_var($currentIp, FILTER_VALIDATE_IP)) {
    echo $logPrefix . "Could not determine public IP.\n";
    exit(1);
}

// Compare with last known IP
$lastIp = file_exists($lastIpFile) ? trim(file_get_contents($lastIpFile)) : '';

if ($currentIp === $lastIp) {
    // No change — nothing to do
    exit(0);
}

echo $logPrefix . "IP changed: {$lastIp} -> {$currentIp}\n";

// If zone ID not stored, try to find it by matching hostname
$cf = new CloudflareAPI();

if (empty($zoneId)) {
    $zones = $cf->listZones();
    foreach ($zones['result'] ?? [] as $zone) {
        if (str_ends_with($hostname, $zone['name'])) {
            $zoneId = $zone['id'];
            DB::saveSetting('cf_ddns_zone_id', $zoneId);
            break;
        }
    }
}

if (empty($zoneId)) {
    echo $logPrefix . "Could not find Cloudflare Zone ID for '{$hostname}'.\n";
    exit(1);
}

// Upsert the A record
$result = $cf->upsertARecord($zoneId, $hostname, $currentIp);

if ($result['success'] ?? false) {
    file_put_contents($lastIpFile, $currentIp);
    DB::saveSetting('cf_ddns_last_ip', $currentIp);
    DB::saveSetting('cf_ddns_last_updated', date('Y-m-d H:i:s'));
    echo $logPrefix . "Updated '{$hostname}' -> {$currentIp}\n";
} else {
    $err = $result['errors'][0]['message'] ?? 'Unknown error';
    echo $logPrefix . "CF API error: {$err}\n";
    exit(1);
}
