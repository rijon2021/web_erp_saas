<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS devices (card terminals / fiscal cash registers such as the Ingenico
 * IDE280) and every operation sent to them:
 *  - pos_terminals: one row per physical device — model (driver), the
 *    warehouse it stands in, the payment method its charges are recorded as,
 *    how Stocky reaches it (cloud API from the server, or a local bridge
 *    program on the cashier PC reached from the browser), the merchant /
 *    terminal ids and the API secrets (encrypted).
 *  - pos_terminal_transactions: one row per sale / refund / void attempt,
 *    with the device's answer (auth code, masked card, RRN) and, once the
 *    sale is saved, the sale + payment it paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminals')) {
            Schema::create('pos_terminals', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                // TerminalRegistry code, e.g. ingenico_ide280
                $table->string('driver', 64);
                // null = usable from every warehouse
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                // The payment method the POS line uses; charges are recorded as it.
                $table->unsignedBigInteger('payment_method_id')->nullable();
                // cloud = the server calls the provider API; bridge = the browser
                // calls a local program on the cashier PC that drives the SDK.
                $table->string('connection_mode', 16)->default('bridge');
                $table->string('api_base_url', 500)->nullable();
                $table->string('bridge_url', 500)->nullable();
                // bearer | basic | header | hmac | none
                $table->string('auth_type', 16)->default('bearer');
                $table->text('api_key')->nullable();
                $table->text('api_secret')->nullable();
                $table->string('merchant_id', 64)->nullable();
                $table->string('terminal_id', 64)->nullable();
                // Device serial / fiscal register number (Sicil No)
                $table->string('serial_number', 64)->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->unsignedInteger('port')->nullable();
                // payment_only = send the card amount; full_receipt = send the
                // items too so the device prints the fiscal receipt.
                $table->string('fiscal_mode', 16)->default('payment_only');
                // endpoints, timeout_seconds, departments (VAT rate => device department)…
                $table->json('settings')->nullable();
                $table->boolean('is_test')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->string('last_status', 191)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('pos_terminal_transactions')) {
            Schema::create('pos_terminal_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pos_terminal_id')->index();
                // sale | refund | void
                $table->string('type', 16)->default('sale');
                // pending | approved | declined | cancelled | failed | timeout
                $table->string('status', 16)->default('pending')->index();
                // Our id for the operation, sent to the device and echoed back.
                $table->string('reference', 40)->unique();
                // Charged amount in `currency`; base_amount in the base currency
                // (what the POS payment line records).
                $table->decimal('amount', 16, 4)->default(0);
                $table->decimal('base_amount', 16, 4)->default(0);
                $table->string('currency', 8)->nullable();
                $table->unsignedSmallInteger('installments')->default(1);
                // The POS checkout this charge belongs to (sale_uuid of create_pos).
                $table->string('sale_uuid', 64)->nullable()->index();
                $table->unsignedBigInteger('sale_id')->nullable()->index();
                $table->unsignedBigInteger('payment_sale_id')->nullable();
                // Refund / void: the sale transaction it reverses.
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('provider_reference', 191)->nullable();
                $table->string('auth_code', 64)->nullable();
                $table->string('card_mask', 32)->nullable();
                $table->string('card_brand', 32)->nullable();
                $table->string('response_code', 32)->nullable();
                $table->string('message', 500)->nullable();
                $table->json('request')->nullable();
                $table->json('response')->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->timestamp('last_polled_at')->nullable();
                // Cloud: when the long provider call was made (once per row).
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminal_transactions');
        Schema::dropIfExists('pos_terminals');
    }
};
