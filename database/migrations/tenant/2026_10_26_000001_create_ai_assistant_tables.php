<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant (Claude): settings, chat conversations and the daily
 * dashboard insights.
 *
 * ai_messages.transcript keeps the exact API messages of each exchange
 * (assistant content blocks incl. thinking + tool calls/results). A
 * conversation is replayed to the API by concatenating these, unchanged and
 * append-only — Claude's thinking blocks are only valid in the history that
 * produced them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            Schema::create('ai_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->boolean('enabled')->default(false);
                $table->text('api_key')->nullable(); // encrypted
                $table->string('model', 64)->default('claude-opus-5-5');
                $table->string('effort', 16)->default('medium');
                $table->boolean('insights_enabled')->default(true);
                // Soft monthly cap on tokens (input + output) across all users; null = no cap.
                $table->unsignedBigInteger('monthly_token_limit')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index();
                $table->string('title', 191)->nullable();
                $table->unsignedBigInteger('input_tokens')->default(0);
                $table->unsignedBigInteger('output_tokens')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('conversation_id')->index();
                $table->text('question');
                $table->longText('answer')->nullable();
                $table->longText('transcript')->nullable(); // JSON list of API messages; null = failed turn, not replayed
                $table->text('tools_used')->nullable();     // JSON [{name, input}]
                $table->string('error', 500)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('ai_insights')) {
            Schema::create('ai_insights', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Insights depend on what the viewer may see (warehouses, own records only).
                $table->string('scope_key', 191);
                $table->date('insight_date');
                $table->longText('content'); // JSON {summary, insights:[{title, detail, tone}]}
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->unique(['scope_key', 'insight_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_settings');
    }
};
