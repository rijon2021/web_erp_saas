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
 * RedX (ShopUp, Bangladesh) — official developer docs at
 * redx.com.bd/developer-api. Header "API-ACCESS-TOKEN: Bearer <token>"
 * (separate sandbox token). Booking needs a RedX area (name + id, from
 * /areas); weight in grams. Webhooks are unsigned — the callback URL itself
 * (Stocky's per-account token) is the credential, as RedX recommends.
 */
class RedxDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://openapi.redx.com.bd/v1.0.0-beta/';
    private const SANDBOX = 'https://sandbox.redx.com.bd/v1.0.0-beta/';

    private const STATUSES = [
        'pickup-pending' => CourierStatus::BOOKED,
        'ready-for-delivery' => CourierStatus::IN_TRANSIT,
        'agent-area-change' => CourierStatus::IN_TRANSIT,
        'delivery-in-progress' => CourierStatus::OUT_FOR_DELIVERY,
        'agent-hold' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED,
        'agent-returning' => CourierStatus::RETURNING,
        'returned' => CourierStatus::RETURNED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'redx',
            'name' => 'RedX',
            'region' => 'Bangladesh',
            'countries' => ['BD'],
            'website' => 'https://redx.com.bd',
            'tracking_url' => null,
            'description' => 'RedX parcels with COD by delivery area, tracking, cancellation and status webhooks.',
            'capabilities' => [
                'booking' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'cities' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'access_token', 'label' => 'API access token', 'type' => 'password', 'required' => true,
                    'help' => 'RedX merchant panel → Developer API → Request token. Sandbox uses a separate token.'],
            ],
            'settings' => [
                ['key' => 'pickup_store_id', 'label' => 'Pickup store ID', 'type' => 'text',
                    'help' => 'Optional. Leave empty to use your default RedX pickup store.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'pickup/stores');
        if (! $res->successful()) {
            return ConnectionResult::failed('RedX: ' . $this->error($res));
        }
        $stores = collect($res->json('pickup_stores') ?? [])->map(fn ($s) => ($s['id'] ?? '') . ' ' . ($s['name'] ?? ''))->implode(', ');

        return ConnectionResult::ok('Connected to RedX.' . ($stores ? ' Pickup stores: ' . $stores : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        if (! $request->courierCityCode) {
            throw new CourierException('RedX: choose the RedX delivery area.');
        }

        $price = (string) (int) round($request->declaredValue ?: $request->codAmount);
        $res = $this->client()->post($this->base() . 'parcel', array_filter([
            'customer_name' => mb_substr($request->consigneeName, 0, 100),
            'customer_phone' => Phone::bd($request->consigneePhone),
            'delivery_area' => (string) $request->courierCityName,
            'delivery_area_id' => (int) $request->courierCityCode,
            'customer_address' => mb_substr($request->address, 0, 250),
            'merchant_invoice_id' => mb_substr($request->reference, 0, 100),
            'cash_collection_amount' => (string) (int) round($request->codAmount),
            'parcel_weight' => (string) max(1, (int) round($request->weight * 1000)),
            'instruction' => $request->notes ?: null,
            'value' => $price,
            'parcel_details_json' => $request->itemsDescription
                ? [['name' => mb_substr($request->itemsDescription, 0, 100), 'category' => 'General', 'value' => (int) $price]] : null,
            'pickup_store_id' => $this->account->setting('pickup_store_id') ? (int) $this->account->setting('pickup_store_id') : null,
        ], fn ($v) => $v !== null && $v !== ''));

        $tn = $res->json('tracking_id');
        if (! $res->successful() || ! $tn) {
            throw new CourierException('RedX: ' . $this->error($res));
        }

        return new BookingResult(trackingNumber: (string) $tn, status: CourierStatus::BOOKED, rawStatus: 'pickup-pending');
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $id = rawurlencode((string) $shipment->tracking_number);
        $info = $this->client()->get($this->base() . 'parcel/info/' . $id);
        $parcel = $info->json('parcel');
        if (! $info->successful() || ! is_array($parcel)) {
            throw new CourierException('RedX: ' . $this->error($info));
        }

        $events = [];
        try {
            foreach ($this->client()->get($this->base() . 'parcel/track/' . $id)->json('tracking') ?? [] as $t) {
                $events[] = new TrackingEvent(StatusText::map($t['message_en'] ?? null), $t['message_en'] ?? 'Update', $this->time($t['time'] ?? null));
            }
        } catch (\Throwable $e) {
            // The current status still counts without history.
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = (string) ($parcel['status'] ?? '');

        return new TrackingResult(
            // "paid" comes after delivery: a poll that missed "delivered" still finishes the parcel.
            status: $raw === 'paid' ? CourierStatus::DELIVERED : (self::STATUSES[$raw] ?? StatusText::map($raw)),
            rawStatus: $raw ?: null,
            events: $events,
            charges: is_numeric($parcel['charge'] ?? null) ? (float) $parcel['charge'] : null,
            codStatus: $raw === 'paid' ? 'remitted' : null,
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->patch($this->base() . 'parcels', [
            'entity_type' => 'parcel-tracking-id',
            'entity_id' => (string) $shipment->tracking_number,
            'update_details' => ['property_name' => 'status', 'new_value' => 'cancelled', 'reason' => 'Cancelled by merchant'],
        ]);
        if (! $res->successful() || $res->json('success') === false) {
            throw new CourierException('RedX: ' . $this->error($res));
        }
    }

    /** Areas: {code: id, name: "Area, District (post code)"}. */
    public function cities(): array
    {
        $res = $this->client()->get($this->base() . 'areas');
        if (! $res->successful()) {
            throw new CourierException('RedX: ' . $this->error($res));
        }

        return collect($res->json('areas') ?? [])
            ->filter(fn ($a) => isset($a['id'], $a['name']))
            ->map(fn ($a) => ['code' => (string) $a['id'], 'name' => (string) $a['name']])
            ->values()->all();
    }

    /** {tracking_number, timestamp, status, message_en, invoice_number, delivery_type}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $tn = $request->input('tracking_number');
        $raw = (string) $request->input('status');
        if (! $tn || $raw === '') {
            return null;
        }

        $at = $this->time($request->input('timestamp'));
        if ($raw === 'paid') {
            return new WebhookUpdate((string) $tn, new TrackingResult(
                status: null,
                rawStatus: $raw,
                events: [new TrackingEvent(null, $request->input('message_en') ?: 'Parcel amount is paid', $at)],
                codStatus: 'remitted',
                codRemittedAt: $at,
            ));
        }

        $status = self::STATUSES[$raw] ?? StatusText::map($raw);

        return new WebhookUpdate((string) $tn, new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: [new TrackingEvent($status, $request->input('message_en') ?: $raw, $at)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    private function client()
    {
        return $this->http()->withHeaders(['API-ACCESS-TOKEN' => 'Bearer ' . $this->account->credential('access_token')]);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE;
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
        $errors = $res->json('errors');
        $detail = is_array($errors) && $errors ? ' (' . collect($errors)->flatten()->implode('; ') . ')' : '';

        return ($res->json('message') ?: ('HTTP ' . $res->status())) . $detail;
    }
}
