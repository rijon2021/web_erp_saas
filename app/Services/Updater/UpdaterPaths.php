<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\File;

/**
 * Central directory layout for the upload-based platform updater.
 *
 * Everything lives under storage/app/updater which is never web-accessible
 * and is excluded from backups and from the file-replacement scope.
 *
 * Paths are anchored on base_path('storage'), NOT storage_path(): the
 * updater runs tenant work inside $tenant->run(), and the tenancy
 * filesystem bootstrapper repoints storage_path() at storage/tenant<id>
 * while a tenant is initialized. State, backups and logs must stay in the
 * one central location whatever context the code runs in.
 */
class UpdaterPaths
{
    public static function storage(string $path = ''): string
    {
        $base = base_path('storage');

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    public static function root(): string
    {
        return self::storage('app/updater');
    }

    public static function uploads(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'uploads';
    }

    public static function packageZip(): string
    {
        return self::uploads().DIRECTORY_SEPARATOR.'package.zip';
    }

    public static function packagePart(): string
    {
        return self::uploads().DIRECTORY_SEPARATOR.'package.zip.part';
    }

    public static function packageMeta(): string
    {
        return self::uploads().DIRECTORY_SEPARATOR.'package.meta.json';
    }

    public static function backups(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'backups';
    }

    public static function backupDir(string $id): string
    {
        return self::backups().DIRECTORY_SEPARATOR.$id;
    }

    public static function staging(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'staged';
    }

    public static function work(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'work';
    }

    public static function stateFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'state.json';
    }

    public static function historyFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'history.json';
    }

    public static function configFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'config.json';
    }

    public static function lockFile(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'lock.json';
    }

    /** flock() target that serializes step() execution across requests. */
    public static function stepMutex(): string
    {
        return self::root().DIRECTORY_SEPARATOR.'step.mutex';
    }

    public static function logFile(): string
    {
        return self::storage('logs/system-update.log');
    }

    /**
     * Relative paths (from base_path) that the updater must never replace,
     * delete or accept from an uploaded package.
     *
     * storage holds every tenant's storage folder (storage/tenant<id>) and
     * public/images every tenant's uploads (images/tenants/<id>) plus the
     * super-admin ones (images/super).
     */
    public static function protectedPaths(): array
    {
        return [
            '.env',
            'storage',
            'public/images',
        ];
    }

    /**
     * Whether a package-relative path falls under a protected path. Matching
     * is case-insensitive so "Storage/" in a ZIP is treated like "storage/"
     * on case-sensitive (Linux) hosts too; a ".env" file is protected at any
     * depth. Shared by the validator and the deployer so both always agree.
     */
    public static function isProtected(string $rel): bool
    {
        $rel = strtolower(trim(str_replace('\\', '/', $rel), '/'));
        if ($rel === '') {
            return false;
        }
        if ($rel === '.env' || substr($rel, -5) === '/.env') {
            return true;
        }
        foreach (self::protectedPaths() as $protected) {
            $protected = strtolower($protected);
            if ($rel === $protected || strpos($rel, $protected.'/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relative paths excluded from the pre-update application backup ZIP.
     *
     * The protected paths are excluded on purpose: the updater never
     * replaces them, and the restore skips them when extracting, so
     * archiving them adds nothing that can be rolled back. Leaving them out
     * keeps the backup proportional to the application code instead of to
     * the size of every tenant's uploads. .env is copied separately by
     * BackupManager::backupEnvAndMeta().
     */
    public static function backupExcludedPaths(): array
    {
        return array_values(array_unique(array_merge(self::protectedPaths(), [
            'node_modules',
            '.git',
        ])));
    }

    public static function ensureDirectories(): void
    {
        foreach ([self::root(), self::uploads(), self::backups(), self::staging(), self::work()] as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }
        // Belt and braces: deny direct web access should the directory ever
        // end up under a public docroot on a misconfigured host.
        $ht = self::root().DIRECTORY_SEPARATOR.'.htaccess';
        if (! File::exists($ht)) {
            @File::put($ht, "Require all denied\nDeny from all\n");
        }
        $index = self::root().DIRECTORY_SEPARATOR.'index.html';
        if (! File::exists($index)) {
            @File::put($index, '');
        }
    }
}
