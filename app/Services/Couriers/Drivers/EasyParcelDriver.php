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
use App\Services\Couriers\Support\RatePicker;
use App\Services\Couriers\Support\Shipper;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * EasyParcel (Malaysia & SEA) — the classic API-key API ("Malaysia
 * Individual 1.4"). {base}?ac=<Action>, form-encoded {api, bulk[]};
 * envelope {api_status, error_code, error_remark, result[]} with a per-item
 * status. Live https://connect.easyparcel.{cc}/, demo
 * http://demo.connect.easyparcel.my/. Booking = EPSubmitOrderBulk then
 * EPPayOrderBulk (wallet). States use EasyParcel's 3-letter codes. The
 * classic API has no cancel call (cancel in the EasyParcel portal); webhooks
 * are unsigned, protected by this account's secret URL token.
 */
class EasyParcelDriver extends AbstractCourierDriver
{
    /** Malaysian state names → EasyParcel state codes. */
    private const MY_STATES = [
        'johor' => 'jhr', 'kedah' => 'kdh', 'kelantan' => 'ktn', 'melaka' => 'mlk', 'malacca' => 'mlk',
        'negeri sembilan' => 'nsn', 'pahang' => 'phg', 'perak' => 'prk', 'perlis' => 'pls',
        'pulau pinang' => 'png', 'penang' => 'png', 'selangor' => 'sgr', 'terengganu' => 'trg',
        'kuala lumpur' => 'kul', 'wilayah persekutuan kuala lumpur' => 'kul', 'putrajaya' => 'pjy',
        'sarawak' => 'srw', 'sabah' => 'sbh', 'labuan' => 'lbn',
    ];

    /** Webhook / tracking status codes. */
    private const CODES = [
        0 => CourierStatus::CANCELLED, 2 => CourierStatus::BOOKED, 3 => CourierStatus::PICKED_UP,
        4 => CourierStatus::IN_TRANSIT, 5 => CourierStatus::DELIVERED, 6 => CourierStatus::RETURNED,
        7 => CourierStatus::BOOKED, 8 => CourierStatus::FAILED_ATTEMPT, 10 => CourierStatus::CANCELLED,
        11 => CourierStatus::BOOKED, 12 => CourierStatus::PICKED_UP, 13 => CourierStatus::IN_TRANSIT,
        14 => CourierStatus::FAILED_ATTEMPT,
    ];

    /** EPParcelStatusBulk ship_status. */
    private const SHIP_STATUSES = [
        'cancel' => CourierStatus::CANCELLED, 'cancel by admin' => CourierStatus::CANCELLED, 'expired (unpaid)' => CourierStatus::CANCELLED,
        'pending for collection' => CourierStatus::BOOKED, 'schedule in arrangement' => CourierStatus::BOOKED, 'pending for drop off' => CourierStatus::BOOKED,
        'collected' => CourierStatus::PICKED_UP, 'parcel has been collected' => CourierStatus::PICKED_UP, 'parcel drop off at point' => CourierStatus::PICKED_UP,
        'delivering' => CourierStatus::IN_TRANSIT, 'on hold' => CourierStatus::FAILED_ATTEMPT,
        'successfully delivered' => CourierStatus::DELIVERED, 'returned' => CourierStatus::RETURNED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'easyparcel',
            'name' => 'EasyParcel',
            'region' => 'Malaysia / Southeast Asia',
            'countries' => ['MY', 'SG', 'TH', 'ID'],
            'website' => 'https://www.easyparcel.com',
            'tracking_url' => null,
            'description' => 'J&T, Ninja Van, Pos Laju, DHL eCommerce and more through EasyParcel: compare rates, book, pay from your wallet and track.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key (Integration ID)', 'type' => 'password', 'required' => true,
                    'help' => 'EasyParcel → Integrations → API.'],
            ],
            'settings' => array_merge(Shipper::fields(), [
                ['key' => 'enable_cod', 'label' => 'Collect COD when the courier supports it', 'type' => 'switch'],
            ]),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->call('EPCheckCreditBalance', []);
        if (! $this->ok($res)) {
            return ConnectionResult::failed('EasyParcel: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to EasyParcel (wallet balance ' . $res->json('result') . ').');
    }

    public function rates(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $dims = Shipper::dimensions($this->account);
        $res = $this->call('EPRateCheckingBulk', ['bulk' => [[
            'pick_code' => $from['zip'], 'pick_state' => $this->state($from['state']), 'pick_country' => $from['country'] ?: 'MY',
            'send_code' => (string) $request->zip, 'send_state' => $this->state($request->state), 'send_country' => $request->countryCode($from['country'] ?: 'MY'),
            'weight' => max(0.01, round($request->weight, 2)),
            'length' => $dims['length'], 'width' => $dims['width'], 'height' => $dims['height'],
            'parcel_value' => round(max($request->declaredValue, $request->codAmount), 2),
        ]]]);
        $item = $res->json('result.0') ?? [];
        if (! $this->ok($res) || ($item['status'] ?? '') !== 'Success') {
            throw new CourierException('EasyParcel: ' . ($item['remarks'] ?? $this->error($res)));
        }

        $wantsCod = $this->account->setting('enable_cod') && $request->codAmount > 0;

        return collect($item['rates'] ?? [])
            ->filter(fn ($r) => ! $wantsCod || ! empty($r['cod_service_available']))
            ->map(fn ($r) => new Rate(
                id: (string) $r['service_id'],
                carrier: (string) ($r['courier_name'] ?? ''),
                service: (string) ($r['service_name'] ?? ''),
                amount: (float) ($r['price'] ?? 0),
                currency: (string) ($r['currency'] ?? 'MYR'),
                days: preg_match('/(\d+)/', (string) ($r['delivery'] ?? ''), $m) ? (int) $m[1] : null,
                meta: ['service_detail' => $r['service_detail'] ?? null],
            ))->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        // Fresh rates every time; a service picked in "Compare rates" matches by id.
        $rate = RatePicker::pick($this->rates($request), $request->service ?: $this->account->default_service);
        if (! $rate) {
            throw new CourierException('EasyParcel: no service matches "' . ($request->service ?: $this->account->default_service) . '".');
        }
        $serviceId = $rate->id;

        $from = Shipper::address($this->account);
        $dims = Shipper::dimensions($this->account);
        $line = fn (?string $s) => mb_substr((string) $s, 0, 35);
        $order = array_filter([
            'weight' => max(0.01, round($request->weight, 2)),
            'length' => $dims['length'], 'width' => $dims['width'], 'height' => $dims['height'],
            'content' => $line($request->itemsDescription ?: 'Goods'),
            'value' => round(max($request->declaredValue, $request->codAmount, 1), 2),
            'service_id' => $serviceId,
            'collect_date' => now()->toDateString(),
            'pick_name' => $line($from['name']), 'pick_company' => $line($from['company']) ?: null,
            'pick_contact' => mb_substr($from['phone'], 0, 16), 'pick_addr1' => $line($from['street']),
            'pick_city' => $line($from['city']), 'pick_state' => $this->state($from['state']),
            'pick_code' => $from['zip'], 'pick_country' => $from['country'] ?: 'MY',
            'send_name' => $line($request->consigneeName), 'send_contact' => mb_substr(preg_replace('/\s+/', '', $request->consigneePhone), 0, 16),
            'send_addr1' => $line($request->address), 'send_addr2' => mb_strlen($request->address) > 35 ? $line(mb_substr($request->address, 35)) : null,
            'send_city' => $line($request->city), 'send_state' => $this->state($request->state),
            'send_code' => (string) $request->zip, 'send_country' => $request->countryCode($from['country'] ?: 'MY'),
            'send_email' => $request->consigneeEmail ?: ($from['email'] ?: null),
            'sms' => false,
            'reference' => mb_substr($request->reference, 0, 25),
            'cod_enabled' => $this->account->setting('enable_cod') && $request->codAmount > 0 ? true : null,
            'cod_amount' => $this->account->setting('enable_cod') && $request->codAmount > 0 ? round($request->codAmount, 2) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $submit = $this->call('EPSubmitOrderBulk', ['bulk' => [$order]]);
        $sub = $submit->json('result.0') ?? [];
        if (! $this->ok($submit) || ($sub['status'] ?? '') !== 'Success' || empty($sub['order_number'])) {
            throw new CourierException('EasyParcel: ' . ($sub['remarks'] ?? $this->error($submit)));
        }

        $pay = $this->call('EPPayOrderBulk', ['bulk' => [['order_no' => $sub['order_number']]]]);
        $paid = $pay->json('result.0') ?? [];
        $parcel = $paid['parcel'] ?? null;
        $parcel = is_array($parcel) && array_is_list($parcel) ? ($parcel[0] ?? []) : (array) $parcel;
        if (! $this->ok($pay) || empty($parcel['parcelno'])) {
            throw new CourierException('EasyParcel: order ' . $sub['order_number'] . ' was created but not paid: ' . ($paid['messagenow'] ?? $this->error($pay)) . ' Pay it in the EasyParcel portal.');
        }

        return new BookingResult(
            trackingNumber: (string) (($parcel['awb'] ?? null) ?: $parcel['parcelno']),
            courierReference: (string) $sub['order_number'],
            labelUrl: $parcel['awb_id_link'] ?? null,
            charges: isset($sub['price']) ? (float) $sub['price'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Paid',
            meta: ['parcel_number' => $parcel['parcelno'], 'awb' => $parcel['awb'] ?? null, 'tracking_url' => $parcel['tracking_url'] ?? null, 'courier' => $sub['courier'] ?? null],
        );
    }

    public function track(Shipment $shipment): TrackingResult
    {
        // Before the courier issues the AWB the shipment carries EasyParcel's parcel number:
        // ask by order number, and switch to the AWB (reported as the new tracking number) once it exists.
        $booking = (array) ($shipment->courier_meta['booking'] ?? []);
        $stillParcelNumber = empty($booking['awb']) && $shipment->courier_reference
            && (string) $shipment->tracking_number === (string) ($booking['parcel_number'] ?? $shipment->tracking_number);
        if ($stillParcelNumber) {
            $res = $this->call('EPParcelStatusBulk', ['bulk' => [['order_no' => $shipment->courier_reference]]]);
            $p = $res->json('result.0.parcel.0') ?? $res->json('result.0.parcel') ?? [];
            if (! $this->ok($res) || ! is_array($p)) {
                throw new CourierException('EasyParcel: ' . $this->error($res));
            }
            $awb = trim((string) ($p['awb'] ?? ''));
            if ($awb !== '') {
                return $this->trackAwb($awb, ['tracking_number' => $awb]);
            }
            $raw = $p['ship_status'] ?? null;

            return new TrackingResult(status: self::SHIP_STATUSES[strtolower(trim((string) $raw))] ?? StatusText::map($raw), rawStatus: $raw);
        }

        return $this->trackAwb((string) $shipment->tracking_number);
    }

    /** Full scan history by AWB; $meta travels with the result (e.g. a new tracking number). */
    private function trackAwb(string $awb, array $meta = []): TrackingResult
    {
        $res = $this->call('EPTrackingBulk', ['bulk' => [['awb_no' => $awb]]]);
        $item = $res->json('result.0') ?? [];
        if (! $this->ok($res) || empty($item)) {
            throw new CourierException('EasyParcel: ' . $this->error($res));
        }

        $events = [];
        foreach ($item['status_list'] ?? [] as $key => $e) {
            if (! is_numeric($key) || ! is_array($e)) {
                continue; // sender / receiver blocks
            }
            $events[] = new TrackingEvent(
                status: StatusText::map($e['status'] ?? null) ?? $this->fromEpCode($e['ep_status_code'] ?? null, $e['ep_status'] ?? null),
                rawStatus: $e['status'] ?? $e['ep_status'] ?? null,
                at: $this->time(trim(($e['event_date'] ?? '') . ' ' . ($e['event_time'] ?? ''))),
                location: $e['location'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;
        $status = StatusText::map($item['latest_status'] ?? null) ?? $latest?->status;

        return new TrackingResult(
            status: $status,
            rawStatus: $item['latest_status'] ?? $latest?->rawStatus,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $latest?->at : null,
            meta: $meta,
        );
    }

    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        throw new CourierException('EasyParcel: the airway bill link comes with payment; open the order in the EasyParcel portal.');
    }

    /** {topic, payload, event_id}: tracking/create carries awb + status_code. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $p = $request->input('payload');
        if (! is_array($p) || empty($p['awb'])) {
            return null;
        }
        $code = isset($p['status_code']) && is_numeric($p['status_code']) ? (int) $p['status_code'] : null;
        $status = $code !== null ? (self::CODES[$code] ?? null) : null;
        $status = StatusText::map($p['description'] ?? null) ?? $status;
        $at = $this->time($p['event_date'] ?? null);
        $result = new TrackingResult(
            status: $status,
            rawStatus: $p['description'] ?? ($code !== null ? "Code {$code}" : null),
            events: [new TrackingEvent($status, $p['description'] ?? null, $at, $p['location'] ?? null)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
            meta: ['tracking_number' => (string) $p['awb']],
        );

        // A shipment booked before its AWB existed is still known by parcel / order number.
        $keys = array_unique(array_filter([(string) $p['awb'], (string) ($p['parcel_no'] ?? $p['parcelno'] ?? ''), (string) ($p['order_no'] ?? $p['order_number'] ?? '')]));

        return array_map(fn ($k) => new WebhookUpdate($k, $result), array_values($keys));
    }

    // ------------------------------------------------------------------

    private function fromEpCode($code, ?string $text): ?string
    {
        return match ((int) $code) {
            1 => CourierStatus::BOOKED, 2 => CourierStatus::BOOKED, 3 => CourierStatus::PICKED_UP,
            4 => CourierStatus::IN_TRANSIT,
            5 => str_contains(strtolower((string) $text), 'return') ? CourierStatus::RETURNED : CourierStatus::DELIVERED,
            default => null,
        };
    }

    /** "Selangor" → "sgr"; a code passes through. */
    private function state(?string $state): string
    {
        $s = mb_strtolower(trim((string) $state));

        return self::MY_STATES[$s] ?? $s;
    }

    private function call(string $action, array $params): Response
    {
        $country = strtolower((string) (Shipper::address($this->account)['country'] ?: 'my'));
        $base = $this->account->isTestMode() ? 'http://demo.connect.easyparcel.my/' : 'https://connect.easyparcel.' . $country . '/';

        return $this->http()->asForm()->post($base . '?ac=' . $action, ['api' => $this->account->credential('api_key'), 'authentication' => ''] + $params);
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && $res->json('api_status') === 'Success' && (int) $res->json('error_code') === 0;
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
        return $res->json('error_remark') ?: ('HTTP ' . $res->status());
    }
}
