<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store Settings → Tracking & Analytics.
 *
 * The tenant's Google tag IDs (GA4 G-…, Google Ads AW-…, GT-…, or a Tag
 * Manager GTM-… container) and Meta Pixel IDs, each a comma-separated list.
 * IDs only — never raw script — so a tenant cannot run arbitrary JavaScript
 * on a host its admin panel shares. Rendered by store/partials/tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_settings')) {
            return;
        }

        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'google_tag_id')) {
                $table->string('google_tag_id', 255)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'meta_pixel_id')) {
                $table->string('meta_pixel_id', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('store_settings')) {
            return;
        }

        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['google_tag_id', 'meta_pixel_id'] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
