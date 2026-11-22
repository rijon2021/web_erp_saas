<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin "force logout" cutoff (App\Support\Security\ForceLogout): every
 * session of the user that signed in at or before this moment is refused and
 * the user must sign in again. Stored in UTC; DATETIME rather than TIMESTAMP
 * so MySQL never converts it with the connection's time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'sessions_revoked_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('sessions_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'sessions_revoked_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sessions_revoked_at');
        });
    }
};
