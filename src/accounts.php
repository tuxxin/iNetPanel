<?php
// FILE: src/accounts.php
// iNetPanel — Accounts list (data loaded via AJAX)

$isAdmin = Auth::hasFullAccess();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-users me-2"></i>Accounts</h4>
    <div class="d-flex gap-2">
        <?php if ($isAdmin): ?>
        <button class="btn btn-outline-secondary btn-sm" onclick="openSshKeys('root')" title="Manage SSH keys for the root server account">
            <i class="fas fa-key me-1"></i>Root SSH Keys
        </button>
        <a href="/admin/add-account" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add Account</a>
        <?php endif; ?>
    </div>
</div>

<!-- Accounts table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="accounts-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">User</th>
                        <th>Domain</th>
                        <th>Port</th>
                        <th>Disk</th>
                        <th>PHP</th>
                        <th>Status</th>
                        <th>WG</th>
                        <th class="text-end pe-4 no-sort">Actions</th>
                    </tr>
                </thead>
                <tbody id="accounts-tbody">
                    <tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fas fa-trash me-2"></i>Delete Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Permanently delete <strong id="delete-domain-name"></strong> and all its data?</p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="delete-backup" checked>
                    <label class="form-check-label" for="delete-backup">
                        Create a backup before deleting
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm-delete-btn">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Progress Modal -->
<div class="modal fade" id="deleteProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body p-4 text-center">
                <div id="delete-progress-icon">
                    <div class="spinner-border text-danger mb-3"></div>
                </div>
                <h6 class="mb-2">Deleting Domain</h6>
                <p class="small text-muted mb-3" id="delete-progress-stage">Preparing...</p>
                <div class="progress" style="height:6px">
                    <div class="progress-bar bg-danger progress-bar-striped progress-bar-animated" id="delete-progress-bar" style="width:5%"></div>
                </div>
                <div class="small text-muted mt-2" id="delete-progress-pct">5%</div>
            </div>
        </div>
    </div>
</div>

<!-- SSH Keys Modal -->
<div class="modal fade" id="sshKeysModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-key me-2"></i>SSH Keys — <span id="ssh-modal-domain"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="ssh-modal-alert" class="d-none mb-3"></div>

                <!-- Key list table -->
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Fingerprint</th>
                                <th>Comment / Label</th>
                                <th>Type</th>
                                <th class="text-end pe-3">Delete</th>
                            </tr>
                        </thead>
                        <tbody id="ssh-keys-tbody">
                            <tr><td colspan="4" class="text-muted text-center py-3 small">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Import Key section (collapsible) -->
                <div class="mb-3">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#import-key-collapse">
                        <i class="fas fa-paste me-1"></i>Import Key
                    </button>
                    <button class="btn btn-sm btn-outline-primary ms-2" type="button" id="generate-key-btn">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="generate-key-spinner"></span>
                        <i class="fas fa-bolt me-1"></i>Generate New Key
                    </button>
                </div>
                <div class="collapse" id="import-key-collapse">
                    <div class="card card-body border bg-light mb-2">
                        <div class="mb-2">
                            <label class="form-label fw-semibold small">Public Key</label>
                            <textarea class="form-control form-control-sm font-monospace" id="import-key-text" rows="3"
                                      placeholder="ssh-ed25519 AAAA... or ssh-rsa AAAA..."></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold small">Label (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="import-key-label" placeholder="my-laptop">
                        </div>
                        <button class="btn btn-primary btn-sm" id="import-key-submit-btn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="import-key-spinner"></span>
                            Add Key
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- One-time Private Key Modal -->
<div class="modal fade" id="privateKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-warning"><i class="fas fa-exclamation-triangle me-2"></i>Download Your Private Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>This private key will not be shown again.</strong> Download or copy it now before closing this window.
                </div>
                <label class="form-label fw-semibold small">Private Key</label>
                <pre id="private-key-display" class="bg-dark text-light p-3 rounded small font-monospace"
                     style="white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto;user-select:all"></pre>
                <div class="text-muted small mt-1">Fingerprint: <span id="private-key-fingerprint" class="font-monospace"></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="download-private-key-btn">
                    <i class="fas fa-download me-1"></i>Download .pem
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i>Change Password — <span id="pw-modal-username"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="pw-modal-alert" class="d-none mb-3"></div>
                <p class="text-muted small mb-3">Updates the password for FTP, SSH, phpMyAdmin, and the user portal.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="pw-new-password" minlength="8" placeholder="Minimum 8 characters">
                        <button class="btn btn-outline-secondary" type="button" id="pw-toggle-btn" title="Show/hide"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-outline-primary" type="button" id="pw-generate-btn" title="Generate"><i class="fas fa-random"></i></button>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold small">Confirm Password</label>
                    <input type="password" class="form-control" id="pw-confirm-password" placeholder="Re-enter password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="pw-save-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="pw-spinner"></span>
                    Save Password
                </button>
            </div>
        </div>
    </div>
</div>

<!-- WG Config Modal -->
<div class="modal fade" id="wgModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-shield-alt me-2 text-primary"></i>WireGuard Config</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted small mb-2" id="wg-peer-name"></p>
                <div id="wg-qr-container" class="mb-3"></div>
                <pre id="wg-config-text" class="text-start bg-light rounded p-3 small" style="white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="wg-download-btn">
                    <i class="fas fa-download me-1"></i>Download .conf
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
<script>
let pendingDeleteDomain = null;
let wgConfigContent    = null;
let wgConfigName       = null;

function showToast(msg, type = 'success') {
    const id = 'toast-' + Date.now();
    const html = `<div id="${id}" class="toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3" role="alert" style="z-index:9999">
        <div class="d-flex"><div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

function loadAccounts() {
    fetch('/api/accounts?action=list')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('accounts-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No accounts found. <a href="/admin/add-account">Create one</a>.</td></tr>';
                return;
            }
            // Track which usernames have already shown their total so we render it
            // just once per user (on that user's first row in the current ordering).
            const usersShownTotal = new Set();
            tbody.innerHTML = data.data.map(a => {
                const suspended = a.status === 'suspended';
                const rowClass  = suspended ? 'table-secondary text-muted' : '';
                const badge     = suspended
                    ? '<span class="badge bg-warning text-dark">Suspended</span>'
                    : '<span class="badge bg-success">Active</span>';
                const username = a.username || a.domain_name;
                const showTotal = !usersShownTotal.has(username);
                if (showTotal) usersShownTotal.add(username);
                const userCell = `
                    <span class="badge bg-light text-dark border">${username}</span>
                    ${showTotal && a.user_total ? `<div class="small text-muted mt-1" title="Files across all domains + MariaDB total">Total: ${a.user_total}</div>` : ''}`;
                const wgBadge = a.wg_ip
                    ? `<span class="badge bg-primary-subtle text-primary" style="cursor:pointer" onclick="showWgConfig('${username}')" title="${a.wg_ip}"><i class="fas fa-shield-alt"></i></span>`
                    : '<span class="text-muted">—</span>';
                const suspendBtn = suspended
                    ? `<button class="btn btn-sm btn-outline-success me-1" onclick="suspendAccount('${a.domain_name}','resume')" title="Reactivate"><i class="fas fa-play"></i></button>`
                    : `<button class="btn btn-sm btn-outline-warning me-1" onclick="suspendAccount('${a.domain_name}','suspend')" title="Suspend"><i class="fas fa-pause"></i></button>`;
                const deleteBtn  = `<button class="btn btn-sm btn-outline-danger" onclick="confirmDelete('${a.domain_name}')" title="Delete"><i class="fas fa-trash"></i></button>`;
                const sshKeysBtn = `<button class="btn btn-sm btn-outline-secondary me-1" onclick="openSshKeys('${username}')" title="SSH Keys"><i class="fas fa-key"></i></button>`;
                const addDomainBtn = `<a class="btn btn-sm btn-outline-primary me-1" href="/admin/add-domain?user=${encodeURIComponent(username)}" title="Add domain to ${username}"><i class="fas fa-plus-circle"></i></a>`;
                const loginAsBtn = `<button class="btn btn-sm btn-outline-info me-1" onclick="loginAsUser('${username}')" title="Login as ${username}"><i class="fas fa-sign-in-alt"></i></button>`;
                const pwBtn = `<button class="btn btn-sm btn-outline-dark me-1" onclick="openChangePassword('${username}')" title="Change password"><i class="fas fa-lock"></i></button>`;
                return `<tr class="${rowClass}">
                    <td class="ps-4">${userCell}</td>
                    <td class="fw-semibold"><a href="https://${a.domain_name}" target="_blank" class="text-decoration-none">${a.domain_name} <i class="fas fa-external-link-alt ms-1" style="font-size:.7em;opacity:.5"></i></a></td>
                    <td>${a.port ?? '—'}</td>
                    <td>${a.disk ?? '—'}</td>
                    <td>${a.php_version ?? '8.4'}</td>
                    <td>${badge}</td>
                    <td>${wgBadge}</td>
                    <td class="text-end pe-4">${loginAsBtn}${addDomainBtn}${sshKeysBtn}${pwBtn}${suspendBtn}${deleteBtn}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('accounts-tbody').innerHTML =
                '<tr><td colspan="8" class="text-center text-danger py-4">Failed to load accounts.</td></tr>';
        });
}

function confirmDelete(domain) {
    pendingDeleteDomain = domain;
    document.getElementById('delete-domain-name').textContent = domain;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

let deleteProgressModal = null;
function getDeleteProgressModal() {
    if (!deleteProgressModal) deleteProgressModal = new bootstrap.Modal(document.getElementById('deleteProgressModal'));
    return deleteProgressModal;
}

function setDeleteProgress(pct, stage) {
    document.getElementById('delete-progress-bar').style.width = pct + '%';
    document.getElementById('delete-progress-pct').textContent = pct + '%';
    if (stage) document.getElementById('delete-progress-stage').textContent = stage;
}

document.getElementById('confirm-delete-btn').addEventListener('click', function () {
    if (!pendingDeleteDomain) return;
    const noBackup = !document.getElementById('delete-backup').checked;

    // Close confirmation modal, show progress modal
    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
    setTimeout(() => {
        setDeleteProgress(5, 'Preparing...');
        document.getElementById('delete-progress-icon').innerHTML = '<div class="spinner-border text-danger mb-3"></div>';
        getDeleteProgressModal().show();

        // Animate progress stages
        const stages = noBackup ? [
            { pct: 15,  text: 'Disabling Apache vhost...',     delay: 500 },
            { pct: 35,  text: 'Removing PHP-FPM pool...',      delay: 2000 },
            { pct: 55,  text: 'Revoking SSL certificate...',   delay: 4000 },
            { pct: 70,  text: 'Dropping database...',          delay: 7000 },
            { pct: 85,  text: 'Cleaning up files...',          delay: 9000 },
            { pct: 92,  text: 'Removing Cloudflare routes...', delay: 11000 },
        ] : [
            { pct: 10,  text: 'Creating backup...',            delay: 500 },
            { pct: 25,  text: 'Disabling Apache vhost...',     delay: 5000 },
            { pct: 40,  text: 'Removing PHP-FPM pool...',      delay: 8000 },
            { pct: 55,  text: 'Revoking SSL certificate...',   delay: 10000 },
            { pct: 70,  text: 'Dropping database...',          delay: 15000 },
            { pct: 85,  text: 'Cleaning up files...',          delay: 18000 },
            { pct: 92,  text: 'Removing Cloudflare routes...', delay: 20000 },
        ];
        const timers = stages.map(s => setTimeout(() => setDeleteProgress(s.pct, s.text), s.delay));

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('domain', pendingDeleteDomain);
        if (noBackup) fd.append('no_backup', '1');
        fetch('/api/accounts', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                timers.forEach(clearTimeout);
                if (data.success) {
                    setDeleteProgress(100, 'Done!');
                    document.getElementById('delete-progress-icon').innerHTML =
                        '<i class="fas fa-check-circle text-success mb-3" style="font-size:2.5rem"></i>';
                    setTimeout(() => {
                        getDeleteProgressModal().hide();
                        showToast(`Account '${pendingDeleteDomain}' deleted.`);
                        loadAccounts();
                    }, 1200);
                } else {
                    setDeleteProgress(0, data.error || 'Delete failed.');
                    document.getElementById('delete-progress-icon').innerHTML =
                        '<i class="fas fa-times-circle text-danger mb-3" style="font-size:2.5rem"></i>';
                    setTimeout(() => {
                        getDeleteProgressModal().hide();
                        showToast(data.error || 'Delete failed.', 'danger');
                    }, 2500);
                }
            })
            .catch(() => {
                timers.forEach(clearTimeout);
                setDeleteProgress(0, 'Connection error.');
                document.getElementById('delete-progress-icon').innerHTML =
                    '<i class="fas fa-times-circle text-danger mb-3" style="font-size:2.5rem"></i>';
                setTimeout(() => getDeleteProgressModal().hide(), 2500);
            });
    }, 300);
});

function suspendAccount(domain, action) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('domain', domain);
    fetch('/api/accounts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(`Account '${domain}' ${action === 'suspend' ? 'suspended' : 'reactivated'}.`);
                loadAccounts();
            } else {
                showToast(data.error || 'Action failed.', 'danger');
            }
        });
}

function showWgConfig(domain) {
    wgConfigName = domain;
    wgConfigContent = null;
    document.getElementById('wg-peer-name').textContent = domain;
    document.getElementById('wg-config-text').textContent = 'Loading…';
    document.getElementById('wg-qr-container').innerHTML = '';
    new bootstrap.Modal(document.getElementById('wgModal')).show();

    fetch(`/api/wireguard.php?action=get_peer_config&name=${encodeURIComponent(domain)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { document.getElementById('wg-config-text').textContent = data.error; return; }
            wgConfigContent = data.config;
            document.getElementById('wg-config-text').textContent = data.config;
            document.getElementById('wg-qr-container').innerHTML = '';
            const qrIsDark = document.documentElement.getAttribute('data-theme') === 'dark';
            new QRCode(document.getElementById('wg-qr-container'), {
                text: data.config, width: 220, height: 220,
                colorDark: qrIsDark ? '#e2e8f0' : '#000000', colorLight: qrIsDark ? '#1e293b' : '#ffffff', correctLevel: QRCode.CorrectLevel.L
            });
        });
}

document.getElementById('wg-download-btn').addEventListener('click', function () {
    if (!wgConfigContent || !wgConfigName) return;
    const blob = new Blob([wgConfigContent], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = wgConfigName + '.conf';
    a.click();
});

function loginAsUser(username) {
    fetch(`/api/accounts?action=auto_login_token&username=${encodeURIComponent(username)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.token) {
                window.open(`/user/auto-login?token=${encodeURIComponent(data.token)}`, '_blank');
            } else {
                showToast(data.error || 'Failed to generate login token.', 'danger');
            }
        })
        .catch(() => showToast('Failed to generate login token.', 'danger'));
}

document.addEventListener('DOMContentLoaded', function () {
    loadAccounts();
    TableKit.init('accounts-table', { filter: true });
});

// ── Change Password ─────────────────────────────────────────────────────────
let pwCurrentUser = null;

function openChangePassword(username) {
    pwCurrentUser = username;
    document.getElementById('pw-modal-username').textContent = username;
    document.getElementById('pw-new-password').value = '';
    document.getElementById('pw-confirm-password').value = '';
    document.getElementById('pw-new-password').type = 'password';
    document.getElementById('pw-modal-alert').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}

function showPwAlert(msg, type = 'success') {
    const el = document.getElementById('pw-modal-alert');
    el.className = `alert alert-${type} py-2 small`;
    el.textContent = msg;
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 5000);
}

document.getElementById('pw-toggle-btn').addEventListener('click', function () {
    const inp = document.getElementById('pw-new-password');
    const conf = document.getElementById('pw-confirm-password');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    conf.type = show ? 'text' : 'password';
    this.innerHTML = show ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
});

document.getElementById('pw-generate-btn').addEventListener('click', function () {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    let pw = '';
    const arr = new Uint32Array(16);
    crypto.getRandomValues(arr);
    for (let i = 0; i < 16; i++) pw += chars[arr[i] % chars.length];
    document.getElementById('pw-new-password').value = pw;
    document.getElementById('pw-confirm-password').value = pw;
    document.getElementById('pw-new-password').type = 'text';
    document.getElementById('pw-confirm-password').type = 'text';
    document.getElementById('pw-toggle-btn').innerHTML = '<i class="fas fa-eye-slash"></i>';
});

document.getElementById('pw-save-btn').addEventListener('click', function () {
    const pw = document.getElementById('pw-new-password').value;
    const confirm = document.getElementById('pw-confirm-password').value;

    if (!pw) { showPwAlert('Enter a password.', 'warning'); return; }
    if (pw.length < 8) { showPwAlert('Password must be at least 8 characters.', 'warning'); return; }
    if (pw !== confirm) { showPwAlert('Passwords do not match.', 'warning'); return; }

    const spinner = document.getElementById('pw-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;

    const fd = new FormData();
    fd.append('action', 'change_password');
    fd.append('username', pwCurrentUser);
    fd.append('password', pw);
    fetch('/api/accounts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('changePasswordModal')).hide();
                showToast(`Password changed for '${pwCurrentUser}'.`);
            } else {
                showPwAlert(data.error || 'Failed to change password.', 'danger');
            }
        })
        .catch(() => {
            spinner.classList.add('d-none');
            this.disabled = false;
            showPwAlert('Connection error.', 'danger');
        });
});

// ── SSH Key Manager ──────────────────────────────────────────────────────────
let sshCurrentDomain = null;
let privateKeyData   = null;

function showSshAlert(msg, type = 'success') {
    const el = document.getElementById('ssh-modal-alert');
    el.className = `alert alert-${type} py-2 small`;
    el.textContent = msg;
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 5000);
}

function openSshKeys(domain) {
    sshCurrentDomain = domain;
    document.getElementById('ssh-modal-domain').textContent = domain === 'root' ? 'root (server)' : domain;
    document.getElementById('ssh-modal-alert').classList.add('d-none');
    document.getElementById('import-key-text').value  = '';
    document.getElementById('import-key-label').value = '';
    // Close import collapse if open
    const collapse = bootstrap.Collapse.getInstance(document.getElementById('import-key-collapse'));
    if (collapse) collapse.hide();
    loadSshKeys();
    new bootstrap.Modal(document.getElementById('sshKeysModal')).show();
}

function loadSshKeys() {
    document.getElementById('ssh-keys-tbody').innerHTML =
        '<tr><td colspan="4" class="text-muted text-center py-3 small">Loading…</td></tr>';
    fetch(`/api/ssh-keys?action=list&domain=${encodeURIComponent(sshCurrentDomain)}`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('ssh-keys-tbody');
            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-danger text-center py-3 small">${data.error || 'Error loading keys.'}</td></tr>`;
                return;
            }
            if (!data.data || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3 small">No authorized SSH keys.</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(k => `<tr>
                <td class="ps-3 font-monospace small text-muted" style="font-size:0.75rem">${k.fingerprint}</td>
                <td class="small">${k.comment || '<span class="text-muted">—</span>'}</td>
                <td><span class="badge bg-secondary small">${k.type}</span></td>
                <td class="text-end pe-3">
                    <button class="btn btn-sm btn-outline-danger py-0 px-1"
                            onclick='deleteSshKey(${JSON.stringify(k.fingerprint)})' title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`).join('');
        });
}

function deleteSshKey(fingerprint) {
    if (!confirm('Delete this SSH key? The server will no longer accept logins using it.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('domain', sshCurrentDomain);
    fd.append('fingerprint', fingerprint);
    fetch('/api/ssh-keys', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showSshAlert('Key deleted.'); loadSshKeys(); }
            else showSshAlert(data.error || 'Delete failed.', 'danger');
        });
}

document.getElementById('import-key-submit-btn').addEventListener('click', function () {
    const key     = document.getElementById('import-key-text').value.trim();
    const comment = document.getElementById('import-key-label').value.trim();
    if (!key) { showSshAlert('Paste a public key first.', 'warning'); return; }
    const spinner = document.getElementById('import-key-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('domain', sshCurrentDomain);
    fd.append('key', key);
    if (comment) fd.append('comment', comment);
    fetch('/api/ssh-keys', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                document.getElementById('import-key-text').value  = '';
                document.getElementById('import-key-label').value = '';
                bootstrap.Collapse.getInstance(document.getElementById('import-key-collapse'))?.hide();
                showSshAlert('Key added successfully.');
                loadSshKeys();
            } else {
                showSshAlert(data.error || 'Failed to add key.', 'danger');
            }
        });
});

document.getElementById('generate-key-btn').addEventListener('click', function () {
    const spinner = document.getElementById('generate-key-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const label = prompt('Key label (optional):', `${sshCurrentDomain}-${new Date().toISOString().slice(0,10)}`) ?? '';
    const fd = new FormData();
    fd.append('action', 'generate');
    fd.append('domain', sshCurrentDomain);
    if (label) fd.append('comment', label);
    fetch('/api/ssh-keys', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                // Show one-time private key modal
                privateKeyData = { key: data.private_key, domain: sshCurrentDomain, fingerprint: data.fingerprint };
                document.getElementById('private-key-display').textContent   = data.private_key;
                document.getElementById('private-key-fingerprint').textContent = data.fingerprint;
                // Temporarily hide SSH keys modal to show private key modal on top
                new bootstrap.Modal(document.getElementById('privateKeyModal')).show();
                loadSshKeys();
            } else {
                showSshAlert(data.error || 'Key generation failed.', 'danger');
            }
        });
});

document.getElementById('download-private-key-btn').addEventListener('click', function () {
    if (!privateKeyData) return;
    const blob = new Blob([privateKeyData.key], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `inetpanel_${privateKeyData.domain}_ed25519.pem`;
    a.click();
    URL.revokeObjectURL(a.href);
});
</script>
