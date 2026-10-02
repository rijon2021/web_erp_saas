<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in rule: a payment must name the cash/bank account it landed in.
 *
 * The accounts module is optional, so account_id is nullable throughout the
 * payments layer and a payment without one is recorded correctly — it simply
 * belongs to no account and appears in no account statement. For a merchant who
 * DOES run tills and bank accounts, though, a blank account means an
 * unattributed payment they have to chase later.
 *
 * Defaults to false so nothing changes for anyone not using the module; a
 * merchant who is can switch it on under Settings > Features.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'require_payment_account')) {
                $table->boolean('require_payment_account')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'require_payment_account')) {
                $table->dropColumn('require_payment_account');
            }
        });
    }
};
