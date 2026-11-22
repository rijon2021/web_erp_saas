<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product specifications + product video (Electronics Premium store theme).
 *
 *  - products.specs: JSON list of spec rows
 *      [{"group": "Display", "label": "Size", "value": "6.7 in", "highlight": true}, …]
 *    Shown as the spec table / highlight tiles on the product page, as chips
 *    on product cards, as shop filters and as compare-table rows. NULL = none.
 *  - products.video_url: an http(s) link (YouTube / Vimeo embeds; anything
 *    else opens in a new tab) shown in the product gallery.
 *
 * Both are optional: every storefront and admin screen works without them
 * (App\Support\StoreSpecs::ready()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'specs')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('specs')->nullable();
            });
        }

        if (! Schema::hasColumn('products', 'video_url')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('video_url', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach (['specs', 'video_url'] as $col) {
            if (Schema::hasColumn('products', $col)) {
                Schema::table('products', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }
};
