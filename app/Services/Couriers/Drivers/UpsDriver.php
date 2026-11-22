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
use App\Services\Couriers\Support\Shipper;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UPS REST APIs (github.com/UPS-API OpenAPI specs). OAuth client credentials
 * (HTTP Basic) → bearer, cached for its expires_in. Rating / Shipping / Void
 * v2409, Tracking v1. Ship returns GIF labels only (no PDF), kept at
 * booking. US/PR origins weigh in LBS/IN, everyone else KGS/CM. COD: package
 * level from US/PR/CA, shipment level elsewhere (UPS rejects unsupported
 * lanes). Track Alert (billed per tracking number) is optional: each parcel
 * is subscribed with this account's webhook URL and the callback carries
 * the account's webhook token in the "credential" header.
 */
class UpsDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://onlinetools.ups.com/';
    private const TEST = 'https://wwwcie.ups.com/';
    private const V = 'v2409';

    private const SERVICES = [
        '01' => 'Next Day Air', '02' => '2nd Day Air', '03' => 'Ground', '07' => 'Worldwide Express', '08' => 'Worldwide Expedited',
        '11' => 'Standard', '12' => '3 Day Select', '13' => 'Next Day Air Saver', '14' => 'Next Day Air Early', '54' => 'Worldwide Express Plus',
        '59' => '2nd Day Air A.M.', '65' => 'Worldwide Saver', '17' => 'Worldwide Economy DDU', '72' => 'Worldwide Economy DDP',
        '74' => 'Express 12:00', '70' => 'Access Point Economy',
    ];

    private const RETURNED = ['034', '067', '081'];
    private const RETURNING = ['033', '035', '036'];
    private const OUT_FOR_DELIVERY = ['006', '021', '037', '072', '089', '091', '146', '162'];
    private const FAILED = ['022', '023', '024', '060', '061', '062', '064', '069', '145'];
    private const PICKED_UP = ['038', '160', '167', '168', '169'];

    public static function meta(): array
    {
        return [
            'code' => 'ups',
            'name' => 'UPS',
            'region' => 'Global',
            'countries' => [],
            'website' => 'https://www.ups.com',
            'tracking_url' => null,
            'description' => 'UPS domestic and worldwide: live rates, labels, void, tracking and optional Track Alert push updates.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => 'developer.ups.com → Apps. Add the Rating, Shipping and Tracking products (and Track Alert for push updates).'],
                ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'required' => true],
                ['key' => 'account_number', 'label' => 'UPS account (shipper) number', 'type' => 'text', 'required' => true,
                    'help' => 'Six characters, linked to the same UPS ID as the app.'],
            ],
            'settings' => array_merge([
                ['key' => 'service_code', 'label' => 'Default service', 'type' => 'select', 'options' => collect(self::SERVICES)
                    ->map(fn ($name, $code) => ['value' => (string) $code, 'label' => $code . ' — ' . $name])->values()->all(),
                    'help' => 'Empty = the cheapest service UPS quotes for the address.'],
                ['key' => 'currency', 'label' => 'Currency for customs and COD', 'type' => 'text',
                    'help' => 'Three letters, e.g. USD, EUR. Empty = your default currency.'],
                ['key' => 'track_alert', 'label' => 'Push updates with UPS Track Alert', 'type' => 'switch',
                    'help' => 'UPS bills each tracking number subscribed. Off = the status is checked on a schedule.'],
            ], Shipper::fields()),
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

        return ConnectionResult::ok('Connected to UPS. Use "Compare rates" on a sale to confirm the account number.');
    }

    public function rates(ShipmentRequest $request): array
    {
        [$from, $to, $intl, $currency, $units] = $this->lane($request);

        $res = $this->client()->post($this->base() . 'api/rating/' . self::V . '/Shop', ['RateRequest' => [
            'Request' => ['TransactionReference' => ['CustomerContext' => mb_substr($request->reference, 0, 35)]],
            'Shipment' => array_filter([
                'Shipper' => ['Name' => mb_substr($from['company'] ?: $from['name'], 0, 35), 'ShipperNumber' => $this->account->credential('account_number'), 'Address' => $this->address($from)],
                'ShipTo' => ['Name' => mb_substr($request->consigneeName, 0, 35), 'Address' => $this->address($to) + ['ResidentialAddressIndicator' => '']],
                'ShipFrom' => ['Name' => mb_substr($from['company'] ?: $from['name'], 0, 35), 'Address' => $this->address($from)],
                'PaymentDetails' => ['ShipmentCharge' => [['Type' => '01', 'BillShipper' => ['AccountNumber' => $this->account->credential('account_number')]]]],
                'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => 'Y'],
                'InvoiceLineTotal' => $intl ? ['CurrencyCode' => $currency, 'MonetaryValue' => $this->money($this->value($request))] : null,
                'Package' => [$this->package($request, $units, 'PackagingType')],
            ]),
        ]]);
        if (! $res->successful()) {
            throw new CourierException('UPS: ' . $this->error($res));
        }

        $out = [];
        foreach ((array) $res->json('RateResponse.RatedShipment') as $r) {
            $code = (string) ($r['Service']['Code'] ?? '');
            $total = $r['NegotiatedRateCharges']['TotalCharge'] ?? $r['TotalCharges'] ?? null;
            if ($code === '' || ! $total) {
                continue;
            }
            $days = $r['GuaranteedDelivery']['BusinessDaysInTransit'] ?? null;
            $out[] = new Rate(
                id: $code,
                carrier: 'UPS',
                service: self::SERVICES[$code] ?? ('Service ' . $code),
                amount: (float) ($total['MonetaryValue'] ?? 0),
                currency: (string) ($total['CurrencyCode'] ?? $currency),
                days: is_numeric($days) ? (int) $days : null,
            );
        }

        return $out;
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        [$from, $to, $intl, $currency, $units] = $this->lane($request);
        $service = $request->service && preg_match('/^[0-9A-Z]{2}$/', $request->service) ? $request->service
            : (trim((string) $this->account->setting('service_code')) ?: $this->cheapestService($request));
        $cod = $request->codAmount > 0;
        $codDetail = ['CODFundsCode' => '0', 'CODAmount' => ['CurrencyCode' => $currency, 'MonetaryValue' => $this->money($request->codAmount)]];
        // Package-level COD from US/PR/CA; shipment-level (cash) from other origins.
        $packageCod = $cod && in_array($from['country'], ['US', 'PR', 'CA'], true);

        $package = $this->package($request, $units, 'Packaging');
        $package['Description'] = mb_substr($request->itemsDescription ?: 'Goods', 0, 35);
        $package['ReferenceNumber'] = [['Value' => mb_substr($request->reference, 0, 35)]];
        if ($packageCod) {
            $package['PackageServiceOptions'] = ['COD' => $codDetail];
        }

        $shipper = $this->party($from, $from['company'] ?: $from['name'], $from['name'], $from['phone'], $from['email']);
        $res = $this->client()->post($this->base() . 'api/shipments/' . self::V . '/ship', ['ShipmentRequest' => [
            'Request' => ['RequestOption' => 'nonvalidate', 'TransactionReference' => ['CustomerContext' => mb_substr($request->reference, 0, 35)]],
            'Shipment' => array_filter([
                'Description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 50),
                'Shipper' => $shipper + ['ShipperNumber' => $this->account->credential('account_number')],
                'ShipTo' => $this->party($to, $request->consigneeName, $request->consigneeName, $request->consigneePhone, (string) $request->consigneeEmail, true),
                'ShipFrom' => $shipper,
                'PaymentInformation' => ['ShipmentCharge' => [['Type' => '01', 'BillShipper' => ['AccountNumber' => $this->account->credential('account_number')]]]],
                'Service' => ['Code' => $service, 'Description' => self::SERVICES[$service] ?? $service],
                'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => 'Y'],
                'ShipmentServiceOptions' => $cod && ! $packageCod ? ['COD' => array_merge($codDetail, ['CODFundsCode' => '1'])] : null,
                'InvoiceLineTotal' => $intl ? ['CurrencyCode' => $currency, 'MonetaryValue' => $this->money($this->value($request))] : null,
                'ReferenceNumber' => [['Value' => mb_substr($request->reference, 0, 35)]],
                'Package' => [$package],
            ]),
            'LabelSpecification' => ['LabelImageFormat' => ['Code' => 'GIF'], 'HTTPUserAgent' => 'Mozilla/4.5', 'LabelStockSize' => ['Height' => '6', 'Width' => '4']],
        ]]);
        $results = $res->json('ShipmentResponse.ShipmentResults');
        $pkg = $results['PackageResults'][0] ?? ($results['PackageResults'] ?? []);
        $tn = $pkg['TrackingNumber'] ?? ($results['ShipmentIdentificationNumber'] ?? null);
        if (! $res->successful() || ! $tn) {
            throw new CourierException('UPS: ' . $this->error($res));
        }

        $total = $results['NegotiatedRateCharges']['TotalCharge'] ?? $results['ShipmentCharges']['TotalCharges'] ?? null;
        $meta = array_filter([
            'service_code' => $service,
            'shipment_id' => $results['ShipmentIdentificationNumber'] ?? null,
            'label_gif' => $pkg['ShippingLabel']['GraphicImage'] ?? null,
        ]);
        if ($this->account->setting('track_alert')) {
            $meta['track_alert'] = $this->subscribe((string) $tn, $to['country']);
        }

        return new BookingResult(
            trackingNumber: (string) $tn,
            courierReference: $results['ShipmentIdentificationNumber'] ?? null,
            charges: isset($total['MonetaryValue']) ? (float) $total['MonetaryValue'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Label created',
            meta: $meta,
        );
    }

    /** Void (US, PR and CA origins; up to 28 days). */
    public function cancel(Shipment $shipment): void
    {
        $id = (string) ($shipment->courier_reference ?: $shipment->tracking_number);
        $res = $this->client()->delete($this->base() . 'api/shipments/' . self::V . '/void/cancel/' . rawurlencode($id));
        $code = $this->clean($res->json('VoidShipmentResponse.SummaryResult.Status.Code'));
        if (! $res->successful() || $code !== '1') {
            throw new CourierException('UPS: ' . ($this->clean($res->json('VoidShipmentResponse.SummaryResult.Status.Description')) ?: $this->error($res)));
        }
    }

    /** UPS labels are GIF images. */
    public function label(Shipment $shipment): array
    {
        $b64 = $shipment->courier_meta['booking']['label_gif'] ?? null;
        $img = $b64 ? base64_decode((string) $b64, true) : false;
        if (! $img) {
            throw new CourierException('UPS returns the label only when the shipment is created, and none was kept for this one.');
        }

        return ['image' => $img, 'mime' => 'image/gif'];
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'api/track/v1/details/' . rawurlencode((string) $shipment->tracking_number), [
            'locale' => 'en_US', 'returnSignature' => 'false', 'returnMilestones' => 'false', 'returnPOD' => 'false',
        ]);
        $warning = collect((array) $res->json('trackResponse.shipment.0.warnings'))->pluck('code')->first();
        if ($res->status() === 404 || $warning === 'TW0001') {
            return new TrackingResult(status: null); // nothing scanned yet
        }
        $pkg = $res->json('trackResponse.shipment.0.package.0');
        if (! $res->successful() || ! is_array($pkg)) {
            throw new CourierException('UPS: ' . $this->error($res));
        }

        $events = [];
        foreach (array_reverse((array) ($pkg['activity'] ?? [])) as $a) {
            $st = (array) ($a['status'] ?? []);
            $status = $this->map($st['type'] ?? null, $st['code'] ?? null, $st['statusCode'] ?? null);
            $events[] = new TrackingEvent($status, (string) ($st['description'] ?? ''), $this->time($a['date'] ?? null, $a['time'] ?? null, $a['gmtOffset'] ?? null),
                $this->place($a['location']['address'] ?? []));
        }
        $last = collect($events)->last(fn ($e) => $e->status !== null);
        $delivered = collect($pkg['deliveryDate'] ?? [])->firstWhere('type', 'DEL')['date'] ?? null;

        return new TrackingResult(
            status: $last?->status,
            rawStatus: $pkg['currentStatus']['description'] ?? ($events ? end($events)->rawStatus : null),
            events: $events,
            deliveredAt: $last?->status === CourierStatus::DELIVERED ? ($this->time($delivered, $pkg['deliveryTime']['endTime'] ?? null) ?? $last->at) : null,
            returnedAt: $last?->status === CourierStatus::RETURNED ? $last->at : null,
        );
    }

    /** Track Alert callback: {trackingNumber, localActivityDate, localActivityTime, activityLocation, activityStatus{type, code, description, descriptionCode}}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        // Stocky subscribes every parcel with this credential, so UPS always sends it back.
        $credential = (string) $request->header('credential');
        if ($credential === '' || ! hash_equals((string) $this->account->webhook_token, $credential)) {
            throw new CourierException('Invalid UPS Track Alert credential.');
        }

        $tn = $request->input('trackingNumber');
        $st = (array) $request->input('activityStatus', []);
        if (! $tn || ! $st) {
            return null;
        }

        $status = $this->map($st['type'] ?? null, $st['code'] ?? null, $st['descriptionCode'] ?? null);
        $at = $this->time($request->input('localActivityDate'), $request->input('localActivityTime'), $request->input('gmtOffset'));

        return new WebhookUpdate((string) $tn, new TrackingResult(
            status: $status,
            rawStatus: $st['description'] ?? null,
            events: [new TrackingEvent($status, (string) ($st['description'] ?? $st['code'] ?? 'Update'), $at, $this->place((array) $request->input('activityLocation', [])))],
            deliveredAt: $status === CourierStatus::DELIVERED ? ($this->time($request->input('actualDeliveryDate'), $request->input('actualDeliveryTime')) ?? $at) : null,
        ));
    }

    // ------------------------------------------------------------------

    /** Activity type (M/I/D/X/MV/U) + 2-char code + 3-digit status code → Stocky status. */
    public function map(?string $type, ?string $code, ?string $statusCode): ?string
    {
        $type = strtoupper(trim((string) $type));
        $code = strtoupper(trim((string) $code));
        $sc = str_pad(trim((string) $statusCode), 3, '0', STR_PAD_LEFT);

        return match (true) {
            in_array($sc, self::RETURNED, true) => CourierStatus::RETURNED,
            in_array($sc, self::RETURNING, true) => CourierStatus::RETURNING,
            $type === 'MV' || $sc === '007' || ($type === 'M' && $code === 'VP') => CourierStatus::CANCELLED,
            $type === 'M' => CourierStatus::BOOKED,
            $type === 'D' => CourierStatus::DELIVERED,
            in_array($sc, self::OUT_FOR_DELIVERY, true) || in_array($code, ['OF', 'OT', 'OD'], true) => CourierStatus::OUT_FOR_DELIVERY,
            $type === 'X' => in_array($sc, self::FAILED, true) ? CourierStatus::FAILED_ATTEMPT : null,
            $type === 'I' && (in_array($sc, self::PICKED_UP, true) || in_array($code, ['PU', 'PC', 'PT', 'OR', 'XD'], true)) => CourierStatus::PICKED_UP,
            $type === 'I' => CourierStatus::IN_TRANSIT,
            default => null,
        };
    }

    /** Subscribe one parcel to Track Alert (v1 carries the callback URL per request). */
    private function subscribe(string $tn, string $country): string
    {
        try {
            $res = $this->client()->post($this->base() . 'api/track/v1/subscription/standard/package', [
                'locale' => 'en_US',
                'countryCode' => $country ?: 'US',
                'trackingNumberList' => [$tn],
                'destination' => [
                    'url' => url('/api/couriers/webhook/' . $this->account->webhook_token),
                    'credentialType' => 'Bearer',
                    'credential' => (string) $this->account->webhook_token,
                ],
            ]);

            return in_array($tn, (array) $res->json('validTrackingNumbers'), true) ? 'subscribed' : 'not subscribed: ' . $this->error($res);
        } catch (\Throwable $e) {
            return 'not subscribed: ' . $e->getMessage();
        }
    }

    /** [sender, receiver, international, currency, units]. */
    private function lane(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        if (strlen($from['country']) !== 2) {
            throw new CourierException('UPS: set the pickup country (two-letter code) on the courier account.');
        }
        $to = ['street' => $request->address, 'city' => $request->city, 'state' => (string) $request->state,
            'zip' => (string) $request->zip, 'country' => (string) $request->countryCode($from['country'])];
        $imperial = in_array($from['country'], ['US', 'PR'], true);

        return [$from, $to, $to['country'] !== $from['country'], $this->currency(), $imperial ? ['LBS', 'IN'] : ['KGS', 'CM']];
    }

    private function package(ShipmentRequest $request, array $units, string $packagingKey): array
    {
        [$w, $l] = $units;
        $d = Shipper::dimensions($this->account);
        $kgToLb = 2.20462;
        $cmToIn = 0.393701;
        $weight = $w === 'LBS' ? $request->weight * $kgToLb : $request->weight;
        $len = fn ($cm) => (string) max(1, (int) ceil($l === 'IN' ? $cm * $cmToIn : $cm));

        return [
            $packagingKey => ['Code' => '02'],
            'Dimensions' => ['UnitOfMeasurement' => ['Code' => $l], 'Length' => $len($d['length']), 'Width' => $len($d['width']), 'Height' => $len($d['height'])],
            'PackageWeight' => ['UnitOfMeasurement' => ['Code' => $w], 'Weight' => (string) max(0.1, round($weight, 1))],
        ];
    }

    private function address(array $a): array
    {
        return array_filter([
            'AddressLine' => array_slice(mb_str_split(trim((string) ($a['street'] ?? '')) ?: '-', 35), 0, 3),
            'City' => mb_substr((string) $a['city'], 0, 30),
            'StateProvinceCode' => ($a['state'] ?? '') !== '' && mb_strlen($a['state']) <= 5 ? strtoupper($a['state']) : null,
            'PostalCode' => ($a['zip'] ?? '') !== '' ? mb_substr(preg_replace('/\s+/', '', (string) $a['zip']), 0, 9) : null,
            'CountryCode' => $a['country'],
        ]);
    }

    private function party(array $a, string $name, string $attention, ?string $phone, ?string $email, bool $residential = false): array
    {
        $address = $this->address($a);
        if ($residential) {
            $address['ResidentialAddressIndicator'] = '';
        }

        return array_filter([
            'Name' => mb_substr($name ?: $attention, 0, 35),
            'AttentionName' => mb_substr($attention ?: $name, 0, 35),
            'Phone' => ['Number' => substr(preg_replace('/\D+/', '', (string) $phone) ?: '0000000000', 0, 15)],
            'EMailAddress' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'Address' => $address,
        ]);
    }

    private function cheapestService(ShipmentRequest $request): string
    {
        $rates = $this->rates($request);
        usort($rates, fn ($a, $b) => $a->amount <=> $b->amount);
        if (! $rates) {
            throw new CourierException('UPS: no service is available for this address. Set a default service on the courier account.');
        }

        return $rates[0]->id;
    }

    private function value(ShipmentRequest $request): float
    {
        return max(1, round($request->declaredValue ?: $request->codAmount ?: 1, 2));
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private function currency(): string
    {
        $c = strtoupper(trim((string) $this->account->setting('currency')));
        if (preg_match('/^[A-Z]{3}$/', $c)) {
            return $c;
        }
        try {
            $code = strtoupper((string) DB::table('settings')->join('currencies', 'currencies.id', '=', 'settings.currency_id')
                ->whereNull('settings.deleted_at')->value('currencies.code'));

            return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
        } catch (\Throwable $e) {
            return 'USD';
        }
    }

    /** Void responses arrive padded with "&#xD;". */
    private function clean($value): string
    {
        return trim(str_replace('&#xD;', '', (string) $value));
    }

    private function place(array $a): ?string
    {
        $p = trim(implode(', ', array_filter([$a['city'] ?? null, $a['stateProvince'] ?? null, $a['countryCode'] ?? ($a['country'] ?? null)])));

        return $p !== '' ? $p : null;
    }

    /** YYYYMMDD + HHMMSS (+ "-05:00") → Carbon. */
    private function time(?string $date, ?string $time = null, ?string $offset = null): ?Carbon
    {
        $date = preg_replace('/\D+/', '', (string) $date);
        if (strlen($date) !== 8) {
            return null;
        }
        $time = str_pad(substr(preg_replace('/\D+/', '', (string) $time), 0, 6), 6, '0');
        try {
            $s = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2);

            return $offset ? Carbon::parse($s . ' ' . $offset) : Carbon::parse($s);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function token(bool $fresh = false): string
    {
        $key = 'courier_ups_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $this->account->credential('client_id') . '|' . $this->account->credential('client_secret'));
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        $res = $this->http()->asForm()->withBasicAuth($this->account->credential('client_id'), $this->account->credential('client_secret'))
            ->withHeaders(array_filter(['x-merchant-id' => $this->account->credential('account_number') ?: null]))
            ->post($this->base() . 'security/v1/oauth/token', ['grant_type' => 'client_credentials']);
        $token = $res->json('access_token');
        if (! $res->successful() || ! $token) {
            throw new CourierException('UPS: ' . $this->error($res));
        }
        Cache::put($key, $token, now()->addSeconds(max(60, ((int) $res->json('expires_in', 3600)) - 120)));

        return $token;
    }

    private function client()
    {
        return $this->http()->withToken($this->token())->withHeaders([
            'transId' => str_replace('-', '', (string) Str::uuid()),
            'transactionSrc' => 'stocky',
        ]);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::TEST : self::LIVE;
    }

    private function error(Response $res): string
    {
        $errors = $res->json('response.errors') ?? $res->json('errors');
        if (is_array($errors) && $errors) {
            return collect($errors)->map(fn ($e) => is_array($e) ? trim(($e['message'] ?? '') . (isset($e['code']) ? ' (' . $e['code'] . ')' : '')) : (string) $e)->filter()->implode('; ');
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
