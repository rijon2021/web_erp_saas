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
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Bosta (Egypt & Saudi Arabia), from Bosta's official OpenAPI spec
 * ("Bosta Server APIs" v2) and its WooCommerce plugin. Live
 * https://app.bosta.co/api/v2, staging https://stg-app.bosta.co/api/v2;
 * header `Authorization: <API key>` (no Bearer); envelope {success, message,
 * data}. The drop-off needs a city name + districtId (from getAllDistricts).
 * Each delivery registers this account's webhook URL with a custom header,
 * so status changes are pushed without dashboard setup. AWBs come back as a
 * base64 PDF. Cancelling (terminate) needs a Full Access key.
 */
class BostaDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://app.bosta.co/api/v2/';
    private const STAGING = 'https://stg-app.bosta.co/api/v2/';

    private const COUNTRY_IDS = ['EG' => '60e4482c7cb7d4bc4849c4d5', 'SA' => 'eF-3f9FZr'];

    private const STATES = [
        10 => CourierStatus::BOOKED, 11 => CourierStatus::BOOKED, 20 => CourierStatus::BOOKED, 25 => CourierStatus::BOOKED,
        21 => CourierStatus::PICKED_UP, 23 => CourierStatus::PICKED_UP,
        24 => CourierStatus::IN_TRANSIT, 30 => CourierStatus::IN_TRANSIT, 102 => CourierStatus::IN_TRANSIT, 105 => CourierStatus::IN_TRANSIT,
        40 => CourierStatus::OUT_FOR_DELIVERY, 41 => CourierStatus::OUT_FOR_DELIVERY,
        45 => CourierStatus::DELIVERED,
        46 => CourierStatus::RETURNED, 60 => CourierStatus::RETURNED,
        47 => CourierStatus::FAILED_ATTEMPT,
        103 => CourierStatus::RETURNING,
        48 => CourierStatus::CANCELLED, 49 => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'bosta',
            'name' => 'Bosta',
            'region' => 'Egypt / Saudi Arabia',
            'countries' => ['EG', 'SA'],
            'website' => 'https://bosta.co',
            'tracking_url' => null,
            'description' => 'COD deliveries across Egypt and Saudi Arabia: booking by area, airway bills, tracking and instant status updates.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'cities' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Bosta dashboard → Settings → API Integration. Use a Full Access key so bookings can be cancelled.'],
            ],
            'settings' => [
                ['key' => 'country', 'label' => 'Country', 'type' => 'select', 'options' => [
                    ['value' => 'EG', 'label' => 'Egypt'], ['value' => 'SA', 'label' => 'Saudi Arabia'],
                ], 'help' => 'Leave empty for Egypt.'],
                ['key' => 'package_size', 'label' => 'Package size', 'type' => 'select', 'options' => [
                    ['value' => 'SMALL', 'label' => 'Small'], ['value' => 'MEDIUM', 'label' => 'Medium'], ['value' => 'LARGE', 'label' => 'Large'],
                ], 'help' => 'Leave empty for Small.'],
                ['key' => 'business_location_id', 'label' => 'Pickup location ID', 'type' => 'text',
                    'help' => 'Optional; your default Bosta pickup location is used otherwise.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'pickup-locations');

        return $res->successful()
            ? ConnectionResult::ok('Connected to Bosta.')
            : ConnectionResult::failed('Bosta: ' . $this->error($res));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $district = $this->district($request);
        [$first, $last] = array_pad(explode(' ', trim($request->consigneeName), 2), 2, '');
        $firstLine = $request->address;
        if (mb_strlen($firstLine) <= 5) {
            $firstLine = trim($firstLine . ', ' . $request->city);
        }

        $body = array_filter([
            'type' => 10,
            'specs' => [
                'size' => $this->account->setting('package_size') ?: 'SMALL',
                'packageType' => 'Parcel',
                'packageDetails' => ['itemsCount' => $request->pieces, 'description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 250)],
            ],
            'notes' => $request->notes ?: null,
            'cod' => round($request->codAmount, 2),
            'goodsInfo' => $request->declaredValue > 0 ? ['amount' => round($request->declaredValue, 2)] : null,
            'dropOffAddress' => ['firstLine' => $firstLine, 'city' => $district['city'], 'districtId' => $district['code']],
            'receiver' => array_filter(['firstName' => $first, 'lastName' => $last ?: null, 'phone' => $this->phone($request->consigneePhone), 'email' => $request->consigneeEmail]),
            'businessReference' => mb_substr($request->reference, 0, 100),
            'uniqueBusinessReference' => mb_substr($request->reference, 0, 90) . '-' . Str::lower(Str::random(4)),
            'businessLocationId' => $this->account->setting('business_location_id') ?: null,
            'webhookUrl' => url('/api/couriers/webhook/' . $this->account->webhook_token),
            'webhookCustomHeaders' => ['X-Stocky-Token' => (string) $this->account->webhook_token],
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . 'deliveries?apiVersion=1', $body);
        $d = $res->json('data') ?? [];
        if (! $res->successful() || $res->json('success') === false || empty($d['trackingNumber'])) {
            throw new CourierException('Bosta: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) $d['trackingNumber'],
            courierReference: (string) ($d['_id'] ?? ''),
            status: CourierStatus::BOOKED,
            rawStatus: $d['state']['value'] ?? 'Pickup requested',
        );
    }

    /** Terminate (needs a Full Access key). */
    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->delete($this->base() . 'deliveries/business/' . rawurlencode((string) $shipment->tracking_number) . '/terminate');
        if (! $res->successful() || $res->json('success') === false) {
            throw new CourierException('Bosta: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'deliveries/business/' . rawurlencode((string) $shipment->tracking_number));
        $d = $res->json('data');
        if (! $res->successful() || ! is_array($d)) {
            throw new CourierException('Bosta: ' . $this->error($res));
        }

        $events = [];
        foreach ($d['history'] ?? [] as $h) {
            $title = $h['title'] ?? null;
            $events[] = new TrackingEvent(
                status: StatusText::map($title),
                rawStatus: $title,
                at: $this->time($h['date'] ?? null),
                description: collect($h['subs'] ?? [])->pluck('title')->filter()->implode(' · ') ?: null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $code = isset($d['state']['code']) ? (int) $d['state']['code'] : null;
        $type = strtoupper((string) ($d['type']['value'] ?? $d['type']['code'] ?? ''));

        return new TrackingResult(
            status: $this->map($code, $type),
            rawStatus: $d['state']['value'] ?? $d['maskedState'] ?? null,
            events: $events,
        );
    }

    /** Thermal A6 airway bill, base64 PDF. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->post($this->base() . 'deliveries/mass-awb', [
            'trackingNumbers' => (string) $shipment->tracking_number,
            'requestedAwbType' => 'A6',
            'lang' => 'en',
        ]);
        $data = $res->json('data');
        if (! $res->successful() || ! is_string($data) || $data === '') {
            throw new CourierException('Bosta: ' . $this->error($res));
        }

        return ['pdf' => base64_decode($data)];
    }

    /** City → district list (districts are what Bosta delivers to). */
    public function cities(): array
    {
        $res = $this->client()->get($this->base() . 'cities/getAllDistricts', ['countryId' => $this->countryId()]);
        if (! $res->successful()) {
            throw new CourierException('Bosta: ' . $this->error($res));
        }

        $out = [];
        foreach ($res->json('data') ?? [] as $city) {
            foreach ($city['districts'] ?? [] as $dist) {
                if (($dist['dropOffAvailability'] ?? true) && ! empty($dist['districtId'])) {
                    $out[] = [
                        'code' => (string) $dist['districtId'],
                        'name' => trim(($dist['districtName'] ?? '') . ' (' . ($city['cityName'] ?? '') . ')'),
                        'city' => (string) ($city['cityName'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }

    /** State-change push: {_id, trackingNumber, state, type, cod, timeStamp, exceptionReason, …}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        // Registered with every delivery (webhookCustomHeaders), so Bosta always sends it.
        $header = (string) $request->header('X-Stocky-Token');
        if ($header === '' || ! hash_equals((string) $this->account->webhook_token, $header)) {
            throw new CourierException('Invalid Bosta webhook header.');
        }

        $tn = $request->input('trackingNumber');
        if ($tn === null || $tn === '') {
            return null;
        }

        $code = is_numeric($request->input('state')) ? (int) $request->input('state') : null;
        $status = $this->map($code, strtoupper((string) $request->input('type')));
        $at = is_numeric($request->input('timeStamp')) ? Carbon::createFromTimestampMs((int) $request->input('timeStamp')) : now();

        return new WebhookUpdate((string) $tn, new TrackingResult(
            status: $status,
            rawStatus: $code !== null ? "State {$code}" : null,
            events: [new TrackingEvent($status, $code !== null ? "State {$code}" : null, $at, null, $request->input('exceptionReason'))],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    /** Code 41 means "out for return" on return-type deliveries. */
    private function map(?int $code, string $type): ?string
    {
        if ($code === 41 && in_array($type, ['RTO', 'CUSTOMER_RETURN_PICKUP', 'EXCHANGE'], true)) {
            return CourierStatus::RETURNING;
        }

        return $code !== null ? (self::STATES[$code] ?? null) : null;
    }

    private function district(ShipmentRequest $request): array
    {
        $all = $this->cities();
        if ($request->courierCityCode) {
            foreach ($all as $d) {
                if ($d['code'] === (string) $request->courierCityCode) {
                    return $d;
                }
            }
        }
        // Try the area / state the customer typed against district names.
        foreach ([$request->state, $request->city] as $guess) {
            $needle = mb_strtolower(trim((string) $guess));
            if ($needle === '') {
                continue;
            }
            foreach ($all as $d) {
                if (mb_strtolower(trim(explode(' (', $d['name'])[0])) === $needle) {
                    return $d;
                }
            }
        }
        throw new CourierException('Choose the Bosta area for "' . $request->city . '".');
    }

    /** Egyptian local format 01XXXXXXXXX. */
    private function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '20') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) === 10 && str_starts_with($digits, '1') ? '0' . $digits : $digits;
    }

    private function countryId(): string
    {
        return self::COUNTRY_IDS[$this->account->setting('country') ?: 'EG'] ?? self::COUNTRY_IDS['EG'];
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function client()
    {
        return $this->http()->withHeaders(['Authorization' => $this->account->credential('api_key')]);
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
        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
