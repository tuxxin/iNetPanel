<?php
// FILE: api/hook_scripts.php
// iNetPanel — Hook Scripts API
// Actions: get, save, toggle, validate

Auth::requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'get':
        echo json_encode([
            'success' => true,
            'data'    => [
                'add_domain' => [
                    'enabled' => DB::setting('hook_add_domain_enabled', '0'),
                    'code'    => DB::setting('hook_add_domain_code', ''),
                ],
                'delete_domain' => [
                    'enabled' => DB::setting('hook_delete_domain_enabled', '0'),
                    'code'    => DB::setting('hook_delete_domain_code', ''),
                ],
            ],
        ]);
        break;

    case 'toggle':
        $hookType = trim($_POST['hook_type'] ?? '');
        $enabled  = trim($_POST['enabled'] ?? '');

        if (!in_array($hookType, ['add_domain', 'delete_domain'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid hook type.']);
            break;
        }
        if (!in_array($enabled, ['0', '1'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid enabled value.']);
            break;
        }

        DB::saveSetting("hook_{$hookType}_enabled", $enabled);
        echo json_encode(['success' => true, 'enabled' => $enabled]);
        break;

    case 'save':
        $hookType = trim($_POST['hook_type'] ?? '');
        $code     = $_POST['code'] ?? '';

        if (!in_array($hookType, ['add_domain', 'delete_domain'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid hook type.']);
            break;
        }

        // Validate bash syntax before saving
        if (trim($code) !== '') {
            $tmp = tempnam('/tmp', 'inetp_hook_');
            file_put_contents($tmp, "#!/bin/bash\n" . $code);
            $check = Shell::exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', 'hook-validate');
            @unlink($tmp);

            if (!$check['success']) {
                $errors = preg_replace('/\/tmp\/inetp_hook_\w+/', 'script', $check['output']);
                echo json_encode(['success' => false, 'error' => 'Bash syntax error:', 'details' => $errors]);
                break;
            }
        }

        DB::saveSetting("hook_{$hookType}_code", $code);
        echo json_encode(['success' => true]);
        break;

    case 'validate':
        $code = $_POST['code'] ?? '';

        if (trim($code) === '') {
            echo json_encode(['success' => true, 'valid' => false, 'errors' => 'No code to validate.']);
            break;
        }

        $tmp = tempnam('/tmp', 'inetp_hook_');
        file_put_contents($tmp, "#!/bin/bash\n" . $code);
        $check = Shell::exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', 'hook-validate');
        @unlink($tmp);

        if ($check['success']) {
            echo json_encode(['success' => true, 'valid' => true]);
        } else {
            $errors = preg_replace('/\/tmp\/inetp_hook_\w+/', 'script', $check['output']);
            echo json_encode(['success' => true, 'valid' => false, 'errors' => $errors]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
