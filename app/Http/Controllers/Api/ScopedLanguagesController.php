<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScopedLanguageInstaller;
use App\Support\LanguageCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared "Languages" + "Translations" admin API for a database-backed surface
 * (storefront, client portal). Subclasses provide the installer, the
 * permission check and where the surface keeps its default language.
 */
abstract class ScopedLanguagesController extends Controller
{
    /** @return class-string<ScopedLanguageInstaller> */
    abstract protected function installerClass(): string;

    abstract protected function authorizeScope(Request $request): void;

    abstract protected function defaultLocale(): string;

    /** Persist a new default language for the surface. */
    abstract protected function applyDefault(string $locale): void;

    /** Extra fields merged into the index payload (e.g. a "visit" URL). */
    protected function extraIndex(): array
    {
        return [];
    }

    /** Human label used in error messages ("storefront", "portal"). */
    protected function label(): string
    {
        return 'language';
    }

    protected function installer(): ScopedLanguageInstaller
    {
        $class = $this->installerClass();

        return new $class;
    }

    // Table / pack access goes through these hooks so a surface with its own
    // legacy tables (the back-office `languages` / `translations`) can reuse
    // every endpoint below.

    protected function languagesTableName(): string
    {
        return ($this->installerClass())::languagesTable();
    }

    protected function translationsTableName(): string
    {
        return ($this->installerClass())::translationsTable();
    }

    protected function languages()
    {
        return DB::table($this->languagesTableName());
    }

    protected function translationsQuery()
    {
        return DB::table($this->translationsTableName());
    }

    protected function forget(?string $locale = null): void
    {
        ($this->installerClass())::forget($locale);
    }

    protected function packPathFor(string $locale): ?string
    {
        return ($this->installerClass())::packPath($locale);
    }

    /** Catalog languages that ship a pack for this surface. */
    protected function availablePacksList(): array
    {
        return ($this->installerClass())::availablePacks();
    }

    protected function installLocale(string $locale): object
    {
        return $this->installer()->install($locale);
    }

    protected function uninstallLocale(string $locale): void
    {
        $this->installer()->uninstall($locale);
    }

    protected function packValueFor(string $locale, string $key): ?string
    {
        return ($this->installerClass())::packValue($locale, $key);
    }

    /** Extra per-language fields for the index cards. */
    protected function extraLanguageFields(object $language): array
    {
        return [];
    }

    // ------------------------------------------------------------- languages

    public function index(Request $request)
    {
        $this->authorizeScope($request);

        $default = $this->defaultLocale();
        // English keys = the reference set every language is measured against.
        $total = $this->translationsQuery()->where('locale', 'en')->count();

        $stats = $this->translationsQuery()
            ->select('locale', DB::raw('COUNT(*) as total'), DB::raw('SUM(is_customized) as customized'))
            ->groupBy('locale')->get()->keyBy('locale');

        $table = $this->translationsTableName();
        $translatedCounts = DB::table($table.' as t')
            ->join($table.' as e', function ($j) {
                $j->on('e.key', '=', 't.key')->where('e.locale', '=', 'en');
            })
            ->where('t.value', '!=', '')
            ->select('t.locale', DB::raw('COUNT(*) as c'))
            ->groupBy('t.locale')->pluck('c', 'locale');

        $rows = $this->languages()->orderBy('id')->get()->map(function ($l) use ($default, $total, $stats, $translatedCounts) {
            $entry = LanguageCatalog::find($l->locale);
            $translated = (int) ($translatedCounts[$l->locale] ?? 0);

            return array_merge([
                'id' => $l->id,
                'name' => $l->name,
                'locale' => $l->locale,
                'flag' => $l->flag,
                'is_active' => (bool) $l->is_active,
                'is_default' => $l->locale === $default,
                'is_protected' => $l->locale === 'en' || $l->locale === $default,
                'rtl' => (bool) ($entry['rtl'] ?? false),
                'keys' => (int) ($stats[$l->locale]->total ?? 0),
                'customized' => (int) ($stats[$l->locale]->customized ?? 0),
                'translated' => $translated,
                'progress' => $total ? min(100, (int) round($translated * 100 / $total)) : 0,
            ], $this->extraLanguageFields($l));
        });

        return response()->json(array_merge([
            'languages' => $rows,
            'default_locale' => $default,
            'total_keys' => $total,
        ], $this->extraIndex()));
    }

    public function available(Request $request)
    {
        $this->authorizeScope($request);

        $installed = $this->languages()->pluck('locale')->map(fn ($l) => strtolower($l))->flip();

        $rows = collect($this->availablePacksList())
            ->reject(fn ($e) => isset($installed[strtolower($e['locale'])]))
            ->map(fn ($e) => [
                'locale' => $e['locale'],
                'name' => LanguageCatalog::nativeName($e['locale']),
                'english_name' => $e['name'],
                'flag' => $e['flag'],
                'rtl' => $e['rtl'],
            ])->values();

        return response()->json($rows);
    }

    public function install(Request $request, string $locale)
    {
        $this->authorizeScope($request);

        if (! LanguageCatalog::find($locale) || ! $this->packPathFor($locale)) {
            return response()->json(['success' => false, 'message' => 'No '.$this->label().' pack is available for this language.'], 404);
        }

        @set_time_limit(120);
        $row = $this->installLocale($locale);

        return response()->json([
            'success' => true,
            'language' => $row,
            'keys' => $this->translationsQuery()->where('locale', $locale)->count(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeScope($request);

        $data = $request->validate(['name' => 'required|string|max:100']);
        abort_unless($this->languages()->where('id', $id)->exists(), 404);

        $this->languages()->where('id', $id)->update(['name' => $data['name'], 'updated_at' => now()]);
        $this->forget();

        return response()->json(['success' => true]);
    }

    public function toggleActive(Request $request, int $id)
    {
        $this->authorizeScope($request);

        $lang = $this->languages()->where('id', $id)->first();
        abort_unless($lang, 404);

        if ($lang->is_active && $lang->locale === $this->defaultLocale()) {
            return response()->json(['success' => false, 'message' => 'The default '.$this->label().' language cannot be deactivated.'], 422);
        }

        $this->languages()->where('id', $id)->update(['is_active' => ! $lang->is_active, 'updated_at' => now()]);
        $this->forget();

        return response()->json(['success' => true, 'is_active' => ! $lang->is_active]);
    }

    public function setDefault(Request $request, int $id)
    {
        $this->authorizeScope($request);

        $lang = $this->languages()->where('id', $id)->first();
        abort_unless($lang, 404);

        // A default language must be visible in the switcher.
        $this->languages()->where('id', $id)->update(['is_active' => true, 'updated_at' => now()]);
        $this->applyDefault($lang->locale);
        $this->forget();

        return response()->json(['success' => true, 'default_locale' => $lang->locale]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeScope($request);

        $lang = $this->languages()->where('id', $id)->first();
        abort_unless($lang, 404);

        if ($lang->locale === 'en' || $lang->locale === $this->defaultLocale()) {
            return response()->json(['success' => false, 'message' => 'English and the default '.$this->label().' language cannot be deleted.'], 422);
        }

        $this->uninstallLocale($lang->locale);

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------- translations

    /**
     * Paged key list for one language, joined with the English reference.
     * filter: all | missing (empty or absent) | customized | custom (keys that
     * exist only in this language, added by the admin).
     */
    public function translations(Request $request, string $locale)
    {
        $this->authorizeScope($request);

        $lang = $this->languages()->where('locale', $locale)->first();
        abort_unless($lang, 404);

        $search = trim((string) $request->get('search', ''));
        $filter = (string) $request->get('filter', 'all');
        $perPage = max(10, min(200, (int) $request->get('per_page', 50)));
        $page = max(1, (int) $request->get('page', 1));

        $english = $this->translationsQuery()->where('locale', 'en')->pluck('value', 'key');
        $rows = $this->translationsQuery()->where('locale', $locale)
            ->get(['key', 'value', 'is_customized', 'updated_at'])->keyBy('key');

        $items = [];
        foreach ($english as $key => $en) {
            $r = $rows[$key] ?? null;
            $items[] = [
                'key' => $key,
                'english' => $en,
                'value' => $r->value ?? '',
                'is_customized' => (bool) ($r->is_customized ?? false),
                'is_custom_key' => false,
                'updated_at' => $r->updated_at ?? null,
            ];
        }
        foreach ($rows as $key => $r) {
            if (! isset($english[$key])) {
                $items[] = [
                    'key' => $key,
                    'english' => null,
                    'value' => $r->value,
                    'is_customized' => (bool) $r->is_customized,
                    'is_custom_key' => true,
                    'updated_at' => $r->updated_at,
                ];
            }
        }

        $counts = [
            'all' => count($items),
            'missing' => count(array_filter($items, fn ($i) => trim((string) $i['value']) === '')),
            'customized' => count(array_filter($items, fn ($i) => $i['is_customized'])),
            'custom' => count(array_filter($items, fn ($i) => $i['is_custom_key'])),
        ];

        $items = array_values(array_filter($items, function ($i) use ($filter, $search) {
            if ($filter === 'missing' && trim((string) $i['value']) !== '') {
                return false;
            }
            if ($filter === 'customized' && ! $i['is_customized']) {
                return false;
            }
            if ($filter === 'custom' && ! $i['is_custom_key']) {
                return false;
            }
            if ($search !== '') {
                $hay = mb_strtolower($i['key'].' '.($i['english'] ?? '').' '.$i['value']);

                return str_contains($hay, mb_strtolower($search));
            }

            return true;
        }));

        // Newest edits first when browsing customized rows; otherwise key order.
        if ($filter === 'customized') {
            usort($items, fn ($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        }

        $total = count($items);

        return response()->json([
            'language' => [
                'name' => $lang->name,
                'locale' => $lang->locale,
                'flag' => $lang->flag,
                'rtl' => (bool) (LanguageCatalog::find($locale)['rtl'] ?? false),
            ],
            'data' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'counts' => $counts,
        ]);
    }

    /** Create or edit one key. Edited rows are flagged so upgrades keep them. */
    public function saveTranslation(Request $request, string $locale)
    {
        $this->authorizeScope($request);
        abort_unless($this->languages()->where('locale', $locale)->exists(), 404);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'value' => 'nullable|string|max:5000',
        ]);

        // Built-in keys may contain spaces/punctuation ("Asset validation due");
        // the strict format only applies to keys the admin is creating.
        $exists = $this->translationsQuery()
            ->whereIn('locale', ['en', $locale])->where('key', $data['key'])->exists();
        if (! $exists && ! preg_match('/^[A-Za-z0-9_.\-]+$/', $data['key'])) {
            return response()->json([
                'message' => 'Letters, numbers, dots, dashes and underscores only.',
                'errors' => ['key' => ['Letters, numbers, dots, dashes and underscores only.']],
            ], 422);
        }

        $now = now();
        $this->translationsQuery()->upsert(
            [[
                'locale' => $locale,
                'key' => $data['key'],
                'value' => (string) ($data['value'] ?? ''),
                'is_customized' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['locale', 'key'],
            ['value', 'is_customized', 'updated_at']
        );
        $this->forget($locale);

        return response()->json(['success' => true]);
    }

    /** Restore the shipped pack value for one key and clear its "edited" flag. */
    public function resetTranslation(Request $request, string $locale)
    {
        $this->authorizeScope($request);
        $key = (string) $request->input('key', '');
        abort_unless($key !== '', 422);

        $packValue = $this->packValueFor($locale, $key);
        if ($packValue === null) {
            return response()->json(['success' => false, 'message' => 'This key has no default value to restore.'], 422);
        }

        $this->translationsQuery()->where('locale', $locale)->where('key', $key)
            ->update(['value' => $packValue, 'is_customized' => 0, 'updated_at' => now()]);
        $this->forget($locale);

        return response()->json(['success' => true, 'value' => $packValue]);
    }

    /** Only keys an admin added (absent from English) can be deleted. */
    public function deleteTranslation(Request $request, string $locale)
    {
        $this->authorizeScope($request);
        $key = (string) $request->input('key', '');

        if ($this->translationsQuery()->where('locale', 'en')->where('key', $key)->exists()) {
            return response()->json(['success' => false, 'message' => 'Built-in keys cannot be deleted. Edit or reset them instead.'], 422);
        }

        $this->translationsQuery()->where('locale', $locale)->where('key', $key)->delete();
        $this->forget($locale);

        return response()->json(['success' => true]);
    }
}
