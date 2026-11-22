<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenCart integration (single store per tenant):
 *  - opencart_settings: singleton row. The store URL and connector secret,
 *    the OpenCart store and warehouse in scope, the automation switches, and
 *    which OpenCart order statuses mean what to Stocky.
 *  - opencart_mappings: local entity <-> OpenCart id. Unique both ways, which
 *    is also what stops the webhook and the scheduled pull from importing one
 *    order twice.
 *  - opencart_logs: sync audit trail, same shape as salla_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opencart_settings')) {
            Schema::create('opencart_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                $table->string('store_url', 191)->nullable();
                // Shown on the connector module page in OpenCart. Encrypted.
                $table->text('secret')->nullable();
                // OpenCart multi-store: which store this connection covers.
                $table->unsignedInteger('store_id')->default(0);
                // Stock counted for the shop, and where orders land. null = all
                // warehouses for stock, the default warehouse for orders.
                $table->integer('warehouse_id')->nullable();
                $table->boolean('auto_sync_products')->default(false);
                $table->boolean('auto_sync_stock')->default(true);
                $table->boolean('auto_import_orders')->default(true);
                // Order status ids, filled from the store on first connection.
                $table->json('commit_status_ids')->nullable();
                $table->json('paid_status_ids')->nullable();
                $table->json('cancel_status_ids')->nullable();
                // Authenticates the webhook URL alongside its HMAC signature.
                $table->string('webhook_token', 64)->nullable();
                // On OpenCart's clock, so a skewed server cannot lose orders.
                $table->dateTime('orders_cursor')->nullable();
                // Orders a scheduled run could not import yet: id => attempts.
                $table->json('orders_retry')->nullable();
                $table->string('connector_version', 20)->nullable();
                $table->string('opencart_version', 20)->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('last_webhook_at')->nullable();
                $table->timestamp('last_stock_reconcile_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('opencart_mappings')) {
            Schema::create('opencart_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // product|variant|customer|order
                $table->unsignedBigInteger('local_id');
                $table->unsignedBigInteger('opencart_id');
                $table->json('extra')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps(6);
                $table->unique(['entity_type', 'local_id'], 'oc_mappings_type_local_unique');
                $table->unique(['entity_type', 'opencart_id'], 'oc_mappings_type_remote_unique');
            });
        }

        if (! Schema::hasTable('opencart_logs')) {
            Schema::create('opencart_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action', 100);
                $table->string('level', 20)->default('info'); // info|warning|error
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps(6);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('opencart_logs');
        Schema::dropIfExists('opencart_mappings');
        Schema::dropIfExists('opencart_settings');
    }
};
