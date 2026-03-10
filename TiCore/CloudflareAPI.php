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

    public function listEmailAddresses(string $accountId): array
    {
        return $this->request('GET', "/accounts/{$accountId}/email/routing/addresses");
    }

    public function createEmailAddress(string $accountId, string $email): array
    {
        return $this->request('POST', "/accounts/{$accountId}/email/routing/addresses", ['email' => $email]);
    }

    // -------------------------------------------------------------------------
    // Zero Trust Tunnels
    // -------------------------------------------------------------------------

    /**
     * Create a named Cloudflare Zero Trust tunnel.
     * Returns the full API response; result.id is the tunnel UUID.
     */
    public function createTunnel(string $accountId, string $name): array
    {
        return $this->request('POST', "/accounts/{$accountId}/cfd_tunnel", [
            'name'         => $name,
            'tunnel_secret' => base64_encode(random_bytes(32)),
            'config_src'   => 'cloudflare',
        ]);
    }

    /**
     * Retrieve the cloudflared connector token for a tunnel.
     * Returns the raw token string, or false on failure.
     */
    public function getTunnelToken(string $accountId, string $tunnelId): string|false
    {
        $result = $this->request('GET', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token");
        $token = $result['result'] ?? '';
        return is_string($token) && $token !== '' ? $token : false;
    }

    /**
     * Get the current ingress configuration for a tunnel.
     */
    public function getTunnelConfig(string $accountId, string $tunnelId): array
    {
        return $this->request('GET', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations");
    }

    /**
     * Replace the full ingress configuration for a tunnel.
     */
    public function updateTunnelConfig(string $accountId, string $tunnelId, array $ingress): array
    {
        return $this->request('PUT', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", [
            'config' => ['ingress' => $ingress],
        ]);
    }

    /**
     * Add a public hostname to a tunnel, routing it to a local service.
     * Also creates the CNAME DNS record in the matching CF zone (if found).
     *
     * @param string $hostname  e.g. "client1.com"
     * @param string $service   e.g. "http://localhost:1080"
     */
    public function addTunnelHostname(string $accountId, string $tunnelId, string $hostname, string $service): array
    {
        // Get current ingress rules
        $config  = $this->getTunnelConfig($accountId, $tunnelId);
        $ingress = $config['result']['config']['ingress'] ?? [['service' => 'http_status:404']];

        // Remove any existing entry for this hostname (prevent duplicates)
        $ingress = array_values(array_filter($ingress, fn($r) => ($r['hostname'] ?? '') !== $hostname));

        // Ensure catch-all is last; insert new rule before it
        $catchAll = array_pop($ingress) ?? ['service' => 'http_status:404'];
        $rule = ['hostname' => $hostname, 'service' => $service];
        // For HTTPS origins, add originRequest so cloudflared verifies the local cert
        if (str_starts_with($service, 'https://')) {
            $rule['originRequest'] = ['originServerName' => $hostname];
        }
        $ingress[] = $rule;
        $ingress[] = $catchAll;

        $result = $this->updateTunnelConfig($accountId, $tunnelId, $ingress);

        // Auto-create CNAME DNS record if the zone is in this CF account
        $dnsCreated = $this->upsertTunnelCname($tunnelId, $hostname);

        $result['dns_skipped'] = !$dnsCreated;
        return $result;
    }

    /**
     * Remove a public hostname from a tunnel and delete its CNAME DNS record.
     */
    public function removeTunnelHostname(string $accountId, string $tunnelId, string $hostname): array
    {
        $config  = $this->getTunnelConfig($accountId, $tunnelId);
        $ingress = $config['result']['config']['ingress'] ?? [['service' => 'http_status:404']];

        $ingress = array_values(array_filter($ingress, fn($r) => ($r['hostname'] ?? '') !== $hostname));
        if (empty($ingress)) {
            $ingress = [['service' => 'http_status:404']];
        }

        $result = $this->updateTunnelConfig($accountId, $tunnelId, $ingress);

        // Remove CNAME DNS record if found
        $this->deleteTunnelCname($hostname);

        return $result;
    }

    /**
     * Create or update a CNAME DNS record pointing to the tunnel.
     * Looks up the CF zone by matching the hostname's root domain.
     */
    private function upsertTunnelCname(string $tunnelId, string $hostname): bool
    {
        $cname = "{$tunnelId}.cfargotunnel.com";
        $zoneId = $this->findZoneForHostname($hostname);
        if (!$zoneId) return false;

        $records = $this->listDNSRecords($zoneId, ['type' => 'CNAME', 'name' => $hostname]);
        $existing = $records['result'][0] ?? null;
        $data = ['type' => 'CNAME', 'name' => $hostname, 'content' => $cname, 'ttl' => 1, 'proxied' => true];
        if ($existing) {
            $this->updateDNSRecord($zoneId, $existing['id'], $data);
        } else {
            $this->createDNSRecord($zoneId, $data);
        }
        return true;
    }

    /**
     * Delete the CNAME DNS record for a hostname (if it points to cfargotunnel.com).
     */
    private function deleteTunnelCname(string $hostname): void
    {
        $zoneId = $this->findZoneForHostname($hostname);
        if (!$zoneId) return;

        $records = $this->listDNSRecords($zoneId, ['type' => 'CNAME', 'name' => $hostname]);
        foreach ($records['result'] ?? [] as $record) {
            if (str_contains($record['content'] ?? '', 'cfargotunnel.com')) {
                $this->deleteDNSRecord($zoneId, $record['id']);
            }
        }
    }

    /**
     * Find the CF zone ID that manages the given hostname.
     * Matches by stripping subdomains until a zone is found.
     */
    private function findZoneForHostname(string $hostname): string|null
    {
        $parts = explode('.', $hostname);
        while (count($parts) >= 2) {
            $candidate = implode('.', $parts);
            $zones = $this->request('GET', "/zones?name={$candidate}&per_page=1");
            if (!empty($zones['result'][0]['id'])) {
                return $zones['result'][0]['id'];
            }
            array_shift($parts);
        }
        return null;
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
