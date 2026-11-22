<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which subscription billing cycles the platform offers.
 *
 * Plans already carry both a monthly `price` and a `yearly_price`, and every
 * customer-facing surface hard-coded "monthly or yearly". These two flags let
 * the super-admin offer monthly only, yearly only, or both. At least one is
 * always on — GeneralSetting::enabledBillingCycles() falls back to monthly if
 * the row somehow ends up with both disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('general_settings', 'billing_monthly_enabled')) {
                $table->boolean('billing_monthly_enabled')->default(true);
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'billing_yearly_enabled')) {
                $table->boolean('billing_yearly_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            foreach (['billing_monthly_enabled', 'billing_yearly_enabled'] as $column) {
                if (Schema::connection('central')->hasColumn('general_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
