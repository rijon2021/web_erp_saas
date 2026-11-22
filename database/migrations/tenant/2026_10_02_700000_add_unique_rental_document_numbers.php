<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Management: document numbers (RNT-0001, RSV-…, RINV-…, RPAY-…,
     * RMT-…) become unique — a safety net behind the numbering lock, so two
     * simultaneous saves can never share a number. Skipped for a column that
     * already holds duplicates (fix those first, then re-run).
     */
    private const COLUMNS = [
        'rental_contracts' => 'contract_number',
        'rental_reservations' => 'reservation_number',
        'rental_invoices' => 'invoice_number',
        'rental_payments' => 'payment_number',
        'rental_maintenance' => 'number',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || $this->hasIndex($table, "{$table}_{$column}_unique")) {
                continue;
            }
            $dupes = DB::table($table)->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists();
            if ($dupes) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                if ($this->hasIndex($table, "{$table}_{$column}_index")) {
                    $t->dropIndex("{$table}_{$column}_index");
                }
                $t->unique($column);
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || ! $this->hasIndex($table, "{$table}_{$column}_unique")) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropUnique([$column]);
                $t->index($column);
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);
    }
};
