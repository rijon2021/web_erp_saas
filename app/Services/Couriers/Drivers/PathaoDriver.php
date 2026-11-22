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
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Pathao Courier (Bangladesh) — "Aladdin" merchant API, from Pathao's own
 * WooCommerce plugin and docs. Live https://api-hermes.pathao.com, sandbox
 * https://courier-api-sandbox.pathao.com. client_id + client_secret →
 * external/login (no merchant password stored) → Bearer token (cached).
 * City/zone/area are optional at booking (Pathao derives them from the
 * address); the picker offers areas for accuracy. No cancel or label API.
 * Webhooks send the merchant's secret in X-PATHAO-Signature and must be
 * answered 202 with Pathao's fixed integration header.
 */
class PathaoDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://api-hermes.pathao.com/aladdin/api/v1/';
    private const SANDBOX = 'https://courier-api-sandbox.pathao.com/aladdin/api/v1/';
    private const ACK_HEADER = 'X-Pathao-Merchant-Webhook-Integration-Secret';
    private const ACK_VALUE = 'f3992ecc-59da-4cbe-a049-a13da2018d51';

    /** Webhook events / status names (lower-case, underscores removed) → Stocky status. */
    private const STATUSES = [
        'ordercreated' => CourierStatus::BOOKED, 'pending' => CourierStatus::BOOKED,
        'pickuprequested' => CourierStatus::BOOKED, 'assignedforpickup' => CourierStatus::BOOKED, 'pickupfailed' => CourierStatus::BOOKED,
        'picked' => CourierStatus::PICKED_UP,
        'pickupcancelled' => CourierStatus::CANCELLED,
        'atthesortinghub' => CourierStatus::IN_TRANSIT, 'intransit' => CourierStatus::IN_TRANSIT, 'receivedatlastmilehub' => CourierStatus::IN_TRANSIT,
        'assignedfordelivery' => CourierStatus::OUT_FOR_DELIVERY,
        'delivered' => CourierStatus::DELIVERED, 'partialdelivery' => CourierStatus::DELIVERED, 'exchange' => CourierStatus::DELIVERED, 'exchanged' => CourierStatus::DELIVERED,
        'deliveryfailed' => CourierStatus::FAILED_ATTEMPT, 'onhold' => CourierStatus::FAILED_ATTEMPT,
        'return' => CourierStatus::RETURNED, 'returned' => CourierStatus::RETURNED, 'paidreturn' => CourierStatus::RETURNED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'pathao',
            'name' => 'Pathao Courier',
            'region' => 'Bangladesh',
            'countries' => ['BD'],
            'website' => 'https://pathao.com/courier',
            'tracking_url' => null,
            'description' => 'COD deliveries across Bangladesh: booking with optional area, tracking history, payment invoices and instant webhooks.',
            'capabilities' => [
                'booking' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => 'Pathao merchant panel → Developer API.'],
                ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'required' => true],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'password',
                    'help' => 'Required for instant status updates. The secret you set with the Callback URL in the Pathao panel.'],
            ],
            'settings' => [
                ['key' => 'store_id', 'label' => 'Store ID', 'type' => 'number', 'required' => true,
                    'help' => 'Your Pathao pickup store. "Test connection" lists them.'],
                ['key' => 'delivery_type', 'label' => 'Delivery type', 'type' => 'select', 'options' => [
                    ['value' => '48', 'label' => 'Normal'], ['value' => '12', 'label' => 'On demand'],
                ], 'help' => 'Leave empty for Normal.'],
                ['key' => 'item_type', 'label' => 'Item type', 'type' => 'select', 'options' => [
                    ['value' => '2', 'label' => 'Parcel'], ['value' => '1', 'label' => 'Document'],
                ], 'help' => 'Leave empty for Parcel.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $res = $this->client()->get($this->base() . 'stores');
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }
        if (! $res->successful()) {
            return ConnectionResult::failed('Pathao: ' . $this->error($res));
        }

        $stores = collect($res->json('data.data') ?? [])->map(fn ($s) => ($s['store_id'] ?? '?') . ' (' . ($s['store_name'] ?? '') . ')')->implode(', ');

        return ConnectionResult::ok('Connected to Pathao.' . ($stores ? " Stores: {$stores}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        [$cityId, $zoneId, $areaId] = array_pad(array_map('intval', explode(':', (string) $request->courierCityCode)), 3, 0);

        $res = $this->client()->post($this->base() . 'orders', array_filter([
            'store_id' => (int) $this->account->setting('store_id'),
            'merchant_order_id' => mb_substr($request->reference, 0, 100),
            'recipient_name' => mb_substr($request->consigneeName, 0, 64),
            'recipient_phone' => Phone::bd($request->consigneePhone),
            'recipient_address' => mb_substr(trim($request->address . ', ' . $request->city, ', '), 0, 220),
            // Optional: omit rather than null; Pathao derives them from the address.
            'recipient_city' => $cityId ?: null,
            'recipient_zone' => $zoneId ?: null,
            'recipient_area' => $areaId ?: null,
            'delivery_type' => (int) ($this->account->setting('delivery_type') ?: 48),
            'item_type' => (int) ($this->account->setting('item_type') ?: 2),
            'special_instruction' => $request->notes ?: null,
            'item_quantity' => $request->pieces,
            'item_weight' => max(0.5, round($request->weight, 2)),
            'item_description' => $request->itemsDescription ?: null,
            'amount_to_collect' => (int) round($request->codAmount),
        ], fn ($v) => $v !== null && $v !== ''));

        $d = $res->json('data') ?? [];
        if (! $res->successful() || empty($d['consignment_id'])) {
            throw new CourierException('Pathao: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) $d['consignment_id'],
            charges: isset($d['delivery_fee']) ? (float) $d['delivery_fee'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: $d['order_status'] ?? 'Pending',
        );
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $id = rawurlencode((string) $shipment->tracking_number);
        $res = $this->client()->get($this->base() . 'orders/' . $id . '/info');
        $d = $res->json('data');
        if (! $res->successful() || ! is_array($d)) {
            throw new CourierException('Pathao: ' . $this->error($res));
        }

        $raw = (string) ($d['order_status'] ?? $d['order_status_slug'] ?? '');
        $events = [];
        // History needs the recipient phone (kept with the booking).
        $phone = Phone::bd($shipment->courier_meta['consignee']['phone'] ?? '');
        if ($phone !== '') {
            try {
                $act = $this->client()->get($this->base() . 'orders/' . $id . '/' . $phone . '/activity', ['archive' => 0]);
                foreach ($act->json('data.log') ?? [] as $log) {
                    $events[] = new TrackingEvent(StatusText::map($log['desc'] ?? null), $log['desc'] ?? null, $this->time($log['created_at'] ?? null));
                }
            } catch (\Throwable $e) {
                // Current status still counts without history.
            }
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $paid = ! empty($d['invoice_id']) && strtolower((string) ($d['payment_status'] ?? '')) === 'paid';

        return new TrackingResult(
            status: $this->map($raw),
            rawStatus: $raw ?: null,
            events: $events,
            codStatus: $paid ? 'remitted' : null,
            codReference: $paid ? (string) $d['invoice_id'] : null,
        );
    }

    /** Areas: {code: "city:zone:area", name: "Area, Zone"} from Pathao's bulk lists. */
    public function cities(): array
    {
        $zones = collect($this->client()->get($this->base() . 'zones/list')->json('data') ?? [])->keyBy('id');
        $areas = $this->client()->get($this->base() . 'areas/list');
        if (! $areas->successful()) {
            throw new CourierException('Pathao: ' . $this->error($areas));
        }

        return collect($areas->json('data') ?? [])
            ->filter(fn ($a) => ($a['is_home_delivery_available'] ?? true) && isset($zones[$a['zone_id'] ?? null]))
            ->map(function ($a) use ($zones) {
                $zone = $zones[$a['zone_id']];

                return ['code' => $zone['city_id'] . ':' . $a['zone_id'] . ':' . $a['id'], 'name' => $a['name'] . ', ' . ($zone['name'] ?? '')];
            })->values()->all();
    }

    /** {consignment_id, event: "order.delivered", updated_at, collected_amount?, reason?, invoice_id?}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('webhook_secret');
        if ($secret === '') {
            throw new CourierException('Set the Pathao webhook secret on the courier account: status updates are rejected until they can be verified.');
        }
        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-PATHAO-Signature'))) {
            throw new CourierException('Invalid Pathao webhook secret.');
        }

        $event = (string) $request->input('event');
        $id = $request->input('consignment_id');
        if (! $id || $event === '' || $event === 'webhook_integration' || str_starts_with($event, 'store.')) {
            return null;
        }

        $at = $this->time($request->input('updated_at'));
        if ($event === 'order.paid') {
            return new WebhookUpdate((string) $id, new TrackingResult(
                status: null,
                events: [new TrackingEvent(null, 'Payment invoice', $at, null, (string) $request->input('invoice_id'))],
                codStatus: 'remitted',
                codReference: $request->input('invoice_id') ? (string) $request->input('invoice_id') : null,
                codRemittedAt: $at,
            ));
        }

        $status = $this->map(str_replace(['order.', '-'], '', $event));

        return new WebhookUpdate((string) $id, new TrackingResult(
            status: $status,
            rawStatus: $event,
            events: [new TrackingEvent($status, $event, $at, null, $request->input('reason'))],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
            charges: is_numeric($request->input('delivery_fee')) ? (float) $request->input('delivery_fee') : null,
        ));
    }

    public function webhookAck(): array
    {
        return [202, ['status' => 'ok'], [self::ACK_HEADER => self::ACK_VALUE]];
    }

    // ------------------------------------------------------------------

    private function map(?string $value): ?string
    {
        $key = str_replace(['_', '-', ' '], '', strtolower((string) $value));

        return self::STATUSES[$key] ?? null;
    }

    private function token(): string
    {
        $key = 'courier_pathao_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $this->account->credential('client_id') . '|' . $this->account->credential('client_secret'));

        return Cache::get($key) ?? (function () use ($key) {
            $res = $this->http()->post($this->base() . 'external/login', [
                'client_id' => $this->account->credential('client_id'),
                'client_secret' => $this->account->credential('client_secret'),
            ]);
            $token = $res->json('access_token');
            if (! $res->successful() || ! $token) {
                throw new CourierException('Pathao: ' . $this->error($res));
            }
            $ttl = max(60, min(30 * 86400, ((int) $res->json('expires_in', 3600)) - 300));
            Cache::put($key, $token, now()->addSeconds($ttl));

            return $token;
        })();
    }

    private function client()
    {
        return $this->http()->withToken($this->token());
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE;
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
        $errors = $res->json('errors');
        if (is_array($errors) && $errors) {
            return collect($errors)->flatten()->implode('; ');
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
