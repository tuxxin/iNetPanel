<?php
// FILE: TiCore/Version.php
// iNetPanel — Version Manager
//
// Versioning scheme: v0.XXX
// Each update increments the version by 1/1000 (0.001)
// Version is stored as a class constant (committed to source control).

class Version
{
    const APP_VERSION = '0.107';

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
