<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the landing on/off toggle with a three-way "landing page mode":
 *   builtin  — the CMS landing page (default)
 *   redirect — / redirects to landing_redirect_url with a 301 or 302
 *   disabled — / goes to the workspace sign-in or the signup page
 *
 * landing_page_enabled / landing_redirect_url are kept; existing rows are
 * migrated so the current behaviour is preserved (off + URL → redirect 302,
 * off + no URL → disabled, showing the login page). Each column is guarded
 * on its own so a partially applied run can be repeated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('general_settings')) {
            return;
        }

        $added = false;

        if (! $schema->hasColumn('general_settings', 'landing_mode')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->string('landing_mode', 20)->default('builtin');
            });
            $added = true;
        }

        if (! $schema->hasColumn('general_settings', 'landing_redirect_status')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('landing_redirect_status')->default(302);
            });
        }

        if (! $schema->hasColumn('general_settings', 'landing_disabled_target')) {
            $schema->table('general_settings', function (Blueprint $table) {
                $table->string('landing_disabled_target', 20)->default('login');
            });
        }

        if ($added && $schema->hasColumn('general_settings', 'landing_page_enabled')) {
            $hasUrl = $schema->hasColumn('general_settings', 'landing_redirect_url');

            foreach (DB::connection('central')->table('general_settings')->where('landing_page_enabled', false)->get() as $row) {
                $url = $hasUrl ? trim((string) ($row->landing_redirect_url ?? '')) : '';

                DB::connection('central')->table('general_settings')->where('id', $row->id)->update([
                    'landing_mode' => $url !== '' ? 'redirect' : 'disabled',
                ]);
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        foreach (['landing_mode', 'landing_redirect_status', 'landing_disabled_target'] as $column) {
            if ($schema->hasColumn('general_settings', $column)) {
                $schema->table('general_settings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
