<?php

namespace App\Support;

/**
 * Cache-busted URLs for the super-admin static assets.
 *
 * The version is the file's modification time, so a URL only changes when the
 * file does and the browser can keep every stylesheet and script for a year
 * (public/.htaccess sets the Expires headers). The layout used to append
 * time(), which produced a brand-new URL on every page view and forced a full
 * re-download of ~800 KB of CSS/JS each time.
 */
class SuperAsset
{
    /** @var array<string, string> */
    private static array $versions = [];

    /** asset() URL with a ?v= stamp derived from the file's mtime. */
    public static function url(string $path): string
    {
        return asset($path) . '?v=' . self::version($path);
    }

    public static function version(string $path): string
    {
        if (! isset(self::$versions[$path])) {
            $file = public_path($path);
            $mtime = is_file($file) ? @filemtime($file) : false;
            self::$versions[$path] = $mtime ? (string) $mtime : '1';
        }

        return self::$versions[$path];
    }
}
