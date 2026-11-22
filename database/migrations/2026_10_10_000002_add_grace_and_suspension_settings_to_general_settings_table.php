<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happens after a paid subscription runs out: an optional grace period
 * (full or read-only access), when an unpaid workspace is suspended, and when
 * a long-inactive one is deleted. The last two replace the hard-coded
 * defaults of the tenant:cleanup-inactive command (7 / 90 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_grace_days')) {
                $table->unsignedSmallInteger('subscription_grace_days')->default(0);
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'subscription_grace_mode')) {
                $table->string('subscription_grace_mode', 16)->default('full');
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'suspend_unpaid_after_days')) {
                $table->unsignedSmallInteger('suspend_unpaid_after_days')->default(7);
            }
            if (! Schema::connection('central')->hasColumn('general_settings', 'delete_inactive_after_days')) {
                $table->unsignedSmallInteger('delete_inactive_after_days')->default(90);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_grace_days',
                'subscription_grace_mode',
                'suspend_unpaid_after_days',
                'delete_inactive_after_days',
            ]);
        });
    }
};
