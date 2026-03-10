<?php
// FILE: src/add_domain.php
// iNetPanel — Add a domain to an existing hosting user

// Fetch hosting users for the dropdown
$hostingUsers = DB::fetchAll('SELECT h.id, h.username, COUNT(d.id) as domain_count FROM hosting_users h LEFT JOIN domains d ON d.hosting_user_id = h.id GROUP BY h.id ORDER BY h.username');

// Fetch installed PHP versions
$phpVersions = [];
$allVersions = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4','8.5'];
foreach ($allVersions as $v) {
    if (file_exists("/usr/sbin/php-fpm{$v}")) {
        $phpVersions[] = $v;
    }
}
$phpDefault = DB::setting('php_default_version', '8.4');
if (empty($phpVersions)) {
    $phpVersions = [$phpDefault];
}
$cfEnabled = DB::setting('cf_enabled', '0') === '1';
$cfAccountId = DB::setting('cf_account_id', '');

// Pre-select user from query string (e.g. linked from accounts page)
$preselectedUser = trim($_GET['user'] ?? '');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Domain</h4>
    <a href="/admin/accounts" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<?php if (!$cfEnabled): ?>
<div class="alert alert-info py-2 small mb-3">
    <i class="fas fa-info-circle me-1"></i>Cloudflare is not configured. Domains will use self-signed SSL and direct port access.
    <a href="/admin/settings">Configure Cloudflare</a>
</div>
<?php endif; ?>

<?php if (empty($hostingUsers)): ?>
<div class="alert alert-warning mb-3">
    <i class="fas fa-exclamation-triangle me-1"></i>No hosting users found. <a href="/admin/add-account">Create an account</a> first.
</div>
<?php else: ?>

<div id="create-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form id="add-domain-form">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Owner <span class="text-danger">*</span></label>
                    <select class="form-select" id="username" name="username" required>
                        <option value="">-- Select a user --</option>
                        <?php foreach ($hostingUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['username']) ?>"
                            <?= $u['username'] === $preselectedUser ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?> (<?= (int)$u['domain_count'] ?> domain<?= $u['domain_count'] != 1 ? 's' : '' ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">The hosting user who will own this domain.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Domain Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="domain" name="domain"
                               placeholder="example.com or shop.example.com" autocomplete="off" required>
                        <?php if ($cfEnabled): ?>
                        <button class="btn btn-outline-primary" type="button" id="check-domain-btn" disabled>
                            <i class="fas fa-search me-1" id="check-icon"></i>
                            <span class="spinner-border spinner-border-sm d-none me-1" id="check-spinner"></span>
                            Check
                        </button>
                        <?php endif; ?>
                    </div>
                    <div id="domain-status" class="form-text">
                        <?php if ($cfEnabled): ?>
                            Enter a domain or subdomain, then click <strong>Check</strong> to verify Cloudflare availability.
                        <?php else: ?>
                            Each domain gets its own vhost, database, and SSL certificate.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">PHP Version</label>
                    <select class="form-select" id="php_version" name="php_version">
                        <?php foreach (array_reverse($phpVersions) as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $v === $phpDefault ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($cfEnabled): ?>
                <div class="col-md-6 d-flex align-items-end">
                    <div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="skip-cf" name="skip_cf" value="1">
                            <label class="form-check-label fw-semibold" for="skip-cf">Don't add to Cloudflare</label>
                        </div>
                        <div id="skip-cf-notice" class="form-text text-warning d-none">
                            <i class="fas fa-exclamation-triangle me-1"></i>Port will be opened in firewall for direct access. Self-signed SSL only.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-3 mt-4">
                <button type="submit" class="btn btn-primary px-5" id="submit-btn" <?= $cfEnabled ? 'disabled' : '' ?>>
                    <i class="fas fa-plus me-1" id="submit-icon"></i>Add Domain
                </button>
                <a href="/admin/accounts" class="btn btn-link text-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- Progress modal -->
<div class="modal fade" id="progress-modal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body p-4 text-center">
                <div class="spinner-border text-primary mb-3" style="width:2.5rem;height:2.5rem"></div>
                <h6 class="mb-2">Adding Domain</h6>
                <p class="small text-muted mb-3" id="progress-stage">Configuring vhost...</p>
                <div class="progress" style="height:6px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="progress-bar" style="width:5%"></div>
                </div>
                <div class="small text-muted mt-2" id="progress-pct">5%</div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
const cfEnabled = <?= $cfEnabled ? 'true' : 'false' ?>;
const cfAccountId = <?= json_encode($cfAccountId) ?>;
let domainChecked = false;
const domainPattern = /^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?\.[a-zA-Z]{2,}$/;

if (document.getElementById('add-domain-form')) {

// ---- Domain status display ----
function setDomainStatus(type, message) {
    const status = document.getElementById('domain-status');
    if (type === 'ok') {
        status.className = 'form-text text-success';
        status.innerHTML = '<i class="fas fa-check-circle me-1"></i>' + message;
    } else if (type === 'warn') {
        status.className = 'form-text text-warning';
        status.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>' + message;
    } else if (type === 'error') {
        status.className = 'form-text text-danger';
        status.innerHTML = '<i class="fas fa-times-circle me-1"></i>' + message;
    } else if (type === 'loading') {
        status.className = 'form-text text-muted';
        status.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.75rem;height:.75rem"></span>' + message;
    } else {
        status.className = 'form-text';
        status.innerHTML = message;
    }
}

function isValidDomain(val) {
    return val.length >= 4 && domainPattern.test(val);
}

function updateCheckButton() {
    if (!cfEnabled) return;
    const val = document.getElementById('domain').value.trim();
    const btn = document.getElementById('check-domain-btn');
    const skipCf = document.getElementById('skip-cf')?.checked;
    btn.disabled = skipCf || !isValidDomain(val);
}

function updateSubmitState() {
    if (!cfEnabled || document.getElementById('skip-cf')?.checked) {
        document.getElementById('submit-btn').disabled = !document.getElementById('username').value;
    } else {
        document.getElementById('submit-btn').disabled = !domainChecked || !document.getElementById('username').value;
    }
}

// ---- User selector ----
document.getElementById('username').addEventListener('change', updateSubmitState);

// ---- Domain input ----
document.getElementById('domain').addEventListener('input', function () {
    domainChecked = false;
    updateCheckButton();
    updateSubmitState();
    const val = this.value.trim();
    if (!val) {
        setDomainStatus('', cfEnabled
            ? 'Enter a domain or subdomain, then click <strong>Check</strong> to verify Cloudflare availability.'
            : 'Each domain gets its own vhost, database, and SSL certificate.');
    } else if (!isValidDomain(val)) {
        setDomainStatus('', '<small>Enter a valid domain (e.g. example.com or shop.example.com)</small>');
    } else if (cfEnabled && !document.getElementById('skip-cf')?.checked) {
        setDomainStatus('', 'Click <strong>Check</strong> to verify availability.');
    } else {
        setDomainStatus('', 'Each domain gets its own vhost, database, and SSL certificate.');
    }
});

// ---- Skip CF checkbox ----
if (cfEnabled) {
    document.getElementById('skip-cf').addEventListener('change', function () {
        document.getElementById('skip-cf-notice').classList.toggle('d-none', !this.checked);
        if (this.checked) {
            document.getElementById('check-domain-btn').disabled = true;
            domainChecked = true;
            setDomainStatus('', 'Domain will be created with self-signed SSL and direct port access.');
        } else {
            domainChecked = false;
            updateCheckButton();
            const val = document.getElementById('domain').value.trim();
            if (isValidDomain(val)) {
                setDomainStatus('', 'Click <strong>Check</strong> to verify availability.');
            }
        }
        updateSubmitState();
    });

    // ---- Check domain button ----
    document.getElementById('check-domain-btn').addEventListener('click', function () {
        const domain = document.getElementById('domain').value.trim();
        if (!domain) return;
        const btn = this;
        const icon = document.getElementById('check-icon');
        const spinner = document.getElementById('check-spinner');
        btn.disabled = true;
        icon.classList.add('d-none');
        spinner.classList.remove('d-none');
        setDomainStatus('loading', 'Checking domain availability...');

        fetch('/api/accounts?action=check_domain&domain=' + encodeURIComponent(domain))
            .then(r => r.json())
            .then(data => {
                icon.classList.remove('d-none');
                spinner.classList.add('d-none');
                btn.disabled = false;
                if (!data.success) {
                    setDomainStatus('error', data.error || 'Check failed.');
                    domainChecked = false;
                } else if (!data.available) {
                    setDomainStatus('error', data.reason || 'Domain not available.');
                    domainChecked = false;
                } else if (!data.cf_managed) {
                    const addUrl = cfAccountId
                        ? 'https://dash.cloudflare.com/' + cfAccountId + '/add-site'
                        : 'https://dash.cloudflare.com/';
                    setDomainStatus('warn',
                        (data.warning || 'Domain zone not found in Cloudflare.') +
                        ' <a href="' + addUrl + '" target="_blank" class="text-warning fw-semibold">Add to Cloudflare <i class="fas fa-external-link-alt fa-xs"></i></a>'
                    );
                    domainChecked = false;
                } else {
                    setDomainStatus('ok', 'Domain available and managed by Cloudflare.');
                    domainChecked = true;
                }
                updateSubmitState();
            })
            .catch(err => {
                icon.classList.remove('d-none');
                spinner.classList.add('d-none');
                btn.disabled = false;
                setDomainStatus('error', 'Check failed: ' + (err.message || 'Network error'));
                domainChecked = false;
                updateSubmitState();
            });
    });
}

// ---- Progress modal ----
let progressModal = null;
const progressBar = document.getElementById('progress-bar');
const progressStage = document.getElementById('progress-stage');
const progressPct = document.getElementById('progress-pct');
let progressTimer = null;

function getProgressModal() {
    if (!progressModal) progressModal = new bootstrap.Modal(document.getElementById('progress-modal'));
    return progressModal;
}

function showProgress() {
    const skipCf = cfEnabled && document.getElementById('skip-cf')?.checked;
    const stages = skipCf ? [
        { pct: 15,  text: 'Configuring Apache vhost...',    delay: 0 },
        { pct: 35,  text: 'Setting up PHP-FPM pool...',     delay: 3000 },
        { pct: 55,  text: 'Creating database...',           delay: 6000 },
        { pct: 75,  text: 'Issuing SSL certificate...',     delay: 9000 },
        { pct: 90,  text: 'Opening firewall port...',       delay: 13000 },
        { pct: 95,  text: 'Finalizing...',                  delay: 16000 },
    ] : [
        { pct: 15,  text: 'Configuring Apache vhost...',    delay: 0 },
        { pct: 30,  text: 'Setting up PHP-FPM pool...',     delay: 3000 },
        { pct: 45,  text: 'Creating database...',           delay: 6000 },
        { pct: 55,  text: 'Issuing SSL certificate...',     delay: 9000 },
        { pct: 70,  text: 'Adding to Cloudflare tunnel...', delay: 13000 },
        { pct: 85,  text: 'Configuring DNS...',             delay: 19000 },
        { pct: 95,  text: 'Finalizing...',                  delay: 24000 },
    ];
    progressBar.style.width = '5%';
    progressStage.textContent = 'Starting...';
    progressPct.textContent = '5%';
    getProgressModal().show();
    const timers = [];
    stages.forEach(s => {
        timers.push(setTimeout(() => {
            progressBar.style.width = s.pct + '%';
            progressStage.textContent = s.text;
            progressPct.textContent = s.pct + '%';
        }, s.delay));
    });
    progressTimer = timers;
}

function hideProgress(success) {
    if (progressTimer) { progressTimer.forEach(t => clearTimeout(t)); progressTimer = null; }
    if (success) {
        progressBar.style.width = '100%';
        progressStage.textContent = 'Done!';
        progressPct.textContent = '100%';
        setTimeout(() => getProgressModal().hide(), 400);
    } else {
        getProgressModal().hide();
    }
}

// ---- Form submit ----
document.getElementById('add-domain-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const username = document.getElementById('username').value;
    const domain = document.getElementById('domain').value.trim();
    const alert = document.getElementById('create-alert');
    const skipCf = cfEnabled && document.getElementById('skip-cf')?.checked;

    if (!username) {
        alert.className = 'alert alert-danger'; alert.textContent = 'Select an owner.'; alert.classList.remove('d-none');
        return;
    }
    if (cfEnabled && !skipCf && !domainChecked) {
        alert.className = 'alert alert-warning'; alert.textContent = 'Please check the domain availability first.'; alert.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    alert.className = 'd-none';

    const fd = new FormData();
    fd.append('action', 'add_domain');
    fd.append('username', username);
    fd.append('domain', domain);
    fd.append('php_version', document.getElementById('php_version').value);
    if (skipCf) fd.append('skip_cf', '1');

    showProgress();

    fetch('/api/accounts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                hideProgress(true);
                setTimeout(() => {
                    alert.className = 'alert alert-success';
                    alert.classList.remove('d-none');
                    let msg = `<strong>Domain added!</strong> <code>${domain}</code> on port <code>${data.port ?? '—'}</code> assigned to <code>${username}</code>.`;
                    if (skipCf) msg += ' <span class="badge bg-secondary">Direct access</span>';
                    msg += ` <a href="/admin/accounts">View all accounts &rarr;</a>`;
                    if (data.warnings && data.warnings.length) {
                        msg += '<div class="alert alert-warning mt-2 mb-0 py-2 small">' + data.warnings.map(w => `<div>${w}</div>`).join('') + '</div>';
                    }
                    alert.innerHTML = msg;
                }, 500);
                document.getElementById('domain').value = '';
                domainChecked = false;
                if (cfEnabled) {
                    document.getElementById('skip-cf-notice').classList.add('d-none');
                    document.getElementById('skip-cf').checked = false;
                    updateCheckButton();
                }
                updateSubmitState();
                setDomainStatus('', cfEnabled
                    ? 'Enter a domain or subdomain, then click <strong>Check</strong> to verify Cloudflare availability.'
                    : 'Each domain gets its own vhost, database, and SSL certificate.');
            } else {
                hideProgress(false);
                alert.className = 'alert alert-danger';
                alert.classList.remove('d-none');
                alert.textContent = data.error || 'Failed to add domain.';
            }
        })
        .catch(err => {
            btn.disabled = false;
            hideProgress(false);
            alert.className = 'alert alert-danger';
            alert.classList.remove('d-none');
            alert.innerHTML = '<strong>Request failed:</strong> ' + (err.message || 'Unknown error') + '<br><small class="text-muted">The domain may have been created — check <a href="/admin/accounts">Accounts</a> and <a href="/admin/logs">Panel Logs</a>.</small>';
        });
});

// Init state if user is preselected
updateSubmitState();

} // end if form exists
</script>
