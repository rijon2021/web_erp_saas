<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons: plan features (modules) and premium store themes a tenant can buy
 * on top of its plan.
 *
 *  - addons          the super admin's catalogue: which keys are for sale, at
 *                    what monthly / yearly / one-time price, to which plans
 *  - tenant_addons   what each tenant bought (or was granted) and until when
 *  - payment_links.tenant_addon_id
 *                    purchases and renewals are paid through a payment link,
 *                    so every gateway, bank transfer and webhook already works
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('addons')) {
            $schema->create('addons', function (Blueprint $table) {
                $table->id();
                // A plan feature key (hrm, crm, …) or "theme:<theme key>".
                $table->string('key', 80)->unique();
                $table->string('type', 20)->default('feature'); // feature | theme
                $table->string('name', 120)->nullable();         // null = the feature's own label
                $table->text('description')->nullable();
                // Null = that billing option is not offered.
                $table->decimal('monthly_price', 12, 2)->nullable();
                $table->decimal('yearly_price', 12, 2)->nullable();
                $table->decimal('lifetime_price', 12, 2)->nullable();
                $table->boolean('is_active')->default(false);
                // Plans whose tenants may buy it; null = every plan.
                $table->json('plan_ids')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('tenant_addons')) {
            $schema->create('tenant_addons', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id')->index();
                $table->string('addon_key', 80);
                // monthly | yearly | lifetime | manual (granted by an admin)
                $table->string('billing_cycle', 20)->default('monthly');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 10)->nullable();
                // pending | active | expired | cancelled | refunded | revoked
                $table->string('status', 20)->default('pending');
                $table->string('source', 20)->default('purchase'); // purchase | admin
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();          // null while active = no end
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('payment_link_id')->nullable(); // the latest purchase / renewal link
                $table->json('metadata')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
                $table->index(['status', 'ends_at']);
            });
        }

        if ($schema->hasTable('payment_links') && ! $schema->hasColumn('payment_links', 'tenant_addon_id')) {
            $schema->table('payment_links', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_addon_id')->nullable()->index();
            });
        }

        // The app caches "tables ready?" — make it see them right away.
        if (class_exists(\App\Models\Central\Addon::class)) {
            \App\Models\Central\Addon::forgetCache();
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasColumn('payment_links', 'tenant_addon_id')) {
            $schema->table('payment_links', function (Blueprint $table) {
                $table->dropIndex(['tenant_addon_id']);
                $table->dropColumn('tenant_addon_id');
            });
        }

        $schema->dropIfExists('tenant_addons');
        $schema->dropIfExists('addons');

        if (class_exists(\App\Models\Central\Addon::class)) {
            \App\Models\Central\Addon::forgetCache();
        }
    }
};
