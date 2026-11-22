<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admin Settings > Security: CAPTCHA and failed-attempt lockout for the
 * /super/login form (JSON, whitelisted by App\Support\Security\SecurityOptions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('general_settings', 'security_options')) {
                $table->text('security_options')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            if (Schema::connection('central')->hasColumn('general_settings', 'security_options')) {
                $table->dropColumn('security_options');
            }
        });
    }
};
