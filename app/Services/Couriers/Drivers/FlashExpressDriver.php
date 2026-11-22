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
use App\Services\Couriers\Support\Shipper;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Flash Express (TH, MY, PH, LA) — open-docs.flashexpress.com. Every call is
 * a form POST signed with sign = UPPER(sha256("k=v&…&key=SECRET")) over the
 * non-blank params sorted by name. Weights in grams, money in cents. A parcel
 * that turns into a return gets a new number (returnedPno); state 5 on that
 * leg means it is back with the merchant. Webhooks sign only mchId+nonceStr.
 */
class FlashExpressDriver extends AbstractCourierDriver
{
    private const HOSTS = [
        'TH' => 'flashexpress.com', 'MY' => 'flashexpress.my', 'PH' => 'flashexpress.ph', 'LA' => 'flashexpress.la',
    ];

    private const STATES = [
        1 => CourierStatus::PICKED_UP, 2 => CourierStatus::IN_TRANSIT, 3 => CourierStatus::OUT_FOR_DELIVERY,
        4 => CourierStatus::FAILED_ATTEMPT, 5 => CourierStatus::DELIVERED, 6 => CourierStatus::FAILED_ATTEMPT,
        7 => CourierStatus::RETURNING, 8 => CourierStatus::CANCELLED, 9 => CourierStatus::CANCELLED,
    ];

    private const ACTIONS = [
        'RECEIVED' => CourierStatus::PICKED_UP,
        'RECEIVE_WAREHOUSE_SCAN' => CourierStatus::IN_TRANSIT, 'SHIPMENT_WAREHOUSE_SCAN' => CourierStatus::IN_TRANSIT,
        'ARRIVAL_WAREHOUSE_SCAN' => CourierStatus::IN_TRANSIT, 'CHANGE_PARCEL_IN_TRANSIT' => CourierStatus::IN_TRANSIT,
        'DELIVERY_TICKET_CREATION_SCAN' => CourierStatus::OUT_FOR_DELIVERY, 'CONTINUE_TRANSPORT' => CourierStatus::OUT_FOR_DELIVERY,
        'DETAIN_WAREHOUSE' => CourierStatus::FAILED_ATTEMPT, 'DIFFICULTY_HANDOVER' => CourierStatus::FAILED_ATTEMPT,
        'DELIVERY_CONFIRM' => CourierStatus::DELIVERED, 'CHANGE_PARCEL_SIGNED' => CourierStatus::DELIVERED,
        'CANCEL_PARCEL' => CourierStatus::CANCELLED, 'CHANGE_PARCEL_CANCEL' => CourierStatus::CANCELLED,
        'CHANGE_PARCEL_CLOSE' => CourierStatus::CANCELLED, 'DIFFICULTY_FINISH_INDEMNITY' => CourierStatus::CANCELLED,
        'SYSTEM_AUTO_RETURN' => CourierStatus::RETURNING, 'DIFFICULTY_RE_TRANSIT' => CourierStatus::RETURNING,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'flash_express',
            'name' => 'Flash Express',
            'region' => 'Southeast Asia',
            'countries' => array_keys(self::HOSTS),
            'website' => 'https://www.flashexpress.com',
            'tracking_url' => null,
            'description' => 'Flash Express orders with COD, PDF labels, pickup requests, tracking routes and status webhooks.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'mch_id', 'label' => 'Merchant ID (mchId)', 'type' => 'text', 'required' => true],
                ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'required' => true,
                    'help' => 'Issued by Flash Express support. Training and production keys differ.'],
            ],
            'settings' => array_merge([
                ['key' => 'country', 'label' => 'Country', 'type' => 'select', 'required' => true, 'options' => array_map(
                    fn ($c) => ['value' => $c, 'label' => $c], array_keys(self::HOSTS)
                )],
                ['key' => 'warehouse_no', 'label' => 'Flash warehouse number', 'type' => 'text',
                    'help' => 'Optional. When set, Flash uses this registered pickup address instead of the one below.'],
                ['key' => 'express_category', 'label' => 'Service', 'type' => 'select', 'options' => [
                    ['value' => '1', 'label' => 'Standard'], ['value' => '2', 'label' => 'On-Time'], ['value' => '4', 'label' => 'Bulky'],
                ]],
                ['key' => 'article_category', 'label' => 'Goods type', 'type' => 'select', 'options' => [
                    ['value' => '2', 'label' => 'Commodity'], ['value' => '0', 'label' => 'File'], ['value' => '1', 'label' => 'Dry food'],
                    ['value' => '3', 'label' => 'Digital'], ['value' => '4', 'label' => 'Clothes'], ['value' => '5', 'label' => 'Books'],
                    ['value' => '6', 'label' => 'Auto parts'], ['value' => '7', 'label' => 'Shoes / bags'], ['value' => '8', 'label' => 'Sports'],
                    ['value' => '9', 'label' => 'Cosmetics'], ['value' => '10', 'label' => 'Household'], ['value' => '99', 'label' => 'Others'],
                ]],
                ['key' => 'auto_pickup', 'label' => 'Request a pickup after booking', 'type' => 'switch',
                    'help' => 'Flash keeps one open pickup per address; extra requests are skipped.'],
            ], array_values(array_filter(Shipper::fields(), fn ($f) => $f['key'] !== 'sender_country'))), // country comes from the setting above
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->call('open/v1/warehouses');
        if (! $this->ok($res)) {
            return ConnectionResult::failed('Flash Express: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to Flash Express (' . $this->country() . ', ' . count((array) $res->json('data')) . ' warehouses).');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $d = Shipper::dimensions($this->account);
        $cod = $request->codAmount > 0;
        $items = Str::limit($request->itemsDescription ?: 'Goods', 50, '');

        $params = array_merge($this->sender(), [
            'outTradeNo' => mb_substr($request->reference . '-' . Str::lower(Str::random(4)), 0, 64),
            'expressCategory' => (string) ($this->account->setting('express_category') ?: '1'),
            // "0" (File) is a real category: only null / blank falls back to Commodity.
            'articleCategory' => trim((string) $this->account->setting('article_category')) !== '' ? (string) $this->account->setting('article_category') : '2',
            'dstName' => mb_substr($request->consigneeName, 0, 50),
            'dstPhone' => $this->phone($request->consigneePhone),
            'dstProvinceName' => (string) $request->state,
            'dstCityName' => $request->courierCityName ?: $request->city,
            'dstPostalCode' => (string) $request->zip,
            'dstDetailAddress' => mb_substr($request->address, 0, 300),
            'weight' => (string) max(1, (int) round($request->weight * 1000)),
            'length' => (string) (int) ceil($d['length']),
            'width' => (string) (int) ceil($d['width']),
            'height' => (string) (int) ceil($d['height']),
            'insured' => '0',
            'codEnabled' => $cod ? '1' : '0',
            'codAmount' => $cod ? (string) (int) round($request->codAmount * 100) : null,
            'subItemTypes' => $cod ? json_encode([['itemName' => $items, 'itemQuantity' => (string) $request->pieces]]) : null,
            'remark' => $request->notes ? mb_substr($request->notes, 0, 200) : null,
        ]);

        $res = $this->call('open/v3/orders', $params);
        $pno = $res->json('data.pno');
        if (! $this->ok($res) || ! $pno) {
            throw new CourierException('Flash Express: ' . $this->error($res));
        }

        $meta = array_filter([
            'out_trade_no' => $params['outTradeNo'],
            'sort_code' => $res->json('data.sortCode'),
            'dst_store' => $res->json('data.dstStoreName'),
        ]);
        if ($this->account->setting('auto_pickup')) {
            $meta['pickup'] = $this->requestPickup();
        }

        return new BookingResult(trackingNumber: (string) $pno, status: CourierStatus::BOOKED, rawStatus: 'Created', meta: $meta);
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $data = $this->routes((string) $shipment->tracking_number);
        $events = $this->events($data['routes'] ?? []);
        $state = (int) ($data['state'] ?? 0);
        $status = $state ? (self::STATES[$state] ?? null) : null;

        // Return leg: a new pno; delivered there = back with the merchant.
        $returnPno = $data['returnedPno'] ?? null;
        if ($returnPno && $state === 7) {
            try {
                $back = $this->routes((string) $returnPno);
                foreach ($this->events($back['routes'] ?? [], true) as $e) {
                    $events[] = $e;
                }
                if ((int) ($back['state'] ?? 0) === 5) {
                    $status = CourierStatus::RETURNED;
                }
            } catch (CourierException $e) {
                // Outbound status still counts.
            }
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $at = isset($data['stateChangeAt']) ? Carbon::createFromTimestamp((int) $data['stateChangeAt']) : null;

        return new TrackingResult(
            status: $status,
            rawStatus: $data['stateText'] ?? ($state ? (string) $state : null),
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->call('open/v1/orders/' . rawurlencode((string) $shipment->tracking_number) . '/cancel');
        if (! $this->ok($res)) {
            throw new CourierException('Flash Express: ' . $this->error($res));
        }
    }

    /** 100 × 180 mm PDF label. */
    public function label(Shipment $shipment): array
    {
        $res = $this->call('open/v1/orders/' . rawurlencode((string) $shipment->tracking_number) . '/pre_print', [], ['Accept' => 'application/pdf, */*']);
        if (! $res->successful() || ! str_starts_with($res->body(), '%PDF')) {
            throw new CourierException('Flash Express: ' . $this->error($res));
        }

        return ['pdf' => $res->body()];
    }

    /** {mchId, nonceStr, sign, data: {pno, state, returned, returnedPno, routedAction?, …}}; data may be a JSON string. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $mch = (string) $request->input('mchId');
        $nonce = (string) $request->input('nonceStr');
        $expected = strtoupper(hash('sha256', 'mchId=' . $mch . '&nonceStr=' . $nonce . '&key=' . $this->account->credential('secret_key')));
        if ($mch !== (string) $this->account->credential('mch_id') || ! hash_equals($expected, strtoupper((string) $request->input('sign')))) {
            throw new CourierException('Invalid Flash Express webhook signature.');
        }

        $data = $request->input('data');
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (! is_array($data) || empty($data['pno'])) {
            return null;
        }

        $returned = (string) ($data['returned'] ?? '0') === '1';
        $action = (string) ($data['routedAction'] ?? $data['routeAction'] ?? '');
        $state = (int) ($data['state'] ?? 0);
        $status = $state ? (self::STATES[$state] ?? null) : ($action !== '' ? (self::ACTIONS[$action] ?? null) : null);
        if ($returned && $status !== null) {
            $status = $status === CourierStatus::DELIVERED ? CourierStatus::RETURNED : CourierStatus::RETURNING;
        }
        $raw = (string) ($data['stateText'] ?? $data['message'] ?? $action);
        $at = $this->time($data['routedAt'] ?? $data['stateDate'] ?? $data['updateAt'] ?? null);

        return new WebhookUpdate((string) $data['pno'], new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: [new TrackingEvent($status, $raw ?: 'Update', $at, $data['currentProvince'] ?? $data['operationAddress'] ?? null,
                ! empty($data['returnedPno']) ? 'Return tracking ' . $data['returnedPno'] : null)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    public function webhookAck(): array
    {
        return [200, ['errorCode' => '1', 'state' => 'success']];
    }

    // ------------------------------------------------------------------

    /** sign over non-blank params (sorted, raw values) + &key=. "0" is kept. */
    public function sign(array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && trim((string) $v) !== '');
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        return strtoupper(hash('sha256', implode('&', $pairs) . '&key=' . $this->account->credential('secret_key')));
    }

    private function call(string $path, array $params = [], array $headers = []): Response
    {
        $params = array_filter($params, fn ($v) => $v !== null && trim((string) $v) !== '');
        $params['mchId'] = (string) $this->account->credential('mch_id');
        $params['nonceStr'] = Str::random(24);
        $params['sign'] = $this->sign($params);

        return $this->http()->asForm()->withHeaders($headers + ['Accept-Language' => 'en'])->post($this->base() . $path, $params);
    }

    private function routes(string $pno): array
    {
        $res = $this->call('open/v1/orders/' . rawurlencode($pno) . '/routes');
        if (! $this->ok($res)) {
            throw new CourierException('Flash Express: ' . $this->error($res));
        }

        return (array) $res->json('data');
    }

    /** @return TrackingEvent[] */
    private function events(array $routes, bool $returnLeg = false): array
    {
        $events = [];
        foreach ($routes as $r) {
            $status = self::ACTIONS[$r['routeAction'] ?? ''] ?? null;
            if ($returnLeg && $status !== null) {
                $status = $status === CourierStatus::DELIVERED ? CourierStatus::RETURNED : CourierStatus::RETURNING;
            }
            $at = isset($r['routedAt']) && is_numeric($r['routedAt']) ? Carbon::createFromTimestamp((int) $r['routedAt']) : $this->time($r['routedAt'] ?? null);
            $events[] = new TrackingEvent($status, $r['message'] ?? ($r['routeAction'] ?? 'Update'), $at);
        }

        return $events;
    }

    /** Opens a pickup task; an already open task (1010) is fine. */
    private function requestPickup(): string
    {
        try {
            $res = $this->call('open/v1/notify', array_merge($this->sender(), ['estimateParcelNumber' => '1']));
            if ($this->ok($res)) {
                return 'requested ' . ($res->json('data.ticketPickupId') ?? '');
            }

            return (int) $res->json('code') === 1010 ? 'already open' : 'failed: ' . $this->error($res);
        } catch (\Throwable $e) {
            return 'failed: ' . $e->getMessage();
        }
    }

    /** Registered warehouse, else the account's pickup address. */
    private function sender(): array
    {
        if ($wh = trim((string) $this->account->setting('warehouse_no'))) {
            return ['warehouseNo' => $wh];
        }
        $from = Shipper::address($this->account);

        return [
            'srcName' => mb_substr($from['name'], 0, 50),
            'srcPhone' => $this->phone($from['phone']),
            'srcProvinceName' => $from['state'],
            'srcCityName' => $from['city'],
            'srcPostalCode' => $from['zip'],
            'srcDetailAddress' => mb_substr($from['street'], 0, 300),
        ];
    }

    /** Local format without the country code (e.g. 0812345678). */
    private function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $code = ['TH' => '66', 'MY' => '60', 'PH' => '63', 'LA' => '856'][$this->country()];
        if (str_starts_with($digits, '00' . $code)) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 7) {
            $digits = '0' . substr($digits, strlen($code));
        }

        return mb_substr($digits, 0, 20);
    }

    private function country(): string
    {
        $c = strtoupper((string) $this->account->setting('country'));

        return isset(self::HOSTS[$c]) ? $c : 'TH';
    }

    private function base(): string
    {
        return 'https://open-api' . ($this->account->isTestMode() ? '-tra' : '') . '.' . self::HOSTS[$this->country()] . '/';
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && (int) $res->json('code') === 1;
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value);
            }

            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $msg = $res->json('message');
        $code = $res->json('code');

        return $msg ? trim($msg . ($code ? " ({$code})" : '')) : ('HTTP ' . $res->status());
    }
}
