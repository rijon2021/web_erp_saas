<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management upgrade (plan feature `bookings`), phase 1.
 *
 * bk_settings   the module's single settings row: business types, how
 *               customers book (pick a free time / send a request), holds,
 *               booking-form field modes, request time windows,
 *               notifications and website options.
 * bookings      new columns for website bookings and later phases (staff,
 *               branch, duration, seats, request details, public status
 *               link, hold, confirm / cancel tracking). Every column is
 *               nullable or defaulted, so existing bookings and the current
 *               booking form keep working unchanged.
 */
return new class extends Migration
{
    /** bookings column => definition, added only when missing. */
    private function bookingColumns(): array
    {
        return [
            'source' => fn (Blueprint $t) => $t->string('source', 20)->default('staff')->index('bookings_source'),
            'booking_mode' => fn (Blueprint $t) => $t->string('booking_mode', 10)->nullable(),
            'business_type' => fn (Blueprint $t) => $t->string('business_type', 30)->nullable(),
            'staff_id' => fn (Blueprint $t) => $t->integer('staff_id')->nullable()->index('bookings_staff_id'),
            'warehouse_id' => fn (Blueprint $t) => $t->integer('warehouse_id')->nullable()->index('bookings_warehouse_id'),
            'duration_minutes' => fn (Blueprint $t) => $t->unsignedSmallInteger('duration_minutes')->nullable(),
            'seats' => fn (Blueprint $t) => $t->unsignedSmallInteger('seats')->default(1),
            'preferred_date' => fn (Blueprint $t) => $t->date('preferred_date')->nullable(),
            'preferred_window' => fn (Blueprint $t) => $t->string('preferred_window', 20)->nullable(),
            'request_fields' => fn (Blueprint $t) => $t->json('request_fields')->nullable(),
            'public_token' => fn (Blueprint $t) => $t->string('public_token', 64)->nullable()->unique('bookings_public_token'),
            'hold_until' => fn (Blueprint $t) => $t->dateTime('hold_until')->nullable(),
            'confirmed_at' => fn (Blueprint $t) => $t->dateTime('confirmed_at')->nullable(),
            'cancelled_at' => fn (Blueprint $t) => $t->dateTime('cancelled_at')->nullable(),
            'cancelled_by' => fn (Blueprint $t) => $t->string('cancelled_by', 20)->nullable(),
            'cancel_reason' => fn (Blueprint $t) => $t->string('cancel_reason', 500)->nullable(),
            'ecommerce_client_id' => fn (Blueprint $t) => $t->integer('ecommerce_client_id')->nullable()->index('bookings_ecommerce_client_id'),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('bk_settings')) {
            Schema::create('bk_settings', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->json('business_types')->nullable();          // NULL / [] = every type
                $table->boolean('slot_booking')->default(true);      // customers pick a free time
                $table->boolean('request_booking')->default(true);   // customers send a request
                $table->unsignedSmallInteger('slot_step_minutes')->default(15); // 0 = the service length
                $table->unsignedInteger('min_notice_minutes')->default(120);
                $table->unsignedSmallInteger('max_days_ahead')->default(60);
                $table->unsignedInteger('hold_minutes')->default(120); // 0 = until answered
                $table->boolean('auto_confirm')->default(false);
                $table->unsignedTinyInteger('max_open_per_customer')->default(3);
                $table->boolean('customer_cancel')->default(true);
                $table->unsignedSmallInteger('cancel_notice_hours')->default(24);
                $table->json('form_fields')->nullable();             // field id => required|optional|off
                $table->json('time_windows')->nullable();            // morning/afternoon/evening {enabled, from, to}
                $table->json('service_areas')->nullable();           // pick list for the "Area" field
                $table->boolean('notify_owner')->default(true);
                $table->string('notify_owner_email', 191)->nullable(); // NULL = the company email
                $table->boolean('notify_customer')->default(true);
                $table->unsignedSmallInteger('reminder_hours')->default(24); // 0 = no reminder
                $table->boolean('whatsapp_notify')->default(false);
                $table->boolean('hero_form')->default(true);
                $table->boolean('show_team')->default(true);
                $table->text('terms')->nullable();
                $table->integer('default_warehouse_id')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('bookings')) {
            $missing = array_filter(
                $this->bookingColumns(),
                fn ($col) => ! Schema::hasColumn('bookings', $col),
                ARRAY_FILTER_USE_KEY
            );
            if ($missing) {
                Schema::table('bookings', function (Blueprint $table) use ($missing) {
                    foreach ($missing as $define) {
                        $define($table);
                    }
                });
            }
            if (! Schema::hasIndex('bookings', 'bookings_date_status')) {
                Schema::table('bookings', function (Blueprint $table) {
                    $table->index(['booking_date', 'status'], 'bookings_date_status');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bookings')) {
            if (Schema::hasIndex('bookings', 'bookings_date_status')) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_date_status'));
            }
            $indexes = [
                'source' => 'bookings_source', 'staff_id' => 'bookings_staff_id', 'warehouse_id' => 'bookings_warehouse_id',
                'public_token' => 'bookings_public_token', 'ecommerce_client_id' => 'bookings_ecommerce_client_id',
            ];
            foreach ($indexes as $col => $name) {
                if (Schema::hasColumn('bookings', $col) && Schema::hasIndex('bookings', $name)) {
                    Schema::table('bookings', function (Blueprint $table) use ($col, $name) {
                        $col === 'public_token' ? $table->dropUnique($name) : $table->dropIndex($name);
                    });
                }
            }
            $present = array_values(array_filter(array_keys($this->bookingColumns()), fn ($col) => Schema::hasColumn('bookings', $col)));
            if ($present) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn($present));
            }
        }

        Schema::dropIfExists('bk_settings');
    }
};
