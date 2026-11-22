<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product size guides (size charts), ported from stocky. Each guide holds a
 * flexible table — `columns` = header labels (Size, Bust, Waist, Hips…),
 * `rows` = rows of cell values aligned to the columns — and/or a chart image.
 * Products point at one guide through products.size_guide_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('size_guides')) {
            Schema::create('size_guides', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 191);
                $table->string('image', 191)->nullable();
                $table->json('columns')->nullable();
                $table->json('rows')->nullable();
                $table->boolean('status')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'size_guide_id')) {
                $table->integer('size_guide_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'size_guide_id')) {
                $table->dropIndex(['size_guide_id']);
                $table->dropColumn('size_guide_id');
            }
        });
        Schema::dropIfExists('size_guides');
    }
};
