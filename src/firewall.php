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
</script>
