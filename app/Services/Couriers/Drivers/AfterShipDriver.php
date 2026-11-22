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
 * AfterShip Tracking (tracking only, 1,100+ couriers). Staff hand the parcel
 * to any courier and type its tracking number; Stocky registers it at
 * AfterShip, which then pushes status changes (webhook) and answers polls.
 *
 * API: https://api.aftership.com/tracking/{version}, header `as-api-key`.
 * Webhooks: header `aftership-hmac-sha256` = base64(HMAC-SHA256(raw body,
 * webhook secret)); payload {event, msg: <tracking>}.
 */
class AfterShipDriver extends AbstractCourierDriver
{
    public const DEFAULT_VERSION = '2026-07';

    /** AfterShip tag → Stocky status (Exception is resolved from its subtag). */
    private const TAGS = [
        'InfoReceived' => CourierStatus::BOOKED,
        'InTransit' => CourierStatus::IN_TRANSIT,
        'OutForDelivery' => CourierStatus::OUT_FOR_DELIVERY,
        'AvailableForPickup' => CourierStatus::OUT_FOR_DELIVERY,
        'AttemptFail' => CourierStatus::FAILED_ATTEMPT,
        'Delivered' => CourierStatus::DELIVERED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'aftership',
            'name' => 'AfterShip (tracking only)',
            'region' => 'Global',
            'countries' => [],
            'website' => 'https://www.aftership.com',
            'tracking_url' => null,
            'description' => 'Automatic status updates for 1,100+ couriers. Book with any courier, enter its tracking number, and AfterShip keeps the status current.',
            'capabilities' => [
                'booking' => true,
                'manual_tracking' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'AfterShip → Settings → API keys.'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'password', 'required' => false,
                    'help' => 'Required for instant status updates. AfterShip → Settings → Webhooks. Used to verify status updates sent to your POS.'],
            ],
            'settings' => [
                ['key' => 'slug', 'label' => 'Default courier slug', 'type' => 'text',
                    'help' => 'AfterShip courier code, e.g. "tcs" or "leopards-courier". Leave empty to let AfterShip detect it.'],
                ['key' => 'api_version', 'label' => 'API version', 'type' => 'text',
                    'help' => 'Leave empty for ' . self::DEFAULT_VERSION . '.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . '/trackings', ['limit' => 1]);

        return $res->successful()
            ? ConnectionResult::ok('Connected to AfterShip.')
            : ConnectionResult::failed($this->error($res));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        if (! $request->trackingNumber) {
            throw new CourierException('Enter the tracking number given by the courier.');
        }

        $slug = $request->service ?: $this->account->setting('slug');
        $body = array_filter([
            'tracking_number' => $request->trackingNumber,
            'slug' => $slug ?: null,
            'order_number' => $request->reference,
            'title' => $request->reference,
            'destination_city' => $request->city ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . '/trackings', $body);

        if ($res->successful()) {
            $tracking = $this->trackingFrom($res->json());
        } else {
            // Already registered at AfterShip (e.g. tracked from another tool): reuse it.
            $tracking = $this->findByNumber($request->trackingNumber);
            if (! $tracking) {
                throw new CourierException('AfterShip: ' . $this->error($res));
            }
        }

        return new BookingResult(
            trackingNumber: $request->trackingNumber,
            courierReference: $tracking['id'] ?? null,
            status: self::TAGS[$tracking['tag'] ?? ''] ?? CourierStatus::BOOKED,
            rawStatus: $tracking['tag'] ?? null,
            meta: ['slug' => $tracking['slug'] ?? $slug],
        );
    }

    /** Stop tracking at AfterShip; never blocks the cancel in Stocky. */
    public function cancel(Shipment $shipment): void
    {
        if ($shipment->courier_reference) {
            try {
                $this->client()->delete($this->base() . '/trackings/' . rawurlencode($shipment->courier_reference));
            } catch (\Throwable $e) {
                // Freeing AfterShip quota is best-effort.
            }
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        if ($shipment->courier_reference) {
            $res = $this->client()->get($this->base() . '/trackings/' . rawurlencode($shipment->courier_reference));
            if (! $res->successful()) {
                throw new CourierException('AfterShip: ' . $this->error($res));
            }
            $tracking = $this->trackingFrom($res->json());
        } else {
            $tracking = $this->findByNumber((string) $shipment->tracking_number);
            if (! $tracking) {
                throw new CourierException('AfterShip does not know this tracking number.');
            }
        }

        return $this->toResult($tracking);
    }

    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('webhook_secret');
        if ($secret === '') {
            throw new CourierException('Set the AfterShip webhook secret on the courier account: status updates are rejected until they can be verified.');
        }
        if ($secret !== '') {
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
            if (! hash_equals($expected, (string) $request->header('aftership-hmac-sha256'))) {
                throw new CourierException('Invalid AfterShip webhook signature.');
            }
        }

        $tracking = $request->input('msg');
        if (! is_array($tracking) || empty($tracking['tracking_number'])) {
            return null;
        }

        return new WebhookUpdate((string) $tracking['tracking_number'], $this->toResult($tracking));
    }

    // ------------------------------------------------------------------

    private function toResult(array $t): TrackingResult
    {
        $events = [];
        foreach ($t['checkpoints'] ?? [] as $cp) {
            $location = $cp['location'] ?? trim(implode(', ', array_filter([$cp['city'] ?? null, $cp['state'] ?? null, $cp['country_region_name'] ?? $cp['country_name'] ?? null])));
            $events[] = new TrackingEvent(
                status: $this->mapTag($cp['tag'] ?? null, $cp['subtag'] ?? null, $cp['message'] ?? null),
                rawStatus: $cp['subtag_message'] ?? $cp['tag'] ?? null,
                at: $this->time($cp['checkpoint_time'] ?? null),
                location: $location ?: null,
                description: $cp['message'] ?? null,
            );
        }

        $status = $this->mapTag($t['tag'] ?? null, $t['subtag'] ?? null, $t['subtag_message'] ?? null);

        return new TrackingResult(
            status: $status,
            rawStatus: $t['subtag_message'] ?? $t['tag'] ?? null,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($t['shipment_delivery_date'] ?? null) : null,
        );
    }

    private function mapTag(?string $tag, ?string $subtag, ?string $text): ?string
    {
        if ($tag === 'Exception') {
            $hay = strtolower(($subtag ?? '') . ' ' . ($text ?? ''));
            if (str_contains($hay, 'returned to sender') || str_contains($hay, 'returned to shipper')) {
                return CourierStatus::RETURNED;
            }

            return str_contains($hay, 'return') ? CourierStatus::RETURNING : CourierStatus::FAILED_ATTEMPT;
        }

        return self::TAGS[$tag ?? ''] ?? null;
    }

    private function findByNumber(string $number): ?array
    {
        $res = $this->client()->get($this->base() . '/trackings', ['tracking_numbers' => $number, 'limit' => 1]);
        if (! $res->successful()) {
            return null;
        }
        $data = $res->json('data') ?? [];
        $list = $data['trackings'] ?? (array_is_list($data) ? $data : []);

        return $list[0] ?? null;
    }

    /** Newer versions return the tracking as `data`, older ones as `data.tracking`. */
    private function trackingFrom(?array $json): array
    {
        $data = $json['data'] ?? [];

        return $data['tracking'] ?? $data;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function base(): string
    {
        $version = trim((string) $this->account->setting('api_version')) ?: self::DEFAULT_VERSION;

        return 'https://api.aftership.com/tracking/' . rawurlencode($version);
    }

    private function client()
    {
        return $this->http()->withHeaders(['as-api-key' => $this->account->credential('api_key')]);
    }

    private function error(Response $res): string
    {
        return $res->json('meta.message') ?: ('HTTP ' . $res->status());
    }
}
