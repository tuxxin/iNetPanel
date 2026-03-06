<?php
// FILE: src/php_packages.php
// iNetPanel — PHP Extension Packages (install/remove per version)

Auth::requireAdmin();
?>

<h4 class="mb-4"><i class="fas fa-box me-2"></i>PHP Packages</h4>

<div id="pkg-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body pb-0">
        <div class="row align-items-end g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">PHP Version</label>
                <select class="form-select" id="pkg-version-sel">
                    <option value="">Loading…</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary" id="load-pkgs-btn">
                    <i class="fas fa-search me-1"></i>Load Packages
                </button>
            </div>
        </div>
    </div>
</div>

<div id="pkg-table-wrap" class="d-none">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <span class="fw-semibold" id="pkg-version-label">Packages</span>
            <input type="text" class="form-control form-control-sm w-auto" id="pkg-search" placeholder="Filter…" style="min-width:180px">
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Extension</th>
                            <th>Package</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody id="pkg-tbody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Progress modal -->
<div class="modal fade" id="pkgModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pkg-modal-title">Working…</h5>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="spinner-border text-primary flex-shrink-0"></div>
                    <span id="pkg-modal-msg">Please wait…</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentVersion = null;

function showAlert(msg, type = 'success') {
    const el = document.getElementById('pkg-alert');
    el.className = `alert alert-${type}`;
    el.textContent = msg;
    setTimeout(() => el.className = 'd-none', 5000);
}

// Load installed versions into selector
fetch('/api/packages.php?action=installed_versions')
    .then(r => r.json())
    .then(data => {
        const sel = document.getElementById('pkg-version-sel');
        sel.innerHTML = '';
        if (!data.success || !data.data.length) {
            sel.innerHTML = '<option value="">No PHP versions installed</option>'; return;
        }
        data.data.slice().reverse().forEach((v, i) => {
            sel.innerHTML += `<option value="${v}">${v === data.data[data.data.length - 1] ? v + ' (latest)' : v}</option>`;
        });
    })
    .catch(() => {
        document.getElementById('pkg-version-sel').innerHTML = '<option value="">Failed to load</option>';
    });

function loadPackages(ver) {
    currentVersion = ver;
    document.getElementById('pkg-table-wrap').classList.remove('d-none');
    document.getElementById('pkg-version-label').textContent = `PHP ${ver} Extensions`;
    document.getElementById('pkg-tbody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>';

    fetch(`/api/packages.php?action=list&version=${encodeURIComponent(ver)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('pkg-tbody').innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">${data.error}</td></tr>`;
                return;
            }
            renderPackages(data.data, ver);
        })
        .catch(() => {
            document.getElementById('pkg-tbody').innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Request failed.</td></tr>';
        });
}

function renderPackages(packages, ver) {
    const tbody = document.getElementById('pkg-tbody');
    tbody.innerHTML = packages.map(p => {
        const badge = p.installed
            ? '<span class="badge bg-success">Installed</span>'
            : '<span class="badge bg-secondary">Not installed</span>';
        const btn = p.installed
            ? `<button class="btn btn-sm btn-outline-danger" onclick="togglePkg('${ver}','${p.extension}','remove')">Remove</button>`
            : `<button class="btn btn-sm btn-outline-primary" onclick="togglePkg('${ver}','${p.extension}','install')">Install</button>`;
        return `<tr data-pkg="${p.extension}">
            <td class="ps-4 fw-medium">${p.extension}</td>
            <td class="text-muted small">${p.package}</td>
            <td>${badge}</td>
            <td class="text-end pe-4">${btn}</td>
        </tr>`;
    }).join('');
}

function togglePkg(ver, ext, action) {
    const modal = new bootstrap.Modal(document.getElementById('pkgModal'));
    document.getElementById('pkg-modal-title').textContent = `${action === 'install' ? 'Installing' : 'Removing'} php${ver}-${ext}…`;
    document.getElementById('pkg-modal-msg').textContent = 'Please wait…';
    modal.show();
    const fd = new FormData();
    fd.append('action', action);
    fd.append('version', ver);
    fd.append('extension', ext);
    fetch('/api/packages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            modal.hide();
            if (data.success) {
                showAlert(`php${ver}-${ext} ${action}ed successfully.`);
                loadPackages(ver);
            } else {
                showAlert(data.error || `${action} failed.`, 'danger');
            }
        })
        .catch(() => { modal.hide(); showAlert('Request failed.', 'danger'); });
}

document.getElementById('load-pkgs-btn').addEventListener('click', function () {
    const ver = document.getElementById('pkg-version-sel').value;
    if (ver) loadPackages(ver);
});

// Filter
document.getElementById('pkg-search').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#pkg-tbody tr').forEach(row => {
        row.style.display = row.dataset.pkg?.includes(q) ? '' : 'none';
    });
});
</script>
