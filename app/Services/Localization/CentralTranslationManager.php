<?php

namespace App\Services\Localization;

use App\Models\Central\CentralLanguage;
use App\Support\CentralLanguageCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns central_translations: installs / uninstalls catalog languages, keeps
 * rows in step with the shipped language files, and applies admin edits.
 *
 * "Source" values are what ships with the code for a locale:
 * resources/lang/{locale}/{group}.php, with resources/lang-packs/{locale}/{group}.php
 * filling any keys the lang file lacks.
 * A row whose value differs from its source is customized and is never
 * touched by sync.
 */
class CentralTranslationManager
{
    /** Translation groups editable from Super admin > Languages. */
    public const GROUPS = ['super', 'central', 'landing', 'billing', 'pdf'];

    /** @var array<string, array<string, string>> "locale|group" => flat key => value */
    protected array $sourceCache = [];

    public static function ready(): bool
    {
        try {
            return Schema::connection('central')->hasTable('central_translations')
                && Schema::connection('central')->hasColumn('central_languages', 'translations_version');
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Source files ────────────────────────────────────────────────

    /** Flat key => value shipped for a locale/group ([] when nothing ships). */
    public function sourceValues(string $locale, string $group): array
    {
        $cacheKey = "{$locale}|{$group}";
        if (isset($this->sourceCache[$cacheKey])) {
            return $this->sourceCache[$cacheKey];
        }

        // The hand-maintained lang file wins; the pack fills the keys it lacks
        // (a full pack for catalog languages, a gap-fill for the original ten).
        $values = [];
        foreach ([lang_path("{$locale}/{$group}.php"), resource_path("lang-packs/{$locale}/{$group}.php")] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $data = require $path;
            if (is_array($data)) {
                foreach (Arr::dot($data) as $key => $value) {
                    if (is_string($value) && $value !== '' && ! isset($values[$key])) {
                        $values[$key] = $value;
                    }
                }
            }
        }

        return $this->sourceCache[$cacheKey] = $values;
    }

    /** group => [key => English value as shipped]. The key set every locale is measured against. */
    public function englishSource(): array
    {
        $out = [];
        foreach (self::GROUPS as $group) {
            $out[$group] = $this->sourceValues('en', $group);
        }

        return $out;
    }

    public function totalKeys(): int
    {
        return array_sum(array_map('count', $this->englishSource()));
    }

    // ── Install / uninstall ─────────────────────────────────────────

    public function install(string $locale): CentralLanguage
    {
        $entry = CentralLanguageCatalog::get($locale);
        if (! $entry) {
            throw new \InvalidArgumentException("Unknown language: {$locale}");
        }

        $language = CentralLanguage::firstOrNew(['locale' => $locale]);
        if (! $language->exists) {
            $language->fill([
                'name'        => $entry['name'],
                'native_name' => $entry['native'],
                'flag'        => $entry['flag'],
                'is_rtl'      => $entry['rtl'],
                'is_active'   => true,
                'is_default'  => false,
                'sort_order'  => (int) CentralLanguage::max('sort_order') + 1,
            ])->save();
        }

        $this->sync($locale);
        CentralLanguage::forgetRtlMap();

        return $language;
    }

    public function uninstall(CentralLanguage $language): void
    {
        if ($language->locale === 'en' || $language->is_default) {
            throw new \RuntimeException('The default language and English cannot be uninstalled.');
        }

        DB::connection('central')->transaction(function () use ($language) {
            DB::connection('central')->table('central_translations')->where('locale', $language->locale)->delete();
            $language->delete();
        });
        CentralLanguage::forgetRtlMap();
    }

    // ── Sync with shipped files ─────────────────────────────────────

    /**
     * Bring a locale's rows in line with its source files: add new keys,
     * refresh untouched rows whose shipped text changed, drop untouched rows
     * whose key no longer ships. Customized rows are left alone.
     *
     * @return array{added:int, updated:int, removed:int}
     */
    public function sync(string $locale): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'removed' => 0];
        $db = DB::connection('central');
        $now = now();

        foreach (self::GROUPS as $group) {
            $source = $this->sourceValues($locale, $group);
            $rows = $db->table('central_translations')
                ->where('locale', $locale)->where('group', $group)
                ->get(['id', 'key', 'value', 'is_customized'])
                ->keyBy('key');

            $insert = [];
            foreach ($source as $key => $value) {
                $row = $rows->get($key);
                if (! $row) {
                    $insert[] = [
                        'locale' => $locale, 'group' => $group, 'key' => $key, 'value' => $value,
                        'is_customized' => false, 'created_at' => $now, 'updated_at' => $now,
                    ];
                } elseif (! $row->is_customized && $row->value !== $value) {
                    $db->table('central_translations')->where('id', $row->id)->update(['value' => $value, 'updated_at' => $now]);
                    $stats['updated']++;
                }
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                $db->table('central_translations')->insertOrIgnore($chunk);
            }
            $stats['added'] += count($insert);

            $stale = $rows->filter(fn ($row) => ! $row->is_customized && ! array_key_exists($row->key, $source))->pluck('id');
            foreach ($stale->chunk(500) as $ids) {
                $db->table('central_translations')->whereIn('id', $ids->all())->delete();
            }
            $stats['removed'] += $stale->count();
        }

        if (array_sum($stats) > 0) {
            $this->bump($locale);
        }

        return $stats;
    }

    /** @return array<string, array{added:int, updated:int, removed:int}> */
    public function syncAll(): array
    {
        $out = [];
        foreach (CentralLanguage::orderBy('sort_order')->pluck('locale') as $locale) {
            $out[$locale] = $this->sync($locale);
        }

        return $out;
    }

    // ── Admin edits ─────────────────────────────────────────────────

    /**
     * Save edited values. An empty value deletes the row so the string falls
     * back to English. Unknown group/key pairs are ignored.
     *
     * @param  array<int, array{group:string, key:string, value:?string}>  $items
     */
    public function saveValues(string $locale, array $items): int
    {
        $english = $this->englishSource();
        $db = DB::connection('central');
        $now = now();
        $saved = 0;

        foreach ($items as $item) {
            $group = (string) ($item['group'] ?? '');
            $key = (string) ($item['key'] ?? '');
            if (! isset($english[$group][$key])) {
                continue;
            }

            $value = (string) ($item['value'] ?? '');
            $match = ['locale' => $locale, 'group' => $group, 'key' => $key];

            if (trim($value) === '') {
                $db->table('central_translations')->where($match)->delete();
            } else {
                $default = $this->sourceValues($locale, $group)[$key] ?? null;
                $db->table('central_translations')->updateOrInsert($match, [
                    'value'         => $value,
                    'is_customized' => $value !== $default,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ]);
            }
            $saved++;
        }

        if ($saved) {
            $this->bump($locale);
        }

        return $saved;
    }

    /** Put one string back to its shipped text (or remove it when nothing ships). */
    public function reset(string $locale, string $group, string $key): ?string
    {
        $default = $this->sourceValues($locale, $group)[$key] ?? null;
        $match = ['locale' => $locale, 'group' => $group, 'key' => $key];
        $db = DB::connection('central');

        if ($default === null) {
            $db->table('central_translations')->where($match)->delete();
        } else {
            $db->table('central_translations')->updateOrInsert($match, [
                'value' => $default, 'is_customized' => false, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        $this->bump($locale);

        return $default;
    }

    /** group => [key => ['value' => string, 'customized' => bool]] for one locale. */
    public function values(string $locale): array
    {
        $out = [];
        DB::connection('central')->table('central_translations')
            ->where('locale', $locale)
            ->orderBy('id')
            ->get(['group', 'key', 'value', 'is_customized'])
            ->each(function ($row) use (&$out) {
                $out[$row->group][$row->key] = ['value' => $row->value, 'customized' => (bool) $row->is_customized];
            });

        return $out;
    }

    /** locale => ['translated' => int, 'total' => int, 'percent' => int, 'customized' => int] */
    public function progress(): array
    {
        $english = $this->englishSource();
        $total = $this->totalKeys();
        $out = [];

        DB::connection('central')->table('central_translations')
            ->where('value', '!=', '')
            ->orderBy('id')
            ->select(['locale', 'group', 'key', 'is_customized'])
            ->lazy(2000)
            ->each(function ($row) use (&$out, $english) {
                if (! isset($english[$row->group][$row->key])) {
                    return;
                }
                $out[$row->locale]['translated'] = ($out[$row->locale]['translated'] ?? 0) + 1;
                if ($row->is_customized) {
                    $out[$row->locale]['customized'] = ($out[$row->locale]['customized'] ?? 0) + 1;
                }
            });

        foreach ($out as $locale => $stats) {
            $out[$locale] = [
                'translated' => $stats['translated'] ?? 0,
                'customized' => $stats['customized'] ?? 0,
                'total'      => $total,
                'percent'    => $total ? (int) floor(100 * ($stats['translated'] ?? 0) / $total) : 0,
            ];
        }

        return $out;
    }

    // ── Import / export ─────────────────────────────────────────────

    /** Flat "group.key" => value for every row of a locale. */
    public function export(string $locale): array
    {
        $out = [];
        foreach ($this->values($locale) as $group => $keys) {
            foreach ($keys as $key => $row) {
                $out["{$group}.{$key}"] = $row['value'];
            }
        }
        ksort($out);

        return $out;
    }

    /** Accepts the export() shape. Returns how many strings were saved. */
    public function import(string $locale, array $data): int
    {
        $items = [];
        foreach ($data as $path => $value) {
            if (! is_string($path) || ! is_string($value) || ! str_contains($path, '.')) {
                continue;
            }
            [$group, $key] = explode('.', $path, 2);
            $items[] = ['group' => $group, 'key' => $key, 'value' => $value];
        }

        return $this->saveValues($locale, $items);
    }

    // ── Cache invalidation ──────────────────────────────────────────

    public function bump(string $locale): void
    {
        CentralLanguage::where('locale', $locale)->increment('translations_version');

        $loader = app('translation.loader');
        // The portal loader wraps this one (AppServiceProvider) and forwards the call.
        if ($loader instanceof \App\Translation\DatabaseTranslationLoader || $loader instanceof \App\Translation\PortalTranslationLoader) {
            $loader->forgetVersions();
        }
    }
}
