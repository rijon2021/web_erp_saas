<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing gateway credentials were VARCHAR(255). Meta (WhatsApp Cloud API)
 * access tokens can be longer, and with strict mode off MySQL silently cut
 * them on save — the stored token then failed with Graph error 190
 * ("Authentication Error"). TEXT holds any provider token.
 *
 * A token already cut short is lost; the admin must paste it again.
 */
return new class extends Migration
{
    private const COLUMNS = ['sms_api_key', 'sms_api_secret', 'whatsapp_api_key'];

    public function up(): void
    {
        if (! Schema::hasTable('marketing_settings')) {
            return;
        }

        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('marketing_settings', $column)) {
                    $table->text($column)->nullable()->change();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_settings')) {
            return;
        }

        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('marketing_settings', $column)) {
                    $table->string($column)->nullable()->change();
                }
            }
        });
    }
};
