<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > Login Page: layout/colour/visibility options as JSON
 * (whitelisted by App\Support\LoginPageAppearance) and the hero
 * background image filename (stored under upload_path('settings')).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'login_page_options')) {
                $table->text('login_page_options')->nullable();
            }
            if (! Schema::hasColumn('settings', 'login_bg_image')) {
                $table->string('login_bg_image')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['login_page_options', 'login_bg_image'] as $col) {
                if (Schema::hasColumn('settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
