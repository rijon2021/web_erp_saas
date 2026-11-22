<?php

namespace App\Services;

use App\Support\LanguageCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed languages for one translation group of one surface
 * (storefront `messages`, client portal `portal`). Each surface has its own
 * <scope>_languages / <scope>_translations tables, so languages are installed
 * per surface; subclasses only declare the names.
 *
 * - install()/uninstall()/importLocale(): catalog packs → DB. Admin-edited rows
 *   (is_customized) are never overwritten by an install or a re-seed.
 * - activeLocales()/installedLocales()/lines(): runtime lookups (cached) read
 *   by the translation loader, middleware and admin editors.
 */
abstract class ScopedLanguageInstaller
{
    /** e.g. 'store' — prefix of the two tables and the cache keys. */
    abstract public static function scope(): string;

    /** Laravel translation group served from the DB ('messages', 'portal'). */
    abstract public static function group(): string;

    /** Folder under database/seeders holding the generated packs. */
    abstract public static function packDir(): string;

    public static function languagesTable(): string
    {
        return static::scope().'_languages';
    }

    public static function translationsTable(): string
    {
        return static::scope().'_translations';
    }

    public static function packPath(string $locale): ?string
    {
        return LanguageCatalog::groupPackPath(static::group(), static::packDir(), $locale);
    }

    /** Catalog languages that ship a pack for this surface. */
    public static function availablePacks(): array
    {
        return LanguageCatalog::groupAvailable(static::group(), static::packDir());
    }

    protected static function localesCacheKey(): string
    {
        return static::scope().'_locales.v1';
    }

    protected static function installedCacheKey(): string
    {
        return static::scope().'_locales_installed.v1';
    }

    public static function linesCacheKey(string $locale): string
    {
        return static::scope().'_translations.v1.'.$locale;
    }

    /** Fallback language list while the tables do not exist yet. */
    protected static function fallbackLocales(): array
    {
        // The packs that ship as resources/lang/<locale>/<group>.php.
        return ['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية', 'es' => 'Español'];
    }

    // ---------------------------------------------------------------- install

    /** Install (or repair) a catalog language for this surface. */
    public function install(string $locale): object
    {
        $entry = LanguageCatalog::find($locale);
        $path = static::packPath($locale);
        if (! $entry || ! $path) {
            throw new \InvalidArgumentException("No ".static::scope()." pack is available for '{$locale}'.");
        }

        if (! DB::table(static::languagesTable())->where('locale', $locale)->exists()) {
            DB::table(static::languagesTable())->insert([
                'name' => LanguageCatalog::nativeName($locale),
                'locale' => $locale,
                'flag' => $entry['flag'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->importLocale($locale, $path);
        static::forget($locale);

        return DB::table(static::languagesTable())->where('locale', $locale)->first();
    }

    /** Remove a language and all of its translations for this surface. */
    public function uninstall(string $locale): void
    {
        DB::table(static::translationsTable())->where('locale', $locale)->delete();
        DB::table(static::languagesTable())->where('locale', $locale)->delete();
        static::forget($locale);
    }

    /** Upsert a pack under $locale; returns how many customized rows were kept. */
    public function importLocale(string $locale, string $path): int
    {
        $lines = require $path;
        if (! is_array($lines)) {
            return 0;
        }

        $customized = DB::table(static::translationsTable())
            ->where('locale', $locale)
            ->where('is_customized', 1)
            ->pluck('key')
            ->flip()
            ->all();

        $now = now();
        $rows = [];
        $skipped = 0;
        foreach ($lines as $key => $value) {
            if (! is_string($value)) {
                continue; // nested groups are not used by these surfaces
            }
            if (isset($customized[$key])) {
                $skipped++;

                continue;
            }
            $rows[] = [
                'locale' => $locale,
                'key' => (string) $key,
                'value' => $value,
                'is_customized' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(static::translationsTable())->upsert($chunk, ['locale', 'key'], ['value', 'updated_at']);
        }

        return $skipped;
    }

    /** Re-import every installed language (install migration + upgrades). */
    public function seedInstalled(): int
    {
        if (! Schema::hasTable(static::languagesTable()) || ! Schema::hasTable(static::translationsTable())) {
            return 0;
        }

        $skipped = 0;
        foreach (DB::table(static::languagesTable())->pluck('locale') as $locale) {
            $path = static::packPath($locale);
            if ($path) {
                $skipped += $this->importLocale($locale, $path);
            }
        }
        static::forget();

        return $skipped;
    }

    /** Value the shipped pack has for one key (for "reset to default"). */
    public static function packValue(string $locale, string $key): ?string
    {
        $path = static::packPath($locale);
        if (! $path) {
            return null;
        }
        $lines = require $path;

        return is_array($lines) && isset($lines[$key]) && is_string($lines[$key]) ? $lines[$key] : null;
    }

    // ---------------------------------------------------------------- runtime
    // No per-process memo anywhere below: queue workers render mail too and
    // must see installs / toggles / edits without a restart. Cache reads are
    // cheap, and Laravel's translator already memoizes per request.

    /** Active languages (shown in switchers), locale => display name. */
    public static function activeLocales(): array
    {
        $locales = [];
        try {
            $locales = Cache::remember(static::localesCacheKey(), 600, function () {
                if (! Schema::hasTable(static::languagesTable())) {
                    return [];
                }

                return DB::table(static::languagesTable())->where('is_active', 1)
                    ->orderBy('id')->pluck('name', 'locale')->all();
            });
        } catch (\Throwable $e) {
            $locales = [];
        }

        return $locales ?: static::fallbackLocales();
    }

    /**
     * EVERY installed language (active or hidden), locale => name. Admin
     * editors and settings use this — hiding a language from the switcher must
     * never discard content or settings tied to it.
     */
    public static function installedLocales(): array
    {
        $locales = [];
        try {
            $locales = Cache::remember(static::installedCacheKey(), 600, function () {
                if (! Schema::hasTable(static::languagesTable())) {
                    return [];
                }

                return DB::table(static::languagesTable())->orderBy('id')->pluck('name', 'locale')->all();
            });
        } catch (\Throwable $e) {
            $locales = [];
        }

        return $locales ?: static::fallbackLocales();
    }

    /**
     * Line map for one locale (empty if not installed). Blank values are left
     * out so the file / English fallback shows instead of an empty label.
     */
    public static function lines(string $locale): array
    {
        try {
            return Cache::rememberForever(static::linesCacheKey($locale), function () use ($locale) {
                if (! Schema::hasTable(static::translationsTable())) {
                    return [];
                }

                return DB::table(static::translationsTable())->where('locale', $locale)
                    ->where('value', '!=', '')
                    ->pluck('value', 'key')->all();
            });
        } catch (\Throwable $e) {
            // No DB yet (installer / update flow) — the file loader alone answers.
            return [];
        }
    }

    public static function forget(?string $locale = null): void
    {
        // Runs from install migrations too, where a database cache store may
        // not have its table yet — a cache hiccup must never fail the install.
        try {
            Cache::forget(static::localesCacheKey());
            Cache::forget(static::installedCacheKey());
            $locales = $locale ? [$locale] : array_keys(LanguageCatalog::all());
            foreach ($locales as $loc) {
                Cache::forget(static::linesCacheKey($loc));
            }
            static::afterForget($locale);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Hook for surface-specific caches derived from the language list. */
    protected static function afterForget(?string $locale): void
    {
    }
}
