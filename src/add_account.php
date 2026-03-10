<?php
// FILE: src/add_account.php
// iNetPanel — Create new hosting account (smart domain selector)

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
                           placeholder="Auto-generated from domain if blank" autocomplete="off"
                           pattern="[a-z][a-z0-9\-]{0,31}">
                    <div class="form-text">Hosting user account. Leave blank to auto-generate from domain. If user exists, domain is added to them.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Domain Name <span class="text-danger">*</span></label>

                    <!-- CF zone quick-select -->
                    <?php if ($cfEnabled): ?>
                    <div id="cf-zone-row" class="mb-2">
                        <div class="input-group input-group-sm">
                            <select id="cf-zone-sel" class="form-select form-select-sm">
                                <option value="">Loading zones...</option>
                            </select>
                            <button class="btn btn-outline-secondary" type="button" id="refresh-zones" title="Refresh zones">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Domain input with validation indicator -->
                    <div class="input-group">
                        <input type="text" class="form-control" id="domain" name="domain"
                               placeholder="example.com" autocomplete="off" required>
                        <span class="input-group-text d-none" id="domain-spinner">
                            <span class="spinner-border spinner-border-sm text-secondary"></span>
                        </span>
                        <span class="input-group-text d-none" id="domain-ok">
                            <i class="fas fa-check text-success"></i>
                        </span>
                        <span class="input-group-text d-none" id="domain-fail">
                            <i class="fas fa-times text-danger"></i>
                        </span>
                    </div>
                    <div id="domain-status" class="form-text">Each domain gets its own vhost, database, and SSL certificate.</div>
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
                               placeholder="Min 8 characters" required minlength="8">
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
                           placeholder="Repeat password" required minlength="8">
                </div>
            </div>

            <div class="d-flex align-items-center gap-3 mt-4">
                <button type="submit" class="btn btn-primary px-5" id="submit-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="create-spinner"></span>
                    <i class="fas fa-plus me-1" id="submit-icon"></i>Create Account
                </button>
                <a href="/admin/accounts" class="btn btn-link text-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
const cfEnabled = <?= $cfEnabled ? 'true' : 'false' ?>;
const cfAccountId = <?= json_encode($cfAccountId) ?>;
let domainCheckTimer = null;
let cfZones = [];
let cfRoutedHostnames = [];
let cfTunnelId = '';

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

// ---- Skip CF checkbox ----
if (cfEnabled) {
    document.getElementById('skip-cf').addEventListener('change', function () {
        document.getElementById('skip-cf-notice').classList.toggle('d-none', !this.checked);
        // Re-check domain status when toggling
        const domain = document.getElementById('domain').value.trim();
        if (domain) checkDomain(domain);
    });
}

// ---- CF Zone Selector ----
function loadDomainOptions(force) {
    if (!cfEnabled) return;
    const sel = document.getElementById('cf-zone-sel');
    sel.innerHTML = '<option value="">Loading zones...</option>';

    fetch('/api/accounts?action=domain_options' + (force ? '&force=1' : ''))
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.cf_enabled) {
                sel.innerHTML = '<option value="">Cloudflare unavailable</option>';
                return;
            }
            cfZones = data.zones || [];
            cfRoutedHostnames = data.routed_hostnames || [];
            cfTunnelId = data.tunnel_id || '';

            sel.innerHTML = '<option value="">-- Select a Cloudflare zone --</option>';
            cfZones.forEach(z => {
                const opt = document.createElement('option');
                opt.value = z.name;
                if (z.routed || z.cname_conflict) {
                    opt.disabled = true;
                    opt.textContent = z.name + (z.cname_conflict ? ' (routed on another tunnel)' : ' (already routed)');
                } else {
                    opt.textContent = z.name;
                }
                sel.appendChild(opt);
            });

            // Add "Add domain to Cloudflare" option
            const addOpt = document.createElement('option');
            addOpt.value = '__add_to_cf__';
            addOpt.textContent = '+ Add domain to Cloudflare...';
            addOpt.className = 'text-primary';
            sel.appendChild(addOpt);
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Failed to load zones</option>';
        });
}

if (cfEnabled) {
    loadDomainOptions(false);

    document.getElementById('cf-zone-sel').addEventListener('change', function () {
        if (this.value === '__add_to_cf__') {
            // Open CF dashboard to add a site
            const url = cfAccountId
                ? 'https://dash.cloudflare.com/' + cfAccountId + '/add-site'
                : 'https://dash.cloudflare.com/';
            window.open(url, '_blank');
            this.value = '';
            return;
        }
        if (this.value) {
            document.getElementById('domain').value = this.value;
            checkDomain(this.value);
        }
    });

    document.getElementById('refresh-zones').addEventListener('click', function () {
        loadDomainOptions(true);
    });
}

// ---- Domain validation ----
function setDomainStatus(type, message) {
    const status = document.getElementById('domain-status');
    const spinner = document.getElementById('domain-spinner');
    const ok = document.getElementById('domain-ok');
    const fail = document.getElementById('domain-fail');

    spinner.classList.add('d-none');
    ok.classList.add('d-none');
    fail.classList.add('d-none');

    if (type === 'loading') {
        spinner.classList.remove('d-none');
        status.className = 'form-text text-muted';
    } else if (type === 'ok') {
        ok.classList.remove('d-none');
        status.className = 'form-text text-success';
    } else if (type === 'warn') {
        ok.classList.remove('d-none');
        status.className = 'form-text text-warning';
    } else if (type === 'error') {
        fail.classList.remove('d-none');
        status.className = 'form-text text-danger';
    } else {
        status.className = 'form-text';
    }
    status.innerHTML = message;
}

function checkDomain(domain) {
    if (!cfEnabled || document.getElementById('skip-cf')?.checked) {
        setDomainStatus('', 'Domain will be created with self-signed SSL and direct port access.');
        return;
    }

    setDomainStatus('loading', 'Checking domain...');

    fetch('/api/accounts?action=check_domain&domain=' + encodeURIComponent(domain))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                setDomainStatus('error', data.error || 'Check failed.');
                return;
            }
            if (!data.available) {
                setDomainStatus('error', data.reason || 'Domain not available.');
            } else if (!data.cf_managed) {
                const addUrl = cfAccountId
                    ? 'https://dash.cloudflare.com/' + cfAccountId + '/add-site'
                    : 'https://dash.cloudflare.com/';
                setDomainStatus('warn',
                    (data.warning || 'Domain zone not found in Cloudflare.') +
                    ' <a href="' + addUrl + '" target="_blank">Add to Cloudflare <i class="fas fa-external-link-alt fa-xs"></i></a>'
                );
            } else {
                setDomainStatus('ok', 'Domain available and managed by Cloudflare.');
            }
        })
        .catch(() => {
            setDomainStatus('error', 'Failed to check domain.');
        });
}

document.getElementById('domain').addEventListener('input', function () {
    clearTimeout(domainCheckTimer);
    const val = this.value.trim();
    if (!val || val.length < 4) {
        setDomainStatus('', 'Each domain gets its own vhost, database, and SSL certificate.');
        return;
    }
    domainCheckTimer = setTimeout(() => checkDomain(val), 500);
});

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
        return;
    }

    const spinner = document.getElementById('create-spinner');
    const icon    = document.getElementById('submit-icon');
    const btn     = document.getElementById('submit-btn');
    spinner.classList.remove('d-none');
    icon.classList.add('d-none');
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

    fetch('/api/accounts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            btn.disabled = false;
            if (data.success) {
                alert.className = 'alert alert-success';
                const uname = data.username || usernameVal || domain;
                let msg = `<strong>Account created!</strong> User: <code>${uname}</code> / Domain: <code>${domain}</code> on port <code>${data.port ?? '—'}</code>.`;
                if (skipCf) msg += ' <span class="badge bg-secondary">Direct access</span>';
                msg += ` <a href="/admin/accounts">View all accounts &rarr;</a>`;
                if (data.warnings && data.warnings.length) {
                    msg += '<div class="alert alert-warning mt-2 mb-0 py-2 small">' + data.warnings.map(w => `<div>${w}</div>`).join('') + '</div>';
                }
                alert.innerHTML = msg;
                document.getElementById('create-form').reset();
                if (cfEnabled) {
                    document.getElementById('cf-zone-sel').value = '';
                    document.getElementById('skip-cf-notice').classList.add('d-none');
                }
                setDomainStatus('', 'Each domain gets its own vhost, database, and SSL certificate.');
                // Refresh zone cache since routing changed
                if (cfEnabled && !skipCf) loadDomainOptions(true);
            } else {
                alert.className = 'alert alert-danger';
                alert.textContent = data.error || 'Failed to create account.';
            }
        })
        .catch(() => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            btn.disabled = false;
            alert.className = 'alert alert-danger';
            alert.textContent = 'Request failed. Check the server log.';
        });
});
</script>
