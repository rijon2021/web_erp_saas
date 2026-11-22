<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant: choose the provider (Claude or ChatGPT). Each provider keeps
 * its own encrypted key and model, so switching back and forth loses nothing.
 * `api_key` / `model` stay the Claude ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_settings', 'provider')) {
                $table->string('provider', 16)->default('anthropic')->after('enabled');
            }
            if (! Schema::hasColumn('ai_settings', 'openai_api_key')) {
                $table->text('openai_api_key')->nullable()->after('model'); // encrypted
            }
            if (! Schema::hasColumn('ai_settings', 'openai_model')) {
                $table->string('openai_model', 64)->default('gpt-6.1-sol')->after('openai_api_key');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            foreach (['provider', 'openai_api_key', 'openai_model'] as $column) {
                if (Schema::hasColumn('ai_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
