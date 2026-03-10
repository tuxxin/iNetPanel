<?php
// FILE: api/ssl.php
// iNetPanel — SSL Certificate Management API
// Actions: status, issue, revoke, renew

Auth::requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // -------------------------------------------------------------------------
    case 'status':
        // List all certificates with their status
        $certs = [];
        $domains = DB::fetchAll('SELECT domain_name, port FROM domains ORDER BY domain_name');

        foreach ($domains as $d) {
            $domain = $d['domain_name'];
            $certFile = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
            $cert = [
                'domain' => $domain,
                'port'   => $d['port'],
                'exists' => false,
                'type'   => null,
                'expiry' => null,
                'valid'  => false,
            ];

            if (file_exists($certFile)) {
                $cert['exists'] = true;
                $info = openssl_x509_parse(file_get_contents($certFile));
                if ($info) {
                    $cert['expiry'] = date('Y-m-d H:i:s', $info['validTo_time_t'] ?? 0);
                    $cert['valid']  = ($info['validTo_time_t'] ?? 0) > time();
                    $issuer = $info['issuer']['O'] ?? '';
                    $cert['type'] = stripos($issuer, "Let's Encrypt") !== false ? 'letsencrypt' : 'self-signed';
                }
            }

            $certs[] = $cert;
        }

        // Check if certbot renewal cron is active
        $cronActive = file_exists('/etc/cron.d/certbot_renew');

        echo json_encode([
            'success'    => true,
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
