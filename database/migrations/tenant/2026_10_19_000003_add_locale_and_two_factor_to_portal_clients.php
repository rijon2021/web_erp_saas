<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - preferred_locale: the portal language a client picked, so it follows
     *   them across devices (NULL = not chosen; SetPortalLocale then falls
     *   back to cookie / Portal Settings default).
     * - two_factor_*: optional two-step sign-in (App\Support\Security\TwoFactor):
     *   'app' (authenticator) or 'email' (a code mailed at sign-in). Tenants
     *   allow it from Client Portal → Portal Settings.
     */
    public function up(): void
    {
        if (! Schema::hasTable('portal_clients')) {
            return;
        }

        Schema::table('portal_clients', function (Blueprint $table) {
            if (! Schema::hasColumn('portal_clients', 'preferred_locale')) {
                $table->string('preferred_locale', 20)->nullable()->after('status');
            }
            if (! Schema::hasColumn('portal_clients', 'two_factor_method')) {
                $table->string('two_factor_method', 10)->nullable();
            }
            if (! Schema::hasColumn('portal_clients', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }
            if (! Schema::hasColumn('portal_clients', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }
            if (! Schema::hasColumn('portal_clients', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portal_clients')) {
            return;
        }

        Schema::table('portal_clients', function (Blueprint $table) {
            foreach (['preferred_locale', 'two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
                if (Schema::hasColumn('portal_clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
