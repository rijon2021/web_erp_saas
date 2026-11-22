<?php

namespace App\Console\Commands;

use App\Models\Central\CentralLanguage;
use App\Services\Localization\CentralTranslationManager;
use Illuminate\Console\Command;

/**
 * Copies the shipped super-admin / landing / billing / PDF strings into
 * central_translations for every installed language. Safe to re-run: rows the
 * super admin edited are never overwritten. Runs from CentralLanguagesSeeder,
 * so setup and every platform update call it.
 */
class LanguagesSync extends Command
{
    protected $signature = 'languages:sync {locale? : Only this installed locale}';

    protected $description = 'Sync installed super-admin languages with the shipped translation files';

    public function handle(CentralTranslationManager $manager): int
    {
        if (! CentralTranslationManager::ready()) {
            $this->error('central_translations is missing — run the central migrations first.');

            return self::FAILURE;
        }

        $locales = $this->argument('locale')
            ? [$this->argument('locale')]
            : CentralLanguage::orderBy('sort_order')->pluck('locale')->all();

        foreach ($locales as $locale) {
            if (! CentralLanguage::where('locale', $locale)->exists()) {
                $this->warn("{$locale}: not installed, skipped.");
                continue;
            }
            $stats = $manager->sync($locale);
            $this->line("{$locale}: +{$stats['added']} added, {$stats['updated']} updated, {$stats['removed']} removed");
        }

        return self::SUCCESS;
    }
}
