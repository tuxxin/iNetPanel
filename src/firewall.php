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
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1">External exposure scan</h5>
                        <p class="text-muted small mb-0" style="max-width:60ch;">
                            Everything else on this page describes what the firewall was
                            <em>told</em> to do. This scans your public IP from the outside
                            and reports what the internet can actually reach — including
                            anything exposed by a container or upstream of this server.
                            Powered by <a href="https://qsa.sh" target="_blank" rel="noopener">qsa.sh</a>.
                        </p>
                    </div>
                    <button class="btn btn-success" id="qsa-run-btn">
                        <i class="fas fa-radar me-1"></i> Run scan
                    </button>
                </div>

                <div class="alert alert-warning mt-3 mb-0 small" id="qsa-consent">
                    <strong>This performs a real external port and vulnerability scan</strong>
                    of <code id="qsa-target">this server's public IP</code>.
                    By running it you confirm you are authorised to scan that address.
                    See <a href="https://qsa.sh/terms" target="_blank" rel="noopener">qsa.sh/terms</a>.
                </div>
            </div>
        </div>

        <!-- Terminal-style output. qsa.sh is a CLI tool first; showing its real
             output rather than reformatting it keeps the result verifiable against
             running `curl qsa.sh` by hand. -->
        <div class="card border-0 shadow-sm mb-3 d-none" id="qsa-output-card">
            <div class="card-header d-flex justify-content-between align-items-center py-2"
                 style="background:#0d1117;border-bottom:1px solid #30363d;">
                <span class="small" style="color:#8b949e;font-family:ui-monospace,monospace;">
                    <span style="color:#ff5f56;">&#9679;</span>
                    <span style="color:#ffbd2e;">&#9679;</span>
                    <span style="color:#27c93f;">&#9679;</span>
                    &nbsp; curl qsa.sh
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

                <!-- Running indicator. The scan is a single blocking request with no
                     server-side progress to read, so this reports elapsed time and a
                     realistic duration range rather than faking a percentage. -->
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
                    <div class="small mt-2" style="color:#d29922;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Keep this page open. The scan runs in this request and qsa.sh stores
                        nothing — navigating away or reloading discards the output for good.
                    </div>
                </div>
            </div>
        </div>

        <!-- Change monitoring -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-1">Change monitoring</h6>
                <p class="text-muted small">
                    Re-scans on a schedule and compares against the previous result,
                    ignoring volatile detail like scan duration and certificate expiry
                    dates. Nothing is reported unless your actual exposure changes — a
                    new open port, a service version change, a new finding.
                </p>
                <p class="text-muted small mb-3">
                    <strong>How you find out:</strong> a change raises a red
                    <em>Exposure changed</em> notice in the panel header until you dismiss
                    it here, and writes a <a href="/admin/logs?source=qsa">log entry</a>.
                    iNetPanel does not run a mail server, so there is no email alert —
                    add a webhook below if you want to be told when you are not logged in.
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
                            <option value="hourly">Hourly (needs a token)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary btn-sm w-100" id="qsa-save-btn">Save monitoring settings</button>
                    </div>
                </div>
                <div class="small text-muted mt-2" id="qsa-monitor-state">—</div>

                <hr class="my-3">
                <label class="form-label small fw-semibold mb-1">
                    Webhook notification <span class="text-muted fw-normal">(optional)</span>
                </label>
                <p class="text-muted small mb-2">
                    Paste a Discord or Slack incoming webhook URL. The change diff is posted
                    there as soon as it is detected. Any other <code>https://</code> endpoint
                    receives the same message as JSON <code>{"text": "..."}</code>.
                </p>
                <div class="input-group input-group-sm mb-2">
                    <input type="url" class="form-control" id="qsa-webhook"
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
                    scan per 24 hours. A token unlocks all 65,535 ports and more frequent scans.
                </p>
                <div class="input-group input-group-sm" style="max-width:520px;">
                    <input type="password" class="form-control" id="qsa-token" placeholder="Paste your qsa.sh token">
                    <button class="btn btn-outline-secondary" id="qsa-token-save">Save token</button>
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
                    ${ip}
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

/* The free scan streams a 15-second authorisation countdown before it starts,
   which is meaningful in a terminal and pure noise in a browser. Strip those
   frames but keep everything else verbatim, so what is shown here matches what
   `curl qsa.sh` prints. */
function qsaClean(t) {
    return (t || '')
        .split('\n')
        .filter(l => !/Starting scan of .* in \d+s/.test(l))
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
            document.getElementById('qsa-tier-badge').textContent = d.has_token ? 'token tier' : 'free tier';
            document.getElementById('qsa-token-state').textContent = d.has_token
                ? 'Token saved — all 65,535 ports available.'
                : 'No token set — using the free tier.';
            let st = d.monitor ? 'Monitoring is on.' : 'Monitoring is off.';
            if (d.last_run)    st += ' Last run: ' + d.last_run + '.';
            if (d.last_change) st += ' Last change detected: ' + d.last_change + '.';
            document.getElementById('qsa-monitor-state').textContent = st;

            // The URL itself is never returned by the API — only whether one is
            // set — so show a placeholder rather than echoing a secret back.
            const wh = document.getElementById('qsa-webhook');
            wh.value = '';
            wh.placeholder = d.webhook_set
                ? '\u2022'.repeat(24) + '  (' + (d.webhook_kind || 'saved') + ' webhook saved \u2014 type to replace)'
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
            showFwToast(d.success ? (d.message || 'Test delivered.') : (d.error || 'Delivery failed.'),
                        d.success ? 'success' : 'danger');
            if (d.success) qsaLoadSettings();
        })
        .catch(() => showFwToast('Could not reach the panel API.', 'danger'))
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
            showFwToast('Dismissed.', 'success');
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

document.getElementById('qsa-run-btn').addEventListener('click', function () {
    const btn = this;
    const card = document.getElementById('qsa-output-card');
    const out  = document.getElementById('qsa-output');
    card.classList.remove('d-none');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Scanning…';
    out.textContent = 'Contacting qsa.sh…\n\nThe free tier takes about 30 seconds, plus a 15-second\nauthorisation countdown before the scan begins.\nA token scan can take several minutes.';
    qsaStartProgress();

    const fd = new FormData();
    fd.append('action', 'qsa_scan');
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            out.textContent = qsaClean(d.output) || 'No output returned.';
            // Only offer the repair when the API confirmed the panel's own copy
            // of inetp is current — otherwise redeploying just copies the same
            // stale file and looks like the repair silently did nothing.
            document.getElementById('qsa-repair-wrap')
                    .classList.toggle('d-none', !d.repairable);
            document.getElementById('qsa-tier-badge').textContent =
                (d.tier === 'token' ? 'token tier' : 'free tier');
            if (d.rate_limited) {
                /* Expected on the free tier rather than an error — say so plainly
                   instead of showing a failure the user cannot act on. */
                out.textContent += '\n\n— Free tier allows one scan per 24 hours. '
                                 + 'A token raises this to hourly (Full) or unlimited (Deep).';
            }
        })
        .catch(e => { out.textContent = 'Request failed: ' + e; })
        .finally(() => {
            qsaStopProgress();
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-radar me-1"></i> Run scan';
            qsaLoadSettings();
        });
});

/* Progress reporting for a request that has no readable progress.
   qsa.sh runs a fixed pipeline, so the phase labels are keyed to its real
   stage order and the elapsed counter is genuine. Nothing here claims a
   percentage, because there is no percentage to report. */
let qsaTimer = null, qsaT0 = 0;

const QSA_PHASES = [
    [0,   'Contacting qsa.sh\u2026'],
    [3,   'Authorisation countdown (~15s) \u2014 scan has not started yet'],
    [18,  'naabu \u2014 discovering open ports'],
    [40,  'nmap \u2014 service and version detection'],
    [70,  'nuclei \u2014 exposure and vulnerability checks'],
    [150, 'Still running \u2014 token-tier scans sweep all 65,535 ports'],
];

function qsaStartProgress() {
    qsaT0 = Date.now();
    document.getElementById('qsa-progress').classList.remove('d-none');
    window.addEventListener('beforeunload', qsaGuard);
    if (qsaTimer) clearInterval(qsaTimer);
    qsaTimer = setInterval(() => {
        const secs = Math.floor((Date.now() - qsaT0) / 1000);
        document.getElementById('qsa-elapsed').textContent =
            secs < 60 ? secs + 's' : Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
        let label = QSA_PHASES[0][1];
        for (const ph of QSA_PHASES) { if (secs >= ph[0]) label = ph[1]; }
        document.getElementById('qsa-phase').textContent = label;
    }, 1000);
}

function qsaStopProgress() {
    if (qsaTimer) { clearInterval(qsaTimer); qsaTimer = null; }
    document.getElementById('qsa-progress').classList.add('d-none');
    window.removeEventListener('beforeunload', qsaGuard);
}

/* Browsers show their own generic wording; returnValue just has to be set. */
function qsaGuard(e) {
    e.preventDefault();
    e.returnValue = 'A scan is still running. Leaving now discards the result.';
    return e.returnValue;
}

document.getElementById('qsa-repair-btn').addEventListener('click', function () {
    const btn = this, out = document.getElementById('qsa-output');
    btn.disabled = true; btn.textContent = 'Repairing…';
    const fd = new FormData();
    fd.append('action', 'qsa_repair_cli');
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showFwToast(d.message, d.success ? 'success' : 'danger');
            if (d.log) { out.textContent += '\n\n--- redeploy log ---\n' + d.log; }
            if (d.success) {
                document.getElementById('qsa-repair-wrap').classList.add('d-none');
            }
        })
        .catch(() => showFwToast('Repair request failed.', 'danger'))
        .finally(() => { btn.disabled = false; btn.textContent = 'Repair CLI'; });
});

document.getElementById('qsa-save-btn').addEventListener('click', function () {
    const fd = new FormData();
    fd.append('action', 'qsa_settings_save');
    fd.append('monitor',  document.getElementById('qsa-monitor').checked ? '1' : '0');
    fd.append('schedule', document.getElementById('qsa-schedule').value);
    // Only send when the user typed something; an untouched field means
    // "leave the saved webhook alone". Clearing is done by typing a space.
    const _wh = document.getElementById('qsa-webhook').value.trim();
    if (_wh !== '' || document.getElementById('qsa-webhook').dataset.cleared === '1') {
        fd.append('webhook_url', _wh);
    }
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showFwToast(d.success ? 'Monitoring settings saved.' : (d.error || 'Could not save.'), d.success ? 'success' : 'danger');
            qsaLoadSettings();
        });
});

document.getElementById('qsa-token-save').addEventListener('click', function () {
    const el = document.getElementById('qsa-token');
    if (!el.value.trim()) { showFwToast('Enter a token first.', 'danger'); return; }
    const fd = new FormData();
    fd.append('action', 'qsa_settings_save');
    fd.append('qsa_token', el.value.trim());
    fd.append('monitor',  document.getElementById('qsa-monitor').checked ? '1' : '0');
    fd.append('schedule', document.getElementById('qsa-schedule').value);
    // Only send when the user typed something; an untouched field means
    // "leave the saved webhook alone". Clearing is done by typing a space.
    const _wh = document.getElementById('qsa-webhook').value.trim();
    if (_wh !== '' || document.getElementById('qsa-webhook').dataset.cleared === '1') {
        fd.append('webhook_url', _wh);
    }
    fetch('/api/firewall', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showFwToast(d.success ? 'Token saved.' : (d.error || 'Could not save the token.'), d.success ? 'success' : 'danger');
            el.value = '';
            qsaLoadSettings();
        });
});

qsaLoadSettings();

</script>
