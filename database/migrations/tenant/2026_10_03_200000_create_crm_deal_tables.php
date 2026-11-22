<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM module — phase 2: pipelines, their stages, deals, deal products and
     * deal history. A deal belongs to a lead OR an existing client (or both,
     * once the lead is converted). Won / lost is the deal's status; stages are
     * the open steps of a pipeline.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crm_pipelines')) {
            Schema::create('crm_pipelines', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('name', 120);
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_pipeline_stages')) {
            Schema::create('crm_pipeline_stages', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('crm_pipeline_id')->index();
                $table->string('name', 120);
                $table->string('color', 16)->default('#6366f1');
                $table->unsignedTinyInteger('probability')->default(10); // % chance to win from here
                $table->unsignedSmallInteger('rot_days')->nullable();      // flag deals idle longer than this
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_deals')) {
            Schema::create('crm_deals', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('deal_number', 32)->unique();
                $table->string('title', 191)->index();
                $table->integer('crm_pipeline_id')->index();
                $table->integer('crm_pipeline_stage_id')->index();
                $table->enum('status', ['open', 'won', 'lost'])->default('open')->index();
                $table->integer('crm_lead_id')->nullable()->index();
                $table->integer('client_id')->nullable()->index();
                $table->integer('owner_id')->nullable()->index();
                $table->decimal('value', 14, 2)->default(0);
                // Value comes from the product lines when there are any.
                $table->boolean('value_from_products')->default(false);
                $table->date('expected_close_date')->nullable()->index();
                $table->unsignedInteger('position')->default(0); // order inside a board column
                $table->timestamp('stage_entered_at')->nullable();
                $table->timestamp('won_at')->nullable();
                $table->timestamp('lost_at')->nullable();
                $table->integer('crm_lost_reason_id')->nullable();
                $table->string('lost_note', 1000)->nullable();
                $table->text('description')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('crm_deal_products')) {
            Schema::create('crm_deal_products', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('crm_deal_id')->index();
                $table->integer('product_id')->nullable();
                $table->integer('product_variant_id')->nullable();
                $table->string('name', 191);
                $table->string('code', 191)->nullable();
                $table->decimal('quantity', 14, 3)->default(1);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('tax_percent', 5, 2)->default(0);
                $table->unsignedTinyInteger('tax_method')->default(1); // 1 = exclusive, 2 = inclusive (as products)
                $table->decimal('total', 14, 2)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_deal_events')) {
            Schema::create('crm_deal_events', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('crm_deal_id')->index();
                $table->string('type', 40);
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->integer('user_id')->nullable();
                $table->timestamps();
            });
        }

        if (DB::table('crm_pipelines')->count() === 0) {
            $now = now();
            $id = DB::table('crm_pipelines')->insertGetId([
                'name' => 'Sales', 'is_default' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $stages = [['Qualification', '#3b82f6', 10], ['Needs analysis', '#8b5cf6', 25], ['Proposal sent', '#f59e0b', 50], ['Negotiation', '#f97316', 75]];
            foreach ($stages as $i => [$name, $color, $p]) {
                DB::table('crm_pipeline_stages')->insert([
                    'crm_pipeline_id' => $id, 'name' => $name, 'color' => $color, 'probability' => $p,
                    'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['crm_deal_events', 'crm_deal_products', 'crm_deals', 'crm_pipeline_stages', 'crm_pipelines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
