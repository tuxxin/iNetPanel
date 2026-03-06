<?php
// FILE: src/add_account.php
// iNetPanel — Create new hosting account

// Fetch installed PHP versions for the dropdown
$phpVersions = [];
$allVersions = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4'];
foreach ($allVersions as $v) {
    if (is_dir("/etc/php/{$v}") || file_exists("/usr/sbin/php-fpm{$v}")) {
        $phpVersions[] = $v;
    }
}
if (empty($phpVersions)) {
    $phpVersions = ['8.4']; // fallback
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-globe me-2"></i>Add New Account</h4>
    <a href="/admin/accounts" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<!-- Result alert -->
<div id="create-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form id="create-form">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Domain Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="domain" name="domain"
                           placeholder="example.com" autocomplete="off" required>
                    <div class="form-text">Used as the Linux username, DB name, and site directory.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">PHP Version</label>
                    <select class="form-select" id="php_version" name="php_version">
                        <?php foreach (array_reverse($phpVersions) as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $v === '8.4' ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
// Toggle password visibility
document.getElementById('toggle-pass').addEventListener('click', function () {
    const inp = document.getElementById('password');
    const icon = this.querySelector('i');
    if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye', 'fa-eye-slash'); }
    else { inp.type = 'password'; icon.classList.replace('fa-eye-slash', 'fa-eye'); }
});

// Generate random password
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

document.getElementById('create-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const domain = document.getElementById('domain').value.trim();
    const pass   = document.getElementById('password').value;
    const pass2  = document.getElementById('password2').value;
    const alert  = document.getElementById('create-alert');

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

    fetch('/api/accounts.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            btn.disabled = false;
            if (data.success) {
                alert.className = 'alert alert-success';
                alert.innerHTML = `<strong>Account created!</strong> Domain: <code>${domain}</code> on port <code>${data.port ?? '—'}</code>. <a href="/admin/accounts">View all accounts &rarr;</a>`;
                document.getElementById('create-form').reset();
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
