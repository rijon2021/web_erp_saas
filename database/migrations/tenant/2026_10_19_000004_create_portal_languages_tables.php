<?php

use App\Support\LanguageCatalog;
use Database\Seeders\PortalTranslationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client portal languages + their translations, per tenant and independent
     * from the back-office languages, so each tenant offers its own portal
     * language set.
     *
     * Only the four default packs (en, fr, ar, es — the ones that ship as
     * resources/lang/<locale>/portal.php) are installed here, for new and
     * existing tenants alike. Every other catalog language is installed on
     * demand from Client Portal → Portal Languages. The portal's default
     * language stays in Portal Settings (PortalSettings::defaultLocale()).
     */
    public function up(): void
    {
        if (! Schema::hasTable('portal_languages')) {
            Schema::create('portal_languages', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name');
                $table->string('locale', 20)->unique();
                $table->string('flag')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps(6);
            });
        }

        if (! Schema::hasTable('portal_translations')) {
            Schema::create('portal_translations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('locale', 20);
                // Keys are case-sensitive.
                $table->string('key')->collation('utf8mb4_bin');
                $table->text('value');
                // Set when the admin edits a row; re-seeding never overwrites it.
                $table->boolean('is_customized')->default(false);
                $table->timestamps(6);

                $table->unique(['locale', 'key']);
                $table->index('locale');
            });
        }

        if (DB::table('portal_languages')->count() === 0) {
            $now = now();
            DB::table('portal_languages')->insert(array_map(fn ($row) => [
                'name' => LanguageCatalog::nativeName($row['locale']),
                'locale' => $row['locale'],
                'flag' => $row['flag'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], LanguageCatalog::defaultLanguageRows()));
        }

        (new PortalTranslationSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_translations');
        Schema::dropIfExists('portal_languages');
    }
};
