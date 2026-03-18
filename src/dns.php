<?php
// FILE: src/dns.php
// iNetPanel — Cloudflare DNS Manager

Auth::requireAdmin();

if (DB::setting('cf_enabled', '0') !== '1') {
    echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Cloudflare integration is not enabled. Enable it in <a href="/admin/settings">Settings → Cloudflare</a>.</div>';
    return;
}
?>

<h4 class="mb-4"><i class="fas fa-globe me-2"></i>DNS Management</h4>

<div id="dns-alert" class="d-none mb-3"></div>

<!-- Zone selector -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3 d-flex gap-3 align-items-end flex-wrap">
        <div>
            <label class="form-label fw-semibold small mb-1">Zone / Domain</label>
            <select class="form-select form-select-sm" id="zone-sel" style="min-width:220px">
                <option value="">Loading zones…</option>
            </select>
        </div>
        <button class="btn btn-outline-primary btn-sm" id="load-dns-btn">
            <i class="fas fa-sync-alt me-1"></i>Load Records
        </button>
        <button class="btn btn-primary btn-sm ms-auto" id="add-record-btn" disabled>
            <i class="fas fa-plus me-1"></i>Add Record
        </button>
    </div>
</div>

<!-- Zone mode toggles (shown after loading a zone) -->
<div class="card border-0 shadow-sm mb-4 d-none" id="zone-modes-card">
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-md-6 d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="ddos-toggle"
                           style="width:2.5em;height:1.3em;cursor:pointer" disabled>
                </div>
                <div>
                    <span class="fw-semibold small"><i class="fas fa-shield-alt text-danger me-1"></i>DDoS Mode</span>
                    <div class="text-muted" style="font-size:.75rem">Enables Cloudflare's "I'm Under Attack" mode. Visitors see a JS challenge for ~5 seconds before accessing your site.</div>
                </div>
            </div>
            <div class="col-md-6 d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="devmode-toggle"
                           style="width:2.5em;height:1.3em;cursor:pointer" disabled>
                </div>
                <div>
                    <span class="fw-semibold small"><i class="fas fa-code text-info me-1"></i>Development Mode</span>
                    <div class="text-muted" style="font-size:.75rem">Bypasses Cloudflare's cache for 3 hours. Use when making changes to cached content so updates appear immediately.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DNS records table -->
<div class="card border-0 shadow-sm d-none" id="dns-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="dns-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Type</th>
                        <th>Name</th>
                        <th>Content</th>
                        <th>TTL</th>
                        <th>Proxied</th>
                        <th class="text-end pe-4 no-sort">Actions</th>
                    </tr>
                </thead>
                <tbody id="dns-tbody">
                    <tr><td colspan="6" class="text-center text-muted py-4">Select a zone to load records.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit Record Modal -->
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
                        <label class="form-label fw-semibold small">TTL (seconds)</label>
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

<script>
let currentZoneId = null;

function showAlert(msg, type = 'success') {
    const el = document.getElementById('dns-alert');
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
            sel.innerHTML = '<option value="">No zones found — check CF credentials</option>'; return;
        }
        data.data.forEach(z => {
            sel.innerHTML += `<option value="${z.id}">${z.name}</option>`;
        });
    })
    .catch(() => {
        document.getElementById('zone-sel').innerHTML = '<option value="">Failed to load zones</option>';
    });

function loadZoneSettings(zoneId) {
    const ddos = document.getElementById('ddos-toggle');
    const dev  = document.getElementById('devmode-toggle');
    ddos.disabled = true;
    dev.disabled  = true;
    document.getElementById('zone-modes-card').classList.remove('d-none');
    fetch(`/api/dns?action=zone_settings&zone_id=${encodeURIComponent(zoneId)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                ddos.checked = data.security_level === 'under_attack';
                dev.checked  = data.development_mode === 'on';
                ddos.disabled = false;
                dev.disabled  = false;
            }
        });
}

document.getElementById('ddos-toggle').addEventListener('change', function () {
    const enabled = this.checked ? '1' : '0';
    const toggle  = this;
    toggle.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_ddos_mode');
    fd.append('zone_id', currentZoneId);
    fd.append('enabled', enabled);
    fetch('/api/dns', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            toggle.disabled = false;
            if (data.success) {
                showAlert(toggle.checked ? 'DDoS mode enabled — Under Attack mode is active.' : 'DDoS mode disabled.', toggle.checked ? 'warning' : 'success');
            } else {
                toggle.checked = !toggle.checked;
                showAlert(data.error || 'Failed to update DDoS mode.', 'danger');
            }
        })
        .catch(() => { toggle.disabled = false; toggle.checked = !toggle.checked; });
});

document.getElementById('devmode-toggle').addEventListener('change', function () {
    const enabled = this.checked ? '1' : '0';
    const toggle  = this;
    toggle.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_dev_mode');
    fd.append('zone_id', currentZoneId);
    fd.append('enabled', enabled);
    fetch('/api/dns', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            toggle.disabled = false;
            if (data.success) {
                showAlert(toggle.checked ? 'Development mode enabled — cache bypassed for 3 hours.' : 'Development mode disabled.', toggle.checked ? 'info' : 'success');
            } else {
                toggle.checked = !toggle.checked;
                showAlert(data.error || 'Failed to update development mode.', 'danger');
            }
        })
        .catch(() => { toggle.disabled = false; toggle.checked = !toggle.checked; });
});

document.getElementById('load-dns-btn').addEventListener('click', function () {
    const zoneId = document.getElementById('zone-sel').value;
    if (!zoneId) return;
    currentZoneId = zoneId;
    loadZoneSettings(zoneId);
    document.getElementById('dns-table-card').classList.remove('d-none');
    document.getElementById('add-record-btn').disabled = false;
    document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Loading…</td></tr>';
    fetch(`/api/dns?action=list&zone_id=${encodeURIComponent(zoneId)}`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('dns-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>'; return;
            }
            if (!document.getElementById('dns-table')._tkInit) {
                TableKit.init('dns-table', { filter: true });
                document.getElementById('dns-table')._tkInit = true;
            }
            tbody.innerHTML = data.data.map(r => {
                const proxied = r.proxied ? '<span class="badge bg-warning text-dark"><i class="fas fa-cloud"></i></span>' : '<span class="text-muted">—</span>';
                return `<tr>
                    <td class="ps-4"><span class="badge bg-primary-subtle text-primary">${r.type}</span></td>
                    <td class="small fw-medium">${r.name}</td>
                    <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.content}</td>
                    <td class="small">${r.ttl === 1 ? 'Auto' : r.ttl}</td>
                    <td>${proxied}</td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editRecord(${JSON.stringify(r).replace(/"/g,'&quot;')})" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteRecord('${r.id}')" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        });
});

document.getElementById('add-record-btn').addEventListener('click', function () {
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

function editRecord(r) {
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
    const spinner = document.getElementById('dns-save-spinner');
    const errEl   = document.getElementById('dns-modal-error');
    const recordId = document.getElementById('dns-record-id').value;
    spinner.classList.remove('d-none');
    this.disabled = true;
    errEl.className = 'd-none';
    const fd = new FormData();
    fd.append('action', recordId ? 'update' : 'create');
    fd.append('zone_id',  currentZoneId);
    if (recordId) fd.append('record_id', recordId);
    fd.append('type',     document.getElementById('dns-type').value);
    fd.append('name',     document.getElementById('dns-name').value);
    fd.append('content',  document.getElementById('dns-content').value);
    fd.append('ttl',      document.getElementById('dns-ttl').value);
    fd.append('priority', document.getElementById('dns-priority').value);
    fd.append('proxied',  document.getElementById('dns-proxied').checked ? '1' : '0');
    fetch('/api/dns', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('dnsModal')).hide();
                showAlert('Record saved.');
                document.getElementById('load-dns-btn').click();
            } else {
                errEl.className = 'alert alert-danger mt-3 small py-2';
                errEl.textContent = data.error || 'Save failed.';
            }
        });
});

function deleteRecord(recordId) {
    if (!confirm('Delete this DNS record?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('zone_id', currentZoneId);
    fd.append('record_id', recordId);
    fetch('/api/dns', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlert('Record deleted.');
                document.getElementById('load-dns-btn').click();
            } else {
                showAlert(data.error || 'Delete failed.', 'danger');
            }
        });
}
</script>
