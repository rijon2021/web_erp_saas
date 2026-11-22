<?php

namespace Database\Seeders\Central;

use App\Models\Central\CentralLanguage;
use App\Services\Localization\CentralTranslationManager;
use App\Support\CentralLanguageCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Runs on setup and on every platform update. Installs the default languages
 * only on an empty table, so a language the super admin uninstalled, switched
 * off or made default stays that way across updates. Then syncs the shipped
 * translations into central_translations.
 */
class CentralLanguagesSeeder extends Seeder
{
    public function run(): void
    {
        $hasNativeName = Schema::connection('central')->hasColumn('central_languages', 'native_name');

        if (! CentralLanguage::exists()) {
            foreach (CentralLanguageCatalog::DEFAULT_INSTALLED as $i => $locale) {
                $entry = CentralLanguageCatalog::get($locale);
                CentralLanguage::create(array_filter([
                    'name'        => $entry['name'],
                    'native_name' => $hasNativeName ? $entry['native'] : null,
                    'locale'      => $locale,
                    'flag'        => $entry['flag'],
                    'is_active'   => true,
                    'is_default'  => $locale === 'en',
                    'is_rtl'      => $entry['rtl'],
                    'sort_order'  => $i + 1,
                ], fn ($v) => $v !== null));
            }
        } elseif ($hasNativeName) {
            // Rows created before native_name existed.
            foreach (CentralLanguage::whereNull('native_name')->get() as $language) {
                if ($entry = CentralLanguageCatalog::get($language->locale)) {
                    $language->update(['native_name' => $entry['native']]);
                }
            }
        }

        if (CentralTranslationManager::ready()) {
            app(CentralTranslationManager::class)->syncAll();
        }
    }
}
