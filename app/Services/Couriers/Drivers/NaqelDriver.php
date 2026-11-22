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
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\Response;

/**
 * Naqel Express (Saudi Arabia & UAE) — SOAP "XMLShippingService" v9.0, from
 * the live WSDL and the official API PDF. Raw SOAP 1.1 over HTTP; elements
 * are written in the WSDL sequence order (the .NET serializer drops
 * out-of-order elements). Every call carries ClientInformation (ClientID,
 * Password, Version 9.0, pickup address + contact). Billing type 5 = COD.
 * Labels are base64 PDFs (GetWaybillSticker). No webhooks; status is polled.
 * Naqel publishes no city-list call, so city codes come from the bundled
 * list (Resources/naqel_cities.json, from the MIT-licensed naqel/naqel-php SDK).
 */
class NaqelDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://infotrack.naqelexpress.com/NaqelAPIServices/NaqelAPI/9.0/XMLShippingService.asmx';
    private const DEMO = 'https://infotrack.naqelexpress.com/NaqelAPIServices/NaqelAPIDemo/9.0/XMLShippingService.asmx';

    /** Event codes confirmed in Naqel's EDI documentation. */
    private const EVENTS = [7 => CourierStatus::DELIVERED, 172 => CourierStatus::DELIVERED, 4 => CourierStatus::IN_TRANSIT, 163 => CourierStatus::OUT_FOR_DELIVERY];

    public static function meta(): array
    {
        return [
            'code' => 'naqel',
            'name' => 'Naqel Express',
            'region' => 'Saudi Arabia / UAE',
            'countries' => ['SA', 'AE'],
            'website' => 'https://www.naqelexpress.com',
            'tracking_url' => null,
            'description' => 'Naqel Express waybills with COD, 4×6 stickers, tracking and cancellation before pickup.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => '7-digit Naqel client number.'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'origin_city_code', 'label' => 'Pickup city code', 'type' => 'text', 'required' => true,
                    'help' => 'Naqel city code, e.g. RUH, JED, DXB.'],
                ['key' => 'origin_country', 'label' => 'Pickup country', 'type' => 'select', 'options' => [
                    ['value' => 'KSA', 'label' => 'Saudi Arabia'], ['value' => 'AE', 'label' => 'UAE'],
                ], 'help' => 'Leave empty for Saudi Arabia.'],
                ['key' => 'load_type_id', 'label' => 'Load type ID', 'type' => 'number',
                    'help' => 'From your Naqel contract; "Test connection" lists them. Leave empty for 36 (non-document).'],
                ['key' => 'sticker_size', 'label' => 'Sticker size', 'type' => 'select', 'options' => [
                    ['value' => 'FourMSixthInches', 'label' => '4×6 in'], ['value' => 'FourMEightInches', 'label' => '4×8 in'],
                    ['value' => 'FourMFourInches', 'label' => '4×4 in'], ['value' => 'A4', 'label' => 'A4'],
                ], 'help' => 'Leave empty for 4×6.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        // A trace answers bad credentials with "Invalid credentials."; an
        // unknown waybill with a different message.
        $trace = $this->soap('TraceByWaybillNo', '<ClientInfo>' . $this->clientInfo() . '</ClientInfo>' . $this->el('WaybillNo', '0'));
        $error = $this->firstText($trace, 'ErrorMessage');
        if (stripos($error, 'credential') !== false) {
            return ConnectionResult::failed('Naqel: ' . $error);
        }

        $doc = $this->soap('GetLoadTypeList', '<ClientInfo>' . $this->clientInfo() . '</ClientInfo>');
        $types = [];
        foreach ($doc->getElementsByTagName('ViwLoadTypeByClient') as $lt) {
            $id = $this->childText($lt, 'ID');
            $name = $this->childText($lt, 'Name');
            if ($id !== '') {
                $types[] = $id . ($name !== '' ? " ({$name})" : '');
            }
        }

        return ConnectionResult::ok('Connected to Naqel.' . ($types ? ' Load types: ' . implode(', ', $types) . '.' : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $city = $this->cityFor($request);
        $cod = $request->codAmount > 0;
        $from = now()->format('Y-m-d\TH:i:s');
        $to = now()->addDays(7)->format('Y-m-d\TH:i:s');

        // ConsigneeInformation, in WSDL order.
        $consignee = $this->el('ConsigneeNationalID', '0')
            . $this->el('ConsigneeName', mb_substr($request->consigneeName, 0, 200))
            . $this->el('Email', (string) $request->consigneeEmail)
            . $this->el('Mobile', $request->consigneePhone)
            . $this->el('PhoneNumber', $request->consigneePhone)
            . $this->el('District', (string) $request->state)
            . $this->el('PostalCode', (string) $request->zip)
            . $this->el('CityName', $city['name'])
            . $this->el('Address', mb_substr($request->address, 0, 250))
            . $this->el('CountryCode', $city['country'])
            . $this->el('CityCode', $city['code']);

        // ManifestShipmentDetails, in WSDL order.
        $details = '<ClientInfo>' . $this->clientInfo() . '</ClientInfo>'
            . '<ConsigneeInfo>' . $consignee . '</ConsigneeInfo>'
            . $this->el('BillingType', $cod ? '5' : '1')
            . $this->el('PicesCount', (string) min(99, $request->pieces))
            . $this->el('Weight', (string) max(0.1, round($request->weight, 2)))
            . $this->el('DeliveryInstruction', mb_substr($request->notes, 0, 200))
            . $this->el('CODCharge', $cod ? (string) round($request->codAmount, 2) : '0')
            . $this->el('CreateBooking', 'false')
            . $this->el('isRTO', 'false')
            . $this->el('GeneratePiecesBarCodes', 'false')
            . $this->el('PromisedDeliveryDateFrom', $from)
            . $this->el('PromisedDeliveryDateTo', $to)
            . $this->el('LoadTypeID', (string) ((int) ($this->account->setting('load_type_id') ?: 36)))
            . $this->el('DeclareValue', '0')
            . $this->el('GoodDesc', mb_substr($request->itemsDescription ?: 'Goods', 0, 250))
            . $this->el('RefNo', mb_substr($request->reference, 0, 100))
            . $this->el('InsuredValue', '0')
            . $this->el('GoodsVATAmount', '0')
            . $this->el('IsCustomDutyPayByConsignee', 'false');

        $doc = $this->soap('CreateWaybill', '<_ManifestShipmentDetails>' . $details . '</_ManifestShipmentDetails>');
        $result = $doc->getElementsByTagName('CreateWaybillResult')->item(0);
        $waybill = $result ? $this->childText($result, 'WaybillNo') : '';
        if (! $result || $this->childText($result, 'HasError') === 'true' || $waybill === '' || $waybill === '0') {
            throw new CourierException('Naqel: ' . ($result ? ($this->childText($result, 'Message') ?: 'waybill not created') : 'unexpected response'));
        }

        return new BookingResult(
            trackingNumber: $waybill,
            courierReference: $this->childText($result, 'BookingRefNo') ?: null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Waybill created',
            meta: ['key' => $this->childText($result, 'Key'), 'tracking_url' => $this->childText($result, 'TrackingUrl') ?: null],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $doc = $this->soap('CancelWaybill', '<_clientInfo>' . $this->clientInfo() . '</_clientInfo>'
            . $this->el('WaybillNo', (string) (int) $shipment->tracking_number)
            . $this->el('CancelReason', 'Cancelled by merchant'));
        $result = $doc->getElementsByTagName('CancelWaybillResult')->item(0);
        if (! $result || $this->childText($result, 'IsCanceled') !== 'true') {
            throw new CourierException('Naqel: ' . ($result ? ($this->childText($result, 'Message') ?: 'not cancelled') : 'unexpected response'));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $doc = $this->soap('TraceByWaybillNo', '<ClientInfo>' . $this->clientInfo() . '</ClientInfo>'
            . $this->el('WaybillNo', (string) (int) $shipment->tracking_number));

        $events = [];
        foreach ($doc->getElementsByTagName('Tracking') as $t) {
            if ($this->childText($t, 'HasError') === 'true') {
                throw new CourierException('Naqel: ' . ($this->childText($t, 'ErrorMessage') ?: 'tracking failed'));
            }
            $activity = $this->childText($t, 'Activity');
            $event = (int) $this->childText($t, 'EventCode');
            $events[] = new TrackingEvent(
                status: self::EVENTS[$event] ?? StatusText::map($activity),
                rawStatus: $activity ?: ($event ? "Event {$event}" : null),
                at: $this->time($this->childText($t, 'Date')),
                location: $this->childText($t, 'StationCode') ?: null,
                description: $this->childText($t, 'Comments') ?: null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;

        return new TrackingResult(
            status: $latest?->status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $latest?->status === CourierStatus::DELIVERED ? $latest->at : null,
        );
    }

    /** Base64 PDF sticker. */
    public function label(Shipment $shipment): array
    {
        $doc = $this->soap('GetWaybillSticker', '<clientInfo>' . $this->clientInfo() . '</clientInfo>'
            . $this->el('WaybillNo', (string) (int) $shipment->tracking_number)
            . $this->el('StickerSize', $this->account->setting('sticker_size') ?: 'FourMSixthInches'));
        $b64 = $this->firstText($doc, 'GetWaybillStickerResult');
        if ($b64 === '') {
            throw new CourierException('Naqel: no sticker returned for this waybill.');
        }

        return ['pdf' => base64_decode($b64)];
    }

    /** Bundled Naqel city list (KSA + UAE). */
    public function cities(): array
    {
        static $cache = null;
        if ($cache === null) {
            $json = json_decode((string) @file_get_contents(dirname(__DIR__) . '/Resources/naqel_cities.json'), true);
            $cache = collect($json['cities'] ?? [])->map(fn ($c) => [
                'code' => $c['code'],
                'name' => $c['name'] . ' (' . ($c['country'] === 'AE' ? 'UAE' : 'KSA') . ')',
                'country' => $c['country'],
            ])->values()->all();
        }

        return $cache;
    }

    // ------------------------------------------------------------------

    private function cityFor(ShipmentRequest $request): array
    {
        $all = $this->cities();
        $code = strtoupper(trim((string) $request->courierCityCode));
        foreach ($all as $c) {
            if ($code !== '' && strtoupper($c['code']) === $code) {
                return ['code' => $c['code'], 'country' => $c['country'], 'name' => explode(' (', $c['name'])[0]];
            }
        }
        $needle = mb_strtolower(trim($request->city));
        foreach ($all as $c) {
            if (mb_strtolower(explode(' (', $c['name'])[0]) === $needle) {
                return ['code' => $c['code'], 'country' => $c['country'], 'name' => explode(' (', $c['name'])[0]];
            }
        }
        throw new CourierException('Choose the Naqel city for "' . $request->city . '".');
    }

    /** ClientInformation, in WSDL order. */
    private function clientInfo(): string
    {
        $acc = $this->account;
        $address = $this->el('PhoneNumber', (string) $acc->pickup_phone)
            . $this->el('ShipperName', (string) ($acc->pickup_contact ?: $acc->name))
            . $this->el('FirstAddress', (string) $acc->pickup_address)
            . $this->el('Location', (string) $acc->pickup_city)
            . $this->el('CountryCode', (string) ($acc->setting('origin_country') ?: 'KSA'))
            . $this->el('CityCode', strtoupper((string) $acc->setting('origin_city_code')));
        $contact = $this->el('Name', (string) ($acc->pickup_contact ?: $acc->name))
            . $this->el('PhoneNumber', (string) $acc->pickup_phone)
            . $this->el('MobileNo', (string) $acc->pickup_phone);

        return '<ClientAddress>' . $address . '</ClientAddress>'
            . '<ClientContact>' . $contact . '</ClientContact>'
            . $this->el('ClientID', (string) (int) $acc->credential('client_id'))
            . $this->el('Password', $acc->credential('password'))
            . $this->el('Version', '9.0');
    }

    private function soap(string $operation, string $inner): DOMDocument
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><' . $operation . ' xmlns="http://tempuri.org/">' . $inner . '</' . $operation . '></soap:Body>'
            . '</soap:Envelope>';

        $res = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/' . $operation . '"',
            'Accept' => 'text/xml',
        ])->withBody($envelope, 'text/xml; charset=utf-8')->post($this->account->isTestMode() ? self::DEMO : self::LIVE);

        $doc = new DOMDocument();
        if (trim($res->body()) === '' || ! @$doc->loadXML($res->body())) {
            throw new CourierException('Naqel: HTTP ' . $res->status() . ' (not a SOAP response).');
        }
        $fault = $this->firstText($doc, 'faultstring');
        if ($fault !== '') {
            throw new CourierException('Naqel: ' . $fault);
        }

        return $doc;
    }

    private function el(string $name, string $value): string
    {
        return '<' . $name . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . '>';
    }

    private function firstText(DOMDocument $doc, string $tag): string
    {
        $n = $doc->getElementsByTagName($tag)->item(0);

        return $n ? trim($n->textContent) : '';
    }

    private function childText(DOMElement $parent, string $tag): string
    {
        foreach ($parent->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $tag) {
                return trim($c->textContent);
            }
        }

        return '';
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
