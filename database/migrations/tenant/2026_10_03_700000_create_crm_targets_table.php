<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM phase 7 — sales targets (per rep or for the whole team, per month or
 * quarter) and the weekly summary email switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_targets')) {
            Schema::create('crm_targets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable(); // null = whole team
                $table->string('period_type', 10);                  // month | quarter
                $table->date('period_start');
                $table->string('metric', 30);                       // see CrmTarget::METRICS
                $table->decimal('target_value', 15, 2)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'period_type', 'period_start', 'metric'], 'crm_targets_unique');
                $table->index(['period_type', 'period_start']);
            });
        }

        Schema::table('crm_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_settings', 'weekly_report')) {
                $table->boolean('weekly_report')->default(false);
                $table->date('weekly_report_sent_on')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_settings', function (Blueprint $table) {
            if (Schema::hasColumn('crm_settings', 'weekly_report')) {
                $table->dropColumn(['weekly_report', 'weekly_report_sent_on']);
            }
        });
        Schema::dropIfExists('crm_targets');
    }
};
