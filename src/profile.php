<?php
// FILE: src/profile.php
// iNetPanel — User Profile (change password + theme)

$user     = Auth::user();
$username = htmlspecialchars($user['username'] ?? 'Unknown');
$role     = $user['role'] ?? 'subadmin';
?>

<h4 class="mb-4"><i class="fas fa-user-circle me-2"></i>My Profile</h4>
<div id="profile-alert" class="d-none mb-3"></div>

<div class="row g-4">

    <!-- Account card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center p-4">
                <div class="rounded-circle d-inline-flex justify-content-center align-items-center mb-3"
                     style="width:72px;height:72px;background:var(--active-gradient);font-size:2rem;color:#fff;font-weight:700;">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
                <h5 class="fw-bold mb-1"><?= $username ?></h5>
                <span class="badge rounded-pill bg-<?= $role === 'admin' ? 'primary' : 'secondary' ?>">
                    <?= ucfirst($role) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="col-md-8 d-flex flex-column gap-4">

        <!-- Change Password -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent py-3">
                <h6 class="mb-0"><i class="fas fa-lock me-2 text-primary"></i>Change Password</h6>
            </div>
            <div class="card-body p-4">
                <form id="pass-form" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Password</label>
                        <input type="password" class="form-control" id="current-pass" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password</label>
                        <input type="password" class="form-control" id="new-pass" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm-pass" minlength="8" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary" id="pass-btn">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="pass-spinner"></span>
                        <i class="fas fa-save me-1" id="pass-icon"></i>Update Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Appearance -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent py-3">
                <h6 class="mb-0"><i class="fas fa-palette me-2 text-primary"></i>Appearance</h6>
            </div>
            <div class="card-body p-4">
                <label class="form-label fw-semibold d-block mb-3">Theme</label>
                <div class="d-flex gap-3 flex-wrap">
                    <button class="theme-option" id="theme-btn-light" onclick="setTheme('light')" type="button">
                        <div class="theme-preview theme-preview-light">
                            <div class="tp-sidebar"></div>
                            <div class="tp-body">
                                <div class="tp-header"></div>
                                <div class="tp-card"></div>
                            </div>
                        </div>
                        <div class="text-center mt-2 small fw-semibold">Light</div>
                    </button>
                    <button class="theme-option" id="theme-btn-dark" onclick="setTheme('dark')" type="button">
                        <div class="theme-preview theme-preview-dark">
                            <div class="tp-sidebar"></div>
                            <div class="tp-body">
                                <div class="tp-header"></div>
                                <div class="tp-card"></div>
                            </div>
                        </div>
                        <div class="text-center mt-2 small fw-semibold">Dark</div>
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ── Theme ──────────────────────────────────────────────────────────────────
function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('inetp_theme', theme);
    document.getElementById('theme-btn-light').classList.toggle('active', theme === 'light');
    document.getElementById('theme-btn-dark').classList.toggle('active',  theme === 'dark');
}
(function () {
    const t = localStorage.getItem('inetp_theme') || 'light';
    document.getElementById('theme-btn-' + t)?.classList.add('active');
})();

// ── Password ───────────────────────────────────────────────────────────────
document.getElementById('pass-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const current = document.getElementById('current-pass').value;
    const newPass = document.getElementById('new-pass').value;
    const confirm = document.getElementById('confirm-pass').value;
    const alertEl = document.getElementById('profile-alert');

    if (newPass !== confirm) {
        alertEl.className = 'alert alert-danger';
        alertEl.textContent = 'New passwords do not match.';
        return;
    }

    const spinner = document.getElementById('pass-spinner');
    const icon    = document.getElementById('pass-icon');
    const btn     = document.getElementById('pass-btn');
    spinner.classList.remove('d-none');
    icon.classList.add('d-none');
    btn.disabled  = true;
    alertEl.className = 'd-none';

    const fd = new FormData();
    fd.append('action',           'change_password');
    fd.append('current_password', current);
    fd.append('new_password',     newPass);

    fetch('/api/profile', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            btn.disabled = false;
            alertEl.className = data.success ? 'alert alert-success' : 'alert alert-danger';
            alertEl.textContent = data.success
                ? 'Password updated successfully.'
                : (data.error || 'Failed to update password.');
            if (data.success) document.getElementById('pass-form').reset();
        })
        .catch(() => {
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            btn.disabled = false;
            alertEl.className = 'alert alert-danger';
            alertEl.textContent = 'Request failed.';
        });
});
</script>
