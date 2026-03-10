<?php
// FILE: src/panel_users.php
// iNetPanel — Panel Sub-Admin User Management

Auth::requireSuperAdmin();

// Load all domains for the multi-select
$domains = [];
try {
    $domains = DB::fetchAll('SELECT domain_name FROM domains ORDER BY domain_name');
} catch (\Throwable $e) {}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-user-shield me-2"></i>Panel Users</h4>
    <button class="btn btn-primary btn-sm" id="add-user-btn">
        <i class="fas fa-plus me-1"></i>Add User
    </button>
</div>

<div id="users-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="panel-users-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Username</th>
                        <th>Role</th>
                        <th>Assigned Domains</th>
                        <th>Created</th>
                        <th class="text-end pe-4 no-sort">Actions</th>
                    </tr>
                </thead>
                <tbody id="users-tbody">
                    <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="user-modal-title"><i class="fas fa-user-plus me-2"></i>Add Sub-Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="user-id">
                <div class="row g-3">
                    <div class="col-md-6" id="username-wrap">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="user-username" placeholder="username" autocomplete="off">
                        <div class="form-text">Lowercase letters, numbers, hyphens only.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Password <span id="pass-optional" class="text-muted small">(leave blank to keep)</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="user-password" placeholder="Min 8 characters" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" id="toggle-user-pass"><i class="fas fa-eye"></i></button>
                            <button class="btn btn-outline-secondary" type="button" id="gen-user-pass"><i class="fas fa-dice"></i></button>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="user-role" id="role-fulladmin" value="fulladmin">
                                <label class="form-check-label" for="role-fulladmin">
                                    <span class="fw-semibold">Full Admin</span>
                                    <div class="form-text mt-0">Full control over the panel. Cannot manage other panel users.</div>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="user-role" id="role-subadmin" value="subadmin" checked>
                                <label class="form-check-label" for="role-subadmin">
                                    <span class="fw-semibold">Sub-user</span>
                                    <div class="form-text mt-0">Restricted to assigned domains. Sees only Dashboard, Accounts, DNS, and Email.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12" id="domain-section">
                        <label class="form-label fw-semibold">Assigned Domains</label>
                        <div class="form-text mb-2">Select which hosting accounts this sub-user can manage. Leave empty to grant access to none.</div>
                        <?php if (empty($domains)): ?>
                        <p class="text-muted small">No hosting accounts exist yet.</p>
                        <?php else: ?>
                        <div class="row g-2" id="domain-checkboxes">
                            <?php foreach ($domains as $d): ?>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input domain-checkbox" type="checkbox"
                                           value="<?= htmlspecialchars($d['domain_name']) ?>"
                                           id="chk-<?= htmlspecialchars($d['domain_name']) ?>">
                                    <label class="form-check-label small" for="chk-<?= htmlspecialchars($d['domain_name']) ?>">
                                        <?= htmlspecialchars($d['domain_name']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-link p-0" onclick="selectAllDomains(true)">Select all</button>
                            &nbsp;/&nbsp;
                            <button type="button" class="btn btn-sm btn-link p-0" onclick="selectAllDomains(false)">Clear all</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="user-modal-error" class="d-none mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-user-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="user-save-spinner"></span>
                    Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Delete sub-admin <strong id="delete-user-name"></strong>? This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm-delete-user-btn">Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingDeleteUserId = null;

function showAlert(msg, type = 'success') {
    const el = document.getElementById('users-alert');
    el.className = `alert alert-${type}`;
    el.innerHTML = msg;
    setTimeout(() => el.className = 'd-none', 5000);
}

function selectAllDomains(state) {
    document.querySelectorAll('.domain-checkbox').forEach(cb => cb.checked = state);
}

function roleLabel(role) {
    return role === 'fulladmin' ? '<span class="badge bg-primary">Full Admin</span>'
                                : '<span class="badge bg-secondary">Sub-user</span>';
}

function updateDomainSection() {
    const role = document.querySelector('input[name="user-role"]:checked')?.value;
    document.getElementById('domain-section').style.display = (role === 'subadmin') ? '' : 'none';
}

document.querySelectorAll('input[name="user-role"]').forEach(r =>
    r.addEventListener('change', updateDomainSection)
);

function loadUsers() {
    fetch('/api/panel-users?action=list')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('users-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No panel users yet.</td></tr>'; return;
            }
            tbody.innerHTML = data.data.map(u => {
                const domains = Array.isArray(u.assigned_domains) ? u.assigned_domains : [];
                const domainBadges = u.role === 'fulladmin'
                    ? '<span class="text-muted small">All domains</span>'
                    : (domains.length
                        ? domains.map(d => `<span class="badge bg-primary-subtle text-primary me-1">${d}</span>`).join('')
                        : '<span class="text-muted small">None</span>');
                const created = u.created_at ? u.created_at.substring(0, 10) : '—';
                return `<tr>
                    <td class="ps-4 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>${u.username}</td>
                    <td>${roleLabel(u.role)}</td>
                    <td>${domainBadges}</td>
                    <td class="text-muted small">${created}</td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editUser(${JSON.stringify(u).replace(/"/g,'&quot;')})" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDeleteUser(${u.id}, '${u.username}')" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        });
}

function openAddModal() {
    document.getElementById('user-modal-title').innerHTML = '<i class="fas fa-user-plus me-2"></i>Add Panel User';
    document.getElementById('user-id').value         = '';
    document.getElementById('user-username').value   = '';
    document.getElementById('user-password').value   = '';
    document.getElementById('pass-optional').textContent = '(required)';
    document.getElementById('username-wrap').style.display = '';
    document.getElementById('role-subadmin').checked = true;
    document.querySelectorAll('.domain-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('user-modal-error').className = 'd-none';
    updateDomainSection();
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function editUser(u) {
    document.getElementById('user-modal-title').innerHTML = '<i class="fas fa-user-edit me-2"></i>Edit Panel User';
    document.getElementById('user-id').value       = u.id;
    document.getElementById('user-username').value = u.username;
    document.getElementById('user-password').value = '';
    document.getElementById('pass-optional').textContent = '(leave blank to keep current)';
    document.getElementById('username-wrap').style.display = 'none';
    // Set role radio
    const roleVal = u.role || 'subadmin';
    const roleRadio = document.querySelector(`input[name="user-role"][value="${roleVal}"]`);
    if (roleRadio) roleRadio.checked = true;
    updateDomainSection();
    const assigned = Array.isArray(u.assigned_domains) ? u.assigned_domains : [];
    document.querySelectorAll('.domain-checkbox').forEach(cb => {
        cb.checked = assigned.includes(cb.value);
    });
    document.getElementById('user-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

document.getElementById('add-user-btn').addEventListener('click', openAddModal);

document.getElementById('toggle-user-pass').addEventListener('click', function () {
    const inp = document.getElementById('user-password');
    const icon = this.querySelector('i');
    if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fas fa-eye-slash'; }
    else { inp.type = 'password'; icon.className = 'fas fa-eye'; }
});

document.getElementById('gen-user-pass').addEventListener('click', function () {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
    let pass = '';
    const arr = new Uint32Array(16);
    crypto.getRandomValues(arr);
    arr.forEach(n => pass += chars[n % chars.length]);
    const inp = document.getElementById('user-password');
    inp.value = pass;
    inp.type  = 'text';
    document.getElementById('toggle-user-pass').querySelector('i').className = 'fas fa-eye-slash';
});

document.getElementById('save-user-btn').addEventListener('click', function () {
    const id       = document.getElementById('user-id').value;
    const username = document.getElementById('user-username').value.trim();
    const password = document.getElementById('user-password').value;
    const errEl    = document.getElementById('user-modal-error');
    const domains  = [...document.querySelectorAll('.domain-checkbox:checked')].map(c => c.value);

    if (!id && !username) { errEl.className = 'alert alert-danger mt-3 small py-2'; errEl.textContent = 'Username required.'; return; }
    if (!id && !password) { errEl.className = 'alert alert-danger mt-3 small py-2'; errEl.textContent = 'Password required for new users.'; return; }
    if (password && password.length < 8) { errEl.className = 'alert alert-danger mt-3 small py-2'; errEl.textContent = 'Password must be at least 8 characters.'; return; }

    const spinner = document.getElementById('user-save-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    errEl.className = 'd-none';

    const role = document.querySelector('input[name="user-role"]:checked')?.value || 'subadmin';

    const fd = new FormData();
    fd.append('action',  id ? 'update' : 'create');
    if (id) fd.append('id', id);
    if (!id) fd.append('username', username);
    if (password) fd.append('password', password);
    fd.append('role', role);
    fd.append('domains', JSON.stringify(domains));

    fetch('/api/panel-users', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
                showAlert(id ? 'User updated.' : 'User created.');
                loadUsers();
            } else {
                errEl.className = 'alert alert-danger mt-3 small py-2';
                errEl.textContent = data.error || 'Save failed.';
            }
        });
});

function confirmDeleteUser(id, username) {
    pendingDeleteUserId = id;
    document.getElementById('delete-user-name').textContent = username;
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}

document.getElementById('confirm-delete-user-btn').addEventListener('click', function () {
    if (!pendingDeleteUserId) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', pendingDeleteUserId);
    fetch('/api/panel-users', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('deleteUserModal')).hide();
            if (data.success) { showAlert('User deleted.'); loadUsers(); }
            else showAlert(data.error || 'Delete failed.', 'danger');
        });
});

document.addEventListener('DOMContentLoaded', function () {
    loadUsers();
    TableKit.init('panel-users-table', { filter: true });
});
</script>
