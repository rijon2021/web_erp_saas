<?php

namespace App\Services\Opencart;

use App\Models\Client as Customer;
use App\Models\OpencartMapping;
use App\Models\OpencartSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ChannelSales;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * OpenCart orders as Stocky sales. One entry point, apply(), used by the
 * webhook, the scheduled catch-up and the Pull button, and safe to repeat:
 * it compares the order with the sale and only does what has changed.
 *
 * The rule that keeps stock honest: in Stocky stock moves exactly when a sale
 * is completed, and every screen that edits or deletes a sale relies on it. So
 * a sale is completed exactly while the OpenCart status is one that has taken
 * stock there (commit_status_ids), which by default includes Pending.
 *
 * Statuses in cancel_status_ids reverse the sale, restoring stock and removing
 * its payments, unless goods have already been delivered or returned. Then
 * nothing is undone automatically and the sale is flagged for a person.
 */
class OrderSync
{
    /** Pages of 25 one scheduled run will read; the per-tenant lock bounds its time. */
    private const PAGE_LIMIT = 2000;

    /** An order that keeps failing is given up after this many scheduled runs. */
    private const RETRY_ATTEMPTS = 12;

    /** Re-read a little before the cursor: a change can land mid-page. */
    private const CURSOR_OVERLAP_MINUTES = 2;

    private OpencartSetting $settings;
    private Client $client;
    private SyncService $sync;

    public function __construct(OpencartSetting $settings)
    {
        $this->settings = $settings;
        $this->client = Client::make($settings);
        $this->sync = new SyncService($settings, $this->client);
    }

    public static function make(?OpencartSetting $settings = null): self
    {
        return new self($settings ?: OpencartSetting::current());
    }

    // -----------------------------------------------------------------
    // Entry points
    // -----------------------------------------------------------------

    /** The Pull button: orders placed since a date, oldest change first. */
    public function pullOrders(int $page, int $perPage, ?string $since, ?int $warehouseId): array
    {
        $page = max(1, $page);
        $response = $this->client->call('orders.list', [
            'page' => $page,
            'limit' => $perPage,
            'store_id' => (int) $this->settings->store_id,
            'created_since' => $since ? Carbon::parse($since)->startOfDay()->format('Y-m-d H:i:s') : null,
        ], 120);

        $tally = $this->applyAll((array) ($response['orders'] ?? []), $warehouseId);

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->sync->log('orders.pull', ($tally['failed'] || $tally['held']) ? 'warning' : 'info', "Orders pull page {$page}: ".$this->summary($tally), [
            'errors' => array_slice($tally['errors'], 0, 10),
        ]);

        $hasMore = ! empty($response['has_more']);

        return ['ok' => true] + $tally + [
            'next_page_info' => $hasMore ? $page + 1 : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * The scheduled safety net: everything changed since the last run. If a
     * page cannot be read the cursor stays put and the next run covers it.
     */
    public function catchUp(): array
    {
        if (! $this->settings->orders_cursor) {
            $this->settings->orders_cursor = $this->client->call('ping')['server_time'] ?? null;
            $this->settings->save();

            return ['started' => true];
        }

        $since = Carbon::parse($this->settings->orders_cursor)->subMinutes(self::CURSOR_OVERLAP_MINUTES)->format('Y-m-d H:i:s');
        $totals = ['imported' => 0, 'updated' => 0, 'reversed' => 0, 'held' => 0, 'failed' => 0];
        $newCursor = null;
        $retry = $this->retryPending($totals);

        for ($page = 1; $page <= self::PAGE_LIMIT; $page++) {
            $response = $this->client->call('orders.list', [
                'page' => $page,
                'limit' => 25,
                'store_id' => (int) $this->settings->store_id,
                'modified_since' => $since,
            ], 120);

            $newCursor = $newCursor ?: ($response['server_time'] ?? null);

            $tally = $this->applyAll((array) ($response['orders'] ?? []), null);
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $tally[$key];
            }
            foreach ($tally['retry'] as $orderId) {
                $retry[$orderId] = $retry[$orderId] ?? 0;
            }
            if ($tally['failed']) {
                $this->sync->log('orders.catch_up', 'warning', 'Scheduled order pull: '.$this->summary($tally), [
                    'errors' => array_slice($tally['errors'], 0, 10),
                ]);
            }

            if (empty($response['has_more'])) {
                if ($newCursor) {
                    $this->settings->orders_cursor = $newCursor;
                }
                break;
            }

            // The cursor stays where it was: the next run reads it all again.
            if ($page === self::PAGE_LIMIT) {
                $this->sync->log('orders.catch_up', 'error', 'Scheduled order pull stopped after '.(self::PAGE_LIMIT * 25).' orders and will start over next time. Pull orders by hand to clear the backlog.');
            }
        }

        $this->settings->orders_retry = $retry ?: null;
        $this->settings->last_sync_at = now();
        $this->settings->save();

        return $totals;
    }

    /**
     * Orders that failed or were held on an earlier run are read again, one
     * by one, before the cursor moves on past them for good. Each attempt is
     * counted; one that never goes through is logged and dropped.
     *
     * @return array<int, int> order id => attempts so far, for what still waits
     */
    private function retryPending(array &$totals): array
    {
        $pending = (array) ($this->settings->orders_retry ?? []);
        $kept = [];

        foreach ($pending as $orderId => $attempts) {
            $orderId = (int) $orderId;
            $attempts = (int) $attempts + 1;

            try {
                $order = $this->client->call('orders.get', ['order_id' => $orderId])['order'] ?? null;
                $result = is_array($order) ? $this->apply($order, null) : 'skipped';
                if ($result !== 'held') {
                    if (in_array($result, ['imported', 'updated', 'reversed'], true) && is_array($order)) {
                        $totals[$result]++;
                        AutoSync::stockTouched($this->productIdsOf($order));
                    }

                    continue;
                }
            } catch (\Throwable $e) {
                $totals['failed']++;
            }

            if ($attempts >= self::RETRY_ATTEMPTS) {
                $this->sync->log('orders.retry', 'error', 'Order '.$orderId.' could not be imported after '.$attempts.' tries and was given up. Pull it by hand once its products exist here.');

                continue;
            }

            $kept[$orderId] = $attempts;
        }

        return $kept;
    }

    /** A webhook said this order changed. */
    public function syncOrder(int $orderId): string
    {
        $order = $this->client->call('orders.get', ['order_id' => $orderId])['order'] ?? null;
        if (! is_array($order)) {
            return 'skipped';
        }

        $result = $this->apply($order, null);

        // OpenCart moved its own stock for this order whatever Stocky did, so
        // its figures are stale either way.
        AutoSync::stockTouched($this->productIdsOf($order));

        return $result;
    }

    private function applyAll(array $orders, ?int $warehouseId): array
    {
        $tally = ['imported' => 0, 'updated' => 0, 'reversed' => 0, 'skipped' => 0, 'held' => 0, 'failed' => 0, 'errors' => [], 'retry' => []];

        foreach ($orders as $order) {
            $order = (array) $order;
            try {
                $result = $this->apply($order, $warehouseId);
                if (in_array($result, ['imported', 'updated', 'reversed', 'held'], true)) {
                    $tally[$result]++;
                } else {
                    $tally['skipped']++;
                }
                if (in_array($result, ['imported', 'updated', 'reversed'], true)) {
                    AutoSync::stockTouched($this->productIdsOf($order));
                }
                if ($result === 'held') {
                    $tally['errors'][] = ['opencart_order_id' => $order['order_id'] ?? null, 'error' => 'Held: some items match no product'];
                    $tally['retry'][] = (int) ($order['order_id'] ?? 0);
                }
            } catch (\Throwable $e) {
                $tally['failed']++;
                $tally['errors'][] = ['opencart_order_id' => $order['order_id'] ?? null, 'error' => $e->getMessage()];
                $tally['retry'][] = (int) ($order['order_id'] ?? 0);
            }
        }

        return $tally;
    }

    private function summary(array $tally): string
    {
        return "{$tally['imported']} imported, {$tally['updated']} updated, {$tally['reversed']} reversed, {$tally['skipped']} unchanged, {$tally['held']} held, {$tally['failed']} failed";
    }

    // -----------------------------------------------------------------
    // Applying one order
    // -----------------------------------------------------------------

    /**
     * @return string imported | updated | reversed | review | held | skipped
     */
    public function apply(array $order, ?int $warehouseId): string
    {
        $orderId = (int) ($order['order_id'] ?? 0);
        if (! $orderId) {
            return 'skipped';
        }

        // The webhook and the scheduled pull can meet on one order.
        $lock = Cache::store('file')->lock('opencart:order:'.(tenant('id') ?? 'central').':'.$orderId, 60);

        try {
            return $lock->block(20, fn () => $this->applyLocked($order, $orderId, $warehouseId));
        } catch (LockTimeoutException $e) {
            return 'skipped';
        } catch (QueryException $e) {
            // The unique mapping refused a second import that got past the lock.
            if (str_contains($e->getMessage(), 'oc_mappings_type_remote_unique')) {
                return 'skipped';
            }

            throw $e;
        }
    }

    private function applyLocked(array $order, int $orderId, ?int $warehouseId): string
    {
        $statusId = (int) ($order['status_id'] ?? 0);
        $mapping = OpencartMapping::where('entity_type', OpencartMapping::TYPE_ORDER)->where('opencart_id', $orderId)->first();
        $sale = $mapping ? Sale::whereNull('deleted_at')->find($mapping->local_id) : null;

        // The sale was reversed or deleted: the link no longer means anything.
        if ($mapping && ! $sale) {
            $mapping->delete();
        }

        if (in_array($statusId, (array) $this->settings->cancel_status_ids, true)) {
            return $sale ? $this->reverse($sale, $order) : 'skipped';
        }

        return $sale ? $this->update($sale, $order) : $this->import($order, $warehouseId);
    }

    private function import(array $order, ?int $warehouseId): string
    {
        $orderId = (int) $order['order_id'];

        [$lines, $unmatched] = $this->resolveLines((array) ($order['lines'] ?? []));

        // Part of an order would leave its total and its stock both wrong.
        // Held orders are picked up by the next pull once the product exists.
        if ($unmatched || ! $lines) {
            $this->sync->log('orders.import', 'warning', 'Order '.$orderId.' held: some items match no product', [
                'opencart_order_id' => $orderId,
                'unmatched' => $unmatched,
            ]);

            return 'held';
        }

        $warehouseId = $this->resolveWarehouseId($warehouseId);
        $clientId = $this->resolveClientId($order);
        $userId = $this->defaultUserId();
        $placed = $this->placedAt($order);
        $money = $this->money($order);
        $statusId = (int) $order['status_id'];
        $completed = $this->takesStock($statusId);
        $paid = $this->isPaid($statusId) ? $money['total'] : 0.0;

        DB::transaction(function () use ($order, $orderId, $lines, $warehouseId, $clientId, $userId, $placed, $money, $completed, $paid) {
            $sale = Sale::create([
                'date' => $placed->format('Y-m-d'),
                'time' => $placed->format('H:i:s'),
                'Ref' => 'OC-'.$orderId,
                'is_pos' => 0,
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'tax_rate' => 0,
                'TaxNet' => $money['tax'],
                'discount' => $money['discount'],
                'discount_Method' => '2',
                'shipping' => $money['shipping'],
                'GrandTotal' => $money['total'],
                'paid_amount' => 0,
                'payment_statut' => Sale::paymentStatusFor($money['total'], 0),
                'statut' => $completed ? 'completed' : 'pending',
                'notes' => $this->notes($order),
            ]);

            $rows = [];
            foreach ($lines as $line) {
                $rows[] = [
                    'date' => $placed->format('Y-m-d'),
                    'sale_id' => $sale->id,
                    'sale_unit_id' => $line['unit_id'],
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['variant_id'],
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'TaxNet' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_method' => '2',
                    'price_type' => 'retail',
                    'total' => $line['total'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('sale_details')->insert($rows);

            // First, so the unique index stops a duplicate before stock moves.
            OpencartMapping::put(OpencartMapping::TYPE_ORDER, (int) $sale->id, $orderId, ['reference' => $order['reference'] ?? null]);

            if ($completed) {
                ChannelSales::moveStock($sale, -1);
            }

            if ($paid > 0) {
                $this->recordPayment($sale, $paid, $order, $placed->format('Y-m-d'));
            }
        }, 3);

        return 'imported';
    }

    private function update(Sale $sale, array $order): string
    {
        $statusId = (int) $order['status_id'];

        // Pending and ordered both hold no stock, so a sale someone moved
        // between them here is left as it is.
        $completed = $this->takesStock($statusId);
        $changed = ($sale->statut === 'completed') !== $completed
            && ChannelSales::followStatus($sale, $completed ? 'completed' : 'pending');

        if ($this->isPaid($statusId) && ChannelSales::due($sale) > 0) {
            $this->recordPayment($sale, ChannelSales::due($sale), $order, now()->toDateString());
            $changed = true;
        }

        return $changed ? 'updated' : 'skipped';
    }

    private function reverse(Sale $sale, array $order): string
    {
        if (! ChannelSales::reverse($sale)) {
            return $this->flagForReview($sale, $order);
        }

        OpencartMapping::where('entity_type', OpencartMapping::TYPE_ORDER)->where('local_id', $sale->id)->delete();

        $this->sync->log('orders.reverse', 'info', 'Sale '.$sale->Ref.' reversed: the OpenCart order was cancelled', [
            'sale_id' => $sale->id,
            'opencart_order_id' => $order['order_id'] ?? null,
        ]);

        return 'reversed';
    }

    private function flagForReview(Sale $sale, array $order): string
    {
        $flag = 'OpenCart order cancelled after delivery or return: review this sale.';

        if (! str_contains((string) $sale->notes, $flag)) {
            $sale->notes = trim($flag."\n".(string) $sale->notes);
            $sale->save();

            $this->sync->log('orders.review', 'warning', 'Sale '.$sale->Ref.' needs review: cancelled in OpenCart after delivery or return', [
                'sale_id' => $sale->id,
                'opencart_order_id' => $order['order_id'] ?? null,
            ]);
        }

        return 'review';
    }

    // -----------------------------------------------------------------
    // Stock and money
    // -----------------------------------------------------------------

    private function recordPayment(Sale $sale, float $amount, array $order, string $date): void
    {
        $title = trim((string) ($order['payment_method'] ?? ''));

        ChannelSales::recordPayment($sale, $amount, 'OC-'.$order['order_id'], $title, 'OpenCart order #'.$order['order_id'].($title !== '' ? ' ('.$title.')' : ''), $date);
    }

    /**
     * Order totals as a sale. Tax and shipping are their own rows; anything
     * else positive (handling, low-order fee) has no column of its own, so it
     * joins shipping, and anything negative is a discount (coupon, voucher,
     * reward). That keeps lines + tax + shipping - discount equal to the total.
     */
    private function money(array $order): array
    {
        $tax = 0.0;
        $shipping = 0.0;
        $discount = 0.0;
        $total = (float) ($order['total'] ?? 0);

        foreach ((array) ($order['totals'] ?? []) as $row) {
            $code = (string) ($row['code'] ?? '');
            $value = (float) ($row['value'] ?? 0);

            if ($code === 'total') {
                $total = $value;
            } elseif ($code === 'tax') {
                $tax += $value;
            } elseif ($code !== 'sub_total') {
                $value < 0 ? $discount += abs($value) : $shipping += $value;
            }
        }

        return ['tax' => round($tax, 2), 'shipping' => round($shipping, 2), 'discount' => round($discount, 2), 'total' => round($total, 2)];
    }

    private function takesStock(int $statusId): bool
    {
        return in_array($statusId, (array) $this->settings->commit_status_ids, true);
    }

    private function isPaid(int $statusId): bool
    {
        return in_array($statusId, (array) $this->settings->paid_status_ids, true);
    }

    // -----------------------------------------------------------------
    // Lines
    // -----------------------------------------------------------------

    /** @return array{0: array, 1: array} [resolved lines, labels of what did not match] */
    private function resolveLines(array $lines): array
    {
        $resolved = [];
        $unmatched = [];

        foreach ($lines as $line) {
            $line = (array) $line;
            $match = $this->matchLine($line);

            // A product added to the shop after the last pull comes in now,
            // with the units this order holds counted back into its stock.
            if (! $match && (int) ($line['product_id'] ?? 0)) {
                $heldId = (int) ($line['variant_product_id'] ?? 0) ?: (int) $line['product_id'];
                try {
                    AutoSync::muted(fn () => $this->sync->importProductById((int) $line['product_id'], [$heldId => (float) ($line['quantity'] ?? 1)]));
                } catch (\Throwable $e) {
                    // Treated as unmatched below.
                }
                $match = $this->matchLine($line);
            }

            if (! $match) {
                $unmatched[] = trim((string) ($line['model'] ?? '')) ?: (string) ($line['name'] ?? 'item');
                continue;
            }

            $product = Product::find($match['product_id']);
            $quantity = (float) ($line['quantity'] ?? 1);

            $resolved[] = $match + [
                'unit_id' => $product && $product->unit_sale_id ? (int) $product->unit_sale_id : null,
                'quantity' => $quantity,
                'price' => (float) ($line['price'] ?? 0),
                'total' => round((float) ($line['total'] ?? 0), 2),
            ];
        }

        return [$resolved, $unmatched];
    }

    /** @return array{product_id:int, variant_id:?int}|null */
    private function matchLine(array $line): ?array
    {
        // The variant, by link, then the master by link.
        $variantRemoteId = (int) ($line['variant_product_id'] ?? 0);
        if ($variantRemoteId) {
            $variantId = OpencartMapping::localId(OpencartMapping::TYPE_VARIANT, $variantRemoteId);
            $variant = $variantId ? ProductVariant::whereNull('deleted_at')->find($variantId) : null;
            if ($variant) {
                return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
            }
        }

        $productRemoteId = (int) ($line['master_id'] ?? 0) ?: (int) ($line['product_id'] ?? 0);
        $productId = OpencartMapping::localId(OpencartMapping::TYPE_PRODUCT, $productRemoteId);
        $product = $productId ? Product::whereNull('deleted_at')->find($productId) : null;
        if ($product && $product->type !== 'is_variant') {
            return ['product_id' => (int) $product->id, 'variant_id' => null];
        }

        // Not linked yet: the code (the field the settings name first), variant first.
        foreach ($this->settings->codeCandidates((string) ($line['model'] ?? ''), OpencartSetting::remoteCodes($line)) as $code) {
            $variant = ProductVariant::whereNull('deleted_at')->where('code', $code)->first();
            if ($variant) {
                return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
            }

            $product = Product::whereNull('deleted_at')->where('code', $code)->where('type', '!=', 'is_variant')->first();
            if ($product) {
                return ['product_id' => (int) $product->id, 'variant_id' => null];
            }
        }

        return null;
    }

    /** @return int[] local product ids, combos' components included by AutoSync */
    private function productIdsOf(array $order): array
    {
        $ids = [];
        foreach ((array) ($order['lines'] ?? []) as $line) {
            $match = $this->matchLine((array) $line);
            if ($match) {
                $ids[] = $match['product_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    // -----------------------------------------------------------------
    // Customer, warehouse, details
    // -----------------------------------------------------------------

    private function resolveClientId(array $order): int
    {
        $remoteId = (int) ($order['customer_id'] ?? 0);
        $localId = $remoteId ? OpencartMapping::localId(OpencartMapping::TYPE_CUSTOMER, $remoteId) : null;
        $client = $localId ? Customer::whereNull('deleted_at')->find($localId) : null;

        $email = trim((string) ($order['email'] ?? ''));
        if (! $client && $email !== '') {
            $client = Customer::whereNull('deleted_at')->where('email', $email)->first();
        }

        if (! $client) {
            $name = trim(((string) ($order['firstname'] ?? '')).' '.((string) ($order['lastname'] ?? '')));

            // Nothing to tell this buyer apart by: the default walk-in customer.
            if ($name === '' && $email === '') {
                $walkIn = (int) (Setting::current()->client_id ?? 0);
                if ($walkIn) {
                    return $walkIn;
                }
            }

            $address = (array) ($order['address'] ?? []);

            $client = new Customer();
            $client->code = (int) (DB::table('clients')->max('code') ?? 0) + 1;
            $client->name = $name !== '' ? $name : ($email !== '' ? $email : 'OpenCart customer');
            $client->email = $email !== '' ? $email : null;
            $client->phone = (string) ($order['telephone'] ?? '');
            $client->adresse = trim(((string) ($address['address_1'] ?? '')).' '.((string) ($address['address_2'] ?? '')));
            $client->city = (string) ($address['city'] ?? '');
            $client->state = (string) ($address['zone'] ?? '');
            $client->zip = (string) ($address['postcode'] ?? '');
            $client->country = (string) ($address['country'] ?? '');
            $client->save();
        }

        if ($remoteId) {
            OpencartMapping::put(OpencartMapping::TYPE_CUSTOMER, (int) $client->id, $remoteId);
        }

        return (int) $client->id;
    }

    private function resolveWarehouseId(?int $warehouseId): int
    {
        $id = $warehouseId
            ?: $this->settings->warehouse_id
            ?: (int) (Setting::current()->warehouse_id ?? 0)
            ?: (int) Warehouse::whereNull('deleted_at')->orderBy('id')->value('id');

        if (! $id) {
            throw new \RuntimeException('No warehouse available for order import');
        }

        return (int) $id;
    }

    private function defaultUserId(): int
    {
        return (int) User::orderBy('id')->value('id');
    }

    private function placedAt(array $order): Carbon
    {
        try {
            return Carbon::parse((string) ($order['date_added'] ?? ''));
        } catch (\Throwable $e) {
            return now();
        }
    }

    private function notes(array $order): string
    {
        $parts = ['Imported from OpenCart order #'.$order['order_id']];
        if (! empty($order['payment_method'])) {
            $parts[] = 'Payment: '.$order['payment_method'];
        }
        if (! empty($order['shipping_method'])) {
            $parts[] = 'Shipping: '.$order['shipping_method'];
        }
        if (! empty($order['comment'])) {
            $parts[] = 'Customer note: '.$order['comment'];
        }

        return implode("\n", $parts);
    }
}
