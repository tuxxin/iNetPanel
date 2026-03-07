<?php
// FILE: api/stats.php
// iNetPanel — Live system stats (CPU load, memory %)

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

echo json_encode(['success' => true, 'cpu' => $cpuLoad, 'mem' => $memPct]);
