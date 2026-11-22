<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register discount guard rails:
 *  - a role may carry max_discount_percent: the most a cashier of that role
 *    may take off a sale, line discounts and the order discount together, as
 *    a share of the sale's price before discounts (null = no limit);
 *  - no line may end below its product's minimum price once its share of the
 *    order discount is taken off too (the POS already checked line prices,
 *    not what the order discount did to them).
 * Loyalty points and automatic promotions are not the cashier's discount and
 * are left out. The POS applies the same rules before payment.
 */
class PosDiscountLimit
{
    public static function enabled(): bool
    {
        return Schema::hasColumn('roles', 'max_discount_percent');
    }

    /** The user's limit in percent, or null when unlimited. */
    public static function limitFor(?User $user): ?float
    {
        if (! $user || ! self::enabled()) {
            return null;
        }
        $limit = $user->roles()->first()?->max_discount_percent;

        return $limit === null || $limit === '' ? null : (float) $limit;
    }

    /**
     * Gross (price × qty), the cashier's line + order discount on it, and the
     * share of each line's net that the order discount takes.
     */
    public static function breakdown(array $details, $orderDiscount, $orderMethod): array
    {
        // The POS's own basis: line totals tax included (exclusive tax on top
        // of the discounted price, inclusive tax inside it), and the order
        // discount taken off that cart total — so a fixed amount is judged
        // against what the customer would pay, as the register applies it.
        $gross = 0.0;
        $afterLines = 0.0;
        foreach ($details as $d) {
            $price = (float) ($d['Unit_price'] ?? 0);
            $qty = (float) ($d['quantity'] ?? 0);
            $net = (string) ($d['discount_Method'] ?? '2') === '2'
                ? (float) ($d['discount'] ?? 0)
                : $price * (float) ($d['discount'] ?? 0) / 100;
            $taxFactor = (string) ($d['tax_method'] ?? '1') === '1' ? 1 + (float) ($d['tax_percent'] ?? 0) / 100 : 1.0;
            $gross += $price * $taxFactor * $qty;
            $afterLines += max($price - $net, 0) * $taxFactor * $qty;
        }
        $order = (string) $orderMethod === '1'
            ? $afterLines * (float) $orderDiscount / 100
            : min((float) $orderDiscount, $afterLines);

        return [
            'gross' => $gross,
            'discount' => ($gross - $afterLines) + $order,
            'order_share' => $afterLines > 0 ? $order / $afterLines : 0.0,
        ];
    }

    /** A message when the sale breaks either rule for this user, else null. */
    public static function violation(?User $user, array $details, $orderDiscount, $orderMethod): ?string
    {
        $b = self::breakdown($details, $orderDiscount, $orderMethod);

        $limit = self::limitFor($user);
        if ($limit !== null && $b['gross'] > 0) {
            $percent = $b['discount'] / $b['gross'] * 100;
            if ($percent > $limit + 0.01) {
                return __('messages.PosDiscountOverLimit', [
                    'percent' => number_format($percent, 2),
                    'limit' => rtrim(rtrim(number_format($limit, 2), '0'), '.'),
                ]);
            }
        }

        // Minimum price per line, after its line discount and order share.
        $productIds = array_filter(array_map(fn ($d) => (int) ($d['product_id'] ?? 0), $details));
        if (! $productIds) {
            return null;
        }
        $mins = DB::table('products')->whereIn('id', $productIds)->pluck('min_price', 'id');
        $variantIds = array_filter(array_map(fn ($d) => (int) ($d['product_variant_id'] ?? 0), $details));
        $variantMins = $variantIds && Schema::hasColumn('product_variants', 'min_price')
            ? DB::table('product_variants')->whereIn('id', $variantIds)->pluck('min_price', 'id')
            : collect();

        foreach ($details as $d) {
            $min = (float) ($variantMins[(int) ($d['product_variant_id'] ?? 0)] ?? 0) ?: (float) ($mins[(int) ($d['product_id'] ?? 0)] ?? 0);
            if ($min <= 0) {
                continue;
            }
            $price = (float) ($d['Unit_price'] ?? 0);
            $net = (string) ($d['discount_Method'] ?? '2') === '2'
                ? (float) ($d['discount'] ?? 0)
                : $price * (float) ($d['discount'] ?? 0) / 100;
            // Only what a discount did is judged: a shelf price already under
            // its minimum (a data issue) is not this check's business.
            if ($net <= 0 && $b['order_share'] <= 0) {
                continue;
            }
            $effective = ($price - $net) * (1 - $b['order_share']);
            if ($effective < $min - 0.005) {
                $name = DB::table('products')->where('id', (int) $d['product_id'])->value('name');

                return __('messages.PosBelowMinPrice', [
                    'product' => $name,
                    'price' => number_format($effective, 2),
                    'min' => number_format($min, 2),
                ]);
            }
        }

        return null;
    }
}
