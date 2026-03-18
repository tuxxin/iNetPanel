<?php
// FILE: src/hook_scripts.php
// iNetPanel — Hook Scripts Admin Page
Auth::requireAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-code me-2"></i>Hook Scripts</h4>
</div>

<div id="hooks-alert" class="d-none mb-3"></div>

<div class="alert alert-info small mb-3">
    <i class="fas fa-info-circle me-2"></i>
    Hook scripts run automatically after domain operations complete successfully. Write custom bash code that executes with root privileges.
    Use cases include auto-deploying frameworks, calling external webhooks, syncing DNS records, and more.
</div>

<div class="alert alert-warning small mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Important:</strong> Do not use <code>exit</code>, <code>exec</code>, or infinite loops in your hook code.
    Hooks run after the API response is sent but within the PHP-FPM request lifecycle. Long-running scripts may be terminated by PHP's request timeout.
</div>

<!-- ═══ POST ADD DOMAIN ══════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-plus-circle me-2 text-success"></i>Post Add Domain</h6>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="hook-add-toggle" style="width:2.5em;height:1.3em;cursor:pointer">
            <label class="form-check-label small" for="hook-add-toggle">Enabled</label>
        </div>
    </div>
    <div class="card-body">
        <details class="mb-3">
            <summary class="fw-semibold small text-muted" style="cursor:pointer">
                <i class="fas fa-info-circle me-1"></i>Available Variables
            </summary>
            <div class="mt-2">
                <table class="table table-sm table-bordered small mb-0">
                    <thead class="table-light"><tr><th style="width:140px">Variable</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$DOMAIN</code></td><td>Domain name (e.g. example.com)</td></tr>
                        <tr><td><code>$USERNAME</code></td><td>Hosting account username</td></tr>
                        <tr><td><code>$PORT</code></td><td>Assigned Apache port</td></tr>
                        <tr><td><code>$DOC_ROOT</code></td><td>Document root: /home/$USERNAME/$DOMAIN/www</td></tr>
                        <tr><td><code>$WEB_ROOT</code></td><td>Same as $DOC_ROOT</td></tr>
                        <tr><td><code>$LOG_DIR</code></td><td>Log directory: /home/$USERNAME/$DOMAIN/logs</td></tr>
                        <tr><td><code>$SERVER_IP</code></td><td>Server IP address</td></tr>
                        <tr><td><code>$PHP_VER</code></td><td>PHP version (e.g. 8.5)</td></tr>
                        <tr><td><code>$DB_NAME</code></td><td>Database name pattern: {username}_{domain_slug}</td></tr>
                        <tr><td><code>$DB_USER</code></td><td>Database user (same as hosting username)</td></tr>
                        <tr><td><code>$DB_PASS</code></td><td>MariaDB root password</td></tr>
                    </tbody>
                </table>
            </div>
        </details>

        <textarea class="form-control font-monospace" id="hook-add-code" rows="15" placeholder="#!/bin/bash&#10;# Your custom post-add-domain script here..." style="font-size:.85rem;tab-size:4;"></textarea>

        <div class="d-flex align-items-center gap-2 mt-3">
            <button class="btn btn-primary btn-sm" onclick="saveHook('add_domain')">
                <i class="fas fa-save me-1"></i>Save
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="validateHook('add_domain')">
                <i class="fas fa-check-circle me-1"></i>Validate
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="loadTiCoreTemplate()">
                <i class="fab fa-php me-1"></i>TiCore Template
            </button>
            <a href="https://ticore.tuxxin.com" target="_blank" class="text-muted small ms-1" style="font-size:.75rem;">
                <i class="fas fa-external-link-alt me-1"></i>Learn more
            </a>
        </div>
    </div>
</div>

<!-- ═══ POST DELETE DOMAIN ══════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-trash me-2 text-danger"></i>Post Delete Domain</h6>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="hook-delete-toggle" style="width:2.5em;height:1.3em;cursor:pointer">
            <label class="form-check-label small" for="hook-delete-toggle">Enabled</label>
        </div>
    </div>
    <div class="card-body">
        <details class="mb-3">
            <summary class="fw-semibold small text-muted" style="cursor:pointer">
                <i class="fas fa-info-circle me-1"></i>Available Variables
            </summary>
            <div class="mt-2">
                <table class="table table-sm table-bordered small mb-0">
                    <thead class="table-light"><tr><th style="width:140px">Variable</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$DOMAIN</code></td><td>Domain name being deleted</td></tr>
                        <tr><td><code>$USERNAME</code></td><td>Hosting account username</td></tr>
                        <tr><td><code>$PORT</code></td><td>Apache port that was assigned</td></tr>
                        <tr><td><code>$DOC_ROOT</code></td><td>Document root (may already be deleted)</td></tr>
                        <tr><td><code>$SERVER_IP</code></td><td>Server IP address</td></tr>
                    </tbody>
                </table>
            </div>
        </details>

        <textarea class="form-control font-monospace" id="hook-delete-code" rows="15" placeholder="#!/bin/bash&#10;# Your custom post-delete-domain script here..." style="font-size:.85rem;tab-size:4;"></textarea>

        <div class="d-flex align-items-center gap-2 mt-3">
            <button class="btn btn-primary btn-sm" onclick="saveHook('delete_domain')">
                <i class="fas fa-save me-1"></i>Save
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="validateHook('delete_domain')">
                <i class="fas fa-check-circle me-1"></i>Validate
            </button>
        </div>
    </div>
</div>

<script>
function showAlert(msg, type = 'success') {
    const el = document.getElementById('hooks-alert');
    el.className = `alert alert-${type}`;
    el.innerHTML = msg;
    setTimeout(() => el.className = 'd-none', 6000);
}

function showToast(msg, type = 'success') {
    const id = 'toast-' + Date.now();
    document.body.insertAdjacentHTML('beforeend',
        `<div id="${id}" class="toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3" style="z-index:9999">
            <div class="d-flex"><div class="toast-body">${msg}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button></div></div>`
    );
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ── Load hooks on page ready ─────────────────────────────────────────────────
function loadHooks() {
    fetch('/api/hook-scripts?action=get')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            document.getElementById('hook-add-toggle').checked = data.data.add_domain.enabled === '1';
            document.getElementById('hook-add-code').value = data.data.add_domain.code;
            document.getElementById('hook-delete-toggle').checked = data.data.delete_domain.enabled === '1';
            document.getElementById('hook-delete-code').value = data.data.delete_domain.code;
        })
        .catch(() => {});
}

document.addEventListener('DOMContentLoaded', loadHooks);

// ── Toggle handlers (immediate save) ─────────────────────────────────────────
document.getElementById('hook-add-toggle').addEventListener('change', function () {
    toggleHook('add_domain', this);
});
document.getElementById('hook-delete-toggle').addEventListener('change', function () {
    toggleHook('delete_domain', this);
});

function toggleHook(hookType, el) {
    const enabled = el.checked ? '1' : '0';
    const label = hookType === 'add_domain' ? 'Post Add Domain' : 'Post Delete Domain';
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('hook_type', hookType);
    fd.append('enabled', enabled);
    fetch('/api/hook-scripts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(label + ' hook ' + (enabled === '1' ? 'enabled' : 'disabled') + '.');
            } else {
                showToast(data.error || 'Failed to toggle.', 'danger');
                el.checked = !el.checked;
            }
        })
        .catch(() => { showToast('Request failed.', 'danger'); el.checked = !el.checked; });
}

// ── Save ─────────────────────────────────────────────────────────────────────
function saveHook(hookType) {
    const textareaId = hookType === 'add_domain' ? 'hook-add-code' : 'hook-delete-code';
    const code = document.getElementById(textareaId).value;
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('hook_type', hookType);
    fd.append('code', code);
    fetch('/api/hook-scripts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Hook script saved.');
            } else {
                let msg = data.error || 'Save failed.';
                if (data.details) msg += '<br><pre class="mb-0 mt-1 small">' + data.details.replace(/</g, '&lt;') + '</pre>';
                showAlert(msg, 'danger');
            }
        })
        .catch(() => showAlert('Request failed.', 'danger'));
}

// ── Validate ─────────────────────────────────────────────────────────────────
function validateHook(hookType) {
    const textareaId = hookType === 'add_domain' ? 'hook-add-code' : 'hook-delete-code';
    const code = document.getElementById(textareaId).value;
    if (!code.trim()) { showToast('No code to validate.', 'warning'); return; }
    const fd = new FormData();
    fd.append('action', 'validate');
    fd.append('code', code);
    fetch('/api/hook-scripts', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.valid) {
                showToast('Syntax OK — no errors found.', 'success');
            } else if (data.success && !data.valid) {
                showAlert('<strong>Syntax Error:</strong><br><pre class="mb-0 mt-1 small">' + (data.errors || '').replace(/</g, '&lt;') + '</pre>', 'danger');
            } else {
                showAlert(data.error || 'Validation failed.', 'danger');
            }
        })
        .catch(() => showAlert('Request failed.', 'danger'));
}

// ── TiCore Template ──────────────────────────────────────────────────────────
<?php
// Nowdoc prevents PHP from processing short open tags inside the template
$tiCoreTemplate = <<<'TICORE_TPL'
#!/bin/bash
# TiCore PHP Framework — Auto-Deploy Hook
# https://ticore.tuxxin.com
set -e
TICORE_REPO="https://github.com/tuxxin/TiCore"
SITE_DIR="/home/$USERNAME/$DOMAIN"
TEMP_DIR=$(mktemp -d)

echo "Deploying TiCore to $SITE_DIR ..."
git clone --depth 1 "$TICORE_REPO" "$TEMP_DIR/ticore"

# Remove iNetPanel welcome page
rm -f "$DOC_ROOT/index.php"

# Copy framework (TiCore uses www/ as web root which maps to DOC_ROOT)
cp -a "$TEMP_DIR/ticore/." "$SITE_DIR/"

# Remove demo site content (TiCore ships with a full example website)
rm -f "$SITE_DIR/TiCore/src/Controllers/HomeController.php"
rm -f "$SITE_DIR/TiCore/src/Controllers/FeaturesController.php"
rm -f "$SITE_DIR/TiCore/templates/default/home.php"
rm -f "$SITE_DIR/TiCore/templates/default/features.php"
rm -f "$SITE_DIR/TiCore/templates/default/compare.php"
rm -f "$SITE_DIR/TiCore/feeds/sitemap.php"
rm -f "$SITE_DIR/www/ads.txt"
rm -f "$SITE_DIR/www/assets/images/"*
rm -f "$SITE_DIR/README.md"
rm -rf "$SITE_DIR/.github"
rm -rf "$SITE_DIR/.git"

# Create a clean home page with account info
cat > "$SITE_DIR/TiCore/templates/default/home.php" << 'HOMEEOF'
<div style="max-width:600px;margin:80px auto;font-family:system-ui,sans-serif;color:#333;">
  <div style="background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:48px;text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:8px;">&#127758;</div>
    <h1 style="font-size:1.8rem;margin:0 0 4px;"><?= htmlspecialchars(SITE_TITLE) ?></h1>
    <p style="color:#888;margin:0 0 32px;">Your site is live and ready to build on.</p>
    <div style="background:#f8f9fa;border-radius:8px;padding:20px;text-align:left;font-family:monospace;font-size:.9rem;line-height:1.8;">
      <strong>Domain:</strong> <?= htmlspecialchars(SITE_TITLE) ?><br>
      <strong>PHP:</strong> <?= PHP_VERSION ?><br>
      <strong>Framework:</strong> <a href="https://ticore.tuxxin.com" target="_blank" style="color:#0d6efd;">TiCore PHP Framework</a>
    </div>
    <p style="margin-top:24px;font-size:.85rem;color:#999;">
      Replace this page by editing <code>TiCore/templates/default/home.php</code>
    </p>
  </div>
  <p style="text-align:center;margin-top:16px;font-size:.75rem;color:#aaa;">
    Powered by <a href="https://ticore.tuxxin.com" style="color:#888;">TiCore</a>
    &middot; by <a href="https://tuxxin.com" style="color:#888;">Tuxxin.com</a>
    &middot; Hosted with <a href="https://inetpanel.tuxxin.com" style="color:#888;">iNetPanel</a>
  </p>
</div>
HOMEEOF

# Add "by Tuxxin.com" to the footer layout
if [ -f "$SITE_DIR/TiCore/templates/default/layouts/footer.php" ]; then
    sed -i 's|</body>|<div style="text-align:center;padding:12px 0;font-size:.75rem;color:#aaa;">Powered by <a href="https://ticore.tuxxin.com" style="color:#888;">TiCore</a> \&middot; by <a href="https://tuxxin.com" style="color:#888;">Tuxxin.com</a> \&middot; Hosted with <a href="https://inetpanel.tuxxin.com" style="color:#888;">iNetPanel</a></div>\n</body>|' "$SITE_DIR/TiCore/templates/default/layouts/footer.php"
fi

# Install Composer dependencies
if [ -f "$SITE_DIR/TiCore/composer.json" ]; then
    cd "$SITE_DIR/TiCore"
    composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || true
fi

# Configure TiCore config.php
if [ -f "$SITE_DIR/TiCore/config.php" ]; then
    sed -i "s|'SITE_TITLE'.*|'SITE_TITLE', '$DOMAIN');|" "$SITE_DIR/TiCore/config.php"
    sed -i "s|'BASE_URL'.*|'BASE_URL', 'https://$DOMAIN');|" "$SITE_DIR/TiCore/config.php"
    sed -i "s|'GA4_ID'.*|'GA4_ID', '');|" "$SITE_DIR/TiCore/config.php"
    sed -i "s|'SITEMAP_ENABLED'.*|'SITEMAP_ENABLED', false);|" "$SITE_DIR/TiCore/config.php"
fi

# Configure .env from example (keep DB disabled)
if [ -f "$SITE_DIR/TiCore/.env-example" ]; then
    cp "$SITE_DIR/TiCore/.env-example" "$SITE_DIR/TiCore/.env"
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" "$SITE_DIR/TiCore/.env"
fi

# Set ownership and permissions
chown -R "$USERNAME:www-data" "$SITE_DIR"
find "$SITE_DIR" -type d -exec chmod 755 {} \;
find "$SITE_DIR" -type f -exec chmod 644 {} \;
chmod 750 "$SITE_DIR/TiCore" 2>/dev/null || true
chmod 640 "$SITE_DIR/TiCore/.env" 2>/dev/null || true

# Create writable logs dir
mkdir -p "$SITE_DIR/TiCore/logs"
chmod 775 "$SITE_DIR/TiCore/logs"
chown "$USERNAME:www-data" "$SITE_DIR/TiCore/logs"

rm -rf "$TEMP_DIR"
echo "TiCore deployed successfully for $DOMAIN"
TICORE_TPL;
?>
const TICORE_TEMPLATE = <?= json_encode($tiCoreTemplate) ?>;

function loadTiCoreTemplate() {
    const textarea = document.getElementById('hook-add-code');
    if (textarea.value.trim() && !confirm('This will replace the current code. Continue?')) return;
    textarea.value = TICORE_TEMPLATE;
    showToast('TiCore template loaded. Click Save to apply.', 'info');
}
</script>
