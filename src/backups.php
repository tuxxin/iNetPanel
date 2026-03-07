<?php
// FILE: src/backups.php
// iNetPanel — Backup Manager (real data)

Auth::requireAdmin();

// Load current settings
$backupEnabled     = DB::setting('backup_enabled', '1');
$backupDest        = DB::setting('backup_destination', '/backup');
$backupRetention   = DB::setting('backup_retention', '3');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-history me-2"></i>Backups</h4>
    <button class="btn btn-primary btn-sm" id="run-backup-btn">
        <span class="spinner-border spinner-border-sm d-none me-1" id="run-spinner"></span>
        <i class="fas fa-play me-1" id="run-icon"></i>Run Backup Now
    </button>
</div>

<div id="backup-alert" class="d-none mb-3"></div>

<div class="row g-4">

    <!-- Backup list -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Existing Backups</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="loadBackups()"><i class="fas fa-sync-alt"></i></button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">File</th>
                                <th>Size</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="backup-tbody">
                            <tr><td colspan="3" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h6 class="mb-0">Backup Settings</h6></div>
            <div class="card-body p-4">
                <form id="backup-settings-form">

                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-3 border">
                        <div>
                            <div class="fw-semibold small">Automated Backups</div>
                            <div class="text-muted" style="font-size:.75rem">Daily at 3am via cron</div>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="backup-enabled"
                                   <?= $backupEnabled === '1' ? 'checked' : '' ?> style="width:2.5em;height:1.3em">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Destination Directory</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-folder"></i></span>
                            <input type="text" class="form-control" id="backup-dest"
                                   value="<?= htmlspecialchars($backupDest) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Retention (days)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="backup-retention"
                                   value="<?= (int)$backupRetention ?>" min="1" max="365">
                            <span class="input-group-text">days</span>
                        </div>
                        <div class="form-text">Backups older than this are deleted automatically.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i>Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function showAlert(msg, type = 'success') {
    const el = document.getElementById('backup-alert');
    el.className = `alert alert-${type}`;
    el.textContent = msg;
    setTimeout(() => el.className = 'd-none', 5000);
}

function loadBackups() {
    fetch('/api/backups?action=list')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('backup-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">No backups found.</td></tr>'; return;
            }
            tbody.innerHTML = data.data.map(b =>
                `<tr>
                    <td class="ps-4 small fw-medium"><i class="fas fa-file-archive text-muted me-2"></i>${b.filename}</td>
                    <td class="small">${b.size_hr}</td>
                    <td class="small text-muted">${b.date}</td>
                </tr>`
            ).join('');
        })
        .catch(() => {
            document.getElementById('backup-tbody').innerHTML =
                '<tr><td colspan="3" class="text-center text-danger py-4">Failed to load.</td></tr>';
        });
}

document.getElementById('run-backup-btn').addEventListener('click', function () {
    const spinner = document.getElementById('run-spinner');
    const icon    = document.getElementById('run-icon');
    spinner.classList.remove('d-none');
    icon.classList.add('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'run');
    fetch('/api/backups', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            this.disabled = false;
            if (data.success) {
                showAlert('Backup completed successfully.');
                loadBackups();
            } else {
                showAlert(data.error || 'Backup failed.', 'danger');
            }
        });
});

document.getElementById('backup-settings-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'settings_save');
    fd.append('backup_enabled',     document.getElementById('backup-enabled').checked ? '1' : '0');
    fd.append('backup_destination', document.getElementById('backup-dest').value.trim());
    fd.append('backup_retention',   document.getElementById('backup-retention').value);
    fetch('/api/backups', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) showAlert('Settings saved.');
            else showAlert(data.error || 'Save failed.', 'danger');
        });
});

document.addEventListener('DOMContentLoaded', loadBackups);
</script>
