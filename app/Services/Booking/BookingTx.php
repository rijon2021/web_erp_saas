<?php

namespace App\Services\Booking;

use Illuminate\Support\Facades\DB;

/**
 * A transaction for booking writes that check free time.
 *
 * MySQL's default REPEATABLE READ fixes a transaction's snapshot at its first
 * plain SELECT, so a check made after waiting for the staff row lock would
 * still miss a booking another visitor committed meanwhile. READ COMMITTED
 * lets every read after the lock see what was committed, so "lock the staff
 * row, then check" really does serialise bookings for that person.
 */
class BookingTx
{
    public static function run(callable $fn)
    {
        if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Applies to the next transaction only.
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($fn);
    }
}
