<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_settings', 'guest_checkout_enabled')) {
            Schema::table('store_settings', function (Blueprint $table) {
                // Off by default: shoppers must sign in to check out until the admin opts in.
                $table->boolean('guest_checkout_enabled')->default(false)->after('require_admin_approval');
            });
        }

        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'is_guest')) {
                $table->boolean('is_guest')->default(false)->after('client_id');
            }
            if (! Schema::hasColumn('online_orders', 'guest_token')) {
                // Secret for the guest's order page link (/order/{token}).
                $table->string('guest_token', 64)->nullable()->unique()->after('is_guest');
            }
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (Schema::hasColumn('online_orders', 'guest_token')) {
                $table->dropUnique(['guest_token']);
                $table->dropColumn('guest_token');
            }
            if (Schema::hasColumn('online_orders', 'is_guest')) {
                $table->dropColumn('is_guest');
            }
        });

        if (Schema::hasColumn('store_settings', 'guest_checkout_enabled')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('guest_checkout_enabled');
            });
        }
    }
};
