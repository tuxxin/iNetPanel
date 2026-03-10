<?php
// FILE: src/services.php
// iNetPanel — Service Manager (real status via api/services.php)
$isAdmin = Auth::hasFullAccess();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-server me-2"></i>Services</h4>
    <div>
        <button class="btn btn-outline-secondary btn-sm" id="refresh-btn">
            <i class="fas fa-sync-alt me-1"></i>Refresh
        </button>
    </div>
</div>

<div id="services-alert" class="d-none mb-3"></div>

<!-- Service Monitor Card -->
<?php if ($isAdmin): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-1 fw-bold"><i class="fas fa-heartbeat me-2 text-danger"></i>Service Monitor</h6>
            <div class="text-muted small">Automatically restarts stopped services every 2 minutes. Events are logged to Admin Logs.</div>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="monitor-toggle" style="width:3em;height:1.5em;cursor:pointer">
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Service</th>
                    <th>Status</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody id="services-tbody">
                <tr><td colspan="3" class="text-center text-muted py-4">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Confirm modal -->
<div class="modal fade" id="svcModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="svc-modal-title">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="svc-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="svc-confirm-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="svc-spinner"></span>
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingSvc   = null;
let pendingAction = null;

function showToast(msg, type = 'success') {
    const id = 'toast-' + Date.now();
    document.body.insertAdjacentHTML('beforeend',
        `<div id="${id}" class="toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3" style="z-index:9999">
            <div class="d-flex"><div class="toast-body">${msg}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button></div></div>`
    );
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

function loadServices() {
    fetch('/api/services?action=list')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('services-tbody');
            if (!data.success) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-3">Failed to load services.</td></tr>'; return;
            }
            // Update monitor toggle state
            const monToggle = document.getElementById('monitor-toggle');
            if (monToggle && data.monitorEnabled !== undefined) {
                monToggle.checked = data.monitorEnabled;
            }
            tbody.innerHTML = data.data.map(s => {
                const badgeClass = s.status === 'active' ? 'bg-success' : (s.status === 'missing' ? 'bg-danger' : 'bg-secondary');
                const statusText = s.status === 'active' ? 'Running' : (s.status === 'missing' ? 'Not installed' : 'Stopped');
                const dotClass   = s.status === 'active' ? 'text-success' : (s.status === 'missing' ? 'text-danger' : 'text-secondary');

                let actions = `<span class="text-muted fst-italic small">System Core</span>`;
                if (s.status === 'missing') {
                    actions = s.name === 'wg-quick@wg0'
                        ? `<a href="/admin/settings" class="btn btn-sm btn-outline-primary"><i class="fas fa-wrench me-1"></i>Install</a>`
                        : `<span class="text-muted fst-italic small">Not available</span>`;
                }
                <?php if ($isAdmin): ?>
                else if (!s.locked) {
                    const toggleBtn = s.status === 'active'
                        ? `<button class="btn btn-sm btn-outline-warning me-1" onclick="svcAction('${s.name}','stop')" title="Stop"><i class="fas fa-stop"></i></button>`
                        : `<button class="btn btn-sm btn-outline-success me-1" onclick="svcAction('${s.name}','start')" title="Start"><i class="fas fa-play"></i></button>`;
                    const restartBtn = `<button class="btn btn-sm btn-outline-secondary" onclick="svcAction('${s.name}','restart')" title="Restart"><i class="fas fa-redo"></i></button>`;
                    actions = toggleBtn + restartBtn;
                }
                <?php endif; ?>

                return `<tr>
                    <td class="ps-4 fw-medium">
                        <i class="fas fa-circle me-2 ${dotClass}" style="font-size:.6rem;vertical-align:middle"></i>
                        <i class="${s.icon} text-muted me-2"></i>${s.label}
                    </td>
                    <td><span class="badge ${badgeClass}">${statusText}</span></td>
                    <td class="text-end pe-4">${actions}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('services-tbody').innerHTML =
                '<tr><td colspan="3" class="text-center text-danger py-3">Request failed.</td></tr>';
        });
}

function svcAction(service, action) {
    pendingSvc    = service;
    pendingAction = action;
    const labels = { start: 'Start', stop: 'Stop', restart: 'Restart' };
    document.getElementById('svc-modal-title').textContent = labels[action] + ' Service';
    document.getElementById('svc-modal-body').innerHTML =
        `<p>Are you sure you want to <strong>${action}</strong> <code>${service}</code>?</p>`
        + (action === 'stop' ? '<p class="text-warning small mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Stopping this service may cause downtime.</p>' : '');
    document.getElementById('svc-confirm-btn').className = 'btn btn-' + (action === 'stop' ? 'warning' : 'primary');
    new bootstrap.Modal(document.getElementById('svcModal')).show();
}

document.getElementById('svc-confirm-btn').addEventListener('click', function () {
    if (!pendingSvc || !pendingAction) return;
    const spinner = document.getElementById('svc-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', pendingAction);
    fd.append('service', pendingSvc);
    fetch('/api/services', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('svcModal')).hide();
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                showToast(`Service '${pendingSvc}' ${pendingAction}ed.`);
                loadServices();
            } else {
                showToast(data.error || 'Action failed.', 'danger');
            }
        });
});

document.getElementById('refresh-btn').addEventListener('click', loadServices);

// Monitor toggle
const monitorToggle = document.getElementById('monitor-toggle');
if (monitorToggle) {
    monitorToggle.addEventListener('change', function() {
        const enabled = this.checked ? '1' : '0';
        const fd = new FormData();
        fd.append('action', 'toggle_monitor');
        fd.append('enabled', enabled);
        fetch('/api/services', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('Service Monitor ' + (data.monitorEnabled ? 'enabled' : 'disabled') + '.');
                } else {
                    showToast(data.error || 'Failed to toggle monitor.', 'danger');
                    monitorToggle.checked = !monitorToggle.checked;
                }
            })
            .catch(() => {
                showToast('Request failed.', 'danger');
                monitorToggle.checked = !monitorToggle.checked;
            });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    loadServices();
    setInterval(loadServices, 30000);
});
</script>
