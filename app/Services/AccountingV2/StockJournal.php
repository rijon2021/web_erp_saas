<?php

namespace App\Services\AccountingV2;

use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use Illuminate\Support\Facades\DB;

/**
 * Inventory postings for stock documents that have no accounting events of
 * their own: stock adjustments (including product opening stock) and damages.
 *
 * Controllers call this from inside their transaction. An edit can change only
 * the detail lines, which never fires a model event on the header, so these
 * are explicit calls rather than observers. The posting runs after the commit,
 * once the lines are final, and only while automatic journals are on.
 */
class StockJournal
{
    use SkipsWhenManual;

    public static function adjustment($adjustment, bool $created = false, bool $opening = false): void
    {
        $id = (int) $adjustment->id;
        self::afterCommit(fn () => AccountingHelper::syncStockAdjustment($id, $created, $opening));
    }

    public static function damage($damage, bool $created = false): void
    {
        $id = (int) $damage->id;
        self::afterCommit(fn () => AccountingHelper::syncDamage($id, $created));
    }

    protected static function afterCommit(callable $post): void
    {
        if ((new static)->shouldSkipAutomation()) {
            return;
        }

        try {
            DB::connection()->afterCommit($post);
        } catch (\RuntimeException $e) {
            // No transactions manager on this connection; nothing to wait for.
            $post();
        }
    }
}
