<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings > Security: login CAPTCHA, failed-attempt lockout, session
 * rules (JSON, whitelisted by App\Support\Security\SecurityOptions) and the
 * sign-in audit log shown on that tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'security_options')) {
                $table->text('security_options')->nullable();
            }
        });

        if (! Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                // success | failed | locked_out | blocked | captcha_failed
                $table->string('event', 30);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['event', 'created_at']);
                $table->index('email');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');

        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'security_options')) {
                $table->dropColumn('security_options');
            }
        });
    }
};
