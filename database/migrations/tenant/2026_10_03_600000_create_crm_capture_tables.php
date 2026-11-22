<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM phase 6 — lead capture & automation: public web forms, assignment
 * rules, lead scoring rules, where a lead was captured from, and the
 * capture / alert switches in crm_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_web_forms')) {
            Schema::create('crm_web_forms', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->string('token', 64)->unique();
                $table->string('title', 191)->nullable();
                $table->text('intro')->nullable();
                $table->json('fields')->nullable();
                $table->string('submit_label', 80)->nullable();
                $table->string('success_message', 500)->nullable();
                $table->string('redirect_url', 500)->nullable();
                $table->string('consent_text', 500)->nullable();
                $table->string('accent_color', 7)->nullable();
                $table->unsignedBigInteger('crm_lead_source_id')->nullable();
                $table->unsignedBigInteger('crm_lead_status_id')->nullable();
                $table->unsignedBigInteger('owner_id')->nullable(); // null = assignment rules
                $table->json('tag_ids')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('submissions')->default(0);
                $table->timestamp('last_submission_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_assignment_rules')) {
            Schema::create('crm_assignment_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('conditions')->nullable();
                $table->string('method', 20)->default('round_robin'); // user | round_robin
                $table->json('user_ids')->nullable();
                $table->unsignedBigInteger('last_user_id')->nullable();
                $table->unsignedInteger('assigned_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_scoring_rules')) {
            Schema::create('crm_scoring_rules', function (Blueprint $table) {
                $table->id();
                $table->string('criterion', 40);
                $table->string('value', 191)->nullable();
                $table->smallInteger('points')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });

            // A sensible starting model; every rule can be edited or removed.
            $now = now();
            $rows = [
                ['has_email', null, 10], ['has_phone', null, 10], ['has_company', null, 10],
                ['rating', 'hot', 25], ['rating', 'warm', 10],
                ['value_at_least', '1000', 15], ['has_open_deal', null, 15],
                ['recent_activity', '7', 10], ['has_reply', null, 10], ['no_activity', '30', -20],
            ];
            foreach ($rows as $i => [$criterion, $value, $points]) {
                DB::table('crm_scoring_rules')->insert([
                    'criterion' => $criterion, 'value' => $value, 'points' => $points, 'is_active' => true,
                    'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        Schema::table('crm_leads', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_leads', 'captured_via')) {
                $table->string('captured_via', 30)->nullable()->after('score')->index();
                $table->unsignedBigInteger('crm_web_form_id')->nullable()->after('captured_via');
                $table->unsignedBigInteger('capture_ref_id')->nullable()->after('crm_web_form_id');
            }
        });

        Schema::table('crm_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_settings', 'capture_store_messages')) {
                $table->boolean('capture_store_messages')->default(false);
                $table->boolean('capture_quote_requests')->default(false);
                $table->boolean('capture_property_inquiries')->default(false);
                $table->boolean('capture_portal_requests')->default(false);
                $table->boolean('capture_whatsapp_unknown')->default(false);
                $table->json('capture_sources')->nullable(); // channel => crm_lead_source_id
                $table->unsignedSmallInteger('capture_followup_hours')->nullable()->default(4);
                $table->boolean('capture_notify')->default(true);
                $table->boolean('scoring_enabled')->default(true);
                $table->boolean('stale_alerts')->default(true);
                $table->date('stale_alerts_sent_on')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_settings', function (Blueprint $table) {
            foreach (['capture_store_messages', 'capture_quote_requests', 'capture_property_inquiries', 'capture_portal_requests',
                'capture_whatsapp_unknown', 'capture_sources', 'capture_followup_hours', 'capture_notify', 'scoring_enabled',
                'stale_alerts', 'stale_alerts_sent_on'] as $col) {
                if (Schema::hasColumn('crm_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('crm_leads', function (Blueprint $table) {
            if (Schema::hasColumn('crm_leads', 'captured_via')) {
                $table->dropIndex(['captured_via']);
                $table->dropColumn(['captured_via', 'crm_web_form_id', 'capture_ref_id']);
            }
        });
        Schema::dropIfExists('crm_scoring_rules');
        Schema::dropIfExists('crm_assignment_rules');
        Schema::dropIfExists('crm_web_forms');
    }
};
