<?php

namespace App\Services\Couriers\Drivers;

use App\Models\Shipment;
use App\Services\Couriers\AbstractCourierDriver;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\CourierStatus;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Data\WebhookUpdate;
use App\Services\Couriers\Support\Phone;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Quiqup (UAE last-mile) — the delivery-only "Ecommerce API" from Quiqup's
 * official Stoplight specs. Production https://api-ae.quiqup.com, staging
 * https://api.staging.quiqup.com. OAuth2 client credentials → Bearer token
 * (7 days live, 1 hour staging; cached). Orders are created "pending" (ignored
 * by dispatch) and then marked ready_for_collection. Label = PDF per parcel.
 * Quiqup has no history endpoint, so the timeline comes from state changes and
 * webhooks (X-Signature: sha1=HMAC-SHA1(raw body, token)).
 */
class QuiqupDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://api-ae.quiqup.com/';
    private const STAGING = 'https://api.staging.quiqup.com/';

    private const STATES = [
        'pending' => CourierStatus::BOOKED, 'ready_for_collection' => CourierStatus::BOOKED,
        'out_for_collection' => CourierStatus::BOOKED, 'collection_failed' => CourierStatus::BOOKED,
        'picking_started' => CourierStatus::BOOKED,
        'collected' => CourierStatus::PICKED_UP,
        'received_at_depot' => CourierStatus::IN_TRANSIT, 'at_depot' => CourierStatus::IN_TRANSIT,
        'scheduled' => CourierStatus::IN_TRANSIT, 'transit' => CourierStatus::IN_TRANSIT, 'in_transit' => CourierStatus::IN_TRANSIT,
        'on_hold' => CourierStatus::IN_TRANSIT, 'wms_on_hold' => CourierStatus::IN_TRANSIT, 'problematic' => CourierStatus::IN_TRANSIT,
        'out_for_delivery' => CourierStatus::OUT_FOR_DELIVERY,
        'delivery_failed' => CourierStatus::FAILED_ATTEMPT,
        'delivery_complete' => CourierStatus::DELIVERED,
        'return_to_origin' => CourierStatus::RETURNING, 'out_for_return' => CourierStatus::RETURNING,
        'returned_to_origin' => CourierStatus::RETURNED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'quiqup',
            'name' => 'Quiqup',
            'region' => 'UAE',
            'countries' => ['AE'],
            'website' => 'https://www.quiqup.com',
            'tracking_url' => null,
            'description' => 'Same-day, next-day and 4-hour delivery across the UAE with cash or card on delivery, parcel labels and signed status webhooks.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => 'API keys from Quiqup (apisupport@quiqup.com); staging and live keys differ.'],
                ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'required' => true],
                ['key' => 'webhook_secret', 'label' => 'Webhook signing token', 'type' => 'password',
                    'help' => 'Token Quiqup gives you for the webhook X-Signature.'],
            ],
            'settings' => [
                ['key' => 'kind', 'label' => 'Service', 'type' => 'select', 'options' => [
                    ['value' => 'partner_next_day', 'label' => 'Next day'], ['value' => 'partner_same_day', 'label' => 'Same day'],
                    ['value' => 'partner_4hr', 'label' => 'Within 4 hours'],
                ], 'help' => 'Leave empty for next day.'],
                ['key' => 'billing_identifier', 'label' => 'Billing identifier', 'type' => 'text',
                    'help' => 'Only when Quiqup set up a billing ID for this account.'],
                ['key' => 'origin_lat', 'label' => 'Pickup latitude', 'type' => 'number'],
                ['key' => 'origin_lng', 'label' => 'Pickup longitude', 'type' => 'number'],
                ['key' => 'card_only', 'label' => 'Refuse cash on delivery (card only)', 'type' => 'switch'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $this->token(true);
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }

        return ConnectionResult::ok('Connected to Quiqup.');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $acc = $this->account;
        $cod = $request->codAmount > 0;
        $originCoords = is_numeric($acc->setting('origin_lng')) && is_numeric($acc->setting('origin_lat'))
            ? [(float) $acc->setting('origin_lng'), (float) $acc->setting('origin_lat')] : null;

        $items = [];
        for ($i = 1; $i <= $request->pieces; $i++) {
            $items[] = ['name' => mb_substr(($request->itemsDescription ?: 'Parcel') . " ({$i} of {$request->pieces})", 0, 190), 'quantity' => 1];
        }

        $body = array_filter([
            'kind' => $request->service && str_starts_with($request->service, 'partner_') ? $request->service : ($acc->setting('kind') ?: 'partner_next_day'),
            'notes' => $request->notes ?: null,
            'payment_mode' => $cod ? 'paid_on_delivery' : 'pre_paid',
            'payment_amount' => $cod ? round($request->codAmount, 2) : 0,
            'disallowed_payment_types' => $cod && $acc->setting('card_only') ? ['cash'] : null,
            'billing_identifier' => $acc->setting('billing_identifier') ?: null,
            'partner_order_id' => mb_substr($request->reference, 0, 100),
            'origin' => [
                'contact_name' => (string) ($acc->pickup_contact ?: $acc->name),
                'contact_phone' => '+' . Phone::intl($acc->pickup_phone, '971'),
                'partner_order_id' => mb_substr($request->reference, 0, 100),
                'address' => array_filter([
                    'address1' => (string) $acc->pickup_address,
                    'town' => (string) $acc->pickup_city,
                    'country' => 'UAE',
                    'coords' => $originCoords,
                ]),
            ],
            'destination' => [
                'contact_name' => $request->consigneeName,
                'contact_phone' => '+' . Phone::intl($request->consigneePhone, '971'),
                'partner_order_id' => mb_substr($request->reference, 0, 100),
                'share_tracking' => true,
                'address' => array_filter([
                    'address1' => $request->address,
                    'address2' => $request->state ?: null,
                    'town' => $request->courierCityName ?: $request->city,
                    'country' => 'UAE',
                ]),
            ],
            'items' => $items,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . 'orders', $body);
        $order = $res->json('order') ?? [];
        if (! $res->successful() || empty($order['id'])) {
            throw new CourierException('Quiqup: ' . $this->error($res));
        }

        // Dispatch ignores "pending" orders.
        $ready = $this->client()->put($this->base() . 'orders/' . $order['id'] . '/ready_for_collection');
        if (! $ready->successful()) {
            throw new CourierException('Quiqup order ' . $order['id'] . ' was created but could not be marked ready for collection: ' . $this->error($ready) . ' Mark it ready in the Quiqup dashboard.');
        }

        return new BookingResult(
            trackingNumber: (string) ($order['items'][0]['parcel_barcode'] ?? $order['id']),
            courierReference: (string) $order['id'],
            status: CourierStatus::BOOKED,
            rawStatus: 'ready_for_collection',
            meta: ['uuid' => $order['uuid'] ?? null, 'tracking_url' => $order['tracking_url'] ?? null],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->put($this->base() . 'orders/batch/set_cancelled', ['order_ids' => [(int) $shipment->courier_reference]]);
        $state = $res->json('0.state') ?? $res->json('orders.0.state');
        if (! $res->successful() || ($state && $state !== 'cancelled')) {
            throw new CourierException('Quiqup: ' . ($res->successful() ? 'Quiqup did not cancel this order; contact Quiqup.' : $this->error($res)));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'orders/' . rawurlencode((string) $shipment->courier_reference));
        $order = $res->json('order');
        if (! $res->successful() || ! is_array($order)) {
            throw new CourierException('Quiqup: ' . $this->error($res));
        }

        return $this->toResult($order);
    }

    /** One label per parcel, as a PDF. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->withHeaders(['Accept' => 'application/pdf'])
            ->get($this->base() . 'order_label/' . rawurlencode((string) $shipment->courier_reference));
        if (! $res->successful() || ! str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            throw new CourierException('Quiqup: ' . $this->error($res));
        }

        return ['pdf' => $res->body()];
    }

    /** {action:"update", type:"order", payload:{order}}; matched by Quiqup order id. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('webhook_secret');
        if ($secret !== '') {
            $expected = 'sha1=' . hash_hmac('sha1', $request->getContent(), $secret);
            if (! hash_equals($expected, strtolower((string) $request->header('X-Signature')))) {
                throw new CourierException('Invalid Quiqup webhook signature.');
            }
        }

        $order = $request->input('payload');
        if ($request->input('type') !== 'order' || ! is_array($order) || empty($order['id'])) {
            return null;
        }

        return new WebhookUpdate((string) $order['id'], $this->toResult($order));
    }

    // ------------------------------------------------------------------

    private function toResult(array $order): TrackingResult
    {
        $raw = (string) ($order['state'] ?? '');
        $status = self::STATES[strtolower($raw)] ?? null;
        $at = $this->time($order['state_updated_at'] ?? null);
        $reason = $order['delivery_failure_reason'] ?? null;

        return new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            // No history endpoint: each distinct state becomes one timeline line.
            events: $raw !== '' ? [new TrackingEvent($status, $raw, $at, null, is_string($reason) ? $reason : null)] : [],
            deliveredAt: $status === CourierStatus::DELIVERED ? ($this->time($order['destination']['finished_at'] ?? null) ?? $at) : null,
        );
    }

    private function token(bool $fresh = false): string
    {
        $key = 'courier_quiqup_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $this->account->credential('client_id') . '|' . $this->account->credential('client_secret'));
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        $res = $this->http()->post($this->base() . 'oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->account->credential('client_id'),
            'client_secret' => $this->account->credential('client_secret'),
        ]);
        // Staging answers bad credentials with HTTP 200 + {"error":…}.
        $token = $res->json('access_token');
        if (! $res->successful() || ! $token) {
            throw new CourierException('Quiqup: ' . ($res->json('error_description') ?: $res->json('error') ?: ('HTTP ' . $res->status())));
        }
        $ttl = max(60, ((int) $res->json('expires_in', 3600)) - 300);
        Cache::put($key, $token, now()->addSeconds($ttl));

        return $token;
    }

    private function client()
    {
        return $this->http()->withToken($this->token());
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        return $res->json('api_error.human') ?: $res->json('api_error.message') ?: $res->json('error')
            ?: ($res->json('message') ?: ('HTTP ' . $res->status()));
    }
}
