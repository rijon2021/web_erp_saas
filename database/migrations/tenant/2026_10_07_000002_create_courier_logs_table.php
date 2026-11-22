<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration logs: every call Stocky makes to a courier API
 * (credentials masked, bodies truncated), every webhook a courier sends, and
 * every booking / status / label error. Kept 30 days (couriers:sync prunes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('courier_logs')) {
            return;
        }

        Schema::create('courier_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('courier_account_id')->nullable()->index();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->string('courier', 40)->nullable();
            $table->string('action', 30)->index();        // test | book | rates | cities | label | cancel | track | webhook | other
            $table->string('direction', 3)->default('out'); // out = Stocky → courier, in = courier → Stocky
            $table->string('level', 10)->default('info')->index(); // info | error
            $table->string('method', 10)->nullable();
            $table->string('url', 500)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_logs');
    }
};
