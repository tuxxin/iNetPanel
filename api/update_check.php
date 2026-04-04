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
        'changelog'    => $data['body'] ?? '',
    ];
}

function fetchLatestBetaCommit(): array
{
    $ch = curl_init('https://api.github.com/repos/tuxxin/iNetPanel/commits/main');
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
    $sha  = $data['sha'] ?? '';
    if (!$sha) {
        return [];
    }

    $shortSha = substr($sha, 0, 7);
    $message  = $data['commit']['message'] ?? '';

    return [
        'tag'          => 'beta-' . $shortSha,
        'sha'          => $sha,
        'short_sha'    => $shortSha,
        'download_url' => 'https://api.github.com/repos/tuxxin/iNetPanel/zipball/main',
        'html_url'     => 'https://github.com/tuxxin/iNetPanel/commit/' . $sha,
        'changelog'    => $message,
    ];
}

$channel = DB::setting('update_channel', 'stable');
$isBeta  = ($channel === 'beta');

switch ($action) {

    case 'check':
        // Force-refresh the cache from GitHub
        $release = $isBeta ? fetchLatestBetaCommit() : fetchLatestRelease();
        if (empty($release)) {
            echo json_encode(['success' => false, 'error' => 'Failed to reach GitHub API.']);
            break;
        }
        DB::saveSetting('panel_latest_ver', $release['tag']);
        DB::saveSetting('panel_check_ts',   (string) time());
        DB::saveSetting('panel_download_url', $release['download_url'] ?? '');
        DB::saveSetting('panel_latest_changelog', $release['changelog'] ?? '');
        if ($isBeta) {
            DB::saveSetting('panel_latest_beta_sha', $release['sha'] ?? '');
        }

        $current = Version::get();
        // Beta: update available if remote SHA differs from what we last installed
        if ($isBeta) {
            $installedSha = DB::setting('panel_installed_beta_sha', '');
            $updateAvailable = ($release['short_sha'] ?? '') !== $installedSha;
        } else {
            $updateAvailable = version_compare($release['tag'], $current, '>');
        }

        echo json_encode([
            'success'          => true,
            'current'          => $current,
            'latest'           => $release['tag'],
            'update_available' => $updateAvailable,
            'download_url'     => $release['download_url'],
            'html_url'         => $release['html_url'],
            'changelog'        => $release['changelog'] ?? '',
            'channel'          => $channel,
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
            $release = $isBeta ? fetchLatestBetaCommit() : fetchLatestRelease();
            if (!empty($release)) {
                $latestVer   = $release['tag'];
                $downloadUrl = $release['download_url'];
                DB::saveSetting('panel_latest_ver', $latestVer);
                DB::saveSetting('panel_check_ts',   (string) time());
                DB::saveSetting('panel_download_url', $downloadUrl);
                DB::saveSetting('panel_latest_changelog', $release['changelog'] ?? '');
                if ($isBeta) {
                    DB::saveSetting('panel_latest_beta_sha', $release['sha'] ?? '');
                }
            }
        }

        if ($isBeta) {
            $installedSha    = DB::setting('panel_installed_beta_sha', '');
            $latestSha       = substr(DB::setting('panel_latest_beta_sha', ''), 0, 7);
            $updateAvailable = $latestSha && $latestSha !== $installedSha;
        } else {
            $updateAvailable = $latestVer && version_compare($latestVer, $current, '>');
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
            'update_available' => $updateAvailable,
            'download_url'     => $downloadUrl,
            'cached'           => (time() - $checkTs < CACHE_TTL),
            'checked_ago'      => $checkedAgo,
            'channel'          => $channel,
        ]);
        break;
}
