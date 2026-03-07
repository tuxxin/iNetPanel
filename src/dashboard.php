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

// Disk
$diskTotal = disk_total_space('/');
$diskFree  = disk_free_space('/');
$diskUsed  = $diskTotal - $diskFree;
$diskPct   = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100) : 0;
$diskUsedG = round($diskUsed / 1024 / 1024 / 1024, 1);

// Domain count
$domainCount = 0;
try {
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
        <div class="card card-stat p-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted mb-1">Active Accounts</h6>
                    <h3 class="fw-bold mb-0"><?= $domainCount ?></h3>
                </div>
                <div class="icon-shape bg-primary-subtle text-primary rounded-circle p-3">
                    <i class="fas fa-users fa-2x"></i>
                </div>
            </div>
        </div>
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
    <div class="card-header bg-white py-3">
        <h6 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Resource History (last 60 polls)</h6>
    </div>
    <div class="card-body">
        <canvas id="resChart" height="80"></canvas>
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
    fetch('/api/accounts?action=list')
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
                    <span class="small fw-semibold">${a.domain_name}</span>
                    <span class="d-flex align-items-center gap-1">${phpPill}<span class="badge ${badge} rounded-pill">${a.status}</span></span></li>`;
            });
        }).catch(() => {
            document.getElementById('dash-accounts').innerHTML = '<li class="list-group-item text-muted small ps-3">Account data unavailable</li>';
        });

    // ── Resource chart (polling every 5s, stores last 60 points) ────────
    const labels = [], cpuData = [], memData = [];
    const ctx = document.getElementById('resChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'CPU Load (1m)', data: cpuData, borderColor: '#0050d5', backgroundColor: 'rgba(0,80,213,.07)', tension: 0.4, pointRadius: 0 },
                { label: 'RAM %',         data: memData, borderColor: '#7a00d5', backgroundColor: 'rgba(122,0,213,.07)', tension: 0.4, pointRadius: 0 },
            ]
        },
        options: {
            animation: false,
            scales: {
                x: { display: false },
                y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: v => v + (v <= 10 ? '' : '%') } }
            },
            plugins: { legend: { position: 'top' } }
        }
    });

    function pollStats() {
        fetch('/api/stats')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                labels.push(new Date().toLocaleTimeString());
                cpuData.push(data.cpu);
                memData.push(data.mem);
                if (labels.length > 60) { labels.shift(); cpuData.shift(); memData.shift(); }
                chart.update('none');
            })
            .catch(() => {});
    }
    pollStats();
    setInterval(pollStats, 5000);
});
</script>
