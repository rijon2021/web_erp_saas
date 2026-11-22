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
use App\Services\Couriers\Support\Shipper;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;

/**
 * Aramex — Shipping Services API (ShippingAPI.V2, JSON flavour of the WCF
 * service), from the live WSDL/XSD, the official manuals and Aramex's
 * WooCommerce plugin. https://ws.aramex.net/ShippingAPI.V2/{Service}/Service_1_0.svc/json/{Op}
 * (test: ws.dev.aramex.net), `Accept: application/json` required. ClientInfo
 * in every body. The WCF deserializer is strict: every required key must be
 * present (empty values are fine). Logical errors arrive as HTTP 200 with
 * HasErrors + Notifications. COD = Services "CODS" + CashOnDeliveryAmount.
 * No shipment-cancel operation and no webhooks; status is polled. Aramex
 * publishes no UpdateCode list, so codes are mapped with a wording fallback.
 */
class AramexDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://ws.aramex.net/ShippingAPI.V2/';
    private const TEST = 'https://ws.dev.aramex.net/ShippingAPI.V2/';

    private const CURRENCIES = ['SA' => 'SAR', 'AE' => 'AED', 'JO' => 'JOD', 'EG' => 'EGP', 'KW' => 'KWD', 'BH' => 'BHD', 'QA' => 'QAR', 'OM' => 'OMR', 'LB' => 'USD'];

    /** UpdateCodes seen in integrations (Aramex does not publish the list). */
    private const CODES = [
        'SH014' => CourierStatus::BOOKED, 'SH203' => CourierStatus::BOOKED,
        'SH012' => CourierStatus::PICKED_UP, 'SH047' => CourierStatus::PICKED_UP, 'SH406' => CourierStatus::PICKED_UP, 'SH434' => CourierStatus::PICKED_UP,
        'SH022' => CourierStatus::IN_TRANSIT, 'SH001' => CourierStatus::IN_TRANSIT, 'SH160' => CourierStatus::IN_TRANSIT,
        'SH003' => CourierStatus::OUT_FOR_DELIVERY, 'SH004' => CourierStatus::OUT_FOR_DELIVERY,
        'SH005' => CourierStatus::DELIVERED, 'SH006' => CourierStatus::DELIVERED, 'SH154' => CourierStatus::DELIVERED,
        'SH474' => CourierStatus::RETURNING, 'SH498' => CourierStatus::RETURNING,
        'SH069' => CourierStatus::RETURNED, 'SH407' => CourierStatus::RETURNED, 'SH559' => CourierStatus::RETURNED,
    ];

    /** Notification-only codes: shown on the timeline, never change the status. */
    private const NOTIFY_ONLY = ['SH249', 'SH270', 'SH271', 'SH369'];

    public static function meta(): array
    {
        return [
            'code' => 'aramex',
            'name' => 'Aramex',
            'region' => 'Gulf / MENA / Global',
            'countries' => ['SA', 'AE', 'JO', 'EG', 'KW', 'BH', 'QA', 'OM'],
            'website' => 'https://www.aramex.com',
            'tracking_url' => null,
            'description' => 'Aramex domestic and international express: AWB with COD, labels and tracking.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'tracking' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
                ['key' => 'account_number', 'label' => 'Account number', 'type' => 'text', 'required' => true],
                ['key' => 'account_pin', 'label' => 'Account PIN', 'type' => 'password', 'required' => true],
                ['key' => 'account_entity', 'label' => 'Account entity', 'type' => 'text', 'required' => true,
                    'help' => 'Aramex office code, e.g. RUH, DXB, AMM.'],
                ['key' => 'account_country_code', 'label' => 'Account country', 'type' => 'text', 'required' => true,
                    'help' => 'Two letters, e.g. SA, AE, JO.'],
            ],
            'settings' => array_merge(Shipper::fields(), [
                ['key' => 'product_type', 'label' => 'Product type', 'type' => 'text',
                    'help' => 'e.g. ONP (overnight parcel), CDS (e-commerce), EPX/PPX (international). Leave empty for ONP domestic / PPX international.'],
                ['key' => 'source', 'label' => 'Source ID', 'type' => 'number', 'help' => 'Only if Aramex gave you one.'],
                ['key' => 'label_report_id', 'label' => 'Label report ID', 'type' => 'number', 'help' => 'Leave empty for 9729.'],
            ]),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->call('Location', 'FetchCountries', []);
        if (! $res->successful() || $res->json('HasErrors')) {
            return ConnectionResult::failed('Aramex: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to Aramex.');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $from = Shipper::address($this->account);
        $origin = $from['country'] ?: strtoupper($this->account->credential('account_country_code'));
        $dest = $request->countryCode($origin);
        $domestic = $dest === $origin;
        $cod = $request->codAmount > 0;
        $currency = self::CURRENCIES[$origin] ?? 'USD';
        $d = Shipper::dimensions($this->account);

        $shipment = [
            'Reference1' => mb_substr($request->reference, 0, 50),
            'Reference2' => '',
            'Reference3' => '',
            'Shipper' => $this->party(
                $from['name'], $from['company'] ?: $from['name'], $from['phone'], $from['email'],
                $from['street'], $from['city'], $from['state'], $from['zip'], $origin,
                $this->account->credential('account_number'),
            ),
            'Consignee' => $this->party(
                $request->consigneeName, $request->consigneeName, $request->consigneePhone, (string) $request->consigneeEmail,
                $request->address, $request->courierCityName ?: $request->city, (string) $request->state, (string) $request->zip, $dest,
            ),
            'ShippingDateTime' => '/Date(' . (now()->getTimestamp() * 1000) . ')/',
            'Comments' => mb_substr($request->notes, 0, 100),
            'Details' => [
                'Dimensions' => ['Length' => $d['length'], 'Width' => $d['width'], 'Height' => $d['height'], 'Unit' => 'CM'],
                'ActualWeight' => ['Unit' => 'KG', 'Value' => max(0.1, round($request->weight, 3))],
                'ChargeableWeight' => null,
                'DescriptionOfGoods' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100),
                'GoodsOriginCountry' => $origin,
                'NumberOfPieces' => min(100, $request->pieces),
                'ProductGroup' => $domestic ? 'DOM' : 'EXP',
                'ProductType' => $request->service ?: ($this->account->setting('product_type') ?: ($domestic ? 'ONP' : 'PPX')),
                'PaymentType' => 'P',
                'PaymentOptions' => '',
                'Services' => $cod ? 'CODS' : '',
                'CashOnDeliveryAmount' => $cod ? ['CurrencyCode' => $currency, 'Value' => round($request->codAmount, 2)] : null,
                'CustomsValueAmount' => $domestic ? null : ['CurrencyCode' => $currency, 'Value' => round(max(1, $request->declaredValue), 2)],
            ],
        ];

        $res = $this->call('Shipping', 'CreateShipments', [
            'Shipments' => [$shipment],
            'LabelInfo' => ['ReportID' => (int) ($this->account->setting('label_report_id') ?: 9729), 'ReportType' => 'URL'],
        ]);
        $s = $res->json('Shipments.0') ?? [];
        if (! $res->successful() || $res->json('HasErrors') || ! empty($s['HasErrors']) || empty($s['ID'])) {
            $msg = collect($s['Notifications'] ?? [])->pluck('Message')->filter()->implode('; ');
            throw new CourierException('Aramex: ' . ($msg ?: $this->error($res)));
        }

        return new BookingResult(
            trackingNumber: (string) $s['ID'],
            labelUrl: $s['ShipmentLabel']['LabelURL'] ?? null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Record created',
            meta: ['product_group' => $shipment['Details']['ProductGroup']],
        );
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->call('Tracking', 'TrackShipments', [
            'Shipments' => [(string) $shipment->tracking_number],
            'GetLastTrackingUpdateOnly' => false,
        ]);
        if (! $res->successful() || $res->json('HasErrors')) {
            throw new CourierException('Aramex: ' . $this->error($res));
        }

        $results = $res->json('TrackingResults') ?? [];
        $rows = [];
        foreach ($results as $k => $entry) {
            // WCF dictionaries serialise as [{Key, Value:[…]}].
            if (is_array($entry) && array_key_exists('Value', $entry)) {
                if ((string) ($entry['Key'] ?? '') === (string) $shipment->tracking_number || count($results) === 1) {
                    $rows = (array) $entry['Value'];
                }
            } elseif ((string) $k === (string) $shipment->tracking_number) {
                $rows = (array) $entry;
            }
        }

        $events = [];
        $latest = null;
        foreach ($rows as $r) {
            $code = strtoupper((string) ($r['UpdateCode'] ?? ''));
            $text = $r['UpdateDescription'] ?? null;
            $status = in_array($code, self::NOTIFY_ONLY, true) ? null : (self::CODES[$code] ?? StatusText::map($text));
            $events[] = new TrackingEvent(
                status: $status,
                rawStatus: $text ?: $code,
                at: $this->wcfDate($r['UpdateDateTime'] ?? null),
                location: $r['UpdateLocation'] ?? null,
                description: $r['Comments'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        foreach (array_reverse($events) as $e) {
            if ($e->status) {
                $latest = $e;
                break;
            }
        }

        return new TrackingResult(
            status: $latest?->status,
            rawStatus: $events ? $events[count($events) - 1]->rawStatus : null,
            events: $events,
            deliveredAt: $latest?->status === CourierStatus::DELIVERED ? $latest->at : null,
        );
    }

    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }

        $res = $this->call('Shipping', 'PrintLabel', [
            'ShipmentNumber' => (string) $shipment->tracking_number,
            'ProductGroup' => $shipment->courier_meta['booking']['product_group'] ?? 'DOM',
            'OriginEntity' => $this->account->credential('account_entity'),
            'LabelInfo' => ['ReportID' => (int) ($this->account->setting('label_report_id') ?: 9729), 'ReportType' => 'URL'],
        ]);
        $url = $res->json('ShipmentLabel.LabelURL');
        if (! $res->successful() || $res->json('HasErrors') || ! $url) {
            throw new CourierException('Aramex: ' . $this->error($res));
        }

        return ['url' => $url];
    }

    // ------------------------------------------------------------------

    /** Party with every key the WCF contract requires. */
    private function party(string $name, string $company, string $phone, string $email, string $line1, string $city, string $state, string $zip, string $country, string $account = ''): array
    {
        return [
            'Reference1' => '',
            'Reference2' => '',
            'AccountNumber' => $account,
            'PartyAddress' => [
                'Line1' => mb_substr($line1, 0, 50),
                'Line2' => mb_strlen($line1) > 50 ? mb_substr($line1, 50, 50) : '',
                'Line3' => '',
                'City' => $city,
                'StateOrProvinceCode' => $state,
                'PostCode' => $zip,
                'CountryCode' => $country,
            ],
            'Contact' => [
                'PersonName' => mb_substr($name, 0, 50),
                'CompanyName' => mb_substr($company ?: $name, 0, 50),
                'PhoneNumber1' => mb_substr($phone, 0, 30),
                'PhoneNumber2' => '',
                'CellPhone' => mb_substr($phone, 0, 30),
                'EmailAddress' => $email,
                'Type' => '',
            ],
        ];
    }

    private function call(string $service, string $operation, array $body): Response
    {
        $acc = $this->account;
        $client = array_filter([
            'UserName' => $acc->credential('username'),
            'Password' => $acc->credential('password'),
            'Version' => 'v1.0',
            'AccountNumber' => $acc->credential('account_number'),
            'AccountPin' => $acc->credential('account_pin'),
            'AccountEntity' => strtoupper($acc->credential('account_entity')),
            'AccountCountryCode' => strtoupper($acc->credential('account_country_code')),
            'Source' => is_numeric($acc->setting('source')) ? (int) $acc->setting('source') : null,
        ], fn ($v) => $v !== null);

        $base = $acc->isTestMode() ? self::TEST : self::LIVE;

        return $this->http()->withHeaders(['Accept' => 'application/json'])
            ->post($base . $service . '/Service_1_0.svc/json/' . $operation, ['ClientInfo' => $client] + $body);
    }

    /** "/Date(1791334800000+0300)/" → Carbon. */
    private function wcfDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        if (preg_match('#/Date\((-?\d+)([+-]\d{4})?\)/#', $value, $m)) {
            return Carbon::createFromTimestampMs((int) $m[1]);
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $msg = collect($res->json('Notifications') ?? [])->map(fn ($n) => trim(($n['Code'] ?? '') . ' ' . ($n['Message'] ?? '')))->filter()->implode('; ');
        if ($msg !== '') {
            return $msg;
        }
        if ($res->status() === 400) {
            return 'request rejected by Aramex (HTTP 400).';
        }

        return 'HTTP ' . $res->status();
    }
}
