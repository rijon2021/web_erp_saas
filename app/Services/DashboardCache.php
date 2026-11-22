<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache of the dashboard payload (DashboardController).
 *
 * The key covers everything the payload is scoped by: tenant, user, the user's
 * record_view flag and assigned warehouses, the chosen warehouse and dates —
 * so revoking a user's access never serves them the old scope.
 *
 * Each entry also carries the tenant's data version, bumped whenever a
 * document the dashboard sums is written (AppServiceProvider). A cashier who
 * finishes a sale and opens the dashboard sees it at once; the TTL only saves
 * repeat loads while nothing changed. The version sits in the value, not the
 * key, so a bump overwrites the entry instead of leaving an orphan behind.
 */
class DashboardCache
{
    public const TTL_SECONDS = 60;

    private const VERSION_KEY = 'dashboard_data:version';

    /** Documents whose writes change dashboard figures. */
    public const MODELS = [
        \App\Models\Sale::class,
        \App\Models\Purchase::class,
        \App\Models\SaleReturn::class,
        \App\Models\PurchaseReturn::class,
        \App\Models\PaymentSale::class,
        \App\Models\PaymentPurchase::class,
        \App\Models\PaymentSaleReturns::class,
        \App\Models\PaymentPurchaseReturns::class,
        \App\Models\Expense::class,
        \App\Models\Deposit::class,
        \App\Models\Adjustment::class,
        \App\Models\Transfer::class,
        \App\Models\ServiceJob::class,
    ];

    public static function key(int $userId, bool $recordView, array $warehouseIds, int $warehouseId, ?string $from, ?string $to): string
    {
        $ids = array_map('intval', $warehouseIds);
        sort($ids);

        return 'dashboard_data:'.implode(':', [
            tenant('id'), $userId, (int) $recordView, sha1(implode(',', $ids)), $warehouseId, (string) $from, (string) $to,
        ]);
    }

    /** @param callable(): string $build */
    public static function remember(string $key, callable $build): string
    {
        $version = Cache::get(self::VERSION_KEY, 0);
        $cached = Cache::get($key);

        if (is_array($cached) && ($cached['v'] ?? null) === $version && is_string($cached['json'] ?? null)) {
            return $cached['json'];
        }

        $json = $build();
        Cache::put($key, ['v' => $version, 'json' => $json], self::TTL_SECONDS);

        return $json;
    }

    /** Invalidate every cached dashboard of the current tenant. */
    public static function bump(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, hrtime(true));
        } catch (\Throwable) {
            // A cache hiccup must never fail the sale that triggered it.
        }
    }
}
