<?php
// FILE: src/account/portal.php
// iNetPanel — Account holder portal (multi-domain, full DNS/email management)

$accountUser = AccountAuth::user();
$username = $accountUser['username'] ?? AccountAuth::username();
$allDomains = $accountUser['domains'] ?? [];

// Domain selector: use query param or default to first domain
$domain = $_GET['domain'] ?? ($allDomains[0]['domain_name'] ?? $username);

// Load account record from DB
$row = DB::fetchOne(
    'SELECT d.*, h.username as hosting_username FROM domains d LEFT JOIN hosting_users h ON d.hosting_user_id = h.id WHERE d.domain_name = ?',
    [$domain]
);
if (!$row) {
    echo '<div class="alert alert-danger">Account not found. Please contact your administrator.</div>';
    return;
}

// Verify this domain belongs to the logged-in user
$owner = $row['hosting_username'] ?? $domain;
if ($owner !== $username && $domain !== $username) {
    echo '<div class="alert alert-danger">Access denied.</div>';
    return;
}

// Derived values
$dbName  = $username . '_' . str_replace(['.', '-'], '_', $domain);
$docRoot = $row['document_root'] ?? "/home/{$username}/{$domain}/www";
$phpVer  = $row['php_version']  ?? '—';
$port    = $row['port']         ?? '—';
$status  = $row['status']       ?? 'active';

// Disk usage (best-effort)
$disk = is_dir($docRoot)
    ? trim((string) shell_exec('du -sh ' . escapeshellarg($docRoot) . ' 2>/dev/null | cut -f1') ?: '—')
    : '—';

// CF enabled?
$cfEnabled = DB::setting('cf_enabled', '0') === '1';
$serverIp  = trim((string) shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}'"));
if (!$serverIp) {
    $serverIp = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print $1}'"));
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-user me-2 text-primary"></i><?= htmlspecialchars($username) ?></h4>
        <span class="text-muted small">Account Dashboard</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if (count($allDomains) > 1): ?>
        <select class="form-select form-select-sm" style="width:auto" onchange="window.location.href='/user/dashboard?domain='+encodeURIComponent(this.value)">
            <?php foreach ($allDomains as $d): ?>
            <option value="<?= htmlspecialchars($d['domain_name']) ?>" <?= $d['domain_name'] === $domain ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['domain_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <span class="badge bg-<?= $status === 'active' ? 'success' : 'warning text-dark' ?> rounded-pill fs-6 px-3 py-2">
            <i class="fas fa-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i><?= ucfirst($status) ?>
        </span>
    </div>
</div>

<!-- Account Info -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>Account Information</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="ps-4 text-muted fw-semibold" style="width:40%">Domain</td>
                            <td class="pe-4 fw-bold"><?= htmlspecialchars($domain) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">PHP Version</td>
                            <td class="pe-4"><?= htmlspecialchars($phpVer) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">Web Root</td>
                            <td class="pe-4"><code style="font-size:0.8rem"><?= htmlspecialchars($docRoot) ?></code></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">Disk Usage</td>
                            <td class="pe-4"><?= htmlspecialchars($disk) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">Server Port</td>
                            <td class="pe-4"><?= htmlspecialchars((string)$port) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="fas fa-database me-2 text-primary"></i>Database Information</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="ps-4 text-muted fw-semibold" style="width:40%">Database Name</td>
                            <td class="pe-4 fw-bold"><?= htmlspecialchars($dbName) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">DB Username</td>
                            <td class="pe-4"><?= htmlspecialchars($username) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">DB Host</td>
                            <td class="pe-4">localhost</td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">DB Port</td>
                            <td class="pe-4">3306</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top py-2 px-4">
                <a href="/user/phpmyadmin" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                    <i class="fas fa-database me-2"></i>Open phpMyAdmin
                </a>
            </div>
        </div>
    </div>
</div>

<!-- FTP / SSH Access -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0"><i class="fas fa-terminal me-2 text-primary"></i>FTP / SSH Access</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">Hostname</div>
                <code><?= htmlspecialchars($serverIp) ?></code>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">FTP Port</div>
                <code>21</code>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">SSH Port</div>
                <code><?= htmlspecialchars(DB::setting('ssh_port', '1022')) ?></code>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">Username</div>
                <code><?= htmlspecialchars($username) ?></code>
            </div>
        </div>
    </div>
</div>

<?php if ($cfEnabled): ?>
<?php $isSubdomain = substr_count($domain, '.') > 1; ?>

<!-- DNS Records -->
<div class="card border-0 shadow-sm mb-4<?= $isSubdomain ? ' opacity-50' : '' ?>">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-network-wired me-2 text-primary"></i>DNS Records</h6>
        <div class="d-flex gap-2 align-items-center">
            <?php if (!$isSubdomain): ?>
            <button class="btn btn-sm btn-primary" id="add-dns-btn"><i class="fas fa-plus me-1"></i>Add Record</button>
            <?php endif; ?>
            <span class="badge bg-warning text-dark"><i class="fab fa-cloudflare me-1"></i>Cloudflare</span>
        </div>
    </div>
    <div class="card-body<?= $isSubdomain ? '' : ' p-0' ?>">
        <?php if ($isSubdomain): ?>
        <div class="text-center text-muted py-3">
            <i class="fas fa-info-circle me-1"></i> DNS management is not available for sub-domains.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Type</th>
                        <th>Name</th>
                        <th>Content</th>
                        <th>TTL</th>
                        <th>Proxied</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody id="dns-tbody">
                    <tr><td colspan="6" class="text-center text-muted py-3 small">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Email Routing -->
<div class="card border-0 shadow-sm mb-4<?= $isSubdomain ? ' opacity-50' : '' ?>">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-envelope me-2 text-primary"></i>Email Routing</h6>
        <div class="d-flex gap-2 align-items-center">
            <?php if (!$isSubdomain): ?>
            <button class="btn btn-sm btn-primary" id="add-email-btn"><i class="fas fa-plus me-1"></i>Add Rule</button>
            <?php endif; ?>
            <span class="badge bg-warning text-dark"><i class="fab fa-cloudflare me-1"></i>Cloudflare</span>
        </div>
    </div>
    <div class="card-body<?= $isSubdomain ? '' : ' p-0' ?>">
        <?php if ($isSubdomain): ?>
        <div class="text-center text-muted py-3">
            <i class="fas fa-info-circle me-1"></i> Email routing is not available for sub-domains.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">From</th>
                        <th>Forward To</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody id="email-tbody">
                    <tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isSubdomain): ?>

<!-- DNS Add/Edit Modal -->
<div class="modal fade" id="dnsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dns-modal-title">Add DNS Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dns-record-id">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Type</label>
                        <select class="form-select" id="dns-type">
                            <option>A</option><option>AAAA</option><option>CNAME</option>
                            <option>MX</option><option>TXT</option><option>NS</option>
                            <option>SRV</option><option>CAA</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small">Name</label>
                        <input type="text" class="form-control" id="dns-name" placeholder="@ or subdomain">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Content / Value</label>
                        <input type="text" class="form-control" id="dns-content" placeholder="IP address or target">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">TTL</label>
                        <input type="number" class="form-control" id="dns-ttl" value="1" min="1">
                        <div class="form-text">1 = Auto</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Priority (MX/SRV)</label>
                        <input type="number" class="form-control" id="dns-priority" value="10" min="0">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="dns-proxied" checked style="width:2em;height:1em">
                            <label class="form-check-label fw-semibold small" for="dns-proxied">CF Proxied</label>
                        </div>
                    </div>
                </div>
                <div id="dns-modal-error" class="d-none mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="dns-save-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="dns-save-spinner"></span>
                    Save Record
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Email Add Rule Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Add Forwarding Rule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">From (source address)</label>
                    <input type="text" class="form-control" id="email-from" placeholder="support@<?= htmlspecialchars($domain) ?>">
                    <div class="form-text">Use <code>*@<?= htmlspecialchars($domain) ?></code> to catch all.</div>
                    <div class="invalid-feedback" id="email-from-hint">Enter a valid email address (e.g. info@<?= htmlspecialchars($domain) ?>)</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Forward To</label>
                    <input type="email" class="form-control" id="email-to" placeholder="you@gmail.com">
                    <div class="form-text">Must be a verified destination address.</div>
                    <div class="invalid-feedback" id="email-to-hint">Enter a valid email address</div>
                </div>
                <div id="email-modal-error" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="email-save-btn" disabled>
                    <span class="spinner-border spinner-border-sm d-none me-1" id="email-save-spinner"></span>
                    Add Rule
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const DOMAIN = <?= json_encode($domain) ?>;

function showToast(msg, type = 'success') {
    const id = 'toast-' + Date.now();
    document.body.insertAdjacentHTML('beforeend',
        `<div id="${id}" class="toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3" role="alert" style="z-index:9999">
            <div class="d-flex"><div class="toast-body">${msg}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ── DNS ──────────────────────────────────────────────────────────────────────

function loadDns() {
    document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3 small">Loading...</td></tr>';
    fetch('/api/account?action=dns&domain=' + encodeURIComponent(DOMAIN))
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('dns-tbody');
            if (!data.success || !data.records || !data.records.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3 small">' + (data.error || 'No DNS records found.') + '</td></tr>';
                return;
            }
            tbody.innerHTML = data.records.map(r => {
                const proxied = r.proxied ? '<span class="badge bg-warning text-dark"><i class="fas fa-cloud"></i></span>' : '<span class="text-muted">--</span>';
                return `<tr>
                    <td class="ps-4"><span class="badge bg-primary-subtle text-primary">${r.type}</span></td>
                    <td class="small fw-medium">${r.name}</td>
                    <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.content}</td>
                    <td class="small">${r.ttl === 1 ? 'Auto' : r.ttl}</td>
                    <td>${proxied}</td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-primary me-1 py-0 px-1" onclick='editDns(${JSON.stringify(r).replace(/'/g,"&#39;")})' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="deleteDns('${r.id}')" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" class="text-muted text-center py-3 small">Failed to load DNS records.</td></tr>';
        });
}

document.getElementById('add-dns-btn').addEventListener('click', function () {
    document.getElementById('dns-modal-title').textContent = 'Add DNS Record';
    document.getElementById('dns-record-id').value = '';
    document.getElementById('dns-name').value    = '';
    document.getElementById('dns-content').value = '';
    document.getElementById('dns-ttl').value     = '1';
    document.getElementById('dns-priority').value = '10';
    document.getElementById('dns-proxied').checked = true;
    document.getElementById('dns-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('dnsModal')).show();
});

function editDns(r) {
    document.getElementById('dns-modal-title').textContent = 'Edit DNS Record';
    document.getElementById('dns-record-id').value = r.id;
    document.getElementById('dns-type').value      = r.type;
    document.getElementById('dns-name').value      = r.name;
    document.getElementById('dns-content').value   = r.content;
    document.getElementById('dns-ttl').value       = r.ttl;
    document.getElementById('dns-priority').value  = r.priority ?? 10;
    document.getElementById('dns-proxied').checked = !!r.proxied;
    document.getElementById('dns-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('dnsModal')).show();
}

document.getElementById('dns-save-btn').addEventListener('click', function () {
    const spinner  = document.getElementById('dns-save-spinner');
    const errEl    = document.getElementById('dns-modal-error');
    const recordId = document.getElementById('dns-record-id').value;
    spinner.classList.remove('d-none');
    this.disabled = true;
    errEl.className = 'd-none';
    const fd = new FormData();
    fd.append('action', recordId ? 'dns_update' : 'dns_create');
    fd.append('domain', DOMAIN);
    if (recordId) fd.append('record_id', recordId);
    fd.append('type',    document.getElementById('dns-type').value);
    fd.append('name',    document.getElementById('dns-name').value);
    fd.append('content', document.getElementById('dns-content').value);
    fd.append('ttl',     document.getElementById('dns-ttl').value);
    fd.append('proxied', document.getElementById('dns-proxied').checked ? '1' : '0');
    fetch('/api/account', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('dnsModal')).hide();
                showToast('DNS record saved.');
                loadDns();
            } else {
                errEl.className = 'alert alert-danger mt-3 small py-2';
                errEl.textContent = data.error || 'Save failed.';
            }
        });
});

function deleteDns(recordId) {
    if (!confirm('Delete this DNS record?')) return;
    const fd = new FormData();
    fd.append('action', 'dns_delete');
    fd.append('domain', DOMAIN);
    fd.append('record_id', recordId);
    fetch('/api/account', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showToast('DNS record deleted.'); loadDns(); }
            else showToast(data.error || 'Delete failed.', 'danger');
        });
}

// ── Email Routing ────────────────────────────────────────────────────────────

function loadEmail() {
    document.getElementById('email-tbody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>';
    fetch('/api/account?action=email&domain=' + encodeURIComponent(DOMAIN))
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('email-tbody');
            if (!data.success || !data.rules || !data.rules.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">' + (data.error || 'No email routing rules.') + '</td></tr>';
                return;
            }
            tbody.innerHTML = data.rules.map(rule => {
                const isCatchAll = rule.matchers?.[0]?.type === 'all';
                const actionType = rule.actions?.[0]?.type ?? '';
                let from, to;
                if (isCatchAll) {
                    from = '<span class="text-muted fst-italic">Catch-all (*@domain)</span>';
                    if (actionType === 'drop') to = '<span class="text-muted">Drop (discard)</span>';
                    else if (actionType === 'forward') to = (rule.actions[0].value ?? []).join(', ') || '--';
                    else to = actionType || '--';
                } else {
                    from = rule.matchers?.[0]?.value ?? '--';
                    const rawTo = rule.actions?.[0]?.value ?? '--';
                    to = Array.isArray(rawTo) ? rawTo.join(', ') : rawTo;
                }
                const enabled = rule.enabled !== false;
                const badge = enabled ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>';
                const deleteBtn = isCatchAll ? '' : `<button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="deleteEmailRule('${rule.id}')" title="Delete"><i class="fas fa-trash"></i></button>`;
                return `<tr>
                    <td class="ps-4 small fw-medium">${from}</td>
                    <td class="small">${to}</td>
                    <td>${badge}</td>
                    <td class="text-end pe-4">${deleteBtn}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('email-tbody').innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3 small">Failed to load email rules.</td></tr>';
        });
}

// Email form validation
const emailFromEl = document.getElementById('email-from');
const emailToEl   = document.getElementById('email-to');
const emailSaveEl = document.getElementById('email-save-btn');
const emailFromRe = /^(\*|[a-zA-Z0-9._%+-]+)@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
const emailToRe   = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

function validateEmailForm() {
    const fromVal = emailFromEl.value.trim();
    const toVal   = emailToEl.value.trim();
    const fromOk  = fromVal === '' || emailFromRe.test(fromVal);
    const toOk    = toVal === '' || emailToRe.test(toVal);

    emailFromEl.classList.toggle('is-invalid', fromVal !== '' && !fromOk);
    emailFromEl.classList.toggle('is-valid', fromVal !== '' && fromOk);
    emailToEl.classList.toggle('is-invalid', toVal !== '' && !toOk);
    emailToEl.classList.toggle('is-valid', toVal !== '' && toOk);

    emailSaveEl.disabled = !(fromVal && toVal && fromOk && toOk);
}
emailFromEl.addEventListener('input', validateEmailForm);
emailToEl.addEventListener('input', validateEmailForm);

document.getElementById('add-email-btn').addEventListener('click', function () {
    emailFromEl.value = '';
    emailToEl.value   = '';
    emailFromEl.classList.remove('is-valid', 'is-invalid');
    emailToEl.classList.remove('is-valid', 'is-invalid');
    emailSaveEl.disabled = true;
    document.getElementById('email-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('emailModal')).show();
});

document.getElementById('email-save-btn').addEventListener('click', function () {
    const from = document.getElementById('email-from').value.trim();
    const to   = document.getElementById('email-to').value.trim();
    const errEl = document.getElementById('email-modal-error');
    if (!from || !to) { errEl.className = 'alert alert-danger small py-2'; errEl.textContent = 'Both fields required.'; return; }
    const spinner = document.getElementById('email-save-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'email_create_rule');
    fd.append('domain', DOMAIN);
    fd.append('from', from);
    fd.append('to', to);
    fetch('/api/account', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
                showToast('Email rule added.');
                loadEmail();
            } else {
                errEl.className = 'alert alert-danger small py-2 mt-2';
                errEl.textContent = data.error || 'Failed to add rule.';
            }
        });
});

function deleteEmailRule(ruleId) {
    if (!confirm('Delete this email forwarding rule?')) return;
    const fd = new FormData();
    fd.append('action', 'email_delete_rule');
    fd.append('domain', DOMAIN);
    fd.append('rule_id', ruleId);
    fetch('/api/account', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showToast('Rule deleted.'); loadEmail(); }
            else showToast(data.error || 'Delete failed.', 'danger');
        });
}

// ── Init ─────────────────────────────────────────────────────────────────────
loadDns();
loadEmail();
</script>
<?php endif; ?>
<?php endif; ?>
