<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_fields') && ! Schema::hasColumn('custom_fields', 'sort_order')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->integer('sort_order')->default(0)->after('default_value');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('custom_fields') && Schema::hasColumn('custom_fields', 'sort_order')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
