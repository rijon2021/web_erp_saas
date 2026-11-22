<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management, phase 6: notifications and "suggest another time".
 *
 * bookings.reminder_sent_at   the reminder before the visit went out (sent once)
 * bookings.proposed_*         a time staff suggested instead; the customer accepts
 *                             it on their status page (or asks for another)
 * bk_messages                 every email / WhatsApp message about a booking
 *                             (sent, failed or skipped), shown on the booking
 */
return new class extends Migration
{
    private function columns(): array
    {
        return [
            'reminder_sent_at' => fn (Blueprint $t) => $t->dateTime('reminder_sent_at')->nullable(),
            'proposed_date' => fn (Blueprint $t) => $t->date('proposed_date')->nullable(),
            'proposed_time' => fn (Blueprint $t) => $t->char('proposed_time', 5)->nullable(),
            'proposed_end_time' => fn (Blueprint $t) => $t->char('proposed_end_time', 5)->nullable(),
            'proposed_staff_id' => fn (Blueprint $t) => $t->integer('proposed_staff_id')->nullable(),
            'proposed_at' => fn (Blueprint $t) => $t->dateTime('proposed_at')->nullable(),
            'proposal_note' => fn (Blueprint $t) => $t->string('proposal_note', 500)->nullable(),
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('bookings')) {
            $missing = array_filter($this->columns(), fn ($col) => ! Schema::hasColumn('bookings', $col), ARRAY_FILTER_USE_KEY);
            if ($missing) {
                Schema::table('bookings', function (Blueprint $table) use ($missing) {
                    foreach ($missing as $define) {
                        $define($table);
                    }
                });
            }
        }

        if (! Schema::hasTable('bk_messages')) {
            Schema::create('bk_messages', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('booking_id')->index('bk_messages_booking');
                $table->string('kind', 30);          // received | new_request | confirmed | declined | cancelled | …
                $table->string('channel', 20);       // email | whatsapp
                $table->string('recipient', 20);     // customer | owner
                $table->string('to', 191)->nullable();
                $table->string('status', 20);        // sent | failed | skipped
                $table->string('error', 1000)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_messages');
        if (Schema::hasTable('bookings')) {
            $present = array_values(array_filter(array_keys($this->columns()), fn ($col) => Schema::hasColumn('bookings', $col)));
            if ($present) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn($present));
            }
        }
    }
};
