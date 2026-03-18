<?php
// FILE: src/add_account.php
// iNetPanel — Create new hosting account (smart domain input with Check button)

// Fetch installed PHP versions for the dropdown
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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-globe me-2"></i>Add New Account</h4>
    <a href="/admin/accounts" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<!-- CF disabled banner -->
<?php if (!$cfEnabled): ?>
<div class="alert alert-info py-2 small mb-3">
    <i class="fas fa-info-circle me-1"></i>Cloudflare is not configured. Domains will use self-signed SSL and direct port access.
    <a href="/admin/settings">Configure Cloudflare</a>
</div>
<?php endif; ?>

<!-- Result alert -->
<div id="create-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form id="create-form">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           placeholder="Auto-generated from domain if blank" autocomplete="one-time-code" readonly onfocus="this.removeAttribute('readonly')"
                           pattern="[a-z][a-z0-9\-]{0,31}">
                    <div class="form-text">Hosting user account. Leave blank to auto-generate from domain. If user exists, domain is added to them.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Domain Name <span class="text-danger">*</span></label>

                    <!-- Domain input with Check button -->
                    <div class="input-group">
                        <input type="text" class="form-control" id="domain" name="domain"
                               placeholder="example.com or shop.example.com" autocomplete="one-time-code" readonly onfocus="this.removeAttribute('readonly')" required>
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

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Min 8 characters" autocomplete="new-password" required minlength="8">
                        <button class="btn btn-outline-secondary" type="button" id="toggle-pass" title="Show/hide">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-outline-secondary" type="button" id="gen-pass" title="Generate">
                            <i class="fas fa-dice"></i>
                        </button>
                    </div>
                    <div class="form-text">FTP / SSH password for this account.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="password2" name="password2"
                           placeholder="Repeat password" autocomplete="new-password" required minlength="8">
                </div>
            </div>

            <div class="d-flex align-items-center gap-3 mt-4">
                <button type="submit" class="btn btn-primary px-5" id="submit-btn" <?= $cfEnabled ? 'disabled' : '' ?>>
                    <i class="fas fa-plus me-1" id="submit-icon"></i>Create Account
                </button>
                <a href="/admin/accounts" class="btn btn-link text-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- Account creation progress modal -->
<div class="modal fade" id="progress-modal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body p-4 text-center">
                <div class="spinner-border text-primary mb-3" style="width:2.5rem;height:2.5rem"></div>
                <h6 class="mb-2">Creating Account</h6>
                <p class="small text-muted mb-3" id="progress-stage">Setting up hosting user...</p>
                <div class="progress" style="height:6px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="progress-bar" style="width:5%"></div>
                </div>
                <div class="small text-muted mt-2" id="progress-pct">5%</div>
            </div>
        </div>
    </div>
</div>

<script>
const cfEnabled = <?= $cfEnabled ? 'true' : 'false' ?>;
const cfAccountId = <?= json_encode($cfAccountId) ?>;
let domainChecked = false;  // true after a successful Check
const domainPattern = /^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?\.[a-zA-Z]{2,}$/;

// ---- Password helpers ----
document.getElementById('toggle-pass').addEventListener('click', function () {
    const inp = document.getElementById('password');
    const icon = this.querySelector('i');
    if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye', 'fa-eye-slash'); }
    else { inp.type = 'password'; icon.classList.replace('fa-eye-slash', 'fa-eye'); }
});

document.getElementById('gen-pass').addEventListener('click', function () {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
    let pass = '';
    const arr = new Uint32Array(16);
    crypto.getRandomValues(arr);
    arr.forEach(n => pass += chars[n % chars.length]);
    document.getElementById('password').value  = pass;
    document.getElementById('password2').value = pass;
    document.getElementById('password').type   = 'text';
    document.getElementById('toggle-pass').querySelector('i').className = 'fas fa-eye-slash';
});

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

// ---- Domain input: live format validation ----
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

document.getElementById('domain').addEventListener('input', function () {
    const val = this.value.trim();
    domainChecked = false;

    if (cfEnabled && !document.getElementById('skip-cf')?.checked) {
        document.getElementById('submit-btn').disabled = true;
    }

    updateCheckButton();

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
        const checkBtn = document.getElementById('check-domain-btn');

        if (this.checked) {
            // Skip CF — hide check button state, enable submit directly
            checkBtn.disabled = true;
            document.getElementById('submit-btn').disabled = false;
            domainChecked = true;
            setDomainStatus('', 'Domain will be created with self-signed SSL and direct port access.');
        } else {
            // Re-enable CF check flow
            domainChecked = false;
            document.getElementById('submit-btn').disabled = true;
            updateCheckButton();
            const val = document.getElementById('domain').value.trim();
            if (isValidDomain(val)) {
                setDomainStatus('', 'Click <strong>Check</strong> to verify availability.');
            }
        }
    });
}

// ---- Check domain button ----
if (cfEnabled) {
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
                    document.getElementById('submit-btn').disabled = true;
                    return;
                }

                if (!data.available) {
                    // Domain not available (already hosted, already routed, or CNAME conflict)
                    setDomainStatus('error', data.reason || 'Domain not available.');
                    domainChecked = false;
                    document.getElementById('submit-btn').disabled = true;
                } else if (!data.cf_managed) {
                    // Zone not found in CF
                    const addUrl = cfAccountId
                        ? 'https://dash.cloudflare.com/' + cfAccountId + '/add-site'
                        : 'https://dash.cloudflare.com/';
                    setDomainStatus('warn',
                        (data.warning || 'Domain zone not found in Cloudflare.') +
                        ' <a href="' + addUrl + '" target="_blank" class="text-warning fw-semibold">Add to Cloudflare <i class="fas fa-external-link-alt fa-xs"></i></a>'
                    );
                    domainChecked = false;
                    document.getElementById('submit-btn').disabled = true;
                } else {
                    // All good — available and CF managed
                    setDomainStatus('ok', 'Domain available and managed by Cloudflare.');
                    domainChecked = true;
                    document.getElementById('submit-btn').disabled = false;
                }
            })
            .catch(err => {
                icon.classList.remove('d-none');
                spinner.classList.add('d-none');
                btn.disabled = false;
                setDomainStatus('error', 'Check failed: ' + (err.message || 'Network error'));
                domainChecked = false;
                document.getElementById('submit-btn').disabled = true;
            });
    });
}

// ---- Progress modal helpers ----
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
        { pct: 10,  text: 'Creating hosting user...',      delay: 0 },
        { pct: 30,  text: 'Configuring Apache vhost...',    delay: 3000 },
        { pct: 50,  text: 'Setting up PHP-FPM pool...',     delay: 6000 },
        { pct: 65,  text: 'Creating database...',           delay: 9000 },
        { pct: 80,  text: 'Issuing SSL certificate...',     delay: 12000 },
        { pct: 90,  text: 'Opening firewall port...',       delay: 16000 },
        { pct: 95,  text: 'Finalizing...',                  delay: 19000 },
    ] : [
        { pct: 10,  text: 'Creating hosting user...',       delay: 0 },
        { pct: 25,  text: 'Configuring Apache vhost...',    delay: 3000 },
        { pct: 40,  text: 'Setting up PHP-FPM pool...',     delay: 6000 },
        { pct: 55,  text: 'Creating database...',           delay: 9000 },
        { pct: 65,  text: 'Issuing SSL certificate...',     delay: 12000 },
        { pct: 75,  text: 'Adding to Cloudflare tunnel...', delay: 16000 },
        { pct: 85,  text: 'Configuring DNS...',             delay: 22000 },
        { pct: 95,  text: 'Finalizing...',                  delay: 27000 },
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
    if (progressTimer) {
        progressTimer.forEach(t => clearTimeout(t));
        progressTimer = null;
    }
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
document.getElementById('create-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const domain = document.getElementById('domain').value.trim();
    const pass   = document.getElementById('password').value;
    const pass2  = document.getElementById('password2').value;
    const alert  = document.getElementById('create-alert');
    const skipCf = cfEnabled && document.getElementById('skip-cf')?.checked;

    if (pass !== pass2) {
        alert.className = 'alert alert-danger';
        alert.textContent = 'Passwords do not match.';
        alert.classList.remove('d-none');
        return;
    }

    if (cfEnabled && !skipCf && !domainChecked) {
        alert.className = 'alert alert-warning';
        alert.textContent = 'Please check the domain availability first.';
        alert.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    alert.className = 'd-none';

    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('domain', domain);
    fd.append('password', pass);
    fd.append('php_version', document.getElementById('php_version').value);
    if (skipCf) fd.append('skip_cf', '1');
    const usernameVal = document.getElementById('username').value.trim();
    if (usernameVal) fd.append('username', usernameVal);

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
                    const uname = data.username || usernameVal || domain;
                    let msg = `<strong>Account created!</strong> User: <code>${uname}</code> / Domain: <code>${domain}</code> on port <code>${data.port ?? '—'}</code>.`;
                    if (skipCf) msg += ' <span class="badge bg-secondary">Direct access</span>';
                    msg += ` <a href="/admin/accounts">View all accounts &rarr;</a>`;
                    if (data.warnings && data.warnings.length) {
                        msg += '<div class="alert alert-warning mt-2 mb-0 py-2 small">' + data.warnings.map(w => `<div>${w}</div>`).join('') + '</div>';
                    }
                    alert.innerHTML = msg;
                }, 500);
                document.getElementById('create-form').reset();
                domainChecked = false;
                if (cfEnabled) {
                    document.getElementById('submit-btn').disabled = true;
                    document.getElementById('skip-cf-notice').classList.add('d-none');
                    updateCheckButton();
                }
                setDomainStatus('', cfEnabled
                    ? 'Enter a domain or subdomain, then click <strong>Check</strong> to verify Cloudflare availability.'
                    : 'Each domain gets its own vhost, database, and SSL certificate.');
            } else {
                hideProgress(false);
                alert.className = 'alert alert-danger';
                alert.classList.remove('d-none');
                alert.textContent = data.error || 'Failed to create account.';
            }
        })
        .catch(err => {
            btn.disabled = false;
            hideProgress(false);
            alert.className = 'alert alert-danger';
            alert.classList.remove('d-none');
            alert.innerHTML = '<strong>Request failed:</strong> ' + (err.message || 'Unknown error') + '<br><small class="text-muted">The account may have been created — check <a href="/admin/accounts">Accounts</a> and <a href="/admin/logs">Panel Logs</a>.</small>';
        });
});
</script>
