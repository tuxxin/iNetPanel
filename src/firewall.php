<?php
// FILE: src/firewall.php
// iNetPanel — Firewall Management (firewalld + fail2ban)
$isAdmin = Auth::hasFullAccess();

// Detect user's real IP (works behind Cloudflare tunnel)
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
if (str_contains($clientIp, ',')) $clientIp = trim(explode(',', $clientIp)[0]);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-shield-halved me-2"></i>Firewall</h4>
    <div class="d-flex gap-2">
        <?php if ($isAdmin): ?>
        <button class="btn btn-primary btn-sm" onclick="fwAutoConfigure()">
            <i class="fas fa-wand-magic-sparkles me-1"></i>Auto Configure
        </button>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm" onclick="loadFirewallStatus()">
            <i class="fas fa-sync-alt me-1"></i>Refresh
        </button>
    </div>
</div>

<div id="fw-alert" class="d-none mb-3"></div>

<!-- Status cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="fas fa-shield-halved fa-2x mb-2" id="fw-icon"></i>
                <h6 class="fw-bold">Firewalld</h6>
                <span id="fw-status-badge" class="badge bg-secondary">Loading...</span>
                <div class="small text-muted mt-1">Zone: <span id="fw-zone">—</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="fas fa-ban fa-2x mb-2 text-muted"></i>
                <h6 class="fw-bold">Fail2Ban</h6>
                <span id="f2b-status-badge" class="badge bg-secondary">Loading...</span>
                <div class="small text-muted mt-1">Banned: <span id="f2b-total-banned" class="fw-bold">0</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="fas fa-lock fa-2x mb-2 text-muted"></i>
                <h6 class="fw-bold">VPN Lockdown</h6>
                <span id="vpn-status-badge" class="badge bg-secondary">Loading...</span>
                <div class="small text-muted mt-1" id="vpn-detail">—</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-firewalld">Firewalld</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-fail2ban">Fail2Ban</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-whitelist">Whitelist</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-qsa">Exposure Scan</a></li>
</ul>

<div class="tab-content mt-3">

    <!-- ======================== FIREWALLD TAB ======================== -->
    <div class="tab-pane fade show active" id="tab-firewalld">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Open Ports (Default Zone)</h6>
                <div id="fw-ports-list" class="mb-3">
                    <span class="text-muted">Loading...</span>
                </div>

                <h6 class="fw-bold mb-3 mt-4">Active Zones</h6>
                <div id="fw-zones-list" class="mb-3">
                    <span class="text-muted">Loading...</span>
                </div>

                <?php if ($isAdmin): ?>
                <hr>
                <h6 class="fw-bold mb-3">Quick Actions</h6>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small">Port</label>
                        <input type="number" class="form-control form-control-sm" id="fw-port-input" placeholder="e.g. 443" min="1" max="65535" style="width:100px;">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small">Protocol</label>
                        <select class="form-select form-select-sm" id="fw-proto-input" style="width:80px;">
                            <option value="tcp">TCP</option>
                            <option value="udp">UDP</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-success" onclick="fwOpenPort()"><i class="fas fa-plus me-1"></i>Open</button>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-danger" onclick="fwClosePort()"><i class="fas fa-minus me-1"></i>Close</button>
                    </div>
                    <div class="col-auto ms-3">
                        <button class="btn btn-sm btn-outline-secondary" onclick="fwReload()"><i class="fas fa-sync-alt me-1"></i>Reload Firewalld</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ======================== FAIL2BAN TAB ======================== -->
    <div class="tab-pane fade" id="tab-fail2ban">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <!-- Settings -->
                <div class="row g-3 mb-4 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small fw-bold">Ban Duration (sec)</label>
                        <input type="number" class="form-control form-control-sm" id="f2b-bantime" style="width:110px;" min="60">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small fw-bold">Find Time (sec)</label>
                        <input type="number" class="form-control form-control-sm" id="f2b-findtime" style="width:110px;" min="60">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small fw-bold">Max Retries</label>
                        <input type="number" class="form-control form-control-sm" id="f2b-maxretry" style="width:80px;" min="1">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary" onclick="f2bSaveSettings()"><i class="fas fa-save me-1"></i>Save</button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Jails & Banned IPs</h6>
                    <?php if ($isAdmin): ?>
                    <button class="btn btn-sm btn-outline-danger" onclick="f2bFlush()"><i class="fas fa-trash me-1"></i>Flush All Bans</button>
                    <?php endif; ?>
                </div>

                <div id="f2b-jails-list">
                    <span class="text-muted">Loading...</span>
                </div>

                <?php if ($isAdmin): ?>
                <hr>
                <h6 class="fw-bold mb-3">Manual Ban</h6>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small">IP Address</label>
                        <input type="text" class="form-control form-control-sm" id="f2b-ban-ip" placeholder="e.g. 1.2.3.4" style="width:160px;">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small">Jail</label>
                        <select class="form-select form-select-sm" id="f2b-ban-jail" style="width:140px;">
                            <option value="sshd">sshd</option>
                            <option value="vsftpd">vsftpd</option>
                            <option value="inetpanel-auth">inetpanel-auth</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-danger" onclick="f2bBan()"><i class="fas fa-ban me-1"></i>Ban</button>
                    </div>
                </div>
                <?php if ($clientIp): ?>
                <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>Your IP: <code><?= htmlspecialchars($clientIp) ?></code></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ======================== WHITELIST TAB ======================== -->
    <div class="tab-pane fade" id="tab-whitelist">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Fail2Ban Whitelist (ignoreip)</h6>
                <p class="small text-muted">Whitelisted IPs are never banned by Fail2Ban. Changes apply to all jails.</p>

                <div id="wl-list" class="mb-3">
                    <span class="text-muted">Loading...</span>
                </div>

                <?php if ($isAdmin): ?>
                <div class="row g-2 align-items-end mt-3">
                    <div class="col-auto">
                        <label class="form-label small">IP or CIDR</label>
                        <input type="text" class="form-control form-control-sm" id="wl-ip-input" placeholder="e.g. 192.168.1.0/24" style="width:200px;">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-success" onclick="wlAdd()"><i class="fas fa-plus me-1"></i>Add</button>
                    </div>
                </div>
                <?php if ($clientIp): ?>
                <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>Your IP: <code><?= htmlspecialchars($clientIp) ?></code></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===================== Exposure Scan (qsa.sh) ===================== -->
    <div class="tab-pane fade" id="tab-qsa">
        <?php if (!TuxxinServices::enabled('qsa')): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-1">External exposure scan</h5>
                <p class="text-muted small mb-3" style="max-width:62ch;">
                    The External Security Audit (qsa.sh) is turned off, so this server is not being scanned
                    and nothing is sent to qsa.sh.
                </p>
                <a href="/admin/settings#tuxxin" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-plug me-1"></i> Settings &rarr; Tuxxin Services
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1">External exposure scan</h5>
                        <p class="text-muted small mb-0" style="max-width:62ch;">
                            Everything else on this page describes what the firewall was
                            <em>told</em> to do. This scans your public IP from the outside
                            and reports what the internet can actually reach. iNetPanel needs
                            <strong>no open ports</strong> — sites come in through the Cloudflare Tunnel —
                            so anything found here is usually a router port-forward or DMZ.
                            Powered by <a href="https://qsa.sh" target="_blank" rel="noopener">qsa.sh</a>.
                        </p>
                    </div>
                    <button class="btn btn-success" id="qsa-run-btn">
                        <i class="fas fa-radar me-1"></i> Run scan
                    </button>
                </div>

                <div class="alert alert-warning mt-3 mb-0 small" id="qsa-consent">
                    <strong>This performs a real external port and vulnerability scan</strong>
                    of <code id="qsa-target">this server's public IPv4</code>.
                    By running it you confirm you are authorised to scan that address.
                    See <a href="https://qsa.sh/terms" target="_blank" rel="noopener">qsa.sh/terms</a>.
                </div>
            </div>
        </div>

        <!-- What the latest scan means. Filled from the scan's own summary. -->
        <div class="card border-0 shadow-sm mb-3 d-none" id="qsa-summary-card">
            <div class="card-body" id="qsa-summary"></div>
        </div>

        <!-- Terminal-style output. qsa.sh is a CLI tool first; showing its real
             output rather than reformatting it keeps the result verifiable against
             running `curl -4 qsa.sh` by hand. -->
        <div class="card border-0 shadow-sm mb-3 d-none" id="qsa-output-card">
            <div class="card-header d-flex justify-content-between align-items-center py-2"
                 style="background:#0d1117;border-bottom:1px solid #30363d;">
                <span class="small" style="color:#8b949e;font-family:ui-monospace,monospace;">
                    <span style="color:#ff5f56;">&#9679;</span>
                    <span style="color:#ffbd2e;">&#9679;</span>
                    <span style="color:#27c93f;">&#9679;</span>
                    &nbsp; curl -4 qsa.sh
                </span>
                <span class="badge bg-secondary" id="qsa-tier-badge">free tier</span>
            </div>
            <div class="card-body p-0">
                <div class="alert alert-warning d-flex align-items-center m-3 d-none" id="qsa-repair-wrap">
                    <i class="fas fa-wrench me-2"></i>
                    <div class="flex-grow-1 small">
                        The panel can redeploy <code>/usr/local/bin/inetp</code> from the files it
                        already has. No download, no restart.
                    </div>
                    <button class="btn btn-sm btn-warning ms-2" id="qsa-repair-btn">Repair CLI</button>
                </div>
                <pre id="qsa-output" style="margin:0;padding:16px;background:#0d1117;color:#c9d1d9;
                     font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;
                     line-height:1.5;max-height:520px;overflow:auto;white-space:pre-wrap;
                     word-break:break-word;">Ready.</pre>

                <!-- Running indicator. The phase comes from the scan's own output. -->
                <div class="d-none" id="qsa-progress"
                     style="background:#0d1117;border-top:1px solid #30363d;padding:12px 16px;">
                    <div class="d-flex align-items-center mb-2">
                        <span class="spinner-border spinner-border-sm me-2" style="color:#27c93f;"></span>
                        <span class="small flex-grow-1" style="color:#c9d1d9;font-family:ui-monospace,monospace;">
                            <span id="qsa-phase">Contacting qsa.sh…</span>
                            <span style="color:#8b949e;"> · elapsed <span id="qsa-elapsed">0s</span></span>
                        </span>
                    </div>
                    <div class="progress" style="height:4px;background:#21262d;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                             style="width:100%;background:#27c93f;"></div>
                    </div>
                    <div class="small mt-2" style="color:#8b949e;">
                        <i class="fas fa-circle-info me-1"></i>
                        The scan runs on the server, not in this page — you can leave and come back.
                    </div>
                </div>
            </div>
        </div>

        <!-- Scan history: the panel's own archive (root-only, last 30 runs). -->
        <div class="card border-0 shadow-sm mb-3 d-none" id="qsa-history-card">
            <div class="card-body">
                <h6 class="mb-1">Previous scans</h6>
                <p class="text-muted small mb-2">
                    The last 30 scans are kept on this server. Select one to load it above.
                </p>
                <div class="list-group list-group-flush" id="qsa-history-list"></div>
            </div>
        </div>

        <!-- Change monitoring -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-1">Change monitoring</h6>
                <p class="text-muted small">
                    Re-scans on a schedule and compares the open ports, service versions and findings
                    with the last complete scan. Timing, countdowns and certificate dates are ignored,
                    and a scan that was cut off is never compared — so nothing is reported unless your
                    actual exposure changes.
                </p>
                <p class="text-muted small mb-3">
                    <strong>How you find out:</strong> a change raises a red
                    <em>Exposure changed</em> notice in the panel header until you dismiss
                    it here, writes a <a href="/admin/logs?source=qsa">log entry</a>, and posts to
                    the webhook below if you set one.
                </p>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="qsa-monitor">
                            <label class="form-check-label fw-semibold" for="qsa-monitor">Enable change monitoring</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Frequency</label>
                        <select class="form-select form-select-sm" id="qsa-schedule">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="hourly">Hourly (needs a Full token)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary btn-sm w-100" id="qsa-save-btn">Save monitoring settings</button>
                    </div>
                </div>
                <div class="small text-muted mt-2" id="qsa-monitor-state">—</div>

                <hr class="my-3">
                <label class="form-label small fw-semibold mb-1">
                    Webhook notification <span class="text-muted fw-normal">(optional — shared with all Tuxxin Services alerts)</span>
                </label>
                <p class="text-muted small mb-2">
                    A Discord or Slack incoming webhook, an <code>https://ntfy.sh/&lt;topic&gt;</code> URL,
                    or any other <code>https://</code> endpoint (it receives JSON with a <code>text</code> field).
                </p>
                <div class="input-group input-group-sm mb-2">
                    <input type="password" class="form-control" id="qsa-webhook" autocomplete="off"
                           placeholder="https://discord.com/api/webhooks/... or https://hooks.slack.com/services/...">
                    <button class="btn btn-outline-secondary" id="qsa-webhook-test" type="button">Send test</button>
                </div>
                <div class="small text-muted" id="qsa-webhook-state"></div>

                <div class="alert alert-danger d-flex align-items-center mt-3 d-none" id="qsa-unacked">
                    <i class="fas fa-triangle-exclamation me-2"></i>
                    <div class="flex-grow-1 small">
                        A change was detected and has not been acknowledged. The header notice
                        stays up until you dismiss it.
                    </div>
                    <button class="btn btn-sm btn-outline-danger ms-2" id="qsa-ack-btn">Dismiss</button>
                </div>
                <div class="mt-3 d-none" id="qsa-diff-wrap">
                    <label class="form-label small fw-semibold">Last detected change</label>
                    <pre id="qsa-diff" style="background:#0d1117;color:#c9d1d9;padding:12px;border-radius:6px;
                         font-family:ui-monospace,monospace;font-size:12px;max-height:300px;overflow:auto;"></pre>
                </div>
            </div>
        </div>

        <!-- Token -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-1">qsa.sh token <span class="text-muted fw-normal small">(optional)</span></h6>
                <p class="text-muted small mb-2">
                    Without a token you get the free tier: the top 1,000 TCP ports and one
                    scan per 24 hours. A Full token sweeps all 65,535 ports and allows a scan an hour;
                    a Deep token adds the complete check set, one credit per scan. The panel learns which
                    kind a token is from qsa.sh on its first scan, and never schedules a Deep token.
                </p>
                <div class="input-group input-group-sm" style="max-width:560px;">
                    <input type="password" class="form-control" id="qsa-token" autocomplete="off"
                           placeholder="Paste your qsa.sh token (32 hexadecimal characters)">
                    <button class="btn btn-outline-secondary" id="qsa-token-save">Save token</button>
                    <button class="btn btn-outline-danger d-none" id="qsa-token-clear" title="Remove the token">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="small text-muted mt-1" id="qsa-token-state">No token set — using the free tier.</div>
            </div>
        </div>

        <!-- Upsell -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-3">Scan depth</h6>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-2" style="font-size:.875rem;">
                    <thead>
                        <tr>
                            <th style="width:22%"></th>
                            <th>Free<div class="small text-muted fw-normal">$0 · see what the internet sees</div></th>
                            <th>Full <span class="badge bg-success">Pro</span><div class="small text-muted fw-normal">$5/mo · complete surface coverage</div></th>
                            <th>Deep<div class="small text-muted fw-normal">$7/scan · uncovers what the surface hides</div></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $qsaRows = [
                            ['Scans / IP',        '1 / 24h',              '1 / hour',                    'Unlimited'],
                            ['Ports (naabu)',     'Top 1,000 TCP',        'All 65,535 TCP',              'All 65,535 TCP'],
                            ['Vuln checks',       '~2,000 curated',       '~2,000 curated, all ports',   '~10,500 full set + custom'],
                            ['Findings',          'Ports, versions & top 3', 'Full list + remediation',  'Full list + remediation, emailed'],
                            ['Typical time',      '~30 seconds',          '~2–12 minutes',               '~13–16 minutes'],
                            ['Data retention',    'Nothing stored',       'Single-read or 24h Redis · no DB', 'Emailed · single-read or 24h Redis · no DB'],
                        ];
                        foreach ($qsaRows as [$label, $free, $full, $deep]): ?>
                        <tr>
                            <td class="text-muted"><?= htmlspecialchars($label) ?></td>
                            <td><?= htmlspecialchars($free) ?></td>
                            <td><?= htmlspecialchars($full) ?></td>
                            <td><?= htmlspecialchars($deep) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="https://qsa.sh/pay?tier=full" target="_blank" rel="noopener" class="btn btn-success btn-sm">Subscribe to Full &rarr;</a>
                    <a href="https://qsa.sh/pay?tier=deep" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">Buy a Deep scan &rarr;</a>
                    <a href="https://qsa.sh/pricing" target="_blank" rel="noopener" class="btn btn-link btn-sm text-muted">Compare tiers</a>
                </div>
                <p class="text-muted mt-2 mb-0" style="font-size:.75rem;">
                    All tiers run naabu, nmap + vulners and nuclei. qsa.sh is a Tuxxin service.
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
function showFwToast(msg, type = 'success') {
    const id = 'toast-' + Date.now();
    document.body.insertAdjacentHTML('beforeend',
        `<div id="${id}" class="toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3" style="z-index:9999">
            <div class="d-flex"><div class="toast-body">${msg}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button></div></div>`
    );
    setTimeout(() => document.getElementById(id)?.remove(), 4000);
}

let fwData = null;

function loadFirewallStatus() {
    fetch('/api/firewall?action=status')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showFwToast(data.error || 'Failed to load firewall status', 'danger');
                return;
            }
            fwData = data.data;
            renderFirewalld(fwData);
            renderFail2Ban(fwData);
        })
        .catch(err => {
            showFwToast('Failed to connect: ' + err.message, 'danger');
        });
    loadWhitelist();
    loadF2bSettings();
}

function renderFirewalld(d) {
    // Status cards
    const fwRunning = d.firewalld?.running;
    document.getElementById('fw-status-badge').className = 'badge ' + (fwRunning ? 'bg-success' : 'bg-danger');
    document.getElementById('fw-status-badge').textContent = fwRunning ? 'Running' : 'Stopped';
    document.getElementById('fw-icon').className = 'fas fa-shield-halved fa-2x mb-2 ' + (fwRunning ? 'text-success' : 'text-danger');
    document.getElementById('fw-zone').textContent = d.firewalld?.default_zone || '—';

    // VPN lockdown
    const vpnLocked = d.vpn_lockdown;
    document.getElementById('vpn-status-badge').className = 'badge ' + (vpnLocked ? 'bg-warning text-dark' : 'bg-secondary');
    document.getElementById('vpn-status-badge').textContent = vpnLocked ? 'Active' : 'Inactive';
    document.getElementById('vpn-detail').textContent = vpnLocked
        ? 'Sources: ' + (d.vpn_sources || []).join(', ')
        : 'No VPN lockdown';

    // Ports list
    const ports = d.firewalld?.ports || [];
    const services = d.firewalld?.services || [];
    let portsHtml = '';
    ports.forEach(p => {
        portsHtml += `<span class="badge bg-primary me-1 mb-1">${p}</span>`;
    });
    services.forEach(s => {
        portsHtml += `<span class="badge bg-info me-1 mb-1">${s}</span>`;
    });
    document.getElementById('fw-ports-list').innerHTML = portsHtml || '<span class="text-muted">No ports open</span>';

    // Zones list
    const zones = d.zones || {};
    let zonesHtml = '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Zone</th><th>Interfaces</th><th>Sources</th></tr></thead><tbody>';
    for (const [name, info] of Object.entries(zones)) {
        zonesHtml += `<tr><td class="fw-medium">${name}</td><td>${(info.interfaces||[]).join(', ') || '—'}</td><td>${(info.sources||[]).join(', ') || '—'}</td></tr>`;
    }
    zonesHtml += '</tbody></table></div>';
    document.getElementById('fw-zones-list').innerHTML = Object.keys(zones).length ? zonesHtml : '<span class="text-muted">No active zones</span>';

    // VPN zone ports
    if (vpnLocked && d.vpn_ports) {
        let vpnHtml = '<h6 class="fw-bold mb-2 mt-3">VPN Zone Ports</h6>';
        d.vpn_ports.forEach(p => {
            vpnHtml += `<span class="badge bg-warning text-dark me-1 mb-1">${p}</span>`;
        });
        document.getElementById('fw-zones-list').innerHTML += vpnHtml;
    }
}

function renderFail2Ban(d) {
    const f2b = d.fail2ban;
    const running = f2b?.running;
    document.getElementById('f2b-status-badge').className = 'badge ' + (running ? 'bg-success' : 'bg-danger');
    document.getElementById('f2b-status-badge').textContent = running ? 'Running' : 'Stopped';

    const jails = f2b?.jails || {};
    let totalBanned = 0;
    let html = '';

    for (const [name, info] of Object.entries(jails)) {
        totalBanned += info.banned;
        html += `<div class="border rounded p-3 mb-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-bold">${name}</span>
                    <span class="badge bg-${info.banned > 0 ? 'danger' : 'success'} ms-2">${info.banned} banned</span>
                    <span class="small text-muted ms-2">(${info.total} total)</span>
                </div>
            </div>`;
        if (info.banned_ips && info.banned_ips.length > 0) {
            html += '<div class="mt-2">';
            info.banned_ips.forEach(ip => {
                html += `<span class="badge bg-light text-dark border me-1 mb-1">
                    ${window.tsvcIp ? window.tsvcIp(ip) : ip}
                    <button class="btn btn-sm p-0 ms-1 text-danger" onclick="f2bUnban('${ip}','${name}')" title="Unban">
                        <i class="fas fa-times" style="font-size:0.7rem;"></i>
                    </button>
                </span>`;
            });
            html += '</div>';
        }
        html += '</div>';
    }

    document.getElementById('f2b-total-banned').textContent = totalBanned;
    document.getElementById('f2b-jails-list').innerHTML = html || '<span class="text-muted">No jails found</span>';
}

function loadWhitelist() {
    fetch('/api/firewall?action=f2b_whitelist_get')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const list = data.data || [];
            let html = '';
            list.forEach(ip => {
                const isSystem = (ip === '127.0.0.1/8' || ip === '::1');
                html += `<span class="badge bg-light text-dark border me-1 mb-1">
                    ${ip}
                    ${!isSystem ? `<button class="btn btn-sm p-0 ms-1 text-danger" onclick="wlRemove('${ip}')" title="Remove"><i class="fas fa-times" style="font-size:0.7rem;"></i></button>` : ''}
                </span>`;
            });
            document.getElementById('wl-list').innerHTML = html || '<span class="text-muted">No whitelisted IPs</span>';
        });
}

function loadF2bSettings() {
    fetch('/api/firewall?action=f2b_settings_get')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            document.getElementById('f2b-bantime').value = data.data.bantime;
            document.getElementById('f2b-findtime').value = data.data.findtime;
            document.getElementById('f2b-maxretry').value = data.data.maxretry;
        });
}

// --- Actions ---
function fwAction(action, body = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(body)) fd.append(k, v);
    return fetch('/api/firewall', { method: 'POST', body: fd }).then(r => r.json());
}

function fwOpenPort() {
    const port = document.getElementById('fw-port-input').value;
    const proto = document.getElementById('fw-proto-input').value;
    if (!port) return;
    fwAction('open_port', { port, protocol: proto }).then(d => {
        showFwToast(d.success ? `Port ${port}/${proto} opened` : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        loadFirewallStatus();
    });
}

function fwClosePort() {
    const port = document.getElementById('fw-port-input').value;
    const proto = document.getElementById('fw-proto-input').value;
    if (!port) return;
    fwAction('close_port', { port, protocol: proto }).then(d => {
        showFwToast(d.success ? `Port ${port}/${proto} closed` : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        loadFirewallStatus();
    });
}

function fwReload() {
    fwAction('reload').then(d => {
        showFwToast(d.success ? 'Firewalld reloaded' : 'Failed', d.success ? 'success' : 'danger');
        loadFirewallStatus();
    });
}

function f2bBan() {
    const ip = document.getElementById('f2b-ban-ip').value;
    const jail = document.getElementById('f2b-ban-jail').value;
    if (!ip) return;
    fwAction('f2b_ban', { ip, jail }).then(d => {
        showFwToast(d.success ? `${ip} banned in ${jail}` : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        document.getElementById('f2b-ban-ip').value = '';
        loadFirewallStatus();
    });
}

function f2bUnban(ip, jail) {
    fwAction('f2b_unban', { ip, jail }).then(d => {
        showFwToast(d.success ? `${ip} unbanned from ${jail}` : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        loadFirewallStatus();
    });
}

function f2bFlush() {
    if (!confirm('Unban ALL IPs across all jails?')) return;
    fwAction('f2b_flush').then(d => {
        showFwToast(d.success ? 'All bans flushed' : 'Failed', d.success ? 'success' : 'danger');
        loadFirewallStatus();
    });
}

function f2bSaveSettings() {
    const bantime = document.getElementById('f2b-bantime').value;
    const findtime = document.getElementById('f2b-findtime').value;
    const maxretry = document.getElementById('f2b-maxretry').value;
    fwAction('f2b_settings_save', { bantime, findtime, maxretry }).then(d => {
        showFwToast(d.success ? 'Settings saved' : (d.error || 'Failed'), d.success ? 'success' : 'danger');
    });
}

function wlAdd() {
    const ip = document.getElementById('wl-ip-input').value;
    if (!ip) return;
    fwAction('f2b_whitelist_add', { ip }).then(d => {
        showFwToast(d.success ? `${ip} whitelisted` : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        document.getElementById('wl-ip-input').value = '';
        loadWhitelist();
    });
}

function wlRemove(ip) {
    if (!confirm(`Remove ${ip} from whitelist?`)) return;
    fwAction('f2b_whitelist_remove', { ip }).then(d => {
        showFwToast(d.success ? `${ip} removed` : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        loadWhitelist();
    });
}

function fwAutoConfigure() {
    if (!confirm('Auto-configure firewall with standard iNetPanel ports (SSH, FTP, HTTP, phpMyAdmin)?\n\nDefault zone will be set to DROP — all incoming traffic is denied except explicitly opened ports.')) return;
    fwAction('auto_configure').then(d => {
        showFwToast(d.success ? 'Firewall configured: ' + (d.ports||[]).join(', ') : (d.error || 'Failed'), d.success ? 'success' : 'danger');
        loadFirewallStatus();
    });
}

document.addEventListener('DOMContentLoaded', loadFirewallStatus);

/* ===================== Exposure Scan (qsa.sh) ===================== */
<?php if (TuxxinServices::enabled('qsa')): ?>
const QSA_PORTS = <?= json_encode(TuxxinServices::portMap(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const QSA_PHASE_LABEL = {
    contacting: 'Contacting qsa.sh…',
    countdown:  'Authorisation countdown (15s) — nothing is scanned yet',
    naabu:      'naabu — discovering open ports',
    nmap:       'nmap — service and version detection',
    nuclei:     'nuclei — exposure and vulnerability checks',
    queued:     'Token scan queued — waiting for qsa.sh',
    polling:    'Token scan running on qsa.sh — checking every 20s',
};
const QSA_STATUS = {
    complete:     ['success', 'Complete'],
    partial:      ['warning text-dark', 'Partial results'],
    capped:       ['warning text-dark', 'Reached the time limit'],
    interrupted:  ['secondary', 'Did not finish'],
    rate_limited: ['secondary', 'Rate limited'],
    refused:      ['danger', 'Refused by qsa.sh'],
    token_rejected: ['danger', 'Token not accepted'],
    no_credits:   ['danger', 'No Deep credits left'],
    failed:       ['danger', 'Failed'],
};
const qsaEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function qsaBadge(status) {
    const b = QSA_STATUS[status] || ['secondary', status || 'unknown'];
    return '<span class="badge bg-' + b[0] + '">' + qsaEsc(b[1]) + '</span>';
}

function qsaDescribePort(port) {
    if (QSA_PORTS[port]) return QSA_PORTS[port];
    if (port >= 40000 && port <= 50000) return {label: 'FTP passive range', kind: 'panel'};
    return {label: 'Not opened by iNetPanel', kind: 'unknown'};
}

/* What a scan means for an iNetPanel server, which needs no open TCP ports. */
function qsaRenderSummary(meta) {
    const card = document.getElementById('qsa-summary-card');
    const box  = document.getElementById('qsa-summary');
    if (!meta) { card.classList.add('d-none'); return; }
    const when = meta.ts ? new Date(meta.ts * 1000).toLocaleString() : '';
    const ip   = meta.ip ? ' of <code>' + qsaEsc(meta.ip) + '</code>' : '';
    let html = '<div class="d-flex justify-content-between flex-wrap gap-2 mb-2"><div class="fw-semibold">'
             + 'Latest scan' + ip + ' ' + qsaBadge(meta.status) + '</div><div class="small text-muted">'
             + qsaEsc(when) + (meta.tier ? ' · ' + qsaEsc(meta.tier) + ' tier' : '') + '</div></div>';
    const ports = meta.ports || [];
    if (meta.status === 'complete' && meta.open_ports === 0) {
        html += '<div class="alert alert-success small mb-0"><i class="fas fa-circle-check me-1"></i>'
              + '<strong>0 open TCP ports.</strong> This is what iNetPanel is built for: hosted sites are served through '
              + 'the Cloudflare Tunnel, so nothing on this server needs to be reachable directly.'
              + (meta.tier === 'free' ? ' The free scan covers the top 1,000 ports; a Full scan checks all 65,535.' : '')
              + '</div>';
    } else if (ports.length) {
        const rows = ports.map(p => {
            const d = qsaDescribePort(p.port);
            const cls = d.kind === 'site' ? 'danger' : 'warning';
            return '<tr><td class="font-monospace">' + p.port + '/tcp</td><td>' + qsaEsc(p.service)
                 + (p.version ? ' <span class="text-muted">' + qsaEsc(p.version) + '</span>' : '') + '</td>'
                 + '<td><span class="badge bg-' + cls + (cls === 'warning' ? ' text-dark' : '') + '">' + qsaEsc(d.label) + '</span></td></tr>';
        }).join('');
        html += '<div class="alert alert-danger small mb-2"><i class="fas fa-triangle-exclamation me-1"></i>'
              + '<strong>' + ports.length + ' open TCP port' + (ports.length === 1 ? '' : 's') + ' reachable from the internet.</strong> '
              + 'iNetPanel needs none: sites come in through the Cloudflare Tunnel. An open port almost always means a '
              + 'port-forward or DMZ on your router or host. Remove it there; then re-scan.</div>'
              + '<div class="table-responsive"><table class="table table-sm small align-middle mb-0"><thead><tr><th>Port</th>'
              + '<th>Service</th><th>What it is</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }
    if (meta.status === 'capped') {
        html += '<div class="alert alert-warning small mt-2 mb-0"><i class="fas fa-hourglass-end me-1"></i>'
              + 'This scan reached qsa.sh\'s time limit before it finished, so the results above are only what it saw first. '
              + 'A server with this much exposure needs a <a href="https://qsa.sh/pricing" target="_blank" rel="noopener">Full or Deep scan</a> '
              + 'to be covered completely.</div>';
    } else if (meta.status === 'partial') {
        html += '<div class="alert alert-warning small mt-2 mb-0">qsa.sh reports that some checks did not run. '
              + 'Do not read this as a clean result; run it again.</div>';
    } else if (meta.status === 'interrupted') {
        html += '<div class="alert alert-secondary small mt-2 mb-0">The scan stopped before it finished '
              + '(connection lost). Its partial output is kept below; run it again for a complete result.</div>';
    }
    if (meta.findings_total) {
        const sev = meta.severity || {};
        const parts = Object.keys(sev).map(k => sev[k] + ' ' + k);
        html += '<div class="small text-muted mt-2">Findings: ' + meta.findings_total
              + (parts.length ? ' (' + qsaEsc(parts.join(', ')) + ')' : '') + ' — details in the report below.</div>';
    }
    box.innerHTML = html;
    card.classList.remove('d-none');
}

/* The terminal shows qsa.sh's own output, minus the countdown frames. */
function qsaClean(t) {
    return (t || '')
        .split('\n')
        .filter(l => !/Starting scan of .* in \d+s/.test(l) && !/^\s*scanning…?\s*$/.test(l))
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function qsaLoadSettings() {
    fetch('/api/firewall?action=qsa_settings_get')
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            document.getElementById('qsa-monitor').checked = !!d.monitor;
            document.getElementById('qsa-schedule').value  = d.schedule || 'daily';
            const tierName = d.token_tier === 'deep' ? 'Deep token' : (d.token_tier === 'full' ? 'Full token' : 'token');
            document.getElementById('qsa-tier-badge').textContent = d.has_token ? tierName : 'free tier';
            document.getElementById('qsa-token-state').textContent = d.has_token
                ? (d.token_tier === 'deep' ? 'Deep token saved — each scan spends a credit; it is never scheduled.'
                   : d.token_tier === 'full' ? 'Full token saved — all 65,535 ports, one scan an hour.'
                   : 'Token saved — its tier (Full or Deep) is confirmed on its first scan.')
                : 'No token set — using the free tier.';
            document.getElementById('qsa-token-clear').classList.toggle('d-none', !d.has_token);
            let st = d.monitor_active ? 'Monitoring is on.' : (d.monitor ? 'Monitoring is set, but not scheduled.' : 'Monitoring is off.');
            if (d.last_run)    st += ' Last scan: ' + d.last_run + '.';
            if (d.last_change) st += ' Last change detected: ' + d.last_change + '.';
            document.getElementById('qsa-monitor-state').textContent = st;

            // The URL itself is never returned — only whether one is saved.
            const wh = document.getElementById('qsa-webhook');
            wh.value = '';
            wh.placeholder = d.webhook_set
                ? '•'.repeat(24) + '  (' + (d.webhook_kind || 'saved') + ' webhook saved — type to replace)'
                : 'https://discord.com/api/webhooks/... or https://hooks.slack.com/services/...';
            document.getElementById('qsa-webhook-state').textContent = d.webhook_set
                ? 'Change alerts are delivered to your ' + (d.webhook_kind || '') + ' webhook.'
                : 'No webhook set — changes appear in the panel header only.';

            document.getElementById('qsa-unacked').classList.toggle('d-none', !d.unacked);
            if (d.last_change) qsaLoadDiff();
        });
}

document.getElementById('qsa-webhook-test').addEventListener('click', function () {
    const url = document.getElementById('qsa-webhook').value.trim();
    const btn = this;
    btn.disabled = true; btn.textContent = 'Sending...';
    const fd = new FormData();
    fd.append('webhook_url', url);
    fetch('/api/firewall?action=qsa_webhook_test', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showFwToast(qsaEsc(d.success ? (d.message || 'Test delivered.') : (d.error || 'Delivery failed.')), d.success ? 'success' : 'danger');
            if (d.success) qsaLoadSettings();
        })
        .catch(() => showFwToast(qsaEsc('Could not reach the panel API.'), 'danger'))
        .finally(() => { btn.disabled = false; btn.textContent = 'Send test'; });
});

document.getElementById('qsa-ack-btn').addEventListener('click', function () {
    fetch('/api/firewall?action=qsa_ack', { method: 'POST' })
        .then(r => r.json())
        .then(() => {
            document.getElementById('qsa-unacked').classList.add('d-none');
            // Drop the header notice without a reload.
            document.querySelectorAll('a[href="/admin/firewall#tab-qsa"].btn-danger')
                    .forEach(el => el.remove());
            showFwToast(qsaEsc('Dismissed.'), 'success');
        });
});

// A webhook field the user emptied on purpose must be distinguishable from one
// they never touched, otherwise the saved URL can never be removed.
document.getElementById('qsa-webhook').addEventListener('input', function () {
    this.dataset.cleared = this.value.trim() === '' ? '1' : '0';
});

function qsaLoadDiff() {
    fetch('/api/firewall?action=qsa_diff')
        .then(r => r.json())
        .then(d => {
            if (d.diff && d.diff.trim() !== '') {
                document.getElementById('qsa-diff').textContent = d.diff;
                document.getElementById('qsa-diff-wrap').classList.remove('d-none');
            }
        });
}

/* A scan runs in its own systemd unit on the server. The page only starts it
   and then polls, so leaving and coming back picks the same scan up again. */
let qsaPoll = null;

function qsaSetRunning(on) {
    const btn = document.getElementById('qsa-run-btn');
    btn.disabled = on;
    btn.innerHTML = on ? '<span class="spinner-border spinner-border-sm me-1"></span> Scanning…'
                       : '<i class="fas fa-radar me-1"></i> Run scan';
    document.getElementById('qsa-progress').classList.toggle('d-none', !on);
}

function qsaFmt(secs) {
    return secs < 60 ? secs + 's' : Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
}

function qsaPollStatus() {
    fetch('/api/firewall?action=qsa_status')
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                qsaStop();
                if (d.error) document.getElementById('qsa-output').textContent = d.error;
                return;
            }
            const out = document.getElementById('qsa-output');
            if (d.running) {
                document.getElementById('qsa-output-card').classList.remove('d-none');
                qsaSetRunning(true);
                document.getElementById('qsa-phase').textContent = QSA_PHASE_LABEL[d.phase] || 'Running…';
                document.getElementById('qsa-elapsed').textContent = qsaFmt(d.elapsed || 0);
                const txt = qsaClean(d.live);
                out.textContent = txt || 'Contacting qsa.sh…\n\nThe free tier takes about 30 seconds, plus a 15-second\n'
                                       + 'authorisation countdown before the scan begins.\nA token scan can take several minutes.';
                out.scrollTop = out.scrollHeight;
                if (!qsaPoll) qsaPoll = setInterval(qsaPollStatus, 2000);
                return;
            }
            const finishedNow = !!qsaPoll;
            qsaStop();
            if (!finishedNow) return;          // page load with nothing running
            if (d.result) {
                qsaRenderSummary(d.result);
                qsaOpenScan(d.result.id, true);
            } else {
                qsaRenderSummary(null);
                out.textContent = qsaClean(d.final_output) || ('Scan ended: ' + (d.status_label || 'no result') + '.');
                if (d.state && d.state.status === 'rate_limited') {
                    out.textContent += '\n\n— The free tier allows one scan per 24 hours. '
                                     + 'A Full token raises this to hourly; Deep has no cooldown.';
                }
            }
            qsaLoadSettings();
            qsaLoadHistory(false);
        })
        .catch(() => {});
}

function qsaStop() {
    if (qsaPoll) { clearInterval(qsaPoll); qsaPoll = null; }
    qsaSetRunning(false);
}

document.getElementById('qsa-run-btn').addEventListener('click', function () {
    document.getElementById('qsa-output-card').classList.remove('d-none');
    document.getElementById('qsa-output').textContent = 'Starting the scan…';
    qsaSetRunning(true);
    const fd = new FormData();
    fd.append('action', 'qsa_scan');
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            document.getElementById('qsa-repair-wrap').classList.toggle('d-none', !d.repairable);
            if (!d.success && !d.running) {
                qsaSetRunning(false);
                document.getElementById('qsa-output').textContent = d.error || 'Could not start the scan.';
                return;
            }
            if (!qsaPoll) qsaPoll = setInterval(qsaPollStatus, 2000);
            qsaPollStatus();
        })
        .catch(e => { qsaSetRunning(false); document.getElementById('qsa-output').textContent = 'Request failed: ' + e; });
});

/* Scan archive. qsa.sh stores nothing, so the panel keeps its own copy. */
function qsaLoadHistory(autoOpenLatest) {
    fetch('/api/firewall?action=qsa_history')
        .then(r => r.json())
        .then(d => {
            const card = document.getElementById('qsa-history-card');
            const list = document.getElementById('qsa-history-list');
            if (!d.success || !d.scans || !d.scans.length) { card.classList.add('d-none'); return; }
            list.innerHTML = '';
            d.scans.forEach((sc, i) => {
                const a = document.createElement('a');
                a.href = '#';
                a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2';
                const ports = (sc.open_ports === null || sc.open_ports === undefined)
                    ? '<span class="text-muted small">—</span>'
                    : (sc.open_ports === 0
                        ? '<span class="badge bg-success">0 open ports</span>'
                        : '<span class="badge bg-danger">' + sc.open_ports + ' open port' + (sc.open_ports === 1 ? '' : 's') + '</span>');
                const when = sc.ts ? new Date(sc.ts * 1000).toLocaleString() : sc.id;
                a.innerHTML = '<span class="small"><i class="fas fa-clock me-2 text-muted"></i>' + qsaEsc(when)
                            + (i === 0 ? ' <span class="badge bg-secondary ms-1">latest</span>' : '')
                            + (sc.trigger === 'monitor' ? ' <span class="text-muted">· scheduled</span>' : '')
                            + '</span><span class="d-flex gap-1">' + qsaBadge(sc.status) + ports + '</span>';
                a.addEventListener('click', ev => { ev.preventDefault(); qsaRenderSummary(sc); qsaOpenScan(sc.id); });
                list.appendChild(a);
            });
            card.classList.remove('d-none');
            if (autoOpenLatest) { qsaRenderSummary(d.scans[0]); qsaOpenScan(d.scans[0].id, true); }
        })
        .catch(() => {});
}

function qsaOpenScan(id, quiet) {
    fetch('/api/firewall?action=qsa_view&id=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(d => {
            if (!d.success) { if (!quiet) showFwToast(qsaEsc(d.error || 'Could not load that scan.'), 'danger'); return; }
            document.getElementById('qsa-output-card').classList.remove('d-none');
            const when = d.meta && d.meta.ts ? new Date(d.meta.ts * 1000).toLocaleString() : id;
            document.getElementById('qsa-output').textContent = '# saved scan from ' + when + '\n\n' + qsaClean(d.output);
        })
        .catch(() => { if (!quiet) showFwToast(qsaEsc('Could not load that scan.'), 'danger'); });
}

document.getElementById('qsa-repair-btn').addEventListener('click', function () {
    const btn = this, out = document.getElementById('qsa-output');
    btn.disabled = true; btn.textContent = 'Repairing…';
    const fd = new FormData();
    fd.append('action', 'qsa_repair_cli');
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showFwToast(qsaEsc(d.message), d.success ? 'success' : 'danger');
            if (d.log) { out.textContent += '\n\n--- redeploy log ---\n' + d.log; }
            if (d.success) {
                document.getElementById('qsa-repair-wrap').classList.add('d-none');
            }
        })
        .catch(() => showFwToast(qsaEsc('Repair request failed.'), 'danger'))
        .finally(() => { btn.disabled = false; btn.textContent = 'Repair CLI'; });
});

function qsaSave(extra, okMsg) {
    const fd = new FormData();
    fd.append('action', 'qsa_settings_save');
    fd.append('monitor',  document.getElementById('qsa-monitor').checked ? '1' : '0');
    fd.append('schedule', document.getElementById('qsa-schedule').value);
    // Only send when the user typed something; an untouched field means
    // "leave the saved webhook alone".
    const _wh = document.getElementById('qsa-webhook').value.trim();
    if (_wh !== '' || document.getElementById('qsa-webhook').dataset.cleared === '1') {
        fd.append('webhook_url', _wh);
    }
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            let msg = d.success ? okMsg : (d.error || 'Could not save.');
            if (d.success && document.getElementById('qsa-monitor').checked && !d.monitor && d.reason) {
                msg += ' Not scheduled: ' + d.reason + '.';
            }
            showFwToast(qsaEsc(msg), d.success ? 'success' : 'danger');
            qsaLoadSettings();
            return d;
        });
}

document.getElementById('qsa-save-btn').addEventListener('click', () => qsaSave({}, 'Monitoring settings saved.'));

document.getElementById('qsa-token-save').addEventListener('click', function () {
    const el = document.getElementById('qsa-token');
    if (!el.value.trim()) { showFwToast(qsaEsc('Enter a token first.'), 'danger'); return; }
    qsaSave({qsa_token: el.value.trim()}, 'Token saved.').then(() => { el.value = ''; });
});

document.getElementById('qsa-token-clear').addEventListener('click', function () {
    if (!confirm('Remove the qsa.sh token? Scans fall back to the free tier.')) return;
    const fd = new FormData();
    fd.append('action', 'qsa_clear_token');
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { showFwToast(qsaEsc(d.success ? 'Token removed.' : (d.error || 'Could not remove it.')), d.success ? 'success' : 'danger'); qsaLoadSettings(); });
});

qsaLoadSettings();
qsaLoadHistory(true);   // restores the last scan into the pane on page load
qsaPollStatus();        // and picks up a scan that is still running
<?php endif; ?>

</script>
