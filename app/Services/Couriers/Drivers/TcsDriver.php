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
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * TCS (Pakistan) — "TCS Connect" e-com API v1, built from TCS's published
 * Swagger specs and the official "API User Guide v1.0".
 * Production https://ociconnect.tcscourier.com, UAT https://devconnect.tcscourier.com.
 * Two tokens: a Bearer JWT from the client ID/secret (/auth/api/auth) on
 * every call, and an account `accesstoken` from the Envio login
 * (/ecom/api/authentication/token) inside ecom bodies/queries. Both are
 * cached until their expiry. Cities are 3-letter codes; pickups are cost
 * centers. No webhooks — status is polled.
 */
class TcsDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://ociconnect.tcscourier.com';
    private const UAT = 'https://devconnect.tcscourier.com';

    /** deliveryinfo[].code → Stocky status (others fall back to the wording). */
    private const CODES = [
        'OK' => CourierStatus::DELIVERED,
        'RO' => CourierStatus::RETURNING,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'tcs',
            'name' => 'TCS',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://www.tcsexpress.com',
            'tracking_url' => null,
            'description' => 'TCS Connect e-commerce API: COD booking, labels, tracking, cancellation and COD payment status.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => 'Issued by TCS for the TCS Connect API (UAT first, then production).'],
                ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'required' => true],
                ['key' => 'username', 'label' => 'Envio username', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Envio password', 'type' => 'password', 'required' => true],
                ['key' => 'account_no', 'label' => 'TCS account number', 'type' => 'text', 'required' => true,
                    'help' => 'e.g. 04011K1.'],
            ],
            'settings' => [
                ['key' => 'cost_center_code', 'label' => 'Cost center code', 'type' => 'text',
                    'help' => 'Your pickup location. "Test connection" lists your cost centers.'],
                ['key' => 'origin_city_name', 'label' => 'Origin city', 'type' => 'text',
                    'help' => 'City you ship from, as TCS names it (used when the pickup city is empty).'],
                ['key' => 'service_code', 'label' => 'Service code', 'type' => 'text',
                    'help' => 'Leave empty for O (overnight).'],
                ['key' => 'fragile', 'label' => 'Mark parcels fragile', 'type' => 'switch'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $token = $this->accessToken();
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }

        $res = $this->client()->get($this->base() . '/ecom/api/inquiry/costcenterinquiry', [
            'accesstoken' => $token, 'customerno' => $this->account->credential('account_no'),
        ]);
        $centers = collect($res->json('detail') ?? [])
            ->map(fn ($c) => ($c['costcentercode'] ?? '?') . ' (' . ($c['costcentercity'] ?? $c['costcentername'] ?? '') . ')')->implode(', ');

        return ConnectionResult::ok('Connected to TCS.' . ($centers ? " Cost centers: {$centers}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $acc = $this->account;
        $body = [
            'accesstoken' => $this->accessToken(),
            'consignmentno' => '',
            'shipperinfo' => array_filter([
                'tcsaccount' => $acc->credential('account_no'),
                'shippername' => $acc->pickup_contact ?: $acc->name,
                'address1' => $acc->pickup_address ?: ($acc->pickup_city ?: 'Pakistan'),
                'countrycode' => 'PK',
                'countryname' => 'Pakistan',
                'cityname' => $acc->pickup_city ?: $acc->setting('origin_city_name'),
                'mobile' => $acc->pickup_phone ? Phone::pk($acc->pickup_phone) : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'consigneeinfo' => array_filter([
                'firstname' => $request->consigneeName,
                'address1' => $request->address,
                'countrycode' => 'PK',
                'countryname' => 'Pakistan',
                'citycode' => $request->courierCityCode,
                'cityname' => $request->courierCityName ?: $request->city,
                'email' => $request->consigneeEmail,
                'mobile' => Phone::pk($request->consigneePhone),
                'zip' => $request->zip,
            ], fn ($v) => $v !== null && $v !== ''),
            'shipmentinfo' => array_filter([
                'costcentercode' => $acc->setting('cost_center_code'),
                'referenceno' => mb_substr($request->reference, 0, 50),
                'contentdesc' => $request->itemsDescription ?: null,
                'servicecode' => $request->service ?: ($acc->setting('service_code') ?: 'O'),
                'currency' => 'PKR',
                'codamount' => (int) round($request->codAmount),
                'declaredvalue' => $request->declaredValue > 0 ? (int) round($request->declaredValue) : null,
                'weightinkg' => max(0.5, round($request->weight, 2)),
                'pieces' => $request->pieces,
                'fragile' => (bool) $acc->setting('fragile'),
                'remarks' => $request->notes ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        $res = $this->client()->asJson()->post($this->base() . '/ecom/api/booking/create', $body);
        $cn = $res->json('consignmentNo');
        if (! $res->successful() || ! $res->json('status') || ! $cn) {
            throw new CourierException('TCS: ' . $this->error($res));
        }

        return new BookingResult(trackingNumber: (string) $cn, status: CourierStatus::BOOKED, rawStatus: 'Booked');
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->asJson()->post($this->base() . '/ecom/api/booking/cancel', [
            'consignmentnumber' => (string) $shipment->tracking_number,
            'accesstoken' => $this->accessToken(),
        ]);
        if (! $res->successful() || ! str_starts_with(strtoupper((string) $res->json('message')), 'SUCCESS')) {
            throw new CourierException('TCS: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . '/tracking/api/Tracking/GetDynamicTrackDetail', ['consignee' => (string) $shipment->tracking_number]);
        if (! $res->successful() || strtoupper((string) $res->json('message')) !== 'SUCCESS') {
            throw new CourierException('TCS: ' . ($res->json('shipmentsummary') ?: $this->error($res)));
        }

        $events = [];
        foreach ($res->json('checkpoints') ?? [] as $c) {
            $events[] = new TrackingEvent(
                status: StatusText::map($c['status'] ?? null),
                rawStatus: $c['status'] ?? null,
                at: $this->time($c['datetime'] ?? null),
                location: $c['recievedby'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $delivery = $res->json('deliveryinfo.0') ?? [];
        $code = strtoupper(trim((string) ($delivery['code'] ?? '')));
        $raw = $delivery['status'] ?? ($events ? end($events)->rawStatus : null);
        $status = self::CODES[$code] ?? StatusText::map($delivery['status'] ?? null)
            ?? ($events ? end($events)->status : null)
            ?? StatusText::map(preg_replace('/^Current Status:\s*/i', '', (string) $res->json('shipmentsummary')));

        [$codStatus, $codRef, $codAt, $charges] = $status === CourierStatus::DELIVERED ? $this->payment($shipment) : [null, null, null, null];

        return new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($delivery['datetime'] ?? null) : null,
            codStatus: $codStatus,
            codReference: $codRef,
            codRemittedAt: $codAt,
            charges: $charges,
        );
    }

    /** TCS label: PDF, or a JSON body carrying a URL. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->withHeaders(['Accept' => 'application/pdf, application/json'])
            ->get($this->base() . '/ecom/api/print/label', [
                'accesstoken' => $this->accessToken(),
                'consignmentno' => (string) $shipment->tracking_number,
                'shipperDetails' => 'true',
                'printtype' => 3,
                'accounttype' => 1,
            ]);

        if ($res->successful() && str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            return ['pdf' => $res->body()];
        }
        if ($res->successful() && $res->json('url')) {
            return ['url' => $res->json('url')];
        }

        throw new CourierException('TCS: ' . $this->error($res));
    }

    public function cities(): array
    {
        $res = $this->client()->get($this->base() . '/ecom/api/setup/citylistbycountry', ['countrycode' => 'PK']);
        if (! $res->successful() || strtoupper((string) $res->json('message')) !== 'SUCCESS') {
            throw new CourierException('TCS: ' . $this->error($res));
        }

        return collect($res->json('data') ?? [])
            ->filter(fn ($c) => ! empty($c['citycode']) && ! empty($c['cityname']))
            ->map(fn ($c) => ['code' => $c['citycode'], 'name' => $c['cityname']])
            ->values()->all();
    }

    // ------------------------------------------------------------------

    /** COD payment status; "payment status" N = not paid yet. */
    private function payment(Shipment $shipment): array
    {
        try {
            $res = $this->client()->get($this->base() . '/ecom/api/Payment/status', [
                'accesstoken' => $this->accessToken(),
                'customerno' => $this->account->credential('account_no'),
                'cnno' => (string) $shipment->tracking_number,
            ]);
            $d = $res->json('detail.0') ?? [];
            $charges = isset($d['delivery charges']) && is_numeric($d['delivery charges']) ? (float) $d['delivery charges'] : null;
            $flag = strtoupper(trim((string) ($d['payment status'] ?? '')));
            $paid = $res->successful() && $flag !== '' && $flag !== 'N' && (float) ($d['amount paid'] ?? 0) > 0;

            return $paid
                ? ['remitted', null, $this->time($d['payment date'] ?? null), $charges]
                : [null, null, null, $charges];
        } catch (\Throwable $e) {
            return [null, null, null, null];
        }
    }

    private function bearer(): string
    {
        return Cache::get($this->cacheKey('jwt')) ?? $this->issue('jwt', function () {
            $res = $this->http()->asJson()->post($this->base() . '/auth/api/auth', [
                'clientid' => $this->account->credential('client_id'),
                'clientsecret' => $this->account->credential('client_secret'),
            ]);
            $token = $res->json('result.accessToken');
            if (! $res->successful() || ! $res->json('status') || ! $token) {
                throw new CourierException('TCS: client ID / secret rejected.');
            }

            return [$token, $res->json('result.expiry')];
        });
    }

    private function accessToken(): string
    {
        return Cache::get($this->cacheKey('access')) ?? $this->issue('access', function () {
            $res = $this->client()->get($this->base() . '/ecom/api/authentication/token', [
                'username' => $this->account->credential('username'),
                'password' => $this->account->credential('password'),
            ]);
            $token = $res->json('accesstoken');
            if (! $res->successful() || ! $token) {
                throw new CourierException('TCS: Envio username / password rejected (' . $this->error($res) . ').');
            }

            return [$token, $res->json('expiry')];
        });
    }

    /** Cache a token until 5 minutes before its expiry (1 hour when unknown). */
    private function issue(string $kind, callable $fetch): string
    {
        [$token, $expiry] = $fetch();
        $until = $this->time($expiry)?->subMinutes(5);
        Cache::put($this->cacheKey($kind), $token, $until && $until->isFuture() ? $until : now()->addHour());

        return $token;
    }

    private function cacheKey(string $kind): string
    {
        $a = $this->account;

        return 'courier_tcs_' . $kind . ':' . (tenant('id') ?? 'central') . ':'
            . md5($a->mode . '|' . $a->credential('client_id') . '|' . $a->credential('username') . '|' . $a->credential('client_secret') . '|' . $a->credential('password'));
    }

    private function client()
    {
        return $this->http()->withToken($this->bearer());
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::UAT : self::LIVE;
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
        $errors = $res->json('error');
        if (is_array($errors) && $errors) {
            $parts = [];
            foreach ($errors as $e) {
                foreach ((array) $e as $field => $desc) {
                    $parts[] = is_string($field) ? "{$field}: {$desc}" : (string) $desc;
                }
            }

            return implode('; ', $parts);
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
