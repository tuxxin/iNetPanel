<?php
// FILE: src/ssl.php
// iNetPanel — SSL Certificate Management Page
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-lock me-2"></i>SSL Certificates</h4>
    <div>
        <button class="btn btn-outline-primary btn-sm me-2" id="renew-all-btn">
            <i class="fas fa-rotate me-1"></i>Renew All
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="refresh-btn">
            <i class="fas fa-sync-alt me-1"></i>Refresh
        </button>
    </div>
</div>

<div id="ssl-alert" class="d-none mb-3"></div>

<!-- Panel Services SSL -->
<div class="card border-0 shadow-sm mb-4" id="panel-ssl-card" style="display:none">
    <div class="card-header bg-white border-bottom">
        <h6 class="mb-0 fw-bold"><i class="fas fa-server me-2 text-primary"></i>Panel Services</h6>
    </div>
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-4">
                <div class="fw-semibold" id="panel-ssl-hostname">—</div>
                <div class="text-muted small">Panel Hostname</div>
            </div>
            <div class="col-md-2" id="panel-ssl-type">—</div>
            <div class="col-md-2" id="panel-ssl-expiry">—</div>
            <div class="col-md-2" id="panel-ssl-status">—</div>
            <div class="col-md-2 text-end">
                <button class="btn btn-sm btn-outline-success" id="panel-ssl-issue-btn" title="Issue/Reissue Panel Certificate">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="panel-ssl-spinner"></span>
                    <i class="fas fa-certificate me-1"></i>Reissue
                </button>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col-md-6">
                <span class="badge bg-light text-dark border me-2"><i class="fas fa-bolt me-1"></i>lighttpd: <span id="panel-lighttpd-status" class="fw-bold">—</span></span>
                <span class="badge bg-light text-dark border"><i class="fas fa-database me-1"></i>phpMyAdmin: <span id="panel-pma-status" class="fw-bold">—</span></span>
            </div>
        </div>
    </div>
</div>

<!-- Status Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="fs-2 fw-bold text-success" id="cert-total">—</div>
                <div class="text-muted small">Domain Certificates</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="fs-2 fw-bold text-warning" id="cert-expiring">—</div>
                <div class="text-muted small">Expiring Soon (&lt;30d)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="fs-2 fw-bold" id="cert-cron">—</div>
                <div class="text-muted small">Auto-Renewal</div>
            </div>
        </div>
    </div>
</div>

<!-- Certificates Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom">
        <h6 class="mb-0 fw-bold"><i class="fas fa-globe me-2 text-primary"></i>Domain Certificates</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Domain</th>
                    <th>Type</th>
                    <th>Expires</th>
                    <th>Status</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody id="ssl-tbody">
                <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
const sslAlert = document.getElementById('ssl-alert');
const sslTbody = document.getElementById('ssl-tbody');

function showAlert(type, msg) {
    sslAlert.className = `alert alert-${type} mb-3`;
    sslAlert.innerHTML = msg;
}

function hideAlert() {
    sslAlert.className = 'd-none mb-3';
}

function daysUntil(dateStr) {
    if (!dateStr) return -1;
    const d = new Date(dateStr);
    const now = new Date();
    return Math.floor((d - now) / 86400000);
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function typeBadge(type) {
    if (type === 'letsencrypt') return '<span class="badge bg-primary">Let\'s Encrypt</span>';
    if (type === 'self-signed') return '<span class="badge bg-secondary">Self-Signed</span>';
    return '—';
}

function statusBadge(exists, days) {
    if (!exists) return '<span class="badge bg-danger">No Certificate</span>';
    if (days < 0) return '<span class="badge bg-danger">Expired</span>';
    if (days < 30) return '<span class="badge bg-warning text-dark">Expires in ' + days + 'd</span>';
    return '<span class="badge bg-success">Valid (' + days + 'd)</span>';
}

function loadCerts(preserveAlert) {
    if (!preserveAlert) hideAlert();
    fetch('/api/ssl?action=status')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlert('danger', data.error || 'Failed to load certificates.');
                return;
            }

            // Panel service certificate
            const pc = data.panelCert;
            const panelCard = document.getElementById('panel-ssl-card');
            if (pc) {
                panelCard.style.display = '';
                document.getElementById('panel-ssl-hostname').textContent = pc.domain;
                document.getElementById('panel-ssl-type').innerHTML = pc.exists ? typeBadge(pc.type) : '—';
                const panelDays = daysUntil(pc.expiry);
                document.getElementById('panel-ssl-expiry').textContent = pc.expiry ? new Date(pc.expiry).toLocaleDateString() : '—';
                document.getElementById('panel-ssl-status').innerHTML = statusBadge(pc.exists, panelDays);
                document.getElementById('panel-lighttpd-status').textContent = pc.lighttpd_ssl ? 'HTTPS' : 'HTTP';
                document.getElementById('panel-lighttpd-status').className = 'fw-bold ' + (pc.lighttpd_ssl ? 'text-success' : 'text-warning');
                document.getElementById('panel-pma-status').textContent = pc.pma_ssl ? 'HTTPS' : 'HTTP';
                document.getElementById('panel-pma-status').className = 'fw-bold ' + (pc.pma_ssl ? 'text-success' : 'text-warning');
            } else {
                panelCard.style.display = 'none';
            }

            // Cron status
            const cronEl = document.getElementById('cert-cron');
            cronEl.textContent = data.cronActive ? 'Active' : 'Inactive';
            cronEl.className = 'fs-2 fw-bold ' + (data.cronActive ? 'text-success' : 'text-danger');

            // Domain certificates
            const certs = data.data;
            let total = 0, expiring = 0;

            if (!certs.length) {
                sslTbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No hosted domains found.</td></tr>';
                document.getElementById('cert-total').textContent = '0';
                document.getElementById('cert-expiring').textContent = '0';
                return;
            }

            let html = '';
            certs.forEach(c => {
                const days = daysUntil(c.expiry);

                if (c.exists) {
                    total++;
                    if (days >= 0 && days < 30) expiring++;
                }

                const expiryStr = c.expiry ? new Date(c.expiry).toLocaleDateString() : '—';

                html += `<tr>
                    <td class="ps-4 fw-semibold">${esc(c.domain)}</td>
                    <td>${c.exists ? typeBadge(c.type) : '—'}</td>
                    <td>${expiryStr}</td>
                    <td>${statusBadge(c.exists, days)}</td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-success me-1" onclick="issueCert('${esc(c.domain)}')" title="Issue/Reissue">
                            <i class="fas fa-certificate"></i>
                        </button>
                        ${c.exists ? `<button class="btn btn-sm btn-outline-danger" onclick="revokeCert('${esc(c.domain)}')" title="Revoke">
                            <i class="fas fa-trash"></i>
                        </button>` : ''}
                    </td>
                </tr>`;
            });

            sslTbody.innerHTML = html;
            document.getElementById('cert-total').textContent = total;
            document.getElementById('cert-expiring').textContent = expiring;
        })
        .catch(() => showAlert('danger', 'Failed to connect to API.'));
}

function issueCert(domain) {
    if (!confirm('Issue SSL certificate for ' + domain + '?\n\nThis uses Let\'s Encrypt via Cloudflare DNS challenge.')) return;
    showAlert('info', '<i class="fas fa-spinner fa-spin me-2"></i>Issuing certificate for ' + esc(domain) + '... This may take a minute.');

    fetch('/api/ssl?action=issue', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'domain=' + encodeURIComponent(domain)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Certificate issued for ' + esc(domain) + '.');
        } else {
            showAlert('warning', 'Certificate issuance: ' + esc(data.error || data.output || 'Unknown result'));
        }
        loadCerts(true);
    })
    .catch(() => showAlert('danger', 'Request failed.'));
}

function revokeCert(domain) {
    if (!confirm('Revoke and delete SSL certificate for ' + domain + '?')) return;

    fetch('/api/ssl?action=revoke', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'domain=' + encodeURIComponent(domain)
    })
    .then(r => r.json())
    .then(data => {
        showAlert(data.success ? 'success' : 'danger', data.success ? 'Certificate revoked.' : (data.error || 'Failed.'));
        loadCerts(true);
    })
    .catch(() => showAlert('danger', 'Request failed.'));
}

// Panel SSL issue/reissue
document.getElementById('panel-ssl-issue-btn').addEventListener('click', function() {
    if (!confirm('Issue/reissue SSL certificate for panel services?\n\nThis will attempt Let\'s Encrypt first, then fall back to self-signed.')) return;
    const spinner = document.getElementById('panel-ssl-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    showAlert('info', '<i class="fas fa-spinner fa-spin me-2"></i>Issuing panel certificate... This may take a minute.');

    fetch('/api/ssl?action=issue_panel', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                showAlert('success', 'Panel SSL certificate issued. Services reloaded.');
            } else {
                showAlert('danger', 'Panel SSL failed: ' + esc(data.error || data.output || 'Unknown error'));
            }
            loadCerts(true);
        })
        .catch(() => {
            spinner.classList.add('d-none');
            this.disabled = false;
            showAlert('danger', 'Request failed.');
        });
});

document.getElementById('renew-all-btn').addEventListener('click', function() {
    if (!confirm('Force renewal of all certificates?')) return;
    showAlert('info', '<i class="fas fa-spinner fa-spin me-2"></i>Renewing all certificates...');

    fetch('/api/ssl?action=renew', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            showAlert(data.success ? 'success' : 'danger', data.success ? 'Renewal complete.' : (data.error || 'Failed.'));
            loadCerts(true);
        })
        .catch(() => showAlert('danger', 'Request failed.'));
});

document.getElementById('refresh-btn').addEventListener('click', loadCerts);

loadCerts();
</script>
