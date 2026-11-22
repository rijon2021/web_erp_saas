<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per stock-moving import (Opening Stock, Import Purchases): a hash of
 * the file's parsed rows plus the document it created, so uploading the same
 * file again asks for confirmation instead of silently doubling stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_fingerprints')) {
            return;
        }

        Schema::create('import_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->char('hash', 64);
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('file_name', 191)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['kind', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_fingerprints');
    }
};
