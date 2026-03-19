<?php
// FILE: api/stats.php
// iNetPanel — Live system stats + historical data from stats_history table
// Actions: (none/live) = real-time poll, history = time-series from DB

$action = $_GET['action'] ?? 'live';

if ($action === 'history') {
    // Return stored stats for a given range
    $range = $_GET['range'] ?? 'hour';
    $rangeMap = ['hour' => 3600, 'day' => 86400, 'week' => 604800];
    $seconds = $rangeMap[$range] ?? 3600;
    $since = time() - $seconds;

    // For larger ranges, aggregate to reduce data points
    // hour = every point (~60 pts), day = every 15 min (~96 pts), week = every hour (~168 pts)
    if ($range === 'hour') {
        $rows = DB::fetchAll('SELECT ts, cpu, mem, net_bytes FROM stats_history WHERE ts >= ? ORDER BY ts', [$since]);
    } elseif ($range === 'day') {
        // Group by 15-min intervals
        $rows = DB::fetchAll('SELECT (ts / 900) * 900 AS ts, ROUND(AVG(cpu),2) AS cpu, ROUND(AVG(mem)) AS mem, MAX(net_bytes) AS net_bytes FROM stats_history WHERE ts >= ? GROUP BY ts / 900 ORDER BY ts', [$since]);
    } else {
        // Group by 1-hour intervals
        $rows = DB::fetchAll('SELECT (ts / 3600) * 3600 AS ts, ROUND(AVG(cpu),2) AS cpu, ROUND(AVG(mem)) AS mem, MAX(net_bytes) AS net_bytes FROM stats_history WHERE ts >= ? GROUP BY ts / 3600 ORDER BY ts', [$since]);
    }

    // Calculate net KB/s from consecutive net_bytes deltas
    $points = [];
    $prevNet = null;
    $prevTs = null;
    foreach ($rows as $r) {
        $netKBs = 0;
        if ($prevNet !== null && $r['ts'] > $prevTs) {
            $delta = max(0, $r['net_bytes'] - $prevNet);
            $elapsed = $r['ts'] - $prevTs;
            $netKBs = $elapsed > 0 ? round($delta / 1024 / $elapsed) : 0;
        }
        $prevNet = (int)$r['net_bytes'];
        $prevTs  = (int)$r['ts'];
        $points[] = [
            'ts'     => (int)$r['ts'],
            'cpu'    => (float)$r['cpu'],
            'mem'    => (int)$r['mem'],
            'net'    => $netKBs,
        ];
    }

    echo json_encode(['success' => true, 'range' => $range, 'points' => $points]);
    exit;
}

// Live stats (real-time poll from browser)
$load    = sys_getloadavg();
$cpuLoad = round($load[0], 2);

$memTotal = $memFree = $memBuffers = $memCached = 0;
foreach (file('/proc/meminfo') as $line) {
    if (str_starts_with($line, 'MemTotal:'))    $memTotal   = (int)explode(':', $line)[1];
    elseif (str_starts_with($line, 'MemFree:')) $memFree    = (int)explode(':', $line)[1];
    elseif (str_starts_with($line, 'Buffers:')) $memBuffers = (int)explode(':', $line)[1];
    elseif (str_starts_with($line, 'Cached:'))  $memCached  = (int)explode(':', $line)[1];
}
$memUsed = $memTotal - $memFree - $memBuffers - $memCached;
$memPct  = $memTotal > 0 ? round(($memUsed / $memTotal) * 100) : 0;

// Network: total bytes received + transmitted across all interfaces
$netBytes = 0;
if (is_readable('/proc/net/dev')) {
    foreach (file('/proc/net/dev') as $line) {
        if (!str_contains($line, ':')) continue;
        $parts = preg_split('/\s+/', trim(explode(':', $line)[1]));
        if (count($parts) >= 9) {
            $netBytes += (int)$parts[0] + (int)$parts[8]; // rx_bytes + tx_bytes
        }
    }
}

echo json_encode(['success' => true, 'cpu' => $cpuLoad, 'mem' => $memPct, 'net_bytes' => $netBytes]);
