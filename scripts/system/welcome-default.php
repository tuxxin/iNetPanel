<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{DOMAIN}}</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f9; color: #333; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; }
        .info { background: #eee; padding: 15px; text-align: left; margin-top: 20px; font-family: monospace; }
        .log-note { font-size: 0.8em; color: #666; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Website Active</h1>
        <div class="info">
            <p><strong>Domain:</strong> {{DOMAIN}}</p>
            <p><strong>Port:</strong> {{PORT}}</p>
            <p><strong>Root:</strong> {{DOC_ROOT}}</p>
            <p><strong>PHP:</strong> <?php echo phpversion(); ?></p>
        </div>
        <p class="log-note">Logs: ~/logs/php_error.log &amp; ~/logs/apache_error.log</p>
    </div>
</body>
</html>
