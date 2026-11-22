<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM phase 8 — marketing campaigns to CRM lead segments (audience on
 * segments, lead recipients, marketing opt-out) and client-portal requests
 * (deals shared with the customer, portal messages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_segments', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_segments', 'audience')) {
                $table->string('audience', 20)->default('customers')->after('description');
            }
        });
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_campaign_recipients', 'crm_lead_id')) {
                $table->unsignedBigInteger('crm_lead_id')->nullable()->after('client_id')->index();
            }
        });
        Schema::table('crm_leads', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_leads', 'marketing_opt_out')) {
                $table->boolean('marketing_opt_out')->default(false);
                $table->timestamp('marketing_opt_out_at')->nullable();
            }
        });
        Schema::table('crm_deals', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_deals', 'portal_visible')) {
                $table->boolean('portal_visible')->default(false);
            }
        });
        Schema::table('crm_messages', function (Blueprint $table) {
            // + 'portal' (messages exchanged in the client portal)
            $table->string('channel', 20)->change();
            if (! Schema::hasColumn('crm_messages', 'portal_read_at')) {
                $table->timestamp('portal_read_at')->nullable(); // customer has seen a staff reply
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', function (Blueprint $table) {
            if (Schema::hasColumn('crm_messages', 'portal_read_at')) {
                $table->dropColumn('portal_read_at');
            }
        });
        Schema::table('crm_deals', function (Blueprint $table) {
            if (Schema::hasColumn('crm_deals', 'portal_visible')) {
                $table->dropColumn('portal_visible');
            }
        });
        Schema::table('crm_leads', function (Blueprint $table) {
            if (Schema::hasColumn('crm_leads', 'marketing_opt_out')) {
                $table->dropColumn(['marketing_opt_out', 'marketing_opt_out_at']);
            }
        });
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            if (Schema::hasColumn('marketing_campaign_recipients', 'crm_lead_id')) {
                $table->dropIndex(['crm_lead_id']);
                $table->dropColumn('crm_lead_id');
            }
        });
        Schema::table('marketing_segments', function (Blueprint $table) {
            if (Schema::hasColumn('marketing_segments', 'audience')) {
                $table->dropColumn('audience');
            }
        });
    }
};
