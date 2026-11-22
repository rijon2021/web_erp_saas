<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Before 2026-11-06 the Salla, Jumia and PrestaShop imports took stock for
 * every order whatever status they gave the sale, so a sale they left as
 * `ordered` or `pending` has had its stock moved. The imports now keep the
 * rule the sale screens rely on (stock has moved exactly while a sale is
 * completed), and a status update or cancellation acts on that rule: such a
 * sale would have its stock taken a second time on delivery, and never
 * restored on cancellation.
 *
 * Marking those sales completed records what already happened to their stock.
 * Only sales still linked to a shop order are touched; a sale someone
 * re-edited by hand since is left alone by the status filter. Safe to run
 * twice: a completed sale is not matched again.
 */
return new class extends Migration
{
    private const MAPPINGS = ['salla_mappings', 'jumia_mappings', 'prestashop_mappings'];

    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        foreach (self::MAPPINGS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $saleIds = DB::table($table)->where('entity_type', 'order')->pluck('local_id');

            foreach ($saleIds->chunk(500) as $chunk) {
                DB::table('sales')
                    ->whereIn('id', $chunk)
                    ->whereNull('deleted_at')
                    ->whereIn('statut', ['ordered', 'pending'])
                    ->update(['statut' => 'completed']);
            }
        }
    }

    /** Nothing to undo: the stock really had moved. */
    public function down(): void
    {
    }
};
