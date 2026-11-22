<?php

namespace App\Services\Opencart;

use App\Jobs\OpencartAutoSyncJob;
use App\Models\OpencartMapping;
use App\Models\OpencartSetting;
use App\Models\product_warehouse;
use App\Services\ComboStockService;
use App\Services\TenantLimitsService;
use Illuminate\Support\Facades\Queue;

/**
 * Pushing POS changes to OpenCart as they happen. Same shape as the
 * WooCommerce module's AutoSync: changes are collected during the request and
 * sent once at the end, after the response, so a twenty-line sale costs one
 * job and the cashier never waits on the shop.
 *
 *  - auto_sync_products: a product created, edited or duplicated is pushed.
 *  - auto_sync_stock: any stock movement pushes the new quantity.
 */
class AutoSync
{
    /** Suppressed while an OpenCart pull is writing local data. */
    private static bool $muted = false;

    /** product_id => true */
    private static array $pendingProducts = [];

    /** product_id => true */
    private static array $pendingStock = [];

    private static bool $flushRegistered = false;

    /** Settings row when some auto-sync is on, null when off; false until looked up. */
    private static $settings = false;

    /** Run something without auto-sync reacting to it. */
    public static function muted(callable $callback)
    {
        $was = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $was;
        }
    }

    public static function productSaved($productId): void
    {
        $productId = (int) $productId;
        if (self::$muted || $productId <= 0 || ! self::enabled('auto_sync_products')) {
            return;
        }

        self::$pendingProducts[$productId] = true;
        self::registerFlush();
    }

    /** Called by the product_warehouse observer when a quantity changes. */
    public static function stockChanged(product_warehouse $row): void
    {
        self::stockTouched([(int) $row->product_id]);
    }

    /**
     * These products' figures may be wrong in the shop. Separate from a local
     * stock movement because an OpenCart order changes OpenCart's quantities
     * even when nothing moved here.
     *
     * @param  int[]  $productIds
     */
    public static function stockTouched(array $productIds): void
    {
        if (self::$muted || ! $productIds || ! self::enabled('auto_sync_stock')) {
            return;
        }

        foreach ($productIds as $productId) {
            if ((int) $productId > 0) {
                self::$pendingStock[(int) $productId] = true;
            }
        }

        self::registerFlush();
    }

    /** Send what has piled up. Called at the end of the request. */
    public static function flush(): void
    {
        $products = array_keys(self::$pendingProducts);
        $stock = array_keys(self::$pendingStock);
        self::$pendingProducts = [];
        self::$pendingStock = [];

        if (! $products && ! $stock) {
            return;
        }

        // The plan may have dropped OpenCart since the switch was turned on.
        try {
            if (! (new TenantLimitsService)->hasFeature('opencart')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        if ($stock) {
            try {
                // A combo's availability follows its components both ways.
                $stock = array_values(array_unique(array_merge($stock, app(ComboStockService::class)->combosUsing($stock))));
            } catch (\Throwable $e) {
            }

            // A pushed product has its stock sent with it; an unlinked product
            // has nowhere to send stock to.
            $stock = array_values(array_diff($stock, $products));
            try {
                $stock = OpencartMapping::where('entity_type', OpencartMapping::TYPE_PRODUCT)
                    ->whereIn('local_id', $stock)
                    ->pluck('local_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable $e) {
                $stock = [];
            }
        }

        if ($products || $stock) {
            self::send(OpencartAutoSyncJob::dispatch($products, $stock));
        }
    }

    /** Forget everything: for tests, and for a worker between tenants. */
    public static function reset(): void
    {
        self::$pendingProducts = [];
        self::$pendingStock = [];
        self::$settings = false;
    }

    // ------------------------------------------------------------ internal --

    /**
     * After the response in a web request. On the CLI and inside a queued job
     * there is no response, so it goes on the queue the scheduler drains.
     */
    private static function send($pending): void
    {
        app()->runningInConsole()
            ? $pending->onQueue('opencart-stock')
            : $pending->afterResponse();
    }

    private static function registerFlush(): void
    {
        if (self::$flushRegistered) {
            return;
        }
        self::$flushRegistered = true;

        app()->terminating(function () {
            self::flush();
        });

        // A worker process does not terminate between jobs, and the next job
        // may belong to another tenant.
        Queue::after(function () {
            self::flush();
            self::$settings = false;
        });
    }

    private static function enabled(string $flag): bool
    {
        if (self::$settings === false) {
            self::$settings = self::lookupSettings();
        }

        return self::$settings !== null && (bool) self::$settings->{$flag};
    }

    /**
     * Read once per request, and guarded: this runs on every stock write in
     * the application, including during an install or update where the table
     * may not exist yet, and a stock movement must never fail because of it.
     */
    private static function lookupSettings(): ?OpencartSetting
    {
        try {
            $settings = OpencartSetting::first();
            if (! $settings || ! $settings->enabled || ! $settings->isConfigured()
                || (! $settings->auto_sync_products && ! $settings->auto_sync_stock)) {
                return null;
            }

            return $settings;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
