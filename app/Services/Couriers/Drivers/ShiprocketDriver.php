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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Shiprocket (India) — external API v1, from Shiprocket's published Postman
 * collection. https://apiv2.shiprocket.in/v1/external/, login with an API
 * user (email + password) → Bearer token valid 10 days (cached). No
 * sandbox: every call is live. Booking = orders/create/adhoc → assign AWB
 * (chosen courier) → label → pickup request. Tracking by AWB; COD payout is
 * read from the order's remittance fields. Webhooks carry the security
 * token set in the Shiprocket panel in the x-api-key header.
 */
class ShiprocketDriver extends AbstractCourierDriver
{
    private const BASE = 'https://apiv2.shiprocket.in/v1/external/';

    /** Shipment status codes (shipment table). */
    private const CODES = [
        1 => CourierStatus::BOOKED, 2 => CourierStatus::BOOKED, 3 => CourierStatus::BOOKED, 4 => CourierStatus::BOOKED,
        5 => CourierStatus::BOOKED, 11 => CourierStatus::BOOKED, 13 => CourierStatus::BOOKED, 15 => CourierStatus::BOOKED,
        19 => CourierStatus::BOOKED, 20 => CourierStatus::BOOKED, 27 => CourierStatus::BOOKED, 52 => CourierStatus::BOOKED,
        42 => CourierStatus::PICKED_UP, 51 => CourierStatus::PICKED_UP,
        6 => CourierStatus::IN_TRANSIT, 18 => CourierStatus::IN_TRANSIT, 22 => CourierStatus::IN_TRANSIT, 38 => CourierStatus::IN_TRANSIT,
        39 => CourierStatus::IN_TRANSIT, 48 => CourierStatus::IN_TRANSIT, 49 => CourierStatus::IN_TRANSIT, 50 => CourierStatus::IN_TRANSIT,
        54 => CourierStatus::IN_TRANSIT, 55 => CourierStatus::IN_TRANSIT, 56 => CourierStatus::IN_TRANSIT, 57 => CourierStatus::IN_TRANSIT,
        17 => CourierStatus::OUT_FOR_DELIVERY,
        21 => CourierStatus::FAILED_ATTEMPT,
        7 => CourierStatus::DELIVERED, 23 => CourierStatus::DELIVERED, 26 => CourierStatus::DELIVERED, 43 => CourierStatus::DELIVERED,
        9 => CourierStatus::RETURNING, 40 => CourierStatus::RETURNING, 41 => CourierStatus::RETURNING, 46 => CourierStatus::RETURNING,
        75 => CourierStatus::RETURNING, 78 => CourierStatus::RETURNING,
        10 => CourierStatus::RETURNED, 14 => CourierStatus::RETURNED,
        // 16 = "Cancellation Requested": not final until Shiprocket confirms (8 / 45).
        8 => CourierStatus::CANCELLED, 45 => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'shiprocket',
            'name' => 'Shiprocket',
            'region' => 'India',
            'countries' => ['IN'],
            'website' => 'https://www.shiprocket.in',
            'tracking_url' => null,
            'description' => 'Delhivery, Blue Dart, Xpressbees, DTDC, Ekart and more through Shiprocket: compare couriers, COD booking, labels, pickups and remittance.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'pickup' => true,
            ],
            'credentials' => [
                ['key' => 'email', 'label' => 'API user email', 'type' => 'text', 'required' => true,
                    'help' => 'Shiprocket → Settings → API → Add New API User (not your main login).'],
                ['key' => 'password', 'label' => 'API user password', 'type' => 'password', 'required' => true],
                ['key' => 'webhook_token', 'label' => 'Webhook security token', 'type' => 'password',
                    'help' => 'Required for instant status updates. The token set on your Shiprocket webhook (sent as x-api-key).'],
            ],
            'settings' => array_merge([
                ['key' => 'pickup_location', 'label' => 'Pickup location name', 'type' => 'text', 'required' => true,
                    'help' => 'The pickup location nickname. "Test connection" lists them.'],
                ['key' => 'pickup_pincode', 'label' => 'Pickup pincode', 'type' => 'text', 'required' => true],
                ['key' => 'auto_pickup', 'label' => 'Request courier pickup after booking', 'type' => 'switch'],
                ['key' => 'sender_email', 'label' => 'Email when the customer has none', 'type' => 'text'],
            ], array_slice(Shipper::fields(), 5)),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $res = $this->client()->get(self::BASE . 'settings/company/pickup');
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }
        if (! $res->successful()) {
            return ConnectionResult::failed('Shiprocket: ' . $this->error($res));
        }

        $names = collect($res->json('data.shipping_address') ?? [])
            ->map(fn ($a) => ($a['pickup_location'] ?? '?') . ' (' . ($a['pin_code'] ?? '') . ')')->implode(', ');

        return ConnectionResult::ok('Connected to Shiprocket.' . ($names ? " Pickup locations: {$names}." : ''));
    }

    public function rates(ShipmentRequest $request): array
    {
        $d = Shipper::dimensions($this->account);
        $res = $this->client()->get(self::BASE . 'courier/serviceability/', [
            'pickup_postcode' => $this->account->setting('pickup_pincode'),
            'delivery_postcode' => $request->zip,
            'cod' => $request->codAmount > 0 ? 1 : 0,
            'weight' => max(0.01, round($request->weight, 2)),
            'length' => $d['length'], 'breadth' => $d['width'], 'height' => $d['height'],
            'declared_value' => round(max($request->declaredValue, $request->codAmount), 2),
        ]);
        if (! $res->successful() || ((int) $res->json('status', 200)) >= 400) {
            throw new CourierException('Shiprocket: ' . $this->error($res));
        }

        return collect($res->json('data.available_courier_companies') ?? [])
            ->filter(fn ($c) => $request->codAmount <= 0 || ! empty($c['cod']))
            ->map(fn ($c) => new Rate(
                id: (string) $c['courier_company_id'],
                carrier: (string) ($c['courier_name'] ?? ''),
                service: ! empty($c['is_surface']) ? 'Surface' : 'Air',
                amount: (float) ($c['rate'] ?? 0),
                currency: 'INR',
                days: isset($c['estimated_delivery_days']) && is_numeric($c['estimated_delivery_days']) ? (int) $c['estimated_delivery_days'] : null,
            ))->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rate = RatePicker::pick($this->rates($request), $request->service ?: $this->account->default_service);
        if (! $rate) {
            throw new CourierException('Shiprocket: no courier serves this pincode for "' . ($request->service ?: $this->account->default_service ?: 'cheapest') . '".');
        }

        $d = Shipper::dimensions($this->account);
        $total = round(max($request->codAmount, $request->declaredValue, 1), 2);
        $phone = substr(preg_replace('/\D/', '', $request->consigneePhone), -10);
        [$first, $last] = array_pad(explode(' ', trim($request->consigneeName), 2), 2, '');

        $order = $this->client()->post(self::BASE . 'orders/create/adhoc', array_filter([
            // Unique even across cancelled orders: suffix allows re-booking.
            'order_id' => mb_substr($request->reference, 0, 40) . '-' . Str::lower(Str::random(4)),
            'order_date' => now()->format('Y-m-d H:i'),
            'pickup_location' => (string) $this->account->setting('pickup_location'),
            'billing_customer_name' => $first,
            'billing_last_name' => $last ?: null,
            'billing_address' => $request->address,
            'billing_city' => mb_substr($request->city, 0, 30),
            'billing_pincode' => (int) preg_replace('/\D/', '', (string) $request->zip),
            'billing_state' => (string) $request->state,
            'billing_country' => 'India',
            'billing_email' => $request->consigneeEmail ?: ($this->account->setting('sender_email') ?: null),
            'billing_phone' => (int) $phone,
            'shipping_is_billing' => true,
            'order_items' => [['name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100), 'sku' => mb_substr($request->reference, 0, 50), 'units' => 1, 'selling_price' => $total]],
            'payment_method' => $request->codAmount > 0 ? 'COD' : 'Prepaid',
            'sub_total' => $total,
            'length' => max(0.6, $d['length']), 'breadth' => max(0.6, $d['width']), 'height' => max(0.6, $d['height']),
            'weight' => max(0.01, round($request->weight, 2)),
            'comment' => $request->notes ?: null,
        ], fn ($v) => $v !== null && $v !== ''));

        $shipmentId = $order->json('shipment_id');
        if (! $order->successful() || ! $shipmentId) {
            throw new CourierException('Shiprocket: ' . $this->error($order));
        }

        $awb = $this->client()->post(self::BASE . 'courier/assign/awb', ['shipment_id' => $shipmentId, 'courier_id' => (int) $rate->id]);
        $awbCode = $awb->json('response.data.awb_code');
        if (! $awb->successful() || (int) $awb->json('awb_assign_status') !== 1 || ! $awbCode) {
            throw new CourierException('Shiprocket order ' . $order->json('order_id') . ' was created but no AWB was assigned: ' . $this->error($awb) . ' Assign it in the Shiprocket panel.');
        }

        $labelUrl = null;
        try {
            $label = $this->client()->post(self::BASE . 'courier/generate/label', ['shipment_id' => [$shipmentId]]);
            $labelUrl = (int) $label->json('label_created') === 1 ? $label->json('label_url') : null;
            if ($this->account->setting('auto_pickup')) {
                $this->client()->post(self::BASE . 'courier/generate/pickup', ['shipment_id' => [$shipmentId]]);
            }
        } catch (\Throwable $e) {
            // Label / pickup can be redone from Shiprocket; the booking stands.
        }

        return new BookingResult(
            trackingNumber: (string) $awbCode,
            courierReference: (string) $shipmentId,
            labelUrl: $labelUrl,
            charges: $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: 'AWB Assigned',
            meta: ['sr_order_id' => $order->json('order_id'), 'courier' => $awb->json('response.data.courier_name') ?? $rate->carrier],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post(self::BASE . 'orders/cancel/shipment/awbs', ['awbs' => [(string) $shipment->tracking_number]]);
        if (! $res->successful()) {
            throw new CourierException('Shiprocket: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get(self::BASE . 'courier/track/awb/' . rawurlencode((string) $shipment->tracking_number));
        $data = $res->json('tracking_data') ?? [];
        if (! $res->successful() || (int) ($data['track_status'] ?? 0) !== 1) {
            throw new CourierException('Shiprocket: ' . ($data['error'] ?? $this->error($res)));
        }

        $events = $this->scans($data['shipment_track_activities'] ?? []);
        $code = isset($data['shipment_status']) ? (int) $data['shipment_status'] : null;
        $status = $code !== null ? (self::CODES[$code] ?? null) : null;
        $track = $data['shipment_track'][0] ?? [];

        [$codStatus, $codRef, $codAt] = $status === CourierStatus::DELIVERED ? $this->remittance($shipment) : [null, null, null];

        return new TrackingResult(
            status: $status,
            rawStatus: $track['current_status'] ?? ($code !== null ? "Status {$code}" : null),
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($track['delivered_date'] ?? null) : null,
            codStatus: $codStatus,
            codReference: $codRef,
            codRemittedAt: $codAt,
        );
    }

    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        $res = $this->client()->post(self::BASE . 'courier/generate/label', ['shipment_id' => [(int) $shipment->courier_reference]]);
        if (! $res->successful() || (int) $res->json('label_created') !== 1) {
            throw new CourierException('Shiprocket: ' . $this->error($res));
        }

        return ['url' => $res->json('label_url')];
    }

    /** Webhook: {awb, shipment_status_id, current_status, scans[], current_timestamp "dd mm yyyy H:i:s", …}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $token = $this->account->credential('webhook_token');
        if ($token === '') {
            throw new CourierException('Set the Shiprocket webhook security token on the courier account: status updates are rejected until they can be verified.');
        }
        if ($token !== '' && ! hash_equals($token, (string) $request->header('x-api-key'))) {
            throw new CourierException('Invalid Shiprocket webhook token.');
        }

        $awb = $request->input('awb');
        if (! $awb) {
            return null;
        }

        $code = is_numeric($request->input('shipment_status_id')) ? (int) $request->input('shipment_status_id') : null;
        $status = $code !== null ? (self::CODES[$code] ?? null) : null;

        return new WebhookUpdate((string) $awb, new TrackingResult(
            status: $status,
            rawStatus: $request->input('shipment_status') ?: $request->input('current_status'),
            events: $this->scans((array) $request->input('scans', [])),
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($request->input('current_timestamp')) : null,
        ));
    }

    // ------------------------------------------------------------------

    private function scans(array $scans): array
    {
        $events = [];
        foreach ($scans as $s) {
            if (! is_array($s)) {
                continue;
            }
            $label = $s['sr-status-label'] ?? null;
            $code = isset($s['sr-status']) && is_numeric($s['sr-status']) ? (int) $s['sr-status'] : null;
            $events[] = new TrackingEvent(
                status: $code !== null ? (self::CODES[$code] ?? null) : null,
                rawStatus: $label && $label !== 'NA' ? $label : ($s['status'] ?? null),
                at: $this->time($s['date'] ?? null),
                location: $s['location'] ?? null,
                description: $s['activity'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        return $events;
    }

    /** COD payout from the order's remittance fields. */
    private function remittance(Shipment $shipment): array
    {
        $orderId = $shipment->courier_meta['booking']['sr_order_id'] ?? null;
        if (! $orderId) {
            return [null, null, null];
        }
        try {
            $res = $this->client()->get(self::BASE . 'orders/show/' . $orderId);
            $o = $res->json('data') ?? [];
            if (! $res->successful() || empty($o['remittance_utr'])) {
                return [null, null, null];
            }

            return ['remitted', (string) $o['remittance_utr'], $this->time($o['remittance_date'] ?? null)];
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    private function token(): string
    {
        $key = 'courier_shiprocket_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->credential('email') . '|' . $this->account->credential('password'));

        return Cache::get($key) ?? (function () use ($key) {
            $res = $this->http()->post(self::BASE . 'auth/login', [
                'email' => $this->account->credential('email'),
                'password' => $this->account->credential('password'),
            ]);
            $token = $res->json('token');
            if (! $res->successful() || ! $token) {
                throw new CourierException('Shiprocket: login failed (' . $this->error($res) . ').');
            }
            Cache::put($key, $token, now()->addDays(9));

            return $token;
        })();
    }

    private function client()
    {
        return $this->http()->withToken($this->token());
    }

    /** Shiprocket mixes ISO dates and "23 05 2023 11:43:52". */
    private function time(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        if (preg_match('/^\d{2} \d{2} \d{4} \d{2}:\d{2}:\d{2}$/', trim($value))) {
            return Carbon::createFromFormat('d m Y H:i:s', trim($value));
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $errors = $res->json('errors');
        if (is_array($errors) && $errors) {
            return collect($errors)->flatten()->implode('; ');
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
