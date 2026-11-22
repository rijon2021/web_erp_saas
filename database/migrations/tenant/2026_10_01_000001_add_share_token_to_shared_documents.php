<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-document share token for the links sent to customers and suppliers.
 *
 * Emails, SMS and WhatsApp messages used to carry /api/sale_pdf/{id}, an
 * unauthenticated route keyed by a sequential id, so anyone could walk the ids
 * and download every invoice. Those routes now require a login; outgoing links
 * point at /api/share/{type}/{token} instead, and the token is the only key.
 *
 * Tokens are created lazily the first time a document is sent, so there is
 * nothing to backfill.
 */
return new class extends Migration
{
    private const TABLES = [
        'sales',
        'quotations',
        'purchases',
        'bookings',
        'payment_sale_returns',
        'payment_purchase_returns',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'share_token')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('share_token', 64)->nullable()->unique($name.'_share_token_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'share_token')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique($name.'_share_token_unique');
                $table->dropColumn('share_token');
            });
        }
    }
};
