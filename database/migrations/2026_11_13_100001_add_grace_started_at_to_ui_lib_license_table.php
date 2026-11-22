<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installs that created ui_lib_license before grace_started_at was added to
 * the create migration never got the column (that migration skips when the
 * table exists). Add it here, idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('ui_lib_license') || $schema->hasColumn('ui_lib_license', 'grace_started_at')) {
            return;
        }

        $schema->table('ui_lib_license', function (Blueprint $table) {
            $table->timestamp('grace_started_at')->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('ui_lib_license') && $schema->hasColumn('ui_lib_license', 'grace_started_at')) {
            $schema->table('ui_lib_license', function (Blueprint $table) {
                $table->dropColumn('grace_started_at');
            });
        }
    }
};
