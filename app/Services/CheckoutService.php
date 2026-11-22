<?php

namespace App\Services;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\StoreCoupon;
use App\Models\StoreCouponRedemption;
use App\Models\StoreSetting;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for online-store checkout maths.
 *
 * Every entry point that touches money (the quote endpoint, the Stripe
 * PaymentIntent, and the final order placement) runs the numbers through
 * here so the customer can never influence prices, tax, or shipping from
 * the browser.
 */
class CheckoutService
{
    public function __construct(
        private FlashSaleService $flashSales,
        private Shipping\LiveShippingRates $liveRates
    ) {
    }

    /**
     * Products the online store sells: active, not hidden from the store and
     * not deleted — the same rule the storefront listings use.
     */
    public static function sellableProducts(): Builder
    {
        return Product::query()
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('hide_from_online_store', 0)
            ->forStorefrontWarehouses();
    }

    /** Variants that still exist (not soft-deleted). */
    public static function sellableVariants(): Builder
    {
        return ProductVariant::query()->whereNull('deleted_at');
    }

    /** Thrown for any checkout validation problem; carries an HTTP status + payload. */
    public function fail(string $message, int $status = 422, array $extra = []): CheckoutException
    {
        return new CheckoutException($message, $status, $extra);
    }

    /**
     * Warehouses the online store sells from (admin multi-select on the store
     * settings; "All warehouses" = every warehouse). Stock is validated as
     * the SUM across these warehouses.
     *
     * @return int[]
     */
    public function activeWarehouseIds(): array
    {
        $ids = StoreSetting::storeWarehouseIds();

        if (! $ids) {
            throw $this->fail(__('messages.NoWarehouseConfigured'));
        }

        return $ids;
    }

    /**
     * Warehouse the confirmed order is booked under: the requested one when it
     * belongs to the active set, otherwise the active warehouse holding the
     * most stock for the cart's products.
     */
    public function selectFulfilmentWarehouseId(array $items, $requested = null): int
    {
        $warehouseIds = $this->activeWarehouseIds();

        $requested = (int) ($requested ?? 0);
        if ($requested && in_array($requested, $warehouseIds, true)) {
            return $requested;
        }

        $productIds = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values();

        // Virtual combo stock: a combo's own row stays at 0, so weigh the
        // warehouses on its components instead.
        if (ComboStockService::enabled()) {
            $comboIds = Product::whereIn('id', $productIds)->where('type', 'is_combo')->pluck('id')->all();
            if ($comboIds) {
                $components = [];
                foreach (app(ComboStockService::class)->recipes($comboIds) as $recipe) {
                    $components = array_merge($components, array_keys($recipe));
                }
                $productIds = $productIds->diff($comboIds)->merge($components)->unique()->values();
            }
        }

        $best = product_warehouse::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->selectRaw('warehouse_id, SUM(qte) as total_qty')
            ->groupBy('warehouse_id')
            ->orderByDesc('total_qty')
            ->value('warehouse_id');

        return (int) ($best ?: $warehouseIds[0]);
    }

    /**
     * Build normalized line items using SERVER-side prices, and the subtotal.
     * Also flags pre-order lines and enforces pre-order limits.
     *
     * Stock is the combined quantity across the given warehouses. With
     * $enforceStock, a line asking for more than is in stock is refused
     * (pre-order, "Always in stock", service and unlimited combo products
     * are exempt — the same products the storefront always shows as buyable).
     *
     * @param  int|int[]  $warehouseIds
     * @return array{items: array<int,array>, subtotal: float, has_preorder_items: bool}
     */
    public function buildLineItems(array $items, int|array $warehouseIds, bool $enforceStock = false): array
    {
        $warehouseIds = array_values(array_map('intval', (array) $warehouseIds));
        $ids = collect($items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values();

        $productColumns = ['id', 'name', 'type', 'price', 'TaxNet', 'discount', 'discount_method', 'tax_method',
            'is_preorder', 'preorder_available_date', 'preorder_limit', 'preorder_note'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'always_in_stock')) {
            $productColumns[] = 'always_in_stock';
        }

        // Only products the store still sells: a browser-held cart can outlive
        // a product being hidden, deactivated or deleted (e.g. a theme switch).
        $products = self::sellableProducts()->whereIn('id', $ids)
            ->get($productColumns)
            ->keyBy('id');

        $variantIds = collect($items)->pluck('product_variant_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        $variants = $variantIds->isEmpty()
            ? collect()
            : self::sellableVariants()->whereIn('id', $variantIds)->get(['id', 'product_id', 'name', 'price'])->keyBy('id');

        // A line is unavailable when its product is, or when its variant was
        // deleted / no longer belongs to that product.
        $missing = $ids->diff($products->keys());
        foreach ($items as $i) {
            $pvid = ! empty($i['product_variant_id']) ? (int) $i['product_variant_id'] : null;
            $variant = $pvid ? $variants->get($pvid) : null;
            if ($pvid && (! $variant || (int) $variant->product_id !== (int) $i['product_id'])) {
                $missing->push((int) $i['product_id']);
            }
        }
        if ($missing->isNotEmpty()) {
            throw $this->fail(__('messages.SomeProductsNotFound'), 422, ['product_ids' => $missing->unique()->values()]);
        }

        $stockByKey = [];
        product_warehouse::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $ids)
            ->whereNull('deleted_at')
            ->get(['product_id', 'product_variant_id', 'qte'])
            ->each(function ($r) use (&$stockByKey) {
                $key = $r->product_id.':'.($r->product_variant_id ?? 'p');
                $stockByKey[$key] = ($stockByKey[$key] ?? 0.0) + (float) $r->qte;
            });

        // Virtual combo stock: a combo's stock is what its components can fill
        // (unlimited components → never a pre-order).
        $unlimitedCombos = [];
        if (ComboStockService::enabled()) {
            $comboIds = $products->where('type', 'is_combo')->keys()->map(fn ($v) => (int) $v)->all();
            if ($comboIds) {
                foreach (app(ComboStockService::class)->availability($comboIds, $warehouseIds) as $pid => $qty) {
                    $stockByKey["{$pid}:p"] = $qty ?? 0.0;
                    if ($qty === null) {
                        $unlimitedCombos[$pid] = true;
                    }
                }
            }
        }

        if ($enforceStock) {
            $this->assertInStock($items, $products, $variants, $stockByKey, $unlimitedCombos);
        }

        $preorderQtyAccumulator = [];
        $normalized = [];
        $subtotal = 0.0;
        $hasPreorderItems = false;

        foreach ($items as $i) {
            $pid = (int) $i['product_id'];
            $pvid = ! empty($i['product_variant_id']) ? (int) $i['product_variant_id'] : null;
            $qty = max(1, (float) $i['qty']);

            $product = $products->get($pid);
            $price = (float) $product->price;
            if ($pvid) {
                $variant = $variants->get($pvid);
                if ($variant && (int) $variant->product_id === $pid) {
                    $price = (float) $variant->price;
                }
            }
            $price = round(max(0, $price), 2);

            // Apply the running flash-sale price server-side so the customer is
            // charged the discounted price and can never tamper with it.
            $price = $this->flashSales->discountedBase($pid, $price);

            // Restaurant add-ons (extra cheese, a size…): priced here from the
            // menu's own rules, never from what the browser sends.
            $addons = $this->restaurantAddons($pid, $i['option_ids'] ?? null, (string) $product->name);
            $price = round($price + $addons['extra'], 2);

            $line = round($qty * $price, 2);

            $stockKey = $pvid ? "{$pid}:{$pvid}" : "{$pid}:p";
            $currentStock = $stockByKey[$stockKey] ?? 0.0;
            $isPreorder = false;

            if ($product->is_preorder && $currentStock <= 0 && ! isset($unlimitedCombos[$pid])) {
                $isPreorder = true;
                $hasPreorderItems = true;

                if ($product->preorder_limit !== null) {
                    $alreadyQueued = $preorderQtyAccumulator[$pid] ?? 0;
                    if (($alreadyQueued + $qty) > $product->preorder_limit) {
                        throw $this->fail(__('messages.PreorderLimitExceededFor', [
                            'name' => $product->name,
                            'max' => $product->preorder_limit,
                        ]));
                    }
                    $preorderQtyAccumulator[$pid] = $alreadyQueued + $qty;
                }
            }

            $normalized[] = [
                'product_id' => $pid,
                'product_variant_id' => $pvid,
                'weight' => (float) ($product->weight ?? 0),
                'product_name' => $product->name,
                'qty' => $qty,
                'price' => $price,
                'line_total' => $line,
                'TaxNet' => (float) ($product->TaxNet ?? 0),
                'discount' => (float) ($product->discount ?? 0),
                'discount_method' => (string) ($product->discount_method ?? '1'),
                'tax_method' => (string) ($product->tax_method ?? '1'),
                'is_preorder' => $isPreorder,
                'current_stock' => $currentStock,
                'options' => $addons['options'] ?: null,
                'options_label' => $addons['label'] !== '' ? mb_substr($addons['label'], 0, 500) : null,
            ];

            $subtotal += $line;
        }

        return [
            'items' => $normalized,
            'subtotal' => round(max(0, $subtotal), 2),
            'has_preorder_items' => $hasPreorderItems,
        ];
    }

    /**
     * The add-ons chosen for a restaurant dish, validated and priced by the
     * Restaurant module's menu rules (required / at most N, sold out).
     * Without the module (or for a product with no add-on groups and nothing
     * chosen) the line is unchanged.
     *
     * @return array{extra: float, options: array, label: string}
     */
    protected function restaurantAddons(int $productId, $optionIds, string $name): array
    {
        $none = ['extra' => 0.0, 'options' => [], 'label' => ''];
        // Before the phase 8 migration the choice could not be kept on the
        // order line, so it is not charged either.
        if (! \App\Support\RestaurantModule::menuReady() || ! \Illuminate\Support\Facades\Schema::hasColumn('online_order_items', 'options')) {
            return $none;
        }
        $optionIds = array_values(array_filter(array_map('intval', (array) ($optionIds ?? [])), fn ($v) => $v > 0));
        $menu = app(\App\Services\Restaurant\MenuService::class);
        // Sold out today / not served at this time (Restaurant › Menu). The
        // line stays in the cart (`dish_ids`, not `product_ids`, which the
        // checkout page drops) so the shopper can order it later.
        $detail = $menu->detailsFor([$productId])[$productId] ?? null;
        if ($detail && ! $menu->availableNow($detail)) {
            throw $this->fail($detail->isSoldOut()
                ? __(':dish is sold out today.', ['dish' => $name])
                : __(':dish is not served at this time.', ['dish' => $name]), 422, ['dish_ids' => [$productId]]);
        }
        // Nothing chosen: only the restaurant theme (which has the add-on
        // picker) insists on required groups; other themes sell the plain dish.
        if (! $optionIds && (empty($menu->groupsFor([$productId])[$productId] ?? []) || (string) (\App\Models\StoreSetting::value('theme') ?? '') !== \App\Support\RestaurantTheme::KEY)) {
            return $none;
        }
        try {
            $sel = $menu->priceSelection($productId, $optionIds);
        } catch (\App\Services\Restaurant\MenuException $e) {
            throw $this->fail($name.': '.$e->getMessage(), 422, ['dish_ids' => [$productId]]);
        }

        return ['extra' => (float) $sel['extra'], 'options' => $sel['options'], 'label' => (string) $sel['label']];
    }

    /**
     * Refuse a cart that asks for more than is in stock. Quantities of the
     * same product/variant on several lines are added up. A variant without
     * its own stock row uses the product-level row, as the storefront does.
     * Throws with the items the checkout page lists (name, available, required).
     */
    protected function assertInStock(array $items, Collection $products, Collection $variants, array $stockByKey, array $unlimitedCombos): void
    {
        $required = [];
        foreach ($items as $i) {
            $pid = (int) $i['product_id'];
            $pvid = ! empty($i['product_variant_id']) ? (int) $i['product_variant_id'] : null;
            $product = $products->get($pid);

            if ($product->is_preorder
                || ($product->always_in_stock ?? false)
                || (string) $product->type === 'is_service'
                || isset($unlimitedCombos[$pid])) {
                continue;
            }

            $key = $pvid ? "{$pid}:{$pvid}" : "{$pid}:p";
            $required[$key] = ($required[$key] ?? 0.0) + max(1, (float) $i['qty']);
        }

        $short = [];
        foreach ($required as $key => $qty) {
            [$pid, $pvid] = explode(':', $key);
            $available = $stockByKey[$key] ?? ($pvid !== 'p' ? ($stockByKey["{$pid}:p"] ?? 0.0) : 0.0);
            $available = max(0.0, (float) $available);

            if ($qty > $available) {
                $product = $products->get((int) $pid);
                $variant = $pvid !== 'p' ? $variants->get((int) $pvid) : null;
                $short[] = [
                    'product_id' => (int) $pid,
                    'product_variant_id' => $variant ? (int) $pvid : null,
                    'name' => $product->name.($variant && $variant->name ? ' ('.$variant->name.')' : ''),
                    'available' => $available + 0,
                    'required' => $qty + 0,
                ];
            }
        }

        if ($short) {
            throw $this->fail(__('messages.InsufficientStockFor').' '.implode(', ', array_column($short, 'name')), 422, ['items' => $short]);
        }
    }

    /**
     * Active shipping methods available for the given country.
     *
     * @return Collection<int,ShippingMethod>
     */
    public function availableShippingMethods(?string $country, array $productIds = []): Collection
    {
        $methods = ShippingMethod::with('regions')
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get()
            ->filter(fn (ShippingMethod $m) => $m->availableForCountry($country));

        return $this->restrictToProducts($methods, $productIds)->values();
    }

    /**
     * Narrow a method list to what EVERY product in the cart allows.
     *
     * A product with no rows in product_shipping_method is unrestricted and
     * constrains nothing — so carts of ordinary products behave exactly as
     * before, and one restricted item narrows the whole order.
     */
    private function restrictToProducts(Collection $methods, array $productIds): Collection
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (! $productIds || $methods->isEmpty()) {
            return $methods;
        }

        $rows = DB::table('product_shipping_method')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'shipping_method_id'])
            ->groupBy('product_id');

        foreach ($rows as $allowedForProduct) {
            $allowed = $allowedForProduct->pluck('shipping_method_id')
                ->map(fn ($id) => (int) $id)->all();
            $methods = $methods->filter(fn (ShippingMethod $m) => in_array((int) $m->id, $allowed, true));
        }

        return $methods;
    }

    /**
     * Every option the customer can pick: one per flat method, one per
     * carrier service of a live (Shippo) method. `pending` = a live method
     * still needs the delivery address before it can quote.
     *
     * @param  array  $lines  line items from buildLineItems() (product_id, qty)
     * @param  array  $destination  name/phone/email/address/city/state/zip
     * @return array{options: list<array{id: int, rate: ?string, name: string, price: float, days: ?int, method: ShippingMethod, quote: ?array}>, pending: bool}
     */
    public function shippingOptions(?string $country, array $lines, array $destination = [], float $netSubtotal = 0.0): array
    {
        $productIds = collect($lines)->pluck('product_id')->filter()->unique()->all();
        $options = [];
        $pending = false;

        foreach ($this->availableShippingMethods($country, $productIds) as $method) {
            if (! $method->isLive()) {
                $options[] = [
                    'id' => (int) $method->id, 'rate' => null, 'name' => $method->name,
                    'price' => round((float) $method->price, 2), 'days' => null,
                    'method' => $method, 'quote' => null,
                ];

                continue;
            }

            $live = $this->liveRates->options($method, $lines, ['country' => $country] + $destination, $netSubtotal);
            $pending = $pending || $live['pending'];
            foreach ($live['options'] as $o) {
                $options[] = [
                    'id' => (int) $method->id, 'rate' => $o['key'], 'name' => $o['name'],
                    'price' => $o['price'], 'days' => $o['days'],
                    'method' => $method, 'quote' => $o['quote'],
                ];
            }
        }

        return ['options' => $options, 'pending' => $pending];
    }

    /**
     * Resolve the chosen shipping option + cost. A live method's option is
     * picked by its rate key and re-priced here, never taken from the browser.
     * When no shipping methods are configured at all, shipping is free/optional.
     *
     * @return array{method: ?ShippingMethod, cost: float, name: ?string, quote: ?array}
     */
    public function resolveShipping($shippingMethodId, ?string $country, array $lines = [], ?string $rateKey = null, array $destination = [], float $netSubtotal = 0.0): array
    {
        // Nothing configured for this store → no shipping charge, no selection needed.
        if (! $this->shippingConfigured()) {
            return ['method' => null, 'cost' => 0.0, 'name' => null, 'quote' => null];
        }

        $choices = $this->shippingOptions($country, $lines, $destination, $netSubtotal);

        if (! $choices['options']) {
            throw $this->fail(__($choices['pending'] ? 'messages.ShippingAddressForRates' : 'messages.NoShippingForRegion'));
        }

        if (! $shippingMethodId) {
            throw $this->fail(__('messages.ShippingMethodRequired'));
        }

        $rateKey = trim((string) $rateKey) ?: null;
        $choice = collect($choices['options'])->first(
            fn ($o) => $o['id'] === (int) $shippingMethodId && $o['rate'] === $rateKey
        );
        if (! $choice) {
            throw $this->fail(__($choices['pending'] ? 'messages.ShippingAddressForRates' : 'messages.ShippingMethodUnavailable'));
        }

        return ['method' => $choice['method'], 'cost' => $choice['price'], 'name' => $choice['name'], 'quote' => $choice['quote']];
    }

    /** Does this store ship at all (an active shipping method exists)? */
    public function shippingConfigured(): bool
    {
        return ShippingMethod::where('active', true)->exists();
    }

    /**
     * Location-based tax for a taxable base amount.
     *
     * @return array{rate: float, amount: float, tax_rate_id: ?int}
     */
    public function resolveTax(float $taxableBase, ?string $country, ?string $state = null): array
    {
        $rate = TaxRate::resolveForLocation($country, $state);
        if (! $rate) {
            return ['rate' => 0.0, 'amount' => 0.0, 'tax_rate_id' => null];
        }

        $pct = (float) $rate->rate;
        $amount = round(max(0, $taxableBase) * $pct / 100, 2);

        return ['rate' => $pct, 'amount' => $amount, 'tax_rate_id' => $rate->id];
    }

    /**
     * Validate a coupon code against a subtotal + customer, returning the coupon
     * and the discount it yields. Throws CheckoutException when the code is
     * present but not usable. Returns nulls when no code is given.
     *
     * @return array{coupon: ?StoreCoupon, discount: float}
     */
    public function resolveCoupon(?string $code, float $subtotal, $clientId = null): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return ['coupon' => null, 'discount' => 0.0];
        }

        $coupon = StoreCoupon::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();

        if (! $coupon || ! $coupon->enabled) {
            throw $this->fail(__('messages.CouponInvalid'));
        }

        $now = now();
        if (($coupon->starts_at && $coupon->starts_at->gt($now)) ||
            ($coupon->ends_at && $coupon->ends_at->lt($now))) {
            throw $this->fail(__('messages.CouponExpired'));
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw $this->fail(__('messages.CouponMinOrder', [
                'amount' => number_format((float) $coupon->min_order_amount, 2),
            ]));
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw $this->fail(__('messages.CouponUsedUp'));
        }

        if ($coupon->per_customer_limit !== null && $clientId) {
            $mine = StoreCouponRedemption::where('coupon_id', $coupon->id)
                ->where('client_id', $clientId)->count();
            if ($mine >= $coupon->per_customer_limit) {
                throw $this->fail(__('messages.CouponPerCustomerLimit'));
            }
        }

        $discount = $coupon->discountFor($subtotal);
        if ($discount <= 0) {
            throw $this->fail(__('messages.CouponInvalid'));
        }

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    /**
     * Full server-side breakdown for a cart + customer location + shipping choice
     * + optional coupon. Discount applies to the subtotal; tax is charged on the
     * discounted amount.
     *
     * @return array full breakdown
     */
    public function calculate(array $items, int|array $warehouseIds, ?string $country, ?string $state, $shippingMethodId, ?string $couponCode = null, $clientId = null, bool $isPickup = false, ?string $shippingRate = null, array $destination = []): array
    {
        $lines = $this->buildLineItems($items, $warehouseIds, true);
        $subtotal = $lines['subtotal'];

        $couponResult = $this->resolveCoupon($couponCode, $subtotal, $clientId);
        $discount = $couponResult['discount'];
        $netSubtotal = round(max(0, $subtotal - $discount), 2);

        // Collected at a branch: nothing ships, so no method is required and
        // no shipping is charged.
        $shipping = $isPickup
            ? ['method' => null, 'cost' => 0.0, 'name' => null, 'quote' => null]
            : $this->resolveShipping(
                $shippingMethodId,
                $country,
                $lines['items'],
                $shippingRate,
                ['state' => $state] + $destination,
                $netSubtotal
            );
        $tax = $this->resolveTax($netSubtotal, $country, $state);

        $total = round($netSubtotal + $tax['amount'] + $shipping['cost'], 2);

        return [
            'items' => $lines['items'],
            'has_preorder_items' => $lines['has_preorder_items'],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon' => $couponResult['coupon'],
            'tax' => $tax['amount'],
            'tax_rate' => $tax['rate'],
            'shipping_cost' => $shipping['cost'],
            'shipping_method' => $shipping['method'],
            'shipping_method_name' => $shipping['name'],
            'shipping_quote' => $shipping['quote'],
            'total' => $total,
        ];
    }
}
