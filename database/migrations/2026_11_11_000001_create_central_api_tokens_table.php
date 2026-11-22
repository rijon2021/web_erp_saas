<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens for the super-admin mobile app (central DB, /api/super/*).
 * Only a SHA-256 hash of each token is stored; the plain token is shown once
 * at sign-in. Kept apart from Passport so a central admin id can never be
 * mistaken for a workspace user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('central_api_tokens')) {
            return;
        }

        $schema->create('central_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('central_user_id')->index();
            $table->string('name', 191)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('central_api_tokens');
    }
};
