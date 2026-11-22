<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration (phase 1): tenant courier accounts, the courier
 * settings singleton, courier columns on the existing shipments table, the
 * per-shipment tracking timeline and the Stocky-city → courier-city map.
 *
 * A courier shipment IS a row in `shipments` (one per sale), so manual and
 * API-booked shipments share the existing Shipments page. `status` stays the
 * legacy shipping status (ordered/packed/shipped/delivered/cancelled) mirrored
 * on sales.shipping_status; `courier_status` is the finer courier lifecycle
 * (App\Services\Couriers\CourierStatus).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courier_accounts')) {
            Schema::create('courier_accounts', function (Blueprint $table) {
                $table->increments('id');
                // Driver code from CourierRegistry ('manual', 'postex', …).
                $table->string('courier', 50)->index();
                $table->string('name', 120);
                $table->string('mode', 10)->default('live'); // test | live
                // Driver credential fields (API keys, tokens) — encrypted at rest.
                $table->text('credentials')->nullable();
                // Driver-specific options (service type, pickup address code…).
                $table->json('settings')->nullable();
                // Tracking page template, {tracking} is replaced. Drivers ship a
                // default; the manual courier relies on it entirely.
                $table->string('tracking_url', 500)->nullable();
                // Pickup / return address printed on bookings and labels.
                $table->string('pickup_contact', 120)->nullable();
                $table->string('pickup_phone', 40)->nullable();
                $table->string('pickup_address', 500)->nullable();
                $table->string('pickup_city', 120)->nullable();
                // Orders from this warehouse default to this account.
                $table->integer('warehouse_id')->nullable()->index();
                // Booking defaults.
                $table->decimal('default_weight', 10, 3)->default(0.5);
                $table->string('default_service', 60)->nullable();
                $table->boolean('allow_open_parcel')->default(false);
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                // Secret path segment of this account's webhook URL.
                $table->string('webhook_token', 64)->unique();
                $table->timestamp('last_tested_at')->nullable();
                $table->boolean('last_test_ok')->nullable();
                $table->string('last_test_message', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('courier_settings')) {
            Schema::create('courier_settings', function (Blueprint $table) {
                $table->increments('id');
                // Returned parcels: 'review' queues them for a person to
                // inspect; 'restock' creates the sale return automatically.
                $table->string('return_action', 20)->default('review');
                // Record a sale payment when the courier remits the COD amount.
                // Only happens once cod_account_id is chosen.
                $table->boolean('cod_auto_record')->default(true);
                $table->integer('cod_account_id')->nullable();
                $table->integer('cod_payment_method_id')->nullable();
                // Save courier delivery charges as an expense.
                $table->boolean('charges_as_expense')->default(false);
                $table->integer('charges_expense_category_id')->nullable();
                // Minutes between status polls for couriers without webhooks.
                $table->unsignedSmallInteger('sync_interval_minutes')->default(60);
                $table->timestamps();
            });
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'courier_account_id')) {
                $table->integer('courier_account_id')->nullable()->index()->after('sale_id');
                $table->string('courier', 50)->nullable()->after('courier_account_id');
                $table->string('tracking_number', 100)->nullable()->index()->after('courier');
                // The courier's own order / consignment id when it differs.
                $table->string('courier_reference', 100)->nullable()->after('tracking_number');
                $table->string('courier_status', 30)->nullable()->index()->after('status');
                $table->string('courier_status_raw', 191)->nullable()->after('courier_status');
                $table->string('destination_city', 120)->nullable()->after('shipping_address');
                $table->decimal('weight', 10, 3)->nullable();
                $table->unsignedSmallInteger('pieces')->nullable();
                $table->decimal('cod_amount', 15, 2)->default(0);
                // none | pending | collected | remitted
                $table->string('cod_status', 20)->nullable()->index();
                $table->timestamp('cod_remitted_at')->nullable();
                $table->string('cod_reference', 120)->nullable();
                $table->decimal('shipping_charges', 15, 2)->nullable();
                $table->text('label_url')->nullable();
                $table->timestamp('booked_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                // Raw booking response and driver extras.
                $table->json('courier_meta')->nullable();
            }
        });

        if (! Schema::hasTable('shipment_events')) {
            Schema::create('shipment_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('shipment_id')->index();
                $table->string('status', 30)->nullable();      // CourierStatus value
                $table->string('raw_status', 191)->nullable(); // courier's own wording
                $table->text('description')->nullable();
                $table->string('location', 191)->nullable();
                $table->timestamp('event_at')->nullable();
                $table->string('source', 20)->default('manual'); // booking | webhook | poll | manual
                // Dedupe key: polling the same history twice adds nothing.
                $table->string('hash', 40);
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->unique(['shipment_id', 'hash']);
            });
        }

        if (! Schema::hasTable('courier_city_mappings')) {
            Schema::create('courier_city_mappings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('courier', 50);
                // Stocky-side city, normalised (lower-case, trimmed).
                $table->string('city_name', 120);
                $table->string('courier_city_code', 100)->nullable();
                $table->string('courier_city_name', 150);
                $table->timestamps();
                $table->unique(['courier', 'city_name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_city_mappings');
        Schema::dropIfExists('shipment_events');

        Schema::table('shipments', function (Blueprint $table) {
            foreach ([
                'courier_account_id', 'courier', 'tracking_number', 'courier_reference',
                'courier_status', 'courier_status_raw', 'destination_city', 'weight', 'pieces',
                'cod_amount', 'cod_status', 'cod_remitted_at', 'cod_reference', 'shipping_charges',
                'label_url', 'booked_at', 'delivered_at', 'returned_at', 'last_synced_at', 'courier_meta',
            ] as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('courier_settings');
        Schema::dropIfExists('courier_accounts');
    }
};
