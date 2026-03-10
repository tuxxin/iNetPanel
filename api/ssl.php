<?php
// FILE: api/ssl.php
// iNetPanel — SSL Certificate Management API
// Actions: status, issue, revoke, renew, issue_panel

Auth::requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Find the cert directory for a domain.
 * Certbot sometimes creates numbered dirs like domain.com-0001.
 * Returns the path to fullchain.pem or null if not found.
 */
function findCertFile(string $domain): ?string
{
    $primary = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
    if (file_exists($primary)) return $primary;

    // Check for numbered variants (e.g. domain.com-0001, domain.com-0002)
    $pattern = "/etc/letsencrypt/live/{$domain}-*/fullchain.pem";
    $matches = glob($pattern);
    if ($matches) {
        // Use the most recently modified one
        usort($matches, fn($a, $b) => filemtime($b) - filemtime($a));
        return $matches[0];
    }

    return null;
}

switch ($action) {

    // -------------------------------------------------------------------------
    case 'status':
        // List all certificates with their status
        $certs = [];

        // Panel service certificate
        $panelHostname = DB::setting('server_hostname', '');
        $panelCert = null;
        if ($panelHostname && strpos($panelHostname, '.') !== false) {
            $certFile = findCertFile($panelHostname);
            $panelCert = [
                'domain'       => $panelHostname,
                'service'      => true,
                'service_name' => 'Panel & phpMyAdmin',
                'exists'       => false,
                'type'         => null,
                'expiry'       => null,
                'valid'        => false,
            ];
            if ($certFile) {
                $panelCert['exists'] = true;
                $info = openssl_x509_parse(file_get_contents($certFile));
                if ($info) {
                    $panelCert['expiry'] = date('Y-m-d H:i:s', $info['validTo_time_t'] ?? 0);
                    $panelCert['valid']  = ($info['validTo_time_t'] ?? 0) > time();
                    $issuer = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '');
                    $panelCert['type'] = stripos($issuer, "Let's Encrypt") !== false ? 'letsencrypt' : 'self-signed';
                }
            }
            // Check if lighttpd SSL is active
            $panelCert['lighttpd_ssl'] = (bool) preg_match('/ssl\.engine\s*=\s*"enable"/', @file_get_contents('/etc/lighttpd/lighttpd.conf') ?: '');
            // Check if Apache/PMA SSL is active
            $panelCert['pma_ssl'] = (bool) preg_match('/SSLEngine\s+on/i', @file_get_contents('/etc/apache2/sites-available/phpmyadmin.conf') ?: '');
        }

        // Domain certificates
        $domains = DB::fetchAll('SELECT domain_name, port FROM domains ORDER BY domain_name');
        foreach ($domains as $d) {
            $domain = $d['domain_name'];
            $certFile = findCertFile($domain);
            $cert = [
                'domain' => $domain,
                'port'   => $d['port'],
                'exists' => false,
                'type'   => null,
                'expiry' => null,
                'valid'  => false,
            ];

            if ($certFile) {
                $cert['exists'] = true;
                $info = openssl_x509_parse(file_get_contents($certFile));
                if ($info) {
                    $cert['expiry'] = date('Y-m-d H:i:s', $info['validTo_time_t'] ?? 0);
                    $cert['valid']  = ($info['validTo_time_t'] ?? 0) > time();
                    $issuer = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '');
                    $cert['type'] = stripos($issuer, "Let's Encrypt") !== false ? 'letsencrypt' : 'self-signed';
                }
            }

            $certs[] = $cert;
        }

        // Check if certbot renewal cron/timer is active
        $cronActive = file_exists('/etc/cron.d/certbot_renew')
            || file_exists('/etc/cron.d/certbot')
            || trim((string) shell_exec('systemctl is-active certbot.timer 2>/dev/null')) === 'active';

        echo json_encode([
            'success'    => true,
            'panelCert'  => $panelCert,
            'data'       => $certs,
            'cronActive' => $cronActive,
        ]);
        break;

    // -------------------------------------------------------------------------
    case 'issue':
        $domain = trim($_POST['domain'] ?? '');
        if (!$domain) {
            echo json_encode(['success' => false, 'error' => 'Domain required.']);
            break;
        }

        // Verify domain exists in our system
        $row = DB::fetchOne('SELECT domain_name FROM domains WHERE domain_name = ?', [$domain]);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Domain not found in system.']);
            break;
        }

        $output = Shell::run('ssl_manage', ['issue', $domain]);
        echo json_encode($output);
        break;

    // -------------------------------------------------------------------------
    case 'issue_panel':
        $hostname = DB::setting('server_hostname', '');
        if (!$hostname || strpos($hostname, '.') === false) {
            echo json_encode(['success' => false, 'error' => 'No FQDN hostname configured. Set one in Settings → General.']);
            break;
        }
        $output = Shell::run('panel_ssl', [$hostname]);
        echo json_encode($output);
        break;

    // -------------------------------------------------------------------------
    case 'revoke':
        $domain = trim($_POST['domain'] ?? '');
        if (!$domain) {
            echo json_encode(['success' => false, 'error' => 'Domain required.']);
            break;
        }

        $output = Shell::run('ssl_manage', ['revoke', $domain]);
        echo json_encode($output);
        break;

    // -------------------------------------------------------------------------
    case 'renew':
        $output = Shell::run('ssl_manage', ['renew']);
        echo json_encode($output);
        break;

    // -------------------------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
