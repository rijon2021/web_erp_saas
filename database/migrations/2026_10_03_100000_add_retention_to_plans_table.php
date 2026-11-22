<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan backup and data-retention policy (see Plan::RETENTION_FIELDS):
 * automatic backup frequency and how long backups are kept, and how long
 * logs, message history and temporary data are kept before tenants:prune-data
 * deletes them. NULL means the defaults — no automatic backups, keep
 * everything — so existing plans behave exactly as before until configured.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('plans', 'retention')) {
            return;
        }

        Schema::connection($this->connection)->table('plans', function (Blueprint $table) {
            $table->json('retention')->nullable()->after('features');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('plans', 'retention')) {
            return;
        }

        Schema::connection($this->connection)->table('plans', function (Blueprint $table) {
            $table->dropColumn('retention');
        });
    }
};
