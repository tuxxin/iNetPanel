<?php
// FILE: api/profile.php
// iNetPanel — Profile API
// Actions: change_password

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'change_password':
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password']     ?? '';

        if (strlen($newPass) < 8) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
            break;
        }

        $user    = Auth::user();
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);

        if (Auth::isAdmin()) {
            $row = DB::fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);
            if (!$row || !password_verify($currentPass, $row['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
                break;
            }
            DB::update('users', ['password_hash' => $newHash], 'id = ?', [$user['id']]);
        } else {
            // Sub-admin IDs are stored in session as 'p_<id>'
            $id  = (int) ltrim((string) $user['id'], 'p_');
            $row = DB::fetchOne('SELECT * FROM panel_users WHERE id = ?', [$id]);
            if (!$row || !password_verify($currentPass, $row['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
                break;
            }
            DB::update('panel_users', ['password_hash' => $newHash], 'id = ?', [$id]);
        }

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
