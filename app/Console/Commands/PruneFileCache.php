<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Delete expired entries of the file cache store.
 *
 * Laravel's file store removes an expired entry only when that same key is
 * read again. Keys that are never read again — a dashboard cached for
 * yesterday's date range, a user who left — stay on disk forever, for every
 * tenant. Each entry starts with its expiry as a 10-digit Unix time
 * (9999999999 = forever), which is all this reads.
 */
class PruneFileCache extends Command
{
    protected $signature = 'cache:prune-expired-files {--dry-run : Count only}';

    protected $description = 'Delete expired entries from the file cache store';

    public function handle(): int
    {
        $path = (string) config('cache.stores.file.path');
        if ($path === '' || ! is_dir($path)) {
            $this->line('No file cache directory.');

            return 0;
        }

        $now = time();
        $deleted = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname()); // only succeeds when empty

                continue;
            }

            $fh = @fopen($file->getPathname(), 'rb');
            if ($fh === false) {
                continue;
            }
            $head = (string) fread($fh, 10);
            fclose($fh);

            if (! ctype_digit($head) || (int) $head >= $now) {
                continue; // not a cache entry, or still valid
            }
            if ($this->option('dry-run') || @unlink($file->getPathname())) {
                $deleted++;
            }
        }

        $this->line(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$deleted.' expired cache file(s).');

        return 0;
    }
}
