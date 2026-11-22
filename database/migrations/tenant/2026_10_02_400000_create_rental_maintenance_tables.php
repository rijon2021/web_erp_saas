<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management — phase 7: maintenance and expenses.
     *
     *  - rental_maintenance: jobs on a rental item. A scheduled or in-progress
     *    job blocks the item over its window (see RentalContractService::conflicts).
     *  - rental_maintenance_schedules: preventive plans (every N days/weeks/
     *    months/years and/or every N meter units) that open jobs ahead of time.
     *  - rental_expenses: money spent on rental items (or the rental business
     *    in general), journaled as Rental Expenses.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rental_maintenance_schedules')) {
            Schema::create('rental_maintenance_schedules', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_item_id')->index();
                $table->string('title', 191);
                $table->enum('type', ['service', 'inspection', 'cleaning', 'repair', 'other'])->default('service');
                $table->unsignedSmallInteger('interval_value')->nullable();
                $table->enum('interval_unit', ['day', 'week', 'month', 'year'])->nullable();
                $table->decimal('meter_interval', 14, 2)->nullable();
                $table->date('last_done_at')->nullable();
                $table->decimal('last_meter', 14, 2)->nullable();
                $table->date('next_due_date')->nullable()->index();
                $table->decimal('next_due_meter', 14, 2)->nullable();
                $table->unsignedSmallInteger('lead_days')->default(7);
                $table->unsignedSmallInteger('duration_hours')->default(24); // how long the item is blocked
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_maintenance')) {
            Schema::create('rental_maintenance', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('number', 32)->index();
                $table->unsignedBigInteger('rental_item_id')->index();
                $table->unsignedInteger('quantity')->default(1); // units blocked (pooled items)
                $table->string('title', 191);
                $table->enum('type', ['repair', 'service', 'inspection', 'cleaning', 'other'])->default('repair');
                $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();
                $table->enum('status', ['open', 'scheduled', 'in_progress', 'completed', 'cancelled'])->default('open')->index();
                $table->enum('source', ['manual', 'return', 'schedule'])->default('manual');
                $table->unsignedBigInteger('rental_contract_id')->nullable()->index();
                $table->unsignedBigInteger('rental_handover_id')->nullable();
                $table->unsignedBigInteger('schedule_id')->nullable()->index();
                $table->dateTime('scheduled_start')->nullable()->index();
                $table->dateTime('scheduled_end')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->integer('employee_id')->nullable()->index();
                $table->string('vendor', 191)->nullable();
                $table->decimal('cost', 14, 2)->default(0);
                $table->decimal('meter_reading', 14, 2)->nullable();
                $table->text('description')->nullable();
                $table->text('resolution')->nullable();
                $table->json('photos')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rental_expenses')) {
            Schema::create('rental_expenses', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rental_item_id')->nullable()->index();
                $table->unsignedBigInteger('rental_maintenance_id')->nullable()->index();
                $table->enum('category', ['maintenance', 'repair', 'utilities', 'insurance', 'tax', 'cleaning', 'fuel', 'fees', 'other'])->default('other')->index();
                $table->decimal('amount', 14, 2);
                $table->date('expense_date')->index();
                $table->integer('payment_method_id')->nullable();
                $table->integer('account_id')->nullable();
                $table->string('vendor', 191)->nullable();
                $table->string('reference', 191)->nullable();
                $table->text('description')->nullable();
                $table->string('attachment', 191)->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_expenses');
        Schema::dropIfExists('rental_maintenance');
        Schema::dropIfExists('rental_maintenance_schedules');
    }
};
