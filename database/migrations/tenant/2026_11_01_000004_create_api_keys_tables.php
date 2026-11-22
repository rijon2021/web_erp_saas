<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public REST API (/api/v1, plan feature `api`):
 *  - api_keys: one row per key. Only a SHA-256 of the key is stored (the key
 *    itself is shown once, at creation); `ability` is read or write; the key
 *    acts as `user_id`, so that user's permissions and warehouses apply.
 *  - api_idempotency: responses of write calls sent with an Idempotency-Key
 *    header, so a retried request is answered from here instead of posting the
 *    same stock or purchase twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('key_prefix', 16);
                $table->char('key_hash', 64)->unique();
                $table->string('ability', 10)->default('read');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_idempotency')) {
            Schema::create('api_idempotency', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('api_key_id');
                $table->string('idempotency_key', 100);
                $table->string('method', 10);
                $table->string('path', 191);
                $table->unsignedSmallInteger('status');
                $table->longText('response');
                $table->timestamps();

                $table->unique(['api_key_id', 'idempotency_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency');
        Schema::dropIfExists('api_keys');
    }
};
