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

/**
 * FedEx REST APIs (developer.fedex.com OpenAPI specs). OAuth client
 * credentials → bearer (cached ~55 min; the token endpoint penalises bursts).
 * Ship + Rate live in one portal project; Track ("Basic Integrated
 * Visibility") must be its own project, so it may have its own key/secret.
 * Weight KG (2 decimals), dimensions whole CM, street lines 35 chars.
 * Labels come back base64 at booking and are kept on the shipment.
 * Webhooks are FedEx's paid Advanced Integrated Visibility (US/CA services):
 * fdx-signature = base64(HMAC-SHA256(raw body, security token)).
 */
class FedExDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://apis.fedex.com/';
    private const SANDBOX = 'https://apis-sandbox.fedex.com/';

    private const EVENTS = [
        'OC' => CourierStatus::BOOKED, 'MD' => CourierStatus::BOOKED, 'LC' => CourierStatus::BOOKED, 'RD' => CourierStatus::BOOKED,
        'RG' => CourierStatus::BOOKED, 'RP' => CourierStatus::BOOKED,
        'PU' => CourierStatus::PICKED_UP, 'DO' => CourierStatus::PICKED_UP, 'IP' => CourierStatus::PICKED_UP,
        'OD' => CourierStatus::OUT_FOR_DELIVERY,
        'DE' => CourierStatus::FAILED_ATTEMPT, 'SE' => CourierStatus::FAILED_ATTEMPT,
        'DL' => CourierStatus::DELIVERED,
        'RS' => CourierStatus::RETURNING, 'RT' => CourierStatus::RETURNING,
        'CA' => CourierStatus::CANCELLED,
    ];

    private const TRANSIT = ['IT', 'AR', 'AF', 'DP', 'TR', 'CC', 'CP', 'EA', 'PM', 'CH', 'AC', 'OX', 'IN', 'AE', 'AO', 'US', 'DY', 'DD',
        'PD', 'CD', 'HA', 'HP', 'RR', 'RM', 'RA', 'PR', 'AS', 'RC', 'DS', 'DR', 'HL'];

    private const DAYS = ['ONE_DAY' => 1, 'TWO_DAYS' => 2, 'THREE_DAYS' => 3, 'FOUR_DAYS' => 4, 'FIVE_DAYS' => 5, 'SIX_DAYS' => 6,
        'SEVEN_DAYS' => 7, 'EIGHT_DAYS' => 8, 'NINE_DAYS' => 9, 'TEN_DAYS' => 10];

    public static function meta(): array
    {
        return [
            'code' => 'fedex',
            'name' => 'FedEx',
            'region' => 'Global',
            'countries' => [],
            'website' => 'https://www.fedex.com',
            'tracking_url' => null,
            'description' => 'FedEx domestic and international: live rates, labels with customs data, COD, cancellation and tracking.',
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
                ['key' => 'client_id', 'label' => 'API key', 'type' => 'text', 'required' => true,
                    'help' => 'developer.fedex.com → My Projects → your Ship/Rate project. Sandbox and production keys differ.'],
                ['key' => 'client_secret', 'label' => 'Secret key', 'type' => 'password', 'required' => true],
                ['key' => 'account_number', 'label' => 'FedEx account number', 'type' => 'text', 'required' => true],
                ['key' => 'track_client_id', 'label' => 'Tracking API key', 'type' => 'text',
                    'help' => 'FedEx puts the Track API in a separate project. Leave empty to use the key above.'],
                ['key' => 'track_client_secret', 'label' => 'Tracking secret key', 'type' => 'password'],
                ['key' => 'webhook_secret', 'label' => 'Webhook security token', 'type' => 'password',
                    'help' => 'Only with FedEx Advanced Integrated Visibility (paid, US/Canada services): the token you set on the webhook.'],
            ],
            'settings' => array_merge([
                ['key' => 'service_type', 'label' => 'Default service', 'type' => 'text',
                    'help' => 'e.g. FEDEX_GROUND, PRIORITY_OVERNIGHT, INTERNATIONAL_PRIORITY. Empty = the cheapest service FedEx quotes.'],
                ['key' => 'pickup_type', 'label' => 'Hand-over', 'type' => 'select', 'options' => [
                    ['value' => 'DROPOFF_AT_FEDEX_LOCATION', 'label' => 'I drop parcels at FedEx'],
                    ['value' => 'USE_SCHEDULED_PICKUP', 'label' => 'Regular scheduled pickup'],
                    ['value' => 'CONTACT_FEDEX_TO_SCHEDULE', 'label' => 'I call FedEx for pickups'],
                ]],
                ['key' => 'label_stock', 'label' => 'Label paper', 'type' => 'select', 'options' => [
                    ['value' => 'PAPER_4X6', 'label' => '4 × 6 in'], ['value' => 'PAPER_85X11_TOP_HALF_LABEL', 'label' => 'Letter, top half'],
                    ['value' => 'PAPER_LETTER', 'label' => 'Letter'],
                ]],
                ['key' => 'duties_payment', 'label' => 'International duties paid by', 'type' => 'select', 'options' => [
                    ['value' => 'SENDER', 'label' => 'Me (sender)'], ['value' => 'RECIPIENT', 'label' => 'Receiver'],
                ]],
                ['key' => 'currency', 'label' => 'Currency for customs and COD', 'type' => 'text',
                    'help' => 'Three letters, e.g. USD, CAD. Empty = your default currency.'],
            ], Shipper::fields()),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $this->token(false, true);
            if ($this->account->credential('track_client_id') !== '') {
                $this->token(true, true);
            }
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }

        return ConnectionResult::ok('Connected to FedEx. Use "Compare rates" on a sale to confirm the account number.');
    }

    public function rates(ShipmentRequest $request): array
    {
        [$from, $to, $intl, $currency] = $this->lane($request);
        $d = $this->dims();

        $res = $this->client()->post($this->base() . 'rate/v1/rates/quotes', [
            'accountNumber' => ['value' => $this->account->credential('account_number')],
            'rateRequestControlParameters' => ['returnTransitTimes' => true],
            'requestedShipment' => array_filter([
                'shipper' => ['address' => $this->rateAddress($from)],
                'recipient' => ['address' => $this->rateAddress($to) + ['residential' => true]],
                'pickupType' => $this->pickupType(),
                'rateRequestType' => ['ACCOUNT', 'LIST'],
                'preferredCurrency' => $currency,
                'shipDateStamp' => now()->toDateString(),
                'packagingType' => 'YOUR_PACKAGING',
                'requestedPackageLineItems' => [['weight' => ['units' => 'KG', 'value' => $this->weight($request)], 'dimensions' => $d]],
                'customsClearanceDetail' => $intl ? $this->customs($request, $from, $currency) : null,
            ]),
        ]);
        if (! $res->successful()) {
            throw new CourierException('FedEx: ' . $this->error($res));
        }

        $out = [];
        foreach ((array) $res->json('output.rateReplyDetails') as $r) {
            $rated = collect($r['ratedShipmentDetails'] ?? [])->firstWhere('rateType', 'ACCOUNT') ?? ($r['ratedShipmentDetails'][0] ?? null);
            if (! $rated || empty($r['serviceType'])) {
                continue;
            }
            $transit = $r['commit']['transitDays']['minimumTransitTime'] ?? $r['operationalDetail']['transitTime'] ?? null;
            $out[] = new Rate(
                id: (string) $r['serviceType'],
                carrier: 'FedEx',
                service: (string) ($r['serviceName'] ?? $r['serviceType']),
                amount: (float) ($rated['totalNetCharge'] ?? 0),
                currency: (string) ($rated['shipmentRateDetail']['currency'] ?? $rated['currency'] ?? $currency),
                days: is_string($transit) ? (self::DAYS[$transit] ?? null) : null,
            );
        }

        return $out;
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        [$from, $to, $intl, $currency] = $this->lane($request);
        $service = $request->service && preg_match('/^[A-Z0-9_]{3,60}$/', $request->service) ? $request->service
            : (trim((string) $this->account->setting('service_type')) ?: $this->cheapestService($request));
        $cod = $request->codAmount > 0;
        $codAmount = ['amount' => round($request->codAmount, 2), 'currency' => $currency];

        $package = array_filter([
            'sequenceNumber' => 1,
            'weight' => ['units' => 'KG', 'value' => $this->weight($request)],
            'dimensions' => $this->dims(),
            'itemDescription' => mb_substr($request->itemsDescription ?: 'Goods', 0, 50),
            'customerReferences' => [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => mb_substr($request->reference, 0, 30)]],
            // Ground COD also needs the package-level amount.
            'packageSpecialServices' => $cod && str_contains($service, 'GROUND')
                ? ['specialServiceTypes' => ['COD'], 'packageCODDetail' => ['codCollectionAmount' => $codAmount]] : null,
        ]);

        $res = $this->client()->post($this->base() . 'ship/v1/shipments', [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => ['value' => $this->account->credential('account_number')],
            'requestedShipment' => array_filter([
                'shipDatestamp' => now()->toDateString(),
                'shipper' => $this->party($from, $from['phone'], $from['email'], $from['name'], $from['company']),
                'recipients' => [$this->party($to, $request->consigneePhone, (string) $request->consigneeEmail, $request->consigneeName, null, true, $request->notes)],
                'serviceType' => $service,
                'packagingType' => 'YOUR_PACKAGING',
                'pickupType' => $this->pickupType(),
                'shippingChargesPayment' => ['paymentType' => 'SENDER'],
                'labelSpecification' => ['imageType' => 'PDF', 'labelStockType' => $this->account->setting('label_stock') ?: 'PAPER_4X6'],
                'shipmentSpecialServices' => $cod ? ['specialServiceTypes' => ['COD'],
                    'shipmentCODDetail' => ['codCollectionAmount' => $codAmount, 'codCollectionType' => 'CASH']] : null,
                'customsClearanceDetail' => $intl ? $this->customs($request, $from, $currency) : null,
                'requestedPackageLineItems' => [$package],
            ]),
        ]);
        $ship = $res->json('output.transactionShipments.0');
        $tn = $ship['masterTrackingNumber'] ?? ($ship['pieceResponses'][0]['trackingNumber'] ?? null);
        if (! $res->successful() || ! $tn) {
            throw new CourierException('FedEx: ' . $this->error($res));
        }

        $piece = $ship['pieceResponses'][0] ?? [];
        $label = collect($piece['packageDocuments'] ?? [])->first(fn ($d) => ($d['contentType'] ?? '') === 'LABEL' && ! empty($d['encodedLabel']));

        return new BookingResult(
            trackingNumber: (string) $tn,
            labelUrl: $label ? null : (collect($piece['packageDocuments'] ?? [])->firstWhere('contentType', 'LABEL')['url'] ?? null),
            charges: isset($piece['netChargeAmount']) ? (float) $piece['netChargeAmount'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Label created',
            meta: array_filter(['service_type' => $service, 'label_pdf' => $label['encodedLabel'] ?? null]),
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->put($this->base() . 'ship/v1/shipments/cancel', array_filter([
            'accountNumber' => ['value' => $this->account->credential('account_number')],
            'trackingNumber' => (string) $shipment->tracking_number,
            'deletionControl' => 'DELETE_ALL_PACKAGES',
            'senderCountryCode' => Shipper::address($this->account)['country'] ?: null,
        ]));
        if (! $res->successful() || $res->json('output.cancelledShipment') === false) {
            throw new CourierException('FedEx: ' . ($res->json('output.message') ?: $this->error($res)));
        }
    }

    public function label(Shipment $shipment): array
    {
        $b64 = $shipment->courier_meta['booking']['label_pdf'] ?? null;
        $pdf = $b64 ? base64_decode((string) $b64, true) : false;
        if ($pdf) {
            return ['pdf' => $pdf];
        }
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }

        throw new CourierException('FedEx returns the label only when the shipment is created, and none was kept for this one.');
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client(true)->post($this->base() . 'track/v1/trackingnumbers', [
            'includeDetailedScans' => true,
            'trackingInfo' => [['trackingNumberInfo' => ['trackingNumber' => (string) $shipment->tracking_number]]],
        ]);
        $r = $res->json('output.completeTrackResults.0.trackResults.0');
        if (! $res->successful() || ! is_array($r)) {
            throw new CourierException('FedEx: ' . $this->error($res));
        }
        if (! empty($r['error']['code'])) {
            if (str_contains((string) $r['error']['code'], 'NOTFOUND')) {
                return new TrackingResult(status: null); // no scans yet right after booking
            }
            throw new CourierException('FedEx: ' . ($r['error']['message'] ?? $r['error']['code']));
        }

        $events = $this->events((array) ($r['scanEvents'] ?? []), fn ($e) => [
            $e['eventType'] ?? $e['derivedStatusCode'] ?? '', $e['eventDescription'] ?? null, $e['date'] ?? null,
            $this->place($e['scanLocation'] ?? []), $e['exceptionDescription'] ?? null,
        ]);
        $latest = strtoupper((string) ($r['latestStatusDetail']['code'] ?? $r['latestStatusDetail']['derivedCode'] ?? ''));
        $status = $this->resolve($latest, $events);
        $delivered = collect($r['dateAndTimes'] ?? [])->firstWhere('type', 'ACTUAL_DELIVERY')['dateTime'] ?? null;

        return new TrackingResult(
            status: $status,
            rawStatus: $r['latestStatusDetail']['description'] ?? ($latest ?: null),
            events: $events,
            deliveredAt: in_array($status, [CourierStatus::DELIVERED, CourierStatus::RETURNED], true) && $delivered ? $this->time($delivered) : null,
        );
    }

    /** AIV payload: {scanEvent{eventCode, eventDescription, eventCreateTime, previousEvents[]}, trackResult{trackingInfo{currentTrackingInfo{trackingNumber}, returnShipment}}}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('webhook_secret');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('fdx-signature'))) {
            throw new CourierException($secret === '' ? 'Set the FedEx webhook security token on the courier account.' : 'Invalid FedEx webhook signature.');
        }

        $tn = $request->input('trackResult.trackingInfo.currentTrackingInfo.trackingNumber');
        $scan = (array) $request->input('scanEvent', []);
        if (! $tn || ! $scan) {
            return null;
        }

        $all = array_merge((array) ($scan['previousEvents'] ?? []), [$scan]);
        $events = $this->events($all, fn ($e) => [
            $e['eventCode'] ?? '', $e['eventDescription'] ?? null, $e['eventCreateTime'] ?? null,
            $this->place($e['location']['locationContactAndAddress']['address'] ?? []), $e['eventReasonDescription'] ?? null,
        ]);
        $status = $this->resolve(strtoupper((string) ($scan['eventCode'] ?? '')), $events);
        if ($request->boolean('trackResult.trackingInfo.returnShipment') && $status === CourierStatus::DELIVERED) {
            $status = CourierStatus::RETURNED;
        }
        $last = end($events) ?: null;

        return new WebhookUpdate((string) $tn, new TrackingResult(
            status: $status,
            rawStatus: $scan['eventDescription'] ?? ($scan['eventCode'] ?? null),
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $last?->at : null,
        ));
    }

    // ------------------------------------------------------------------

    /**
     * @param  callable  $pick  event → [code, description, time, location, detail]
     * @return TrackingEvent[] oldest first; anything after a return-to-shipper is the return leg
     */
    private function events(array $raw, callable $pick): array
    {
        $rows = collect($raw)->map(function ($e) use ($pick) {
            [$code, $desc, $time, $loc, $detail] = $pick((array) $e);

            return ['code' => strtoupper((string) $code), 'desc' => $desc, 'at' => $this->time($time), 'loc' => $loc, 'detail' => $detail];
        })->sortBy(fn ($e) => $e['at']?->timestamp ?? 0)->values();

        $events = [];
        $returning = false;
        foreach ($rows as $e) {
            $status = $this->map($e['code']);
            if (in_array($e['code'], ['RS', 'RT'], true)) {
                $returning = true;
            } elseif ($returning && $status === CourierStatus::DELIVERED) {
                $status = CourierStatus::RETURNED;
            } elseif ($returning && $status !== null && $status !== CourierStatus::CANCELLED) {
                $status = CourierStatus::RETURNING;
            }
            $events[] = new TrackingEvent($status, (string) ($e['desc'] ?: $e['code']), $e['at'], $e['loc'], $e['detail'] ?: null);
        }

        return $events;
    }

    /** Latest code, unless the history shows the parcel is on its way back. */
    private function resolve(string $latest, array $events): ?string
    {
        $status = $this->map($latest);
        $last = collect($events)->last(fn ($e) => $e->status !== null);
        if ($last && in_array($last->status, [CourierStatus::RETURNING, CourierStatus::RETURNED], true)) {
            return $last->status;
        }

        return $status ?? $last?->status;
    }

    private function map(string $code): ?string
    {
        if (isset(self::EVENTS[$code])) {
            return self::EVENTS[$code];
        }

        return in_array($code, self::TRANSIT, true) ? CourierStatus::IN_TRANSIT : null;
    }

    /** [sender, receiver, international, currency]. */
    private function lane(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        if (strlen($from['country']) !== 2) {
            throw new CourierException('FedEx: set the pickup country (two-letter code) on the courier account.');
        }
        $to = ['street' => $request->address, 'city' => $request->city, 'state' => (string) $request->state,
            'zip' => (string) $request->zip, 'country' => (string) $request->countryCode($from['country'])];

        return [$from, $to, $to['country'] !== $from['country'], $this->currency()];
    }

    private function party(array $a, ?string $phone, ?string $email, ?string $name, ?string $company, bool $residential = false, ?string $instructions = null): array
    {
        $lines = array_slice(mb_str_split(trim((string) $a['street']) ?: '-', 35), 0, 3);

        return array_filter([
            'contact' => array_filter([
                'personName' => $name ? mb_substr($name, 0, 70) : null,
                'companyName' => $company ? mb_substr($company, 0, 35) : null,
                'phoneNumber' => $this->phone($phone, $a['country']),
                'emailAddress' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 80) : null,
            ]),
            'address' => array_filter([
                'streetLines' => $lines,
                'city' => mb_substr((string) $a['city'], 0, 35),
                'stateOrProvinceCode' => strlen((string) $a['state']) === 2 ? strtoupper($a['state']) : null,
                'postalCode' => $a['zip'] !== '' ? mb_substr((string) $a['zip'], 0, 10) : null,
                'countryCode' => $a['country'],
                'residential' => $residential ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
            'deliveryInstructions' => $instructions ? mb_substr($instructions, 0, 100) : null,
        ]);
    }

    private function rateAddress(array $a): array
    {
        return array_filter([
            'postalCode' => $a['zip'] !== '' ? $a['zip'] : null,
            'countryCode' => $a['country'],
            'city' => $a['city'] ?: null,
            'stateOrProvinceCode' => strlen((string) $a['state']) === 2 ? strtoupper($a['state']) : null,
        ]);
    }

    private function customs(ShipmentRequest $request, array $from, string $currency): array
    {
        $value = max(1, round($request->declaredValue ?: $request->codAmount ?: 1, 2));
        $pieces = max(1, $request->pieces);

        return [
            'dutiesPayment' => ['paymentType' => $this->account->setting('duties_payment') === 'RECIPIENT' ? 'RECIPIENT' : 'SENDER'],
            'isDocumentOnly' => false,
            'totalCustomsValue' => ['amount' => $value, 'currency' => $currency],
            'commercialInvoice' => ['shipmentPurpose' => 'SOLD'],
            'commodities' => [[
                'description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 450),
                'countryOfManufacture' => $from['country'],
                'quantity' => $pieces,
                'quantityUnits' => 'PCS',
                'numberOfPieces' => 1,
                'unitPrice' => ['amount' => round($value / $pieces, 2), 'currency' => $currency],
                'customsValue' => ['amount' => $value, 'currency' => $currency],
                'weight' => ['units' => 'KG', 'value' => $this->weight($request)],
            ]],
        ];
    }

    private function cheapestService(ShipmentRequest $request): string
    {
        $rates = $this->rates($request);
        usort($rates, fn ($a, $b) => $a->amount <=> $b->amount);
        if (! $rates) {
            throw new CourierException('FedEx: no service is available for this address. Set a default service on the courier account.');
        }

        return $rates[0]->id;
    }

    /** US/CA: 10 digits; elsewhere up to 15 digits. */
    private function phone(?string $phone, string $country): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (in_array($country, ['US', 'CA'], true) && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return substr($digits !== '' ? $digits : '0000000000', 0, 15);
    }

    private function weight(ShipmentRequest $request): float
    {
        return max(0.1, round($request->weight, 2));
    }

    private function dims(): array
    {
        $d = Shipper::dimensions($this->account);

        return ['length' => (int) max(1, ceil($d['length'])), 'width' => (int) max(1, ceil($d['width'])), 'height' => (int) max(1, ceil($d['height'])), 'units' => 'CM'];
    }

    private function pickupType(): string
    {
        return $this->account->setting('pickup_type') ?: 'DROPOFF_AT_FEDEX_LOCATION';
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

    private function place(array $a): ?string
    {
        $p = trim(implode(', ', array_filter([$a['city'] ?? null, $a['stateOrProvinceCode'] ?? null, $a['countryCode'] ?? null])));

        return $p !== '' ? $p : null;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Bearer for the Ship/Rate project, or the Track project's own key when set. */
    private function token(bool $track = false, bool $fresh = false): string
    {
        $id = $track && $this->account->credential('track_client_id') !== '' ? $this->account->credential('track_client_id') : $this->account->credential('client_id');
        $secret = $track && $this->account->credential('track_client_id') !== '' ? $this->account->credential('track_client_secret') : $this->account->credential('client_secret');
        $key = 'courier_fedex_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $id . '|' . $secret);
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        $res = $this->http()->asForm()->post($this->base() . 'oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $id,
            'client_secret' => $secret,
        ]);
        $token = $res->json('access_token');
        if (! $res->successful() || ! $token) {
            throw new CourierException('FedEx' . ($track ? ' tracking' : '') . ': ' . $this->error($res));
        }
        Cache::put($key, $token, now()->addSeconds(max(60, ((int) $res->json('expires_in', 3600)) - 300)));

        return $token;
    }

    private function client(bool $track = false)
    {
        return $this->http()->withToken($this->token($track))->withHeaders(['X-locale' => 'en_US']);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE;
    }

    private function error(Response $res): string
    {
        $errors = $res->json('errors');
        if (is_array($errors) && isset($errors['code'])) {
            $errors = [$errors];
        }
        if (is_array($errors) && $errors) {
            return collect($errors)->map(fn ($e) => trim(($e['message'] ?? '') . (isset($e['code']) ? ' (' . $e['code'] . ')' : '')))->filter()->implode('; ');
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
