<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TranslationSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $path = database_path('seeders/translations');

        // Sort case-insensitively so files of the same locale (e.g. "Ind"/"ind")
        // are adjacent and share one dedupe set.
        $files = File::files($path);
        usort($files, fn ($a, $b) => strcasecmp($a->getFilename(), $b->getFilename()));

        $now = now();

        // Truncate + bulk insert is much faster than upsert on remote databases.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('translations')->truncate();

        // Stream one locale file at a time and flush every 2000 rows, so memory
        // stays flat instead of holding every locale (~150k rows) at once.
        DB::transaction(function () use ($files, $now) {
            $buffer = [];
            $seen = [];
            $currentLocale = null;

            foreach ($files as $file) {
                $locale = pathinfo($file, PATHINFO_FILENAME);

                // Deduplicate by locale+key (PHP arrays are case-sensitive but
                // MySQL unique index is case-insensitive, causing collisions).
                if (strtolower($locale) !== $currentLocale) {
                    $currentLocale = strtolower($locale);
                    $seen = [];
                }

                $translations = require $file;

                foreach ($translations as $key => $value) {
                    $dup = strtolower($key);
                    if (isset($seen[$dup])) {
                        continue;
                    }
                    $seen[$dup] = true;

                    $buffer[] = [
                        'locale' => $locale,
                        'key' => $key,
                        'value' => $value,
                        'is_default' => $locale === 'en' ? 1 : 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($buffer) >= 2000) {
                        DB::table('translations')->insert($buffer);
                        $buffer = [];
                    }
                }

                unset($translations);
            }

            if ($buffer) {
                DB::table('translations')->insert($buffer);
            }
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
