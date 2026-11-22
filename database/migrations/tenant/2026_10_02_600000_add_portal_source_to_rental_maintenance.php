<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 9: maintenance requests sent by customers from
     * the client portal are stored as jobs with source = 'portal'.
     */
    public function up(): void
    {
        if (Schema::hasTable('rental_maintenance') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rental_maintenance MODIFY source ENUM('manual', 'return', 'schedule', 'portal') NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_maintenance') && DB::getDriverName() === 'mysql') {
            DB::table('rental_maintenance')->where('source', 'portal')->update(['source' => 'manual']);
            DB::statement("ALTER TABLE rental_maintenance MODIFY source ENUM('manual', 'return', 'schedule') NOT NULL DEFAULT 'manual'");
        }
    }
};
