<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management, phase 2: dining areas and their tables.
 *
 * A table's live state (status, guests, waiter, seated_at, merged_into_id)
 * sits on the row itself; orders (phase 4) point at the table. Both tables
 * soft-delete so past orders keep their table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rst_areas')) {
            Schema::create('rst_areas', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 80);
                $table->integer('warehouse_id')->nullable()->index(); // null = every branch
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rst_tables')) {
            Schema::create('rst_tables', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('area_id');
                $table->string('name', 20);
                $table->unsignedTinyInteger('seats')->default(4);
                $table->string('shape', 10)->default('square'); // square | round | long
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('status', 16)->default('free'); // free | occupied | bill | reserved | cleaning
                $table->unsignedSmallInteger('guests')->nullable();
                $table->timestamp('seated_at')->nullable();
                $table->integer('waiter_id')->nullable();
                $table->unsignedInteger('merged_into_id')->nullable()->index();
                $table->timestamp('status_changed_at')->nullable();
                $table->string('note', 191)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('area_id')->references('id')->on('rst_areas')->cascadeOnDelete();
                $table->index(['area_id', 'sort_order']);
                $table->index(['status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rst_tables');
        Schema::dropIfExists('rst_areas');
    }
};
