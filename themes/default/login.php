<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Login - iNetPanel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #0050d5 0%, #7a00d5 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border: none;
        }
        .login-header {
            background: #fff;
            padding: 30px 20px 10px;
            text-align: center;
        }
        .login-body {
            background: #f8f9fa;
            padding: 30px;
        }
        .btn-custom {
            background: #7a00d5; /* Matches logo purple */
            color: #fff;
            border: none;
            padding: 10px;
            font-weight: 600;
        }
        .btn-custom:hover {
            background: #5a00a3;
            color: #fff;
        }
    </style>
</head>
<body>

<div class="card login-card">
    <div class="login-header">
        <div class="mb-3">
             <i class="fas fa-network-wired fa-3x" style="color: #0050d5;"></i>
        </div>
        <h4 class="fw-bold" style="color: #333;">iNetPanel</h4>
        <p class="text-muted small">Webhosting Management System</p>
    </div>
    
    <div class="login-body">
        <?php
        // Show error if login failed
        if (!empty($error)): ?>
        <div class="alert alert-danger py-2 px-3 mb-3 small">
            <i class="fas fa-exclamation-circle me-1"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php
        // Show notice if install hasn't been completed
        $lockFile = dirname(__DIR__, 2) . '/db/.installed';
        if (!file_exists($lockFile)): ?>
        <div class="alert alert-warning py-2 px-3 mb-3 small">
            <i class="fas fa-tools me-1"></i>
            Panel not set up yet. <a href="/install.php" class="alert-link">Complete installation</a>.
        </div>
        <?php endif; ?>

        <form action="/login" method="POST">
            <div class="mb-3">
                <label class="form-label text-secondary small text-uppercase fw-bold">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-user text-muted"></i></span>
                    <input type="text" name="username" class="form-control border-start-0 ps-0"
                           placeholder="Enter username" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label text-secondary small text-uppercase fw-bold">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="password" class="form-control border-start-0 ps-0" placeholder="••••••••" required>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-custom">
                    <i class="fas fa-sign-in-alt me-2"></i>Login to Panel
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>