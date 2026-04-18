<?php
// FILE: src/dashboard.php
// iNetPanel — Dashboard (real system stats)


// ── System stats ─────────────────────────────────────────────────────────────
$load     = sys_getloadavg();
$cpuLoad  = round($load[0], 1);
$load[1]  = round($load[1], 1);
$load[2]  = round($load[2], 1);

// Memory from /proc/meminfo
$memTotal = $memFree = $memBuffers = $memCached = 0;
foreach (file('/proc/meminfo') as $line) {
    if (str_starts_with($line, 'MemTotal:'))     $memTotal   = (int)explode(':', $line)[1];
    elseif (str_starts_with($line, 'MemFree:'))  $memFree    = (int)explode(':', $line)[1];
    elseif (str_starts_with($line, 'Buffers:'))  $memBuffers = (int)explode(':', $line)[1];
    elseif (str_starts_with($line, 'Cached:'))   $memCached  = (int)explode(':', $line)[1];
}
$memUsed    = $memTotal - $memFree - $memBuffers - $memCached;
$memPct     = $memTotal > 0 ? round(($memUsed / $memTotal) * 100) : 0;
$memUsedGB  = round($memUsed / 1024 / 1024, 2);
$memTotalGB = round($memTotal / 1024 / 1024, 1);

// Disk — use df to get accurate used/total (PHP's disk_free_space excludes
// ext4 reserved blocks, inflating "used" by ~5% of the partition)
$diskTotal = $diskUsed = $diskFree = 0;
$dfLine = @shell_exec("df -B1 --output=size,used,avail / 2>/dev/null | tail -1");
if ($dfLine && preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $dfLine, $m)) {
    $diskTotal = (float)$m[1];
    $diskUsed  = (float)$m[2];
    $diskFree  = (float)$m[3];
} else {
    $diskTotal = disk_total_space('/');
    $diskFree  = disk_free_space('/');
    $diskUsed  = $diskTotal - $diskFree;
}
$diskPct   = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100) : 0;
$diskUsedG = round($diskUsed / 1024 / 1024 / 1024, 1);

// Account & domain counts
$accountCount = 0;
$domainCount = 0;
try {
    $row = DB::fetchOne('SELECT COUNT(*) as cnt FROM hosting_users');
    $accountCount = $row ? (int)$row['cnt'] : 0;
    $row = DB::fetchOne('SELECT COUNT(*) as cnt FROM domains WHERE status = ?', ['active']);
    $domainCount = $row ? (int)$row['cnt'] : 0;
} catch (\Throwable $e) {}

// CPU colour
$cpuClass = $cpuLoad > 2 ? 'danger' : ($cpuLoad > 1 ? 'warning' : 'success');
?>

<h4 class="mb-4"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h4>

<div class="row g-4 mb-4">

    <!-- Domains -->
    <div class="col-md-3">
        <a href="/admin/accounts" class="text-decoration-none">
        <div class="card card-stat p-3 h-100" style="cursor:pointer">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">Accounts &amp; Domains</h6>
                    <h3 class="fw-bold mb-0"><?= $accountCount ?> <small class="text-muted fs-6">/ <?= $domainCount ?></small></h3>
                </div>
                <div class="icon-shape bg-primary-subtle text-primary rounded-circle p-3">
                    <i class="fas fa-users fa-2x"></i>
                </div>
            </div>
        </div>
        </a>
    </div>

    <!-- Disk -->
    <div class="col-md-3">
        <div class="card card-stat p-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">Disk Used</h6>
                    <h3 class="fw-bold mb-0"><?= $diskPct ?>%</h3>
                    <small class="text-muted"><?= $diskUsedG ?> GB</small>
                </div>
                <div class="icon-shape bg-warning-subtle text-warning rounded-circle p-3">
                    <i class="fas fa-hdd fa-2x"></i>
                </div>
            </div>
            <div class="progress mt-2" style="height:4px">
                <div class="progress-bar bg-warning" style="width:<?= $diskPct ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Memory -->
    <div class="col-md-3">
        <div class="card card-stat p-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">Memory</h6>
                    <h3 class="fw-bold mb-0"><?= $memPct ?>%</h3>
                    <small class="text-muted"><?= $memUsedGB ?> / <?= $memTotalGB ?> GB</small>
                </div>
                <div class="icon-shape bg-success-subtle text-success rounded-circle p-3">
                    <i class="fas fa-memory fa-2x"></i>
                </div>
            </div>
            <div class="progress mt-2" style="height:4px">
                <div class="progress-bar bg-success" style="width:<?= $memPct ?>%"></div>
            </div>
        </div>
    </div>

    <!-- CPU Load -->
    <div class="col-md-3">
        <div class="card card-stat p-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">CPU Load (1m)</h6>
                    <h3 class="fw-bold mb-0"><?= $cpuLoad ?></h3>
                    <small class="text-muted"><?= $load[1] ?> / <?= $load[2] ?> (5m/15m)</small>
                </div>
                <div class="icon-shape bg-<?= $cpuClass ?>-subtle text-<?= $cpuClass ?> rounded-circle p-3">
                    <i class="fas fa-microchip fa-2x"></i>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Quick links row -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-server me-2 text-primary"></i>Service Status</h6>
                <a href="/admin/services" class="btn btn-sm btn-outline-primary">Manage</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush" id="dash-services">
                    <li class="list-group-item text-muted small py-2 ps-3">Loading…</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-users me-2 text-primary"></i>Recent Accounts</h6>
                <a href="/admin/accounts" class="btn btn-sm btn-outline-primary">All Accounts</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush" id="dash-accounts">
                    <li class="list-group-item text-muted small py-2 ps-3">Loading…</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Resource history chart -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-chart-area me-2 text-primary"></i>Resource History</h6>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" id="range-btns">
                <button class="btn btn-outline-primary active" data-range="hour">1 Hour</button>
                <button class="btn btn-outline-primary" data-range="day">24 Hours</button>
                <button class="btn btn-outline-primary" data-range="week">7 Days</button>
            </div>
            <span class="badge bg-light text-dark border" id="chart-cpu-now" style="font-size:.75rem;"><i class="fas fa-microchip me-1 text-primary"></i>CPU: —</span>
            <span class="badge bg-light text-dark border" id="chart-mem-now" style="font-size:.75rem;"><i class="fas fa-memory me-1 text-success"></i>RAM: —</span>
            <span class="badge bg-light text-dark border" id="chart-net-now" style="font-size:.75rem;"><i class="fas fa-network-wired me-1 text-info"></i>Net: —</span>
        </div>
    </div>
    <div class="card-body">
        <canvas id="resChart" height="90"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Service quick-list ───────────────────────────────────────────────
    fetch('/api/services?action=list')
        .then(r => r.json())
        .then(data => {
            const ul = document.getElementById('dash-services');
            ul.innerHTML = '';
            if (!data.success) { ul.innerHTML = '<li class="list-group-item text-danger small ps-3">Failed to load</li>'; return; }
            data.data.slice(0, 6).forEach(s => {
                const dot = s.status === 'active' ? 'bg-success' : (s.status === 'missing' ? 'bg-danger' : 'bg-secondary');
                const txt = s.status === 'active' ? 'Running' : (s.status === 'missing' ? 'Not installed' : 'Stopped');
                ul.innerHTML += `<li class="list-group-item d-flex justify-content-between align-items-center py-2 ps-3">
                    <span class="small">${s.label}</span>
                    <span class="badge ${dot} rounded-pill">${txt}</span></li>`;
            });
        }).catch(() => {
            document.getElementById('dash-services').innerHTML = '<li class="list-group-item text-muted small ps-3">Service data unavailable</li>';
        });

    // ── Recent accounts ──────────────────────────────────────────────────
    // limit=6 + skip_disk=1 — dashboard only needs domain, status, php_version
    fetch('/api/accounts?action=list&limit=6&skip_disk=1')
        .then(r => r.json())
        .then(data => {
            const ul = document.getElementById('dash-accounts');
            ul.innerHTML = '';
            if (!data.success || !data.data.length) {
                ul.innerHTML = '<li class="list-group-item text-muted small ps-3">No accounts yet</li>'; return;
            }
            data.data.slice(0, 6).forEach(a => {
                const badge = a.status === 'active' ? 'bg-success' : 'bg-warning text-dark';
                const phpPill = a.php_version ? `<span class="badge bg-secondary-subtle text-secondary rounded-pill me-1">PHP ${a.php_version}</span>` : '';
                ul.innerHTML += `<li class="list-group-item d-flex justify-content-between align-items-center py-2 ps-3">
                    <a href="//${a.domain_name}" target="_blank" class="small fw-semibold text-decoration-none">${a.domain_name} <i class="fas fa-external-link-alt ms-1" style="font-size:.65em;opacity:.5"></i></a>
                    <span class="d-flex align-items-center gap-1">${phpPill}<span class="badge ${badge} rounded-pill">${a.status}</span></span></li>`;
            });
        }).catch(() => {
            document.getElementById('dash-accounts').innerHTML = '<li class="list-group-item text-muted small ps-3">Account data unavailable</li>';
        });

    // ── Resource chart — history from DB + live polling ────────────────
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    let currentRange = 'hour';
    const labels = [], cpuData = [], memData = [], netData = [];

    const ctx = document.getElementById('resChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'CPU Load', data: cpuData, borderColor: '#0050d5', backgroundColor: 'rgba(0,80,213,.08)', borderWidth: 2, tension: 0.4, pointRadius: 0, fill: true },
                { label: 'RAM %', data: memData, borderColor: '#7a00d5', backgroundColor: 'rgba(122,0,213,.08)', borderWidth: 2, tension: 0.4, pointRadius: 0, fill: true },
                { label: 'Network KB/s', data: netData, borderColor: '#0891b2', backgroundColor: 'rgba(8,145,178,.06)', borderWidth: 1.5, tension: 0.4, pointRadius: 0, fill: true, yAxisID: 'y1' },
            ]
        },
        options: {
            animation: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    display: true,
                    ticks: { maxTicksLimit: 10, font: { size: 10 }, color: isDark ? '#94a3b8' : '#aaa' },
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    suggestedMax: 100,
                    position: 'left',
                    ticks: { callback: v => v + '%', font: { size: 10 }, color: isDark ? '#94a3b8' : '#aaa' },
                    grid: { color: isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.04)' }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    ticks: { callback: v => v + ' KB/s', font: { size: 10 }, color: isDark ? '#94a3b8' : '#aaa' },
                    grid: { drawOnChartArea: false }
                }
            },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', padding: 15, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            if (c.datasetIndex === 0) return ' CPU Load: ' + c.raw;
                            if (c.datasetIndex === 1) return ' RAM: ' + c.raw + '%';
                            return ' Network: ' + c.raw + ' KB/s';
                        }
                    }
                }
            }
        }
    });

    function fmtTs(ts, range) {
        const d = new Date(ts * 1000);
        if (range === 'week') return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        if (range === 'day') return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function loadHistory(range) {
        currentRange = range;
        fetch('/api/stats?action=history&range=' + range)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                labels.length = 0; cpuData.length = 0; memData.length = 0; netData.length = 0;
                data.points.forEach(p => {
                    labels.push(fmtTs(p.ts, range));
                    cpuData.push(p.cpu);
                    memData.push(p.mem);
                    netData.push(p.net);
                });
                chart.update('none');
                if (!data.points.length) {
                    labels.push('No data');
                    cpuData.push(0); memData.push(0); netData.push(0);
                    chart.update('none');
                }
            })
            .catch(() => {});
    }

    // Range button handlers
    document.querySelectorAll('#range-btns button').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#range-btns button').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            loadHistory(this.dataset.range);
        });
    });

    // Initial load + live badge polling
    loadHistory('hour');

    function pollLive() {
        fetch('/api/stats')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                document.getElementById('chart-cpu-now').innerHTML = `<i class="fas fa-microchip me-1 text-primary"></i>CPU: ${data.cpu}`;
                document.getElementById('chart-mem-now').innerHTML = `<i class="fas fa-memory me-1 text-success"></i>RAM: ${data.mem}%`;
            })
            .catch(() => {});
    }
    pollLive();
    setInterval(pollLive, 15000);

    // Refresh chart data every 60s to pick up new history points
    setInterval(() => loadHistory(currentRange), 60000);
});
</script>
