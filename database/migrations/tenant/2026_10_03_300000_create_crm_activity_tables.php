<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM module — phase 3: activities and tasks.
     *
     * One table for both: an activity is `done` (a call made, a note, a
     * meeting held) or `open` (planned, with a due date — a task, a call to
     * make). It hangs off a lead, a deal and/or a client; logging on a deal
     * also stores the deal's lead / client so every timeline shows it.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crm_activities')) {
            Schema::create('crm_activities', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->enum('type', ['call', 'meeting', 'email', 'note', 'task'])->default('task')->index();
                $table->string('subject', 191);
                $table->text('description')->nullable();
                $table->enum('status', ['open', 'done'])->default('open')->index();
                $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
                $table->timestamp('due_at')->nullable()->index();
                $table->unsignedSmallInteger('duration_minutes')->nullable();
                $table->string('location', 191)->nullable();
                // Calls: connected, no_answer, voicemail, busy, wrong_number.
                $table->string('outcome', 30)->nullable();
                $table->timestamp('done_at')->nullable()->index();
                $table->integer('crm_lead_id')->nullable()->index();
                $table->integer('crm_deal_id')->nullable()->index();
                $table->integer('client_id')->nullable()->index();
                $table->integer('owner_id')->nullable()->index(); // who does it
                $table->unsignedSmallInteger('reminder_minutes')->nullable(); // remind this long before due_at
                $table->timestamp('reminded_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('crm_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_settings', 'default_reminder_minutes')) {
                $table->unsignedSmallInteger('default_reminder_minutes')->nullable()->default(15);
            }
            if (! Schema::hasColumn('crm_settings', 'reminder_email')) {
                $table->boolean('reminder_email')->default(true);
            }
            if (! Schema::hasColumn('crm_settings', 'reminder_push')) {
                $table->boolean('reminder_push')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::table('crm_settings', function (Blueprint $table) {
            foreach (['default_reminder_minutes', 'reminder_email', 'reminder_push'] as $col) {
                if (Schema::hasColumn('crm_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
