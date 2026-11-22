<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta-approved WhatsApp templates for marketing campaigns.
 *
 * The WhatsApp Cloud API only delivers free text to customers who messaged the
 * business in the last 24 hours; everyone else needs an approved template. A
 * WhatsApp campaign (or a saved marketing template that pre-fills one) can now
 * name that template, its language and its body parameters ({{1}}, {{2}}...),
 * each of which may use personalization variables like {customer_name}.
 */
return new class extends Migration
{
    private const TABLES = ['marketing_campaigns', 'marketing_templates'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'wa_template_name')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('wa_template_name', 191)->nullable();
                $table->string('wa_template_language', 20)->nullable();
                // JSON array of strings, one per {{n}} body placeholder, in order.
                $table->text('wa_template_params')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'wa_template_name')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['wa_template_name', 'wa_template_language', 'wa_template_params']);
            });
        }
    }
};
