<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration (phase 7): customer notifications on status changes,
 * the public storefront tracking page switch, return-risk thresholds, and
 * which statuses each shipment has already notified (never twice).
 */
return new class extends Migration
{
    private const SETTINGS = [
        'notify_sms', 'notify_email', 'notify_whatsapp', 'notify_statuses', 'notify_templates',
        'tracking_page', 'risk_high_percent', 'risk_min_shipments',
    ];

    public function up(): void
    {
        Schema::table('courier_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('courier_settings', 'notify_sms')) {
                $table->boolean('notify_sms')->default(false);
                $table->boolean('notify_email')->default(false);
                $table->boolean('notify_whatsapp')->default(false);
                $table->json('notify_statuses')->nullable();   // null = the default set
                $table->json('notify_templates')->nullable();  // {status: text}; missing = default text
                $table->boolean('tracking_page')->default(true);
                $table->unsignedTinyInteger('risk_high_percent')->default(30);
                $table->unsignedTinyInteger('risk_min_shipments')->default(3);
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'notified_statuses')) {
                $table->json('notified_statuses')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('courier_settings', function (Blueprint $table) {
            foreach (self::SETTINGS as $column) {
                if (Schema::hasColumn('courier_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'notified_statuses')) {
                $table->dropColumn('notified_statuses');
            }
        });
    }
};
