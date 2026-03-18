<?php
// FILE: src/account/portal.php
// iNetPanel — Account holder portal (sidebar nav, multi-section)

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
$sshPort = DB::setting('ssh_port', '1022');
$isSubdomain = substr_count($domain, '.') > 1;
?>

<!-- Header: username + domain selector -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
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

<!-- Top Navigation Tabs -->
<ul class="nav nav-tabs mb-4" id="portal-nav">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#p-overview"><i class="fas fa-home me-1"></i>Overview</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#p-database"><i class="fas fa-database me-1"></i>Database</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#p-ftp-ssh"><i class="fas fa-terminal me-1"></i>FTP / SSH</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#p-files"><i class="fas fa-file-code me-1"></i>File Manager</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#p-backups"><i class="fas fa-archive me-1"></i>Backups</button></li>
    <?php if ($cfEnabled): ?>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#p-dns"><i class="fas fa-globe me-1"></i>DNS</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#p-email"><i class="fas fa-envelope me-1"></i>Email</button></li>
    <?php endif; ?>
</ul>

<!-- Content Area -->
<div class="tab-content" id="portal-content">

            <!-- ═══ OVERVIEW ═══ -->
            <div class="tab-pane fade show active" id="p-overview">
                <h5 class="fw-bold mb-3"><i class="fas fa-home me-2 text-primary"></i>Account Overview</h5>
                <div class="card border shadow-sm">
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr><td class="ps-4 text-muted fw-semibold" style="width:35%">Domain</td>
                                    <td class="pe-4 fw-bold"><?= htmlspecialchars($domain) ?></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">PHP Version</td>
                                    <td class="pe-4"><?= htmlspecialchars($phpVer) ?></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">Web Root</td>
                                    <td class="pe-4"><code style="font-size:0.8rem"><?= htmlspecialchars($docRoot) ?></code></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">Disk Usage</td>
                                    <td class="pe-4"><?= htmlspecialchars($disk) ?></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">Server Port</td>
                                    <td class="pe-4"><?= htmlspecialchars((string)$port) ?></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">Status</td>
                                    <td class="pe-4">
                                        <span class="badge bg-<?= $status === 'active' ? 'success' : 'warning text-dark' ?>"><?= ucfirst($status) ?></span>
                                    </td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══ DATABASE ═══ -->
            <div class="tab-pane fade" id="p-database">
                <h5 class="fw-bold mb-3"><i class="fas fa-database me-2 text-primary"></i>Database</h5>
                <div class="card border shadow-sm mb-3">
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr><td class="ps-4 text-muted fw-semibold" style="width:35%">DB Username</td>
                                    <td class="pe-4 fw-bold"><code><?= htmlspecialchars($username) ?></code></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">DB Host</td>
                                    <td class="pe-4"><code>localhost</code></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">DB Port</td>
                                    <td class="pe-4"><code>3306</code></td></tr>
                                <tr><td class="ps-4 text-muted fw-semibold">DB Prefix</td>
                                    <td class="pe-4"><code><?= htmlspecialchars($username) ?>_</code></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white border-top py-2 px-4">
                        <a href="/user/phpmyadmin" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                            <i class="fas fa-database me-2"></i>Open phpMyAdmin
                        </a>
                    </div>
                </div>
                <div class="alert alert-info small py-2 mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Use phpMyAdmin to create and manage your databases. All databases must use the prefix
                    <code><?= htmlspecialchars($username) ?>_</code>
                    (e.g., <code><?= htmlspecialchars($username) ?>_blog</code>, <code><?= htmlspecialchars($username) ?>_store</code>).
                    Your database user has full access to all databases matching this prefix.
                    Your database password is the same as your FTP/SSH password.
                </div>
            </div>

            <!-- ═══ FTP / SSH ═══ -->
            <div class="tab-pane fade" id="p-ftp-ssh">
                <h5 class="fw-bold mb-3"><i class="fas fa-terminal me-2 text-primary"></i>FTP / SSH Access</h5>
                <div class="card border shadow-sm mb-4">
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
                                <code><?= htmlspecialchars($sshPort) ?></code>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <div class="small text-muted fw-semibold mb-1">Username</div>
                                <code><?= htmlspecialchars($username) ?></code>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SSH Keys -->
                <h6 class="fw-bold mb-2"><i class="fas fa-key me-2 text-muted"></i>SSH Keys</h6>
                <div class="card border shadow-sm">
                    <div class="card-body p-0">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Fingerprint</th>
                                    <th>Comment / Label</th>
                                    <th>Type</th>
                                    <th class="text-end pe-4">Delete</th>
                                </tr>
                            </thead>
                            <tbody id="ssh-keys-tbody">
                                <tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white border-top py-2 px-4">
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#ssh-import-collapse">
                                <i class="fas fa-file-import me-1"></i>Import Key
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="generateKey()">
                                <i class="fas fa-bolt me-1"></i>Generate New Key
                            </button>
                        </div>
                        <div class="collapse mt-3" id="ssh-import-collapse">
                            <div class="mb-2">
                                <textarea class="form-control form-control-sm font-monospace" id="ssh-import-key" rows="3"
                                          placeholder="Paste your public key here (ssh-ed25519 AAAA... or ssh-rsa AAAA...)"></textarea>
                            </div>
                            <button class="btn btn-sm btn-primary" onclick="importKey()">
                                <i class="fas fa-plus me-1"></i>Add Key
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Generated key modal -->
                <div class="modal fade" id="genKeyModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-key me-2"></i>Generated SSH Key</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-warning small py-2">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Save this private key now — it cannot be retrieved later.
                                </div>
                                <label class="form-label fw-semibold small">Private Key</label>
                                <textarea class="form-control font-monospace small" id="gen-private-key" rows="12" readonly></textarea>
                                <button class="btn btn-sm btn-outline-secondary mt-2" onclick="navigator.clipboard.writeText(document.getElementById('gen-private-key').value);showToast('Copied to clipboard')">
                                    <i class="fas fa-copy me-1"></i>Copy
                                </button>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ FILE MANAGER ═══ -->
            <div class="tab-pane fade" id="p-files">
                <h5 class="fw-bold mb-3"><i class="fas fa-file-code me-2 text-primary"></i>File Manager</h5>
                <div class="alert alert-info small py-2">
                    <i class="fas fa-info-circle me-1"></i>
                    Edit <code>.htaccess</code> files to control URL rewriting, redirects, and access rules.
                    Select a directory below to view or edit its <code>.htaccess</code> file.
                </div>
                <div class="d-flex gap-3" style="min-height:350px;">
                    <!-- Directory tree -->
                    <div class="border rounded bg-light p-2" style="width:220px;flex-shrink:0;overflow-y:auto;max-height:500px;">
                        <div class="small fw-bold text-muted mb-2"><i class="fas fa-folder me-1"></i>Directories</div>
                        <div id="dir-tree">
                            <div class="text-muted small py-2 text-center">Loading...</div>
                        </div>
                    </div>
                    <!-- Editor -->
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="small fw-semibold text-muted" id="htaccess-path">.htaccess</span>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleProtect()" id="protect-btn" title="Password protect this directory">
                                    <i class="fas fa-lock me-1"></i>Protect Directory
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="saveHtaccess()">
                                    <i class="fas fa-save me-1"></i>Save
                                </button>
                            </div>
                        </div>
                        <textarea class="form-control font-monospace small" id="htaccess-editor" rows="16"
                                  placeholder="Select a directory to load its .htaccess file..."></textarea>
                        <div id="htaccess-alert" class="mt-2"></div>

                        <!-- Directory protection panel -->
                        <div class="collapse mt-3" id="protect-collapse">
                            <div class="card border">
                                <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 small fw-bold"><i class="fas fa-shield-alt me-1"></i>Password Protection</h6>
                                    <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="disableProtection()" id="remove-protect-btn" style="display:none">
                                        <i class="fas fa-times me-1"></i>Remove All
                                    </button>
                                </div>
                                <div class="card-body py-2">
                                    <!-- Existing users list -->
                                    <div id="protect-users-list" class="mb-2"></div>
                                    <!-- Add user form -->
                                    <div class="row g-2 align-items-end">
                                        <div class="col-sm-4">
                                            <label class="form-label small mb-1">Username</label>
                                            <input type="text" class="form-control form-control-sm" id="protect-user" placeholder="username">
                                        </div>
                                        <div class="col-sm-4">
                                            <label class="form-label small mb-1">Password</label>
                                            <input type="password" class="form-control form-control-sm" id="protect-pass" placeholder="password">
                                        </div>
                                        <div class="col-sm-4">
                                            <button class="btn btn-sm btn-success w-100" onclick="addProtectUser()">
                                                <i class="fas fa-plus me-1"></i>Add User
                                            </button>
                                        </div>
                                    </div>
                                    <div id="protect-alert" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ BACKUPS ═══ -->
            <div class="tab-pane fade" id="p-backups">
                <h5 class="fw-bold mb-3"><i class="fas fa-archive me-2 text-primary"></i>Backups</h5>
                <div class="alert alert-info small py-2">
                    <i class="fas fa-info-circle me-1"></i>
                    Automated backups include your site files and database exports. Backups are retained for
                    <?= htmlspecialchars(DB::setting('backup_retention', '3')) ?> days.
                </div>
                <div class="card border shadow-sm">
                    <div class="card-body p-0">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Filename</th>
                                    <th>Size</th>
                                    <th>Date</th>
                                    <th class="text-end pe-4">Download</th>
                                </tr>
                            </thead>
                            <tbody id="backups-tbody">
                                <tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($cfEnabled): ?>
            <!-- ═══ DNS ═══ -->
            <div class="tab-pane fade" id="p-dns">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-globe me-2 text-primary"></i>DNS Records</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if (!$isSubdomain): ?>
                        <button class="btn btn-sm btn-primary" id="add-dns-btn"><i class="fas fa-plus me-1"></i>Add Record</button>
                        <?php endif; ?>
                        <span class="badge bg-warning text-dark"><i class="fab fa-cloudflare me-1"></i>Cloudflare</span>
                    </div>
                </div>
                <?php if (!$isSubdomain): ?>
                <div class="card border shadow-sm mb-3">
                    <div class="card-body py-2 d-flex gap-4 flex-wrap align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="ddos-toggle"
                                       style="width:2.5em;height:1.3em" disabled>
                            </div>
                            <span class="fw-semibold small"><i class="fas fa-shield-alt text-danger me-1"></i>DDoS Mode</span>
                            <span class="text-muted small">— Enables Cloudflare "Under Attack" mode</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="devmode-toggle"
                                       style="width:2.5em;height:1.3em" disabled>
                            </div>
                            <span class="fw-semibold small"><i class="fas fa-code text-info me-1"></i>Development Mode</span>
                            <span class="text-muted small">— Bypasses Cloudflare cache for 3 hours</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($isSubdomain): ?>
                <div class="text-center text-muted py-3">
                    <i class="fas fa-info-circle me-1"></i> DNS management is not available for sub-domains.
                </div>
                <?php else: ?>
                <div class="card border shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Type</th>
                                        <th>Name</th>
                                        <th>Content</th>
                                        <th>TTL</th>
                                        <th>Proxied</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="dns-tbody">
                                    <tr><td colspan="6" class="text-center text-muted py-3 small">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══ EMAIL ═══ -->
            <div class="tab-pane fade" id="p-email">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-envelope me-2 text-primary"></i>Email Routing</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if (!$isSubdomain): ?>
                        <button class="btn btn-sm btn-primary" id="add-email-btn"><i class="fas fa-plus me-1"></i>Add Rule</button>
                        <?php endif; ?>
                        <span class="badge bg-warning text-dark"><i class="fab fa-cloudflare me-1"></i>Cloudflare</span>
                    </div>
                </div>
                <?php if ($isSubdomain): ?>
                <div class="text-center text-muted py-3">
                    <i class="fas fa-info-circle me-1"></i> Email routing is not available for sub-domains.
                </div>
                <?php else: ?>
                <div class="card border shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">From</th>
                                        <th>Forward To</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="email-tbody">
                                    <tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$isSubdomain): ?>
            <!-- DNS Modal -->
            <div class="modal fade" id="dnsModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="dns-modal-title">Add DNS Record</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="dns-record-id">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small">Type</label>
                                    <select class="form-select" id="dns-type">
                                        <option>A</option><option>AAAA</option><option>CNAME</option>
                                        <option>MX</option><option>TXT</option><option>NS</option>
                                        <option>SRV</option><option>CAA</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold small">Name</label>
                                    <input type="text" class="form-control" id="dns-name" placeholder="@ or subdomain">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small">Content / Value</label>
                                    <input type="text" class="form-control" id="dns-content" placeholder="IP address or target">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small">TTL</label>
                                    <input type="number" class="form-control" id="dns-ttl" value="1" min="1">
                                    <div class="form-text">1 = Auto</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small">Priority (MX/SRV)</label>
                                    <input type="number" class="form-control" id="dns-priority" value="10" min="0">
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="dns-proxied" checked style="width:2em;height:1em">
                                        <label class="form-check-label fw-semibold small" for="dns-proxied">CF Proxied</label>
                                    </div>
                                </div>
                            </div>
                            <div id="dns-modal-error" class="d-none mt-3"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="dns-save-btn">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="dns-save-spinner"></span>
                                Save Record
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email Modal -->
            <div class="modal fade" id="emailModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Add Forwarding Rule</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">From (source address)</label>
                                <input type="text" class="form-control" id="email-from" placeholder="support@<?= htmlspecialchars($domain) ?>">
                                <div class="form-text">Use <code>*@<?= htmlspecialchars($domain) ?></code> to catch all.</div>
                                <div class="invalid-feedback" id="email-from-hint">Enter a valid email address (e.g. info@<?= htmlspecialchars($domain) ?>)</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Forward To</label>
                                <input type="email" class="form-control" id="email-to" placeholder="you@gmail.com">
                                <div class="form-text">Must be a verified destination address.</div>
                                <div class="invalid-feedback" id="email-to-hint">Enter a valid email address</div>
                            </div>
                            <div id="email-modal-error" class="d-none"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="email-save-btn" disabled>
                                <span class="spinner-border spinner-border-sm d-none me-1" id="email-save-spinner"></span>
                                Add Rule
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

</div><!-- /tab-content -->

<script>
const DOMAIN = <?= json_encode($domain) ?>;
const USERNAME = <?= json_encode($username) ?>;
const DOC_ROOT = <?= json_encode($docRoot) ?>;

function showToast(msg, type = 'success') {
    const id = 'toast-' + Date.now();
    document.body.insertAdjacentHTML('beforeend',
        `<div id="${id}" class="toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3" role="alert" style="z-index:9999">
            <div class="d-flex"><div class="toast-body">${msg}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

// ── SSH Keys ────────────────────────────────────────────────────────────────

function apiFetch(url, opts) {
    return fetch(url, opts).then(r => {
        if (r.redirected || !r.ok) throw new Error('Session expired');
        const ct = r.headers.get('content-type') || '';
        if (!ct.includes('json')) throw new Error('Not JSON');
        return r.json();
    });
}

function loadKeys() {
    const tbody = document.getElementById('ssh-keys-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>';
    apiFetch('/api/account?action=ssh_keys_list&domain=' + encodeURIComponent(DOMAIN))
        .then(data => {
            const keys = data.data || data.keys || [];
            if (!data.success || !keys.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">' + (data.error || 'No SSH keys found.') + '</td></tr>';
                return;
            }
            tbody.innerHTML = keys.map(k => `<tr>
                <td class="ps-4 small font-monospace">${k.fingerprint || '—'}</td>
                <td class="small">${k.comment || '—'}</td>
                <td class="small"><span class="badge bg-secondary">${k.type || '—'}</span></td>
                <td class="text-end pe-4">
                    <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="deleteKey('${k.fingerprint}')" title="Delete"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`).join('');
        })
        .catch(e => {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3 small">' + (e.message === 'Session expired' ? 'Session expired. <a href="/user/login">Login again</a>' : 'Failed to load SSH keys.') + '</td></tr>';
        });
}

function importKey() {
    const key = document.getElementById('ssh-import-key').value.trim();
    if (!key) { showToast('Paste a public key first.', 'warning'); return; }
    const fd = new FormData();
    fd.append('action', 'ssh_keys_add');
    fd.append('domain', DOMAIN);
    fd.append('key', key);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) {
                showToast('SSH key imported.');
                document.getElementById('ssh-import-key').value = '';
                bootstrap.Collapse.getInstance(document.getElementById('ssh-import-collapse'))?.hide();
                loadKeys();
            } else {
                showToast(data.error || 'Failed to import key.', 'danger');
            }
        });
}

function deleteKey(fingerprint) {
    if (!confirm('Delete this SSH key?')) return;
    const fd = new FormData();
    fd.append('action', 'ssh_keys_delete');
    fd.append('domain', DOMAIN);
    fd.append('fingerprint', fingerprint);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) { showToast('Key deleted.'); loadKeys(); }
            else showToast(data.error || 'Delete failed.', 'danger');
        });
}

function generateKey() {
    const fd = new FormData();
    fd.append('action', 'ssh_keys_generate');
    fd.append('domain', DOMAIN);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) {
                document.getElementById('gen-private-key').value = data.private_key || '';
                new bootstrap.Modal(document.getElementById('genKeyModal')).show();
                loadKeys();
            } else {
                showToast(data.error || 'Generate failed.', 'danger');
            }
        });
}

// ── File Manager (.htaccess) ────────────────────────────────────────────────

let currentDir = '';

function loadDirs() {
    apiFetch('/api/account?action=list_dirs&domain=' + encodeURIComponent(DOMAIN))
        .then(data => {
            const tree = document.getElementById('dir-tree');
            if (!data.success || !data.dirs || !data.dirs.length) {
                tree.innerHTML = '<div class="text-muted small py-2 text-center">No directories found.</div>';
                return;
            }
            tree.innerHTML = data.dirs.map(d => {
                const icon = d.has_htaccess ? 'fa-file-alt text-success' : 'fa-folder text-muted';
                const label = d.path === '' ? '/ (web root)' : d.path;
                const protIcon = d.is_protected ? ' <i class="fas fa-lock text-warning" title="Protected"></i>' : '';
                return `<div class="dir-item small py-1 px-2 rounded cursor-pointer" style="cursor:pointer"
                    onclick="selectDir('${d.path.replace(/'/g, "\\'")}')" data-dir="${d.path}">
                    <i class="fas ${icon} me-1"></i>${label}${protIcon}
                </div>`;
            }).join('');
            // Auto-select root
            selectDir('');
        })
        .catch(() => {
            document.getElementById('dir-tree').innerHTML = '<div class="text-muted small py-2 text-center">Failed to load.</div>';
        });
}

function selectDir(dir) {
    currentDir = dir;
    const label = dir === '' ? '/ (web root) .htaccess' : dir + '/.htaccess';
    document.getElementById('htaccess-path').textContent = label;
    document.getElementById('htaccess-alert').innerHTML = '';

    // Highlight selected
    document.querySelectorAll('#dir-tree .dir-item').forEach(el => {
        el.classList.toggle('bg-primary', el.dataset.dir === dir);
        el.classList.toggle('text-white', el.dataset.dir === dir);
    });

    // Reset protection panel
    document.getElementById('protect-user').value = '';
    document.getElementById('protect-pass').value = '';
    document.getElementById('protect-alert').innerHTML = '';
    const collapseEl = document.getElementById('protect-collapse');
    const collapseInst = bootstrap.Collapse.getInstance(collapseEl);
    if (collapseInst) collapseInst.hide();

    // Load .htaccess content
    const fd = new FormData();
    fd.append('action', 'htaccess_read');
    fd.append('domain', DOMAIN);
    fd.append('dir', dir);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            document.getElementById('htaccess-editor').value = data.success ? (data.content || '') : '';
            if (data.is_protected) {
                document.getElementById('protect-btn').innerHTML = '<i class="fas fa-lock me-1"></i>Protected';
                document.getElementById('protect-btn').classList.replace('btn-outline-secondary', 'btn-outline-success');
            } else {
                document.getElementById('protect-btn').innerHTML = '<i class="fas fa-lock me-1"></i>Protect Directory';
                document.getElementById('protect-btn').classList.replace('btn-outline-success', 'btn-outline-secondary');
            }
            loadProtectUsers();
        });
}

function saveHtaccess() {
    const fd = new FormData();
    fd.append('action', 'htaccess_save');
    fd.append('domain', DOMAIN);
    fd.append('dir', currentDir);
    fd.append('content', document.getElementById('htaccess-editor').value);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            const el = document.getElementById('htaccess-alert');
            if (data.success) {
                showToast('.htaccess saved.');
                el.innerHTML = '';
                loadDirs();
            } else {
                el.innerHTML = '<div class="alert alert-danger small py-2">' + (data.error || 'Save failed.') + '</div>';
            }
        });
}

function toggleProtect() {
    const collapse = document.getElementById('protect-collapse');
    bootstrap.Collapse.getOrCreateInstance(collapse).toggle();
}

function loadProtectUsers() {
    const list = document.getElementById('protect-users-list');
    const fd = new FormData();
    fd.append('action', 'htpasswd_users');
    fd.append('domain', DOMAIN);
    fd.append('dir', currentDir);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            const users = data.users || [];
            document.getElementById('remove-protect-btn').style.display = users.length ? '' : 'none';
            if (!users.length) {
                list.innerHTML = '<div class="small text-muted mb-1">No users configured.</div>';
                return;
            }
            list.innerHTML = '<div class="small fw-semibold text-muted mb-1">Authorized users:</div>' +
                users.map(u => `<span class="badge bg-secondary me-1 mb-1">${u}
                    <button class="btn-close btn-close-white ms-1" style="font-size:.5rem" onclick="deleteProtectUser('${u.replace(/'/g, "\\'")}')" title="Remove"></button>
                </span>`).join('');
        })
        .catch(() => { list.innerHTML = ''; });
}

function addProtectUser() {
    const user = document.getElementById('protect-user').value.trim();
    const pass = document.getElementById('protect-pass').value.trim();
    if (!user || !pass) { showToast('Username and password required.', 'warning'); return; }
    const fd = new FormData();
    fd.append('action', 'dir_protect');
    fd.append('domain', DOMAIN);
    fd.append('dir', currentDir);
    fd.append('enabled', '1');
    fd.append('ht_user', user);
    fd.append('ht_pass', pass);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) {
                showToast('User "' + user + '" added.');
                document.getElementById('protect-user').value = '';
                document.getElementById('protect-pass').value = '';
                loadProtectUsers();
                loadDirs();
            } else {
                showToast(data.error || 'Failed.', 'danger');
            }
        });
}

function deleteProtectUser(user) {
    if (!confirm('Remove user "' + user + '" from this directory?')) return;
    const fd = new FormData();
    fd.append('action', 'htpasswd_delete_user');
    fd.append('domain', DOMAIN);
    fd.append('dir', currentDir);
    fd.append('ht_user', user);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) {
                showToast('User removed.');
                loadProtectUsers();
                selectDir(currentDir);
                loadDirs();
            } else {
                showToast(data.error || 'Failed.', 'danger');
            }
        });
}

function disableProtection() {
    if (!confirm('Remove all password protection from this directory?')) return;
    const fd = new FormData();
    fd.append('action', 'dir_protect');
    fd.append('domain', DOMAIN);
    fd.append('dir', currentDir);
    fd.append('enabled', '0');
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) {
                showToast('Protection removed.');
                loadProtectUsers();
                selectDir(currentDir);
                loadDirs();
            } else {
                showToast(data.error || 'Failed.', 'danger');
            }
        });
}

// ── Backups ─────────────────────────────────────────────────────────────────

function loadBackups() {
    const tbody = document.getElementById('backups-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>';
    apiFetch('/api/account?action=backups_list&domain=' + encodeURIComponent(DOMAIN))
        .then(data => {
            const backups = data.backups || [];
            if (!data.success || !backups.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">No backups found.</td></tr>';
                return;
            }
            tbody.innerHTML = backups.map(b => `<tr>
                <td class="ps-4 small fw-medium"><i class="fas fa-file-archive text-muted me-1"></i>${b.filename}</td>
                <td class="small">${b.size_h}</td>
                <td class="small text-muted">${b.date}</td>
                <td class="text-end pe-4">
                    <a href="/api/account?action=backup_download&domain=${encodeURIComponent(DOMAIN)}&file=${encodeURIComponent(b.filename)}" class="btn btn-sm btn-outline-primary py-0 px-2">
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                </td>
            </tr>`).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3 small">Failed to load backups.</td></tr>';
        });
}

<?php if ($cfEnabled && !$isSubdomain): ?>
// ── DNS ─────────────────────────────────────────────────────────────────────

function loadZoneSettings() {
    const ddos = document.getElementById('ddos-toggle');
    const dev  = document.getElementById('devmode-toggle');
    if (!ddos || !dev) return;
    ddos.disabled = true;
    dev.disabled = true;
    apiFetch('/api/account?action=zone_settings&domain=' + encodeURIComponent(DOMAIN))
        .then(data => {
            if (data.success) {
                ddos.checked = data.security_level === 'under_attack';
                dev.checked  = data.development_mode === 'on';
                ddos.disabled = false;
                dev.disabled = false;
            }
        }).catch(() => {});
}

document.getElementById('ddos-toggle')?.addEventListener('change', function () {
    const toggle = this;
    toggle.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_ddos_mode');
    fd.append('domain', DOMAIN);
    fd.append('enabled', toggle.checked ? '1' : '0');
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            toggle.disabled = false;
            if (data.success) {
                showToast(toggle.checked ? 'DDoS mode enabled — Under Attack mode is active.' : 'DDoS mode disabled.', toggle.checked ? 'warning' : 'success');
            } else {
                toggle.checked = !toggle.checked;
                showToast(data.error || 'Failed to update DDoS mode.', 'danger');
            }
        }).catch(() => { toggle.disabled = false; toggle.checked = !toggle.checked; });
});

document.getElementById('devmode-toggle')?.addEventListener('change', function () {
    const toggle = this;
    toggle.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_dev_mode');
    fd.append('domain', DOMAIN);
    fd.append('enabled', toggle.checked ? '1' : '0');
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            toggle.disabled = false;
            if (data.success) {
                showToast(toggle.checked ? 'Development mode enabled — cache bypassed for 3 hours.' : 'Development mode disabled.', toggle.checked ? 'info' : 'success');
            } else {
                toggle.checked = !toggle.checked;
                showToast(data.error || 'Failed to update development mode.', 'danger');
            }
        }).catch(() => { toggle.disabled = false; toggle.checked = !toggle.checked; });
});

function loadDns() {
    document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3 small">Loading...</td></tr>';
    apiFetch('/api/account?action=dns&domain=' + encodeURIComponent(DOMAIN))
        .then(data => {
            const tbody = document.getElementById('dns-tbody');
            if (!data.success || !data.records || !data.records.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3 small">' + (data.error || 'No DNS records found.') + '</td></tr>';
                return;
            }
            tbody.innerHTML = data.records.map(r => {
                const proxied = r.proxied ? '<span class="badge bg-warning text-dark"><i class="fas fa-cloud"></i></span>' : '<span class="text-muted">--</span>';
                return `<tr>
                    <td class="ps-4"><span class="badge bg-primary-subtle text-primary">${r.type}</span></td>
                    <td class="small fw-medium">${r.name}</td>
                    <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.content}</td>
                    <td class="small">${r.ttl === 1 ? 'Auto' : r.ttl}</td>
                    <td>${proxied}</td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-primary me-1 py-0 px-1" onclick='editDns(${JSON.stringify(r).replace(/'/g,"&#39;")})' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="deleteDns('${r.id}')" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('dns-tbody').innerHTML = '<tr><td colspan="6" class="text-muted text-center py-3 small">Failed to load DNS records.</td></tr>';
        });
}

document.getElementById('add-dns-btn')?.addEventListener('click', function () {
    document.getElementById('dns-modal-title').textContent = 'Add DNS Record';
    document.getElementById('dns-record-id').value = '';
    document.getElementById('dns-name').value    = '';
    document.getElementById('dns-content').value = '';
    document.getElementById('dns-ttl').value     = '1';
    document.getElementById('dns-priority').value = '10';
    document.getElementById('dns-proxied').checked = true;
    document.getElementById('dns-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('dnsModal')).show();
});

function editDns(r) {
    document.getElementById('dns-modal-title').textContent = 'Edit DNS Record';
    document.getElementById('dns-record-id').value = r.id;
    document.getElementById('dns-type').value      = r.type;
    document.getElementById('dns-name').value      = r.name;
    document.getElementById('dns-content').value   = r.content;
    document.getElementById('dns-ttl').value       = r.ttl;
    document.getElementById('dns-priority').value  = r.priority ?? 10;
    document.getElementById('dns-proxied').checked = !!r.proxied;
    document.getElementById('dns-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('dnsModal')).show();
}

document.getElementById('dns-save-btn')?.addEventListener('click', function () {
    const spinner  = document.getElementById('dns-save-spinner');
    const errEl    = document.getElementById('dns-modal-error');
    const recordId = document.getElementById('dns-record-id').value;
    spinner.classList.remove('d-none');
    this.disabled = true;
    errEl.className = 'd-none';
    const fd = new FormData();
    fd.append('action', recordId ? 'dns_update' : 'dns_create');
    fd.append('domain', DOMAIN);
    if (recordId) fd.append('record_id', recordId);
    fd.append('type',    document.getElementById('dns-type').value);
    fd.append('name',    document.getElementById('dns-name').value);
    fd.append('content', document.getElementById('dns-content').value);
    fd.append('ttl',     document.getElementById('dns-ttl').value);
    fd.append('proxied', document.getElementById('dns-proxied').checked ? '1' : '0');
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('dnsModal')).hide();
                showToast('DNS record saved.');
                loadDns();
            } else {
                errEl.className = 'alert alert-danger mt-3 small py-2';
                errEl.textContent = data.error || 'Save failed.';
            }
        });
});

function deleteDns(recordId) {
    if (!confirm('Delete this DNS record?')) return;
    const fd = new FormData();
    fd.append('action', 'dns_delete');
    fd.append('domain', DOMAIN);
    fd.append('record_id', recordId);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) { showToast('DNS record deleted.'); loadDns(); }
            else showToast(data.error || 'Delete failed.', 'danger');
        });
}

// ── Email Routing ───────────────────────────────────────────────────────────

function loadEmail() {
    document.getElementById('email-tbody').innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">Loading...</td></tr>';
    apiFetch('/api/account?action=email&domain=' + encodeURIComponent(DOMAIN))
        .then(data => {
            const tbody = document.getElementById('email-tbody');
            if (!data.success || !data.rules || !data.rules.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">' + (data.error || 'No email routing rules.') + '</td></tr>';
                return;
            }
            tbody.innerHTML = data.rules.map(rule => {
                const isCatchAll = rule.matchers?.[0]?.type === 'all';
                const actionType = rule.actions?.[0]?.type ?? '';
                let from, to;
                if (isCatchAll) {
                    from = '<span class="text-muted fst-italic">Catch-all (*@domain)</span>';
                    if (actionType === 'drop') to = '<span class="text-muted">Drop (discard)</span>';
                    else if (actionType === 'forward') to = (rule.actions[0].value ?? []).join(', ') || '--';
                    else to = actionType || '--';
                } else {
                    from = rule.matchers?.[0]?.value ?? '--';
                    const rawTo = rule.actions?.[0]?.value ?? '--';
                    to = Array.isArray(rawTo) ? rawTo.join(', ') : rawTo;
                }
                const enabled = rule.enabled !== false;
                const badge = enabled ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>';
                const deleteBtn = isCatchAll ? '' : `<button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="deleteEmailRule('${rule.id}')" title="Delete"><i class="fas fa-trash"></i></button>`;
                return `<tr>
                    <td class="ps-4 small fw-medium">${from}</td>
                    <td class="small">${to}</td>
                    <td>${badge}</td>
                    <td class="text-end pe-4">${deleteBtn}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('email-tbody').innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3 small">Failed to load email rules.</td></tr>';
        });
}

const emailFromEl = document.getElementById('email-from');
const emailToEl   = document.getElementById('email-to');
const emailSaveEl = document.getElementById('email-save-btn');
const emailFromRe = /^(\*|[a-zA-Z0-9._%+-]+)@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
const emailToRe   = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

function validateEmailForm() {
    const fromVal = emailFromEl.value.trim();
    const toVal   = emailToEl.value.trim();
    const fromOk  = fromVal === '' || emailFromRe.test(fromVal);
    const toOk    = toVal === '' || emailToRe.test(toVal);
    emailFromEl.classList.toggle('is-invalid', fromVal !== '' && !fromOk);
    emailFromEl.classList.toggle('is-valid', fromVal !== '' && fromOk);
    emailToEl.classList.toggle('is-invalid', toVal !== '' && !toOk);
    emailToEl.classList.toggle('is-valid', toVal !== '' && toOk);
    emailSaveEl.disabled = !(fromVal && toVal && fromOk && toOk);
}
emailFromEl?.addEventListener('input', validateEmailForm);
emailToEl?.addEventListener('input', validateEmailForm);

document.getElementById('add-email-btn')?.addEventListener('click', function () {
    emailFromEl.value = '';
    emailToEl.value   = '';
    emailFromEl.classList.remove('is-valid', 'is-invalid');
    emailToEl.classList.remove('is-valid', 'is-invalid');
    emailSaveEl.disabled = true;
    document.getElementById('email-modal-error').className = 'd-none';
    new bootstrap.Modal(document.getElementById('emailModal')).show();
});

document.getElementById('email-save-btn')?.addEventListener('click', function () {
    const from = document.getElementById('email-from').value.trim();
    const to   = document.getElementById('email-to').value.trim();
    const errEl = document.getElementById('email-modal-error');
    if (!from || !to) { errEl.className = 'alert alert-danger small py-2'; errEl.textContent = 'Both fields required.'; return; }
    const spinner = document.getElementById('email-save-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'email_create_rule');
    fd.append('domain', DOMAIN);
    fd.append('from', from);
    fd.append('to', to);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
                showToast('Email rule added.');
                loadEmail();
            } else {
                errEl.className = 'alert alert-danger small py-2 mt-2';
                errEl.textContent = data.error || 'Failed to add rule.';
            }
        });
});

function deleteEmailRule(ruleId) {
    if (!confirm('Delete this email forwarding rule?')) return;
    const fd = new FormData();
    fd.append('action', 'email_delete_rule');
    fd.append('domain', DOMAIN);
    fd.append('rule_id', ruleId);
    apiFetch('/api/account', { method: 'POST', body: fd })
        .then(data => {
            if (data.success) { showToast('Rule deleted.'); loadEmail(); }
            else showToast(data.error || 'Delete failed.', 'danger');
        });
}
<?php endif; ?>

// ── Init (after Bootstrap loads) ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Hash-based section deep linking
    let hash = window.location.hash;
    if (hash && !hash.startsWith('#p-')) hash = '#p-' + hash.substring(1);
    if (hash) {
        const btn = document.querySelector(`#portal-nav [data-bs-target="${hash}"]`);
        if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
    document.querySelectorAll('#portal-nav button[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', () => {
            history.replaceState(null, '', btn.dataset.bsTarget.replace('#p-', '#'));
        });
    });

    loadKeys();
    loadDirs();
    loadBackups();
    <?php if ($cfEnabled && !$isSubdomain): ?>
    loadZoneSettings();
    loadDns();
    loadEmail();
    <?php endif; ?>
});
</script>
