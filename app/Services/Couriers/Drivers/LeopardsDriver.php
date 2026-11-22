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
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Leopards Courier (Pakistan, COD). Built from the official "eCom Merchant
 * APIs Version 2.0" document. {base}{method}/format/json/ with api_key +
 * api_password in the body; envelope {status: 1|0, error}. Cities are IDs
 * (getAllCities); weight is in grams; the slip is a URL returned at booking.
 * Status pushes come from the Leopards Push API, registered with Leopards
 * against this account's webhook URL.
 */
class LeopardsDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://merchantapi.leopardscourier.com/api/';
    private const STAGING = 'https://merchantapistaging.leopardscourier.com/api/';

    /** Push API / activity codes → Stocky status. */
    private const CODES = [
        'RC' => CourierStatus::BOOKED,
        'SP' => CourierStatus::PICKED_UP,
        'DP' => CourierStatus::IN_TRANSIT,
        'AR' => CourierStatus::IN_TRANSIT,
        'AC' => CourierStatus::OUT_FOR_DELIVERY,
        'PN1' => CourierStatus::FAILED_ATTEMPT,
        'PN2' => CourierStatus::FAILED_ATTEMPT,
        'DV' => CourierStatus::DELIVERED,
        'NR' => CourierStatus::RETURNING,
        'RS' => CourierStatus::RETURNED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'leopards',
            'name' => 'Leopards Courier',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://www.leopardscourier.com',
            'tracking_url' => null,
            'description' => 'COD deliveries across Pakistan through the Leopards merchant API: booking, slips, tracking, COD payments and status pushes.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Leopards merchant portal → API Settings → API Management.'],
                ['key' => 'api_password', 'label' => 'API password', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'origin_city', 'label' => 'Origin city ID', 'type' => 'number',
                    'help' => 'Leopards city ID you ship from. Leave empty to use your account city.'],
                ['key' => 'shipment_id', 'label' => 'Shipper profile ID', 'type' => 'number',
                    'help' => 'Optional shipper (pickup) profile from the Leopards portal.'],
                ['key' => 'shipment_type', 'label' => 'Shipment type', 'type' => 'text',
                    'help' => 'e.g. overnight. Leave empty for the Leopards default.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->call('getAllCities');
        if (! $this->ok($res)) {
            return ConnectionResult::failed($this->error($res));
        }

        return ConnectionResult::ok('Connected to Leopards (' . count($res->json('city_list') ?? []) . ' cities available).');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $destination = $request->courierCityCode ?: $this->cityIdByName($request->courierCityName ?: $request->city);
        if (! $destination) {
            throw new CourierException('Choose the Leopards city for "' . $request->city . '".');
        }

        $acc = $this->account;
        $body = array_filter([
            'booked_packet_weight' => max(1, (int) round($request->weight * 1000)),
            'booked_packet_no_piece' => $request->pieces,
            'booked_packet_collect_amount' => (int) round($request->codAmount),
            'booked_packet_order_id' => $request->reference,
            'origin_city' => $acc->setting('origin_city') ?: 'self',
            'destination_city' => (int) $destination,
            'shipment_id' => $acc->setting('shipment_id') ?: null,
            'shipment_name_eng' => $acc->pickup_contact ?: 'self',
            'shipment_email' => 'self',
            'shipment_phone' => $acc->pickup_phone ? Phone::pk($acc->pickup_phone) : 'self',
            'shipment_address' => $acc->pickup_address ?: 'self',
            'consignment_name_eng' => $request->consigneeName,
            'consignment_email' => $request->consigneeEmail ?: null,
            'consignment_phone' => Phone::pk($request->consigneePhone),
            'consignment_address' => $request->address,
            'special_instructions' => trim($request->notes . ' ' . $request->itemsDescription) ?: 'None',
            'shipment_type' => $request->service ?: ($acc->setting('shipment_type') ?: null),
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->call('bookPacket', $body);
        $tracking = $res->json('track_number');
        if (! $this->ok($res) || ! $tracking) {
            throw new CourierException('Leopards: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) $tracking,
            labelUrl: $res->json('slip_link') ?: null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Booked',
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->call('cancelBookedPackets', ['cn_numbers' => (string) $shipment->tracking_number]);
        if (! $this->ok($res)) {
            throw new CourierException('Leopards: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->call('trackBookedPacket', ['track_numbers' => (string) $shipment->tracking_number]);
        $packet = $res->json('packet_list.0');
        if (! $this->ok($res) || ! $packet) {
            throw new CourierException('Leopards: ' . $this->error($res));
        }

        $events = [];
        foreach ($packet['Tracking Detail'] ?? [] as $h) {
            $text = $h['Status'] ?? $h['Staus'] ?? null;
            $date = trim(($h['Activity Date'] ?? '') . ' ' . ($h['Activity Time'] ?? $h['Activity_Time'] ?? ''));
            $receiver = $h['Reciever Name'] ?? $h['Receiver Name'] ?? null;
            $events[] = new TrackingEvent(
                status: $this->mapStatus($text),
                rawStatus: $text,
                at: $this->time($date),
                description: trim(($h['Reason'] ?? '') . ($receiver ? ' · ' . $receiver : ''), ' ·') ?: null,
            );
        }

        $raw = $packet['booked_packet_status'] ?? null;
        $status = $this->mapStatus($raw);

        [$codStatus, $codRef, $codAt] = $status === CourierStatus::DELIVERED ? $this->payment($shipment) : [null, null, null];

        return new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($packet['activity_date'] ?? null) : null,
            codStatus: $codStatus,
            codReference: $codRef,
            codRemittedAt: $codAt,
        );
    }

    /** The slip URL is saved at booking; nothing else to fetch. */
    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        throw new CourierException('Leopards returns the slip link only at booking time.');
    }

    public function cities(): array
    {
        $res = $this->call('getAllCities');
        if (! $this->ok($res)) {
            throw new CourierException('Leopards: ' . $this->error($res));
        }

        return collect($res->json('city_list') ?? [])
            ->filter(fn ($c) => ($c['allow_as_destination'] ?? true) && isset($c['id'], $c['name']))
            ->map(fn ($c) => ['code' => (string) $c['id'], 'name' => $c['name']])
            ->values()->all();
    }

    /**
     * Leopards Push API: {"data":[{cn_number, status (code), reason, activity_date}]}.
     * It carries no signature; the secret token in this account's webhook URL
     * is the authentication.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $updates = [];
        foreach ((array) $request->input('data', []) as $row) {
            if (! is_array($row) || empty($row['cn_number'])) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['status'] ?? '')));
            $status = self::CODES[$code] ?? StatusText::map($code);
            $at = $this->time($row['activity_date'] ?? null);
            $updates[] = new WebhookUpdate((string) $row['cn_number'], new TrackingResult(
                status: $status,
                rawStatus: $code ?: null,
                events: [new TrackingEvent($status, $code ?: null, $at, null, $row['reason'] ?? null)],
                deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
            ));
        }

        return $updates ?: null;
    }

    /** What the Push API expects back. */
    public function webhookAck(): array
    {
        return [202, [['status' => 1, 'errors' => []]]];
    }

    // ------------------------------------------------------------------

    private function mapStatus(?string $text): ?string
    {
        $code = strtoupper(trim((string) $text));

        return self::CODES[$code] ?? StatusText::map($text);
    }

    /**
     * COD payment: getPaymentDetails returns the cheque / invoice used to pay
     * the merchant. Its status values are undocumented, so a payment counts
     * as remitted once an invoice/cheque number is present.
     */
    private function payment(Shipment $shipment): array
    {
        try {
            $res = $this->call('getPaymentDetails', ['cn_numbers' => (string) $shipment->tracking_number], 'GET');
            $p = $res->json('payment_list.0');
            if (! $this->ok($res) || empty($p['invoice_cheque_no'])) {
                return [null, null, null];
            }

            return ['remitted', (string) $p['invoice_cheque_no'], $this->time($p['invoice_cheque_date'] ?? null)];
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    private function cityIdByName(?string $name): ?string
    {
        $needle = mb_strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }
        foreach ($this->cities() as $c) {
            if (mb_strtolower(trim($c['name'])) === $needle) {
                return $c['code'];
            }
        }

        return null;
    }

    private function call(string $method, array $params = [], string $verb = 'POST'): Response
    {
        $base = $this->account->isTestMode() ? self::STAGING : self::LIVE;
        $payload = ['api_key' => $this->account->credential('api_key'), 'api_password' => $this->account->credential('api_password')] + $params;
        $url = $base . $method . '/format/json/';

        return $verb === 'GET' ? $this->http()->get($url, $payload) : $this->http()->asJson()->post($url, $payload);
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && (int) $res->json('status') === 1;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse(str_replace('/', '-', $value)) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $error = $res->json('error');
        if (is_array($error)) {
            $error = implode('; ', array_map(fn ($k, $v) => is_string($k) ? "{$k}: {$v}" : (string) $v, array_keys($error), $error));
        }

        return ($error && $error !== 'null' && $error !== '0') ? (string) $error : ('HTTP ' . $res->status());
    }
}
