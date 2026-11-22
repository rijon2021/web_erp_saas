<?php

namespace App\Services\AccountingV2;

use App\Listeners\AccountingV2\Concerns\SkipsWhenManual;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-asset postings (purchase, monthly depreciation, disposal).
 *
 * Asset controllers call sync() after a write; `assets:post-depreciation`
 * calls syncAll() daily so depreciation keeps accruing with no user action.
 * Postings run after the surrounding transaction commits and only while
 * automatic journals are on, like every other AccountingV2 posting.
 */
class AssetJournal
{
    use SkipsWhenManual;

    public static function sync(int $assetId): void
    {
        self::afterCommit(fn () => AccountingHelper::syncAsset($assetId));
    }

    /** Every asset that is or ever was on the books. Returns how many were checked. */
    public static function syncAll(): int
    {
        if ((new static)->shouldSkipAutomation()) {
            return 0;
        }

        $count = 0;
        DB::table('assets')->orderBy('id')->select('id')->chunk(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                AccountingHelper::syncAsset((int) $row->id);
                $count++;
            }
        });

        return $count;
    }

    protected static function afterCommit(callable $post): void
    {
        if ((new static)->shouldSkipAutomation()) {
            return;
        }

        try {
            DB::connection()->afterCommit($post);
        } catch (\RuntimeException $e) {
            $post();
        }
    }
}
