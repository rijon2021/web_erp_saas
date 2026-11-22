<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifyLog;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncRun;
use App\Services\Shopify\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Work one slice of a Shopify sync run in the background, then queue the next.
 *
 * A sync started from the Sync Centre no longer runs inside the web request —
 * hosting that ends a request after a minute or two (LiteSpeed, nginx, a load
 * balancer) killed big ones part-way. The run is created paused and this job
 * carries it on from its cursor, SLICE_SECONDS at a time, re-queueing itself
 * until it is done. Slices rather than one long job so a cancel is noticed
 * promptly, other shops' runs get a turn on the worker, and a worker started
 * by cron with --max-time hands over cleanly.
 *
 * It competes for each slice with the page that started the run: whichever
 * claims the paused run first (ShopifySyncRun::claimForResume, atomic) works
 * it, and the other finds nothing to do. So with no queue worker at all the
 * page still sees the run through, and with the page closed the worker does.
 */
class ShopifySyncJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The queue these run on; Kernel drains it every minute. */
    public const QUEUE = 'shopify';

    /** How long one job works before handing on to the next. */
    public const SLICE_SECONDS = 45;

    /** A failed slice fails its run; the next start carries on from the cursor. */
    public int $tries = 1;

    /**
     * Well past the slice: a slice only stops between records, and one product
     * with a large gallery can spend a while on its last picture.
     */
    public int $timeout = 600;

    /**
     * One pending job per run. If no worker ever picks it up the lock lapses,
     * so a run is never left unable to queue its next slice.
     */
    public int $uniqueFor = 900;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    /**
     * Queue the next slice of a paused run, if there is a worker to run it.
     *
     * On the 'sync' connection "dispatching" would run every slice inline in
     * the current request, back to back — the very thing this exists to
     * avoid — so there it does nothing and the page carries the run on.
     */
    public static function queueFor(ShopifySyncRun $run): bool
    {
        if (! $run->isPaused() || config('queue.default') === 'sync') {
            return false;
        }

        try {
            static::dispatch($run->id)->onQueue(self::QUEUE);

            return true;
        } catch (\Throwable $e) {
            // No queue to write to: the page carries the run on instead.
            return false;
        }
    }

    public function handle(): void
    {
        $run = ShopifySyncRun::find($this->runId);

        // Finished, cancelled, or another worker or the page has this slice.
        if (! $run || ! $run->isPaused() || ! $run->claimForResume()) {
            return;
        }

        $store = ShopifyStore::whereNull('deleted_at')->find($run->store_id);
        if (! $store || $store->status !== 'connected') {
            $reason = $store ? 'The store was disconnected before the sync finished.' : 'The store was deleted before the sync finished.';
            $run->update(['status' => 'failed', 'finished_at' => now(), 'last_error' => $reason]);
            ShopifyLog::write($run->store_id, $run->entity.'.'.$run->direction, $reason, 'error', [], $run->id, $run->entity);

            return;
        }

        @ini_set('memory_limit', '512M');

        $run = SyncService::for($store)->resume($run, ['time_budget' => self::SLICE_SECONDS]);

        if ($run->isPaused()) {
            self::queueFor($run);
        }
    }
}
