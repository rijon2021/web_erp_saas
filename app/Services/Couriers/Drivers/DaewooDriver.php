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

/**
 * Daewoo FastEx (Pakistan, COD home delivery). Built from the official
 * "DEPBS – COD Home Delivery API Integration Guide v1.6" and checked against
 * the live host. Base https://codapi.daewoo.net.pk/, credentials apiKey /
 * apiUser / apiPassword in the query string; bodies send every value as a
 * string. Destinations are Daewoo terminals (getLocations). No label, COD
 * settlement or webhook endpoints: Stocky prints the label and status is polled.
 */
class DaewooDriver extends AbstractCourierDriver
{
    private const BASE = 'https://codapi.daewoo.net.pk/api/';

    /** Two-letter prefix of TrackingDetails.Status ("OK - DELIVERED - DELIVERED"). */
    private const CODES = [
        'OK' => CourierStatus::DELIVERED,
        'OR' => CourierStatus::IN_TRANSIT,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'daewoo',
            'name' => 'Daewoo FastEx',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://fastex.pk',
            'tracking_url' => 'https://daewoofastex.pk/tracking/?cn={tracking}',
            'description' => 'COD home delivery through Daewoo FastEx terminals: booking, tracking and cancellation.',
            'capabilities' => [
                'booking' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Issued by Daewoo FastEx with your COD account.'],
                ['key' => 'api_user', 'label' => 'API user', 'type' => 'text', 'required' => true],
                ['key' => 'api_password', 'label' => 'API password', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'source_terminal_id', 'label' => 'Origin terminal ID', 'type' => 'number',
                    'help' => 'Daewoo terminal you ship from. Leave empty for your account default.'],
                ['key' => 'category_id', 'label' => 'Category ID', 'type' => 'number',
                    'help' => 'Tariff category from Daewoo. Leave empty for the default.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->http()->get(self::BASE . 'cargo/getLocations', $this->auth());

        return $this->ok($res)
            ? ConnectionResult::ok('Connected to Daewoo FastEx (' . count($res->json('Data') ?? []) . ' terminals).')
            : ConnectionResult::failed($this->error($res));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $destination = $request->courierCityCode ?: $this->terminalIdByName($request->courierCityName ?: $request->city);
        if (! $destination) {
            throw new CourierException('Choose the Daewoo terminal for "' . $request->city . '".');
        }

        $source = (string) ($this->account->setting('source_terminal_id') ?: '0');
        $body = [
            'order_no' => $request->reference,
            'source_terminal_id' => $source,
            'destination_terminal_id' => (string) $destination,
            'receiver_name' => $request->consigneeName,
            'receiver_cnic' => '',
            'receiver_mobile' => Phone::pk($request->consigneePhone),
            'receiver_address' => $request->address,
            'receiver_city' => $request->courierCityName ?: $request->city,
            'receiver_email' => (string) $request->consigneeEmail,
            'remarks' => $request->notes,
            'category_id' => (string) ($this->account->setting('category_id') ?: '0'),
            'qty' => (string) $request->pieces,
            'weight' => (string) round($request->weight, 3),
            'barcode' => '0',
            'cod_amount' => (string) round($request->codAmount),
            'source_location_point' => '0.0',
            'destination_location_point' => '0.0',
            'source_location_address' => (string) $this->account->pickup_address,
            'destination_location_address' => $request->address,
            'item_description' => $request->itemsDescription,
        ];

        // quickBookV3 books from a chosen terminal; quickBook from the account default.
        $method = $source !== '0' ? 'quickBookV3' : 'quickBook';
        $res = $this->http()->asJson()->post(self::BASE . 'booking/' . $method . '?' . http_build_query($this->auth()), $body);

        $tracking = (string) $res->json('TrackNo');
        if (! $res->successful() || $res->json('Success') !== true || $tracking === '' || $tracking === '0') {
            throw new CourierException('Daewoo: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: $tracking,
            courierReference: $res->json('Barcode') ? (string) $res->json('Barcode') : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Booked',
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $query = http_build_query($this->auth() + ['trackingNo' => $shipment->tracking_number]);
        $res = $this->http()->post(self::BASE . 'booking/quickCancel?' . $query);
        if (! $res->successful() || $res->json('Success') !== true) {
            throw new CourierException('Daewoo: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $number = (string) $shipment->tracking_number;
        // Credentials go along like on every other call (ignored if the endpoint is public).
        $res = $this->http()->get(self::BASE . 'booking/quickTrack', ['trackingNo' => $number] + $this->auth());
        $result = $res->json('Result') ?? [];
        $current = is_array($result['CurrentTrackStatus'] ?? null) ? ($result['CurrentTrackStatus'][0] ?? null) : null;

        if (! $res->successful() || ($result['Success'] ?? false) !== true || ! $current) {
            throw new CourierException('Daewoo: ' . ($result['Response'] ?? ('HTTP ' . $res->status())));
        }
        // An unknown number can come back as somebody else's consignment.
        if ((string) ($current['track_no'] ?? '') !== $number) {
            throw new CourierException('Daewoo returned a different consignment for ' . $number . '.');
        }

        $events = [];
        foreach (is_array($result['TrackingDetails'] ?? null) ? $result['TrackingDetails'] : [] as $h) {
            $text = $h['Status'] ?? null;
            $events[] = new TrackingEvent(
                status: $this->map($text),
                rawStatus: $text,
                at: $this->time($h['PortalDate'] ?? $h['Date'] ?? null),
                location: $h['TransactionTerminal'] ?? null,
                description: trim(($h['Status_Reason'] ?? '') . (! empty($h['Collector_Name']) ? ' · ' . $h['Collector_Name'] : ''), ' ·') ?: null,
            );
        }

        $raw = $current['status_name'] ?? null;
        $status = $this->map($raw) ?? ($events ? end($events)->status : null);

        return new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: $events,
        );
    }

    /** Terminals: [{code: terminal_id, name: terminal_name}]. */
    public function cities(): array
    {
        $res = $this->http()->get(self::BASE . 'cargo/getLocations', $this->auth());
        if (! $this->ok($res)) {
            throw new CourierException('Daewoo: ' . $this->error($res));
        }

        return collect($res->json('Data') ?? [])
            ->filter(fn ($t) => isset($t['terminal_id'], $t['terminal_name']))
            ->map(fn ($t) => ['code' => (string) $t['terminal_id'], 'name' => $t['terminal_name']])
            ->values()->all();
    }

    // ------------------------------------------------------------------

    private function map(?string $text): ?string
    {
        $code = strtoupper(substr(trim((string) $text), 0, 2));
        if (isset(self::CODES[$code]) && preg_match('/^[A-Z]{2}\s*-/', strtoupper(trim((string) $text)))) {
            return self::CODES[$code];
        }

        return StatusText::map($text);
    }

    private function terminalIdByName(?string $name): ?string
    {
        $needle = mb_strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }
        foreach ($this->cities() as $t) {
            if (mb_strtolower(trim($t['name'])) === $needle) {
                return $t['code'];
            }
        }

        return null;
    }

    private function auth(): array
    {
        return [
            'apiKey' => $this->account->credential('api_key'),
            'apiUser' => $this->account->credential('api_user'),
            'apiPassword' => $this->account->credential('api_password'),
        ];
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && $res->json('Success') === true;
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
        return $res->json('Response') ?: $res->json('Message') ?: ('HTTP ' . $res->status());
    }
}
