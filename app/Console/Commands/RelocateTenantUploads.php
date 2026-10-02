<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Move uploads that older releases wrote to the shared public/images tree
 * into the per-tenant folder (upload_path()) and rewrite the stored paths.
 *
 * Covers what the 1.4 -> 1.5 patch changed:
 *   - users.avatar                 (filename; was public/images/avatar/)
 *   - store_settings.*_path        (relative path; was images/store/...)
 *   - store_settings.theme_options (nested JSON; images/store/theme/...)
 *
 * Files are COPIED, never deleted, because several tenants may still share
 * one filename in the old folder. Safe to run repeatedly: anything already
 * relocated is skipped. The auto-updater calls it during finalization.
 */
class RelocateTenantUploads extends Command
{
    protected $signature = 'uploads:relocate-tenant {--all : Run for every tenant (central context)}';

    protected $description = 'Copy legacy shared uploads (avatars, store images) into the per-tenant folder and fix DB paths';

    private const STORE_PATH_COLUMNS = ['logo_path', 'favicon_path', 'hero_image_path', 'gcash_qr_path'];

    public function handle(): int
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            $this->relocateCurrentTenant();

            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            $this->error('Run inside a tenant (php artisan tenants:run uploads:relocate-tenant) or pass --all.');

            return self::FAILURE;
        }

        tenancy()->runForMultiple(null, function ($tenant) {
            $this->info("[{$tenant->id}]");
            $this->relocateCurrentTenant();
        });

        return self::SUCCESS;
    }

    /** Entry point for callers already inside a tenant (the auto-updater). */
    public function relocateCurrentTenant(): void
    {
        $this->relocateAvatars();
        $this->relocateStoreSettings();
    }

    // ------------------------------------------------------------------ avatars

    private function relocateAvatars(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $legacyDir = public_path('images/avatar');
        $tenantDir = upload_public_path('avatar');
        File::ensureDirectoryExists($tenantDir);

        // Default placeholder every tenant needs.
        foreach ([$legacyDir.'/no_avatar.png', public_path('images/no_avatar.png')] as $default) {
            if (is_file($default) && ! is_file($tenantDir.'/no_avatar.png')) {
                File::copy($default, $tenantDir.'/no_avatar.png');
                break;
            }
        }

        $copied = 0;
        DB::table('users')->whereNotNull('avatar')->where('avatar', '!=', '')
            ->orderBy('id')->chunk(200, function ($users) use ($legacyDir, $tenantDir, &$copied) {
                foreach ($users as $user) {
                    $name = basename((string) $user->avatar);
                    if ($name === '' || $name !== $user->avatar) {
                        continue; // not a bare filename, nothing to relocate
                    }
                    $dst = $tenantDir.'/'.$name;
                    $src = $legacyDir.'/'.$name;
                    if (! is_file($dst) && is_file($src)) {
                        File::copy($src, $dst);
                        $copied++;
                    }
                }
            });

        $this->line("  avatars: {$copied} copied into ".upload_path('avatar'));
    }

    // ------------------------------------------------------------ store settings

    private function relocateStoreSettings(): void
    {
        if (! Schema::hasTable('store_settings')) {
            return;
        }

        $row = DB::table('store_settings')->orderBy('id')->first();
        if (! $row) {
            return;
        }

        $updates = [];
        foreach (self::STORE_PATH_COLUMNS as $col) {
            if (! Schema::hasColumn('store_settings', $col)) {
                continue;
            }
            $old = (string) ($row->$col ?? '');
            $new = $this->relocateStorePath($old);
            if ($new !== null && $new !== $old) {
                $updates[$col] = $new;
            }
        }

        if (Schema::hasColumn('store_settings', 'theme_options') && ! empty($row->theme_options)) {
            $decoded = json_decode((string) $row->theme_options, true);
            if (is_array($decoded)) {
                $changed = false;
                $decoded = $this->walkThemeOptions($decoded, $changed);
                if ($changed) {
                    $updates['theme_options'] = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        if ($updates) {
            DB::table('store_settings')->where('id', $row->id)->update($updates);
        }

        $this->line('  store settings: '.count($updates).' column(s) rewritten to '.upload_path('store'));
    }

    /**
     * "images/store/x.png" -> copy to the tenant store folder and return the
     * new relative path. Returns null when the value is not a legacy store
     * path or the source file is missing (the DB is then left untouched).
     */
    private function relocateStorePath(string $value): ?string
    {
        $value = ltrim($value, '/');
        if (! str_starts_with($value, 'images/store/') || str_contains($value, '..')) {
            return null;
        }

        $relative = substr($value, strlen('images/store/')); // e.g. "theme/abc.jpg"
        $src = public_path($value);
        if (! is_file($src)) {
            return null;
        }

        $newRelative = upload_path('store').'/'.$relative;
        $dst = public_path($newRelative);
        if (! is_file($dst)) {
            File::ensureDirectoryExists(dirname($dst));
            File::copy($src, $dst);
        }

        return $newRelative;
    }

    private function walkThemeOptions(array $node, bool &$changed): array
    {
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = $this->walkThemeOptions($v, $changed);
            } elseif (is_string($v) && str_starts_with(ltrim($v, '/'), 'images/store/')) {
                $new = $this->relocateStorePath($v);
                if ($new !== null && $new !== $v) {
                    $node[$k] = $new;
                    $changed = true;
                }
            }
        }

        return $node;
    }
}
