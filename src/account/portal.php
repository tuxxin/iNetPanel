<?php
// FILE: src/account/portal.php
// iNetPanel — Account holder portal (multi-domain, read-only account info)

$accountUser = AccountAuth::user();
$username = $accountUser['username'] ?? AccountAuth::username();
$allDomains = $accountUser['domains'] ?? [];

// Domain selector: use query param or default to first domain
$domain = $_GET['domain'] ?? ($allDomains[0]['domain_name'] ?? $username);

// Load account record from DB
$row = DB::fetchOne(
    'SELECT d.*, h.username as hosting_username FROM domains d LEFT JOIN hosting_users h ON d.hosting_user_id = h.id WHERE d.domain_name = ?',
    [$domain]
);
if (!$row) {
    echo '<div class="alert alert-danger">Account not found. Please contact your administrator.</div>';
    return;
}

// Verify this domain belongs to the logged-in user
$owner = $row['hosting_username'] ?? $domain;
if ($owner !== $username && $domain !== $username) {
    echo '<div class="alert alert-danger">Access denied.</div>';
    return;
}

// Derived values
$dbName  = $username . '_' . str_replace(['.', '-'], '_', $domain);
$docRoot = $row['document_root'] ?? "/home/{$username}/{$domain}/www";
$phpVer  = $row['php_version']  ?? '—';
$port    = $row['port']         ?? '—';
$status  = $row['status']       ?? 'active';

// Disk usage (best-effort)
$disk = is_dir($docRoot)
    ? trim((string) shell_exec('du -sh ' . escapeshellarg($docRoot) . ' 2>/dev/null | cut -f1') ?: '—')
    : '—';

// CF enabled?
$cfEnabled = DB::setting('cf_enabled', '0') === '1';
$serverIp  = trim((string) shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}'"));
if (!$serverIp) {
    $serverIp = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print $1}'"));
}
$serverHost = gethostname();
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-user me-2 text-primary"></i><?= htmlspecialchars($username) ?></h4>
        <span class="text-muted small">Account Dashboard</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if (count($allDomains) > 1): ?>
        <select class="form-select form-select-sm" style="width:auto" onchange="window.location.href='/user/dashboard?domain='+encodeURIComponent(this.value)">
            <?php foreach ($allDomains as $d): ?>
            <option value="<?= htmlspecialchars($d['domain_name']) ?>" <?= $d['domain_name'] === $domain ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['domain_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <span class="badge bg-<?= $status === 'active' ? 'success' : 'warning text-dark' ?> rounded-pill fs-6 px-3 py-2">
            <i class="fas fa-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i><?= ucfirst($status) ?>
        </span>
    </div>
</div>

<!-- Account Info -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>Account Information</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="ps-4 text-muted fw-semibold" style="width:40%">Domain</td>
                            <td class="pe-4 fw-bold"><?= htmlspecialchars($domain) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">PHP Version</td>
                            <td class="pe-4"><?= htmlspecialchars($phpVer) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">Web Root</td>
                            <td class="pe-4"><code style="font-size:0.8rem"><?= htmlspecialchars($docRoot) ?></code></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">Disk Usage</td>
                            <td class="pe-4"><?= htmlspecialchars($disk) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">Server Port</td>
                            <td class="pe-4"><?= htmlspecialchars((string)$port) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0"><i class="fas fa-database me-2 text-primary"></i>Database Information</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td class="ps-4 text-muted fw-semibold" style="width:40%">Database Name</td>
                            <td class="pe-4 fw-bold"><?= htmlspecialchars($dbName) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">DB Username</td>
                            <td class="pe-4"><?= htmlspecialchars($username) ?></td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">DB Host</td>
                            <td class="pe-4">localhost</td></tr>
                        <tr><td class="ps-4 text-muted fw-semibold">DB Port</td>
                            <td class="pe-4">3306</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top py-2 px-4">
                <a href="http://<?= htmlspecialchars($serverIp) ?>:8888" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                    <i class="fas fa-database me-2"></i>Open phpMyAdmin
                </a>
            </div>
        </div>
    </div>
</div>

<!-- FTP / SSH Access -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0"><i class="fas fa-terminal me-2 text-primary"></i>FTP / SSH Access</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">Hostname</div>
                <code><?= htmlspecialchars($serverIp) ?></code>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">FTP Port</div>
                <code>21</code>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">SSH Port</div>
                <code><?= htmlspecialchars(DB::setting('ssh_port', '1022')) ?></code>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="small text-muted fw-semibold mb-1">Username</div>
                <code><?= htmlspecialchars($username) ?></code>
            </div>
        </div>
    </div>
</div>

<?php if ($cfEnabled): ?>
<?php $isSubdomain = substr_count($domain, '.') > 1; ?>

<!-- DNS Records -->
<div class="card border-0 shadow-sm mb-4<?= $isSubdomain ? ' opacity-50' : '' ?>">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-network-wired me-2 text-primary"></i>DNS Records</h6>
        <span class="badge bg-warning text-dark"><i class="fab fa-cloudflare me-1"></i>Cloudflare</span>
    </div>
    <div class="card-body<?= $isSubdomain ? '' : ' p-0' ?>">
        <?php if ($isSubdomain): ?>
        <div class="text-center text-muted py-3">
            <i class="fas fa-info-circle me-1"></i> DNS management is not available for sub-domains. Contact your administrator to manage DNS for the parent domain.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Name</th>
                        <th>Type</th>
                        <th>Content</th>
                        <th class="pe-4">TTL</th>
                    </tr>
                </thead>
                <tbody id="dns-tbody">
                    <tr><td colspan="4" class="text-center text-muted py-3 small">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Email Routing -->
<div class="card border-0 shadow-sm mb-4<?= $isSubdomain ? ' opacity-50' : '' ?>">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-envelope me-2 text-primary"></i>Email Routing</h6>
        <span class="badge bg-warning text-dark"><i class="fab fa-cloudflare me-1"></i>Cloudflare</span>
    </div>
    <div class="card-body<?= $isSubdomain ? '' : ' p-0' ?>">
        <?php if ($isSubdomain): ?>
        <div class="text-center text-muted py-3">
            <i class="fas fa-info-circle me-1"></i> Email routing is not available for sub-domains. Contact your administrator to manage email for the parent domain.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">From</th>
                        <th>Forward To</th>
                        <th class="pe-4">Status</th>
                    </tr>
                </thead>
                <tbody id="email-tbody">
                    <tr><td colspan="3" class="text-center text-muted py-3 small">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isSubdomain): ?>
<script>
const DOMAIN = <?= json_encode($domain) ?>;

// Load DNS records for this domain's zone
fetch('/api/account?action=dns&domain=' + encodeURIComponent(DOMAIN))
    .then(r => r.json())
    .then(data => {
        const tbody = document.getElementById('dns-tbody');
        if (!data.success || !data.records || !data.records.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">' + (data.error || 'No DNS records found.') + '</td></tr>';
            return;
        }
        tbody.innerHTML = data.records.map(r => `<tr>
            <td class="ps-4 small fw-medium">${r.name}</td>
            <td><span class="badge bg-secondary-subtle text-secondary">${r.type}</span></td>
            <td class="small text-truncate" style="max-width:260px">${r.content}</td>
            <td class="pe-4 small text-muted">${r.ttl === 1 ? 'Auto' : r.ttl}</td>
        </tr>`).join('');
    })
    .catch(() => {
        document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3 small">Failed to load DNS records.</td></tr>';
    });

// Load email routing rules for this domain's zone
fetch('/api/account?action=email&domain=' + encodeURIComponent(DOMAIN))
    .then(r => r.json())
    .then(data => {
        const tbody = document.getElementById('email-tbody');
        if (!data.success || !data.rules || !data.rules.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3 small">' + (data.error || 'No email routing rules.') + '</td></tr>';
            return;
        }
        tbody.innerHTML = data.rules.map(rule => {
            const from    = rule.matchers?.[0]?.value ?? '—';
            const to      = rule.actions?.[0]?.value  ?? '—';
            const enabled = rule.enabled !== false;
            return `<tr>
                <td class="ps-4 small fw-medium">${from}</td>
                <td class="small">${Array.isArray(to) ? to.join(', ') : to}</td>
                <td class="pe-4"><span class="badge ${enabled ? 'bg-success' : 'bg-secondary'}">${enabled ? 'Active' : 'Disabled'}</span></td>
            </tr>`;
        }).join('');
    })
    .catch(() => {
        document.getElementById('email-tbody').innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3 small">Failed to load email rules.</td></tr>';
    });
</script>
<?php endif; ?>
<?php endif; ?>
