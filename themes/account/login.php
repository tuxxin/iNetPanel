<!DOCTYPE html>
<html lang="en" <?php $t = $_COOKIE['inetp_theme'] ?? ''; echo $t === 'dark' ? 'data-theme="dark"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Account Login — iNetPanel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/assets/css/style.css" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('inetp_theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})()</script>
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; }
        .login-logo { max-width: 180px; margin-bottom: 1.5rem; }
        [data-theme="dark"] .card { background: #1e293b; }
        [data-theme="dark"] .form-control { background: #0f172a; border-color: rgba(255,255,255,0.15); color: #e2e8f0; }
        [data-theme="dark"] .form-control::placeholder { color: #64748b; }
        [data-theme="dark"] .form-control:focus { background: #0f172a; color: #e2e8f0; }
    </style>
</head>
<body>
<div class="login-card p-3">
    <div class="text-center">
        <img src="/assets/img/iNetPanel-Logo.webp" alt="iNetPanel" class="login-logo">
    </div>
    <div class="card border-0 shadow-lg">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-1 text-center">Account Portal</h5>
            <p class="text-muted text-center small mb-4">Sign in with your FTP / SSH credentials</p>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="/user/login">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Domain / Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-globe text-muted"></i></span>
                        <input type="text" class="form-control" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               placeholder="example.com" autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                        <input type="password" class="form-control" name="password"
                               placeholder="Your FTP/SSH password" autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
            </form>
        </div>
    </div>
    <p class="text-center text-white-50 small mt-3">
        Panel admin? <a href="/login" class="text-white-50">Admin login &rarr;</a>
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
