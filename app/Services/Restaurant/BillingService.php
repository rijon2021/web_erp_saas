<?php

namespace App\Services\Restaurant;

use App\Http\Controllers\Restaurant\RestaurantPosBridge;
use App\Models\Category;
use App\Models\Product;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantSetting;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant bills. quote() prices what is being paid; pay() turns it into a
 * normal POS sale (RestaurantPosBridge → PosController::createPosSale), so
 * stock, cash register, accounting and e-invoicing behave exactly as at the POS.
 *
 * Money rules (all amounts in the base currency):
 *  - dish line = POS line formula on (dish price + add-ons), with the
 *    product's own tax % and method (exclusive / inclusive);
 *  - service charge = setting % of the dishes before tax (dine-in only unless
 *    the setting says all orders), booked on a "Service charge" service line;
 *  - discount = amount or % of dishes + service, capped by the setting;
 *  - tip = amount or % of the dishes, booked on a "Tip" service line;
 *  - rounding = the total rounded DOWN to the setting's step (guest's favour),
 *    added to the sale discount;
 *  - add-on options linked to an ingredient add a 0-priced line so that
 *    ingredient's stock is used up.
 */
class BillingService
{
    public const SPLITS = ['full', 'equal', 'items'];

    /** The POS charges payment method 1 through Stripe (needs a card token), so bills skip it. */
    public const STRIPE_METHOD_ID = 1;

    /**
     * @param  array  $opts  item_ids?: int[] (null = every unpaid dish), discount_type?: amount|percent,
     *                       discount_value?: number, tip_type?: amount|percent, tip_value?: number, shares?: int
     */
    public function quote(RestaurantOrder $order, array $opts = []): array
    {
        $s = RestaurantSetting::current();
        $items = $this->payableItems($order, $opts['item_ids'] ?? null);
        if ($items->isEmpty()) {
            throw new OrderException(__('Nothing left to pay on this order.'));
        }
        $products = Product::whereIn('id', $items->pluck('product_id')->merge($this->addonProductIds($items))->unique())
            ->get(['id', 'name', 'type', 'TaxNet', 'tax_method', 'unit_sale_id'])->keyBy('id');

        $lines = [];
        $stockLines = [];
        $itemsTotal = 0.0;
        $netTotal = 0.0;
        $serviceBase = 0.0;
        foreach ($items as $it) {
            $p = $products[$it->product_id] ?? null;
            $taxPercent = (float) ($p->TaxNet ?? 0);
            $taxMethod = (string) ($p->tax_method ?? '1') === '2' ? '2' : '1';
            $calc = $this->line((float) $it->unit_price + (float) $it->addons_price, (float) $it->qty, $taxPercent, $taxMethod);
            $itemsTotal = round($itemsTotal + $calc['subtotal'], 2);
            $netTotal += $calc['net'] * (float) $it->qty;
            // Service charge is on the dishes before tax: for tax-inclusive
            // prices that is price / (1 + rate), not the POS's legacy net.
            $serviceBase += $taxMethod === '2' ? $calc['subtotal'] / (1 + $taxPercent / 100) : $calc['net'] * (float) $it->qty;
            $lines[] = [
                'item_id' => $it->id,
                'product_id' => $it->product_id,
                'product_variant_id' => $it->product_variant_id,
                'product_type' => $p->type ?? 'is_single',
                'sale_unit_id' => $p->unit_sale_id ?? null,
                'name' => $it->name,
                'addons_label' => $it->addons_label,
                'qty' => (float) $it->qty,
                'unit_price' => round((float) $it->unit_price + (float) $it->addons_price, 2),
                'tax_percent' => $taxPercent,
                'tax_method' => $taxMethod,
                'tax' => round($calc['tax'] * (float) $it->qty, 2),
                'subtotal' => $calc['subtotal'],
                'seat' => $it->seat,
            ];
            foreach ((array) ($it->addons ?? []) as $a) {
                $ap = ! empty($a['product_id']) ? ($products[(int) $a['product_id']] ?? null) : null;
                // Only stocked single products / combos can be taken as an ingredient (a variant product has no one stock row).
                if ($ap && in_array($ap->type, ['is_single', 'is_combo'], true)) {
                    $stockLines[] = [
                        'product_id' => $ap->id, 'product_variant_id' => null, 'product_type' => $ap->type, 'sale_unit_id' => $ap->unit_sale_id,
                        'name' => $ap->name, 'qty' => round((float) ($a['stock_qty'] ?? 1) * (float) $it->qty, 3),
                    ];
                }
            }
        }
        $netTotal = round($netTotal, 2);

        $servicePercent = (float) $s->service_charge_percent;
        $service = ($servicePercent > 0 && ($s->service_charge_scope === 'all' || $order->type === 'dine_in'))
            ? round($serviceBase * $servicePercent / 100, 2) : 0.0;

        $discountBase = round($itemsTotal + $service, 2);
        $discount = $this->amountOf($opts['discount_type'] ?? 'amount', (float) ($opts['discount_value'] ?? 0), $discountBase);
        $maxDiscount = round($discountBase * (float) $s->max_discount_percent / 100, 2);
        if ($discount > $maxDiscount + 0.001) {
            throw new OrderException(__('The largest discount allowed is :n% (:amount).', ['n' => rtrim(rtrim(number_format((float) $s->max_discount_percent, 2), '0'), '.'), 'amount' => number_format($maxDiscount, 2)]));
        }

        $tip = $s->tips_enabled ? $this->amountOf($opts['tip_type'] ?? 'amount', (float) ($opts['tip_value'] ?? 0), $itemsTotal) : 0.0;

        $beforeRounding = round($itemsTotal + $service - $discount + $tip, 2);
        $step = (float) $s->bill_rounding;
        $grand = $beforeRounding;
        // Paying by item: only the payment that settles the order is rounded,
        // so splitting a bill never multiplies the rounding.
        $allItems = ! isset($opts['item_ids']) || $items->count() === $this->payableItems($order, null)->count();
        if ($step > 0 && $allItems) {
            $grand = round(floor(round($beforeRounding / $step, 6)) * $step, 2);
        }
        $rounding = round($beforeRounding - $grand, 2);

        $shares = max(1, min(20, (int) ($opts['shares'] ?? 1)));
        $shareAmounts = $this->splitEqually($grand, $shares);

        return [
            'lines' => $lines,
            'stock_lines' => $stockLines,
            'items_total' => $itemsTotal,
            'net_total' => $netTotal,
            'tax_total' => round(collect($lines)->sum('tax'), 2),
            'service_percent' => $servicePercent,
            'service_charge' => $service,
            'discount' => $discount,
            'max_discount' => $maxDiscount,
            'tip' => $tip,
            'rounding' => $rounding,
            'grand_total' => $grand,
            'shares' => $shares,
            'share_amounts' => $shareAmounts,
            'all_items' => $allItems,
        ];
    }

    /**
     * Take the payment: one POS sale for the quoted dishes. When every dish
     * of the order is paid, the order is closed and the table cleared
     * (Needs cleaning / Free per Restaurant Settings).
     *
     * @param  array  $payments  [{payment_method_id, amount, account_id?}]
     * @return array{sale_id:int, order:RestaurantOrder, grand_total:float, change:float, closed:bool}
     */
    public function pay(RestaurantOrder $order, array $opts, array $payments, User $cashier, ?int $clientId = null, ?string $saleUuid = null, string $split = 'full'): array
    {
        if (! in_array($split, self::SPLITS, true)) {
            $split = 'full';
        }

        return DB::transaction(function () use ($order, $opts, $payments, $cashier, $clientId, $saleUuid, $split) {
            $order = RestaurantOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // A retry of a payment that already went through (lost response).
            if ($saleUuid && ($done = DB::table('rst_order_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
                ->where('p.order_id', $order->id)->where('s.sale_uuid', $saleUuid)->first(['p.sale_id', 'p.amount']))) {
                return ['sale_id' => (int) $done->sale_id, 'order' => $order, 'grand_total' => (float) $done->amount, 'change' => 0.0, 'closed' => ! $order->isOpen()];
            }
            if ($saleUuid && DB::table('sales')->where('sale_uuid', $saleUuid)->exists()) {
                throw new OrderException(__('This payment was already used for another sale. Reload the bill and try again.'));
            }
            if (! $order->isOpen()) {
                throw new OrderException(__('Order :ref is closed.', ['ref' => $order->ref]));
            }
            $q = $this->quote($order, $opts);
            // The bill must still be the one the cashier showed the guest: a
            // dish added (QR, waiter) or paid elsewhere meanwhile, or a tip
            // changed a moment before "Take payment", refuses the payment.
            if (isset($opts['expected_total']) && abs((float) $opts['expected_total'] - $q['grand_total']) > 0.005) {
                throw new OrderException(__('The bill has changed since it was shown. Check it again before taking payment.'), 409);
            }
            if (isset($opts['expected_item_ids'])) {
                $want = collect($opts['expected_item_ids'])->map(fn ($v) => (int) $v)->sort()->values()->all();
                $have = collect($q['lines'])->pluck('item_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
                if ($want !== $have) {
                    throw new OrderException(__('The bill has changed since it was shown. Check it again before taking payment.'), 409);
                }
            }
            if (collect($payments)->contains(fn ($p) => (int) ($p['payment_method_id'] ?? 0) === self::STRIPE_METHOD_ID)) {
                throw new OrderException(__('Online card payments (Stripe) are taken at the POS. Use another payment method here.'));
            }
            $paid = round(collect($payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0)), 2);
            if ($paid + 0.001 < $q['grand_total']) {
                throw new OrderException(__('The payments (:paid) do not cover the bill (:total).', ['paid' => number_format($paid, 2), 'total' => number_format($q['grand_total'], 2)]));
            }

            $settings = RestaurantSetting::current();
            $warehouseId = $this->warehouseFor($order, $settings, $cashier);
            $client = $clientId ?: ($order->client_id ?: (int) Setting::whereNull('deleted_at')->value('client_id'));
            if (! $client) {
                throw new OrderException(__('Choose a customer, or set the default customer in System Settings.'));
            }

            $details = [];
            foreach ($q['lines'] as $l) {
                $calc = $this->line($l['unit_price'], $l['qty'], $l['tax_percent'], $l['tax_method']);
                $details[] = $this->detail($l['product_id'], $l['product_variant_id'], $l['product_type'], $l['sale_unit_id'], $l['name'], $l['qty'], $l['unit_price'], $l['tax_percent'], $l['tax_method'], $calc['subtotal']);
            }
            foreach ($q['stock_lines'] as $l) {
                $details[] = $this->detail($l['product_id'], null, $l['product_type'], $l['sale_unit_id'], $l['name'], $l['qty'], 0, 0, '1', 0);
            }
            if ($q['service_charge'] > 0) {
                $sp = $this->systemProduct('service', $settings);
                $details[] = $this->detail($sp->id, null, 'is_service', null, $sp->name, 1, $q['service_charge'], 0, '1', $q['service_charge']);
            }
            if ($q['tip'] > 0) {
                $tp = $this->systemProduct('tip', $settings);
                $details[] = $this->detail($tp->id, null, 'is_service', null, $tp->name, 1, $q['tip'], 0, '1', $q['tip']);
            }

            $table = $order->table_id ? optional($order->table()->first())->name : null;
            $payload = [
                'client_id' => $client,
                'warehouse_id' => $warehouseId,
                'tax_rate' => 0,
                'TaxNet' => 0,
                'discount' => round($q['discount'] + $q['rounding'], 2),
                'discount_Method' => '2',
                'shipping' => 0,
                'GrandTotal' => $q['grand_total'],
                'notes' => trim('Restaurant '.$order->ref.($table ? ' · Table '.$table : '').($order->customer_name ? ' · '.$order->customer_name : '')),
                'details' => $details,
                'payments' => array_values(array_map(fn ($p) => array_filter([
                    'payment_method_id' => (int) $p['payment_method_id'],
                    'amount' => round((float) $p['amount'], 2),
                    'account_id' => ! empty($p['account_id']) ? (int) $p['account_id'] : null,
                ], fn ($v) => $v !== null), $payments)),
                'sale_uuid' => $saleUuid,
                'kitchen_action' => 'none',
            ];

            $response = $this->createSale($payload, $cashier);
            $status = $response->getStatusCode();
            $body = $response->getData(true);
            if ($status >= 300 || empty($body['success']) || empty($body['id'])) {
                throw new OrderException($body['message'] ?? __('The sale could not be saved.'), $status >= 400 && $status < 500 ? 422 : 500);
            }
            $saleId = (int) $body['id'];

            RestaurantOrderItem::whereIn('id', collect($q['lines'])->pluck('item_id'))->update(['sale_id' => $saleId, 'updated_at' => now()]);
            // Paid before "Send" (a takeaway rung up at the counter): the dishes still go to the kitchen.
            app(OrderService::class)->sendLines($order->id, collect($q['lines'])->pluck('item_id')->all(), $cashier->id);
            DB::table('rst_order_payments')->insert([
                'order_id' => $order->id, 'sale_id' => $saleId, 'split' => $split, 'shares' => $split === 'equal' ? $q['shares'] : null,
                'amount' => $q['grand_total'], 'service_charge' => $q['service_charge'], 'discount' => $q['discount'], 'tip' => $q['tip'],
                'rounding' => $q['rounding'], 'paid_by' => $cashier->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $closed = $this->payableItems($order, null)->isEmpty();
            if ($closed) {
                $order->update(['status' => 'paid', 'closed_at' => now(), 'sale_id' => $order->sale_id ?: $saleId]);
                if ($order->table_id) {
                    $tableRow = \App\Models\RestaurantTable::find($order->table_id);
                    if ($tableRow && $tableRow->isInUse()) {
                        app(FloorService::class)->clear($tableRow->id);
                    }
                }
            } elseif (! $order->sale_id) {
                $order->update(['sale_id' => $saleId]);
            }

            unset($q['stock_lines']);

            return ['sale_id' => $saleId, 'order' => $order->fresh(), 'grand_total' => $q['grand_total'], 'change' => round(max(0, $paid - $q['grand_total']), 2), 'closed' => $closed, 'quote' => $q];
        });
    }

    // ------------------------------------------------------------------

    /** Unpaid, non-void dishes of the order (optionally limited to some ids). */
    public function payableItems(RestaurantOrder $order, ?array $itemIds)
    {
        return RestaurantOrderItem::where('order_id', $order->id)->where('status', '!=', 'void')->whereNull('sale_id')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('id', array_map('intval', $itemIds)))
            ->orderBy('course')->orderBy('id')->get();
    }

    /** The POS line formula (lib/lineCalc.js recomputeLine) with no line discount. */
    private function line(float $price, float $qty, float $taxPercent, string $taxMethod): array
    {
        if ($taxMethod === '1') {
            $net = $price;
            $tax = round($taxPercent * $price / 100, 6);
        } else {
            $tax = round($price * ($taxPercent / 100), 6);
            $net = $price - $tax;
        }

        return ['net' => $net, 'tax' => $tax, 'subtotal' => round($qty * $net + $tax * $qty, 2)];
    }

    private function detail(int $productId, ?int $variantId, string $type, $unitId, string $name, float $qty, float $price, float $taxPercent, string $taxMethod, float $subtotal): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_type' => $type,
            'sale_unit_id' => $unitId,
            'name' => $name,
            'quantity' => $qty,
            'Unit_price' => round($price, 2),
            'tax_percent' => $taxPercent,
            'tax_method' => $taxMethod,
            'discount' => 0,
            'discount_Method' => '2',
            'subtotal' => round($subtotal, 2),
            'price_type' => 'retail',
        ];
    }

    private function amountOf(string $type, float $value, float $base): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        return $type === 'percent' ? round($base * min($value, 100) / 100, 2) : round($value, 2);
    }

    /** Equal shares that add up to the total exactly (the last share takes the cents). */
    public function splitEqually(float $total, int $shares): array
    {
        // In whole cents: 50.01 / 3 = 16.67 × 3 (the first shares take the spare cents).
        $cents = (int) round($total * 100);
        $base = intdiv($cents, $shares);
        $extra = $cents - $base * $shares;
        $out = [];
        for ($i = 0; $i < $shares; $i++) {
            $out[] = round(($base + ($i < $extra ? 1 : 0)) / 100, 2);
        }

        return $out;
    }

    private function addonProductIds($items): array
    {
        return $items->flatMap(fn ($it) => collect((array) ($it->addons ?? []))->pluck('product_id'))->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function warehouseFor(RestaurantOrder $order, RestaurantSetting $s, User $cashier): int
    {
        $id = $order->warehouse_id ?: $s->default_warehouse_id ?: (int) Setting::whereNull('deleted_at')->value('warehouse_id');
        if (! $id) {
            $id = (int) DB::table('user_warehouse')->where('user_id', $cashier->id)->value('warehouse_id');
        }
        if (! $id) {
            throw new OrderException(__('Choose the warehouse restaurant sales use in Restaurant Settings.'));
        }

        return (int) $id;
    }

    /**
     * The "Service charge" / "Tip" service products (created on first use,
     * hidden from the online store and from restaurant menus).
     */
    public function systemProduct(string $kind, ?RestaurantSetting $s = null): Product
    {
        $s ??= RestaurantSetting::current();
        // Lock the settings row so two first payments cannot both create the product.
        $s = RestaurantSetting::whereKey($s->id)->lockForUpdate()->first() ?? $s;
        $col = $kind === 'tip' ? 'tip_product_id' : 'service_product_id';
        $existing = $s->{$col} ? Product::whereNull('deleted_at')->find($s->{$col}) : null;
        if ($existing) {
            return $existing;
        }
        $code = $kind === 'tip' ? 'RST-TIP' : 'RST-SERVICE';
        $p = Product::whereNull('deleted_at')->where('code', $code)->first();
        if (! $p) {
            $cat = Category::whereNull('deleted_at')->where('name', 'Restaurant charges')->first();
            if (! $cat) {
                $cat = new Category;
                $cat->forceFill(['name' => 'Restaurant charges', 'code' => 'RST-CHARGES']);
                if (Schema::hasColumn('categories', 'show_in_store')) {
                    $cat->show_in_store = false;
                }
                $cat->save();
            }
            $p = new Product;
            $p->forceFill([
                'code' => $code, 'Type_barcode' => 'CODE128', 'name' => $kind === 'tip' ? 'Tip' : 'Service charge',
                'cost' => 0, 'price' => 0, 'wholesale_price' => 0, 'min_price' => 0,
                'category_id' => $cat->id, 'type' => 'is_service', 'is_variant' => 0,
                'tax_method' => '1', 'TaxNet' => 0, 'is_active' => 1, 'not_selling' => 0, 'hide_from_online_store' => 1,
                'image' => 'no-image.png', 'discount_method' => '1', 'stock_alert' => 0,
            ]);
            $p->save();
        }
        DB::table('rst_settings')->where('id', $s->id)->update([$col => $p->id]);
        $s->{$col} = $p->id;

        return $p;
    }

    /** Product ids the restaurant menus must never list. */
    public static function systemProductIds(): array
    {
        try {
            $s = RestaurantSetting::query()->orderBy('id')->first(['service_product_id', 'tip_product_id']);

            return array_values(array_filter([(int) ($s->service_product_id ?? 0), (int) ($s->tip_product_id ?? 0)]));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function createSale(array $payload, User $cashier)
    {
        $request = Request::create('/api/pos/create_pos', 'POST', $payload);
        $request->setUserResolver(fn () => $cashier);
        // CreatePOS reads both Auth::user() and $request->user('api').
        Auth::setUser($cashier);
        Auth::guard('api')->setUser($cashier);

        return app(RestaurantPosBridge::class)->createSale($request);
    }
}
