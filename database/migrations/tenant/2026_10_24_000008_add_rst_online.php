<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant Management, phase 8: online ordering, QR table ordering and
 * online table booking.
 *
 * rst_orders.source / online_order_id  where an order came from (staff, the
 *                                      online store, a table's QR code)
 * rst_tables.qr_token                  secret in the table's QR link
 * online_order_items.options(_label)   add-ons chosen in the online store
 * rst_settings                         QR ordering + online booking switches
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rst_orders')) {
            Schema::table('rst_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('rst_orders', 'source')) {
                    $table->string('source', 10)->default('staff'); // staff | online | qr
                }
                if (! Schema::hasColumn('rst_orders', 'online_order_id')) {
                    $table->unsignedInteger('online_order_id')->nullable()->unique();
                }
            });
        }

        if (Schema::hasTable('rst_tables') && ! Schema::hasColumn('rst_tables', 'qr_token')) {
            Schema::table('rst_tables', function (Blueprint $table) {
                $table->string('qr_token', 40)->nullable()->unique();
            });
        }

        if (Schema::hasTable('online_order_items')) {
            Schema::table('online_order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('online_order_items', 'options')) {
                    $table->json('options')->nullable();
                }
                if (! Schema::hasColumn('online_order_items', 'options_label')) {
                    $table->string('options_label', 500)->nullable();
                }
            });
        }

        if (Schema::hasTable('rst_settings')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                $cols = [
                    'qr_ordering_enabled' => fn () => $table->boolean('qr_ordering_enabled')->default(false),
                    'qr_auto_send' => fn () => $table->boolean('qr_auto_send')->default(false),
                    'online_booking_enabled' => fn () => $table->boolean('online_booking_enabled')->default(false),
                    'booking_from' => fn () => $table->string('booking_from', 5)->default('12:00'),
                    'booking_to' => fn () => $table->string('booking_to', 5)->default('22:00'),
                    'booking_slot_minutes' => fn () => $table->unsignedSmallInteger('booking_slot_minutes')->default(30),
                    'online_max_party' => fn () => $table->unsignedSmallInteger('online_max_party')->default(8),
                    'booking_notice_minutes' => fn () => $table->unsignedSmallInteger('booking_notice_minutes')->default(60),
                    'booking_days_ahead' => fn () => $table->unsignedSmallInteger('booking_days_ahead')->default(30),
                ];
                foreach ($cols as $name => $add) {
                    if (! Schema::hasColumn('rst_settings', $name)) {
                        $add();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rst_settings')) {
            Schema::table('rst_settings', function (Blueprint $table) {
                foreach (['qr_ordering_enabled', 'qr_auto_send', 'online_booking_enabled', 'booking_from', 'booking_to', 'booking_slot_minutes', 'online_max_party', 'booking_notice_minutes', 'booking_days_ahead'] as $c) {
                    if (Schema::hasColumn('rst_settings', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('online_order_items')) {
            Schema::table('online_order_items', function (Blueprint $table) {
                foreach (['options', 'options_label'] as $c) {
                    if (Schema::hasColumn('online_order_items', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('rst_tables') && Schema::hasColumn('rst_tables', 'qr_token')) {
            Schema::table('rst_tables', function (Blueprint $table) {
                $table->dropUnique(['qr_token']);
                $table->dropColumn('qr_token');
            });
        }
        if (Schema::hasTable('rst_orders')) {
            Schema::table('rst_orders', function (Blueprint $table) {
                if (Schema::hasColumn('rst_orders', 'online_order_id')) {
                    $table->dropUnique(['online_order_id']);
                    $table->dropColumn('online_order_id');
                }
                if (Schema::hasColumn('rst_orders', 'source')) {
                    $table->dropColumn('source');
                }
            });
        }
    }
};
