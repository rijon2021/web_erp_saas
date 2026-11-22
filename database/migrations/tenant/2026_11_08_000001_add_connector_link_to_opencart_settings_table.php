<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrations → OpenCart → "Connector website": the URL OpenCart's
 * Extensions → Installer page links the connector's name to (install.json
 * "link"). The tenant enters their own website; blank falls back to this
 * platform's URL, so the vendor's site never shows in the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opencart_settings') || Schema::hasColumn('opencart_settings', 'connector_link')) {
            return;
        }

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->string('connector_link', 191)->nullable()->after('code_source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('opencart_settings') || ! Schema::hasColumn('opencart_settings', 'connector_link')) {
            return;
        }

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->dropColumn('connector_link');
        });
    }
};
