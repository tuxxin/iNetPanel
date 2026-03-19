<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($GLOBALS['_page_title'] ?? 'My Account') ?> — iNetPanel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../../public/assets/css/style.css') ?: time() ?>" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('inetp_theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})()</script>
    <style>
        body { background-color: var(--body-bg); font-family: 'Segoe UI', system-ui, sans-serif; color: var(--text-main); margin: 0; }
        .account-nav { background: var(--header-gradient); position: sticky; top: 0; z-index: 900; box-shadow: 0 2px 10px rgba(0,0,0,0.15); }
        .account-nav .nav-brand img { height: 36px; }
        .account-nav .nav-domain { color: rgba(255,255,255,0.85); font-weight: 600; font-size: 0.9rem; }
        .account-main { max-width: 1100px; margin: 0 auto; padding: 30px 20px 80px; }
        [data-theme="dark"] .card { background: #1e293b; border-color: rgba(255,255,255,0.08) !important; }
        [data-theme="dark"] .card-header { background: #1e293b !important; border-bottom-color: rgba(255,255,255,0.08); }
        [data-theme="dark"] .bg-white { background: #1e293b !important; }
        [data-theme="dark"] .table { color: #e2e8f0; --bs-table-bg: transparent; }
        [data-theme="dark"] .table-light { --bs-table-bg: rgba(255,255,255,0.04); color: #e2e8f0; }
        [data-theme="dark"] .table > :not(caption) > * > * { border-bottom-color: rgba(255,255,255,0.08); }
        [data-theme="dark"] .list-group-item { background: #1e293b; border-color: rgba(255,255,255,0.08); color: #e2e8f0; }
        [data-theme="dark"] .text-muted { color: #94a3b8 !important; }
        [data-theme="dark"] body { background-color: #0f172a; color: #e2e8f0; }
    </style>
</head>
<body>

<nav class="account-nav py-2 px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
        <a href="/user/dashboard" class="nav-brand"><img src="/assets/img/iNetPanel-Logo.webp" alt="iNetPanel"></a>
        <span class="text-white-50 d-none d-md-inline">|</span>
        <span class="nav-domain d-none d-md-inline">
            <i class="fas fa-user me-1" style="color: var(--brand-cyan);"></i>
            <?= htmlspecialchars(AccountAuth::username() ?? '') ?>
        </span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white-50 small d-none d-sm-inline" id="live-clock"><i class="fas fa-clock me-1"></i><?= date('g:i:s A T') ?></span>
        <script>
        (function(){
            const tz = <?= json_encode(date_default_timezone_get()) ?>;
            const el = document.getElementById('live-clock');
            if (!el) return;
            setInterval(function(){
                const now = new Date().toLocaleTimeString('en-US', {timeZone: tz, hour:'numeric', minute:'2-digit', second:'2-digit', hour12:true});
                const tzAbbr = new Date().toLocaleTimeString('en-US', {timeZone: tz, timeZoneName:'short'}).split(' ').pop();
                el.innerHTML = '<i class="fas fa-clock me-1"></i>' + now + ' ' + tzAbbr;
            }, 1000);
        })();
        </script>
        <a href="/user/logout" class="btn btn-sm btn-outline-light opacity-75">
            <i class="fas fa-sign-out-alt me-1"></i>Logout
        </a>
    </div>
</nav>

<div class="account-main">
