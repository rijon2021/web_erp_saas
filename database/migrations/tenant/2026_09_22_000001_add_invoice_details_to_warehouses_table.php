<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouses act as the issuing branch on every document they own (sale,
 * purchase, quotation, return, payment receipt, POS ticket...). The original
 * table only carried city/zip/country, which is not enough to print a real
 * letterhead, so add the missing address line, a per-branch logo and a
 * per-branch invoice footer note. All three are optional: anything left empty
 * falls back to the global company settings at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'address')) {
                $table->string('address', 500)->nullable()->after('name');
            }
            if (! Schema::hasColumn('warehouses', 'logo')) {
                $table->string('logo', 192)->nullable()->after('email');
            }
            if (! Schema::hasColumn('warehouses', 'invoice_footer')) {
                $table->text('invoice_footer')->nullable()->after('logo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            foreach (['address', 'logo', 'invoice_footer'] as $column) {
                if (Schema::hasColumn('warehouses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
