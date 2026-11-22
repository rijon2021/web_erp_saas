<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token usage of one-off AI features (product writer, …) so the monthly AI
 * token limit covers them too. Chat and insights keep their usage on their
 * own rows (ai_messages / ai_insights).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage')) {
            Schema::create('ai_usage', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('feature', 40);
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
