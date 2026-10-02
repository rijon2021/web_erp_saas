<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentMethodsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // is_cash drives the cash register close: only these tenders are
        // expected in the physical drawer at the end of a shift.
        $paymentMethods = [
            ['id' => 1, 'name' => 'Credit Card', 'is_cash' => false],
            ['id' => 2, 'name' => 'Cash', 'is_cash' => true],
            ['id' => 3, 'name' => 'Check', 'is_cash' => false],
            ['id' => 4, 'name' => 'TPE', 'is_cash' => false],
            ['id' => 5, 'name' => 'Western Union', 'is_cash' => false],
            ['id' => 6, 'name' => 'bank transfer', 'is_cash' => false],
            ['id' => 7, 'name' => 'other', 'is_cash' => false],
        ];

        $hasCashColumn = Schema::hasColumn('payment_methods', 'is_cash');

        foreach ($paymentMethods as $method) {
            if (! $hasCashColumn) {
                unset($method['is_cash']);
            }

            DB::table('payment_methods')->updateOrInsert(['id' => $method['id']], $method);
        }
    }
}
