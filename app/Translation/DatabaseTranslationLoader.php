<?php

namespace App\Translation;

use App\Services\Localization\CentralTranslationManager;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wraps Laravel's file loader: for the groups managed under Super admin >
 * Languages, rows in central_translations are laid over the file values, so
 * every existing __('super.…') call picks up admin edits and installed
 * languages without view changes.
 *
 * Always reads the central connection (billing / pdf strings render inside
 * tenant requests too). The cache key carries central_languages.translations_version
 * because cache keys are tenant-prefixed: bumping the version invalidates
 * every tenant's copy without having to find them.
 */
class DatabaseTranslationLoader implements Loader
{
    /** @var array<string, int|null> locale => translations_version (null = not installed / not migrated) */
    protected array $versions = [];

    public function __construct(protected Loader $files)
    {
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);

        if (($namespace !== null && $namespace !== '*') || ! in_array($group, CentralTranslationManager::GROUPS, true)) {
            return $lines;
        }

        $overrides = $this->overrides($locale, $group);

        return $overrides ? array_replace_recursive($lines, $overrides) : $lines;
    }

    protected function overrides(string $locale, string $group): array
    {
        $version = $this->version($locale);
        if ($version === null) {
            return [];
        }

        try {
            return Cache::remember(
                "central_i18n.{$locale}.{$group}.v{$version}",
                now()->addDays(7),
                fn () => Arr::undot(
                    DB::connection('central')->table('central_translations')
                        ->where('locale', $locale)->where('group', $group)
                        ->pluck('value', 'key')->all()
                )
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function version(string $locale): ?int
    {
        if (! array_key_exists($locale, $this->versions)) {
            try {
                $version = DB::connection('central')->table('central_languages')
                    ->where('locale', $locale)->value('translations_version');
            } catch (\Throwable $e) {
                // Before the migration runs: files only.
                $version = null;
            }
            $this->versions[$locale] = $version === null ? null : (int) $version;
        }

        return $this->versions[$locale];
    }

    public function forgetVersions(): void
    {
        $this->versions = [];
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }

    /** Anything else (addPath, jsonPaths, …) goes to the file loader. */
    public function __call($method, $parameters)
    {
        return $this->files->{$method}(...$parameters);
    }
}
