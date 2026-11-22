<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UI Lib licence state for the platform (central DB, single row): the
 * licence key / purchase code the owner entered, the contact email (Envato
 * codes only), and the signed token ui-lib.com answered with. The token is
 * the only thing that proves the licence: it is verified offline with the
 * public key on every read, so an edited row never passes as licensed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('central');

        if ($schema->hasTable('ui_lib_license')) {
            return;
        }

        $schema->create('ui_lib_license', function (Blueprint $table) {
            $table->id();
            $table->text('license')->nullable();           // encrypted licence key / purchase code
            $table->string('email', 191)->nullable();      // required with an Envato purchase code
            $table->text('token')->nullable();             // signed answer from ui-lib.com
            $table->string('instance_id', 64)->nullable(); // random id shown to UI Lib support
            $table->string('last_code', 64)->nullable();   // last error code (null = ok)
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('grace_started_at')->nullable(); // when this install stopped being licensed (lock grace counts from here)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('ui_lib_license');
    }
};
