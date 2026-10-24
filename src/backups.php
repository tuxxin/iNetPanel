<?php
// FILE: src/backups.php
// iNetPanel — Backup Manager (real data)

Auth::requireAdmin();

// Load current settings
$backupEnabled     = DB::setting('backup_enabled', '1');
$backupDest        = DB::setting('backup_destination', '/backup');
$backupRetention   = DB::setting('backup_retention', '3');

// Remote backup (S3). The secret key is never read back: only whether one is saved.
// The default folder carries a short per-server id as well as the host name:
// two servers left at a default host name like "debian" must not share a folder.
// s3_backup.py tags uploads with the same id, so it is created here if missing.
$s3ServerId = DB::setting('s3_server_id', '');
if (!preg_match('/^[0-9a-f]{32}\z/', $s3ServerId)) {
    $s3ServerId = bin2hex(random_bytes(16));
    DB::saveSetting('s3_server_id', $s3ServerId);
}
$s3DefaultPrefix = 'inetpanel/' . (trim(preg_replace('/[^a-z0-9.-]+/', '-',
    strtolower(DB::setting('server_hostname', '') ?: (gethostname() ?: 'server'))), '-.') ?: 'server')
    . '-' . substr($s3ServerId, 0, 6);
$s3 = [
    'enabled'     => DB::setting('s3_enabled', '0'),
    'endpoint'    => DB::setting('s3_endpoint', ''),
    'region'      => DB::setting('s3_region', 'us-east-1'),
    'bucket'      => DB::setting('s3_bucket', ''),
    'prefix'      => DB::setting('s3_prefix', null) ?? $s3DefaultPrefix,
    'access_key'  => DB::setting('s3_access_key', ''),
    'path_style'  => DB::setting('s3_path_style', 'auto'),
    'ret_enabled' => DB::setting('s3_retention_enabled', '0'),
    'ret_days'    => DB::setting('s3_retention_days', '30'),
    'bw_mbit'     => DB::setting('s3_bandwidth_mbit', '0'),
    'inc_system'  => DB::setting('s3_include_system', '1'),
    'secret_set'  => DB::setting('s3_secret_set', '0') === '1',
];
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
                    <table class="table table-hover align-middle mb-0" id="backups-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">File</th>
                                <th>Size</th>
                                <th>Date</th>
                                <th class="text-end pe-4 no-sort">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="backup-tbody">
                            <tr><td colspan="4" class="text-center text-muted py-4">Loading…</td></tr>
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

    <!-- Remote backup (S3) -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-cloud-upload-alt me-2"></i>Remote Backup (S3)</h6>
                <span id="s3-badge" class="badge <?= $s3['enabled'] === '1' ? 'bg-success' : 'bg-secondary' ?>"><?= $s3['enabled'] === '1' ? 'On' : 'Off' ?></span>
            </div>
            <div class="card-body p-4">
                <div id="s3-status" class="small mb-3 text-muted">Loading status…</div>

                <form id="s3-form" autocomplete="off">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-4 border">
                        <div>
                            <div class="fw-semibold small">Upload backups to an S3 bucket</div>
                            <div class="text-muted" style="font-size:.75rem">Every finished backup is copied off-site after each run. Works with AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and MinIO.</div>
                        </div>
                        <div class="form-check form-switch mb-0 ms-3">
                            <input class="form-check-input" type="checkbox" id="s3-enabled"
                                   <?= $s3['enabled'] === '1' ? 'checked' : '' ?> style="width:2.5em;height:1.3em">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="s3-endpoint">Endpoint URL</label>
                            <input type="text" class="form-control" id="s3-endpoint" spellcheck="false"
                                   placeholder="Leave blank for Amazon S3"
                                   value="<?= htmlspecialchars($s3['endpoint']) ?>">
                            <div class="form-text">For other providers, e.g. <code>https://&lt;account-id&gt;.r2.cloudflarestorage.com</code>, <code>https://s3.us-west-004.backblazeb2.com</code>, <code>https://s3.wasabisys.com</code>. Plain <code>http://</code> works only for a storage server elsewhere on your private network.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" for="s3-region">Region</label>
                            <input type="text" class="form-control" id="s3-region" spellcheck="false"
                                   value="<?= htmlspecialchars($s3['region']) ?>">
                            <div class="form-text">Use <code>auto</code> for Cloudflare R2.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" for="s3-path-style">Addressing</label>
                            <select class="form-select" id="s3-path-style">
                                <?php foreach (['auto' => 'Automatic', 'path' => 'Path-style', 'virtual' => 'Virtual-hosted'] as $v => $label): ?>
                                <option value="<?= $v ?>" <?= $s3['path_style'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Automatic suits almost everyone.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="s3-bucket">Bucket</label>
                            <input type="text" class="form-control" id="s3-bucket" spellcheck="false"
                                   value="<?= htmlspecialchars($s3['bucket']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="s3-prefix">Folder in the bucket</label>
                            <input type="text" class="form-control" id="s3-prefix" spellcheck="false"
                                   value="<?= htmlspecialchars($s3['prefix']) ?>">
                            <div class="form-text">Backups are stored as <code>folder/account_YYYY-MM-DD.tgz</code>. Give each server its own folder.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="s3-access-key">Access key ID</label>
                            <input type="text" class="form-control" id="s3-access-key" spellcheck="false"
                                   value="<?= htmlspecialchars($s3['access_key']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="s3-secret">Secret access key</label>
                            <input type="password" class="form-control" id="s3-secret" autocomplete="new-password"
                                   placeholder="<?= $s3['secret_set'] ? 'Saved — leave blank to keep it' : '' ?>">
                            <div class="form-text">Stored where only root can read it, never in the panel database.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" for="s3-bw">Upload speed limit</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="s3-bw" min="0" max="100000"
                                       value="<?= (int)$s3['bw_mbit'] ?>">
                                <span class="input-group-text">Mbit/s</span>
                            </div>
                            <div class="form-text">0 = no limit. Uploads share your connection with the sites you host — set a limit if they slow down while backups upload.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small d-block">Server configuration</label>
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" id="s3-inc-system"
                                       <?= $s3['inc_system'] !== '0' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="s3-inc-system">Upload the server configuration backup too</label>
                            </div>
                            <div class="form-text">Needed to rebuild this server from scratch. It contains the panel database (including your Cloudflare API key and the activity log), web server, PHP and database configuration, and WireGuard private keys — use a bucket only you can read.</div>
                        </div>

                        <div class="col-12">
                            <div class="p-3 border rounded">
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="s3-ret-enabled"
                                               <?= $s3['ret_enabled'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label small fw-semibold" for="s3-ret-enabled">Delete old backups from the bucket</label>
                                    </div>
                                    <div class="input-group input-group-sm" style="width:auto">
                                        <span class="input-group-text">older than</span>
                                        <input type="number" class="form-control" id="s3-ret-days" min="1" max="3650"
                                               style="width:6em" value="<?= (int)$s3['ret_days'] ?>">
                                        <span class="input-group-text">days</span>
                                    </div>
                                </div>
                                <div class="form-text mb-0">Checked after every upload run. Only backups this server uploaded to this folder are ever deleted, and the newest backup of each account is always kept. Pre-deletion backups (<code>deleted/</code>) are never removed. When this is off, nothing is deleted from the bucket. If the bucket keeps old versions (versioning), also add a lifecycle rule to expire them.</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" id="s3-save-btn">
                            <i class="fas fa-save me-1"></i>Save
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="s3-test-btn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="s3-test-spinner"></span>
                            <i class="fas fa-plug me-1" id="s3-test-icon"></i>Test Connection
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="s3-upload-btn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="s3-upload-spinner"></span>
                            <i class="fas fa-cloud-upload-alt me-1" id="s3-upload-icon"></i>Upload Now
                        </button>
                        <span id="s3-save-msg" class="align-self-center small"></span>
                    </div>
                    <div id="s3-test-result" class="mt-3 small"></div>
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
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No backups found.</td></tr>'; return;
            }
            tbody.innerHTML = data.data.map(b =>
                `<tr>
                    <td class="ps-4 small fw-medium"><i class="fas fa-file-archive text-muted me-2"></i>${b.filename}</td>
                    <td class="small">${b.size_hr}</td>
                    <td class="small text-muted">${b.date}</td>
                    <td class="text-end pe-4"><a href="/api/backups?action=download&file=${encodeURIComponent(b.filename)}" class="btn btn-sm btn-outline-primary" title="Download"><i class="fas fa-download"></i></a></td>
                </tr>`
            ).join('');
        })
        .catch(() => {
            document.getElementById('backup-tbody').innerHTML =
                '<tr><td colspan="4" class="text-center text-danger py-4">Failed to load.</td></tr>';
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
                s3Expect = 3;     // the run starts the remote upload when that is on
                loadS3Status();
            } else {
                showAlert(data.error || 'Backup failed.', 'danger');
            }
        })
        .catch(err => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            this.disabled = false;
            showAlert('Request failed: ' + err.message, 'danger');
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

// ── Remote backup (S3) ──────────────────────────────────────────────────────
// Status and test text can come from the storage provider, so every value is
// escaped before it reaches innerHTML.
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
}

function fmtBytes(n) {
    n = Number(n) || 0;
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(1) : n) + ' ' + u[i];
}

function s3FormData(action) {
    const v = id => document.getElementById(id).value.trim();
    const fd = new FormData();
    fd.append('action', action);
    fd.append('s3_enabled',           document.getElementById('s3-enabled').checked ? '1' : '0');
    fd.append('s3_endpoint',          v('s3-endpoint'));
    fd.append('s3_region',            v('s3-region'));
    fd.append('s3_path_style',        v('s3-path-style'));
    fd.append('s3_bucket',            v('s3-bucket'));
    fd.append('s3_prefix',            v('s3-prefix'));
    fd.append('s3_access_key',        v('s3-access-key'));
    fd.append('s3_secret',            document.getElementById('s3-secret').value);
    fd.append('s3_retention_enabled', document.getElementById('s3-ret-enabled').checked ? '1' : '0');
    fd.append('s3_retention_days',    v('s3-ret-days'));
    fd.append('s3_bandwidth_mbit',    v('s3-bw'));
    fd.append('s3_include_system',    document.getElementById('s3-inc-system').checked ? '1' : '0');
    return fd;
}

let s3Poll = null;
let s3Expect = 0;   // re-checks left while waiting for a just-started upload to report in
let s3WasRunning = false;

function renderS3Status(d) {
    const el    = document.getElementById('s3-status');
    const badge = document.getElementById('s3-badge');
    const st    = d.status;
    badge.className   = 'badge ' + (d.enabled ? 'bg-success' : 'bg-secondary');
    badge.textContent = d.enabled ? 'On' : 'Off';
    el.className = 'small mb-3';

    if (!st) {
        el.classList.add('text-muted');
        el.textContent = d.enabled
            ? 'No upload has run yet. One starts after the next backup, or click Upload Now.'
            : 'Remote backup is off.';
        return;
    }
    const icons = {
        ok:          'fa-check-circle text-success',
        partial:     'fa-exclamation-triangle text-warning',
        failed:      'fa-times-circle text-danger',
        interrupted: 'fa-exclamation-triangle text-warning',
    };
    let html = '';
    if (st.state === 'running' && d.running) {
        const cur = st.current;
        const pct = cur && cur.size ? Math.min(100, Math.floor(cur.done * 100 / cur.size)) : 0;
        html = '<div class="d-flex align-items-center mb-2"><span class="spinner-border spinner-border-sm text-primary me-2"></span>'
             + '<strong>Uploading</strong>&nbsp;'
             + (cur ? esc(cur.file) + ' — ' + pct + '% of ' + fmtBytes(cur.size) : 'checking what is new')
             + (st.pending > 1 ? ' · ' + (st.pending - 1) + ' more waiting' : '')
             + '</div>'
             + (cur ? '<div class="progress mb-2" style="height:6px"><div class="progress-bar" style="width:' + pct + '%"></div></div>' : '')
             + '<div class="text-muted">' + (Number(st.uploaded) || 0) + ' done so far (' + fmtBytes(st.bytes) + ') · started ' + esc(st.started) + '</div>'
             + (st.note ? '<div class="text-warning mt-1"><i class="fas fa-redo me-1"></i>' + esc(st.note) + '</div>' : '');
    } else if (st.state === 'interrupted') {
        html = '<i class="fas ' + icons.interrupted + ' me-1"></i>The last upload stopped without finishing (last seen '
             + esc(st.heartbeat) + '), e.g. by a reboot. It carries on with the next backup run, or click Upload Now.';
    } else {
        html = '<i class="fas ' + (icons[st.state] || 'fa-info-circle text-muted') + ' me-1"></i>'
             + '<strong>Last upload ' + esc(st.finished || st.started) + ':</strong> ' + esc(st.message);
    }
    if ((st.state === 'failed' || st.state === 'partial') && st.retry) {
        html += '<div class="text-muted mt-1">It is retried automatically every hour, or click Upload Now.</div>';
    }
    if (st.errors && st.errors.length && st.state !== 'running') {
        html += '<ul class="mb-0 mt-1 text-danger">' + st.errors.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul>';
    }
    if (st.state !== 'ok' && st.last_success) {
        html += '<div class="text-muted mt-1">Last complete upload: ' + esc(st.last_success) + '</div>';
    }
    if (st.destination) {
        html += '<div class="text-muted mt-1">Destination: <code>' + esc(st.destination) + '</code></div>';
    }
    el.innerHTML = html;
}

function loadS3Status() {
    clearTimeout(s3Poll);
    fetch('/api/backups?action=s3_status')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            renderS3Status(data.data);
            s3WasRunning = !!data.data.running;
            if (data.data.running) {
                s3Expect = 0;
                s3Poll = setTimeout(loadS3Status, 5000);
            } else if (s3Expect > 0) {
                s3Expect--;
                s3Poll = setTimeout(loadS3Status, 2000);
            }
        })
        .catch(() => {
            // One failed poll must not freeze the progress display.
            if (s3WasRunning) s3Poll = setTimeout(loadS3Status, 10000);
        });
}

function s3Busy(name, busy) {
    document.getElementById('s3-' + name + '-btn').disabled = busy;
    document.getElementById('s3-' + name + '-spinner').classList.toggle('d-none', !busy);
    document.getElementById('s3-' + name + '-icon').classList.toggle('d-none', busy);
}

function s3Msg(text, ok) {
    const el = document.getElementById('s3-save-msg');
    el.className = 'align-self-center small ' + (ok ? 'text-success' : 'text-danger');
    el.textContent = text;
}

document.getElementById('s3-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('s3-save-btn');
    btn.disabled = true;
    s3Msg('', true);
    fetch('/api/backups', { method: 'POST', body: s3FormData('s3_save') })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                const sec = document.getElementById('s3-secret');
                sec.value = '';
                if (data.secret_set) sec.placeholder = 'Saved — leave blank to keep it';
                s3Msg('Saved.', true);
                loadS3Status();
            } else {
                s3Msg(data.error || 'Save failed.', false);
            }
        })
        .catch(err => { btn.disabled = false; s3Msg('Request failed: ' + err.message, false); });
});

document.getElementById('s3-test-btn').addEventListener('click', function () {
    const out = document.getElementById('s3-test-result');
    s3Busy('test', true);
    out.innerHTML = '<span class="text-muted">Testing — this writes, reads back and deletes a small test file…</span>';
    fetch('/api/backups', { method: 'POST', body: s3FormData('s3_test') })
        .then(r => r.json())
        .then(j => {
            s3Busy('test', false);
            const steps = j.steps || [];
            const icon  = { ok: 'fa-check text-success', warn: 'fa-exclamation-triangle text-warning', error: 'fa-times text-danger' };
            out.innerHTML = '<div class="alert ' + (j.success ? 'alert-success' : 'alert-danger') + ' py-2 mb-2">'
                + esc(j.success ? j.message : (j.message || j.error || 'Test failed.')) + '</div>'
                + (steps.length ? '<ul class="list-unstyled mb-1">' + steps.map(s =>
                    '<li><i class="fas ' + (icon[s.level] || icon.error) + ' me-2"></i><strong>' + esc(s.name)
                    + '</strong> — ' + esc(s.detail) + '</li>').join('') + '</ul>' : '')
                + (j.endpoint ? '<div class="text-muted">' + esc(j.endpoint) + ' (' + esc(j.style) + '-style) · '
                    + (Number(j.elapsed_ms) || 0) + ' ms</div>' : '');
        })
        .catch(err => { s3Busy('test', false); out.innerHTML = '<div class="alert alert-danger py-2 mb-0">Request failed: ' + esc(err.message) + '</div>'; });
});

document.getElementById('s3-upload-btn').addEventListener('click', function () {
    s3Busy('upload', true);
    const fd = new FormData();
    fd.append('action', 's3_upload');
    fetch('/api/backups', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => {
            s3Busy('upload', false);
            if (j.success) {
                s3Msg(j.started === false ? (j.message || 'An upload is already running.') : 'Upload started.', true);
                s3Expect = 5;
                loadS3Status();
            } else {
                s3Msg(j.error || 'Could not start the upload.', false);
            }
        })
        .catch(err => { s3Busy('upload', false); s3Msg('Request failed: ' + err.message, false); });
});

document.addEventListener('DOMContentLoaded', function () {
    loadBackups();
    loadS3Status();
    TableKit.init('backups-table', { filter: true });
});
</script>
