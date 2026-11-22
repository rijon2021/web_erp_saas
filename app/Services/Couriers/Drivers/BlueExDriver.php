<?php

namespace App\Services\Couriers\Drivers;

use App\Models\Shipment;
use App\Services\Couriers\AbstractCourierDriver;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;

/**
 * BlueEx (Pakistan) — tracking only for now. BlueEx publishes no API
 * documentation; only its V4 tracking call is confirmed
 * (POST https://apis.blue-ex.com/api/V4/GetTracking, HTTP Basic auth with the
 * portal login, body {ShipmentNumbers: [...]}). Staff book in the BlueEx
 * portal and enter the CN here; Stocky then follows the parcel. Booking,
 * labels and COD settlement wait for BlueEx's official API spec.
 */
class BlueExDriver extends AbstractCourierDriver
{
    private const TRACKING = 'https://apis.blue-ex.com/api/V4/GetTracking';

    public static function meta(): array
    {
        return [
            'code' => 'blueex',
            'name' => 'BlueEx (tracking)',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://www.blue-ex.com',
            'tracking_url' => null,
            'description' => 'Book in the BlueEx portal, enter the CN here, and the status is kept current automatically.',
            'capabilities' => [
                'booking' => true,
                'manual_tracking' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
            ],
            'credentials' => [
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true,
                    'help' => 'Your BlueEx portal login.'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ],
            'settings' => [],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->post(self::TRACKING, ['ShipmentNumbers' => ['0']]);
        // A wrong login answers 400 {"status":"0","message":"Invalid Username"}.
        if ($res->status() === 401 || ($res->status() === 400 && (string) $res->json('status') === '0')) {
            return ConnectionResult::failed('BlueEx: ' . ($res->json('message') ?: 'login rejected'));
        }

        return $res->serverError()
            ? ConnectionResult::failed('BlueEx: HTTP ' . $res->status())
            : ConnectionResult::ok('Connected to BlueEx.');
    }

    /** Registers a CN booked in the BlueEx portal. */
    public function book(ShipmentRequest $request): BookingResult
    {
        if (! $request->trackingNumber) {
            throw new CourierException('Enter the CN from the BlueEx portal.');
        }

        return new BookingResult(trackingNumber: $request->trackingNumber);
    }

    /** Cancel in the BlueEx portal; Stocky only marks it cancelled. */
    public function cancel(Shipment $shipment): void
    {
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->post(self::TRACKING, ['ShipmentNumbers' => [(string) $shipment->tracking_number]]);
        $parcel = collect($res->json('processed_shipments') ?? [])
            ->first(fn ($p) => (string) ($p['shipmentnumber'] ?? '') === (string) $shipment->tracking_number);
        if (! $res->successful() || ! $parcel) {
            throw new CourierException('BlueEx: ' . ($res->json('message') ?: ('HTTP ' . $res->status())));
        }

        $events = [];
        foreach ($parcel['cnDetail'] ?? [] as $h) {
            $text = $h['statusmessage'] ?? null;
            $events[] = new TrackingEvent(
                status: StatusText::map($text),
                rawStatus: $text,
                at: $this->time(trim(($h['statusdate'] ?? '') . ' ' . ($h['statustime'] ?? ''))),
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;

        return new TrackingResult(
            status: $latest?->status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $latest?->status === 'delivered' ? $latest->at : null,
        );
    }

    private function client()
    {
        return $this->http()->asJson()->withBasicAuth($this->account->credential('username'), $this->account->credential('password'));
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
