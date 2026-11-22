<?php

namespace App\Services\Updater;

use App\Models\Permission;
use App\Models\Role;
use App\Models\sms_gateway;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Everything a platform upgrade runs once the new files are in place:
 * central migrations + seeders, then per tenant its migrations, permission
 * sync, seeders and data clean-ups.
 *
 * The single source of truth for upgrade finalization — the System Update
 * flow (UpdateManager) and the migration-only /update page
 * (UpdateController) both call it. New tenant permissions come from
 * PermissionsSeeder::rows(), so there is no list to keep in sync here.
 */
class PlatformUpgradeTasks
{
    /** Central database: migrations, languages, plan limits. Returns migrate output. */
    public function central(): string
    {
        Artisan::call('config:clear');
        Artisan::call('migrate', [
            '--database' => 'central',
            '--force' => true,
        ]);
        $output = trim(Artisan::output());

        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\Central\\CentralLanguagesSeeder',
            '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\Central\\PlanLimitsSyncSeeder',
            '--force' => true,
        ]);

        return $output;
    }

    /**
     * The tenant currently initialized (call inside $tenant->run()):
     * migrations, permissions, seeders, clean-ups. Returns migrate output.
     */
    public function tenant(): string
    {
        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
            '--force' => true,
        ]);
        $output = trim(Artisan::output());

        $this->syncPermissions();
        $this->syncSmsGateways();
        $this->seedTranslationsChunked();

        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\StoreSettingSeeder',
            '--force' => true,
        ]);
        // New client-portal keys reach every installed portal language (edited rows kept).
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\PortalTranslationSeeder',
            '--force' => true,
        ]);

        $this->cleanProductNames();

        // 1.5: avatars and store images moved from the shared public/images
        // tree into the per-tenant folder; copy this tenant's files across.
        // Best effort — a missing legacy file must not fail the upgrade.
        try {
            Artisan::call('uploads:relocate-tenant');
        } catch (\Throwable $e) {
            \Log::warning('Platform update: legacy upload relocation skipped.', ['error' => $e->getMessage()]);
        }

        $this->clearTenantCache();

        return $output;
    }

    public function syncPermissions(): void
    {
        $created = [];
        foreach (PermissionsSeeder::rows() as $row) {
            $permission = Permission::firstOrCreate(['name' => $row['name']]);
            if ($permission->wasRecentlyCreated) {
                $created[$row['name']] = $permission->id;
            }
        }
        // Store submenu permissions: existing roles inherit them from the
        // permission that used to guard each page (first run only).
        \App\Support\StorePermissionGrants::grant($created);

        $ownerRole = Role::find(1);
        if ($ownerRole) {
            // Restriction permissions remove access, so the owner role never
            // carries them (sync also strips any stray ones).
            $ownerRole->permissions()->sync(
                Permission::whereNotIn('name', Permission::RESTRICTIONS)->pluck('id')->toArray()
            );
        }
    }

    public function syncSmsGateways(): void
    {
        sms_gateway::firstOrCreate(['title' => 'infobip']);
        sms_gateway::firstOrCreate(['title' => 'termii']);
        sms_gateway::firstOrCreate(['title' => 'custom']);

        $nexmoGateway = sms_gateway::where('title', 'nexmo')->first();
        if ($nexmoGateway) {
            $nexmoGateway->delete();
        }
    }

    public function cleanProductNames(): void
    {
        \App\Models\Product::where('name', 'REGEXP', '<|>')
            ->chunkById(200, function ($products) {
                foreach ($products as $p) {
                    $old = $p->name;
                    $clean = str_replace(['<', '>'], ['‹', '›'], strip_tags($old));
                    if ($clean !== $old) {
                        $p->name = $clean;
                        $p->save();
                    }
                }
            });
    }

    /** Seed translations one language file at a time to keep memory low. */
    public function seedTranslationsChunked(): void
    {
        DB::disableQueryLog();

        $dir = database_path('seeders/translations');
        if (! is_dir($dir)) {
            return;
        }

        foreach (File::files($dir) as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            $translations = require $file;
            $isDefault = $locale === 'en' ? 1 : 0;
            $now = now();

            $batch = [];
            foreach ($translations as $key => $value) {
                $batch[] = [
                    'locale' => $locale,
                    'key' => $key,
                    'value' => $value,
                    'is_default' => $isDefault,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 500) {
                    DB::table('translations')->upsert($batch, ['locale', 'key'], ['value', 'is_default', 'updated_at']);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                DB::table('translations')->upsert($batch, ['locale', 'key'], ['value', 'is_default', 'updated_at']);
            }

            unset($translations, $batch);
        }
    }

    /** Clear only the current tenant's file cache and compiled views. */
    public function clearTenantCache(): void
    {
        $cachePath = storage_path('framework/cache/data');
        if (is_dir($cachePath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cachePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
            }
        }

        $viewsPath = storage_path('framework/views');
        if (is_dir($viewsPath)) {
            foreach (glob($viewsPath.'/*.php') as $view) {
                @unlink($view);
            }
        }
    }
}
