<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin > Languages: editable UI strings for the super-admin panel,
 * landing page, billing screens and PDF invoices (groups super / central /
 * landing / billing / pdf). Rows are seeded from the shipped language files by
 * `php artisan languages:sync`; is_customized marks admin edits so updates
 * never overwrite them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('central_translations')) {
            Schema::connection('central')->create('central_translations', function (Blueprint $table) {
                $table->id();
                $table->string('locale', 10);
                $table->string('group', 32);
                $table->string('key', 191);
                $table->text('value');
                $table->boolean('is_customized')->default(false);
                $table->timestamps();

                $table->unique(['locale', 'group', 'key']);
                $table->index(['locale', 'group']);
            });
        }

        Schema::connection('central')->table('central_languages', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('central_languages', 'native_name')) {
                $table->string('native_name', 100)->nullable()->after('name');
            }
            // Bumped on every translation change; part of the loader's cache key,
            // so tenant-prefixed caches go stale on their own.
            if (! Schema::connection('central')->hasColumn('central_languages', 'translations_version')) {
                $table->unsignedInteger('translations_version')->default(0)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('central_translations');

        Schema::connection('central')->table('central_languages', function (Blueprint $table) {
            $table->dropColumn(['native_name', 'translations_version']);
        });
    }
};
