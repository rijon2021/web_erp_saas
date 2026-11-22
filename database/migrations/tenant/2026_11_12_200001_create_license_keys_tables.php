<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Software License Keys (plan feature `license_keys`).
 *
 * products.license_mode   none | pool (uploaded third-party keys) | generated (own software)
 *                         The product keeps its real type (is_single / is_variant); the
 *                         product form shows "Software License" when the mode is set.
 * license_policies        generated-key rules per product (variant_id NULL) or per edition.
 * license_keys            every key: uploaded stock, pre-generated or issued at sale.
 *                         A key belongs to at most one sale line (sale_detail_id), claimed
 *                         with a conditional UPDATE … WHERE status = 'available'.
 * license_activations     domains / devices a generated key is activated on.
 * license_key_events      per-key timeline (imported, sold, returned, revoked, revealed…).
 * license_settings        singleton: delivery switches, API switch, signing key pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'license_mode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('license_mode', 16)->default('none')->index();
            });
        }

        if (! Schema::hasTable('license_policies')) {
            Schema::create('license_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('product_variant_id')->nullable()->index();
                $table->string('key_format', 64)->nullable();
                // lifetime | days | months | years
                $table->string('duration_type', 16)->default('lifetime');
                $table->unsignedInteger('duration_value')->default(1);
                // NULL = unlimited activations
                $table->unsignedInteger('max_activations')->nullable();
                // none | domain | device
                $table->string('restriction', 16)->default('none');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('license_keys')) {
            Schema::create('license_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                // pool | generated
                $table->string('source', 16)->default('pool');
                $table->text('key_encrypted');
                $table->char('key_hash', 64)->unique();
                $table->string('key_last4', 8)->nullable();
                // available | sold | returned | void | suspended | revoked
                $table->string('status', 16)->default('available');
                $table->decimal('cost', 15, 2)->nullable();
                $table->string('batch', 40)->nullable()->index();
                $table->unsignedBigInteger('adjustment_id')->nullable();
                $table->unsignedBigInteger('sale_id')->nullable()->index();
                $table->unsignedBigInteger('sale_detail_id')->nullable()->index();
                // Set when a sale edit / delete / return took the key back from its
                // sale line (sale_detail_id NULL); prev_status lets it be put back.
                $table->dateTime('released_at')->nullable();
                $table->string('prev_status', 16)->nullable();
                $table->unsignedBigInteger('online_order_id')->nullable()->index();
                $table->unsignedBigInteger('client_id')->nullable()->index();
                $table->dateTime('sold_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->unsignedInteger('max_activations')->nullable();
                $table->string('restriction', 16)->nullable();
                $table->string('note', 255)->nullable();
                $table->string('status_reason', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('reminded_at')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'product_variant_id', 'status'], 'license_keys_stock_idx');
            });
        }

        if (! Schema::hasTable('license_activations')) {
            Schema::create('license_activations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('license_key_id');
                // domain | device
                $table->string('kind', 16)->default('device');
                $table->string('identifier', 255);
                $table->string('label', 191)->nullable();
                $table->string('ip', 45)->nullable();
                $table->dateTime('activated_at')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->dateTime('deactivated_at')->nullable();
                $table->timestamps();

                $table->index(['license_key_id', 'identifier'], 'license_activations_key_ident_idx');
            });
        }

        if (! Schema::hasTable('license_key_events')) {
            Schema::create('license_key_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('license_key_id')->index();
                $table->string('event', 32);
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('meta')->nullable();
                $table->dateTime('created_at')->nullable();
            });
        }

        // Keys a completed sale is still owed (channel imports, pre-orders,
        // subscription invoices are never refused for lack of keys): filled as
        // soon as keys are imported, or from the sale with "Assign now".
        if (! Schema::hasTable('license_backorders')) {
            Schema::create('license_backorders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->unsignedInteger('missing');
                $table->timestamps();

                $table->unique(['sale_id', 'product_id', 'product_variant_id'], 'license_backorders_line_unique');
                $table->index(['product_id', 'product_variant_id'], 'license_backorders_product_idx');
            });
        }

        // A sale line that renewed an existing generated license instead of
        // issuing a new key (subscription invoices).
        if (! Schema::hasTable('license_renewals')) {
            Schema::create('license_renewals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('license_key_id')->index();
                $table->unsignedBigInteger('sale_id')->index();
                $table->unsignedBigInteger('sale_detail_id')->nullable()->index();
                $table->dateTime('from_expires_at')->nullable();
                $table->dateTime('to_expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('license_settings')) {
            Schema::create('license_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('email_enabled')->default(true);
                $table->boolean('show_on_receipt')->default(false);
                $table->boolean('api_enabled')->default(true);
                $table->unsignedInteger('offline_grace_days')->default(7);
                $table->string('reminder_days', 32)->default('30,7');
                $table->text('public_key')->nullable();
                $table->text('secret_key')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('license_settings');
        Schema::dropIfExists('license_renewals');
        Schema::dropIfExists('license_backorders');
        Schema::dropIfExists('license_key_events');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('license_keys');
        Schema::dropIfExists('license_policies');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'license_mode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['license_mode']);
                $table->dropColumn('license_mode');
            });
        }
    }
};
