<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin → Settings → Features, and the first optional feature on it:
 * multi-currency pricing.
 *
 *  - platform_features         one row per optional platform feature (on/off + its settings)
 *  - platform_currencies       currencies plans are sold in (besides the main one)
 *  - plan_prices               a plan's price in one of those currencies (fixed or auto)
 *  - tenant_billing_payments.base_*  the payment's value in the main currency at
 *                              the day's rate, so revenue totals add up across currencies
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('platform_features')) {
            $schema->create('platform_features', function (Blueprint $table) {
                $table->id();
                $table->string('key', 60)->unique();
                $table->boolean('is_enabled')->default(false);
                $table->json('settings')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('platform_currencies')) {
            $schema->create('platform_currencies', function (Blueprint $table) {
                $table->id();
                $table->string('code', 3)->unique();
                $table->string('symbol', 12)->nullable();       // null = built-in symbol
                // How automatic (converted) prices are rounded: none | whole | 10 | 50 | 100 | 500 | 1000
                $table->string('rounding', 10)->default('whole');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('plan_prices')) {
            $schema->create('plan_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('plan_id')->index();
                $table->string('currency', 3);
                $table->string('mode', 10)->default('auto');      // fixed | auto
                $table->decimal('monthly_price', 14, 2)->nullable();
                $table->decimal('yearly_price', 14, 2)->nullable();
                $table->timestamps();
                $table->unique(['plan_id', 'currency']);
            });
        }

        if ($schema->hasTable('tenant_billing_payments')) {
            $schema->table('tenant_billing_payments', function (Blueprint $table) use ($schema) {
                if (! $schema->hasColumn('tenant_billing_payments', 'base_amount')) {
                    $table->decimal('base_amount', 14, 2)->nullable();
                }
                if (! $schema->hasColumn('tenant_billing_payments', 'base_currency')) {
                    $table->string('base_currency', 3)->nullable();
                }
                if (! $schema->hasColumn('tenant_billing_payments', 'base_rate')) {
                    $table->decimal('base_rate', 20, 8)->nullable();
                }
            });
        }

        if (class_exists(\App\Support\PlatformFeatures::class)) {
            \App\Support\PlatformFeatures::forgetCache();
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        foreach (['base_amount', 'base_currency', 'base_rate'] as $column) {
            if ($schema->hasColumn('tenant_billing_payments', $column)) {
                $schema->table('tenant_billing_payments', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        $schema->dropIfExists('plan_prices');
        $schema->dropIfExists('platform_currencies');
        $schema->dropIfExists('platform_features');

        if (class_exists(\App\Support\PlatformFeatures::class)) {
            \App\Support\PlatformFeatures::forgetCache();
        }
    }
};
