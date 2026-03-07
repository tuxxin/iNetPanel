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
    <title><?php echo htmlspecialchars(DB::setting('panel_name', 'iNetPanel')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body>

<?php include 'sidebar.php'; ?>

<header class="top-header">
    <div class="system-info d-flex align-items-center">
        <span class="me-4"><i class="fas fa-server"></i> <?php echo gethostname(); ?></span>
        <span class="d-none d-md-inline"><i class="fas fa-clock"></i> <?php echo date('g:i A T'); ?></span>
    </div>

    <?php if ($_updateAvailable): ?>
    <a href="/admin/settings#tab-updates" class="btn btn-sm btn-warning ms-3 fw-semibold">
        <i class="fas fa-arrow-up me-1"></i>Update available: v<?= htmlspecialchars($_latestVer) ?>
    </a>
    <?php endif; ?>

    <div class="dropdown">
        <a href="#" class="user-dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="bg-primary rounded-circle d-flex justify-content-center align-items-center me-2" 
                 style="width:32px; height:32px; background: var(--active-gradient) !important;">
                 A
            </div>
            <span class="me-1">Admin</span>
            <i class="fas fa-chevron-down small" style="font-size: 0.7rem;"></i>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
            <li><a class="dropdown-item" href="#"><i class="fas fa-user-circle me-2 text-muted"></i> Profile</a></li>
            <li><a class="dropdown-item" href="/admin/settings"><i class="fas fa-cogs me-2 text-muted"></i> Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="/logout"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
        </ul>
    </div>
</header>

<div class="main-content">
    <div class="container-fluid p-0">