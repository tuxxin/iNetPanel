<?php
// FILE: src/restore.php
// iNetPanel — Restore Backup (admin UI)

$cfEnabled  = DB::setting('cf_enabled', '0') === '1';
$cfProxy    = !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
$serverIp   = trim(shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'") ?: '');
if (!$serverIp) $serverIp = trim(shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'") ?: '');
$phpLimit   = ini_get('upload_max_filesize') ?: '100M';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-upload me-2"></i>Restore Backup</h4>
    <a href="/admin/backups" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Backups</a>
</div>

<div id="restore-alert" class="d-none mb-3"></div>

<!-- ── Step indicator ── -->
<div class="d-flex gap-2 mb-4" id="step-nav">
    <span class="badge bg-primary px-3 py-2" data-step="1">1. Upload</span>
    <span class="badge bg-secondary px-3 py-2" data-step="2">2. Review</span>
    <?php if ($cfEnabled): ?>
    <span class="badge bg-secondary px-3 py-2" data-step="3">3. Cloudflare</span>
    <?php endif; ?>
    <span class="badge bg-secondary px-3 py-2" data-step="4"><?= $cfEnabled ? '4' : '3' ?>. Restore</span>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 1: Upload                                                           -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-1" class="restore-step">
    <?php if ($cfProxy): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <i class="fas fa-cloud me-1"></i><strong>Cloudflare proxy detected</strong> — web uploads limited to 100MB. Use FTP or SCP for larger backups.
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-web">Web Upload</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-ftp">FTP Upload</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-scp">SSH / SCP</a></li>
    </ul>

    <div class="tab-content">
        <!-- Web Upload -->
        <div class="tab-pane fade show active" id="tab-web">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <label class="form-label fw-semibold">Select Backup File (.tgz)</label>
                    <input type="file" class="form-control mb-2" id="backup-file" accept=".tgz">
                    <div class="form-text mb-3">
                        Max upload: <strong><?= $cfProxy ? '100MB (Cloudflare limit)' : htmlspecialchars($phpLimit) ?></strong>
                    </div>

                    <!-- Upload progress -->
                    <div id="upload-progress-wrap" class="d-none">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span id="upload-status-text">Uploading...</span>
                            <span id="upload-pct">0%</span>
                        </div>
                        <div class="progress mb-2" style="height:8px">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="upload-bar" style="width:0%"></div>
                        </div>
                        <div class="small text-muted" id="upload-bytes"></div>
                    </div>

                    <button class="btn btn-primary mt-2" id="upload-btn" disabled>
                        <i class="fas fa-cloud-upload-alt me-1"></i>Upload
                    </button>
                </div>
            </div>
        </div>

        <!-- FTP Upload -->
        <div class="tab-pane fade" id="tab-ftp">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">Upload large backup files via FTP. Connect with the credentials below and upload your <code>.tgz</code> file.</p>
                    <div id="ftp-info">
                        <div class="spinner-border spinner-border-sm me-1"></div> Loading FTP credentials...
                    </div>
                    <hr>
                    <div class="d-flex align-items-center gap-3">
                        <div class="small text-muted" id="ftp-poll-status">Waiting for file upload...</div>
                        <button class="btn btn-sm btn-outline-primary" id="ftp-refresh-btn">
                            <i class="fas fa-sync-alt me-1"></i>Check Now
                        </button>
                    </div>
                    <div id="ftp-detected" class="d-none mt-3"></div>
                </div>
            </div>
        </div>

        <!-- SCP -->
        <div class="tab-pane fade" id="tab-scp">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">Run this command on the <strong>source server</strong> to send the backup directly:</p>
                    <div class="bg-dark text-light p-3 rounded font-monospace small mb-3" id="scp-command">
                        scp /backup/USERNAME_DATE.tgz root@<?= htmlspecialchars($serverIp) ?>:/backup/restore_staging/
                    </div>
                    <div class="alert alert-info py-2 small">
                        <i class="fas fa-info-circle me-1"></i>Requires SSH access from the source server. Only works if both servers are on the same local or public network.
                    </div>
                    <hr>
                    <div class="d-flex align-items-center gap-3">
                        <div class="small text-muted" id="scp-poll-status">Waiting for file...</div>
                        <button class="btn btn-sm btn-outline-primary" id="scp-refresh-btn">
                            <i class="fas fa-sync-alt me-1"></i>Check Now
                        </button>
                    </div>
                    <div id="scp-detected" class="d-none mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Staged files (shown after any upload method detects a file) -->
    <div id="staged-files" class="d-none mt-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <h6 class="mb-2"><i class="fas fa-file-archive me-1"></i>Staged Backups</h6>
                <div id="staged-list"></div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 2: Review & Confirm                                                 -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-2" class="restore-step d-none">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-3"><i class="fas fa-user me-1"></i>Account Details</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Username</label>
                    <input type="text" class="form-control" id="restore-username" pattern="[a-z][a-z0-9\-]{0,31}">
                    <div class="form-text" id="user-conflict-msg"></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="restore-password">
                        <button class="btn btn-outline-secondary" type="button" id="restore-toggle-pass"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-outline-secondary" type="button" id="restore-gen-pass" title="Generate"><i class="fas fa-sync-alt"></i></button>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">PHP Version</label>
                    <select class="form-select" id="restore-php-version"></select>
                </div>
            </div>
            <div class="small text-muted">
                <i class="fas fa-info-circle me-1"></i>This password will be used for FTP, SSH, and MariaDB access.
            </div>
        </div>
    </div>

    <!-- Domains table -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-3"><i class="fas fa-globe me-1"></i>Domains</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Domain</th><th style="width:100px">Port</th><th>Status</th></tr></thead>
                    <tbody id="domains-table"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Databases table -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-3"><i class="fas fa-database me-1"></i>Databases</h6>
            <div id="databases-section"></div>
        </div>
    </div>

    <!-- Summary -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <div class="row text-center">
                <div class="col"><div class="small text-muted">Archive Size</div><strong id="summary-size">—</strong></div>
                <div class="col"><div class="small text-muted">Files</div><strong id="summary-files">—</strong></div>
                <div class="col"><div class="small text-muted">Domains</div><strong id="summary-domains">—</strong></div>
                <div class="col"><div class="small text-muted">Databases</div><strong id="summary-dbs">—</strong></div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" onclick="goToStep(1)"><i class="fas fa-arrow-left me-1"></i>Back</button>
        <button class="btn btn-primary" id="step2-next"><?= $cfEnabled ? 'Next: Cloudflare Check' : 'Start Restore' ?></button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 3: Cloudflare Check (only if CF enabled)                            -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($cfEnabled): ?>
<div id="step-3" class="restore-step d-none">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-3"><i class="fas fa-cloud me-1"></i>Cloudflare Routing Check</h6>
            <div id="cf-check-loading" class="text-center py-3">
                <div class="spinner-border spinner-border-sm me-1"></div> Checking Cloudflare...
            </div>
            <div id="cf-results" class="d-none"></div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" onclick="goToStep(2)"><i class="fas fa-arrow-left me-1"></i>Back</button>
        <button class="btn btn-primary" id="step3-next">Start Restore</button>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- STEP 4: Execute & Summary                                                -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="step-4" class="restore-step d-none">
    <!-- Progress -->
    <div id="restore-progress" class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4 text-center">
            <div class="spinner-border text-primary mb-3" style="width:2.5rem;height:2.5rem"></div>
            <h6 class="mb-2">Restoring Account</h6>
            <p class="small text-muted mb-3" id="restore-stage">Starting...</p>
            <div class="progress mb-2" style="height:6px">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="restore-bar" style="width:5%"></div>
            </div>
            <div class="small text-muted" id="restore-bar-pct">5%</div>
        </div>
    </div>

    <!-- Result (hidden until done) -->
    <div id="restore-result" class="d-none">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-4">
                <div class="text-center mb-3">
                    <i class="fas fa-check-circle text-success fa-3x"></i>
                    <h5 class="mt-2">Account Restored!</h5>
                </div>
                <div id="result-details"></div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="/admin/accounts" class="btn btn-primary"><i class="fas fa-users me-1"></i>View Accounts</a>
            <button class="btn btn-outline-secondary" onclick="goToStep(1)"><i class="fas fa-upload me-1"></i>Restore Another</button>
        </div>
    </div>
</div>

<script>
const cfEnabled = <?= $cfEnabled ? 'true' : 'false' ?>;
let selectedFile = null;
let parsedData = null;
let cfData = {};

// ── Helpers ──────────────────────────────────────────────────────────────────
function showAlert(msg, type) {
    const el = document.getElementById('restore-alert');
    el.className = 'alert alert-' + type + ' py-2 small mb-3';
    el.innerHTML = (type === 'danger' ? '<i class="fas fa-times-circle me-1"></i>' : '<i class="fas fa-check-circle me-1"></i>') + msg;
}

function formatBytes(b) {
    if (b === 0) return '0 B';
    const k = 1024, sizes = ['B','KB','MB','GB','TB'];
    const i = Math.floor(Math.log(b) / Math.log(k));
    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function generatePassword() {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
    const arr = new Uint32Array(16);
    crypto.getRandomValues(arr);
    return Array.from(arr, n => chars[n % chars.length]).join('');
}

function goToStep(n) {
    document.querySelectorAll('.restore-step').forEach(el => el.classList.add('d-none'));
    const step = document.getElementById('step-' + n);
    if (step) step.classList.remove('d-none');
    document.querySelectorAll('#step-nav .badge').forEach(el => {
        el.classList.replace('bg-primary', 'bg-secondary');
    });
    const nav = document.querySelector(`#step-nav [data-step="${n}"]`);
    if (nav) nav.classList.replace('bg-secondary', 'bg-primary');
}

// ── Step 1: Web Upload ───────────────────────────────────────────────────────
const fileInput = document.getElementById('backup-file');
const uploadBtn = document.getElementById('upload-btn');

fileInput.addEventListener('change', () => {
    uploadBtn.disabled = !fileInput.files.length;
});

uploadBtn.addEventListener('click', () => {
    const file = fileInput.files[0];
    if (!file) return;

    uploadBtn.disabled = true;
    const wrap = document.getElementById('upload-progress-wrap');
    wrap.classList.remove('d-none');

    const xhr = new XMLHttpRequest();
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('backup', file);

    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            document.getElementById('upload-bar').style.width = pct + '%';
            document.getElementById('upload-pct').textContent = pct + '%';
            document.getElementById('upload-bytes').textContent = formatBytes(e.loaded) + ' / ' + formatBytes(e.total);
            document.getElementById('upload-status-text').textContent = pct < 100 ? 'Uploading...' : 'Processing...';
        }
    };

    xhr.onload = () => {
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                document.getElementById('upload-status-text').textContent = 'Upload complete!';
                document.getElementById('upload-bar').classList.remove('progress-bar-animated');
                document.getElementById('upload-bar').classList.add('bg-success');
                showAlert('Uploaded: ' + data.filename + ' (' + data.size_hr + ')', 'success');
                selectedFile = data.filename;
                refreshStagedFiles();
            } else {
                showAlert(data.error || 'Upload failed.', 'danger');
                uploadBtn.disabled = false;
            }
        } catch (e) {
            showAlert('Upload failed — server error.', 'danger');
            uploadBtn.disabled = false;
        }
    };

    xhr.onerror = () => {
        showAlert('Upload failed — connection error.', 'danger');
        uploadBtn.disabled = false;
    };

    xhr.open('POST', '/api/restore');
    xhr.send(fd);
});

// ── Step 1: FTP Info ─────────────────────────────────────────────────────────
fetch('/api/restore?action=ftp_info')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('ftp-info').innerHTML = `
                <div class="row g-2 small">
                    <div class="col-md-3"><span class="text-muted">Host:</span><br><strong>${data.host}</strong></div>
                    <div class="col-md-2"><span class="text-muted">Port:</span><br><strong>${data.port}</strong></div>
                    <div class="col-md-3"><span class="text-muted">Username:</span><br><strong>${data.username}</strong></div>
                    <div class="col-md-4"><span class="text-muted">Password:</span><br><code>${data.password}</code></div>
                </div>
                <div class="form-text mt-2">Upload your <code>.tgz</code> file to: <code>${data.directory}/</code></div>`;
        } else {
            document.getElementById('ftp-info').innerHTML = '<span class="text-danger small">Failed to load FTP info.</span>';
        }
    })
    .catch(() => { document.getElementById('ftp-info').innerHTML = '<span class="text-danger small">Connection error.</span>'; });

// ── Step 1: Polling for FTP/SCP uploads ──────────────────────────────────────
let pollTimer = null;

function pollStagedFiles(source) {
    fetch('/api/restore?action=upload_status')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.files.length > 0) {
                const statusEl = document.getElementById(source + '-poll-status');
                const detectEl = document.getElementById(source + '-detected');
                const f = data.files[0];
                statusEl.innerHTML = '<i class="fas fa-check-circle text-success me-1"></i>File detected!';
                detectEl.className = 'mt-3';
                detectEl.innerHTML = `<div class="alert alert-success py-2 small mb-2">
                    <strong>${f.filename}</strong> — ${f.size_hr}
                </div>`;
                refreshStagedFiles();
            }
        }).catch(() => {});
}

function startPolling(source) {
    pollStagedFiles(source);
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(() => pollStagedFiles(source), 5000);
}

document.getElementById('ftp-refresh-btn').addEventListener('click', () => pollStagedFiles('ftp'));
document.getElementById('scp-refresh-btn').addEventListener('click', () => pollStagedFiles('scp'));

// Start polling when FTP or SCP tabs are shown
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', (e) => {
        if (pollTimer) clearInterval(pollTimer);
        const target = e.target.getAttribute('href');
        if (target === '#tab-ftp') startPolling('ftp');
        else if (target === '#tab-scp') startPolling('scp');
    });
});

// ── Step 1: Staged files list ────────────────────────────────────────────────
function refreshStagedFiles() {
    fetch('/api/restore?action=upload_status')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.files.length) return;
            const wrap = document.getElementById('staged-files');
            const list = document.getElementById('staged-list');
            wrap.classList.remove('d-none');
            list.innerHTML = data.files.map(f => `
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <i class="fas fa-file-archive text-warning me-1"></i>
                        <strong>${f.filename}</strong>
                        <span class="text-muted ms-2">${f.size_hr}</span>
                    </div>
                    <button class="btn btn-sm btn-primary" onclick="selectFile('${f.filename}')">
                        <i class="fas fa-arrow-right me-1"></i>Restore This
                    </button>
                </div>`).join('');
        }).catch(() => {});
}

function selectFile(filename) {
    selectedFile = filename;
    parseBackup(filename);
}

// Initial check for any existing staged files
refreshStagedFiles();

// ── Step 2: Parse backup ─────────────────────────────────────────────────────
function parseBackup(filename) {
    goToStep(2);
    const fd = new FormData();
    fd.append('action', 'parse');
    fd.append('filename', filename);

    fetch('/api/restore', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlert(data.error || 'Failed to parse backup.', 'danger');
                goToStep(1);
                return;
            }
            parsedData = data;
            renderStep2(data);
        })
        .catch(() => { showAlert('Failed to parse backup.', 'danger'); goToStep(1); });
}

function renderStep2(data) {
    // Username
    const userInput = document.getElementById('restore-username');
    userInput.value = data.archive_user;
    const conflictMsg = document.getElementById('user-conflict-msg');
    if (data.user_exists) {
        conflictMsg.className = 'form-text text-warning';
        conflictMsg.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>User exists — password will be updated and files merged.';
    } else {
        conflictMsg.className = 'form-text text-success';
        conflictMsg.innerHTML = '<i class="fas fa-check-circle me-1"></i>Username available.';
    }

    // Password
    document.getElementById('restore-password').value = generatePassword();

    // PHP versions dropdown
    const phpSel = document.getElementById('restore-php-version');
    phpSel.innerHTML = data.php_versions.map(v =>
        `<option value="${v}" ${v === data.php_versions[data.php_versions.length-1] ? 'selected' : ''}>${v}</option>`
    ).join('');

    // Domains table
    const tbody = document.getElementById('domains-table');
    tbody.innerHTML = data.domains.map((d, i) => {
        let badge = '<span class="badge bg-success">Ready</span>';
        if (d.conflict) {
            badge = '<span class="badge bg-warning text-dark">Exists — will overwrite</span>';
        }
        return `<tr>
            <td><i class="fas fa-globe me-1 text-muted"></i>${d.domain}</td>
            <td><input type="number" class="form-control form-control-sm domain-port" data-idx="${i}" value="${d.port}" min="1024" max="65535" style="width:90px"></td>
            <td>${badge}${d.old_port ? ' <span class="text-muted small">(was port ' + d.old_port + ')</span>' : ''}</td>
        </tr>`;
    }).join('');

    // Databases
    const dbSection = document.getElementById('databases-section');
    if (data.databases.length > 0) {
        dbSection.innerHTML = `
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th><input type="checkbox" checked id="db-select-all"></th><th>Database</th><th>Size</th></tr></thead>
                    <tbody>${data.databases.map((db, i) => `
                        <tr>
                            <td><input type="checkbox" class="db-check" data-idx="${i}" checked></td>
                            <td><i class="fas fa-database me-1 text-muted"></i>${db.db_name}</td>
                            <td>${db.size_hr}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>`;
        document.getElementById('db-select-all')?.addEventListener('change', function() {
            document.querySelectorAll('.db-check').forEach(cb => cb.checked = this.checked);
        });
    } else {
        dbSection.innerHTML = '<p class="text-muted small mb-0"><i class="fas fa-info-circle me-1"></i>No databases found in this backup.</p>';
    }

    // Summary
    document.getElementById('summary-size').textContent = data.archive_size_hr;
    document.getElementById('summary-files').textContent = data.file_count.toLocaleString();
    document.getElementById('summary-domains').textContent = data.domains.length;
    document.getElementById('summary-dbs').textContent = data.databases.length;
}

// Password helpers
document.getElementById('restore-toggle-pass').addEventListener('click', function() {
    const inp = document.getElementById('restore-password');
    const icon = this.querySelector('i');
    if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye', 'fa-eye-slash'); }
    else { inp.type = 'password'; icon.classList.replace('fa-eye-slash', 'fa-eye'); }
});
document.getElementById('restore-gen-pass').addEventListener('click', () => {
    const inp = document.getElementById('restore-password');
    inp.value = generatePassword();
    inp.type = 'text';
    document.getElementById('restore-toggle-pass').querySelector('i').className = 'fas fa-eye-slash';
});

// ── Step 2 → Step 3 / Execute ────────────────────────────────────────────────
document.getElementById('step2-next').addEventListener('click', () => {
    if (cfEnabled) {
        goToStep(3);
        runCfCheck();
    } else {
        goToStep(4);
        executeRestore();
    }
});

// ── Step 3: Cloudflare check ─────────────────────────────────────────────────
function runCfCheck() {
    const loading = document.getElementById('cf-check-loading');
    const results = document.getElementById('cf-results');
    loading.classList.remove('d-none');
    results.classList.add('d-none');

    const domains = parsedData.domains.map(d => d.domain);
    const fd = new FormData();
    fd.append('action', 'cf_check');
    fd.append('domains', JSON.stringify(domains));

    fetch('/api/restore', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            loading.classList.add('d-none');
            results.classList.remove('d-none');
            if (!data.success || !data.cf_enabled) {
                results.innerHTML = '<p class="text-muted small">Cloudflare is not configured. Domains will use self-signed SSL.</p>';
                return;
            }
            cfData = data.domains;
            renderCfResults(data.domains);
        })
        .catch(() => {
            loading.classList.add('d-none');
            results.classList.remove('d-none');
            results.innerHTML = '<div class="alert alert-danger py-2 small">Failed to check Cloudflare.</div>';
        });
}

function renderCfResults(domains) {
    const el = document.getElementById('cf-results');
    let html = '';
    for (const [domain, info] of Object.entries(domains)) {
        const routed = info.currently_routed;
        const zoneOk = info.zone_found;

        let statusHtml = '';
        if (!zoneOk) {
            statusHtml = '<span class="badge bg-secondary">Zone not in Cloudflare</span>';
        } else if (routed) {
            statusHtml = `<span class="badge bg-warning text-dark">Currently routed</span>
                <span class="text-muted small ms-2">→ ${info.current_service}</span>`;
        } else {
            statusHtml = '<span class="badge bg-success">Not routed — available</span>';
        }

        const dnsHtml = info.dns_records.length > 0
            ? info.dns_records.map(r => `<span class="badge bg-dark me-1">${r.type}: ${r.content}${r.proxied ? ' (proxied)' : ''}</span>`).join('')
            : '<span class="text-muted small">No DNS records</span>';

        const checked = zoneOk ? 'checked' : '';
        const warn = routed ? `<div class="small text-warning mt-1"><i class="fas fa-exclamation-triangle me-1"></i>This will redirect traffic from the current server to this one.</div>` : '';

        html += `<div class="border rounded p-3 mb-2">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>${domain}</strong><br>
                    <div class="mt-1">${statusHtml}</div>
                    <div class="mt-1">${dnsHtml}</div>
                    ${warn}
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input cf-override" value="${domain}" ${checked} ${!zoneOk ? 'disabled' : ''}>
                    <label class="form-check-label small">Route here</label>
                </div>
            </div>
        </div>`;
    }
    el.innerHTML = html;
}

if (document.getElementById('step3-next')) {
    document.getElementById('step3-next').addEventListener('click', () => {
        goToStep(4);
        executeRestore();
    });
}

// ── Step 4: Execute restore ──────────────────────────────────────────────────
function executeRestore() {
    document.getElementById('restore-progress').classList.remove('d-none');
    document.getElementById('restore-result').classList.add('d-none');

    const stages = [
        { pct: 10, text: 'Creating user...' },
        { pct: 30, text: 'Extracting files...' },
        { pct: 50, text: 'Importing databases...' },
        { pct: 70, text: 'Configuring services...' },
        { pct: 85, text: 'Updating Cloudflare...' },
        { pct: 95, text: 'Finalizing...' },
    ];
    let stageIdx = 0;
    const timer = setInterval(() => {
        if (stageIdx < stages.length) {
            document.getElementById('restore-bar').style.width = stages[stageIdx].pct + '%';
            document.getElementById('restore-stage').textContent = stages[stageIdx].text;
            document.getElementById('restore-bar-pct').textContent = stages[stageIdx].pct + '%';
            stageIdx++;
        }
    }, 2000);

    // Gather domain data with user-edited ports
    const domainData = parsedData.domains.map((d, i) => {
        const portInput = document.querySelector(`.domain-port[data-idx="${i}"]`);
        return {
            domain: d.domain,
            port: portInput ? parseInt(portInput.value) : d.port,
            php_version: document.getElementById('restore-php-version').value,
        };
    });

    // CF overrides
    const cfOverride = [];
    document.querySelectorAll('.cf-override:checked').forEach(cb => cfOverride.push(cb.value));

    // Has any DB checked for import?
    const hasDbImport = document.querySelectorAll('.db-check:checked').length > 0;

    const fd = new FormData();
    fd.append('action', 'execute');
    fd.append('filename', selectedFile);
    fd.append('username', document.getElementById('restore-username').value);
    fd.append('password', document.getElementById('restore-password').value);
    fd.append('domains', JSON.stringify(domainData));
    fd.append('cf_override', JSON.stringify(cfOverride));
    fd.append('php_version', document.getElementById('restore-php-version').value);
    fd.append('import_db', hasDbImport ? '1' : '0');

    fetch('/api/restore', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            clearInterval(timer);
            if (data.success) {
                document.getElementById('restore-bar').style.width = '100%';
                document.getElementById('restore-bar').classList.remove('progress-bar-animated');
                document.getElementById('restore-bar').classList.add('bg-success');
                document.getElementById('restore-stage').textContent = 'Complete!';
                document.getElementById('restore-bar-pct').textContent = '100%';

                setTimeout(() => {
                    document.getElementById('restore-progress').classList.add('d-none');
                    document.getElementById('restore-result').classList.remove('d-none');
                    renderResult(data);
                }, 1000);
            } else {
                document.getElementById('restore-bar').classList.add('bg-danger');
                document.getElementById('restore-stage').textContent = 'Failed: ' + (data.error || 'Unknown error');
                showAlert(data.error || 'Restore failed.', 'danger');
            }
        })
        .catch(() => {
            clearInterval(timer);
            document.getElementById('restore-bar').classList.add('bg-danger');
            document.getElementById('restore-stage').textContent = 'Connection error.';
            showAlert('Restore failed — connection error.', 'danger');
        });
}

function renderResult(data) {
    const el = document.getElementById('result-details');
    let html = `
        <table class="table table-sm small mb-3">
            <tr><td class="text-muted">Username</td><td><strong>${data.username}</strong></td></tr>
            <tr><td class="text-muted">Password</td><td><code>${data.password}</code></td></tr>
        </table>
        <h6 class="fw-semibold">Domains</h6>
        <table class="table table-sm small mb-3">
            <thead><tr><th>Domain</th><th>Port</th><th>Cloudflare</th></tr></thead>
            <tbody>`;
    (data.domains || []).forEach(d => {
        const cfStatus = data.cf_results?.[d.domain]
            ? (data.cf_results[d.domain].success ? '<span class="badge bg-success">Routed</span>' : '<span class="badge bg-danger">Failed</span>')
            : '<span class="text-muted">—</span>';
        html += `<tr><td>${d.domain}</td><td>${d.port}</td><td>${cfStatus}</td></tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}
</script>
