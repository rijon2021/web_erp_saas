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
use App\Services\Couriers\Support\Shipper;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DHL Express — MyDHL API 3.3.3 (official OpenAPI spec). HTTP Basic with the
 * API key / secret, header x-version. https://express.api.dhl.com/mydhlapi
 * (test: …/mydhlapi/test — only DHL's preloaded waybills can be tracked
 * there). International parcels are customs-declarable and need an export
 * declaration line. The label is returned once, at booking, so it is kept on
 * the shipment. MyDHL has no shipment void and no push tracking: status is
 * polled, and an unused label is simply never handed over.
 */
class DhlExpressDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://express.api.dhl.com/mydhlapi/';
    private const TEST = 'https://express.api.dhl.com/mydhlapi/test/';
    private const VERSION = '3.3.3';

    private const EVENTS = [
        'SD' => CourierStatus::BOOKED, 'MF' => CourierStatus::BOOKED,
        'PU' => CourierStatus::PICKED_UP, 'SA' => CourierStatus::PICKED_UP,
        'WC' => CourierStatus::OUT_FOR_DELIVERY,
        'NH' => CourierStatus::FAILED_ATTEMPT, 'BA' => CourierStatus::FAILED_ATTEMPT, 'CA' => CourierStatus::FAILED_ATTEMPT,
        'RD' => CourierStatus::FAILED_ATTEMPT, 'MD' => CourierStatus::FAILED_ATTEMPT, 'ND' => CourierStatus::FAILED_ATTEMPT,
        'CM' => CourierStatus::FAILED_ATTEMPT, 'CC' => CourierStatus::FAILED_ATTEMPT,
        'OK' => CourierStatus::DELIVERED, 'PD' => CourierStatus::DELIVERED, 'DD' => CourierStatus::DELIVERED,
        'RT' => CourierStatus::RETURNING,
    ];

    /** Movement / customs / hold codes: the parcel is with DHL and moving. */
    private const TRANSIT = ['AF', 'PL', 'DF', 'AR', 'CI', 'LV', 'UV', 'RW', 'CU', 'HN', 'HI', 'HO', 'TR', 'SM', 'FD', 'TP', 'EM',
        'RR', 'IC', 'CR', 'BR', 'BN', 'ES', 'PW', 'DI', 'TD', 'CD', 'UD', 'OH', 'HP', 'PY', 'MS', 'MC', 'SC', 'AD', 'SS', 'ST'];

    public static function meta(): array
    {
        return [
            'code' => 'dhl_express',
            'name' => 'DHL Express',
            'region' => 'Global',
            'countries' => [],
            'website' => 'https://www.dhl.com/express',
            'tracking_url' => null,
            'description' => 'DHL Express worldwide: live rates, waybills with customs invoice data, labels and tracking.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'tracking' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'text', 'required' => true,
                    'help' => 'From developer.dhl.com (MyDHL API app), issued with your DHL Express account. Test and production keys differ.'],
                ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'password', 'required' => true],
                ['key' => 'account_number', 'label' => 'DHL Express account number', 'type' => 'text', 'required' => true],
            ],
            'settings' => array_merge([
                ['key' => 'product_code', 'label' => 'Default product code', 'type' => 'text',
                    'help' => 'Leave empty: P (Express Worldwide) abroad, N (Domestic Express) at home. "Compare rates" shows what your account offers.'],
                ['key' => 'currency', 'label' => 'Customs value currency', 'type' => 'text',
                    'help' => 'Three letters, e.g. USD, AED, GBP. Empty = your default currency.'],
                ['key' => 'incoterm', 'label' => 'Incoterm', 'type' => 'select', 'options' => [
                    ['value' => 'DAP', 'label' => 'DAP — receiver pays duties'], ['value' => 'DDP', 'label' => 'DDP — you pay duties'],
                ]],
                ['key' => 'label_template', 'label' => 'Label size', 'type' => 'select', 'options' => [
                    ['value' => 'ECOM26_84_001', 'label' => '4 × 8 in thermal'], ['value' => 'ECOM26_84_A4_001', 'label' => 'A4 paper'],
                    ['value' => 'ECOM26_A6_002', 'label' => 'A6'],
                ]],
                ['key' => 'request_pickup', 'label' => 'Book a DHL pickup with each shipment', 'type' => 'switch'],
            ], Shipper::fields()),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $from = Shipper::address($this->account);
        $res = $this->client()->get($this->base() . 'address-validate', array_filter([
            'type' => 'pickup',
            'countryCode' => $from['country'] ?: 'GB',
            'postalCode' => $from['zip'] ?: null,
            'cityName' => $from['city'] ?: null,
        ]));
        if (in_array($res->status(), [401, 403], true)) {
            return ConnectionResult::failed('DHL Express: the API key / secret were not accepted (HTTP ' . $res->status() . ').');
        }
        if ($res->serverError()) {
            return ConnectionResult::failed('DHL Express: ' . $this->error($res));
        }

        // address-validate needs no account: the account number is checked by the first rate / booking.
        return ConnectionResult::ok('Connected to DHL Express. Use "Compare rates" on a sale to confirm the account number.');
    }

    public function rates(ShipmentRequest $request): array
    {
        [$from, $to, $declarable, $currency, $d] = $this->lane($request);

        $res = $this->client()->post($this->base() . 'rates', array_filter([
            'customerDetails' => ['shipperDetails' => $this->rateAddress($from), 'receiverDetails' => $this->rateAddress($to)],
            'accounts' => [['typeCode' => 'shipper', 'number' => $this->account->credential('account_number')]],
            'plannedShippingDateAndTime' => $this->shipTime(),
            'unitOfMeasurement' => 'metric',
            'isCustomsDeclarable' => $declarable,
            'monetaryAmount' => $declarable ? [['typeCode' => 'declaredValue', 'value' => $this->value($request), 'currency' => $currency]] : null,
            'packages' => [['weight' => max(0.1, round($request->weight, 3)), 'dimensions' => $d]],
        ], fn ($v) => $v !== null));
        if (! $res->successful()) {
            throw new CourierException('DHL Express: ' . $this->error($res));
        }

        $out = [];
        foreach ((array) $res->json('products') as $p) {
            $price = collect($p['totalPrice'] ?? [])->firstWhere('currencyType', 'BILLC') ?? ($p['totalPrice'][0] ?? null);
            if (! $price || empty($p['productCode'])) {
                continue;
            }
            $days = $p['deliveryCapabilities']['totalTransitDays'] ?? null;
            $out[] = new Rate(
                id: (string) $p['productCode'],
                carrier: 'DHL Express',
                service: (string) ($p['productName'] ?? $p['productCode']),
                amount: (float) ($price['price'] ?? 0),
                currency: (string) ($price['priceCurrency'] ?? $currency),
                days: is_numeric($days) ? (int) $days : null,
            );
        }

        return $out;
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        [$from, $to, $declarable, $currency, $d] = $this->lane($request);
        $product = $request->service && preg_match('/^[A-Z0-9]{1,6}$/', $request->service)
            ? $request->service
            : (trim((string) $this->account->setting('product_code')) ?: ($declarable || $from['country'] !== $to['country'] ? 'P' : 'N'));
        $value = $this->value($request);
        $description = mb_substr(trim($request->itemsDescription) ?: 'Goods', 0, 70);
        $weight = max(0.1, round($request->weight, 3));

        $content = [
            'packages' => [['weight' => $weight, 'dimensions' => $d,
                'customerReferences' => [['value' => mb_substr($request->reference, 0, 35), 'typeCode' => 'CU']]]],
            'isCustomsDeclarable' => $declarable,
            'description' => $description,
            'incoterm' => $this->account->setting('incoterm') ?: 'DAP',
            'unitOfMeasurement' => 'metric',
        ];
        if ($declarable) {
            $pieces = max(1, $request->pieces);
            $content['declaredValue'] = $value;
            $content['declaredValueCurrency'] = $currency;
            $content['exportDeclaration'] = [
                'lineItems' => [[
                    'number' => 1,
                    'description' => mb_substr($description, 0, 512),
                    'price' => round($value / $pieces, 2),
                    'quantity' => ['value' => $pieces, 'unitOfMeasurement' => 'PCS'],
                    'manufacturerCountry' => $from['country'],
                    'weight' => ['netValue' => $weight, 'grossValue' => $weight],
                ]],
                'invoice' => ['number' => mb_substr($request->reference, 0, 35), 'date' => now()->toDateString()],
                'exportReasonType' => 'permanent',
            ];
        }

        $images = [['typeCode' => 'label', 'templateName' => $this->account->setting('label_template') ?: 'ECOM26_84_001']];
        if ($declarable) {
            $images[] = ['typeCode' => 'invoice', 'isRequested' => true, 'invoiceType' => 'commercial'];
        }

        $res = $this->client()->post($this->base() . 'shipments', [
            'plannedShippingDateAndTime' => $this->shipTime(),
            'pickup' => ['isRequested' => (bool) $this->account->setting('request_pickup')],
            'productCode' => $product,
            'accounts' => [['typeCode' => 'shipper', 'number' => $this->account->credential('account_number')]],
            'customerReferences' => [['value' => mb_substr($request->reference, 0, 35), 'typeCode' => 'CU']],
            'customerDetails' => [
                'shipperDetails' => $this->party($from, $from['phone'], $from['email'], $from['company'] ?: $from['name'], $from['name']),
                'receiverDetails' => $this->party($to, $request->consigneePhone, (string) $request->consigneeEmail, $request->consigneeName, $request->consigneeName),
            ],
            'content' => $content,
            'outputImageProperties' => ['encodingFormat' => 'pdf', 'imageOptions' => $images],
            'getRateEstimates' => true,
        ]);
        $awb = $res->json('shipmentTrackingNumber');
        if (! $res->successful() || ! $awb) {
            throw new CourierException('DHL Express: ' . $this->error($res));
        }

        $docs = collect((array) $res->json('documents'));
        $label = $docs->firstWhere('typeCode', 'label')['content'] ?? null;
        $invoice = $docs->firstWhere('typeCode', 'invoice')['content'] ?? null;
        $charge = collect((array) $res->json('shipmentCharges'))->firstWhere('currencyType', 'BILLC');

        return new BookingResult(
            trackingNumber: (string) $awb,
            charges: isset($charge['price']) ? (float) $charge['price'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Shipment created',
            // The label cannot be fetched again later: keep it.
            meta: array_filter([
                'product_code' => $product,
                'label_pdf' => $label,
                'invoice_pdf' => $invoice,
                'dispatch_confirmation' => $res->json('dispatchConfirmationNumber'),
            ]),
        );
    }

    public function label(Shipment $shipment): array
    {
        $b64 = $shipment->courier_meta['booking']['label_pdf'] ?? null;
        $pdf = $b64 ? base64_decode((string) $b64, true) : false;
        if (! $pdf) {
            throw new CourierException('DHL Express returns the label only when the shipment is created, and none was kept for this one.');
        }

        return ['pdf' => $pdf];
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'shipments/' . rawurlencode((string) $shipment->tracking_number) . '/tracking', [
            'trackingView' => 'all-checkpoints',
            'levelOfDetail' => 'shipment',
            'requestGMTOffsetPerEvent' => 'true',
        ]);
        if ($res->status() === 404) {
            // Right after booking DHL has no scans yet.
            return new TrackingResult(status: null);
        }
        if (! $res->successful()) {
            throw new CourierException('DHL Express: ' . $this->error($res));
        }

        $events = [];
        $seenReturn = false;
        $rows = collect((array) $res->json('shipments.0.events'))
            ->map(fn ($e) => $e + ['_at' => $this->eventTime($e)])
            ->sortBy(fn ($e) => $e['_at']?->timestamp ?? 0)->values();
        foreach ($rows as $e) {
            $code = strtoupper((string) ($e['typeCode'] ?? ''));
            $status = $this->map($code);
            if ($code === 'RT') {
                $seenReturn = true;
            } elseif ($seenReturn && $status === CourierStatus::DELIVERED) {
                $status = CourierStatus::RETURNED; // delivered back to the shipper
            } elseif ($seenReturn && $status !== null) {
                $status = CourierStatus::RETURNING;
            }
            $events[] = new TrackingEvent($status, (string) ($e['description'] ?? $code), $e['_at'],
                $e['serviceArea'][0]['description'] ?? null, $e['signedBy'] ?? null);
        }
        $last = collect($events)->last(fn ($e) => $e->status !== null);

        return new TrackingResult(
            status: $last?->status,
            rawStatus: $events ? end($events)->rawStatus : null,
            events: $events,
            deliveredAt: $last?->status === CourierStatus::DELIVERED ? $last->at : null,
            returnedAt: $last?->status === CourierStatus::RETURNED ? $last->at : null,
        );
    }

    // ------------------------------------------------------------------

    private function map(string $code): ?string
    {
        if (isset(self::EVENTS[$code])) {
            return self::EVENTS[$code];
        }

        return in_array($code, self::TRANSIT, true) ? CourierStatus::IN_TRANSIT : null;
    }

    /** [sender, receiver, customs-declarable, currency, dimensions]. */
    private function lane(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        if (strlen($from['country']) !== 2) {
            throw new CourierException('DHL Express: set the pickup country (two-letter code) on the courier account.');
        }
        $toCountry = $request->countryCode($from['country']);
        $to = [
            'street' => $request->address, 'city' => $request->city, 'state' => (string) $request->state,
            'zip' => (string) $request->zip, 'country' => (string) $toCountry,
        ];
        $declarable = $to['country'] !== $from['country'];
        $d = Shipper::dimensions($this->account);

        return [$from, $to, $declarable, $this->currency(), ['length' => max(1, $d['length']), 'width' => max(1, $d['width']), 'height' => max(1, $d['height'])]];
    }

    private function party(array $addr, ?string $phone, ?string $email, string $company, string $name): array
    {
        $lines = mb_str_split(trim((string) $addr['street']) ?: '-', 45);

        return [
            'postalAddress' => array_filter([
                'postalCode' => mb_substr((string) $addr['zip'], 0, 12),
                'cityName' => mb_substr((string) $addr['city'], 0, 45),
                'countryCode' => $addr['country'],
                'provinceCode' => $addr['state'] !== '' && mb_strlen($addr['state']) <= 35 && mb_strlen($addr['state']) >= 2 ? $addr['state'] : null,
                'addressLine1' => mb_substr($lines[0], 0, 45),
                'addressLine2' => isset($lines[1]) ? mb_substr($lines[1], 0, 45) : null,
                'addressLine3' => isset($lines[2]) ? mb_substr($lines[2], 0, 45) : null,
            ], fn ($v) => $v !== null) + ['postalCode' => mb_substr((string) $addr['zip'], 0, 12)],
            'contactInformation' => array_filter([
                'phone' => mb_substr(trim((string) $phone) ?: '0', 0, 70),
                // Required even for private receivers: DHL's own samples repeat the name.
                'companyName' => mb_substr($company ?: $name, 0, 100),
                'fullName' => mb_substr($name ?: $company, 0, 255),
                'email' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 70) : null,
            ]),
        ];
    }

    private function rateAddress(array $addr): array
    {
        return array_filter([
            'postalCode' => mb_substr((string) $addr['zip'], 0, 12),
            'cityName' => mb_substr((string) $addr['city'], 0, 45),
            'countryCode' => $addr['country'],
        ], fn ($v) => $v !== null) + ['postalCode' => ''];
    }

    private function value(ShipmentRequest $request): float
    {
        return max(1, round($request->declaredValue ?: $request->codAmount ?: 1, 2));
    }

    private function currency(): string
    {
        $c = strtoupper(trim((string) $this->account->setting('currency')));
        if (preg_match('/^[A-Z]{3}$/', $c)) {
            return $c;
        }
        try {
            $code = DB::table('settings')->join('currencies', 'currencies.id', '=', 'settings.currency_id')
                ->whereNull('settings.deleted_at')->value('currencies.code');

            return preg_match('/^[A-Z]{3}$/', strtoupper((string) $code)) ? strtoupper($code) : 'USD';
        } catch (\Throwable $e) {
            return 'USD';
        }
    }

    /** "2026-10-08T10:00:00 GMT+05:00" — an hour ahead, never in the past on arrival. */
    private function shipTime(): string
    {
        $t = now()->addHour()->startOfHour();

        return $t->format('Y-m-d\TH:i:s') . ' GMT' . $t->format('P');
    }

    private function eventTime(array $e): ?Carbon
    {
        try {
            $s = trim(($e['date'] ?? '') . ' ' . ($e['time'] ?? ''));
            if ($s === '') {
                return null;
            }
            $offset = $e['GMTOffset'] ?? null;

            return $offset ? Carbon::parse($s . ' ' . $offset) : Carbon::parse($s);
        } catch (\Throwable $ex) {
            return null;
        }
    }

    private function client()
    {
        return $this->http()->withBasicAuth($this->account->credential('api_key'), $this->account->credential('api_secret'))->withHeaders([
            'x-version' => self::VERSION,
            'Message-Reference' => (string) Str::uuid(),
            'Message-Reference-Date' => gmdate('D, d M Y H:i:s') . ' GMT',
            'Accept' => 'application/json',
        ]);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::TEST : self::LIVE;
    }

    private function error(Response $res): string
    {
        $detail = $res->json('detail') ?: $res->json('message') ?: $res->json('title');
        $extra = collect((array) $res->json('additionalDetails'))->filter(fn ($v) => is_string($v))->take(3)->implode('; ');

        return trim(($detail ?: 'HTTP ' . $res->status()) . ($extra ? " ({$extra})" : ''));
    }
}
