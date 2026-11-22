<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Turns the archive a buyer downloads from CodeCanyon into the application
 * ZIP the updater expects.
 *
 * The CodeCanyon "main files" download is a wrapper (documentation, licence
 * and the real application ZIP side by side), so when the uploaded archive
 * does not itself contain the application, the inner .zip that does is
 * streamed out and used in its place. A plain application ZIP is returned
 * untouched.
 */
class PackageUnwrapper
{
    /** Wrapper levels followed (CodeCanyon zip → app zip, plus one spare level). */
    private const MAX_DEPTH = 2;

    /** Inner .zip candidates inspected per level, largest first. */
    private const MAX_CANDIDATES = 3;

    /** Exception code for "not enough disk space" — aborts instead of trying the next candidate. */
    private const ERR_DISK = 507;

    /**
     * Returns ['path' => application zip, 'inner' => entry names unwrapped
     * (empty when the upload already was the application)]. Throws a
     * RuntimeException with a user-facing message when unwrapping fails.
     */
    public function unwrap(string $zipPath): array
    {
        $current = $zipPath;
        $inner = [];

        try {
            for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
                $zip = new ZipArchive;
                if ($zip->open($current) !== true) {
                    throw new \RuntimeException('The uploaded file is not a valid ZIP archive.');
                }
                if ($this->containsApplication($zip)) {
                    $zip->close();

                    return ['path' => $current, 'inner' => $inner];
                }

                $found = null;     // candidate holding the application itself
                $wrapper = null;   // else: candidate that is itself a wrapper of zips
                $lastError = null;
                foreach ($this->innerZipCandidates($zip) as $i => $candidate) {
                    $target = UpdaterPaths::uploads().DIRECTORY_SEPARATOR.'package.inner'.$depth.'_'.$i.'.zip';
                    try {
                        $this->extractEntry($zip, $candidate, $target);
                    } catch (\RuntimeException $e) {
                        if ($e->getCode() === self::ERR_DISK) {
                            $zip->close();
                            throw $e;
                        }
                        $lastError = $e; // unreadable candidate — try the next one
                        continue;
                    }
                    $kind = $this->classify($target);
                    if ($kind === 'app') {
                        $found = ['name' => $candidate['name'], 'path' => $target];
                        break;
                    }
                    if ($kind === 'wrapper' && ! $wrapper) {
                        $wrapper = ['name' => $candidate['name'], 'path' => $target];
                        continue;
                    }
                    @File::delete($target);
                }
                $zip->close();

                $next = $found ?: $wrapper;
                if ($found && $wrapper) {
                    @File::delete($wrapper['path']);
                }
                if (! $next) {
                    if ($lastError) {
                        throw $lastError; // clearer than "version.txt missing" for a corrupt download
                    }
                    break;
                }
                if ($current !== $zipPath) {
                    @File::delete($current); // intermediate wrapper level
                }
                $current = $next['path'];
                $inner[] = $next['name'];
                if ($found) {
                    return ['path' => $current, 'inner' => $inner];
                }
            }
        } catch (\RuntimeException $e) {
            self::cleanup();
            throw $e;
        }

        // No application found at any level: hand the original upload to the
        // validator so its report describes what was actually uploaded.
        self::cleanup();

        return ['path' => $zipPath, 'inner' => []];
    }

    /** Remove any extracted inner archives left behind. */
    public static function cleanup(): void
    {
        foreach (File::glob(UpdaterPaths::uploads().DIRECTORY_SEPARATOR.'package.inner*.zip') ?: [] as $file) {
            @File::delete($file);
        }
    }

    /** Whether the archive holds the application at its root or under one top-level folder. */
    private function containsApplication(ZipArchive $zip): bool
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $entries[] = str_replace('\\', '/', $name);
            }
        }
        $prefix = (new PackageValidator)->detectRootPrefix($entries) ?? '';

        return $zip->locateName($prefix.'artisan') !== false
            && $zip->locateName($prefix.'version.txt') !== false;
    }

    /** 'app' (holds the application), 'wrapper' (holds further .zip files) or null. */
    private function classify(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $kind = $this->containsApplication($zip) ? 'app' : ($this->innerZipCandidates($zip) ? 'wrapper' : null);
        $zip->close();

        return $kind;
    }

    /** .zip file entries (macOS resource forks excluded), largest first. */
    private function innerZipCandidates(ZipArchive $zip): array
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || ! isset($stat['name'])) {
                continue;
            }
            $name = str_replace('\\', '/', $stat['name']);
            $base = basename($name);
            if (substr($name, -1) === '/' || strcasecmp(substr($name, -4), '.zip') !== 0
                || stripos($name, '__MACOSX/') !== false || strpos($base, '._') === 0) {
                continue;
            }
            $candidates[] = ['entry' => $stat['name'], 'name' => $name, 'size' => (int) ($stat['size'] ?? 0)];
        }
        usort($candidates, fn ($a, $b) => $b['size'] <=> $a['size']);

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /** Stream one entry to disk (never loaded into memory — it can be hundreds of MB). */
    private function extractEntry(ZipArchive $zip, array $candidate, string $target): void
    {
        $free = @disk_free_space(dirname($target));
        if ($free !== false && $free < $candidate['size'] + 50 * 1024 * 1024) {
            throw new \RuntimeException('Not enough free disk space to extract "'.$candidate['name'].'" from the uploaded archive.', self::ERR_DISK);
        }

        $in = $zip->getStream($candidate['entry']);
        $out = @fopen($target, 'wb');
        if (! $in || ! $out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
                @File::delete($target);
            }
            throw new \RuntimeException('Could not extract "'.$candidate['name'].'" from the uploaded archive.');
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false || ($candidate['size'] > 0 && $copied !== $candidate['size'])) {
            @File::delete($target);
            throw new \RuntimeException('Extracting "'.$candidate['name'].'" from the uploaded archive failed (corrupted download?). Re-download it from CodeCanyon and upload again.');
        }
    }
}
