<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management, phase 7: table reservations and the walk-in waitlist.
 *
 * rst_reservations  a booking for a party at a date/time (wall clock in the
 *                   tenant's timezone), optionally on a table; booked →
 *                   confirmed → seated → completed, or cancelled / no_show
 * rst_waitlist      walk-ins waiting for a table: waiting → notified → seated, or left
 * rst_settings      default booking length, how early a table is held, when a
 *                   late party counts as a no-show, largest party taken
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rst_reservations')) {
            Schema::create('rst_reservations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('customer_name', 120);
                $table->string('customer_phone', 40)->nullable();
                $table->string('customer_email', 191)->nullable();
                $table->integer('client_id')->nullable();
                $table->unsignedSmallInteger('party_size');
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->unsignedInteger('table_id')->nullable();
                $table->unsignedInteger('area_id')->nullable(); // seating preference when no table is chosen yet
                $table->integer('warehouse_id')->nullable();
                $table->string('status', 16)->default('booked'); // booked | confirmed | seated | completed | cancelled | no_show
                $table->string('source', 16)->default('phone');  // phone | walk_in | online | other
                $table->string('note', 500)->nullable();
                $table->unsignedInteger('order_id')->nullable();
                $table->dateTime('seated_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->string('cancel_reason', 255)->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();

                $table->index('starts_at');
                $table->index(['table_id', 'starts_at']);
                $table->index('status');
            });
        }

        if (! Schema::hasTable('rst_waitlist')) {
            Schema::create('rst_waitlist', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('customer_name', 120);
                $table->string('customer_phone', 40)->nullable();
                $table->unsignedSmallInteger('party_size');
                $table->unsignedSmallInteger('quoted_minutes')->nullable();
                $table->unsignedInteger('area_id')->nullable();
                $table->integer('warehouse_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->string('status', 16)->default('waiting'); // waiting | notified | seated | left
                $table->unsignedInteger('table_id')->nullable();
                $table->dateTime('notified_at')->nullable();
                $table->dateTime('seated_at')->nullable();
                $table->dateTime('left_at')->nullable();
                $table->integer('added_by')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }

        if (Schema::hasTable('rst_settings')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('rst_settings', 'reservation_minutes')) {
                    $table->unsignedSmallInteger('reservation_minutes')->default(90);
                }
                if (! Schema::hasColumn('rst_settings', 'reservation_hold_minutes')) {
                    $table->unsignedSmallInteger('reservation_hold_minutes')->default(30);
                }
                if (! Schema::hasColumn('rst_settings', 'no_show_minutes')) {
                    $table->unsignedSmallInteger('no_show_minutes')->default(15);
                }
                if (! Schema::hasColumn('rst_settings', 'max_party_size')) {
                    $table->unsignedSmallInteger('max_party_size')->default(20);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rst_settings')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                foreach (['reservation_minutes', 'reservation_hold_minutes', 'no_show_minutes', 'max_party_size'] as $c) {
                    if (Schema::hasColumn('rst_settings', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        Schema::dropIfExists('rst_waitlist');
        Schema::dropIfExists('rst_reservations');
    }
};
