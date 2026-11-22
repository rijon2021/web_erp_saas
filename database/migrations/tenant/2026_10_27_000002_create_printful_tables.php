<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printful (print-on-demand) integration:
 *  - printful_settings: singleton row — the private API token, the chosen
 *    Printful store, when online orders are sent, and the webhook token.
 *  - printful_mappings: local product / variant <-> Printful sync product /
 *    sync variant. `single` maps a one-variant Printful product onto a
 *    simple Stocky product (local_id = products.id).
 *  - printful_orders: one row per online order sent (or queued) to Printful,
 *    with its Printful status, costs and tracking.
 *  - printful_logs: sync audit trail, same shape as jumia_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('printful_settings')) {
            Schema::create('printful_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                $table->text('api_token')->nullable();
                // Account-level tokens reach several stores: the one used here
                // (sent as X-PF-Store-Id). Null with a store-level token.
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('store_name', 191)->nullable();
                // Currency of Printful retail prices / costs, and how many
                // store-currency units one unit of it is worth.
                $table->string('currency', 3)->nullable();
                $table->decimal('exchange_rate', 18, 6)->nullable();
                // confirmed = when the order is confirmed in Stocky,
                // paid = once paid online, manual = only from the Orders tab.
                $table->string('submit_on', 16)->default('confirmed');
                // false = orders arrive in Printful as drafts to review.
                $table->boolean('auto_confirm')->default(false);
                $table->boolean('sync_costs')->default(true);
                $table->boolean('update_prices')->default(false);
                $table->boolean('mark_shipped')->default(true);
                $table->integer('category_id')->nullable();
                // When automatic sending was last switched on: the sweep only
                // picks up orders that reached their trigger after it.
                $table->timestamp('auto_since')->nullable();
                $table->string('webhook_token', 64)->nullable();
                $table->timestamp('webhook_registered_at')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('printful_mappings')) {
            Schema::create('printful_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // product|variant|single
                $table->unsignedBigInteger('local_id');
                $table->unsignedBigInteger('printful_id');
                $table->json('extra')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps(6);
                $table->unique(['entity_type', 'local_id'], 'printful_mappings_type_local_unique');
                // One local record per Printful record (re-imports move the link).
                $table->unique(['entity_type', 'printful_id'], 'printful_mappings_type_remote_unique');
            });
        }

        if (! Schema::hasTable('printful_orders')) {
            Schema::create('printful_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('online_order_id')->unique();
                $table->unsignedBigInteger('printful_order_id')->nullable()->index();
                $table->string('external_id', 64)->nullable();
                // queued|error, then Printful's own: draft|pending|failed|
                // canceled|inprocess|onhold|partial|fulfilled
                $table->string('status', 24)->default('queued');
                $table->json('items')->nullable();
                $table->json('costs')->nullable();
                $table->decimal('total_cost', 14, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->json('shipments')->nullable();
                $table->string('carrier', 100)->nullable();
                $table->string('tracking_number', 191)->nullable();
                $table->string('tracking_url', 500)->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->text('error')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                // Set after a network / rate-limit failure: the sweep retries then.
                $table->timestamp('retry_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->index('status');
            });
        }

        if (! Schema::hasTable('printful_logs')) {
            Schema::create('printful_logs', function (Blueprint $table) {
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
        Schema::dropIfExists('printful_logs');
        Schema::dropIfExists('printful_orders');
        Schema::dropIfExists('printful_mappings');
        Schema::dropIfExists('printful_settings');
    }
};
