<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings > Features: master switches for the Angola (AGT) and
 * DR Congo (DGI) e-invoicing modules. Each module keeps its own
 * configuration in agt_settings / dgi_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'agt_einvoice_enabled')) {
                $table->boolean('agt_einvoice_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'dgi_einvoice_enabled')) {
                $table->boolean('dgi_einvoice_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['agt_einvoice_enabled', 'dgi_einvoice_enabled'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
