<div class="sidebar">
    <div class="sidebar-header">
        <img src="/assets/img/iNetPanel-Logo.webp" alt="iNetPanel" class="sidebar-brand-logo">
    </div>
    
    <?php
    // Sidebar helpers
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    function sidebarActive(string $segment): string {
        global $uri;
        return str_contains($uri, $segment) ? 'active' : '';
    }
    // Load CF and WireGuard status from DB (silently fail if DB not ready)
    $cfEnabled = false;
    $wgEnabled = false;
    try {
        if (class_exists('DB')) {
            $cfEnabled = DB::setting('cf_enabled', '0') === '1';
            $wgEnabled = DB::setting('wg_enabled', '0') === '1';
        }
    } catch (\Throwable) {}
    $isSuperAdmin  = class_exists('Auth') && Auth::isAdmin();        // real admin only
    $hasFullAccess = class_exists('Auth') && Auth::hasFullAccess();  // admin + fulladmin
    $isSubAdmin    = !$hasFullAccess;                                 // subadmin or unauthenticated
    $isAdmin       = $isSuperAdmin; // kept for legacy references below
    ?>

    <div class="sidebar-menu">
        <div class="text-uppercase small text-muted fw-bold px-3 mb-2" style="font-size: 0.75rem; letter-spacing: 1px;">Menu</div>

        <a href="/admin/dashboard" class="<?= sidebarActive('dashboard') ?>">
            <i class="fas fa-home"></i> <span>Dashboard</span>
        </a>
        <a href="/admin/add-account" class="<?= sidebarActive('add-account') ?>">
            <i class="fas fa-globe"></i> <span>Add Account</span>
        </a>
        <a href="/admin/add-domain" class="<?= sidebarActive('add-domain') ?>">
            <i class="fas fa-plus-circle"></i> <span>Add Domain</span>
        </a>
        <a href="/admin/accounts" class="<?= sidebarActive('accounts') && !sidebarActive('add-account') && !sidebarActive('add-domain') ? 'active' : '' ?>">
            <i class="fas fa-users"></i> <span>List Accounts</span>
        </a>

        <a href="/admin/phpmyadmin" target="_blank">
            <i class="fas fa-database"></i> <span>phpMyAdmin</span>
        </a>

        <?php if ($cfEnabled): ?>
        <div class="text-uppercase small text-muted fw-bold px-3 mb-2 mt-4" style="font-size: 0.75rem; letter-spacing: 1px;">Cloudflare</div>
        <a href="/admin/dns" class="<?= sidebarActive('dns') ?>">
            <i class="fas fa-network-wired"></i> <span>DNS Settings</span>
        </a>
        <a href="/admin/email" class="<?= sidebarActive('email') ?>">
            <i class="fas fa-envelope"></i> <span>Email Routing</span>
        </a>
        <?php endif; ?>

        <div class="text-uppercase small text-muted fw-bold px-3 mb-2 mt-4" style="font-size: 0.75rem; letter-spacing: 1px;">System</div>

        <a href="/admin/settings" class="<?= sidebarActive('settings') ?>">
            <i class="fas fa-sliders-h"></i> <span>Settings</span>
        </a>
        <a href="/admin/services" class="<?= sidebarActive('services') ?>">
            <i class="fas fa-server"></i> <span>Services</span>
        </a>
        <a href="/admin/firewall" class="<?= sidebarActive('firewall') ?>">
            <i class="fas fa-shield-halved"></i> <span>Firewall</span>
        </a>
        <a href="/admin/ssl" class="<?= sidebarActive('/ssl') ?>">
            <i class="fas fa-lock"></i> <span>SSL Certificates</span>
        </a>
        <a href="/admin/multi-php" class="<?= sidebarActive('multi-php') ?>">
            <i class="fab fa-php" style="font-size: 1.2rem;"></i> <span>Multi-PHP</span>
        </a>
        <a href="/admin/php-packages" class="<?= sidebarActive('php-packages') ?>">
            <i class="fas fa-puzzle-piece"></i> <span>PHP Packages</span>
        </a>
        <a href="/admin/backups" class="<?= sidebarActive('backups') ?>">
            <i class="fas fa-history"></i> <span>Backups</span>
        </a>
        <a href="/admin/logs" class="<?= sidebarActive('logs') ?>">
            <i class="fas fa-file-lines"></i> <span>Logs</span>
        </a>
        <?php if ($hasFullAccess): ?>
        <a href="/admin/hook-scripts" class="<?= sidebarActive('hook-scripts') ?>">
            <i class="fas fa-code"></i> <span>Hook Scripts</span>
        </a>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
        <div class="text-uppercase small text-muted fw-bold px-3 mb-2 mt-4" style="font-size: 0.75rem; letter-spacing: 1px;">Admin</div>
        <a href="/admin/panel-users" class="<?= sidebarActive('panel-users') ?>">
            <i class="fas fa-user-shield"></i> <span>Panel Users</span>
        </a>
        <?php endif; ?>

        <hr class="my-3 mx-2 opacity-25">

        <a href="/logout" class="text-danger">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
        <a href="#" class="text-danger" data-bs-toggle="modal" data-bs-target="#sidebarRestartModal">
            <i class="fas fa-power-off"></i> <span>Restart Server</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <?php $panel_version = class_exists('Version') ? Version::display() : 'v0.107'; ?>
        <div class="d-flex align-items-center justify-content-between w-100">
            <div class="sidebar-version-block">
                <div class="d-flex align-items-center gap-2">
                    <span class="version-label">iNetPanel</span>
                    <a href="https://github.com/tuxxin/iNetPanel" class="version-badge text-decoration-none d-flex align-items-center gap-1" target="_blank" title="GitHub">
                        <i class="fab fa-github" style="font-size:0.7rem;"></i><?php echo htmlspecialchars($panel_version); ?>
                    </a>
                </div>
                <div class="version-sub text-muted" style="font-size: 0.68rem; margin-top: 2px;">
                    Powered by <a href="https://tuxxin.com" target="_blank" class="ticore-tag text-decoration-none">Tuxxin.com</a>
                </div>
            </div>
            <a href="#" data-bs-toggle="modal" data-bs-target="#bmcModal" title="Support iNetPanel" style="font-size:1.4rem;color:#FFDD00;text-decoration:none;line-height:1;">
                <i class="fas fa-mug-hot"></i>
            </a>
        </div>
    </div>
</div>

<script>
function sidebarReboot() {
    const fd = new FormData();
    fd.append('action', 'reboot');
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .catch(() => {});
}
</script>

<!-- Buy Me a Coffee Modal -->
<div class="modal fade" id="bmcModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="background:#FFDD00;">
                <h5 class="modal-title fw-bold"><i class="fas fa-mug-hot me-2"></i>Support iNetPanel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="small text-muted mb-3">iNetPanel is free and open source. If it saves you time, consider buying me a coffee!</p>
                <div class="d-grid gap-2">
                    <a href="https://buymeacoffee.com/Tuxxin" target="_blank" class="btn fw-bold" style="background:#FFDD00;color:#000;">
                        <i class="fas fa-coffee me-1"></i> One-Time Support
                    </a>
                    <a href="https://buymeacoffee.com/Tuxxin" target="_blank" class="btn btn-outline-dark fw-bold">
                        <i class="fas fa-heart me-1"></i> Monthly Supporter
                    </a>
                    <p class="small text-muted mt-1 mb-0" style="font-size:.7rem;">Select "Make this monthly" on the page</p>
                </div>
                <p class="small text-muted mt-3 mb-0">Thank you for your support!</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="sidebarRestartModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i> System Restart</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="fw-bold">Are you sure you want to restart the server?</p>
                <p class="text-muted small mb-0">All active services and connections will be temporarily interrupted. This process may take a few minutes.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger fw-bold" onclick="sidebarReboot()">
                    <i class="fas fa-power-off me-2"></i> Yes, Restart Now
                </button>
            </div>
        </div>
    </div>
</div>