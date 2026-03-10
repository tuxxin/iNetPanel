<?php
// FILE: src/logs.php
// iNetPanel — System Logs (all loaded via AJAX for proper sudo support)


// Available log files (keys must match api/logs.php $SYSTEM_LOGS)
$systemLogs = [
    'update'     => ['label' => 'System Updates'],
    'backup'     => ['label' => 'Backup Jobs'],
    'lighttpd'   => ['label' => 'lighttpd Error'],
    'ssl'        => ['label' => 'SSL / Certbot'],
    'ssl_renew'  => ['label' => 'SSL Renewal'],
    'auth'       => ['label' => 'Auth / SSH'],
    'fail2ban'   => ['label' => 'Fail2Ban'],
    'panel_logs' => ['label' => 'Panel Logs', 'source' => 'db'],
];

// Domain log selector — DB first, fallback to Apache vhosts
$domains = [];
try {
    $domains = DB::fetchAll('SELECT domain_name FROM domains ORDER BY domain_name');
} catch (\Throwable $e) {}
if (empty($domains)) {
    foreach (glob('/etc/apache2/sites-available/*.conf') as $conf) {
        $name = basename($conf, '.conf');
        if (in_array($name, ['000-default', 'default-ssl', 'phpmyadmin'])) continue;
        $domains[] = ['domain_name' => $name];
    }
    sort($domains);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-file-lines me-2"></i>System Logs</h4>
    <button class="btn btn-outline-secondary btn-sm" id="refresh-log-btn">
        <i class="fas fa-sync me-1"></i>Refresh
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom-0 pt-3">
        <ul class="nav nav-tabs card-header-tabs" id="log-tabs" role="tablist">
            <?php $first = true; foreach ($systemLogs as $key => $info): ?>
            <li class="nav-item">
                <button class="nav-link <?= $first ? 'active' : '' ?>"
                        data-bs-toggle="tab"
                        data-bs-target="#log-<?= $key ?>"
                        data-logtype="system"
                        data-logkey="<?= $key ?>"
                        type="button">
                    <?= htmlspecialchars($info['label']) ?>
                </button>
            </li>
            <?php $first = false; endforeach; ?>
            <li class="nav-item">
                <button class="nav-link"
                        data-bs-toggle="tab"
                        data-bs-target="#log-domain"
                        data-logtype="domain"
                        type="button">
                    <i class="fas fa-globe me-1"></i>Domain Logs
                </button>
            </li>
        </ul>
    </div>

    <div class="card-body p-0">
        <div class="tab-content">

            <!-- System log tabs — all loaded via AJAX -->
            <?php $first = true; foreach ($systemLogs as $key => $info): ?>
            <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="log-<?= $key ?>">
                <pre id="pre-<?= $key ?>" class="m-0 p-3 bg-dark text-light rounded-bottom" style="height:500px;overflow-y:auto;font-size:.82rem;white-space:pre-wrap;word-break:break-all">(Loading...)</pre>
            </div>
            <?php $first = false; endforeach; ?>

            <!-- Domain log tab -->
            <div class="tab-pane fade" id="log-domain">
                <div class="p-3 border-bottom d-flex gap-3 align-items-end flex-wrap">
                    <div>
                        <label class="form-label fw-semibold small mb-1">Domain</label>
                        <select class="form-select form-select-sm" id="domain-log-sel" style="min-width:200px">
                            <option value="">Select domain…</option>
                            <?php foreach ($domains as $d): ?>
                            <option value="<?= htmlspecialchars($d['domain_name']) ?>">
                                <?= htmlspecialchars($d['domain_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-semibold small mb-1">Log Type</label>
                        <select class="form-select form-select-sm" id="domain-log-type">
                            <option value="apache_error">Apache Error</option>
                            <option value="apache_access">Apache Access</option>
                            <option value="php_error">PHP Error</option>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-sm" id="load-domain-log-btn">Load</button>
                </div>
                <pre id="pre-domain" class="m-0 p-3 bg-dark text-light rounded-bottom" style="height:442px;overflow-y:auto;font-size:.82rem;white-space:pre-wrap;word-break:break-all">(Select a domain and click Load)</pre>
            </div>

        </div>
    </div>
</div>

<script>
function scrollToBottom(preId) {
    const el = document.getElementById(preId);
    if (el) el.scrollTop = el.scrollHeight;
}

// Load a system log tab via API (uses sudo for restricted logs)
function loadSystemLog(key) {
    const pre = document.getElementById(`pre-${key}`);
    if (!pre) return;
    pre.textContent = 'Loading…';
    fetch(`/api/logs?action=tail&key=${encodeURIComponent(key)}`)
        .then(r => r.json())
        .then(data => {
            pre.textContent = data.content || '(empty)';
            pre.scrollTop = pre.scrollHeight;
        })
        .catch(() => { pre.textContent = '(Failed to load)'; });
}

// Load DB-sourced panel logs
function loadPanelLogs() {
    const pre = document.getElementById('pre-panel_logs');
    if (!pre) return;
    pre.textContent = 'Loading…';
    fetch('/api/logs?action=panel')
        .then(r => r.json())
        .then(data => {
            pre.textContent = data.content || '(No activity logged yet.)';
            pre.scrollTop = pre.scrollHeight;
        })
        .catch(() => { pre.textContent = '(Failed to load panel logs)'; });
}

document.addEventListener('DOMContentLoaded', function () {
    // Load the first (active) system log tab
    const activeBtn = document.querySelector('#log-tabs .nav-link.active');
    if (activeBtn) {
        const key = activeBtn.dataset.logkey;
        if (key === 'panel_logs') loadPanelLogs();
        else if (key) loadSystemLog(key);
    }

    // Load other tabs on switch (lazy)
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', function () {
            const key = this.dataset.logkey;
            if (!key) return; // domain tab
            if (key === 'panel_logs') loadPanelLogs();
            else loadSystemLog(key);

            const pane = document.querySelector(this.dataset.bsTarget);
            const pre  = pane?.querySelector('pre');
            if (pre) pre.scrollTop = pre.scrollHeight;
        });
    });
});

// Refresh current visible tab
document.getElementById('refresh-log-btn').addEventListener('click', function () {
    const active = document.querySelector('#log-tabs .nav-link.active');
    if (!active) return;
    const type = active.dataset.logtype;
    if (type === 'domain') {
        document.getElementById('load-domain-log-btn').click();
    } else {
        const key = active.dataset.logkey;
        if (key === 'panel_logs') loadPanelLogs();
        else loadSystemLog(key);
    }
});

// Load domain log
document.getElementById('load-domain-log-btn').addEventListener('click', function () {
    const domain = document.getElementById('domain-log-sel').value;
    const logtype = document.getElementById('domain-log-type').value;
    const pre = document.getElementById('pre-domain');
    if (!domain) { pre.textContent = 'Please select a domain.'; return; }
    pre.textContent = 'Loading…';
    fetch(`/api/logs?action=domain&domain=${encodeURIComponent(domain)}&logtype=${encodeURIComponent(logtype)}`)
        .then(r => r.json())
        .then(data => {
            pre.textContent = data.content || '(empty)';
            pre.scrollTop = pre.scrollHeight;
        })
        .catch(() => { pre.textContent = 'Request failed.'; });
});
</script>
