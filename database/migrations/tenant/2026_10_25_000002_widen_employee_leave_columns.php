<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employees.total_leave / remaining_leave were created as boolean (tinyint),
 * which caps an annual leave allowance at 127 days. Widen both to integer.
 * Signed on purpose: lowering an employee's allowance below what they have
 * already taken can leave a negative remaining balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->integer('total_leave')->nullable()->default(0)->change();
            $table->integer('remaining_leave')->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('total_leave')->nullable()->default(0)->change();
            $table->boolean('remaining_leave')->nullable()->default(0)->change();
        });
    }
};
