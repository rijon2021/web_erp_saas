<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'portal_settings')) {
                // JSON blob of Client Portal options (global on/off, which
                // modules clients see, welcome message, default language,
                // invitation expiry, two-step sign-in). Null = defaults in
                // App\Support\PortalSettings.
                $table->longText('portal_settings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'portal_settings')) {
                $table->dropColumn('portal_settings');
            }
        });
    }
};
