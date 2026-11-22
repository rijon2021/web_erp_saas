<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store Settings > "Cookie Consent Banner" switch. Default ON so storefronts
 * keep asking for consent until the tenant turns it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_settings') && ! Schema::hasColumn('store_settings', 'cookie_consent_enabled')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->boolean('cookie_consent_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_settings') && Schema::hasColumn('store_settings', 'cookie_consent_enabled')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('cookie_consent_enabled');
            });
        }
    }
};
