<?php

namespace App\Services\WooCommerce;

use App\Jobs\WooCommerceAutoSyncJob;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\WooCommerceSetting;
use App\Services\TenantLimitsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Pushing POS changes to WooCommerce as they happen, instead of waiting for
 * someone to press "Sync" on the WooCommerce page.
 *
 * Two opt-in switches on the WooCommerce settings:
 *
 *  - auto_sync_products: a product created, edited or duplicated in the POS
 *    is pushed (details, variants, images), followed by its stock.
 *  - auto_sync_stock: any stock movement — POS sale, return, purchase,
 *    adjustment, transfer — pushes the new quantity of the products involved.
 *
 * Same shape as Shopify\StockWatcher: changes are collected during the request
 * and sent once at the end, after the response, so a twenty-line sale costs
 * one job and the cashier never waits on the WooCommerce server. The job
 * re-reads stock when it runs, so it always sends the current level.
 */
class AutoSync
{
    /** Suppressed while a WooCommerce → POS pull is writing local data. */
    private static bool $muted = false;

    /** product_id => true */
    private static array $pendingProducts = [];

    /** product_id => true */
    private static array $pendingStock = [];

    private static bool $flushRegistered = false;

    /** Settings row when some auto-sync is on, null when off; false until looked up. */
    private static $settings = false;

    /**
     * Run something without auto-sync reacting to it. Used by the pulls: stock
     * written from WooCommerce data would otherwise bounce straight back out.
     */
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

    /** A product was created, edited or duplicated in the POS. */
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
        $productId = (int) $row->product_id;
        if (self::$muted || $productId <= 0 || ! self::enabled('auto_sync_stock')) {
            return;
        }

        self::$pendingStock[$productId] = true;
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

        // The plan may have dropped WooCommerce since the switch was turned on.
        try {
            if (! app(TenantLimitsService::class)->hasFeature('woocommerce')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        // A combo's stock is derived from its components, so a component that
        // moved changes every combo built from it.
        if ($stock) {
            try {
                $combos = DB::table('combined_products')
                    ->whereIn('combined_product_id', $stock)
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $stock = array_values(array_unique(array_merge($stock, $combos)));
            } catch (\Throwable $e) {
            }
        }

        // A pushed product has its stock sent with it; a product never linked
        // to WooCommerce has nowhere to send stock to.
        $stock = array_values(array_diff($stock, $products));
        if ($stock) {
            try {
                $stock = Product::whereIn('id', $stock)
                    ->whereNotNull('woocommerce_id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable $e) {
                $stock = [];
            }
        }

        // One job; it works through the ids in rounds itself.
        if ($products || $stock) {
            self::send(WooCommerceAutoSyncJob::dispatch($products, $stock));
        }
    }

    /** Forget everything — for tests, and for a worker between jobs. */
    public static function reset(): void
    {
        self::$pendingProducts = [];
        self::$pendingStock = [];
        self::$settings = false;
    }

    // ------------------------------------------------------------ internal --

    /**
     * In a web request the job runs after the response has been sent, in the
     * same process — no queue worker or cron needed. On the CLI and inside a
     * queued job there is no response, so it goes on the Woo stock queue that
     * the scheduler already drains.
     */
    private static function send($pending): void
    {
        app()->runningInConsole()
            ? $pending->onQueue('woocommerce-stock')
            : $pending->afterResponse();
    }

    private static function registerFlush(): void
    {
        if (self::$flushRegistered) {
            return;
        }
        self::$flushRegistered = true;

        // End of the request (or of the artisan command).
        app()->terminating(function () {
            self::flush();
        });

        // And after each queued job, because a worker process does not
        // terminate between them. The settings are dropped too: the next job
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
     * Read once per request. Guarded because this runs on every stock write in
     * the application, including during an install or update where the table
     * or the columns may not exist yet — a stock movement must never fail
     * because of it. A missing column reads as null, i.e. switched off.
     */
    private static function lookupSettings(): ?WooCommerceSetting
    {
        try {
            $settings = WooCommerceSetting::first();
            if (! $settings
                || empty($settings->store_url) || empty($settings->consumer_key) || empty($settings->consumer_secret)
                || (! $settings->auto_sync_products && ! $settings->auto_sync_stock)) {
                return null;
            }

            return $settings;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
