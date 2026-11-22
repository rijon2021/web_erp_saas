<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login hero background colour picked in Appearance Settings. Null keeps the
 * built-in gradient from css/auth.css.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'login_bg_color')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->string('login_bg_color', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'login_bg_color')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('login_bg_color');
            });
        }
    }
};
