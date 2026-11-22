<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** crm_settings.default_owner gains a third mode, 'rules' (assignment rules): enum → string. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('crm_settings', 'default_owner')) {
            Schema::table('crm_settings', function (Blueprint $table) {
                $table->string('default_owner', 20)->default('creator')->change();
            });
        }
    }

    public function down(): void
    {
        // Kept as a string: narrowing back to the enum would fail on 'rules' rows.
    }
};
