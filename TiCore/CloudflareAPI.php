<?php
// FILE: TiCore/CloudflareAPI.php
// TiCore PHP Framework - Cloudflare API v4 Client
// Covers: Zones, DNS Records, Email Routing, DDNS update
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class CloudflareAPI
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    private string $email;
    private string $apiKey;

    public function __construct(?string $email = null, ?string $apiKey = null)
    {
        $this->email  = $email  ?? DB::setting('cf_email',   '');
        $this->apiKey = $apiKey ?? DB::setting('cf_api_key', '');
    }

    // -------------------------------------------------------------------------
    // Zones
    // -------------------------------------------------------------------------

    public function listZones(): array
    {
        return $this->request('GET', '/zones?per_page=50');
    }

    public function getZone(string $zoneId): array
    {
        return $this->request('GET', "/zones/{$zoneId}");
    }

    // -------------------------------------------------------------------------
    // DNS Records
    // -------------------------------------------------------------------------

    public function listDNSRecords(string $zoneId, array $query = []): array
    {
        $qs = $query ? '?' . http_build_query($query) : '';
        return $this->request('GET', "/zones/{$zoneId}/dns_records{$qs}");
    }

    public function createDNSRecord(string $zoneId, array $data): array
    {
        return $this->request('POST', "/zones/{$zoneId}/dns_records", $data);
    }

    public function updateDNSRecord(string $zoneId, string $recordId, array $data): array
    {
        return $this->request('PUT', "/zones/{$zoneId}/dns_records/{$recordId}", $data);
    }

    public function deleteDNSRecord(string $zoneId, string $recordId): array
    {
        return $this->request('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");
    }

    /**
     * Update or create a DDNS A record.
     * Finds the record by name; updates if found, creates if not.
     */
    public function upsertARecord(string $zoneId, string $name, string $ip): array
    {
        $records = $this->listDNSRecords($zoneId, ['type' => 'A', 'name' => $name]);
        $existing = $records['result'][0] ?? null;

        $data = [
            'type'    => 'A',
            'name'    => $name,
            'content' => $ip,
            'ttl'     => 60,
            'proxied' => false,
        ];

        if ($existing) {
            return $this->updateDNSRecord($zoneId, $existing['id'], $data);
        }
        return $this->createDNSRecord($zoneId, $data);
    }

    // -------------------------------------------------------------------------
    // Email Routing
    // -------------------------------------------------------------------------

    public function listEmailRouting(string $zoneId): array
    {
        return $this->request('GET', "/zones/{$zoneId}/email/routing/rules");
    }

    public function createEmailRule(string $zoneId, array $data): array
    {
        return $this->request('POST', "/zones/{$zoneId}/email/routing/rules", $data);
    }

    public function deleteEmailRule(string $zoneId, string $ruleId): array
    {
        return $this->request('DELETE', "/zones/{$zoneId}/email/routing/rules/{$ruleId}");
    }

    public function listEmailAddresses(string $zoneId): array
    {
        return $this->request('GET', "/zones/{$zoneId}/email/routing/addresses");
    }

    public function createEmailAddress(string $zoneId, string $email): array
    {
        return $this->request('POST', "/zones/{$zoneId}/email/routing/addresses", ['email' => $email]);
    }

    // -------------------------------------------------------------------------
    // Account Validation
    // -------------------------------------------------------------------------

    public function validateCredentials(): bool
    {
        $result = $this->request('GET', '/user');
        return $result['success'] ?? false;
    }

    // -------------------------------------------------------------------------
    // HTTP Transport
    // -------------------------------------------------------------------------

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::BASE . $path;

        $headers = [
            'X-Auth-Email: ' . $this->email,
            'X-Auth-Key: '   . $this->apiKey,
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'errors' => [['message' => $error]], 'result' => null];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['success' => false, 'errors' => [['message' => 'Invalid JSON']], 'result' => null];
    }
}
