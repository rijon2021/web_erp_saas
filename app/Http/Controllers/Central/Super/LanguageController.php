<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Services\Localization\CentralTranslationManager;
use App\Support\CentralLanguageCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admin > Languages: install catalog languages, switch them on/off,
 * choose the default and RTL, and edit every super-admin / landing / billing /
 * PDF string per language (stored in central_translations).
 */
class LanguageController extends Controller
{
    public function __construct(protected CentralTranslationManager $manager)
    {
    }

    public function index(): View
    {
        $ready = CentralTranslationManager::ready();
        $installed = CentralLanguage::orderBy('sort_order')->get();
        $installedLocales = $installed->pluck('locale')->all();

        $available = collect(CentralLanguageCatalog::ALL)
            ->reject(fn ($entry, $locale) => in_array($locale, $installedLocales, true))
            ->map(fn ($entry, $locale) => $entry + [
                'locale'   => $locale,
                'has_pack' => is_dir(lang_path($locale)) || is_dir(resource_path("lang-packs/{$locale}")),
            ]);

        return view('central.super.languages.index', [
            'ready'     => $ready,
            'installed' => $installed,
            'available' => $available,
            'progress'  => $ready ? $this->manager->progress() : [],
            'totalKeys' => $this->manager->totalKeys(),
        ]);
    }

    public function install(string $locale): RedirectResponse
    {
        abort_unless(CentralLanguageCatalog::has($locale), 404);
        if (! CentralTranslationManager::ready()) {
            return back()->with('error', __('super.languages.migrate_first'));
        }

        $language = $this->manager->install($locale);

        return redirect()->route('super.languages.index')
            ->with('success', __('super.languages.installed_msg', ['name' => $language->name]));
    }

    public function uninstall(CentralLanguage $language): RedirectResponse
    {
        if ($language->locale === 'en' || $language->is_default) {
            return back()->with('error', __('super.languages.cannot_uninstall'));
        }

        $name = $language->name;
        $this->manager->uninstall($language);

        return redirect()->route('super.languages.index')
            ->with('success', __('super.languages.uninstalled_msg', ['name' => $name]));
    }

    public function update(Request $request, CentralLanguage $language): RedirectResponse
    {
        $validated = $request->validate([
            'native_name' => ['nullable', 'string', 'max:100'],
            'is_active'   => ['nullable', 'boolean'],
            'is_rtl'      => ['nullable', 'boolean'],
        ]);

        $data = [
            'is_rtl'    => $request->boolean('is_rtl'),
            // The default language always stays on so there is a fallback locale.
            'is_active' => $language->is_default || $request->boolean('is_active'),
        ];
        if (array_key_exists('native_name', $validated) && filled($validated['native_name'])) {
            $data['native_name'] = $validated['native_name'];
        }
        $language->update($data);
        CentralLanguage::forgetRtlMap();

        return back()->with('success', __('super.languages.updated_msg', ['name' => $language->name]));
    }

    public function makeDefault(CentralLanguage $language): RedirectResponse
    {
        CentralLanguage::where('id', '!=', $language->id)->where('is_default', true)->update(['is_default' => false]);
        $language->update(['is_default' => true, 'is_active' => true]);

        return back()->with('success', __('super.languages.default_msg', ['name' => $language->name]));
    }

    public function sync(CentralLanguage $language): RedirectResponse
    {
        if (! CentralTranslationManager::ready()) {
            return back()->with('error', __('super.languages.migrate_first'));
        }
        $stats = $this->manager->sync($language->locale);

        return back()->with('success', __('super.languages.synced_msg', [
            'name' => $language->name, 'added' => $stats['added'], 'updated' => $stats['updated'],
        ]));
    }

    // ── Translation editor ──────────────────────────────────────────

    public function translations(Request $request, CentralLanguage $language): View|RedirectResponse
    {
        if (! CentralTranslationManager::ready()) {
            return redirect()->route('super.languages.index')->with('error', __('super.languages.migrate_first'));
        }

        $group = in_array($request->query('group'), CentralTranslationManager::GROUPS, true) ? $request->query('group') : null;
        $filter = in_array($request->query('filter'), ['untranslated', 'customized'], true) ? $request->query('filter') : 'all';
        $search = trim((string) $request->query('q', ''));

        $englishSource = $this->manager->englishSource();
        $englishDb = $language->locale === 'en' ? null : $this->manager->values('en');
        $values = $this->manager->values($language->locale);

        $rows = [];
        $groupCounts = [];
        $untranslatedTotal = 0;
        foreach ($englishSource as $g => $keys) {
            $groupCounts[$g] = count($keys);
            foreach ($keys as $key => $english) {
                $current = $values[$g][$key] ?? null;
                if ($current === null) {
                    $untranslatedTotal++;
                }
                if ($group && $g !== $group) {
                    continue;
                }
                if ($filter === 'untranslated' && $current !== null) {
                    continue;
                }
                if ($filter === 'customized' && ! ($current['customized'] ?? false)) {
                    continue;
                }

                $source = $englishDb[$g][$key]['value'] ?? $english;
                if ($search !== '' && ! str_contains(mb_strtolower($g . '.' . $key . ' ' . $source . ' ' . ($current['value'] ?? '')), mb_strtolower($search))) {
                    continue;
                }

                $rows[] = [
                    'group'      => $g,
                    'key'        => $key,
                    'english'    => $source,
                    'value'      => $current['value'] ?? '',
                    'customized' => $current['customized'] ?? false,
                ];
            }
        }

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('central.super.languages.translations', [
            'language'          => $language,
            'rows'              => $paginator,
            'groups'            => CentralTranslationManager::GROUPS,
            'groupCounts'       => $groupCounts,
            'group'             => $group,
            'filter'            => $filter,
            'search'            => $search,
            'untranslatedTotal' => $untranslatedTotal,
            'totalKeys'         => array_sum($groupCounts),
        ]);
    }

    public function saveTranslations(Request $request, CentralLanguage $language): JsonResponse
    {
        $validated = $request->validate([
            'items'         => ['required', 'array', 'max:500'],
            'items.*.group' => ['required', 'string', Rule::in(CentralTranslationManager::GROUPS)],
            'items.*.key'   => ['required', 'string', 'max:191'],
            'items.*.value' => ['nullable', 'string', 'max:20000'],
        ]);

        $saved = $this->manager->saveValues($language->locale, $validated['items']);

        return response()->json([
            'success' => true,
            'saved'   => $saved,
            'message' => trans_choice('super.languages.saved_msg', $saved, ['count' => $saved]),
        ]);
    }

    public function resetTranslation(Request $request, CentralLanguage $language): JsonResponse
    {
        $validated = $request->validate([
            'group' => ['required', 'string', Rule::in(CentralTranslationManager::GROUPS)],
            'key'   => ['required', 'string', 'max:191'],
        ]);

        $value = $this->manager->reset($language->locale, $validated['group'], $validated['key']);

        return response()->json([
            'success' => true,
            'value'   => $value ?? '',
            'message' => __('super.languages.reset_msg'),
        ]);
    }

    public function export(CentralLanguage $language): Response
    {
        $json = json_encode($this->manager->export($language->locale), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response($json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="translations-' . $language->locale . '.json"',
        ]);
    }

    public function import(Request $request, CentralLanguage $language): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data)) {
            return back()->with('error', __('super.languages.import_invalid'));
        }

        $saved = $this->manager->import($language->locale, $data);

        return back()->with('success', __('super.languages.imported_msg', ['count' => $saved, 'name' => $language->name]));
    }
}
