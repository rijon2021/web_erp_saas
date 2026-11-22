<?php

use App\Support\HtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront CMS page content is rendered raw ({!! !!}). New saves are cleaned
 * by StorePage's saving hook; this cleans the rows that already exist, once.
 * Idempotent (cleaning clean HTML returns it unchanged). Not reversible:
 * removed script/handlers are not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_pages') || ! Schema::hasColumn('store_pages', 'content')) {
            return;
        }

        $hasTranslations = Schema::hasColumn('store_pages', 'content_translations');
        $columns = $hasTranslations ? ['id', 'content', 'content_translations'] : ['id', 'content'];

        DB::table('store_pages')->select($columns)->orderBy('id')->chunkById(100, function ($rows) use ($hasTranslations) {
            foreach ($rows as $row) {
                $changes = [];

                if (is_string($row->content) && $row->content !== '') {
                    $clean = HtmlSanitizer::clean($row->content);
                    if ($clean !== $row->content) {
                        $changes['content'] = $clean;
                    }
                }

                if ($hasTranslations && is_string($row->content_translations) && $row->content_translations !== '') {
                    $map = json_decode($row->content_translations, true);
                    if (is_array($map)) {
                        $cleanMap = HtmlSanitizer::cleanMap($map);
                        if ($cleanMap !== $map) {
                            $changes['content_translations'] = json_encode($cleanMap, JSON_UNESCAPED_UNICODE);
                        }
                    }
                }

                if ($changes) {
                    DB::table('store_pages')->where('id', $row->id)->update($changes);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible by design.
    }
};
