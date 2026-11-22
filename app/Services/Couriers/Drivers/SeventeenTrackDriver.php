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
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * 17TRACK (tracking only, 3,000+ carriers). Numbers are registered once,
 * then 17TRACK pushes TRACKING_UPDATED webhooks and answers gettrackinfo.
 *
 * API v2.4: https://api.17track.net/track/v2.4/*, header `17token`; bodies
 * are arrays of {number, carrier}; responses {code, data{accepted, rejected}}.
 * Webhook: header `sign` = sha256_hex(raw body . '/' . api key).
 */
class SeventeenTrackDriver extends AbstractCourierDriver
{
    private const BASE = 'https://api.17track.net/track/v2.4';

    private const ALREADY_REGISTERED = -18019901;

    private const STATUSES = [
        'InfoReceived' => CourierStatus::BOOKED,
        'InTransit' => CourierStatus::IN_TRANSIT,
        'AvailableForPickup' => CourierStatus::OUT_FOR_DELIVERY,
        'OutForDelivery' => CourierStatus::OUT_FOR_DELIVERY,
        'DeliveryFailure' => CourierStatus::FAILED_ATTEMPT,
        'Delivered' => CourierStatus::DELIVERED,
    ];

    public static function meta(): array
    {
        return [
            'code' => '17track',
            'name' => '17TRACK (tracking only)',
            'region' => 'Global',
            'countries' => [],
            'website' => 'https://www.17track.net',
            'tracking_url' => 'https://t.17track.net/en#nums={tracking}',
            'description' => 'Automatic status updates for 3,000+ carriers. Book with any courier, enter its tracking number, and 17TRACK keeps the status current.',
            'capabilities' => [
                'booking' => true,
                'manual_tracking' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'Security key', 'type' => 'password', 'required' => true,
                    'help' => '17TRACK API → Settings → Security key. Also used to verify status updates.'],
            ],
            'settings' => [
                ['key' => 'carrier', 'label' => 'Default carrier code', 'type' => 'number',
                    'help' => '17TRACK carrier number. Leave empty to let 17TRACK detect the carrier.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->post(self::BASE . '/getquota', []);
        if (! $res->successful() || (int) $res->json('code', -1) !== 0) {
            return ConnectionResult::failed($this->error($res));
        }

        $remain = $res->json('data.quota_remain');

        return ConnectionResult::ok('Connected to 17TRACK' . ($remain !== null ? " ({$remain} trackings left in quota)." : '.'));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        if (! $request->trackingNumber) {
            throw new CourierException('Enter the tracking number given by the courier.');
        }

        $item = $this->item($request->trackingNumber, $request->service ?: $this->account->setting('carrier'));
        $item['tag'] = mb_substr($request->reference, 0, 100);

        $res = $this->client()->post(self::BASE . '/register', [$item]);
        if (! $res->successful() || (int) $res->json('code', -1) !== 0) {
            throw new CourierException('17TRACK: ' . $this->error($res));
        }

        $rejected = $res->json('data.rejected.0');
        if ($rejected && (int) ($rejected['error']['code'] ?? 0) !== self::ALREADY_REGISTERED) {
            throw new CourierException('17TRACK: ' . ($rejected['error']['message'] ?? 'tracking number rejected'));
        }

        $accepted = $res->json('data.accepted.0') ?? [];

        return new BookingResult(
            trackingNumber: $request->trackingNumber,
            courierReference: isset($accepted['carrier']) ? (string) $accepted['carrier'] : ($item['carrier'] ?? null),
            meta: ['carrier' => $accepted['carrier'] ?? ($item['carrier'] ?? null)],
        );
    }

    /** Stop tracking at 17TRACK; never blocks the cancel in Stocky. */
    public function cancel(Shipment $shipment): void
    {
        try {
            $this->client()->post(self::BASE . '/stoptrack', [$this->item((string) $shipment->tracking_number, $shipment->courier_reference)]);
        } catch (\Throwable $e) {
            // Best-effort.
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->post(self::BASE . '/gettrackinfo', [$this->item((string) $shipment->tracking_number, $shipment->courier_reference)]);
        if (! $res->successful() || (int) $res->json('code', -1) !== 0) {
            throw new CourierException('17TRACK: ' . $this->error($res));
        }

        $accepted = $res->json('data.accepted.0');
        if (! $accepted) {
            throw new CourierException('17TRACK: ' . ($res->json('data.rejected.0.error.message') ?: 'no tracking information yet'));
        }

        return $this->toResult($accepted['track_info'] ?? []);
    }

    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $expected = hash('sha256', $request->getContent() . '/' . $this->account->credential('api_key'));
        if (! hash_equals($expected, strtolower((string) $request->header('sign')))) {
            throw new CourierException('Invalid 17TRACK webhook signature.');
        }

        if ($request->input('event') !== 'TRACKING_UPDATED') {
            return null;
        }

        $data = $request->input('data') ?? [];
        if (empty($data['number'])) {
            return null;
        }

        return new WebhookUpdate((string) $data['number'], $this->toResult($data['track_info'] ?? []));
    }

    // ------------------------------------------------------------------

    private function toResult(array $info): TrackingResult
    {
        $events = [];
        foreach ($info['tracking']['providers'] ?? [] as $provider) {
            foreach ($provider['events'] ?? [] as $e) {
                $events[] = new TrackingEvent(
                    status: $this->map($e['stage'] ?? null, $e['sub_status'] ?? null),
                    rawStatus: $e['sub_status'] ?? $e['stage'] ?? null,
                    at: $this->time($e['time_iso'] ?? $e['time_utc'] ?? null),
                    location: is_string($e['location'] ?? null) ? $e['location'] : null,
                    description: $e['description'] ?? null,
                );
            }
        }

        $latest = $info['latest_status'] ?? [];
        $status = $this->map($latest['status'] ?? null, $latest['sub_status'] ?? null);

        return new TrackingResult(
            status: $status,
            rawStatus: $latest['sub_status'] ?? $latest['status'] ?? null,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($info['latest_event']['time_iso'] ?? null) : null,
        );
    }

    private function map(?string $status, ?string $subStatus): ?string
    {
        if ($subStatus === 'Exception_Returned') {
            return CourierStatus::RETURNED;
        }
        if ($subStatus === 'Exception_Returning') {
            return CourierStatus::RETURNING;
        }
        if ($status === 'Exception' && in_array($subStatus, ['Exception_Rejected', 'Exception_NoBody', 'Exception_Security', 'Exception_Delayed'], true)) {
            return CourierStatus::FAILED_ATTEMPT;
        }
        if ($status === 'Exception' && $subStatus === 'Exception_Cancel') {
            return CourierStatus::CANCELLED;
        }

        return self::STATUSES[$status ?? ''] ?? null;
    }

    private function item(string $number, $carrier): array
    {
        $item = ['number' => $number];
        if (is_numeric($carrier) && (int) $carrier > 0) {
            $item['carrier'] = (int) $carrier;
        }

        return $item;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function client()
    {
        return $this->http()->withHeaders(['17token' => $this->account->credential('api_key')]);
    }

    private function error(Response $res): string
    {
        return $res->json('data.errors.0.message') ?: $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
