<?php

namespace Database\Seeders;

use App\Services\PortalLanguageInstaller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds client-portal translations for INSTALLED portal languages only.
 *
 * Runs from the migration that creates portal_translations (en, fr, ar, es)
 * and after every upgrade (PostUpdateTasks), so new portal keys reach the
 * installed languages. Optional packs are installed on demand from Client
 * Portal → Portal Languages; admin-edited rows are always preserved.
 */
class PortalTranslationSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $skipped = (new PortalLanguageInstaller)->seedInstalled();

        if ($skipped > 0) {
            $this->command?->info("Preserved {$skipped} customized portal translation(s).");
        }
    }
}
