<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default primary colours chosen in Settings > General:
 *  - super_primary_color  → super-admin panel (Appearance tab)
 *  - tenant_primary_color → every tenant app (Tenant Settings tab)
 * NULL keeps the built-in colours (GeneralSetting::BUILTIN_*_COLOR). A colour a
 * user picked in a customizer (stored in their browser) still wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('general_settings')) {
            return;
        }

        foreach (['super_primary_color', 'tenant_primary_color'] as $column) {
            if (! $schema->hasColumn('general_settings', $column)) {
                $schema->table('general_settings', function (Blueprint $table) use ($column) {
                    $table->string($column, 7)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        foreach (['super_primary_color', 'tenant_primary_color'] as $column) {
            if ($schema->hasColumn('general_settings', $column)) {
                $schema->table('general_settings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
