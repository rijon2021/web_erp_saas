<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management, phase 3: the menu.
 *
 * rst_stations         kitchen stations (Grill, Fryer, Cold, Desserts, Drinks)
 * rst_dish_details     one row per product sold as a dish: station, prep time,
 *                      spice, dietary tags, meal periods, sold-out date
 * rst_addon_groups     "Size", "Extras", "Spice level": min / max choices
 * rst_addon_options    the choices, each with an extra price and optionally the
 *                      product whose stock it uses (cheese slice, egg…)
 * rst_dish_addon_group which groups a dish offers, in order
 * rst_settings.menu_times  breakfast / lunch / dinner windows
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rst_stations')) {
            Schema::create('rst_stations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 60);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rst_dish_details')) {
            Schema::create('rst_dish_details', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('product_id')->unique();
                $table->unsignedInteger('station_id')->nullable()->index();
                $table->unsignedSmallInteger('prep_minutes')->nullable();
                $table->unsignedSmallInteger('calories')->nullable();
                $table->string('spice_level', 10)->nullable(); // mild | medium | hot
                $table->json('dietary')->nullable();           // vegetarian, vegan, spicy, halal, gluten_free
                $table->json('meal_periods')->nullable();      // breakfast, lunch, dinner — empty = all day
                $table->date('sold_out_on')->nullable();       // sold out for that day only
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('rst_addon_groups')) {
            Schema::create('rst_addon_groups', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 80);
                $table->unsignedTinyInteger('min_select')->default(0);
                $table->unsignedTinyInteger('max_select')->default(1);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rst_addon_options')) {
            Schema::create('rst_addon_options', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('group_id');
                $table->string('name', 80);
                $table->decimal('price', 12, 2)->default(0);
                $table->integer('product_id')->nullable()->index(); // ingredient whose stock this option uses
                $table->decimal('stock_qty', 12, 3)->default(1);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_available')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('group_id')->references('id')->on('rst_addon_groups')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('rst_dish_addon_group')) {
            Schema::create('rst_dish_addon_group', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('product_id');
                $table->unsignedInteger('group_id');
                $table->unsignedInteger('sort_order')->default(0);

                $table->unique(['product_id', 'group_id']);
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('group_id')->references('id')->on('rst_addon_groups')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('rst_settings') && ! Schema::hasColumn('rst_settings', 'menu_times')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                $table->json('menu_times')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rst_settings') && Schema::hasColumn('rst_settings', 'menu_times')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                $table->dropColumn('menu_times');
            });
        }
        Schema::dropIfExists('rst_dish_addon_group');
        Schema::dropIfExists('rst_addon_options');
        Schema::dropIfExists('rst_addon_groups');
        Schema::dropIfExists('rst_dish_details');
        Schema::dropIfExists('rst_stations');
    }
};
