<?php
// FILE: TiCore/Version.php
// iNetPanel — Version Manager
//
// Versioning scheme: v0.XXX
// Each update increments the version by 1/1000 (0.001)
// Version is stored as a class constant (committed to source control).

class Version
{
    const APP_VERSION = '1.10';

    /**
     * Return current version string.
     */
    public static function get(): string
    {
        return self::APP_VERSION;
    }

    /**
     * Return version with 'v' prefix for display.
     */
    public static function display(): string
    {
        return 'v' . self::get();
    }

    /**
     * Build standard GitHub API request headers, including auth token if configured.
     */
    public static function githubHeaders(): array
    {
        $headers = [
            'User-Agent: iNetPanel/' . self::APP_VERSION,
            'Accept: application/vnd.github+json',
        ];
        if (class_exists('DB')) {
            $token = DB::setting('github_token', '');
            if ($token !== '') {
                $headers[] = 'Authorization: token ' . $token;
            }
        }
        return $headers;
    }

    /**
     * Check if a newer version is available.
     * Fetches the latest release tag from GitHub releases.
     * Returns ['available' => bool, 'latest' => string].
     * Results are cached in a transient file for 6 hours to avoid hammering GitHub.
     */
    public static function checkUpdate(): array
    {
        $cache = sys_get_temp_dir() . '/inetp_update_check.json';
        if (file_exists($cache) && (time() - filemtime($cache)) < 21600) {
            $data = json_decode(file_get_contents($cache), true);
            if (is_array($data)) return $data;
        }

        $ch = curl_init('https://api.github.com/repos/tuxxin/iNetPanel/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_HTTPHEADER     => self::githubHeaders(),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return ['available' => false, 'latest' => self::APP_VERSION];
        }

        $json   = json_decode($body, true);
        $latest = ltrim($json['tag_name'] ?? '', 'v');
        $result = [
            'available' => version_compare($latest, self::APP_VERSION, '>'),
            'latest'    => $latest ?: self::APP_VERSION,
        ];

        file_put_contents($cache, json_encode($result));
        return $result;
    }

    /**
     * Increment version by 0.001 and rewrite the constant in this file.
     * Called by the version bump script on each release commit.
     */
    public static function bump(): string
    {
        $current = self::get();
        $parts   = explode('.', $current);
        $minor   = str_pad((string)((int)($parts[1] ?? 107) + 1), 3, '0', STR_PAD_LEFT);
        $new     = ($parts[0] ?? '0') . '.' . $minor;

        $file = __FILE__;
        $src  = file_get_contents($file);
        $src  = preg_replace(
            "/const APP_VERSION = '[^']+';/",
            "const APP_VERSION = '{$new}';",
            $src
        );
        file_put_contents($file, $src);

        return $new;
    }
}
