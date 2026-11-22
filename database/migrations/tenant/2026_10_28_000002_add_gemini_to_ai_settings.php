<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant: Gemini (Google) as a third provider, with its own encrypted
 * key and model next to Claude's and ChatGPT's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_settings', 'gemini_api_key')) {
                $table->text('gemini_api_key')->nullable(); // encrypted
            }
            if (! Schema::hasColumn('ai_settings', 'gemini_model')) {
                $table->string('gemini_model', 64)->default('gemini-3.8-flash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            foreach (['gemini_api_key', 'gemini_model'] as $column) {
                if (Schema::hasColumn('ai_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
