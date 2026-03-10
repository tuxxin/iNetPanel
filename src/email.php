<?php
// FILE: src/email.php
// iNetPanel — Cloudflare Email Routing

Auth::requireAdmin();

if (DB::setting('cf_enabled', '0') !== '1') {
    echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Cloudflare integration is not enabled. Enable it in <a href="/admin/settings">Settings → Cloudflare</a>.</div>';
    return;
}
?>

<h4 class="mb-4"><i class="fas fa-envelope me-2"></i>Email Routing</h4>

<div id="email-alert" class="d-none mb-3"></div>

<!-- Zone selector -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3 d-flex gap-3 align-items-end flex-wrap">
        <div>
            <label class="form-label fw-semibold small mb-1">Zone / Domain</label>
            <select class="form-select form-select-sm" id="zone-sel" style="min-width:220px">
                <option value="">Loading zones…</option>
            </select>
        </div>
        <button class="btn btn-outline-primary btn-sm" id="load-rules-btn">
            <i class="fas fa-sync-alt me-1"></i>Load Rules
        </button>
        <button class="btn btn-primary btn-sm ms-auto" id="add-rule-btn" disabled>
            <i class="fas fa-plus me-1"></i>Add Rule
        </button>
    </div>
</div>

<!-- Rules table -->
<div class="card border-0 shadow-sm d-none mb-4" id="rules-card">
    <div class="card-header bg-white py-3"><h6 class="mb-0">Forwarding Rules</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="email-rules-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">From (address pattern)</th>
                        <th>Forward To</th>
                        <th>Status</th>
                        <th class="text-end pe-4 no-sort">Actions</th>
                    </tr>
                </thead>
                <tbody id="rules-tbody">
                    <tr><td colspan="4" class="text-center text-muted py-4">Select a zone to load rules.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Destination addresses -->
<div class="card border-0 shadow-sm d-none" id="addresses-card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Destination Addresses</h6>
        <button class="btn btn-sm btn-outline-primary" id="add-address-btn"><i class="fas fa-plus me-1"></i>Add Address</button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Email Address</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="addresses-tbody">
                    <tr><td colspan="2" class="text-muted text-center py-3 small">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Rule Modal -->
<div class="modal fade" id="addRuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Add Forwarding Rule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">From (source pattern)</label>
                    <input type="text" class="form-control" id="rule-from" placeholder="support@example.com or *@example.com">
                    <div class="form-text">Wildcards supported: <code>*@yourdomain.com</code> catches all.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Forward To (destination)</label>
                    <input type="email" class="form-control" id="rule-to" placeholder="you@gmail.com">
                    <div class="form-text">Must be a verified destination address.</div>
                </div>
                <div id="rule-modal-error" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-rule-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="rule-spinner"></span>
                    Add Rule
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Destination Address Modal -->
<div class="modal fade" id="addAddressModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Destination Address</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Email Address</label>
                <input type="email" class="form-control" id="new-address" placeholder="you@gmail.com">
                <div class="form-text">A verification email will be sent to this address.</div>
                <div id="addr-modal-error" class="d-none mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-address-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="addr-spinner"></span>
                    Add Address
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentZoneId = null;

function showAlert(msg, type = 'success') {
    const el = document.getElementById('email-alert');
    el.className = `alert alert-${type}`;
    el.innerHTML = msg;
    setTimeout(() => el.className = 'd-none', 5000);
}

// Load zones
fetch('/api/dns?action=zones')
    .then(r => r.json())
    .then(data => {
        const sel = document.getElementById('zone-sel');
        sel.innerHTML = '';
        if (!data.success || !data.data.length) {
            sel.innerHTML = '<option value="">No zones — check CF credentials</option>'; return;
        }
        data.data.forEach(z => {
            sel.innerHTML += `<option value="${z.id}">${z.name}</option>`;
        });
        // Auto-load first zone
        if (sel.value) {
            currentZoneId = sel.value;
            document.getElementById('rules-card').classList.remove('d-none');
            document.getElementById('addresses-card').classList.remove('d-none');
            document.getElementById('add-rule-btn').disabled = false;
            loadRules();
            loadAddresses();
        }
    });

// Reload on zone change
document.getElementById('zone-sel').addEventListener('change', function () {
    const zoneId = this.value;
    if (!zoneId) return;
    currentZoneId = zoneId;
    document.getElementById('rules-card').classList.remove('d-none');
    document.getElementById('addresses-card').classList.remove('d-none');
    document.getElementById('add-rule-btn').disabled = false;
    loadRules();
    loadAddresses();
});

document.getElementById('load-rules-btn').addEventListener('click', function () {
    const zoneId = document.getElementById('zone-sel').value;
    if (!zoneId) return;
    currentZoneId = zoneId;
    document.getElementById('rules-card').classList.remove('d-none');
    document.getElementById('addresses-card').classList.remove('d-none');
    document.getElementById('add-rule-btn').disabled = false;
    loadRules();
    loadAddresses();
});

function loadRules() {
    document.getElementById('rules-tbody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>';
    fetch(`/api/email?action=list_rules&zone_id=${encodeURIComponent(currentZoneId)}`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('rules-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No rules configured.</td></tr>'; return;
            }
            tbody.innerHTML = data.data.map(rule => {
                const isCatchAll = rule.matchers?.[0]?.type === 'all';
                const actionType = rule.actions?.[0]?.type ?? '';
                let from, to;
                if (isCatchAll) {
                    from = '<span class="text-muted fst-italic">Catch-all (*@domain)</span>';
                    if (actionType === 'drop') to = '<span class="text-muted">Drop (discard)</span>';
                    else if (actionType === 'forward') to = (rule.actions[0].value ?? []).join(', ') || '—';
                    else if (actionType === 'worker') to = '<span class="text-muted">Worker</span>';
                    else to = actionType || '—';
                } else {
                    from = rule.matchers?.[0]?.value ?? '—';
                    const rawTo = rule.actions?.[0]?.value ?? '—';
                    to = Array.isArray(rawTo) ? rawTo.join(', ') : rawTo;
                }
                const enabled = rule.enabled !== false;
                const badge = enabled ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Disabled</span>';
                const deleteBtn = isCatchAll ? '' : `<button class="btn btn-sm btn-outline-danger" onclick="deleteRule('${rule.id}')" title="Delete"><i class="fas fa-trash"></i></button>`;
                return `<tr>
                    <td class="ps-4 small fw-medium">${from}</td>
                    <td class="small">${to}</td>
                    <td>${badge}</td>
                    <td class="text-end pe-4">${deleteBtn}</td>
                </tr>`;
            }).join('');
            if (!document.getElementById('email-rules-table')._tkInit) {
                TableKit.init('email-rules-table', { filter: true });
                document.getElementById('email-rules-table')._tkInit = true;
            }
        });
}

function loadAddresses() {
    fetch(`/api/email?action=list_addresses&zone_id=${encodeURIComponent(currentZoneId)}`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('addresses-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="2" class="text-muted text-center py-3 small">No destination addresses.</td></tr>'; return;
            }
            tbody.innerHTML = data.data.map(a => {
                const verified = a.verified ? '<span class="badge bg-success small">Verified</span>' : '<span class="badge bg-warning text-dark small">Pending</span>';
                return `<tr><td class="ps-4 small">${a.email}</td><td>${verified}</td></tr>`;
            }).join('');
        });
}

// Add Rule
document.getElementById('add-rule-btn').addEventListener('click', function () {
    document.getElementById('rule-from').value = '';
    document.getElementById('rule-to').value   = '';
    document.getElementById('rule-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('addRuleModal')).show();
});

document.getElementById('save-rule-btn').addEventListener('click', function () {
    const from = document.getElementById('rule-from').value.trim();
    const to   = document.getElementById('rule-to').value.trim();
    const errEl  = document.getElementById('rule-modal-error');
    if (!from || !to) { errEl.className = 'alert alert-danger small py-2'; errEl.textContent = 'Both fields required.'; return; }
    const spinner = document.getElementById('rule-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'create_rule');
    fd.append('zone_id', currentZoneId);
    fd.append('from', from);
    fd.append('to', to);
    fetch('/api/email', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('addRuleModal')).hide();
                showAlert('Rule added.');
                loadRules();
            } else {
                errEl.className = 'alert alert-danger small py-2 mt-2';
                errEl.textContent = data.error || 'Failed.';
            }
        });
});

function deleteRule(ruleId) {
    if (!confirm('Delete this forwarding rule?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_rule');
    fd.append('zone_id', currentZoneId);
    fd.append('rule_id', ruleId);
    fetch('/api/email', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showAlert('Rule deleted.'); loadRules(); }
            else showAlert(data.error || 'Delete failed.', 'danger');
        });
}

// Add destination address
document.getElementById('add-address-btn').addEventListener('click', function () {
    document.getElementById('new-address').value = '';
    document.getElementById('addr-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('addAddressModal')).show();
});

document.getElementById('save-address-btn').addEventListener('click', function () {
    const email  = document.getElementById('new-address').value.trim();
    const errEl  = document.getElementById('addr-modal-error');
    if (!email) return;
    const spinner = document.getElementById('addr-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'create_address');
    fd.append('zone_id', currentZoneId);
    fd.append('email', email);
    fetch('/api/email', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('addAddressModal')).hide();
                showAlert('Address added. Check your inbox for verification.');
                loadAddresses();
            } else {
                errEl.className = 'alert alert-danger small py-2';
                errEl.textContent = data.error || 'Failed.';
            }
        });
});
</script>
