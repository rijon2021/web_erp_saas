<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management, phase 4: the admin workflow.
 *
 * bookings.sale_id       the POS sale made by "Complete & charge"
 * bookings.confirmed_by  the user who confirmed it
 * bookings.arrived_at    when the customer was marked as arrived
 * bookings.completed_at  when the booking was completed
 *
 * New status values (no schema change, status is a string): arrived,
 * declined, no_show — next to pending, confirmed, completed, cancelled.
 */
return new class extends Migration
{
    private function columns(): array
    {
        return [
            'sale_id' => fn (Blueprint $t) => $t->integer('sale_id')->nullable()->index('bookings_sale_id'),
            'confirmed_by' => fn (Blueprint $t) => $t->integer('confirmed_by')->nullable(),
            'arrived_at' => fn (Blueprint $t) => $t->dateTime('arrived_at')->nullable(),
            'completed_at' => fn (Blueprint $t) => $t->dateTime('completed_at')->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }
        $missing = array_filter($this->columns(), fn ($col) => ! Schema::hasColumn('bookings', $col), ARRAY_FILTER_USE_KEY);
        if ($missing) {
            Schema::table('bookings', function (Blueprint $table) use ($missing) {
                foreach ($missing as $define) {
                    $define($table);
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }
        if (Schema::hasColumn('bookings', 'sale_id') && Schema::hasIndex('bookings', 'bookings_sale_id')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_sale_id'));
        }
        $present = array_values(array_filter(array_keys($this->columns()), fn ($col) => Schema::hasColumn('bookings', $col)));
        if ($present) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
