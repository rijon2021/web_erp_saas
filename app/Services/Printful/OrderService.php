<?php

namespace App\Services\Printful;

use App\Jobs\Printful\SubmitPrintfulOrderJob;
use App\Models\OnlineOrder;
use App\Models\PrintfulMapping;
use App\Models\PrintfulOrder;
use App\Models\PrintfulSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CountryService;
use App\Services\TenantLimitsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Printful fulfilment of storefront orders.
 *
 * An online order whose lines include imported Printful products is sent to
 * Printful (only those lines — anything else the store ships itself) when
 * the store's trigger fires: the order is confirmed in Stocky, it is paid
 * online, or someone presses "Send" on the Orders tab. Orders go in as drafts
 * unless auto-confirm is on (a confirmed Printful order is charged and goes
 * to production). Printful's webhooks, re-read from the API, bring back the
 * status, costs and tracking; a fully shipped order is marked shipped here.
 */
class OrderService
{
    private PrintfulSetting $settings;
    private Client $client;

    public function __construct(PrintfulSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?PrintfulSetting $settings = null): self
    {
        return new self($settings ?: PrintfulSetting::current());
    }

    // -----------------------------------------------------------------
    // Triggers
    // -----------------------------------------------------------------

    /**
     * OnlineOrder model hook: the order was confirmed ('confirmed') or paid
     * ('paid'). Queues it when that is the store's trigger. Never throws —
     * an order save must not fail because of Printful.
     */
    public static function orderChanged(OnlineOrder $order, string $trigger): void
    {
        try {
            if (! self::enabled()) {
                return;
            }
            $settings = PrintfulSetting::query()->first();
            if (! $settings || ! $settings->isReady() || $settings->submit_on !== $trigger) {
                return;
            }
            (new self($settings))->queue($order);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * OnlineOrder model hook: the order was cancelled. A Printful order not
     * sent yet is dropped; a draft or pending one is cancelled in Printful
     * (after the surrounding transaction commits); one already in production
     * is flagged in the log. Never throws.
     */
    public static function orderCancelled(OnlineOrder $order): void
    {
        try {
            if (! self::enabled()) {
                return;
            }
            $po = PrintfulOrder::where('online_order_id', $order->id)->first();
            if (! $po || in_array($po->status, PrintfulOrder::FINAL_STATUSES, true)) {
                return;
            }
            $settings = PrintfulSetting::query()->first();
            if (! $settings) {
                return;
            }
            $service = new self($settings);

            if (! $po->printful_order_id) {
                $po->forceFill(['status' => 'canceled', 'retry_at' => null])->save();
                $service->log('orders.cancel', 'info', 'Order '.$order->ref.' was cancelled before it reached Printful.', ['online_order_id' => $order->id]);

                return;
            }

            DB::afterCommit(function () use ($service, $po, $order) {
                try {
                    if ($po->isCancellable() && $service->settings->isConfigured()) {
                        $service->cancel($po);
                    } else {
                        $service->log('orders.cancel', 'warning', 'Order '.$order->ref.' was cancelled here, but Printful order #'.$po->printful_order_id.' is '.$po->status.' and can no longer be cancelled — check it in Printful.', ['online_order_id' => $order->id]);
                    }
                } catch (\Throwable $e) {
                    $service->log('orders.cancel', 'error', 'Order '.$order->ref.' was cancelled here, but cancelling Printful order #'.$po->printful_order_id.' failed: '.$e->getMessage(), ['online_order_id' => $order->id]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The Printful tables exist on this tenant (memoised per tenant: workers switch tenants). */
    public static function installed(): bool
    {
        static $installed = [];

        return $installed[tenant('id') ?? 'central'] ??= Schema::hasTable('printful_orders') && Schema::hasTable('printful_settings');
    }

    /** Tables exist and the tenant's plan includes Printful. */
    public static function enabled(): bool
    {
        try {
            return self::installed() && app(TenantLimitsService::class)->hasFeature('printful');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Creates the order's Printful row and queues the send (after the
     * surrounding transaction commits). An order already queued or sent is
     * left alone; $retry re-queues one that failed.
     */
    public function queue(OnlineOrder $order, bool $retry = false): ?PrintfulOrder
    {
        if ($order->status === 'cancelled' || $order->isPickup() || ! $this->printfulLines($order)) {
            return null;
        }

        $po = PrintfulOrder::firstOrCreate(['online_order_id' => $order->id], ['status' => PrintfulOrder::STATUS_QUEUED]);
        if (! $po->wasRecentlyCreated) {
            if (! $retry || $po->printful_order_id || $po->status !== PrintfulOrder::STATUS_ERROR) {
                return $po;
            }
            $po->forceFill(['status' => PrintfulOrder::STATUS_QUEUED, 'error' => null])->save();
        }

        $id = $po->id;
        DB::afterCommit(function () use ($id) {
            try {
                // On the sync driver the job would run inside this request (a
                // shopper's checkout, an admin's confirm): send after the
                // response instead. A failure here leaves the row queued for
                // the printful:send-orders sweep.
                if (config('queue.default') === 'sync') {
                    SubmitPrintfulOrderJob::dispatchAfterResponse($id);
                } else {
                    SubmitPrintfulOrderJob::dispatch($id);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $po;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /**
     * Creates the order in Printful. Never throws: a failure Printful
     * explains (bad address, unknown variant…) is recorded on the row for a
     * manual retry; a network / rate-limit failure is recorded with a
     * retry_at the sweep picks up (a few attempts).
     */
    public function submit(PrintfulOrder $po): PrintfulOrder
    {
        if ($po->printful_order_id || $po->status === 'canceled') {
            return $po;
        }

        $order = OnlineOrder::with('items')->find($po->online_order_id);
        if (! $order || $order->status === 'cancelled') {
            return $this->fail($po, 'The online order was cancelled or deleted before it reached Printful.');
        }

        $lines = $this->printfulLines($order);
        if (! $lines) {
            return $this->fail($po, 'The order has no Printful products (were their links removed?).');
        }

        $payload = $this->payload($order, $lines, $po);
        $po->forceFill([
            'external_id' => $payload['external_id'],
            'items' => $payload['items'],
            'attempts' => $po->attempts + 1,
        ])->save();

        $remote = [];
        try {
            $remote = $this->client->post('orders', $payload, ['confirm' => $this->settings->auto_confirm ? 'true' : 'false'])['result'] ?? [];
        } catch (PrintfulException $e) {
            // An earlier attempt may have created it before timing out (a
            // repeated external_id is refused): look it up before failing.
            if (in_array($e->status(), [400, 409], true) || $e->retryable()) {
                try {
                    $remote = $this->client->get('orders/@'.$payload['external_id'])['result'] ?? [];
                } catch (PrintfulException $ignored) {
                    $remote = [];
                }
            }
            if (empty($remote['id'])) {
                return $this->fail($po, $e->getMessage(), ['order' => $order->ref], $e->retryable());
            }
        }

        $po->submitted_at = now();
        $this->apply($po, (array) $remote);
        $this->log('orders.submit', 'info', 'Order '.$order->ref.' sent to Printful as #'.$po->printful_order_id.' ('.$po->status.')', [
            'online_order_id' => $order->id,
            'printful_order_id' => $po->printful_order_id,
        ]);

        return $po;
    }

    /** A Printful draft → pending: Printful charges it and starts production. */
    public function confirm(PrintfulOrder $po): PrintfulOrder
    {
        $remote = $this->client->post('orders/'.$po->printful_order_id.'/confirm')['result'] ?? [];
        $this->apply($po, (array) $remote);
        $this->log('orders.confirm', 'info', 'Printful order #'.$po->printful_order_id.' confirmed', ['online_order_id' => $po->online_order_id]);

        return $po;
    }

    public function cancel(PrintfulOrder $po): PrintfulOrder
    {
        $remote = $this->client->delete('orders/'.$po->printful_order_id)['result'] ?? [];
        $this->apply($po, (array) $remote + ['status' => 'canceled']);
        $this->log('orders.cancel', 'warning', 'Printful order #'.$po->printful_order_id.' cancelled', ['online_order_id' => $po->online_order_id]);

        return $po;
    }

    public function refresh(PrintfulOrder $po): PrintfulOrder
    {
        $remote = $this->client->get('orders/'.$po->printful_order_id)['result'] ?? [];
        $this->apply($po, (array) $remote);

        return $po;
    }

    /** Copies Printful's view of the order onto the row (+ costs, shipped status). */
    public function apply(PrintfulOrder $po, array $remote): void
    {
        if (! empty($remote['id'])) {
            $po->printful_order_id = (int) $remote['id'];
        }
        if (! empty($remote['external_id'])) {
            $po->external_id = (string) $remote['external_id'];
        }
        $status = (string) ($remote['status'] ?? '');
        if ($status !== '') {
            $po->status = $status;
            if ($status !== 'failed') {
                $po->error = null;
            }
        }

        $costs = (array) ($remote['costs'] ?? []);
        // Product costs follow the first costs Printful reports for an order,
        // so refreshing an old order never rolls them back to older prices.
        $firstCosts = $costs && empty($po->costs);
        if ($costs) {
            $po->costs = $costs;
            $po->total_cost = isset($costs['total']) ? (float) $costs['total'] : $po->total_cost;
            $po->currency = isset($costs['currency']) ? strtoupper((string) $costs['currency']) : $po->currency;
        }

        $shipments = [];
        foreach ((array) ($remote['shipments'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $shipments[] = [
                'id' => $s['id'] ?? null,
                'carrier' => $s['carrier'] ?? null,
                'service' => $s['service'] ?? null,
                'tracking_number' => $s['tracking_number'] ?? null,
                'tracking_url' => $s['tracking_url'] ?? null,
                'shipped_at' => $s['shipped_at'] ?? ($s['created'] ?? null),
                'reshipment' => (bool) ($s['reshipment'] ?? false),
            ];
        }
        if ($shipments) {
            $po->shipments = $shipments;
            $last = end($shipments);
            $po->carrier = $last['carrier'] ? mb_substr((string) $last['carrier'], 0, 100) : $po->carrier;
            $po->tracking_number = $last['tracking_number'] ? mb_substr((string) $last['tracking_number'], 0, 191) : $po->tracking_number;
            $po->tracking_url = $last['tracking_url'] ? mb_substr((string) $last['tracking_url'], 0, 500) : $po->tracking_url;
            $po->shipped_at ??= is_numeric($last['shipped_at']) ? now()->setTimestamp((int) $last['shipped_at']) : now();
        }
        $po->save();

        if ($this->settings->sync_costs && $firstCosts) {
            $this->syncCosts((array) ($remote['items'] ?? []), $po->currency);
        }
        if ($po->status === 'fulfilled') {
            $this->markShipped($po);
        }
    }

    /** Retryable failures (network, 429, 5xx) are tried again this many times in all. */
    public const MAX_ATTEMPTS = 4;

    private function fail(PrintfulOrder $po, string $error, array $context = [], bool $retryable = false): PrintfulOrder
    {
        $retryAt = $retryable && $po->attempts < self::MAX_ATTEMPTS ? now()->addMinutes(10 * max(1, $po->attempts)) : null;
        $po->forceFill([
            'status' => PrintfulOrder::STATUS_ERROR,
            'error' => mb_substr($error, 0, 2000),
            'retry_at' => $retryAt,
        ])->save();
        $this->log('orders.submit', 'error', 'Order #'.$po->online_order_id.' not sent: '.$error.($retryAt ? ' (will retry)' : ''), $context + ['online_order_id' => $po->online_order_id]);

        return $po;
    }

    // -----------------------------------------------------------------
    // Payload
    // -----------------------------------------------------------------

    /**
     * Order lines that are Printful products, with their mapping.
     *
     * @return list<array{item: \App\Models\OnlineOrderItem, mapping: PrintfulMapping}>
     */
    public function printfulLines(OnlineOrder $order): array
    {
        $lines = [];
        foreach ($order->items as $item) {
            $mapping = PrintfulMapping::forLine((int) $item->product_id, $item->product_variant_id ? (int) $item->product_variant_id : null);
            if ($mapping) {
                $lines[] = ['item' => $item, 'mapping' => $mapping];
            }
        }

        return $lines;
    }

    /** Every line of the order is a Printful product. */
    public function allPrintful(OnlineOrder $order): bool
    {
        return $order->items->count() > 0 && count($this->printfulLines($order)) === $order->items->count();
    }

    public function payload(OnlineOrder $order, array $lines, PrintfulOrder $po): array
    {
        $country = CountryService::toCode($order->shipping_country) ?? strtoupper(trim((string) $order->shipping_country));
        $recipient = array_filter([
            'name' => trim((string) ($order->customer_name ?: optional($order->client)->name)) ?: 'Customer',
            'address1' => trim((string) $order->shipping_address),
            'city' => trim((string) $order->shipping_city),
            'state_code' => self::stateCode($order->shipping_state, $country),
            'country_code' => $country,
            'zip' => trim((string) $order->shipping_zip),
            'phone' => trim((string) $order->customer_phone),
            'email' => trim((string) $order->customer_email),
        ], fn ($v) => $v !== null && $v !== '');

        $items = [];
        $subtotal = 0.0;
        $priced = true;
        foreach ($lines as $line) {
            $item = $line['item'];
            $qty = max(1, (int) round((float) $item->qty));
            $retail = $this->toPrintfulPrice((float) $item->price);
            $row = [
                'sync_variant_id' => (int) $line['mapping']->printful_id,
                'quantity' => $qty,
                'name' => $this->lineName((int) $item->product_id, $item->product_variant_id ? (int) $item->product_variant_id : null),
            ];
            if ($retail === null) {
                $priced = false;
            } else {
                $row['retail_price'] = number_format($retail, 2, '.', '');
                $subtotal += $retail * $qty;
            }
            $items[] = $row;
        }

        $payload = [
            'external_id' => $po->external_id ?: $this->externalId($order),
            'recipient' => $recipient,
            'items' => $items,
        ];

        // Packing slip + customs values, only in a currency Printful knows.
        if ($priced) {
            $costs = ['currency' => $this->printfulCurrency(), 'subtotal' => number_format($subtotal, 2, '.', '')];
            $shipping = $this->allPrintful($order) ? $this->toPrintfulPrice((float) $order->shipping_cost) : null;
            if ($shipping !== null) {
                $costs['shipping'] = number_format($shipping, 2, '.', '');
            }
            $payload['retail_costs'] = $costs;
        } else {
            foreach ($payload['items'] as &$row) {
                unset($row['retail_price']);
            }
            unset($row);
        }

        // The Printful rate the customer chose at checkout.
        $quote = is_array($order->shipping_quote) ? $order->shipping_quote : [];
        if (($quote['source'] ?? null) === 'printful' && ! empty($quote['service_token'])) {
            $payload['shipping'] = (string) $quote['service_token'];
        }

        return $payload;
    }

    /** Readable in Printful, unique per tenant + order (Printful refuses repeats). */
    private function externalId(OnlineOrder $order): string
    {
        $ref = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order->ref), '-');
        $ref = mb_substr($ref !== '' ? $ref : 'order-'.$order->id, 0, 24);

        return $ref.'-'.substr(sha1((tenant('id') ?? 'central').':'.$order->id), 0, 6);
    }

    private function lineName(int $productId, ?int $variantId): string
    {
        $name = (string) Product::whereKey($productId)->value('name');
        if ($variantId) {
            $variant = (string) ProductVariant::whereKey($variantId)->value('name');
            $name = trim($name.($variant !== '' ? ' - '.$variant : ''));
        }

        return mb_substr($name !== '' ? $name : 'Item', 0, 190);
    }

    // -----------------------------------------------------------------
    // After Printful answers
    // -----------------------------------------------------------------

    /** Item costs Printful charged → the product / variant cost, in store currency. */
    private function syncCosts(array $items, ?string $currency): void
    {
        $catalog = SyncService::make($this->settings);
        foreach ($items as $item) {
            $syncVariantId = (int) ($item['sync_variant_id'] ?? 0);
            $cost = $catalog->toStorePrice($item['price'] ?? null, $currency);
            if (! $syncVariantId || $cost === null || $cost <= 0) {
                continue;
            }

            if ($variantId = PrintfulMapping::localId(PrintfulMapping::TYPE_VARIANT, $syncVariantId)) {
                ProductVariant::whereKey($variantId)->where('cost', '!=', $cost)->update(['cost' => $cost]);
            } elseif ($productId = PrintfulMapping::localId(PrintfulMapping::TYPE_SINGLE, $syncVariantId)) {
                Product::whereKey($productId)->where('cost', '!=', $cost)->update(['cost' => $cost]);
            }
        }
    }

    /**
     * Printful shipped everything: a confirmed order whose lines are all
     * Printful products becomes "shipped". Mixed orders and orders not yet
     * confirmed in Stocky are left for the store to move on.
     */
    private function markShipped(PrintfulOrder $po): void
    {
        if (! $this->settings->mark_shipped) {
            return;
        }
        $order = OnlineOrder::with('items')->find($po->online_order_id);
        if (! $order || $order->status !== 'confirmed') {
            if ($order && $order->status === 'pending') {
                $this->log('orders.shipped', 'warning', 'Printful shipped order '.$order->ref.', but it is still pending here — confirm it to record the sale.', ['online_order_id' => $order->id]);
            }

            return;
        }
        if (! $this->allPrintful($order)) {
            $this->log('orders.shipped', 'info', 'Printful shipped its items of order '.$order->ref.'; the other items are still yours to ship.', ['online_order_id' => $order->id]);

            return;
        }

        $order->status = 'shipped';
        $order->save();
        $this->log('orders.shipped', 'info', 'Order '.$order->ref.' marked shipped ('.trim(($po->carrier ?? '').' '.($po->tracking_number ?? '')).')', ['online_order_id' => $order->id]);
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    /**
     * A Printful webhook. Order events are re-read from the API rather than
     * trusted; product events refresh (or unlink) imported products.
     */
    public function handleWebhook(string $type, array $data): void
    {
        if (str_starts_with($type, 'package_') || str_starts_with($type, 'order_')) {
            $remote = (array) ($data['order'] ?? []);
            $po = null;
            if (! empty($remote['id'])) {
                $po = PrintfulOrder::where('printful_order_id', (int) $remote['id'])->first();
            }
            if (! $po && ! empty($remote['external_id'])) {
                $po = PrintfulOrder::where('external_id', (string) $remote['external_id'])->first();
            }
            if (! $po) {
                $this->log('webhook.'.$type, 'info', 'Ignored: Printful order #'.($remote['id'] ?? '?').' was not sent from this store.');

                return;
            }
            if (! $po->printful_order_id && ! empty($remote['id'])) {
                $po->printful_order_id = (int) $remote['id'];
            }
            if (! $po->printful_order_id) {
                return;
            }

            $this->refresh($po);
            $level = in_array($type, ['order_failed', 'order_canceled', 'order_put_hold', 'package_returned'], true) ? 'warning' : 'info';
            $reason = trim((string) ($data['reason'] ?? ''));
            $this->log('webhook.'.$type, $level, 'Printful order #'.$po->printful_order_id.' → '.$po->status.($reason !== '' ? ' ('.$reason.')' : ''), [
                'online_order_id' => $po->online_order_id,
            ]);
            if ($type === 'order_failed' && $reason !== '') {
                $po->forceFill(['error' => mb_substr($reason, 0, 2000)])->save();
            }

            return;
        }

        if (str_starts_with($type, 'product_')) {
            $syncProductId = (int) ($data['sync_product']['id'] ?? 0);
            $localId = $syncProductId ? PrintfulMapping::localId(PrintfulMapping::TYPE_PRODUCT, $syncProductId) : null;
            if (! $localId) {
                $this->log('webhook.'.$type, 'info', 'Printful product #'.$syncProductId.' is not imported yet — use Import products to add it.');

                return;
            }

            if ($type === 'product_deleted') {
                $this->unlinkProduct($localId);
                $this->log('webhook.'.$type, 'warning', 'Printful product #'.$syncProductId.' was deleted: its linked product was deactivated and unlinked.', ['product_id' => $localId]);

                return;
            }

            $result = SyncService::make($this->settings)->importSyncProduct($syncProductId);
            $this->log('webhook.'.$type, 'info', 'Printful product #'.$syncProductId.' refreshed ('.$result.')', ['product_id' => $localId]);
        }
    }

    private function unlinkProduct(int $productId): void
    {
        $variantIds = ProductVariant::where('product_id', $productId)->pluck('id');
        PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_VARIANT)->whereIn('local_id', $variantIds)->delete();
        PrintfulMapping::whereIn('entity_type', [PrintfulMapping::TYPE_SINGLE, PrintfulMapping::TYPE_PRODUCT])->where('local_id', $productId)->delete();
        Product::whereKey($productId)->update(['is_active' => 0]);
    }

    // -----------------------------------------------------------------
    // Shipping rates (checkout)
    // -----------------------------------------------------------------

    /**
     * Printful's shipping rates for the cart's Printful lines, in Printful's
     * currency. [] when the cart has none; throws when Printful fails.
     *
     * @param  array  $lines  checkout lines (product_id, product_variant_id, qty, price)
     * @return list<array{key: string, token: string, carrier: string, service: string, amount: float, currency: string, days: ?int}>
     */
    public function rates(array $lines, array $destination): array
    {
        $items = $this->rateItems($lines);
        if (! $items) {
            return [];
        }

        $country = CountryService::toCode($destination['country'] ?? null) ?? strtoupper(trim((string) ($destination['country'] ?? '')));
        $recipient = array_filter([
            'address1' => trim((string) ($destination['address'] ?? '')),
            'city' => trim((string) ($destination['city'] ?? '')),
            'country_code' => $country,
            'state_code' => self::stateCode($destination['state'] ?? null, $country),
            'zip' => trim((string) ($destination['zip'] ?? '')),
            'phone' => trim((string) ($destination['phone'] ?? '')),
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->client->quick()->post('shipping/rates', [
            'recipient' => $recipient,
            'items' => $items,
            'currency' => $this->printfulCurrency(),
        ])['result'] ?? [];

        $rates = [];
        foreach ((array) $result as $rate) {
            if (! is_array($rate) || empty($rate['id']) || ! is_numeric($rate['rate'] ?? null)) {
                continue;
            }
            $days = $rate['maxDeliveryDays'] ?? ($rate['minDeliveryDays'] ?? null);
            $rates[] = [
                'key' => (string) $rate['id'],
                'token' => (string) $rate['id'],
                'carrier' => 'Printful',
                'service' => trim(preg_replace('/\s*\(.*\)\s*$/', '', (string) ($rate['name'] ?? $rate['id']))),
                'amount' => round((float) $rate['rate'], 2),
                'currency' => strtoupper((string) ($rate['currency'] ?? $this->printfulCurrency())),
                'days' => is_numeric($days) ? (int) $days : null,
            ];
        }

        return $rates;
    }

    /** Rate-request items (catalog variant id + qty) for the Printful lines of a cart. */
    public function rateItems(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $mapping = PrintfulMapping::forLine((int) ($line['product_id'] ?? 0), ! empty($line['product_variant_id']) ? (int) $line['product_variant_id'] : null);
            $catalogId = (int) ($mapping->extra['catalog_variant_id'] ?? 0);
            if (! $catalogId) {
                continue;
            }
            $row = ['variant_id' => (string) $catalogId, 'quantity' => max(1, (int) round((float) ($line['qty'] ?? 1)))];
            $value = isset($line['price']) ? $this->toPrintfulPrice((float) $line['price']) : null;
            if ($value !== null) {
                $row['value'] = number_format($value, 2, '.', '');
            }
            $items[] = $row;
        }

        return $items;
    }

    // -----------------------------------------------------------------
    // Currency
    // -----------------------------------------------------------------

    public function printfulCurrency(): string
    {
        return strtoupper((string) ($this->settings->currency ?: 'USD'));
    }

    /** A store-currency amount in Printful's currency, null when unknown. */
    public function toPrintfulPrice(float $amount): ?float
    {
        $store = SyncService::make($this->settings)->storeCurrency();
        if ($store === $this->printfulCurrency()) {
            return round($amount, 2);
        }
        $rate = (float) $this->settings->exchange_rate;

        return $rate > 0 ? round($amount / $rate, 2) : null;
    }

    /**
     * Printful wants ISO state codes for the US, Canada and Australia (and
     * takes them elsewhere). Checkout collects free text, so names of those
     * countries' states are translated; short values pass through.
     */
    public static function stateCode($state, ?string $country): ?string
    {
        $state = trim((string) $state);
        if ($state === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9]{1,3}$/', $state)) {
            return strtoupper($state);
        }
        $key = mb_strtolower(preg_replace('/\s+/', ' ', $state));

        return self::STATES[strtoupper((string) $country)][$key] ?? null;
    }

    private const STATES = [
        'US' => [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
            'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC',
            'washington dc' => 'DC', 'washington, dc' => 'DC', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI',
            'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY',
            'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI',
            'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
            'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
            'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
            'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
            'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA',
            'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
            'puerto rico' => 'PR', 'guam' => 'GU', 'us virgin islands' => 'VI', 'american samoa' => 'AS',
            'northern mariana islands' => 'MP',
        ],
        'CA' => [
            'alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB', 'new brunswick' => 'NB',
            'newfoundland and labrador' => 'NL', 'newfoundland' => 'NL', 'nova scotia' => 'NS', 'ontario' => 'ON',
            'prince edward island' => 'PE', 'quebec' => 'QC', 'québec' => 'QC', 'saskatchewan' => 'SK',
            'northwest territories' => 'NT', 'nunavut' => 'NU', 'yukon' => 'YT',
        ],
        'AU' => [
            'new south wales' => 'NSW', 'victoria' => 'VIC', 'queensland' => 'QLD', 'south australia' => 'SA',
            'western australia' => 'WA', 'tasmania' => 'TAS', 'northern territory' => 'NT',
            'australian capital territory' => 'ACT',
        ],
    ];

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        SyncService::make($this->settings)->log($action, $level, $message, $context);
    }
}
