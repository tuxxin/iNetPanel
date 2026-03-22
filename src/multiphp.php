<?php
// FILE: src/multiphp.php
// iNetPanel — Multi-PHP Manager (real data)


Auth::requireAdmin();

$allVersions = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4','8.5'];

// Detect installed versions
$installed = [];
foreach ($allVersions as $v) {
    if (file_exists("/usr/sbin/php-fpm{$v}")) {
        $installed[] = $v;
    }
}

// Current system default
$systemDefault = DB::setting('php_default_version', '8.4');

// All domains with their PHP versions
$domains = [];
try {
    $domains = DB::fetchAll('SELECT domain_name, php_version FROM domains WHERE status = ? ORDER BY domain_name', ['active']);
} catch (\Throwable $e) {}
?>

<h4 class="mb-4"><i class="fab fa-php me-2"></i>Multi-PHP Manager</h4>

<div id="multiphp-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom-0 pt-3">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-versions" type="button">PHP Versions</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-selection" type="button">Version Selection</button>
            </li>
        </ul>
    </div>

    <div class="card-body p-4">
        <div class="tab-content">

            <!-- TAB 1: Versions -->
            <div class="tab-pane fade show active" id="tab-versions">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">Available PHP Versions</h6>
                <div class="row g-3" id="versions-grid">
                    <?php foreach ($allVersions as $v):
                        $isInstalled  = in_array($v, $installed);
                        $isProtected  = ($v === $systemDefault);
                    ?>
                    <div class="col-md-3 col-6">
                        <div class="p-3 border rounded d-flex justify-content-between align-items-center <?= $isInstalled ? 'bg-success-subtle border-success-subtle' : 'bg-light' ?> h-100" id="php-card-<?= str_replace('.', '', $v) ?>">
                            <span class="fw-bold">PHP <?= htmlspecialchars($v) ?></span>
                            <?php if ($isInstalled): ?>
                                <div class="d-flex gap-1 align-items-center">
                                    <span class="badge bg-success"><i class="fas fa-check"></i> Installed</span>
                                    <?php if ($isProtected): ?>
                                        <span class="badge bg-primary ms-1" title="This is the panel default — change the default before removing">Required</span>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="toggleVersion('<?= $v ?>', 'remove')" title="Remove">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="toggleVersion('<?= $v ?>', 'install')">
                                    <i class="fas fa-download me-1"></i>Install
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 p-3 bg-primary-subtle rounded border border-primary-subtle text-primary-emphasis small">
                    <i class="fas fa-info-circle me-1"></i>Installing a new PHP version downloads and configures PHP-FPM. This may take a minute.
                </div>
            </div>

            <!-- TAB 2: Version Selection -->
            <div class="tab-pane fade" id="tab-selection">
                <!-- System Default -->
                <div class="mb-4">
                    <h6 class="fw-semibold">System Default Version</h6>
                    <p class="text-muted small">Used for new accounts unless overridden per domain.</p>
                    <div class="row align-items-end g-2">
                        <div class="col-md-4">
                            <select class="form-select" id="system-default-select">
                                <?php foreach (array_reverse($installed) as $v): ?>
                                <option value="<?= $v ?>" <?= $v === $systemDefault ? 'selected' : '' ?>>PHP <?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-primary" onclick="saveSystemDefault()">Save Default</button>
                        </div>
                    </div>
                </div>

                <hr class="text-muted opacity-25">

                <!-- Per-domain overrides -->
                <h6 class="fw-semibold mt-4">Per-Domain PHP Version</h6>
                <p class="text-muted small mb-3">Override the PHP version for a specific account.</p>

                <?php if (empty($domains)): ?>
                <p class="text-muted small">No active accounts.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Domain</th>
                                <th width="35%">PHP Version</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domains as $d):
                                $cur = $d['php_version'] ?? $systemDefault;
                            ?>
                            <tr>
                                <td class="fw-medium"><?= htmlspecialchars($d['domain_name']) ?></td>
                                <td>
                                    <select class="form-select form-select-sm domain-php-sel" data-domain="<?= htmlspecialchars($d['domain_name']) ?>">
                                        <?php foreach (array_reverse($installed) as $v): ?>
                                        <option value="<?= $v ?>" <?= $v === $cur ? 'selected' : '' ?>>PHP <?= htmlspecialchars($v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" onclick="setDomainPhp('<?= htmlspecialchars($d['domain_name']) ?>', this)">Apply</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Progress modal -->
<div class="modal fade" id="phpModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="php-modal-title">Working…</h5>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="spinner-border text-primary"></div>
                    <span id="php-modal-msg">Please wait…</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PANEL_DEFAULT_PHP = '<?= htmlspecialchars($systemDefault) ?>';

function phpVerGt(a, b) {
    const pa = a.split('.').map(Number), pb = b.split('.').map(Number);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const d = (pa[i] ?? 0) - (pb[i] ?? 0);
        if (d !== 0) return d > 0;
    }
    return false;
}

function showAlert(msg, type = 'success') {
    const el = document.getElementById('multiphp-alert');
    el.className = `alert alert-${type}`;
    el.textContent = msg;
    setTimeout(() => el.className = 'd-none', 5000);
}

function toggleVersion(ver, action) {
    if (phpVerGt(ver, PANEL_DEFAULT_PHP)) {
        const msg = `⚠️ PHP ${ver} is newer than the panel default (PHP ${PANEL_DEFAULT_PHP}).\n\n` +
            `${action === 'install' ? 'Installing' : 'Removing'} a version newer than the system default ` +
            `may cause instability or break the system entirely.\n\nProceed with caution — continue?`;
        if (!confirm(msg)) return;
    }
    const modal = new bootstrap.Modal(document.getElementById('phpModal'));
    document.getElementById('php-modal-title').textContent = action === 'install' ? `Installing PHP ${ver}…` : `Removing PHP ${ver}…`;
    document.getElementById('php-modal-msg').textContent = 'This may take a minute. Please wait.';
    modal.show();
    const fd = new FormData();
    fd.append('action', action);
    fd.append('version', ver);
    fetch('/api/multiphp', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('php-modal-msg').textContent =
                    `PHP ${ver} ${action} in progress. This may take a minute…`;
                // Poll until the version state changes (installed/removed), then reload
                const wantInstalled = (action === 'install');
                let attempts = 0;
                const poll = setInterval(() => {
                    attempts++;
                    fetch('/api/multiphp', { method: 'POST', body: (() => { const f = new FormData(); f.append('action','list'); return f; })() })
                        .then(r => r.ok ? r.json() : null)
                        .then(d => {
                            if (!d || !d.success) return;
                            const found = (d.versions || []).find(v => v.version === ver);
                            if (found && found.installed === wantInstalled) {
                                clearInterval(poll);
                                modal.hide();
                                showAlert(`PHP ${ver} ${action}ed successfully.`);
                                setTimeout(() => location.reload(), 500);
                            }
                        })
                        .catch(() => {});
                    if (attempts > 60) {
                        clearInterval(poll);
                        modal.hide();
                        showAlert(`PHP ${ver} ${action} may still be running. Reload the page.`, 'warning');
                    }
                }, 3000);
            } else {
                modal.hide();
                showAlert(data.error || `${action} failed.`, 'danger');
            }
        })
        .catch(() => { modal.hide(); showAlert('Request failed.', 'danger'); });
}

function saveSystemDefault() {
    const ver = document.getElementById('system-default-select').value;
    const fd = new FormData();
    fd.append('action', 'set_default');
    fd.append('version', ver);
    fetch('/api/multiphp', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) showAlert(`System default set to PHP ${ver}.`);
            else showAlert(data.error || 'Save failed.', 'danger');
        });
}

function setDomainPhp(domain, btn) {
    const row = btn.closest('tr');
    const ver = row.querySelector('.domain-php-sel').value;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_domain');
    fd.append('domain', domain);
    fd.append('version', ver);
    fetch('/api/multiphp', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) showAlert(`PHP version for '${domain}' set to ${ver}.`);
            else showAlert(data.error || 'Update failed.', 'danger');
        });
}
</script>
