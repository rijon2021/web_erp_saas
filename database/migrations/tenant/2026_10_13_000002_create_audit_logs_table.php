<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > Audit Log: who changed what, and when. One row per create /
 * update / delete of an audited record (App\Services\Audit\AuditRegistry),
 * plus sign-in events. Written by App\Services\Audit\AuditRecorder; nothing
 * else writes here. Pruned under the plan's audit_retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // created | updated | deleted | restored | login | logout | login_failed
            $table->string('event', 20);
            // Registry type key (sale, product, client…) — not a class name,
            // so renaming a model never orphans its history.
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Snapshot of the record's name/Ref at the time, so the row still
            // reads well after the record is deleted or renamed.
            $table->string('subject_label', 191)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            // Actor. user_name is a snapshot for the same reason as subject_label.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 191)->nullable();
            // Super admin acting through "Login as tenant", when that was the case.
            $table->string('impersonator', 191)->nullable();
            $table->string('channel', 10)->default('web'); // web | system
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('url', 255)->nullable();
            // Groups every entry written by one request / command run.
            $table->char('batch', 36)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
            $table->index('batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
