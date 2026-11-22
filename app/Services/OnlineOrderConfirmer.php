<?php

namespace App\Services;

use App\Exceptions\OnlineOrderConfirmException;
use App\Models\OnlineOrder;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\StoreSetting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Confirming an online order: the Sale (+ its payment when the order is
 * already paid) is created and stock is deducted, in one transaction.
 *
 * Two callers:
 *  - the admin "Confirm" action (Online Orders page), and
 *  - autoConfirmById(), run wherever an online gateway marks an order paid
 *    (return legs, webhooks, paid-at-checkout methods) when the admin switched
 *    "Auto-confirm paid orders" on for that gateway.
 *
 * The order row is locked and re-checked inside the transaction, so a browser
 * return, a gateway webhook and an admin click arriving together confirm the
 * order once: one Sale, stock deducted once.
 */
class OnlineOrderConfirmer
{
    /** online_orders.auto_confirm_error present? Per tenant database. */
    private static array $hasErrorColumn = [];

    private ?int $systemUserId = null;

    /**
     * Confirm a pending order. Throws OnlineOrderConfirmException with a
     * readable message when it cannot (not pending any more, no warehouse,
     * stock short); database errors propagate unchanged.
     */
    public function confirm(OnlineOrder $order, ?int $userId = null): Sale
    {
        $warehouseId = $this->resolveWarehouseId($order);
        if (! $warehouseId) {
            throw new OnlineOrderConfirmException('No warehouse configured.');
        }

        return DB::transaction(function () use ($order, $warehouseId, $userId) {
            // Serialize concurrent confirms and work on the current row.
            $order = OnlineOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $order || $order->status !== 'pending') {
                throw new OnlineOrderConfirmException('Only pending orders can be confirmed.');
            }
            $order->load('items');

            // Final stock check with row locks (skip pre-order items)
            foreach ($this->stockRequirements($order) as $req) {
                $pw = $this->stockRow($warehouseId, $req)->lockForUpdate()->first();
                if ($req['component'] && $pw && ! $pw->manage_stock) {
                    continue;
                }

                $have = $pw ? (float) $pw->qte : 0.0;
                if ($have < $req['need']) {
                    throw new OnlineOrderConfirmException("Insufficient stock for {$req['name']} (need {$req['need']}, have {$have}).");
                }
            }

            $alreadyPaid = ($order->payment_status ?? '') === 'paid';
            $grandTotal = (float) $order->total;

            // Create Sale header
            $sale = Sale::create([
                'date' => $order->date ?: now()->toDateString(),
                'time' => $order->time ?: now()->format('H:i:s'),
                'Ref' => $this->getNumberOrder(),
                'is_pos' => 0,
                'client_id' => $order->client_id,
                'warehouse_id' => $warehouseId,
                'statut' => 'completed',
                'shipping_status' => null,

                'discount' => 0,
                'shipping' => 0,
                'TaxNet' => 0,
                'tax_rate' => 0,
                'GrandTotal' => $grandTotal,

                'paid_amount' => $alreadyPaid ? $grandTotal : 0,
                'payment_statut' => $alreadyPaid ? 'paid' : 'unpaid',
                'notes' => null,
                'user_id' => $userId,
                // Multi-Currency: inherit the order's snapshot so the
                // admin invoice prints in the customer's checkout currency
                'currency_id' => $order->currency_id,
                'exchange_rate' => $order->exchange_rate,
            ]);

            // If the online order was already paid, create a payment_sales record.
            // Through the model so the accounting payment journal is posted too.
            if ($alreadyPaid) {
                // Paid from the customer wallet at checkout: the payment
                // row carries that wallet charge, so its journal settles
                // against the wallet liability (2310) instead of cash, and
                // deleting it later gives the money back to the wallet.
                $walletTxId = null;
                $paymentMethodId = null;
                if (($order->payment_method ?? '') === 'wallet') {
                    $wallets = app(\App\Services\CustomerWallet\CustomerWalletService::class);
                    $walletTxId = optional($wallets->checkoutTransactionFor($order))->id;
                    $paymentMethodId = $wallets->paymentMethodId() ?: $wallets->syncPaymentMethod(true);
                }
                $paymentMethodId = $paymentMethodId ?: $this->resolvePaymentMethodId($order->payment_method ?? 'cod');

                PaymentSale::create([
                    'user_id' => $userId ?? 0,
                    'date' => $sale->date,
                    'Ref' => $this->generatePaymentRef(),
                    'sale_id' => $sale->id,
                    'account_id' => null,
                    'montant' => $grandTotal,
                    'change' => 0,
                    'payment_method_id' => $paymentMethodId,
                    'notes' => 'Auto-created from online order '.($order->ref ?? '#'.$order->id),
                ] + \App\Services\CustomerWallet\CustomerWalletService::txColumn($walletTxId));
            }

            // Create details + decrement stock (skip decrement for pre-order items)
            foreach ($order->items as $it) {
                $product = Product::find($it->product_id);
                $unit = $this->saleUnitForProduct($product);
                $need = $this->requiredBaseQty($it->qty, $unit);

                $taxNet = isset($it->TaxNet) ? (float) $it->TaxNet : (float) ($product->TaxNet ?? 0);
                $taxMethod = $it->tax_method ?? ($product->tax_method ?? null);
                $discount = isset($it->discount) ? (float) $it->discount : (float) ($product->discount ?? 0);
                $discountMethod = $it->discount_method ?? ($product->discount_method ?? null);

                $unitPrice = (float) $it->price;
                $qty = (float) $it->qty;

                SaleDetail::create([
                    'date' => $sale->date,
                    'sale_id' => $sale->id,
                    'sale_unit_id' => $unit ? $unit->id : null,
                    'quantity' => $qty,
                    'price' => $unitPrice,
                    'TaxNet' => $taxNet,
                    'tax_method' => $taxMethod,
                    'discount' => $discount,
                    'discount_method' => $discountMethod,
                    'product_id' => (int) $it->product_id,
                    'product_variant_id' => $it->product_variant_id ?: null,
                    'total' => round($unitPrice * $qty, 2),
                ]);

                // Restaurant add-on ingredients: a free line each, stock taken.
                if (! $it->is_preorder) {
                    foreach ($this->addonStock($it) as $a) {
                        $aUnit = $this->saleUnitForProduct($a['product']);
                        SaleDetail::create([
                            'date' => $sale->date,
                            'sale_id' => $sale->id,
                            'sale_unit_id' => $aUnit ? $aUnit->id : null,
                            'quantity' => $a['qty'],
                            'price' => 0,
                            'TaxNet' => 0,
                            'tax_method' => '1',
                            'discount' => 0,
                            'discount_method' => '2',
                            'product_id' => $a['product']->id,
                            'product_variant_id' => null,
                            'total' => 0,
                        ]);
                        $apw = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_id', $a['product']->id)
                            ->whereNull('product_variant_id')
                            ->lockForUpdate()
                            ->first();
                        if ($apw && $apw->manage_stock) {
                            $apw->qte = max(0, (float) $apw->qte - $a['need']);
                            $apw->save();
                        }
                    }
                }

                // Pre-order items: no stock to decrement
                if ($it->is_preorder) {
                    continue;
                }

                // Virtual combo: take its components instead of its own row.
                if (! $it->product_variant_id
                    && app(ComboStockService::class)->moveComponents((int) $it->product_id, $warehouseId, -$need)) {
                    continue;
                }

                $pw = product_warehouse::where('deleted_at', '=', null)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $it->product_id)
                    ->when($it->product_variant_id, function ($q) use ($it) {
                        $q->where('product_variant_id', $it->product_variant_id);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($pw) {
                    $pw->qte = max(0, (float) $pw->qte - $need);
                    $pw->save();
                }
            }

            // Software License products: claim / issue the keys (emailed after commit).
            // Pre-order lines are sold before the stock (keys) exists: they are
            // backordered and filled when the keys are imported.
            try {
                \App\Services\LicenseKeys\LicenseFulfilment::afterSaleWrite($sale, (int) $order->id, (bool) $order->has_preorder_items);
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw new OnlineOrderConfirmException($e->getMessage());
            }

            // Flip online order status; keep the Sale link so payments
            // recorded on it later can mark this order paid.
            $order->status = 'confirmed';
            $order->sale_id = $sale->id;
            // Remember where the stock came from: returns restock into the
            // order's warehouse, which was empty when the fallback was used.
            $order->warehouse_id = $order->warehouse_id ?: $warehouseId;
            if (self::hasErrorColumn()) {
                $order->auto_confirm_error = null;
            }
            $order->save();

            return $sale;
        });
    }

    /**
     * Confirm a just-paid order when its gateway has auto-confirm switched on.
     * Never throws: the payment is already taken, so a failure only leaves the
     * order pending + paid for the admin, with the reason on the order.
     */
    public function autoConfirmById(int $orderId): ?Sale
    {
        try {
            $order = OnlineOrder::with('items')->find($orderId);
            if (! $order || $order->status !== 'pending' || ($order->payment_status ?? '') !== 'paid') {
                return null;
            }
            if (! StoreSetting::autoConfirmEnabled($order->payment_method)) {
                return null;
            }

            // Pre-orders are not in stock yet: the admin confirms them.
            if ($order->has_preorder_items || $order->items->contains(fn ($i) => (bool) $i->is_preorder)) {
                $this->noteFailure($order->id, 'Has pre-order items: confirm it manually.');

                return null;
            }

            $warehouseId = $this->resolveWarehouseId($order);
            if (! $warehouseId) {
                $this->noteFailure($order->id, 'No warehouse configured.');

                return null;
            }

            $short = $this->insufficientStock($order, $warehouseId);
            if ($short) {
                $this->noteFailure($order->id, 'Not enough stock: '.implode('; ', array_map(
                    fn ($r) => "{$r['name']} (need {$r['required']}, have {$r['available']})",
                    $short
                )));

                return null;
            }

            $sale = $this->confirm($order, $this->systemUserId());
            Log::info("Online order {$order->id} auto-confirmed after {$order->payment_method} payment (sale {$sale->Ref}).");

            return $sale;
        } catch (\Throwable $e) {
            // Lost a race to another confirm (return leg vs webhook vs admin):
            // the order is already confirmed, nothing to report.
            $current = OnlineOrder::whereKey($orderId)->value('status');
            if ($current !== 'pending') {
                return null;
            }
            Log::warning("Online order {$orderId} auto-confirm failed: ".$e->getMessage());
            // Only our own reasons are shown to the admin; a database or other
            // unexpected error stays in the log.
            $this->noteFailure($orderId, $e instanceof OnlineOrderConfirmException
                ? $e->getMessage()
                : 'Could not be confirmed automatically because of an unexpected error (see the application log). Confirm it manually.');

            return null;
        }
    }

    /** Order warehouse → store default warehouse → first warehouse. */
    public function resolveWarehouseId(OnlineOrder $order): int
    {
        $warehouseId = (int) ($order->warehouse_id ?: (DB::table('store_settings')->value('default_warehouse_id') ?: 0));

        return $warehouseId ?: (int) Warehouse::value('id');
    }

    /**
     * Lines the warehouse cannot cover (no locks — the confirm transaction
     * re-checks with locks). Pre-order items are exempt.
     *
     * @return array<int, array{product_id:int, product_variant_id:?int, name:string, required:float, available:float}>
     */
    public function insufficientStock(OnlineOrder $order, int $warehouseId): array
    {
        $insufficient = [];
        foreach ($this->stockRequirements($order) as $req) {
            $pw = $this->stockRow($warehouseId, $req)->first();
            if ($req['component'] && $pw && ! $pw->manage_stock) {
                continue;
            }

            $have = $pw ? (float) $pw->qte : 0.0;
            if ($have < $req['need']) {
                $insufficient[] = [
                    'product_id' => $req['product_id'],
                    'product_variant_id' => $req['product_variant_id'],
                    'name' => $req['name'],
                    'required' => $req['need'],
                    'available' => $have,
                ];
            }
        }

        return $insufficient;
    }

    /** Keep the reason on the order for the admin (no model events). */
    private function noteFailure(int $orderId, string $reason): void
    {
        if (! self::hasErrorColumn()) {
            return;
        }
        OnlineOrder::whereKey($orderId)->update(['auto_confirm_error' => mb_substr($reason, 0, 500)]);
    }

    private static function hasErrorColumn(): bool
    {
        $db = (string) DB::connection()->getDatabaseName();

        return self::$hasErrorColumn[$db] ??= Schema::hasColumn('online_orders', 'auto_confirm_error');
    }

    /** Who an automatic confirm is booked under: the first admin, else the first user. */
    private function systemUserId(): ?int
    {
        if ($this->systemUserId !== null) {
            return $this->systemUserId ?: null;
        }

        $admin = User::whereNull('deleted_at')
            ->whereHas('roles', fn ($q) => $q->where('roles.id', 1))
            ->value('id');

        $this->systemUserId = (int) ($admin ?: User::whereNull('deleted_at')->value('id'));

        return $this->systemUserId ?: null;
    }

    /**
     * Base units the order takes from stock, per product/variant, pre-order
     * lines excluded. With virtual combo stock on, a combo line becomes the
     * units of its components (merged with lines selling them directly);
     * components that are "Always in stock" or services never limit it.
     *
     * @return array<string, array{product_id:int, product_variant_id:?int, name:string, need:float, component:bool}>
     */
    protected function stockRequirements($order): array
    {
        $comboStock = ComboStockService::enabled() ? app(ComboStockService::class) : null;
        $reqs = [];
        $add = function (int $pid, ?int $vid, string $name, float $need, bool $component) use (&$reqs) {
            $key = $pid.':'.($vid ?? 0);
            if (isset($reqs[$key])) {
                $reqs[$key]['need'] += $need;
                $reqs[$key]['component'] = $reqs[$key]['component'] && $component;
            } else {
                $reqs[$key] = [
                    'product_id' => $pid,
                    'product_variant_id' => $vid,
                    'name' => $name,
                    'need' => $need,
                    'component' => $component,
                ];
            }
        };

        foreach ($order->items as $it) {
            if ($it->is_preorder) {
                continue;
            }

            $product = Product::find($it->product_id);
            $need = $this->requiredBaseQty($it->qty, $this->saleUnitForProduct($product));
            $name = $product ? $product->name : ('#'.$it->product_id);

            $componentNeeds = $comboStock && $product && $product->type === 'is_combo'
                ? $comboStock->componentNeeds($product->id, $need)
                : [];
            if ($componentNeeds) {
                foreach (Product::whereIn('id', array_keys($componentNeeds))->get() as $component) {
                    if ($component->always_in_stock || $component->type === 'is_service') {
                        continue;
                    }
                    $add($component->id, null, $component->name.' ('.$name.')', $componentNeeds[$component->id], true);
                }

                continue;
            }

            // Never short, same as checkout and POS (print-on-demand, services…).
            if ($product && ($product->always_in_stock || $product->type === 'is_service')) {
                continue;
            }

            $add((int) $it->product_id, $it->product_variant_id ? (int) $it->product_variant_id : null, $name, $need, false);
        }

        // Restaurant add-on ingredients are NOT a requirement: the dish was
        // already sold (and maybe paid and cooked), so a short ingredient
        // never blocks the confirm — its stock is taken down to zero below.

        return $reqs;
    }

    /**
     * Ingredient use of an order line's restaurant add-ons (snapshot in
     * online_order_items.options): [{product, qty (sale units), need (base units)}].
     *
     * @return array<int, array{product: Product, qty: float, need: float}>
     */
    protected function addonStock($it): array
    {
        $options = is_array($it->options ?? null) ? $it->options : [];
        $out = [];
        foreach ($options as $o) {
            $pid = (int) ($o['product_id'] ?? 0);
            if (! $pid) {
                continue;
            }
            $product = Product::find($pid);
            if (! $product || $product->type === 'is_service' || $product->always_in_stock) {
                continue;
            }
            $qty = round((float) ($o['stock_qty'] ?? 1) * (float) $it->qty, 3);
            if ($qty <= 0) {
                continue;
            }
            $out[] = ['product' => $product, 'qty' => $qty, 'need' => $this->requiredBaseQty($qty, $this->saleUnitForProduct($product))];
        }

        return $out;
    }

    /** Stock row query for one requirement from stockRequirements(). */
    protected function stockRow(int $warehouseId, array $req)
    {
        return product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $req['product_id'])
            ->when($req['product_variant_id'], function ($q) use ($req) {
                $q->where('product_variant_id', $req['product_variant_id']);
            }, function ($q) use ($req) {
                // Components are single products: their stock is the NULL-variant row.
                if ($req['component']) {
                    $q->whereNull('product_variant_id');
                }
            });
    }

    /**
     * Convert a sold quantity into "base" stock quantity by applying the sale unit operator:
     * '/' → qty / operator_value, otherwise qty * operator_value.
     */
    protected function requiredBaseQty($qty, ?Unit $unit): float
    {
        $q = (float) $qty;
        if (! $unit) {
            return $q;
        }
        $op = $unit->operator ?? '*';
        $v = (float) ($unit->operator_value ?: 1);
        if ($v <= 0) {
            $v = 1;
        }

        return $op === '/' ? ($q / $v) : ($q * $v);
    }

    /** Best-effort: pick a sale unit from the product if present. */
    protected function saleUnitForProduct(?Product $p): ?Unit
    {
        if (! $p) {
            return null;
        }
        $unitId = $p->sale_unit_id ?? $p->unit_sale_id ?? $p->unit_id ?? null;

        return $unitId ? Unit::find($unitId) : null;
    }

    /**
     * Map the online order payment_method string to a payment_methods.id.
     * Credit Card is always id=1 (seeded). Online gateways get a method named
     * after the gateway (Paystack, Flutterwave…) so reports show how the order
     * was really paid; everything else keeps its old mapping. A missing method
     * is created (never as a cash tender).
     */
    public function resolvePaymentMethodId(string $method): int
    {
        // Credit Card is guaranteed id=1 from the original seed
        if ($method === 'credit_card') {
            return 1;
        }

        $nameMap = [
            'mobile_money' => 'Mobile Money',
            'cod' => 'Cash on Delivery',
        ];

        $name = in_array($method, StoreSetting::AUTO_CONFIRM_METHODS, true)
            ? StorePaymentMethodService::label($method)
            : ($nameMap[$method] ?? $nameMap['cod']);

        // Look up existing row (case-insensitive) or create it
        $row = DB::table('payment_methods')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($row) {
            return (int) $row->id;
        }

        $attrs = ['name' => $name, 'created_at' => now(), 'updated_at' => now()];
        if (Schema::hasColumn('payment_methods', 'is_cash')) {
            $attrs['is_cash'] = 0;
        }

        return (int) DB::table('payment_methods')->insertGetId($attrs);
    }

    protected function generatePaymentRef(): string
    {
        $prefix = 'INV/SL';

        $last = DB::table('payment_sales')
            ->where('Ref', 'like', $prefix.'_%')
            ->where('deleted_at', null)
            ->orderByDesc('id')
            ->first();

        if ($last) {
            $parts = explode('_', $last->Ref);
            $seq = isset($parts[1]) && is_numeric($parts[1]) ? ((int) $parts[1] + 1) : 1;
        } else {
            $seq = 1;
        }

        return $prefix.'_'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function getNumberOrder(): string
    {
        // Get prefix from settings, fallback to 'SL' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = ! empty($setting->sale_prefix) ? $setting->sale_prefix : 'SL';

        // Get the last sale with a reference that starts with the prefix.
        // "_" is a LIKE wildcard: match the literal "SL_" and "SL-" forms only
        // (same rule as SalesController::getNumberOrder). No lockForUpdate —
        // this runs inside the confirmation transaction.
        $likePrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $last = DB::table('sales')
            ->where(function ($q) use ($likePrefix) {
                $q->where('Ref', 'like', $likePrefix.'\\_%')
                    ->orWhere('Ref', 'like', $likePrefix.'-%');
            })
            ->latest('id')
            ->first();

        if ($last) {
            $nwMsg = explode('_', $last->Ref);

            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                return $nwMsg[0].'_'.str_pad($nwMsg[1] + 1, 4, '0', STR_PAD_LEFT);
            }
        }

        return $prefix.'_0001';
    }
}
