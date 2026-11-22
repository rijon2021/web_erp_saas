<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management, phase 8: deposits paid online and CRM capture.
 *
 * bk_settings.deposit_*       the default deposit (none | fixed | percent | full),
 *                             how long an unpaid booking holds its time, whether
 *                             requests are asked for it once confirmed, and the
 *                             text shown next to it
 * bk_settings.crm_*           website bookings go to the CRM (note on the
 *                             customer / open lead; requests also as a deal)
 * bk_services.deposit_*       a service's own deposit (deposit_type NULL = the default)
 * bookings.deposit_*          what is due, what was paid, how and when
 * bookings.crm_lead_id / crm_deal_id   where the CRM put it
 * bk_payments                 every online deposit attempt (pending | paid | failed)
 */
return new class extends Migration
{
    private function settingsColumns(): array
    {
        return [
            'deposit_type' => fn (Blueprint $t) => $t->string('deposit_type', 10)->default('none'),
            'deposit_value' => fn (Blueprint $t) => $t->decimal('deposit_value', 12, 3)->default(0),
            'deposit_minutes' => fn (Blueprint $t) => $t->unsignedSmallInteger('deposit_minutes')->default(30),
            'deposit_auto_cancel' => fn (Blueprint $t) => $t->boolean('deposit_auto_cancel')->default(true),
            'deposit_on_requests' => fn (Blueprint $t) => $t->boolean('deposit_on_requests')->default(false),
            'deposit_note' => fn (Blueprint $t) => $t->string('deposit_note', 500)->nullable(),
            'deposit_gateways' => fn (Blueprint $t) => $t->text('deposit_gateways')->nullable(),
            'crm_capture' => fn (Blueprint $t) => $t->boolean('crm_capture')->default(false),
            'crm_request_deals' => fn (Blueprint $t) => $t->boolean('crm_request_deals')->default(true),
        ];
    }

    private function serviceColumns(): array
    {
        return [
            'deposit_type' => fn (Blueprint $t) => $t->string('deposit_type', 10)->nullable(),
            'deposit_value' => fn (Blueprint $t) => $t->decimal('deposit_value', 12, 3)->nullable(),
        ];
    }

    private function bookingColumns(): array
    {
        return [
            'deposit_amount' => fn (Blueprint $t) => $t->decimal('deposit_amount', 12, 3)->nullable(),
            'deposit_status' => fn (Blueprint $t) => $t->string('deposit_status', 12)->nullable(),   // due | paid | waived | refunded
            'deposit_paid' => fn (Blueprint $t) => $t->decimal('deposit_paid', 12, 3)->default(0),
            'deposit_paid_at' => fn (Blueprint $t) => $t->dateTime('deposit_paid_at')->nullable(),
            'deposit_gateway' => fn (Blueprint $t) => $t->string('deposit_gateway', 20)->nullable(),
            'deposit_reference' => fn (Blueprint $t) => $t->string('deposit_reference', 191)->nullable(),
            'deposit_note' => fn (Blueprint $t) => $t->string('deposit_note', 500)->nullable(),
            'crm_lead_id' => fn (Blueprint $t) => $t->integer('crm_lead_id')->nullable(),
            'crm_deal_id' => fn (Blueprint $t) => $t->integer('crm_deal_id')->nullable(),
        ];
    }

    private function add(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $missing = array_filter($columns, fn ($col) => ! Schema::hasColumn($table, $col), ARRAY_FILTER_USE_KEY);
        if ($missing) {
            Schema::table($table, function (Blueprint $t) use ($missing) {
                foreach ($missing as $define) {
                    $define($t);
                }
            });
        }
    }

    private function drop(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $present = array_values(array_filter(array_keys($columns), fn ($col) => Schema::hasColumn($table, $col)));
        if ($present) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    public function up(): void
    {
        $this->add('bk_settings', $this->settingsColumns());
        $this->add('bk_services', $this->serviceColumns());
        $this->add('bookings', $this->bookingColumns());

        if (! Schema::hasTable('bk_payments')) {
            Schema::create('bk_payments', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('booking_id')->index('bk_payments_booking');
                $table->string('gateway', 20);
                $table->string('reference', 191)->nullable();     // the gateway's checkout / order / invoice id
                $table->string('transaction_id', 191)->nullable();
                $table->decimal('amount', 12, 3);
                $table->string('currency', 3);
                $table->string('status', 12)->default('pending'); // pending | paid | failed
                $table->string('error', 500)->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();
                $table->unique(['gateway', 'reference'], 'bk_payments_gateway_reference');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_payments');
        $this->drop('bookings', $this->bookingColumns());
        $this->drop('bk_services', $this->serviceColumns());
        $this->drop('bk_settings', $this->settingsColumns());
    }
};
