<?php
// FILE: api/update_check.php
// iNetPanel — GitHub Release Version Check
// Caches result 24h in SQLite to avoid rate-limiting

Auth::check();
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'status';

define('GH_API_URL', 'https://api.github.com/repos/tuxxin/inetpanel/releases/latest');
define('CACHE_TTL', 86400); // 24 hours

function fetchLatestRelease(): array
{
    $ch = curl_init(GH_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => Version::githubHeaders(),
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['tag_name'])) {
        return [];
    }

    // Find the zip asset URL
    $downloadUrl = '';
    foreach ($data['assets'] ?? [] as $asset) {
        if ($asset['name'] === 'inetpanel-latest.zip') {
            $downloadUrl = $asset['browser_download_url'];
            break;
        }
    }

    return [
        'tag'          => ltrim($data['tag_name'], 'v'),
        'download_url' => $downloadUrl ?: ($data['zipball_url'] ?? ''),
        'html_url'     => $data['html_url'] ?? '',
    ];
}

switch ($action) {

    case 'check':
        // Force-refresh the cache from GitHub
        $release = fetchLatestRelease();
        if (empty($release)) {
            echo json_encode(['success' => false, 'error' => 'Failed to reach GitHub API.']);
            break;
        }
        DB::saveSetting('panel_latest_ver', $release['tag']);
        DB::saveSetting('panel_check_ts',   (string) time());
        DB::saveSetting('panel_download_url', $release['download_url'] ?? '');

        $current = Version::get();
        echo json_encode([
            'success'          => true,
            'current'          => $current,
            'latest'           => $release['tag'],
            'update_available' => version_compare($release['tag'], $current, '>'),
            'download_url'     => $release['download_url'],
            'html_url'         => $release['html_url'],
        ]);
        break;

    case 'status':
    default:
        // Return cached info; refresh if stale
        $latestVer   = DB::setting('panel_latest_ver', '');
        $checkTs     = (int) DB::setting('panel_check_ts', '0');
        $downloadUrl = DB::setting('panel_download_url', '');
        $current     = Version::get();

        // Refresh stale cache inline (will add latency once per 24h)
        if (!$latestVer || time() - $checkTs > CACHE_TTL) {
            $release = fetchLatestRelease();
            if (!empty($release)) {
                $latestVer   = $release['tag'];
                $downloadUrl = $release['download_url'];
                DB::saveSetting('panel_latest_ver', $latestVer);
                DB::saveSetting('panel_check_ts',   (string) time());
                DB::saveSetting('panel_download_url', $downloadUrl);
            }
        }

        $diff = $checkTs ? time() - $checkTs : null;
        if (!$diff)              $checkedAgo = 'never';
        elseif ($diff < 60)     $checkedAgo = 'just now';
        elseif ($diff < 3600)   $checkedAgo = round($diff / 60) . ' min ago';
        elseif ($diff < 86400)  $checkedAgo = round($diff / 3600) . ' hr ago';
        else                    $checkedAgo = round($diff / 86400) . ' days ago';

        echo json_encode([
            'success'          => true,
            'current'          => $current,
            'latest'           => $latestVer,
            'update_available' => $latestVer && version_compare($latestVer, $current, '>'),
            'download_url'     => $downloadUrl,
            'cached'           => (time() - $checkTs < CACHE_TTL),
            'checked_ago'      => $checkedAgo,
        ]);
        break;
}
