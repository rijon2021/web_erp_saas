<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commissions:
 *  - commission_programs.rule_mode: 'all' (every matching rule pays, the old
 *    behaviour, kept for existing programs) or 'highest_priority' (only the
 *    first qualifying rule by priority pays).
 *  - sale_commissions.is_adjustment: a correction row (top-up or claw-back)
 *    created when a sale changes after part of its commission was paid.
 *  - sale_commissions.rule_terms: the rule's type/source/value/threshold/cap
 *    when the commission was first earned, so later rule edits do not reprice
 *    old sales when they are recalculated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commission_programs', 'rule_mode')) {
            Schema::table('commission_programs', function (Blueprint $table) {
                $table->string('rule_mode', 32)->default('all')->after('is_active');
            });
        }

        Schema::table('sale_commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_commissions', 'is_adjustment')) {
                $table->boolean('is_adjustment')->default(false)->after('status');
            }
            if (! Schema::hasColumn('sale_commissions', 'rule_terms')) {
                $table->json('rule_terms')->nullable()->after('commission_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_commissions', function (Blueprint $table) {
            if (Schema::hasColumn('sale_commissions', 'rule_terms')) {
                $table->dropColumn('rule_terms');
            }
            if (Schema::hasColumn('sale_commissions', 'is_adjustment')) {
                $table->dropColumn('is_adjustment');
            }
        });

        if (Schema::hasColumn('commission_programs', 'rule_mode')) {
            Schema::table('commission_programs', function (Blueprint $table) {
                $table->dropColumn('rule_mode');
            });
        }
    }
};
