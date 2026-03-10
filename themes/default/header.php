<?php
// Update notification — check cached version from SQLite (no live request here)
$_updateAvailable = false;
$_latestVer = '';
try {
    if (class_exists('DB') && class_exists('Version')) {
        $__latestVer = DB::setting('panel_latest_ver', '');
        $__checkTs   = (int) DB::setting('panel_check_ts', '0');
        if ($__latestVer && version_compare($__latestVer, Version::get(), '>')) {
            $_updateAvailable = true;
            $_latestVer = $__latestVer;
        }
        // If cache is stale (>24h) trigger a background refresh — fire-and-forget
        if (time() - $__checkTs > 86400) {
            ignore_user_abort(true);
            @file_get_contents(
                'http://127.0.0.1/api/update_check?action=check',
                false,
                stream_context_create(['http' => ['timeout' => 2]])
            );
        }
    }
} catch (\Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(DB::setting('panel_name', 'iNetPanel')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../../public/assets/css/style.css') ?: time() ?>" rel="stylesheet">
    <!-- Apply saved theme before first paint to avoid flash -->
    <script>(function(){var t=localStorage.getItem('inetp_theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})()</script>
</head>
<body>

<?php include 'sidebar.php'; ?>

<header class="top-header">
    <div class="system-info d-flex align-items-center">
        <span class="me-4"><i class="fas fa-server"></i> <?php echo gethostname(); ?></span>
        <span class="d-none d-md-inline me-4"><i class="fas fa-clock"></i> <?php echo date('g:i A T'); ?></span>
        <?php
        $_fwState = trim(@shell_exec('sudo firewall-cmd --state 2>/dev/null') ?? '');
        $_fwActive = $_fwState === 'running';
        $_f2bBans = 0;
        if ($_fwActive) {
            $_f2bRaw = @shell_exec('sudo fail2ban-client status 2>/dev/null') ?? '';
            if (preg_match('/Jail list:\s*(.+)$/m', $_f2bRaw, $_jm)) {
                foreach (array_map('trim', explode(',', $_jm[1])) as $_j) {
                    if (!$_j) continue;
                    $_js = @shell_exec('sudo fail2ban-client status ' . escapeshellarg($_j) . ' 2>/dev/null') ?? '';
                    if (preg_match('/Currently banned:\s*(\d+)/', $_js, $_bm)) $_f2bBans += (int)$_bm[1];
                }
            }
        }
        ?>
        <a href="/admin/firewall" class="text-decoration-none d-none d-md-inline">
            <i class="fas fa-shield-halved <?= $_fwActive ? 'text-success' : 'text-danger' ?>"></i>
            <span class="small <?= $_fwActive ? 'text-success' : 'text-danger' ?>"><?= $_fwActive ? 'Protected' : 'Unprotected' ?></span>
            <?php if ($_f2bBans > 0): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><?= $_f2bBans ?> banned</span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($_updateAvailable): ?>
    <a href="/admin/settings#tab-updates" class="btn btn-sm btn-warning ms-3 fw-semibold">
        <i class="fas fa-arrow-up me-1"></i>Update available: v<?= htmlspecialchars($_latestVer) ?>
    </a>
    <?php endif; ?>

    <?php
    $_headerUser    = class_exists('Auth') ? Auth::user() : null;
    $_headerName    = htmlspecialchars($_headerUser['username'] ?? 'User');
    $_headerInitial = strtoupper(substr($_headerUser['username'] ?? 'U', 0, 1));
    ?>
    <div class="dropdown">
        <a href="#" class="user-dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="rounded-circle d-flex justify-content-center align-items-center me-2 fw-bold"
                 style="width:32px; height:32px; background: var(--active-gradient); font-size:0.9rem; color:#fff;">
                <?= $_headerInitial ?>
            </div>
            <span class="me-1"><?= $_headerName ?></span>
            <i class="fas fa-chevron-down small" style="font-size: 0.7rem;"></i>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
            <li><a class="dropdown-item" href="/admin/profile"><i class="fas fa-user-circle me-2 text-muted"></i> Profile</a></li>
            <li><a class="dropdown-item" href="/admin/settings"><i class="fas fa-cogs me-2 text-muted"></i> Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="/logout"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
        </ul>
    </div>
</header>

<div class="main-content">
    <div class="container-fluid p-0">