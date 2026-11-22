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
use Illuminate\Support\Str;

/**
 * Biteship (Indonesia aggregator — JNE, SiCepat, J&T, AnterAja, Gojek…).
 * https://api.biteship.com/v1, header `authorization: <API key>` (test or
 * live key decides the mode). Weight in grams; origin/destination by postal
 * code. POST /v1/orders books and pays from the Biteship balance. Biteship
 * has no label API, so Stocky prints the label. Webhooks carry a static
 * header name/value the merchant sets in the Biteship dashboard.
 */
class BiteshipDriver extends AbstractCourierDriver
{
    private const BASE = 'https://api.biteship.com/v1/';

    private const STATUSES = [
        'confirmed' => CourierStatus::BOOKED, 'scheduled' => CourierStatus::BOOKED,
        'allocated' => CourierStatus::BOOKED, 'picking_up' => CourierStatus::BOOKED,
        'picked' => CourierStatus::PICKED_UP,
        'in_transit' => CourierStatus::IN_TRANSIT,
        'dropping_off' => CourierStatus::OUT_FOR_DELIVERY,
        'on_hold' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED,
        'return_in_transit' => CourierStatus::RETURNING,
        'returned' => CourierStatus::RETURNED, 'disposed' => CourierStatus::RETURNED,
        'cancelled' => CourierStatus::CANCELLED, 'rejected' => CourierStatus::CANCELLED, 'courier_not_found' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'biteship',
            'name' => 'Biteship',
            'region' => 'Indonesia',
            'countries' => ['ID'],
            'website' => 'https://biteship.com',
            'tracking_url' => null,
            'description' => 'JNE, SiCepat, J&T, AnterAja, instant couriers and more through Biteship: compare rates, book with COD, track.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Biteship dashboard → Integrations → API keys (biteship_test… or biteship_live…).'],
                ['key' => 'webhook_header', 'label' => 'Webhook header name', 'type' => 'text',
                    'help' => 'Required for instant status updates. The "Headers Signature Key" set on your Biteship webhook.'],
                ['key' => 'webhook_secret', 'label' => 'Webhook header value', 'type' => 'password',
                    'help' => 'Required for instant status updates. The "Headers Signature Secret".'],
            ],
            'settings' => [
                ['key' => 'origin_postal_code', 'label' => 'Pickup postal code', 'type' => 'text', 'required' => true],
                ['key' => 'couriers', 'label' => 'Couriers to compare', 'type' => 'text',
                    'help' => 'Comma-separated codes. Leave empty for jne,sicepat,jnt,anteraja,ninja,pos.'],
                ['key' => 'item_category', 'label' => 'Item category', 'type' => 'text',
                    'help' => 'e.g. fashion, electronic, others. Leave empty for others.'],
                ['key' => 'collection_method', 'label' => 'Hand-over', 'type' => 'select', 'options' => [
                    ['value' => 'pickup', 'label' => 'Courier picks up'], ['value' => 'drop_off', 'label' => 'I drop off'],
                ], 'help' => 'Leave empty for pickup.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get(self::BASE . 'couriers');
        if (! $res->successful() || $res->json('success') === false) {
            return ConnectionResult::failed('Biteship: ' . $this->error($res));
        }
        $mode = str_starts_with($this->account->credential('api_key'), 'biteship_test') ? ' (test key)' : '';

        return ConnectionResult::ok('Connected to Biteship' . $mode . '.');
    }

    public function rates(ShipmentRequest $request): array
    {
        $body = array_filter([
            'origin_postal_code' => (int) preg_replace('/\D/', '', (string) $this->account->setting('origin_postal_code')),
            'destination_postal_code' => (int) preg_replace('/\D/', '', (string) $request->zip),
            'couriers' => $this->account->setting('couriers') ?: 'jne,sicepat,jnt,anteraja,ninja,pos',
            'items' => $this->items($request),
            'destination_cash_on_delivery' => $request->codAmount > 0 ? (int) round($request->codAmount) : null,
            'destination_cash_on_delivery_type' => $request->codAmount > 0 ? '7_days' : null,
        ], fn ($v) => $v !== null && $v !== 0);

        $res = $this->client()->post(self::BASE . 'rates/couriers', $body);
        if (! $res->successful() || $res->json('success') === false) {
            throw new CourierException('Biteship: ' . $this->error($res));
        }

        return collect($res->json('pricing') ?? [])
            ->filter(fn ($p) => $request->codAmount <= 0 || ! empty($p['available_for_cash_on_delivery']))
            ->map(fn ($p) => new Rate(
                id: $p['courier_code'] . ':' . $p['courier_service_code'],
                carrier: (string) ($p['courier_name'] ?? $p['courier_code']),
                service: (string) ($p['courier_service_name'] ?? $p['courier_service_code']),
                amount: (float) ($p['price'] ?? 0),
                currency: (string) ($p['currency'] ?? 'IDR'),
                days: preg_match('/(\d+)/', (string) ($p['duration'] ?? ''), $m) ? (int) $m[1] : null,
            ))->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rate = RatePicker::pick($this->rates($request), $request->service ?: $this->account->default_service);
        if (! $rate) {
            throw new CourierException('Biteship: no courier service matches "' . ($request->service ?: $this->account->default_service) . '".');
        }
        [$company, $type] = explode(':', $rate->id, 2);
        $from = Shipper::address($this->account);

        $body = array_filter([
            'origin_contact_name' => $from['name'],
            'origin_contact_phone' => $from['phone'],
            'origin_address' => trim($from['street'] . ', ' . $from['city'], ', '),
            'origin_postal_code' => (int) preg_replace('/\D/', '', (string) $this->account->setting('origin_postal_code')),
            'origin_collection_method' => $this->account->setting('collection_method') ?: 'pickup',
            'destination_contact_name' => $request->consigneeName,
            'destination_contact_phone' => $request->consigneePhone,
            'destination_contact_email' => $request->consigneeEmail,
            'destination_address' => trim($request->address . ', ' . $request->city, ', '),
            'destination_postal_code' => (int) preg_replace('/\D/', '', (string) $request->zip),
            'destination_note' => $request->notes ?: null,
            'destination_cash_on_delivery' => $request->codAmount > 0 ? (int) round($request->codAmount) : null,
            'destination_cash_on_delivery_type' => $request->codAmount > 0 ? '7_days' : null,
            'courier_company' => $company,
            'courier_type' => $type,
            'delivery_type' => 'now',
            // Unique per order; a suffix allows re-booking a cancelled sale.
            'reference_id' => mb_substr($request->reference, 0, 40) . '-' . Str::lower(Str::random(4)),
            'items' => $this->items($request),
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post(self::BASE . 'orders', $body);
        $o = $res->json() ?? [];
        if (! $res->successful() || ($o['success'] ?? false) !== true || empty($o['id'])) {
            throw new CourierException('Biteship: ' . $this->error($res));
        }

        $waybill = $o['courier']['waybill_id'] ?? null;

        return new BookingResult(
            trackingNumber: (string) ($waybill ?: $o['id']),
            courierReference: (string) $o['id'],
            charges: isset($o['price']) ? (float) $o['price'] : $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: $o['status'] ?? 'confirmed',
            meta: ['tracking_id' => $o['courier']['tracking_id'] ?? null, 'courier_link' => $o['courier']['link'] ?? null, 'company' => $company, 'type' => $type],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post(self::BASE . 'orders/' . rawurlencode((string) $shipment->courier_reference) . '/cancel', [
            'cancellation_reason_code' => 'others',
            'cancellation_reason' => 'Cancelled by merchant',
        ]);
        if (! $res->successful() || $res->json('success') === false) {
            throw new CourierException('Biteship: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $trackingId = $shipment->courier_meta['booking']['tracking_id'] ?? null;
        $res = $trackingId
            ? $this->client()->get(self::BASE . 'trackings/' . rawurlencode($trackingId))
            : $this->client()->get(self::BASE . 'orders/' . rawurlencode((string) $shipment->courier_reference));
        if (! $res->successful() || $res->json('success') === false) {
            throw new CourierException('Biteship: ' . $this->error($res));
        }

        $history = $trackingId ? ($res->json('history') ?? []) : ($res->json('courier.history') ?? []);
        $events = [];
        foreach ($history as $h) {
            $raw = $this->snake($h['status'] ?? null);
            $events[] = new TrackingEvent(
                status: self::STATUSES[$raw] ?? null,
                rawStatus: $raw ?: null,
                at: $this->time($h['updated_at'] ?? null),
                description: $h['note'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = $this->snake($res->json('status'));
        $status = self::STATUSES[$raw] ?? null;

        return new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED && $events ? $events[count($events) - 1]->at : null,
        );
    }

    /** order.status / order.price / order.waybill_id, matched by Biteship order id. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $header = $this->account->credential('webhook_header');
        $secret = $this->account->credential('webhook_secret');
        if ($header === '' || $secret === '') {
            throw new CourierException('Set the Biteship webhook header name and value on the courier account: status updates are rejected until they can be verified.');
        }
        if ($header !== '' && $secret !== '' && ! hash_equals($secret, (string) $request->header($header))) {
            throw new CourierException('Invalid Biteship webhook header.');
        }

        $orderId = $request->input('order_id');
        if (! $orderId) {
            return null;
        }

        $event = (string) $request->input('event');
        if ($event === 'order.price') {
            return new WebhookUpdate((string) $orderId, new TrackingResult(status: null, charges: is_numeric($request->input('price')) ? (float) $request->input('price') : null));
        }
        if ($event === 'order.waybill_id') {
            // The courier's waybill arrived after booking: it becomes the tracking number.
            $waybill = trim((string) $request->input('courier_waybill_id', $request->input('waybill_id', '')));

            return $waybill === '' ? null : new WebhookUpdate((string) $orderId, new TrackingResult(
                status: null,
                events: [new TrackingEvent(null, 'Waybill issued', now(), null, $waybill)],
                meta: ['tracking_number' => $waybill],
            ));
        }
        if ($event !== 'order.status') {
            return null;
        }

        $raw = $this->snake($request->input('status'));
        $status = self::STATUSES[$raw] ?? null;

        return new WebhookUpdate((string) $orderId, new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: [new TrackingEvent($status, $raw ?: null, now())],
            deliveredAt: $status === CourierStatus::DELIVERED ? now() : null,
        ));
    }

    // ------------------------------------------------------------------

    private function items(ShipmentRequest $request): array
    {
        return [[
            'name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100),
            // Biteship multiplies value and weight by quantity: send per-unit figures.
            'value' => max(1, (int) round(max($request->declaredValue, $request->codAmount, 1) / max(1, $request->pieces))),
            'quantity' => max(1, $request->pieces),
            'weight' => max(1, (int) round($request->weight * 1000 / max(1, $request->pieces))),
            'category' => $this->account->setting('item_category') ?: 'others',
        ] + Shipper::dimensions($this->account)];
    }

    /** "returnInTransit" → "return_in_transit". */
    private function snake(?string $status): string
    {
        return Str::snake(trim((string) $status));
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
        return $this->http()->withHeaders(['authorization' => $this->account->credential('api_key')]);
    }

    private function error(Response $res): string
    {
        return $res->json('error') ?: $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
