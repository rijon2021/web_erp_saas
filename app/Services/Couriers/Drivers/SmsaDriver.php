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
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * SMSA Express (Saudi Arabia) — the REST "eCom API", as used by SMSA's
 * official WooCommerce plugin. Production https://ecomapis.smsaexpress.com,
 * sandbox https://ecomapis-sandbox.azurewebsites.net; header `apikey`.
 * POST /api/shipment/b2c/new returns the shipment AWB (sawb) and base64 PDF
 * labels per parcel. City must be the SMSA city name (lookup endpoint).
 * Webhooks are enabled by SMSA on request and post an array of tracking
 * objects; they are unsigned, so this account's secret URL token protects them.
 */
class SmsaDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://ecomapis.smsaexpress.com/';
    private const SANDBOX = 'https://ecomapis-sandbox.azurewebsites.net/';

    private const SCANS = [
        'AF' => CourierStatus::IN_TRANSIT, 'HOP' => CourierStatus::IN_TRANSIT, 'CR' => CourierStatus::IN_TRANSIT, 'CC' => CourierStatus::IN_TRANSIT,
        'OD' => CourierStatus::OUT_FOR_DELIVERY,
        'DL' => CourierStatus::DELIVERED, 'POD' => CourierStatus::DELIVERED, 'DEX09' => CourierStatus::DELIVERED,
        'RTO' => CourierStatus::RETURNING, 'DEX14' => CourierStatus::RETURNING,
        'RTS' => CourierStatus::RETURNED,
        'DE' => CourierStatus::FAILED_ATTEMPT,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'smsa',
            'name' => 'SMSA Express',
            'region' => 'Saudi Arabia / Gulf',
            'countries' => ['SA', 'AE', 'BH', 'KW', 'OM', 'QA', 'EG', 'JO'],
            'website' => 'https://www.smsaexpress.com',
            'tracking_url' => null,
            'description' => 'SMSA eCom API: COD shipments with PDF waybills, tracking, cancellation and status pushes.',
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
                    'help' => 'From SMSA; sandbox and production keys differ.'],
            ],
            'settings' => array_merge(Shipper::fields(false), [
                ['key' => 'service_code', 'label' => 'Service code', 'type' => 'text',
                    'help' => 'Leave empty for EDDL (domestic) / EIDL (international).'],
            ]),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'api/track/statuslookup');

        return $res->successful()
            ? ConnectionResult::ok('Connected to SMSA Express.')
            : ConnectionResult::failed('SMSA: ' . ($res->status() === 401 ? 'API key rejected.' : $this->error($res)));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $from = Shipper::address($this->account);
        $origin = $from['country'] ?: 'SA';
        $dest = $request->countryCode($origin);
        $currency = match ($dest) { 'AE' => 'AED', 'BH' => 'BHD', 'KW' => 'KWD', 'OM' => 'OMR', 'QA' => 'QAR', 'EG' => 'EGP', 'JO' => 'JOD', default => 'SAR' };
        $value = round(max(0.1, $request->declaredValue, $request->codAmount), 2);

        $body = [
            'ConsigneeAddress' => array_filter([
                'ContactName' => $this->atLeast($request->consigneeName, 5, $request->city),
                'ContactPhoneNumber' => $request->consigneePhone,
                'Country' => $dest,
                'City' => $request->courierCityName ?: $request->city,
                'District' => $request->state,
                'PostalCode' => $request->zip,
                'AddressLine1' => mb_substr($this->atLeast($request->address, 10, $request->city), 0, 100),
                'AddressLine2' => mb_strlen($request->address) > 100 ? mb_substr($request->address, 100, 100) : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'ShipperAddress' => array_filter([
                'ContactName' => $this->atLeast($from['name'], 5, $from['city']),
                'ContactPhoneNumber' => $from['phone'],
                'Country' => $origin,
                'City' => $from['city'],
                'PostalCode' => $from['zip'] ?: null,
                'AddressLine1' => mb_substr($this->atLeast($from['street'], 10, $from['city']), 0, 100),
            ], fn ($v) => $v !== null && $v !== ''),
            'OrderNumber' => mb_substr($request->reference, 0, 50),
            'DeclaredValue' => $value,
            'CODAmount' => round($request->codAmount, 2),
            'Parcels' => min(6, $request->pieces),
            'ShipDate' => now('Asia/Riyadh')->format('Y-m-d\TH:i:s'),
            'ShipmentCurrency' => $currency,
            'SMSARetailID' => '0',
            'WaybillType' => 'PDF',
            'Weight' => max(0.001, round($request->weight, 3)),
            'WeightUnit' => 'KG',
            'ContentDescription' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100),
            'ServiceCode' => $request->service ?: ($this->account->setting('service_code') ?: ($dest === $origin ? 'EDDL' : 'EIDL')),
        ];

        $res = $this->client()->post($this->base() . 'api/shipment/b2c/new', $body);
        $awb = $res->json('sawb');
        if (! $res->successful() || ! $awb) {
            throw new CourierException('SMSA: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) $awb,
            status: CourierStatus::BOOKED,
            rawStatus: 'Created',
            meta: ['parcel_awbs' => collect($res->json('waybills') ?? [])->pluck('awb')->filter()->values()->all()],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->patch($this->base() . 'api/Shipment/b2c/' . rawurlencode((string) $shipment->tracking_number) . '/cancel');
        if (! $res->successful()) {
            throw new CourierException('SMSA: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'api/track/single/' . rawurlencode((string) $shipment->tracking_number));
        if (! $res->successful() || ! is_array($res->json())) {
            throw new CourierException('SMSA: ' . $this->error($res));
        }

        return $this->toResult($res->json());
    }

    /** First parcel's base64 PDF from the shipment query. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->get($this->base() . 'api/shipment/b2c/query/' . rawurlencode((string) $shipment->tracking_number));
        $file = $res->json('waybills.0.awbFile');
        if (! $res->successful() || ! $file) {
            throw new CourierException('SMSA: ' . $this->error($res));
        }

        return ['pdf' => base64_decode($file)];
    }

    /** SMSA city names: [{code: cityName, name: cityName}]. */
    public function cities(): array
    {
        $country = Shipper::address($this->account)['country'] ?: 'SA';
        $res = $this->client()->get($this->base() . 'api/lookup/cities/' . $country);
        if (! $res->successful()) {
            throw new CourierException('SMSA: ' . $this->error($res));
        }

        return collect($res->json() ?? [])
            ->map(fn ($c) => $c['cityName'] ?? $c['CityName'] ?? null)
            ->filter()->unique()->map(fn ($n) => ['code' => $n, 'name' => $n])->values()->all();
    }

    /** Push: an array of tracking objects ({AWB, Scans[]…}). */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $items = $request->json()->all();
        if (! is_array($items) || ! array_is_list($items)) {
            $items = [$items];
        }

        $updates = [];
        foreach ($items as $item) {
            if (is_array($item) && ! empty($item['AWB'] ?? $item['awb'] ?? null)) {
                $updates[] = new WebhookUpdate((string) ($item['AWB'] ?? $item['awb']), $this->toResult($item));
            }
        }

        return $updates ?: null;
    }

    // ------------------------------------------------------------------

    private function toResult(array $t): TrackingResult
    {
        $events = [];
        foreach ($t['Scans'] ?? $t['scans'] ?? [] as $s) {
            $code = strtoupper(trim((string) ($s['ScanType'] ?? '')));
            $desc = $s['ScanDescription'] ?? null;
            $status = self::SCANS[$code] ?? (str_starts_with($code, 'DEX') ? CourierStatus::FAILED_ATTEMPT : StatusText::map($desc));
            $events[] = new TrackingEvent(
                status: $status,
                rawStatus: $desc ?: ($code ?: null),
                at: $this->time(trim(($s['ScanDateTime'] ?? '') . ($s['ScanTimeZone'] ?? ''))),
                location: $s['City'] ?? null,
                description: ! empty($s['ReceivedBy']) ? 'Received by ' . $s['ReceivedBy'] : null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;

        $status = ! empty($t['isDelivered']) ? CourierStatus::DELIVERED : $latest?->status;

        return new TrackingResult(
            status: $status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $latest?->at : null,
        );
    }

    /** SMSA enforces minimum lengths on names and address lines. */
    private function atLeast(string $value, int $min, string $suffix): string
    {
        $value = trim($value);

        return mb_strlen($value) >= $min ? $value : trim($value . ', ' . $suffix);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE;
    }

    private function client()
    {
        return $this->http()->timeout(40)->withHeaders(['apikey' => $this->account->credential('api_key')]);
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
            return collect($errors)->flatten()->filter(fn ($v) => is_string($v))->implode('; ');
        }
        $json = $res->json();
        if (is_string($json) && $json !== '') {
            return $json;
        }

        return $res->json('message') ?: $res->json('title') ?: ('HTTP ' . $res->status());
    }
}
