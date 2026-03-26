<?php
// FILE: src/settings.php
// iNetPanel — Panel Settings (real data from SQLite)

Auth::requireAdmin();

// Load all settings
function s(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $rows = DB::fetchAll('SELECT key, value FROM settings');
        $cache = array_column($rows, 'value', 'key');
    }
    return $cache[$key] ?? $default;
}
?>

<h4 class="mb-4"><i class="fas fa-sliders-h me-2"></i>Settings</h4>

<div id="settings-alert" class="d-none mb-3"></div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom-0 pt-3">
        <ul class="nav nav-tabs card-header-tabs" id="settings-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-updates" id="tab-updates-btn" type="button"><i class="fas fa-sync-alt me-1"></i>Updates</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-general" type="button">General</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cloudflare" type="button"><i class="fab fa-cloudflare me-1"></i>Cloudflare</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ddns" type="button">DDNS</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-wireguard" type="button"><i class="fas fa-shield-alt me-1"></i>WireGuard</button></li>
        </ul>
    </div>

    <div class="card-body p-4">
        <div class="tab-content">

            <!-- ═══ UPDATES ══════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="tab-updates">
                <?php
                $currentVer = class_exists('Version') ? Version::get() : '0.000';
                $latestVer  = s('panel_latest_ver', '');
                $checkTs    = (int)s('panel_check_ts', '0');
                $updateAvail = $latestVer && version_compare($latestVer, $currentVer, '>');
                $checkedAgo  = $checkTs ? human_time_diff($checkTs) : 'never';
                function human_time_diff(int $ts): string {
                    $diff = time() - $ts;
                    if ($diff < 60)   return 'just now';
                    if ($diff < 3600) return round($diff/60) . ' min ago';
                    if ($diff < 86400) return round($diff/3600) . ' hr ago';
                    return round($diff/86400) . ' days ago';
                }
                ?>

                <!-- Version status card -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="p-3 border rounded bg-light">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Current Version</div>
                            <div class="h5 mb-0 font-monospace"><?= htmlspecialchars('v' . $currentVer) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded <?= $updateAvail ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light' ?>">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Latest Version</div>
                            <div class="h5 mb-0 font-monospace" id="latest-ver-display">
                                <?= $latestVer ? htmlspecialchars('v' . $latestVer) : '<span class="text-muted small">Unknown</span>' ?>
                                <?php if ($updateAvail): ?>
                                    <span class="badge bg-warning text-dark ms-2 fs-6">Update Available</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-1">Checked: <span id="update-checked-ago"><?= htmlspecialchars($checkedAgo) ?></span>
                                <button class="btn btn-link btn-sm p-0 ms-1" id="check-now-btn">Check now</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-center">
                        <button class="btn btn-warning w-100 <?= $updateAvail ? '' : 'd-none' ?>" id="update-now-btn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="update-spinner"></span>
                            <i class="fas fa-download me-1"></i>Update Now
                        </button>
                        <div class="text-success w-100 text-center <?= $updateAvail ? 'd-none' : '' ?>" id="no-update-msg">
                            <i class="fas fa-check-circle fa-2x mb-1 d-block"></i>
                            <small>Up to date</small>
                        </div>
                    </div>
                </div>

                <!-- Update output modal -->
                <div class="modal fade" id="updateOutputModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Update Complete</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Changelog section -->
                                <div id="changelog-section" class="d-none">
                                    <h6 class="fw-bold mb-2"><i class="fas fa-list-ul me-1"></i>What's New</h6>
                                    <div id="update-changelog" class="border rounded p-3 mb-3" style="max-height:350px;overflow-y:auto;font-size:.9rem;line-height:1.6;"></div>
                                </div>
                                <!-- Collapsible raw output -->
                                <div>
                                    <a class="small text-muted" data-bs-toggle="collapse" href="#update-output-wrap" role="button">
                                        <i class="fas fa-terminal me-1"></i>Show update log
                                    </a>
                                    <div class="collapse mt-2" id="update-output-wrap">
                                        <pre id="update-output" class="bg-dark text-light p-3 rounded small" style="max-height:250px;overflow-y:auto"></pre>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <a id="changelog-gh-link" href="#" target="_blank" class="btn btn-outline-secondary btn-sm d-none">
                                    <i class="fab fa-github me-1"></i>View on GitHub
                                </a>
                                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Release Channel -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="fw-semibold mb-2"><i class="fas fa-code-branch me-1"></i>Release Channel</div>
                            <div class="text-muted small mb-2">
                                <strong>Stable</strong> pulls from tagged GitHub releases.
                                <strong>Beta</strong> pulls the latest code from the main branch.
                            </div>
                            <select class="form-select form-select-sm w-auto" id="update-channel-select">
                                <option value="stable" <?= s('update_channel', 'stable') === 'stable' ? 'selected' : '' ?>>Stable (Recommended)</option>
                                <option value="beta" <?= s('update_channel', 'stable') === 'beta' ? 'selected' : '' ?>>Beta</option>
                            </select>
                        </div>
                    </div>
                </div>

                <hr class="my-4">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">Scheduled Jobs</h6>

                <form class="settings-form" data-section="cron">
                    <div class="row g-3">

                        <!-- System Package Updates -->
                        <div class="col-12">
                            <div class="p-3 border rounded">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <div class="fw-semibold">System Package Updates</div>
                                        <div class="text-muted small">Runs <code>/root/scripts/inetp-update.sh</code> (apt-get upgrade) daily.</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="update_cron_enabled" id="update-cron-enabled"
                                                   <?= s('update_cron_enabled', '1') === '1' ? 'checked' : '' ?> style="width:2.5em;height:1.3em">
                                            <label class="form-check-label small" for="update-cron-enabled">Enabled</label>
                                        </div>
                                        <div>
                                            <label class="form-label small fw-semibold mb-1">Time</label>
                                            <input type="time" class="form-control form-control-sm" name="update_cron_time"
                                                   value="<?= htmlspecialchars(s('update_cron_time', '00:00')) ?>" style="width:120px">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Account Backups -->
                        <div class="col-12">
                            <div class="p-3 border rounded">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <div class="fw-semibold">Account Backups</div>
                                        <div class="text-muted small">Runs <code>backup_accounts.sh</code> to archive all hosting accounts.</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div>
                                            <label class="form-label small fw-semibold mb-1">Time</label>
                                            <input type="time" class="form-control form-control-sm" name="backup_cron_time"
                                                   value="<?= htmlspecialchars(s('backup_cron_time', '03:00')) ?>" style="width:120px">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- DDNS Update -->
                        <div class="col-12">
                            <div class="p-3 border rounded bg-light">
                                <div class="fw-semibold">DDNS Update</div>
                                <div class="text-muted small">Configured on the <a href="#tab-ddns" onclick="bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target=\'#tab-ddns\']')).show()">DDNS tab</a> — interval in minutes.</div>
                            </div>
                        </div>

                        <!-- Panel Auto-Update -->
                        <div class="col-12">
                            <div class="p-3 border rounded">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <div class="fw-semibold">Panel Auto-Update</div>
                                        <div class="text-muted small">Automatically downloads and applies iNetPanel updates. <span class="badge bg-secondary">Off by default</span></div>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="auto_update_enabled" id="auto-update-enabled"
                                                   <?= s('auto_update_enabled', '0') === '1' ? 'checked' : '' ?> style="width:2.5em;height:1.3em">
                                            <label class="form-check-label small" for="auto-update-enabled">Enabled</label>
                                        </div>
                                        <div>
                                            <label class="form-label small fw-semibold mb-1">Time</label>
                                            <input type="time" class="form-control form-control-sm" name="auto_update_time"
                                                   value="<?= htmlspecialchars(s('auto_update_time', '02:00')) ?>" style="width:120px">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save Schedule</button>
                    </div>
                </form>
            </div>

            <!-- ═══ GENERAL ═══════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-general">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">Panel Configuration</h6>
                <form class="settings-form" data-section="general">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Panel Name</label>
                            <input type="text" class="form-control" name="panel_name" value="<?= htmlspecialchars(s('panel_name', 'iNetPanel')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Admin Email</label>
                            <input type="email" class="form-control" name="admin_email" value="<?= htmlspecialchars(s('admin_email')) ?>">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Server Hostname</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="server-hostname" value="<?= htmlspecialchars(DB::setting('server_hostname', gethostname())) ?>" placeholder="e.g. panel.example.com">
                                <?php if (DB::setting('cf_enabled', '0') === '1'): ?>
                                <button class="btn btn-outline-secondary" type="button" id="verify-hostname-btn" onclick="verifyHostname()">
                                    <i class="fas fa-check-circle me-1"></i> Verify
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-outline-primary" type="button" id="save-hostname-btn">
                                    <span class="spinner-border spinner-border-sm d-none me-1" id="hostname-spinner"></span>
                                    Apply
                                </button>
                            </div>
                            <div id="hostname-status"></div>
                            <div id="server-ip-info" class="mt-2"></div>
                            <div class="form-text">The DNS hostname used to access your panel. SSL will be issued automatically.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Timezone</label>
                            <select class="form-select" name="timezone">
                                <?php
                                $tz  = s('timezone', 'UTC');
                                $tzs = ['UTC','America/New_York','America/Chicago','America/Los_Angeles','Europe/London','Europe/Paris','Europe/Berlin','Asia/Tokyo','Asia/Singapore','Australia/Sydney'];
                                foreach ($tzs as $t) {
                                    $sel = $t === $tz ? 'selected' : '';
                                    echo "<option value=\"{$t}\" {$sel}>{$t}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SSH Port</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="ssh-port" value="<?= htmlspecialchars(s('ssh_port', '1022')) ?>" min="1" max="65535" placeholder="1022">
                                <button class="btn btn-outline-primary" type="button" id="save-ssh-port-btn">
                                    <span class="spinner-border spinner-border-sm d-none me-1" id="ssh-port-spinner"></span>
                                    Apply
                                </button>
                            </div>
                            <div class="form-text">Changes SSH port in sshd, firewalld, and fail2ban. Current connections may drop.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save General</button>
                </form>
            </div>

            <!-- ═══ CLOUDFLARE ════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-cloudflare">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">Cloudflare API Credentials</h6>
                <form class="settings-form" data-section="cloudflare">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-3 border">
                        <div>
                            <div class="fw-semibold">Enable Cloudflare Integration</div>
                            <div class="text-muted small">Enables DNS management and email routing pages in the sidebar.</div>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="cf_enabled" id="cf-enabled"
                                   <?= s('cf_enabled') === '1' ? 'checked' : '' ?> style="width:2.5em;height:1.3em">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cloudflare Email</label>
                            <input type="email" class="form-control" name="cf_email" value="<?= htmlspecialchars(s('cf_email')) ?>" placeholder="you@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Global API Key</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="cf_api_key" id="cf-api-key"
                                       value="<?= s('cf_api_key') ? str_repeat('*', 20) : '' ?>" placeholder="Your CF Global API Key">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('cf-api-key',this)"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Account ID</label>
                            <input type="text" class="form-control" name="cf_account_id" value="<?= htmlspecialchars(s('cf_account_id')) ?>" placeholder="Your CF Account ID">
                            <div class="form-text">Required for Zero Trust Tunnel management.</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">Save & Validate</button>
                        <button type="button" class="btn btn-outline-secondary" id="setup-tunnel-btn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="tunnel-spinner"></span>
                            <i class="fas fa-network-wired me-1"></i>Setup Zero Trust Tunnel
                        </button>
                        <span id="cf-validate-result" class="align-self-center small"></span>
                        <span id="tunnel-result" class="align-self-center small"></span>
                    </div>
                    <?php if (s('cf_tunnel_id')): ?>
                    <div class="alert alert-success py-2 px-3 mt-3 small">
                        <i class="fas fa-check-circle me-1"></i>Tunnel active: <code><?= htmlspecialchars(s('cf_tunnel_id')) ?></code>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning py-2 px-3 mt-3 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>No Zero Trust Tunnel configured. Click <strong>Setup Zero Trust Tunnel</strong> to create one.
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- ═══ DDNS ═══════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-ddns">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">Cloudflare Dynamic DNS</h6>
                <form class="settings-form" data-section="ddns">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-3 border">
                        <div>
                            <div class="fw-semibold">Enable CF DDNS</div>
                            <div class="text-muted small">Auto-updates a DNS A record with this server's public IP on a schedule.</div>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="cf_ddns_enabled"
                                   <?= s('cf_ddns_enabled') === '1' ? 'checked' : '' ?> style="width:2.5em;height:1.3em">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Hostname</label>
                            <input type="text" class="form-control" name="cf_ddns_hostname"
                                   value="<?= htmlspecialchars(s('cf_ddns_hostname')) ?>" placeholder="home.example.com">
                            <div class="form-text">The A record to keep updated (must be in a CF zone).</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Zone ID (optional)</label>
                            <input type="text" class="form-control" name="cf_ddns_zone_id"
                                   value="<?= htmlspecialchars(s('cf_ddns_zone_id')) ?>" placeholder="Auto-detected from hostname">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Update Interval (minutes)</label>
                            <input type="number" class="form-control" name="cf_ddns_interval" min="1" max="60"
                                   value="<?= (int)(s('cf_ddns_interval', '5')) ?>">
                        </div>
                        <div class="col-md-8 d-flex align-items-end gap-3 pb-1">
                            <span class="text-muted small">
                                <?php $lastIp = s('cf_ddns_last_ip'); $lastUp = s('cf_ddns_last_updated'); ?>
                                <?= $lastIp ? "Last IP: <code>{$lastIp}</code> &nbsp; Updated: " . htmlspecialchars($lastUp) : 'No update recorded yet.' ?>
                            </span>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Save DDNS</button>
                        <button type="button" class="btn btn-outline-secondary" id="ddns-test-btn">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="ddns-test-spinner"></span>
                            Test & Update Now
                        </button>
                        <span id="ddns-test-result" class="align-self-center small"></span>
                    </div>
                </form>
            </div>

            <!-- ═══ WIREGUARD ═════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-wireguard">
                <h6 class="text-muted text-uppercase small fw-bold mb-3">WireGuard VPN</h6>

                <?php $wgInstalled = file_exists('/usr/bin/wg') || file_exists('/usr/sbin/wg'); ?>
                <?php if (!$wgInstalled): ?>

                <div class="alert alert-warning d-flex align-items-start gap-3">
                    <i class="fas fa-exclamation-triangle mt-1"></i>
                    <div>
                        <div class="fw-semibold">WireGuard is not installed</div>
                        <div class="small mt-1">WireGuard was not selected during installation. To install it, run the setup script on your server:</div>
                        <code class="d-block mt-2 p-2 bg-light rounded border small">sudo /root/scripts/wireguard_setup.sh --port 1443 --subnet 10.10.0.0/24</code>
                        <div class="small text-muted mt-2">After installation, reload this page to manage WireGuard from the panel.</div>
                    </div>
                </div>

                <?php else: ?>

                <!-- Lockdown notice -->
                <div class="alert alert-info border-0 mb-4">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-lock mt-1 text-primary"></i>
                        <div>
                            <div class="fw-semibold">Full Server Lockdown</div>
                            <div class="small mt-1">When WireGuard is active, only port <?= htmlspecialchars(s('wg_port', '1443')) ?>/UDP is publicly open. All other services (SSH, FTP, panel, phpMyAdmin, hosted sites) are accessible only via VPN or Cloudflare Tunnel.</div>
                            <div class="small text-muted mt-2">
                                <i class="fas fa-shield-alt me-1"></i> Port 80 (Panel) &mdash; VPN + Cloudflare only &nbsp;
                                <i class="fas fa-database me-1 ms-2"></i> Port 8888 (phpMyAdmin) &mdash; VPN only &nbsp;
                                <i class="fas fa-terminal me-1 ms-2"></i> SSH/FTP &mdash; VPN only
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status row -->
                <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-light rounded border" id="wg-status-row">
                    <div class="flex-grow-1">
                        <div class="fw-semibold">WireGuard Status</div>
                        <div class="text-muted small" id="wg-status-text">Loading…</div>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" id="wg-toggle-btn" disabled>
                        <span class="spinner-border spinner-border-sm d-none me-1" id="wg-toggle-spinner"></span>
                        Toggle
                    </button>
                </div>

                <!-- Server info -->
                <div class="row mb-4" id="wg-server-info" style="display:none!important">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Public Key</label>
                        <input type="text" class="form-control form-control-sm bg-light" id="wg-pubkey" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Endpoint</label>
                        <input type="text" class="form-control form-control-sm bg-light" id="wg-endpoint" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Port</label>
                        <input type="text" class="form-control form-control-sm bg-light" id="wg-port" readonly>
                    </div>
                </div>

                <!-- Auto-peer toggle -->
                <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-4 border">
                    <div>
                        <div class="fw-semibold">Auto-configure all users</div>
                        <div class="text-muted small">Automatically generate a WireGuard peer for every hosting account (existing and new).</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="wg-auto-peer"
                                   style="width:2.5em;height:1.3em">
                        </div>
                        <button class="btn btn-sm btn-outline-primary d-none" id="wg-auto-configure-btn">Configure All Now</button>
                    </div>
                </div>

                <!-- Peer list -->
                <h6 class="fw-semibold mb-2">Peers <button class="btn btn-sm btn-primary ms-2" id="add-peer-btn"><i class="fas fa-plus me-1"></i>Add Peer</button></h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>IP</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="wg-peers-tbody">
                            <tr><td colspan="4" class="text-muted small py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>

                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Hostname Reboot Modal -->
<div class="modal fade" id="rebootModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sync-alt me-2 text-warning"></i>Hostname Changed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>The hostname has been updated to <strong id="reboot-new-hostname"></strong>.</p>
                <div class="alert alert-warning py-2 small mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>A reboot is recommended for the change to fully take effect across all services.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Reboot Later</button>
                <button type="button" class="btn btn-warning" id="reboot-now-btn">
                    <i class="fas fa-power-off me-1"></i>Reboot Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Peer Modal -->
<div class="modal fade" id="addPeerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add WireGuard Peer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Peer Name</label>
                <input type="text" class="form-control" id="new-peer-name" placeholder="e.g. mydevice or example.com">
                <div id="add-peer-alert" class="d-none mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="add-peer-confirm-btn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="add-peer-spinner"></span>
                    Add
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
<script>
function showAlert(msg, type = 'success') {
    const el = document.getElementById('settings-alert');
    el.className = `alert alert-${type}`;
    el.innerHTML = msg;
    setTimeout(() => el.className = 'd-none', 6000);
}

function togglePass(id, btn) {
    const inp = document.getElementById(id);
    if (inp.type === 'password') { inp.type = 'text'; btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
    else { inp.type = 'password'; btn.innerHTML = '<i class="fas fa-eye"></i>'; }
}

// ── Generic settings form handler ────────────────────────────────────────────
document.querySelectorAll('.settings-form').forEach(form => {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action', 'save');
        // Convert unchecked checkboxes to '0'
        this.querySelectorAll('input[type=checkbox]').forEach(cb => {
            if (!cb.checked) fd.set(cb.name, '0');
            else fd.set(cb.name, '1');
        });
        fetch('/api/settings', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('Settings saved successfully.');
                    if (this.dataset.section === 'cloudflare') {
                        document.getElementById('cf-validate-result').innerHTML =
                            data.cf_valid === false
                                ? '<span class="text-danger"><i class="fas fa-times me-1"></i>Invalid credentials</span>'
                                : (data.cf_valid ? '<span class="text-success"><i class="fas fa-check me-1"></i>Credentials valid</span>' : '');
                    }
                } else {
                    showAlert(data.error || 'Save failed.', 'danger');
                }
            })
            .catch(() => showAlert('Request failed. Check your connection.', 'danger'));
    });
});

// ── Setup Zero Trust Tunnel button ───────────────────────────────────────────
document.getElementById('setup-tunnel-btn').addEventListener('click', function () {
    const spinner = document.getElementById('tunnel-spinner');
    const result  = document.getElementById('tunnel-result');
    spinner.classList.remove('d-none');
    this.disabled = true;
    result.textContent = '';
    const fd = new FormData(); fd.append('action', 'setup_tunnel');
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                result.innerHTML = '<span class="text-success"><i class="fas fa-check me-1"></i>Tunnel created. Reloading…</span>';
                setTimeout(() => location.reload(), 1500);
            } else {
                result.innerHTML = '<span class="text-danger"><i class="fas fa-times me-1"></i>' + (data.error || 'Failed') + '</span>';
            }
        })
        .catch(() => { spinner.classList.add('d-none'); this.disabled = false; result.innerHTML = '<span class="text-danger">Request failed.</span>'; });
});

// ── DDNS test button ─────────────────────────────────────────────────────────
document.getElementById('ddns-test-btn').addEventListener('click', function () {
    const spinner = document.getElementById('ddns-test-spinner');
    const result  = document.getElementById('ddns-test-result');
    spinner.classList.remove('d-none');
    this.disabled = true;
    result.textContent = '';
    const fd = new FormData(); fd.append('action', 'ddns_test');
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            result.innerHTML = data.success
                ? `<span class="text-success"><i class="fas fa-check me-1"></i>${data.output || 'Updated'}</span>`
                : `<span class="text-danger"><i class="fas fa-times me-1"></i>${data.error || 'Failed'}</span>`;
        })
        .catch(() => { spinner.classList.add('d-none'); this.disabled = false; result.innerHTML = '<span class="text-danger">Request failed.</span>'; });
});

// ── WireGuard tab ─────────────────────────────────────────────────────────────
function loadWgStatus() {
    fetch('/api/wireguard?action=status')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const btn = document.getElementById('wg-toggle-btn');
            const txt = document.getElementById('wg-status-text');
            btn.disabled = false;
            txt.innerHTML = data.active
                ? '<span class="text-success fw-semibold"><i class="fas fa-circle me-1" style="font-size:.6rem"></i>Running</span>'
                + ` &mdash; ${data.peer_count} peer(s)`
                : '<span class="text-secondary">Stopped</span>';
            btn.textContent = data.active ? 'Stop' : 'Start';
            btn.className = 'btn btn-sm ' + (data.active ? 'btn-outline-warning' : 'btn-outline-success');

            const info = document.getElementById('wg-server-info');
            if (data.active && data.public_key) {
                document.getElementById('wg-pubkey').value   = data.public_key;
                document.getElementById('wg-endpoint').value = data.endpoint || '—';
                document.getElementById('wg-port').value     = data.port || '—';
                info.style.removeProperty('display');
            } else {
                info.style.setProperty('display', 'none', 'important');
            }

            const autoCb = document.getElementById('wg-auto-peer');
            autoCb.checked = data.auto_peer;
        });
}

function loadWgPeers() {
    fetch('/api/wireguard?action=list_peers')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('wg-peers-tbody');
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-muted small py-2">No peers configured.</td></tr>'; return;
            }
            tbody.innerHTML = data.data.map(p =>
                `<tr>
                    <td class="fw-medium small">${p.hosting_user}</td>
                    <td class="small text-muted">${p.peer_ip}</td>
                    <td class="small text-muted">${p.created_at ? p.created_at.substring(0,10) : '—'}</td>
                    <td class="text-end">
                        <button class="btn btn-xs btn-outline-danger btn-sm py-0 px-1" onclick="removePeer('${p.hosting_user}')" title="Remove"><i class="fas fa-times"></i></button>
                    </td>
                </tr>`
            ).join('');
        });
}

if (document.getElementById('wg-toggle-btn')) {
document.getElementById('wg-toggle-btn').addEventListener('click', function () {
    const spinner = document.getElementById('wg-toggle-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData(); fd.append('action', 'toggle');
    fetch('/api/wireguard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            if (data.success) loadWgStatus();
            else showAlert(data.error || 'Toggle failed.', 'danger');
        })
        .catch(() => { spinner.classList.add('d-none'); this.disabled = false; showAlert('Request failed.', 'danger'); });
});

document.getElementById('wg-auto-peer').addEventListener('change', function () {
    const btn = document.getElementById('wg-auto-configure-btn');
    btn.classList.toggle('d-none', !this.checked);
    const fd = new FormData();
    fd.append('action', 'set_auto_peer');
    fd.append('enabled', this.checked ? '1' : '0');
    fetch('/api/wireguard', { method: 'POST', body: fd });
});

document.getElementById('wg-auto-configure-btn').addEventListener('click', function () {
    this.disabled = true;
    const fd = new FormData(); fd.append('action', 'auto_configure_all');
    fetch('/api/wireguard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            this.disabled = false;
            if (data.success) {
                showAlert(`Configured ${data.count} peer(s).` + (Object.keys(data.errors).length ? ' Some errors occurred.' : ''));
                loadWgPeers();
            } else {
                showAlert(data.error || 'Failed.', 'danger');
            }
        })
        .catch(() => { this.disabled = false; showAlert('Request failed.', 'danger'); });
});

document.getElementById('add-peer-btn').addEventListener('click', function () {
    document.getElementById('new-peer-name').value = '';
    document.getElementById('add-peer-alert').className = 'd-none';
    new bootstrap.Modal(document.getElementById('addPeerModal')).show();
});

document.getElementById('add-peer-confirm-btn').addEventListener('click', function () {
    const name = document.getElementById('new-peer-name').value.trim();
    if (!name || !/^[a-zA-Z0-9._-]+$/.test(name)) {
        showAlert('Invalid peer name. Use letters, numbers, dots, underscores, and hyphens only.', 'warning');
        return;
    }
    const spinner = document.getElementById('add-peer-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'add_peer');
    fd.append('name', name);
    fetch('/api/wireguard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            const al = document.getElementById('add-peer-alert');
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('addPeerModal')).hide();
                showAlert(`Peer '${name}' added.`);
                loadWgPeers();
            } else {
                al.className = 'alert alert-danger mt-2 small py-2';
                al.textContent = data.error || 'Failed.';
            }
        })
        .catch(() => { spinner.classList.add('d-none'); this.disabled = false; showAlert('Request failed.', 'danger'); });
});

function removePeer(name) {
    if (!confirm(`Remove peer '${name}'?`)) return;
    const fd = new FormData();
    fd.append('action', 'remove_peer');
    fd.append('name', name);
    fetch('/api/wireguard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showAlert(`Peer '${name}' removed.`); loadWgPeers(); }
            else showAlert(data.error || 'Remove failed.', 'danger');
        })
        .catch(() => showAlert('Request failed.', 'danger'));
}

// Load WG data when that tab is first shown
document.querySelector('[data-bs-target="#tab-wireguard"]').addEventListener('shown.bs.tab', function () {
    if (typeof loadWgStatus === 'function') { loadWgStatus(); loadWgPeers(); }
});
} // end if wg-toggle-btn exists

// ── Updates tab ───────────────────────────────────────────────────────────────
function refreshUpdateStatus() {
    fetch('/api/update_check?action=status')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.latest) return;
            const disp = document.getElementById('latest-ver-display');
            if (disp) disp.innerHTML = 'v' + data.latest + (data.update_available ? ' <span class="badge bg-warning text-dark ms-2">Update Available</span>' : '');
            const checkedEl = document.getElementById('update-checked-ago');
            if (checkedEl && data.checked_ago) checkedEl.textContent = data.checked_ago;
        })
        .catch(() => {});
}
// Auto-load on page ready
document.addEventListener('DOMContentLoaded', refreshUpdateStatus);

// Release channel selector
const channelSelect = document.getElementById('update-channel-select');
if (channelSelect) {
    channelSelect.addEventListener('change', function() {
        const fd = new FormData();
        fd.append('action', 'set_update_channel');
        fd.append('channel', this.value);
        fetch('/api/settings', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) showAlert(`Release channel set to ${data.channel}.`, 'success');
                else showAlert(data.error || 'Failed.', 'danger');
            })
            .catch(() => showAlert('Request failed.', 'danger'));
    });
}

const checkNowBtn  = document.getElementById('check-now-btn');
const updateNowBtn = document.getElementById('update-now-btn');

if (checkNowBtn) {
    checkNowBtn.addEventListener('click', function () {
        this.textContent = 'Checking…';
        this.disabled = true;
        fetch('/api/update_check?action=check')
            .then(r => r.json())
            .then(data => {
                this.disabled = false;
                this.textContent = 'Check now';
                if (data.success) {
                    const disp = document.getElementById('latest-ver-display');
                    if (disp) disp.innerHTML = 'v' + data.latest + (data.update_available ? ' <span class="badge bg-warning text-dark ms-2">Update Available</span>' : '');
                    const checkedEl = document.getElementById('update-checked-ago');
                    if (checkedEl) checkedEl.textContent = 'just now';
                    const updateBtn = document.getElementById('update-now-btn');
                    const noUpdateMsg = document.getElementById('no-update-msg');
                    if (data.update_available) {
                        updateBtn?.classList.remove('d-none');
                        noUpdateMsg?.classList.add('d-none');
                    } else {
                        updateBtn?.classList.add('d-none');
                        noUpdateMsg?.classList.remove('d-none');
                    }
                    showAlert(data.update_available ? `Update available: v${data.latest}` : 'Already up to date.', data.update_available ? 'warning' : 'success');
                } else {
                    showAlert(data.error || 'Check failed.', 'danger');
                }
            })
            .catch(() => { this.disabled = false; this.textContent = 'Check now'; showAlert('Request failed.', 'danger'); });
    });
}

// Lightweight Markdown → HTML for GitHub release notes
function mdToHtml(md) {
    return md
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/^### (.+)$/gm, '<h6 class="fw-bold mt-3 mb-1">$1</h6>')
        .replace(/^## (.+)$/gm, '<h5 class="fw-bold mt-3 mb-2">$1</h5>')
        .replace(/^# (.+)$/gm, '<h4 class="fw-bold mt-3 mb-2">$1</h4>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code class="bg-light px-1 rounded">$1</code>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>\n?)+/gs, '<ul class="mb-2">$&</ul>')
        .replace(/\n{2,}/g, '<br><br>')
        .replace(/\n/g, '<br>');
}

if (updateNowBtn) {
    updateNowBtn.addEventListener('click', function () {
        const spinner = document.getElementById('update-spinner');
        spinner.classList.remove('d-none');
        this.disabled = true;
        const fd = new FormData(); fd.append('action', 'update_now');
        fetch('/api/settings', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                spinner.classList.add('d-none');
                this.disabled = false;
                document.getElementById('update-output').textContent = data.output || '(no output)';
                // Render changelog if available
                const clSection = document.getElementById('changelog-section');
                const clDiv = document.getElementById('update-changelog');
                if (data.changelog && data.changelog.trim()) {
                    clDiv.innerHTML = mdToHtml(data.changelog);
                    clSection.classList.remove('d-none');
                } else {
                    clSection.classList.add('d-none');
                }
                new bootstrap.Modal(document.getElementById('updateOutputModal')).show();
            })
            .catch(() => { spinner.classList.add('d-none'); this.disabled = false; showAlert('Request failed.', 'danger'); });
    });
    document.getElementById('updateOutputModal')?.addEventListener('hidden.bs.modal', () => {
        location.reload();
    });
}


// ── Hostname change ──────────────────────────────────────────────────────────
document.getElementById('save-hostname-btn').addEventListener('click', function () {
    const input    = document.getElementById('server-hostname');
    const hostname = input.value.trim();
    if (!hostname) { showAlert('Hostname cannot be empty.', 'warning'); return; }
    if (!/^[a-zA-Z0-9][a-zA-Z0-9.\-]*$/.test(hostname)) {
        showAlert('Invalid hostname. Use letters, numbers, dots, and hyphens.', 'warning'); return;
    }
    const spinner = document.getElementById('hostname-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_hostname');
    fd.append('hostname', hostname);
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                showAlert('Hostname updated successfully.');
                document.getElementById('reboot-new-hostname').textContent = hostname;
                new bootstrap.Modal(document.getElementById('rebootModal')).show();
            } else {
                showAlert(data.error || 'Failed to update hostname.', 'danger');
            }
        })
        .catch(() => { spinner.classList.add('d-none'); this.disabled = false; showAlert('Request failed.', 'danger'); });
});

// ── Hostname verification (Cloudflare DNS) ──────────────────────────────────
function verifyHostname() {
    const hostname = document.getElementById('server-hostname').value.trim();
    if (!hostname) return;
    const btn = document.getElementById('verify-hostname-btn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    const fd = new FormData();
    fd.append('action', 'verify_hostname');
    fd.append('hostname', hostname);
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('hostname-status');
            if (data.exists) {
                el.innerHTML = '<div class="alert alert-success py-2 mt-2 mb-0 small"><i class="fas fa-check-circle me-1"></i> DNS record found — points to <strong>' + data.ip + '</strong></div>';
            } else if (data.zone_found) {
                el.innerHTML = '<div class="alert alert-warning py-2 mt-2 mb-0 small"><i class="fas fa-exclamation-triangle me-1"></i> Zone found but no A record for this hostname. <button class="btn btn-sm btn-outline-success ms-2" onclick="createHostnameDns()"><i class="fas fa-plus me-1"></i>Create A Record</button></div>';
            } else {
                el.innerHTML = '<div class="alert alert-danger py-2 mt-2 mb-0 small"><i class="fas fa-times-circle me-1"></i> Zone not found in your Cloudflare account.</div>';
            }
        })
        .catch(() => { document.getElementById('hostname-status').innerHTML = '<div class="alert alert-danger py-2 mt-2 mb-0 small">Verification failed.</div>'; })
        .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Verify'; });
}

function createHostnameDns() {
    const hostname = document.getElementById('server-hostname').value.trim();
    const fd = new FormData();
    fd.append('action', 'create_hostname_dns');
    fd.append('hostname', hostname);
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('hostname-status');
            if (data.success) {
                el.innerHTML = '<div class="alert alert-success py-2 mt-2 mb-0 small"><i class="fas fa-check-circle me-1"></i> A record created successfully!</div>';
            } else {
                el.innerHTML = '<div class="alert alert-danger py-2 mt-2 mb-0 small">' + (data.message || 'Failed to create DNS record.') + '</div>';
            }
        })
        .catch(() => { document.getElementById('hostname-status').innerHTML = '<div class="alert alert-danger py-2 mt-2 mb-0 small">Failed to create DNS record.</div>'; });
}

// ── Server IP detection ─────────────────────────────────────────────────────
(function detectServerIp() {
    const fd = new FormData();
    fd.append('action', 'detect_ip');
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('server-ip-info');
            if (!el) return;
            const ip = data.ip || 'Unknown';
            if (data.is_private) {
                el.innerHTML = '<div class="alert alert-warning border-0 py-2 mb-0 small"><i class="fas fa-exclamation-triangle me-1"></i> <strong>Server IP: ' + ip + '</strong> (Private/Local Address)<br>This hostname will only resolve within your local network. For remote access to the Admin Panel, Client Portal, and phpMyAdmin, enable the VPN under the WireGuard tab.</div>';
            } else {
                el.innerHTML = '<div class="alert alert-success border-0 py-2 mb-0 small"><i class="fas fa-check-circle me-1"></i> <strong>Server IP: ' + ip + '</strong> (Public Address)</div>';
            }
        }).catch(() => {});
})();

document.getElementById('reboot-now-btn').addEventListener('click', function () {
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rebooting…';
    const fd = new FormData();
    fd.append('action', 'reboot');
    fetch('/api/settings', { method: 'POST', body: fd })
        .then(() => {
            showAlert('Server is rebooting. This page will reload automatically…', 'warning');
            bootstrap.Modal.getInstance(document.getElementById('rebootModal'))?.hide();
            // Poll until the server comes back
            const poll = setInterval(() => {
                fetch('/api/settings?action=get', { signal: AbortSignal.timeout(3000) })
                    .then(r => { if (r.ok) { clearInterval(poll); location.reload(); } })
                    .catch(() => {});
            }, 5000);
        })
        .catch(() => { this.disabled = false; this.innerHTML = '<i class="fas fa-power-off me-1"></i>Reboot Now'; });
});

// ── SSH port change ──────────────────────────────────────────────────────────
document.getElementById('save-ssh-port-btn').addEventListener('click', function () {
    const input = document.getElementById('ssh-port');
    const port  = parseInt(input.value, 10);
    if (!port || port < 1 || port > 65535) { showAlert('Invalid port (1–65535).', 'warning'); return; }
    if (!confirm(`Change SSH port to ${port}? Active SSH connections may drop.`)) return;
    const spinner = document.getElementById('ssh-port-spinner');
    spinner.classList.remove('d-none');
    this.disabled = true;
    const fd = new FormData();
    fd.append('action', 'set_ssh_port');
    fd.append('port', port);
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            spinner.classList.add('d-none');
            this.disabled = false;
            if (data.success) {
                showAlert(`SSH port changed to ${port}. Firewalld and Fail2Ban updated.`);
            } else {
                showAlert(data.error || 'Failed to change SSH port.', 'danger');
            }
        })
        .catch(() => { spinner.classList.add('d-none'); this.disabled = false; showAlert('Request failed.', 'danger'); });
});

// Activate tab from URL hash (e.g. /admin/settings#general or #tab-general)
(function () {
    let hash = window.location.hash;
    if (hash && !hash.startsWith('#tab-')) hash = '#tab-' + hash.substring(1);
    if (hash) {
        const btn = document.querySelector(`[data-bs-target="${hash}"]`);
        if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
})();
// Update hash on tab change for browser back/forward
document.querySelectorAll('#settings-tabs button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', () => {
        history.replaceState(null, '', btn.dataset.bsTarget.replace('#tab-', '#'));
    });
});
</script>
