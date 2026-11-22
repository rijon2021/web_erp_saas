<?php

namespace App\Jobs;

use App\Models\OpencartLog;
use App\Models\OpencartSetting;
use App\Services\Opencart\AutoSync;
use App\Services\Opencart\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Pushes products and stock to OpenCart, dispatched by AutoSync. Works like
 * WooCommerceAutoSyncJob: ids join a per-store pending list and one job drains
 * it, so pushes for a store never run side by side, nothing waits on a lock,
 * and a store that stops answering pauses the pushes instead of slowing every
 * sale. The list survives the pause.
 */
class OpencartAutoSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ROUND = 50;
    private const RUN_LOCK_SECONDS = 600;
    private const PAUSE_SECONDS = 300;
    private const PENDING_TTL = 604800;

    public int $timeout = 1200;
    public int $tries = 1;

    /**
     * @param  int[]  $productIds  pushed in full, stock included
     * @param  int[]  $stockProductIds  stock quantities only
     */
    public function __construct(
        private array $productIds,
        private array $stockProductIds
    ) {
    }

    public function handle(): void
    {
        $settings = OpencartSetting::first();
        if (! $settings || ! $settings->enabled || ! $settings->isConfigured()) {
            return;
        }
        if (! $settings->auto_sync_products && ! $settings->auto_sync_stock) {
            return;
        }

        $cache = Cache::store('file');
        $key = 'opencart_auto_sync:'.sha1((tenant('id') ?? 'central').'|'.$settings->store_url);

        try {
            $this->addPending($cache, $key, $this->productIds, $this->stockProductIds);

            while (! $cache->has($key.':paused') && $this->hasPending($cache, $key)) {
                $lock = $cache->lock($key.':run', self::RUN_LOCK_SECONDS);
                if (! $lock->get()) {
                    return; // the running job picks our ids up
                }

                try {
                    [$products, $stock] = $this->takePending($cache, $key);
                    if (! $products && ! $stock) {
                        continue;
                    }

                    if (! AutoSync::muted(fn () => $this->push($settings, $products, $stock))) {
                        $this->addPending($cache, $key, $products, $stock);
                        $cache->put($key.':paused', true, self::PAUSE_SECONDS);
                        $this->log('warning', 'Auto-sync paused for '.(self::PAUSE_SECONDS / 60).' minutes: the store is not responding. Waiting changes go out after the pause.');
                    }
                } finally {
                    $lock->release();
                }
            }
        } catch (LockTimeoutException $e) {
            $this->log('warning', 'Auto-sync skipped: the pending list was busy');
        }
    }

    /** Returns false only when the store itself is what failed. */
    private function push(OpencartSetting $settings, array $productIds, array $stockIds): bool
    {
        $sync = new SyncService($settings);
        $productIds = $settings->auto_sync_products ? $productIds : [];
        $stockIds = $settings->auto_sync_stock ? $stockIds : [];

        try {
            if ($productIds) {
                $sync->pushProducts(0, count($productIds), $productIds);
            }
            if ($stockIds) {
                $sync->pushStockFor($stockIds);
            }

            return true;
        } catch (\Throwable $e) {
            $this->log('error', 'Auto-sync failed: '.$e->getMessage());

            try {
                $sync->client()->call('ping', [], 10);

                return true; // the store answers, so a retry would fail the same way
            } catch (\Throwable $ping) {
                return false;
            }
        }
    }

    private function addPending(Repository $cache, string $key, array $products, array $stock): void
    {
        $this->withPending($cache, $key, function (array $pending) use ($products, $stock) {
            foreach ($products as $id) {
                $pending['products'][(int) $id] = true;
            }
            foreach ($stock as $id) {
                $pending['stock'][(int) $id] = true;
            }

            return [$pending, null];
        });
    }

    /** Remove and return up to one round of ids: [productIds, stockIds]. */
    private function takePending(Repository $cache, string $key): array
    {
        return $this->withPending($cache, $key, function (array $pending) {
            $products = array_slice(array_keys($pending['products']), 0, self::ROUND);
            $stock = array_slice(array_keys($pending['stock']), 0, self::ROUND);
            foreach ($products as $id) {
                unset($pending['products'][$id]);
            }
            foreach ($stock as $id) {
                unset($pending['stock'][$id]);
            }

            return [$pending, [$products, $stock]];
        });
    }

    private function hasPending(Repository $cache, string $key): bool
    {
        $pending = $cache->get($key.':pending');

        return is_array($pending) && (! empty($pending['products']) || ! empty($pending['stock']));
    }

    /** Read-modify-write of the pending list under a short mutex. */
    private function withPending(Repository $cache, string $key, callable $callback)
    {
        return $cache->lock($key.':mutex', 10)->block(5, function () use ($cache, $key, $callback) {
            $pending = $cache->get($key.':pending');
            $pending = (is_array($pending) ? $pending : []) + ['products' => [], 'stock' => []];

            [$pending, $result] = $callback($pending);

            if ($pending['products'] || $pending['stock']) {
                $cache->put($key.':pending', $pending, self::PENDING_TTL);
            } else {
                $cache->forget($key.':pending');
            }

            return $result;
        });
    }

    private function log(string $level, string $message): void
    {
        try {
            OpencartLog::create(['action' => 'auto_sync', 'level' => $level, 'message' => $message, 'context' => [
                'product_ids' => $this->productIds,
                'stock_product_ids' => $this->stockProductIds,
            ]]);
        } catch (\Throwable $e) {
        }
    }
}
