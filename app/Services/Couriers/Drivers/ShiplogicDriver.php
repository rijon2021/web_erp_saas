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
use App\Services\Couriers\Support\RatePicker;
use App\Services\Couriers\Support\Shipper;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Shiplogic (South Africa — Bob Go, The Courier Guy and other couriers on
 * the platform), from Shiplogic's official Postman collection.
 * https://api.shiplogic.com/v3/ (v3 = JSON errors), Bearer API key; a
 * sandbox key works on the same host. A white-label host (e.g. The Courier
 * Guy portal) can be set per account. Service level codes are courier-
 * specific, so they always come from /rates. Labels are signed S3 PDF URLs
 * valid 24 hours, fetched fresh on every print. Webhooks are unsigned —
 * protected by this account's secret URL token.
 */
class ShiplogicDriver extends AbstractCourierDriver
{
    private const DEFAULT_BASE = 'https://api.shiplogic.com';

    private const STATUSES = [
        'submitted' => CourierStatus::BOOKED, 'collection-assigned' => CourierStatus::BOOKED, 'collection-unassigned' => CourierStatus::BOOKED,
        'collection-accepted' => CourierStatus::BOOKED, 'collection-rejected' => CourierStatus::BOOKED, 'awaiting-dropoff' => CourierStatus::BOOKED,
        'collected' => CourierStatus::PICKED_UP, 'collected-from-locker' => CourierStatus::PICKED_UP, 'collected-from-counter' => CourierStatus::PICKED_UP,
        'at-hub' => CourierStatus::IN_TRANSIT, 'manifested' => CourierStatus::IN_TRANSIT, 'ready-for-dispatch' => CourierStatus::IN_TRANSIT,
        'in-transit' => CourierStatus::IN_TRANSIT, 'at-destination-hub' => CourierStatus::IN_TRANSIT, 'inbound-customs-and-vat' => CourierStatus::IN_TRANSIT,
        'on-hold' => CourierStatus::IN_TRANSIT, 'on-hold-internal' => CourierStatus::IN_TRANSIT, 'under-query' => CourierStatus::IN_TRANSIT,
        'floor-check' => CourierStatus::IN_TRANSIT, 'swad-dimensions' => CourierStatus::IN_TRANSIT, 'swad-imaging' => CourierStatus::IN_TRANSIT,
        'delivery-scheduled' => CourierStatus::IN_TRANSIT, 'delivery-assigned' => CourierStatus::IN_TRANSIT,
        'delivery-unassigned' => CourierStatus::IN_TRANSIT, 'delivery-rejected' => CourierStatus::IN_TRANSIT,
        'out-for-delivery' => CourierStatus::OUT_FOR_DELIVERY, 'in-locker' => CourierStatus::OUT_FOR_DELIVERY, 'ready-for-pickup' => CourierStatus::OUT_FOR_DELIVERY,
        'delivery-failed-attempt' => CourierStatus::FAILED_ATTEMPT, 'delivery-exception' => CourierStatus::FAILED_ATTEMPT,
        'collection-failed-attempt' => CourierStatus::FAILED_ATTEMPT, 'collection-exception' => CourierStatus::FAILED_ATTEMPT,
        'collect-and-return-to-hub' => CourierStatus::RETURNING, 'return-to-hub' => CourierStatus::RETURNING, 'returned-to-hub' => CourierStatus::RETURNING,
        'returned-to-sender' => CourierStatus::RETURNED,
        'delivered' => CourierStatus::DELIVERED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'shiplogic',
            'name' => 'Shiplogic (Bob Go / The Courier Guy)',
            'region' => 'South Africa',
            'countries' => ['ZA'],
            'website' => 'https://www.shiplogic.com',
            'tracking_url' => null,
            'description' => 'South African couriers on Shiplogic: compare service levels, book, print waybills, track and cancel.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Shiplogic portal → Integrations → API Keys. A sandbox key books test shipments.'],
            ],
            'settings' => array_merge([
                ['key' => 'sender_suburb', 'label' => 'Pickup suburb', 'type' => 'text'],
                ['key' => 'api_base', 'label' => 'API host', 'type' => 'text',
                    'help' => 'Only for white-label portals, e.g. https://api.portal.thecourierguy.co.za. Leave empty for Shiplogic.'],
            ], Shipper::fields()),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'shipments', ['limit' => 1]);

        return $res->successful()
            ? ConnectionResult::ok('Connected to Shiplogic.')
            : ConnectionResult::failed('Shiplogic: ' . $this->error($res));
    }

    public function rates(ShipmentRequest $request): array
    {
        $res = $this->client()->post($this->base() . 'rates', [
            'collection_address' => $this->collectionAddress(),
            'delivery_address' => $this->deliveryAddress($request),
            'parcels' => $this->parcels($request),
            'declared_value' => round(max($request->declaredValue, 0), 2),
        ]);
        if (! $res->successful()) {
            throw new CourierException('Shiplogic: ' . $this->error($res));
        }

        return collect($res->json('rates') ?? [])->filter(fn ($r) => ! empty($r['service_level']['code']))->map(function ($r) {
            $sl = $r['service_level'];
            $days = null;
            if (! empty($sl['delivery_date_to'])) {
                try {
                    $days = max(0, (int) ceil(now()->startOfDay()->diffInDays(Carbon::parse($sl['delivery_date_to'])->startOfDay(), false)));
                } catch (\Throwable $e) {
                    $days = null;
                }
            }

            return new Rate(
                id: (string) $sl['code'],
                carrier: (string) ($this->account->name ?: 'Shiplogic'),
                service: (string) ($sl['name'] ?? $sl['description'] ?? $sl['code']),
                amount: (float) ($r['rate'] ?? 0),
                currency: 'ZAR',
                days: $days,
            );
        })->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rate = RatePicker::pick($this->rates($request), $request->service ?: $this->account->default_service);
        if (! $rate) {
            throw new CourierException('Shiplogic: no service level matches "' . ($request->service ?: $this->account->default_service) . '".');
        }
        $from = Shipper::address($this->account);

        $res = $this->client()->post($this->base() . 'shipments', array_filter([
            'collection_address' => $this->collectionAddress(),
            'collection_contact' => array_filter(['name' => $from['name'], 'mobile_number' => $from['phone'], 'email' => $from['email'] ?: null]),
            'delivery_address' => $this->deliveryAddress($request),
            'delivery_contact' => array_filter(['name' => $request->consigneeName, 'mobile_number' => $request->consigneePhone, 'email' => $request->consigneeEmail]),
            'parcels' => $this->parcels($request),
            'service_level_code' => $rate->id,
            'declared_value' => round(max($request->declaredValue, 0), 2),
            'customer_reference' => mb_substr($request->reference, 0, 50),
            'customer_reference_name' => 'Order',
            'special_instructions_delivery' => $request->notes ?: null,
        ], fn ($v) => $v !== null && $v !== ''));

        $s = $res->json() ?? [];
        if (! $res->successful() || empty($s['id'])) {
            throw new CourierException('Shiplogic: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) ($s['short_tracking_reference'] ?? $s['custom_tracking_reference'] ?? $s['id']),
            courierReference: (string) $s['id'],
            charges: isset($s['rate']) ? (float) $s['rate'] : $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: $s['status'] ?? 'submitted',
            meta: ['service_level' => $s['service_level_code'] ?? $rate->id, 'custom_tracking_reference' => $s['custom_tracking_reference'] ?? null],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post($this->base() . 'shipments/cancel', ['tracking_reference' => (string) $shipment->tracking_number]);
        if (! $res->successful()) {
            throw new CourierException('Shiplogic: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'tracking/shipments', ['tracking_reference' => (string) $shipment->tracking_number]);
        $s = $res->json('shipments.0');
        if (! $res->successful() || ! is_array($s)) {
            throw new CourierException('Shiplogic: ' . $this->error($res));
        }

        return $this->toResult($s['status'] ?? null, $s['tracking_events'] ?? [], $s['shipment_delivered_date'] ?? null);
    }

    /** Signed PDF URL (valid 24 hours), so it is fetched on every print. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->get($this->base() . 'shipments/label', ['id' => $shipment->courier_reference]);
        $url = $res->json('url');
        if (! $res->successful() || ! $url) {
            throw new CourierException('Shiplogic: ' . $this->error($res));
        }

        return ['url' => $url];
    }

    /** Tracking-event webhook: flat {short_tracking_reference, status, tracking_events[], …}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $ref = $request->input('short_tracking_reference') ?: $request->input('shipment_id');
        if (! $ref) {
            return null;
        }

        return new WebhookUpdate((string) $ref, $this->toResult(
            $request->input('status'),
            (array) $request->input('tracking_events', []),
            $request->input('shipment_delivered_date'),
        ));
    }

    // ------------------------------------------------------------------

    private function toResult(?string $raw, array $events, ?string $deliveredDate): TrackingResult
    {
        $out = [];
        foreach ($events as $e) {
            if (! is_array($e)) {
                continue;
            }
            $out[] = new TrackingEvent(
                status: self::STATUSES[$e['status'] ?? ''] ?? null,
                rawStatus: $e['status'] ?? null,
                at: $this->time($e['date'] ?? null),
                location: $e['location'] ?? null,
                description: $e['message'] ?? null,
            );
        }
        usort($out, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $status = self::STATUSES[(string) $raw] ?? null;

        return new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: $out,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($deliveredDate) : null,
        );
    }

    private function collectionAddress(): array
    {
        $from = Shipper::address($this->account);

        return array_filter([
            'type' => 'business',
            'company' => $from['company'] ?: null,
            'street_address' => $from['street'],
            'local_area' => $this->account->setting('sender_suburb') ?: $from['city'],
            'city' => $from['city'],
            'zone' => $from['state'],
            'country' => $from['country'] ?: 'ZA',
            'code' => $from['zip'],
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function deliveryAddress(ShipmentRequest $request): array
    {
        return array_filter([
            'type' => 'residential',
            'street_address' => $request->address,
            'local_area' => $request->city,
            'city' => $request->courierCityName ?: $request->city,
            'zone' => $request->state,
            'country' => $request->countryCode('ZA'),
            'code' => $request->zip,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function parcels(ShipmentRequest $request): array
    {
        $d = Shipper::dimensions($this->account);

        return [[
            'submitted_length_cm' => $d['length'],
            'submitted_width_cm' => $d['width'],
            'submitted_height_cm' => $d['height'],
            'submitted_weight_kg' => max(0.1, round($request->weight, 2)),
            'parcel_description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100),
        ]];
    }

    private function base(): string
    {
        return rtrim($this->account->setting('api_base') ?: self::DEFAULT_BASE, '/') . '/v3/';
    }

    private function client()
    {
        return $this->http()->withToken($this->account->credential('api_key'));
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
        return $res->json('message') ?: (trim($res->body()) !== '' && strlen($res->body()) < 300 ? trim($res->body()) : ('HTTP ' . $res->status()));
    }
}
