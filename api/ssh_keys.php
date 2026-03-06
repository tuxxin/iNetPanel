<?php
// FILE: api/ssh_keys.php
// iNetPanel — SSH Key Manager API
// Actions: list, add, delete, generate
// Supports: all hosting accounts + root (admin-only)

Auth::check();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$domain = trim($_GET['domain'] ?? $_POST['domain'] ?? '');

// Validate domain
if (!$domain) {
    echo json_encode(['success' => false, 'error' => 'Missing domain.']);
    exit;
}

// Access control
if ($domain === 'root') {
    Auth::requireAdmin();
} else {
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
        echo json_encode(['success' => false, 'error' => 'Invalid domain name.']);
        exit;
    }
    if (!Auth::canAccessDomain($domain)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }
}

/**
 * Run manage_ssh_keys.sh with given arguments via sudo.
 * Returns decoded JSON output from the script.
 */
function runKeyScript(string $domain, string $action, array $extra = []): array
{
    $cmd = 'sudo /root/scripts/manage_ssh_keys.sh'
        . ' --domain ' . escapeshellarg($domain)
        . ' --action ' . escapeshellarg($action);

    foreach ($extra as $flag => $val) {
        $cmd .= ' ' . escapeshellarg($flag) . ' ' . escapeshellarg($val);
    }
    $cmd .= ' 2>&1';

    $output   = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $json = implode('', $output);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Script error: ' . $json, 'code' => $exitCode];
    }
    return $data;
}

switch ($action) {

    case 'list':
        $result = runKeyScript($domain, 'list');
        echo json_encode($result);
        break;

    case 'add':
        $key     = trim($_POST['key']     ?? '');
        $comment = trim($_POST['comment'] ?? '');

        if (!$key) {
            echo json_encode(['success' => false, 'error' => 'Missing key.']);
            break;
        }
        // Validate public key format
        if (!preg_match('/^(ssh-(rsa|dss|ed25519|ecdsa)|ecdsa-sha2-nistp(256|384|521))\s+[A-Za-z0-9+\/]+=*/', $key)) {
            echo json_encode(['success' => false, 'error' => 'Invalid public key format. Must start with ssh-ed25519, ssh-rsa, etc.']);
            break;
        }

        $extra = ['--key' => $key];
        if ($comment) {
            $extra['--comment'] = $comment;
        }
        $result = runKeyScript($domain, 'add', $extra);
        echo json_encode($result);
        break;

    case 'delete':
        $fingerprint = trim($_POST['fingerprint'] ?? '');
        if (!$fingerprint) {
            echo json_encode(['success' => false, 'error' => 'Missing fingerprint.']);
            break;
        }
        $result = runKeyScript($domain, 'delete', ['--key' => $fingerprint]);
        echo json_encode($result);
        break;

    case 'generate':
        $comment = trim($_POST['comment'] ?? '');
        $extra   = $comment ? ['--comment' => $comment] : [];
        $result  = runKeyScript($domain, 'generate', $extra);
        // private_key is in the result — pass through once, never log it
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
