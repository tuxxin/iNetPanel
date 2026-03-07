<?php
// FILE: api/panel_users.php
// iNetPanel — Panel Users API (sub-admin management, admin only)
// Actions: list, create, update, delete


Auth::requireAdmin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'list':
        $users = DB::fetchAll('SELECT id, username, role, assigned_domains, created_at FROM panel_users ORDER BY created_at DESC');
        foreach ($users as &$u) {
            $u['assigned_domains'] = json_decode($u['assigned_domains'] ?? '[]', true);
        }
        echo json_encode(['success' => true, 'data' => $users]);
        break;

    case 'create':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'subadmin';
        $domains  = json_decode($_POST['domains'] ?? '[]', true) ?: [];

        if (!in_array($role, ['fulladmin', 'subadmin'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid role.']); break;
        }
        if (!$username || !$password) {
            echo json_encode(['success' => false, 'error' => 'Username and password required.']); break;
        }
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']); break;
        }
        // Full admins have access to all domains
        if ($role === 'fulladmin') {
            $domains = [];
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $id = DB::insert('panel_users', [
                'username'         => $username,
                'password_hash'    => $hash,
                'role'             => $role,
                'assigned_domains' => json_encode($domains),
            ]);
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Username already exists.']);
        }
        break;

    case 'update':
        $id      = (int)($_POST['id'] ?? 0);
        $role    = $_POST['role'] ?? null;
        $domains = json_decode($_POST['domains'] ?? '[]', true) ?: [];
        $updates = [];

        if ($role !== null) {
            if (!in_array($role, ['fulladmin', 'subadmin'], true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid role.']); break;
            }
            $updates['role'] = $role;
            // Full admins don't need domain restrictions
            if ($role === 'fulladmin') {
                $domains = [];
            }
        }
        $updates['assigned_domains'] = json_encode($domains);

        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 8) {
                echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']); break;
            }
            $updates['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        DB::update('panel_users', $updates, 'id = ?', [$id]);
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        DB::delete('panel_users', 'id = ?', [$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
