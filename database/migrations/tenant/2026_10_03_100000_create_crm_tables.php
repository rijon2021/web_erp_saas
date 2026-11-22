<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM module — phase 1: settings, lookups (lead sources, lead statuses,
     * lost reasons, tags), leads and their history.
     *
     * Leads are prospects that are NOT clients yet; converting one (phase 4)
     * creates / links an existing `clients` row. Owners are `users`.
     * No DB-level foreign keys, like the rest of the app.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crm_settings')) {
            Schema::create('crm_settings', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('lead_prefix', 10)->default('LD');
                $table->string('deal_prefix', 10)->default('DL');
                // New leads with no owner go to: the creator, or stay in the shared pool.
                $table->enum('default_owner', ['creator', 'pool'])->default('creator');
                // A lead with no activity for this many days counts as stale.
                $table->unsignedSmallInteger('stale_lead_days')->default(14);
                $table->boolean('duplicate_check')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_lead_sources')) {
            Schema::create('crm_lead_sources', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 120);
                $table->string('color', 16)->default('#6366f1');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_lead_statuses')) {
            Schema::create('crm_lead_statuses', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 120);
                $table->string('color', 16)->default('#6366f1');
                // open = still being worked; converted = became a client; lost = dropped.
                $table->enum('kind', ['open', 'converted', 'lost'])->default('open');
                $table->boolean('is_default')->default(false); // status of a new lead
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_lost_reasons')) {
            Schema::create('crm_lost_reasons', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 160);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_tags')) {
            Schema::create('crm_tags', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 80);
                $table->string('color', 16)->default('#64748b');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Tags on leads now, deals later (polymorphic).
        if (! Schema::hasTable('crm_taggables')) {
            Schema::create('crm_taggables', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('crm_tag_id')->index();
                $table->string('taggable_type', 40);
                $table->integer('taggable_id');
                $table->unique(['crm_tag_id', 'taggable_type', 'taggable_id'], 'crm_taggables_unique');
                $table->index(['taggable_type', 'taggable_id']);
            });
        }

        if (! Schema::hasTable('crm_leads')) {
            Schema::create('crm_leads', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('lead_number', 32)->unique();
                $table->enum('type', ['person', 'company'])->default('person');
                // Display / search name: "First Last", or the company name.
                $table->string('name', 191)->index();
                $table->string('first_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
                $table->string('company_name', 191)->nullable();
                $table->string('job_title', 120)->nullable();
                $table->string('email', 191)->nullable()->index();
                $table->string('phone', 50)->nullable();
                $table->string('phone_normalized', 20)->nullable()->index(); // last 9 digits, for duplicates
                $table->string('website', 191)->nullable();
                $table->string('industry', 120)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('country', 120)->nullable();
                $table->integer('crm_lead_source_id')->nullable()->index();
                $table->integer('crm_lead_status_id')->nullable()->index();
                $table->integer('owner_id')->nullable()->index();
                $table->enum('rating', ['cold', 'warm', 'hot'])->default('warm');
                $table->decimal('estimated_value', 14, 2)->default(0);
                $table->integer('score')->default(0);
                $table->text('description')->nullable();
                $table->integer('crm_lost_reason_id')->nullable();
                $table->string('lost_note', 1000)->nullable();
                $table->timestamp('lost_at')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->integer('client_id')->nullable()->index(); // set when converted
                $table->timestamp('last_activity_at')->nullable()->index();
                $table->integer('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_lead_events')) {
            Schema::create('crm_lead_events', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('crm_lead_id')->index();
                $table->string('type', 40);
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->integer('user_id')->nullable();
                $table->timestamps();
            });
        }

        $this->seedDefaults();
    }

    /** Sensible starting lookups; every one can be renamed or removed in CRM Settings. */
    private function seedDefaults(): void
    {
        $now = now();
        if (DB::table('crm_lead_statuses')->count() === 0) {
            $rows = [
                ['New', '#3b82f6', 'open', true],
                ['Contacted', '#8b5cf6', 'open', false],
                ['Qualified', '#f59e0b', 'open', false],
                ['Converted', '#16a34a', 'converted', false],
                ['Lost', '#dc2626', 'lost', false],
            ];
            foreach ($rows as $i => [$name, $color, $kind, $default]) {
                DB::table('crm_lead_statuses')->insert([
                    'name' => $name, 'color' => $color, 'kind' => $kind, 'is_default' => $default,
                    'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        if (DB::table('crm_lead_sources')->count() === 0) {
            $rows = [
                ['Website', '#0ea5e9'], ['Phone call', '#8b5cf6'], ['Walk-in', '#f59e0b'], ['Referral', '#16a34a'],
                ['Social media', '#ec4899'], ['Email campaign', '#6366f1'], ['Trade show', '#14b8a6'], ['Other', '#64748b'],
            ];
            foreach ($rows as $i => [$name, $color]) {
                DB::table('crm_lead_sources')->insert([
                    'name' => $name, 'color' => $color, 'sort_order' => $i + 1, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        if (DB::table('crm_lost_reasons')->count() === 0) {
            foreach (['Price too high', 'Chose a competitor', 'No budget', 'No response', 'Not a fit', 'Timing / not now'] as $i => $name) {
                DB::table('crm_lost_reasons')->insert([
                    'name' => $name, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['crm_lead_events', 'crm_leads', 'crm_taggables', 'crm_tags', 'crm_lost_reasons', 'crm_lead_statuses', 'crm_lead_sources', 'crm_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
