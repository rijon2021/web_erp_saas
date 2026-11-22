<?php

namespace App\Services\Couriers\Drivers;

use App\Models\Shipment;
use App\Services\Couriers\AbstractCourierDriver;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\CourierStatus;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\Rate;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Data\WebhookUpdate;
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\Shipper;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Torod (Saudi Arabia aggregator) — Merchant API, from Torod's official
 * Postman collection and its WooCommerce plugin. Production
 * https://api.torod.co/en/api/, sandbox https://demo.stage.torod.co/en/api/.
 * client_id + client_secret → Bearer token (cached). Form-encoded requests;
 * envelope {status, code, message, data}. Booking = order/create (numeric
 * city_id) → courier/partners → order/ship/process; the Torod order_id is
 * the tracking number (order/track accepts it). Webhooks carry the client
 * secret in the Authorization header / client_secret_key field.
 *
 * Torod publishes no example responses for courier/partners, ship/process,
 * track or cancel: those are read defensively.
 */
class TorodDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://api.torod.co/en/api/';
    private const SANDBOX = 'https://demo.stage.torod.co/en/api/';

    private const SHIPMENT_STATUSES = [
        'READY_FOR_PICKUP' => CourierStatus::BOOKED, 'PENDING_PICKUP' => CourierStatus::BOOKED,
        'PICKED_UP' => CourierStatus::PICKED_UP,
        'IN_TRANSIT' => CourierStatus::IN_TRANSIT,
        'OUT_FOR_DELIVERY' => CourierStatus::OUT_FOR_DELIVERY,
        'FAILED_TO_DELIVER' => CourierStatus::FAILED_ATTEMPT, 'FAILED_TO_ATTEMPT' => CourierStatus::FAILED_ATTEMPT, 'RESCHEDULED' => CourierStatus::FAILED_ATTEMPT,
        'DELIVERED' => CourierStatus::DELIVERED, 'PARTIAL_DELIVERY' => CourierStatus::DELIVERED,
        'RTO_IN_PROGRESS' => CourierStatus::RETURNING,
        'RTO' => CourierStatus::RETURNED,
        'CANCELLED' => CourierStatus::CANCELLED,
    ];

    private const ORDER_STATUSES = [
        'created' => CourierStatus::BOOKED, 'pending pickup' => CourierStatus::BOOKED,
        'shipped' => CourierStatus::IN_TRANSIT,
        'out for delivery' => CourierStatus::OUT_FOR_DELIVERY,
        'failed' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED, 'partial delivery' => CourierStatus::DELIVERED,
        'rto' => CourierStatus::RETURNED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'torod',
            'name' => 'Torod',
            'region' => 'Saudi Arabia / GCC',
            'countries' => ['SA'],
            'website' => 'https://torod.co',
            'tracking_url' => null,
            'description' => 'Saudi carriers through Torod: compare couriers, COD booking, airway bills and status webhooks.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => 'Torod merchant panel → App credentials.'],
                ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'required' => true,
                    'help' => 'Also verifies the status updates Torod sends.'],
            ],
            'settings' => array_merge([
                ['key' => 'warehouse', 'label' => 'Warehouse code', 'type' => 'text', 'required' => true,
                    'help' => 'Your Torod pickup address code, e.g. ABC001. "Test connection" lists them.'],
                ['key' => 'shipping_type', 'label' => 'Shipment type', 'type' => 'select', 'options' => [
                    ['value' => 'normal', 'label' => 'Normal'], ['value' => 'quick', 'label' => 'Quick'],
                    ['value' => 'cold', 'label' => 'Cold'], ['value' => 'pudo', 'label' => 'Pick-up point'],
                ], 'help' => 'Leave empty for Normal.'],
                ['key' => 'filter_by', 'label' => 'Choose courier by', 'type' => 'select', 'options' => [
                    ['value' => 'cheapest', 'label' => 'Cheapest'], ['value' => 'fastest', 'label' => 'Fastest'],
                ], 'help' => 'Used when no courier is picked in "Compare rates".'],
                ['key' => 'sender_email', 'label' => 'Pickup email', 'type' => 'text', 'help' => 'Used when the customer has no email (Torod requires one).'],
            ], array_slice(Shipper::fields(), 5)),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $res = $this->client()->get($this->base() . 'address/list', ['page' => 1]);
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }
        if (! $this->ok($res)) {
            return ConnectionResult::failed('Torod: ' . $this->error($res));
        }

        $codes = collect($this->list($res->json('data')))
            ->map(fn ($a) => ($a['warehouse'] ?? '?') . (! empty($a['warehouse_name']) ? ' (' . $a['warehouse_name'] . ')' : ''))->implode(', ');

        return ConnectionResult::ok('Connected to Torod.' . ($codes ? " Warehouses: {$codes}." : ''));
    }

    public function rates(ShipmentRequest $request): array
    {
        $res = $this->client()->asForm()->post($this->base() . 'courier/partners/list', array_filter([
            'warehouse' => (string) $this->account->setting('warehouse'),
            'customer_city_id' => $this->cityId($request),
            'payment' => $request->codAmount > 0 ? 'COD' : 'Prepaid',
            'weight' => max(0.1, round($request->weight, 2)),
            'order_total' => round(max($request->codAmount, $request->declaredValue), 2),
            'no_of_box' => $request->pieces,
            'type' => $this->account->setting('shipping_type') ?: 'normal',
            'filter_by' => $this->account->setting('filter_by') ?: 'cheapest',
            'dimension' => json_encode(Shipper::dimensions($this->account)),
        ], fn ($v) => $v !== null && $v !== ''));
        if (! $this->ok($res)) {
            throw new CourierException('Torod: ' . $this->error($res));
        }

        return collect($this->list($res->json('data')))->filter(fn ($p) => isset($p['id']))->map(fn ($p) => new Rate(
            id: ((int) ($p['is_own'] ?? 0)) . ':' . $p['id'],
            carrier: (string) ($p['title'] ?? $p['name'] ?? $p['courier_name'] ?? $p['partner_name'] ?? ('Courier ' . $p['id'])),
            service: (string) ($p['service_name'] ?? $p['type'] ?? ''),
            amount: (float) ($p['rate'] ?? $p['price'] ?? $p['total_amount'] ?? $p['amount'] ?? 0),
            currency: (string) ($p['currency'] ?? 'SAR'),
            days: isset($p['delivery_days']) && is_numeric($p['delivery_days']) ? (int) $p['delivery_days'] : null,
        ))->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $email = $request->consigneeEmail ?: $this->account->setting('sender_email');
        if (! $email) {
            throw new CourierException('Torod needs an email address. Add one to the customer, or set "Pickup email" on the courier account.');
        }
        $create = $this->client()->asForm()->post($this->base() . 'order/create', array_filter([
            'name' => $request->consigneeName,
            'email' => $email,
            'phone_number' => Phone::intl($request->consigneePhone, '966'),
            'item_description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 250),
            'order_total' => round(max($request->codAmount, $request->declaredValue), 2),
            'payment' => $request->codAmount > 0 ? 'COD' : 'Prepaid',
            'weight' => max(0.1, round($request->weight, 2)),
            'no_of_box' => $request->pieces,
            'type' => 'address_city',
            'address' => $request->address,
            'city_id' => $this->cityId($request),
            'dimension' => json_encode(Shipper::dimensions($this->account)),
            'meta_data' => json_encode(['reference' => $request->reference]),
        ], fn ($v) => $v !== null && $v !== ''));
        $orderId = $create->json('data.order_id');
        if (! $this->ok($create) || ! $orderId) {
            throw new CourierException('Torod: ' . $this->error($create));
        }

        // "is_own:id" from Compare rates, else the first option Torod offers.
        if ($request->service && preg_match('/^(\d):(\d+)$/', $request->service, $m)) {
            [$isOwn, $partnerId] = [(int) $m[1], (int) $m[2]];
        } else {
            $options = $this->client()->asForm()->post($this->base() . 'courier/partners', [
                'order_id' => $orderId,
                'warehouse' => (string) $this->account->setting('warehouse'),
                'type' => $this->account->setting('shipping_type') ?: 'normal',
                'filter_by' => $this->account->setting('filter_by') ?: 'cheapest',
            ]);
            $first = collect($this->list($options->json('data')))->first(fn ($p) => isset($p['id']));
            if (! $this->ok($options) || ! $first) {
                throw new CourierException('Torod: no courier available for this order (' . $this->error($options) . ').');
            }
            [$isOwn, $partnerId] = [(int) ($first['is_own'] ?? 0), (int) $first['id']];
        }

        $ship = $this->client()->asForm()->post($this->base() . 'order/ship/process', [
            'order_id' => $orderId,
            'warehouse' => (string) $this->account->setting('warehouse'),
            'type' => $this->account->setting('shipping_type') ?: 'normal',
            'courier_partner_id' => $partnerId,
            'is_own' => $isOwn,
        ]);
        if (! $this->ok($ship)) {
            throw new CourierException('Torod: the order was created (' . $orderId . ') but shipping failed: ' . $this->error($ship));
        }

        return new BookingResult(
            trackingNumber: (string) $orderId,
            courierReference: $ship->json('data.tracking_id') ? (string) $ship->json('data.tracking_id') : null,
            labelUrl: $ship->json('data.aws_label') ?: null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Created',
            meta: ['courier_partner_id' => $partnerId],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->asForm()->post($this->base() . 'shipments/cancel', ['tracking_or_order_id' => (string) $shipment->tracking_number]);
        if (! $this->ok($res)) {
            $res = $this->client()->asForm()->post($this->base() . 'order/cancel', ['order_id' => (string) $shipment->tracking_number]);
        }
        if (! $this->ok($res)) {
            throw new CourierException('Torod: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->asForm()->post($this->base() . 'order/track', ['tracking_id' => (string) $shipment->tracking_number]);
        $d = $res->json('data');
        if (! $this->ok($res) || ! is_array($d)) {
            throw new CourierException('Torod: ' . $this->error($res));
        }
        $d = array_is_list($d) ? ($d[0] ?? []) : $d;

        $events = [];
        foreach ($d['tracking_history'] ?? $d['history'] ?? [] as $h) {
            $raw = $h['courier_status'] ?? $h['status'] ?? null;
            $events[] = new TrackingEvent(
                status: $this->map($h['shipment_status'] ?? null, $h['status'] ?? null) ?? StatusText::map($raw),
                rawStatus: $raw,
                at: $this->time($h['date_time'] ?? $h['date'] ?? null),
                description: $h['torod_description'] ?? $h['description'] ?? null,
            );
        }

        $status = $this->map($d['shipment_status'] ?? null, $d['status'] ?? null);

        return new TrackingResult(
            status: $status,
            rawStatus: $d['shipment_status'] ?? $d['status'] ?? null,
            events: $events,
        );
    }

    /** The AWB PDF link Torod returns with the order. */
    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        $res = $this->client()->asForm()->post($this->base() . 'order/track', ['tracking_id' => (string) $shipment->tracking_number]);
        $d = $res->json('data');
        $d = is_array($d) && array_is_list($d) ? ($d[0] ?? []) : (array) $d;
        if (! $this->ok($res) || empty($d['aws_label'])) {
            throw new CourierException('Torod: the airway bill is not ready yet.');
        }

        return ['url' => $d['aws_label']];
    }

    /** Cities the merchant can ship to: [{code: cities_id, name: city_name}]. */
    public function cities(): array
    {
        $cities = [];
        for ($page = 1; $page <= 20; $page++) {
            $res = $this->client()->get($this->base() . 'get-all/cities-access', ['page' => $page]);
            if (! $this->ok($res)) {
                throw new CourierException('Torod: ' . $this->error($res));
            }
            $batch = $this->list($res->json('data'));
            foreach ($batch as $c) {
                if (isset($c['cities_id'], $c['city_name'])) {
                    $cities[] = ['code' => (string) $c['cities_id'], 'name' => $c['city_name']];
                }
            }
            if (count($batch) < 50) {
                break;
            }
        }

        return $cities;
    }

    /**
     * Torod webhook: JSON {order_id, tracking_id, status, shipment_status,
     * courier_status, date_time, description, torod_description,
     * client_secret_key, aws_label, …}; the client secret also arrives in
     * the Authorization header.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('client_secret');
        $header = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization'));
        $body = (string) $request->input('client_secret_key');
        if ($secret === '' || ! (hash_equals($secret, $header) || hash_equals($secret, $body))) {
            throw new CourierException('Invalid Torod webhook secret.');
        }

        $orderId = $request->input('order_id');
        if (! $orderId) {
            return null;
        }

        $status = $this->map($request->input('shipment_status'), $request->input('status'));
        $at = $this->time($request->input('date_time'));

        return new WebhookUpdate((string) $orderId, new TrackingResult(
            status: $status,
            rawStatus: $request->input('shipment_status') ?: $request->input('status'),
            events: [new TrackingEvent($status, $request->input('courier_status') ?: $request->input('status'), $at, null,
                $request->input('torod_description') ?: $request->input('description'))],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    private function map(?string $shipmentStatus, ?string $orderStatus): ?string
    {
        return self::SHIPMENT_STATUSES[strtoupper(trim((string) $shipmentStatus))]
            ?? self::ORDER_STATUSES[strtolower(trim((string) $orderStatus))]
            ?? null;
    }

    private function cityId(ShipmentRequest $request): ?string
    {
        if ($request->courierCityCode) {
            return $request->courierCityCode;
        }
        $needle = mb_strtolower(trim($request->courierCityName ?: $request->city));
        foreach ($this->cities() as $c) {
            if (mb_strtolower(trim($c['name'])) === $needle) {
                return $c['code'];
            }
        }
        throw new CourierException('Choose the Torod city for "' . $request->city . '".');
    }

    /** Paginated payloads come as a list or {data: [...]}. */
    private function list($data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_is_list($data) ? $data : (is_array($data['data'] ?? null) ? $data['data'] : []);
    }

    private function token(): string
    {
        $key = 'courier_torod_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $this->account->credential('client_id') . '|' . $this->account->credential('client_secret'));

        return Cache::get($key) ?? (function () use ($key) {
            $res = $this->http()->asForm()->post($this->base() . 'token', [
                'client_id' => $this->account->credential('client_id'),
                'client_secret' => $this->account->credential('client_secret'),
            ]);
            $token = $res->json('data.bearer_token');
            if (! $this->ok($res) || ! $token) {
                throw new CourierException('Torod: ' . $this->error($res));
            }
            // Lifetime is undocumented; Torod's own plugin assumes 24 hours.
            Cache::put($key, $token, now()->addHours(23));

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

    private function ok(Response $res): bool
    {
        return $res->successful() && $res->json('status') === true;
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
        $m = $res->json('message');
        if (is_array($m)) {
            return collect($m)->flatten()->filter(fn ($v) => is_string($v))->implode('; ') ?: ('HTTP ' . $res->status());
        }

        return $m ?: ('HTTP ' . $res->status());
    }
}
