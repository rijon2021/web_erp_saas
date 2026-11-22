<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management, phase 3: bookable staff and their availability.
 *
 * bk_staff           the people customers book with — an HRM employee, a user
 *                    account, or just a name. Soft-deleted so old bookings
 *                    keep their staff_id.
 * bk_staff_hours     weekly working hours, one row per ISO weekday
 *                    (1 = Monday … 7 = Sunday) with an optional break; no row
 *                    = day off. Times are wall-clock "HH:MM" in the tenant
 *                    timezone.
 * bk_staff_time_off  days off on top of approved HRM leave and company
 *                    holidays (both read live from HRM). from_time applies to
 *                    the first day, to_time to the last; NULL = whole day.
 * bk_service_staff   which staff do which service (product id). A service
 *                    with no rows here can be done by any active staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bk_staff')) {
            Schema::create('bk_staff', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('employee_id')->nullable()->index('bk_staff_employee_id');
                $table->integer('user_id')->nullable()->index('bk_staff_user_id');
                $table->string('name', 120);
                $table->string('title', 120)->nullable();
                $table->string('color', 7)->default('#6d28d9');
                $table->string('photo', 191)->nullable();
                $table->integer('warehouse_id')->nullable()->index('bk_staff_warehouse_id');
                $table->boolean('bookable_online')->default(true);
                $table->boolean('show_on_website')->default(true);
                $table->unsignedSmallInteger('max_per_day')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('bk_staff_hours')) {
            Schema::create('bk_staff_hours', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('staff_id');
                $table->unsignedTinyInteger('weekday');
                $table->char('start', 5);
                $table->char('end', 5);
                $table->char('break_start', 5)->nullable();
                $table->char('break_end', 5)->nullable();
                $table->timestamps();
                $table->unique(['staff_id', 'weekday'], 'bk_staff_hours_day');
            });
        }

        if (! Schema::hasTable('bk_staff_time_off')) {
            Schema::create('bk_staff_time_off', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedInteger('staff_id')->index('bk_staff_time_off_staff');
                $table->date('start_date');
                $table->date('end_date');
                $table->char('from_time', 5)->nullable();
                $table->char('to_time', 5)->nullable();
                $table->string('reason', 191)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bk_service_staff')) {
            Schema::create('bk_service_staff', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('product_id');
                $table->unsignedInteger('staff_id')->index('bk_service_staff_staff');
                $table->unique(['product_id', 'staff_id'], 'bk_service_staff_pair');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_service_staff');
        Schema::dropIfExists('bk_staff_time_off');
        Schema::dropIfExists('bk_staff_hours');
        Schema::dropIfExists('bk_staff');
    }
};
