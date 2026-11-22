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
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Shadowfax Forward Logistics (India) — developer.shadowfax.in OpenAPI.
 * Static "Authorization: Token <token>" from the SFX 360 partner portal
 * (separate staging token). Orders return HTTP 200 even on failure, so the
 * "message" field decides. Weight in kg. Status callbacks use a per-client
 * template with no signature; the URL token identifies the account.
 */
class ShadowfaxDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://dale.shadowfax.in/api/';
    private const STAGING = 'https://dale.staging.shadowfax.in/api/';

    private const STATUSES = [
        'new' => CourierStatus::BOOKED, 'assigned_for_pickup' => CourierStatus::BOOKED, 'assigned_for_seller_pickup' => CourierStatus::BOOKED,
        'ofp' => CourierStatus::BOOKED, 'seller_initiated_delay' => CourierStatus::BOOKED, 'seller_not_contactable' => CourierStatus::BOOKED,
        'pickup_not_attempted' => CourierStatus::BOOKED, 'pickup_on_hold' => CourierStatus::BOOKED, 'qc_failed' => CourierStatus::BOOKED,
        'picked' => CourierStatus::PICKED_UP, 'received_from_client_warehouse' => CourierStatus::PICKED_UP,
        'recd_at_rev_hub' => CourierStatus::IN_TRANSIT, 'recd_at_fwd_hub' => CourierStatus::IN_TRANSIT, 'recd_at_fwd_dc' => CourierStatus::IN_TRANSIT,
        'assigned_for_delivery' => CourierStatus::IN_TRANSIT, 'pincode_updated' => CourierStatus::IN_TRANSIT, 'pending_inscan' => CourierStatus::IN_TRANSIT,
        'ewb_on_hold' => CourierStatus::IN_TRANSIT, 'reopen_ndr' => CourierStatus::IN_TRANSIT,
        'ofd' => CourierStatus::OUT_FOR_DELIVERY,
        'cid' => CourierStatus::FAILED_ATTEMPT, 'nc' => CourierStatus::FAILED_ATTEMPT, 'na' => CourierStatus::FAILED_ATTEMPT, 'on_hold' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED, 'partially_delivered' => CourierStatus::DELIVERED,
        'rts' => CourierStatus::RETURNING, 'rts_in_process' => CourierStatus::RETURNING, 'rto' => CourierStatus::RETURNING,
        'rts_nd' => CourierStatus::RETURNING, 'rto_nd' => CourierStatus::RETURNING,
        'rts_d' => CourierStatus::RETURNED, 'rto_d' => CourierStatus::RETURNED,
        'cancelled_by_customer' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'shadowfax',
            'name' => 'Shadowfax',
            'region' => 'India',
            'countries' => ['IN'],
            'website' => 'https://www.shadowfax.in',
            'tracking_url' => null,
            'description' => 'Shadowfax forward orders with COD, PDF labels, tracking and status callbacks.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'required' => true,
                    'help' => 'SFX 360 partner portal (shadowfax360.in). Staging and production tokens differ.'],
            ],
            'settings' => [
                ['key' => 'order_type', 'label' => 'Order type', 'type' => 'select', 'options' => [
                    ['value' => 'marketplace', 'label' => 'Marketplace (pickup from my address)'],
                    ['value' => 'warehouse', 'label' => 'Warehouse (Shadowfax-registered origin)'],
                ]],
                ['key' => 'sender_zip', 'label' => 'Pickup pincode', 'type' => 'text', 'required' => true],
                ['key' => 'sender_state', 'label' => 'Pickup state', 'type' => 'text'],
                ['key' => 'parcel_length', 'label' => 'Default parcel length (cm)', 'type' => 'number'],
                ['key' => 'parcel_width', 'label' => 'Default parcel width (cm)', 'type' => 'number'],
                ['key' => 'parcel_height', 'label' => 'Default parcel height (cm)', 'type' => 'number'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        // No dedicated test endpoint: a serviceability lookup proves the token.
        $res = $this->client()->get($this->base() . 'v1/clients/serviceability/', ['service' => 'customer_delivery', 'pincodes' => '110001']);
        if (! $res->successful() || ! is_array($res->json())) {
            return ConnectionResult::failed('Shadowfax: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to Shadowfax.');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $cod = $request->codAmount > 0;
        $value = round($request->declaredValue ?: $request->codAmount, 2);
        $type = $this->account->setting('order_type') === 'warehouse' ? 'warehouse' : 'marketplace';
        $origin = array_filter([
            'name' => mb_substr((string) ($this->account->pickup_contact ?: $this->account->name), 0, 100),
            'contact' => $this->phone($this->account->pickup_phone),
            'address_line_1' => (string) $this->account->pickup_address,
            'city' => (string) $this->account->pickup_city,
            'state' => $this->account->setting('sender_state') ?: null,
            'pincode' => (int) preg_replace('/\D+/', '', (string) $this->account->setting('sender_zip')),
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . 'v3/clients/orders/', [
            'order_type' => $type,
            'customer_details' => array_filter([
                'name' => mb_substr($request->consigneeName, 0, 100),
                'contact' => $this->phone($request->consigneePhone),
                'address_line_1' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'pincode' => (int) preg_replace('/\D+/', '', (string) $request->zip),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
            ], fn ($v) => $v !== null && $v !== ''),
            'order_details' => [
                'client_order_id' => mb_substr($request->reference, 0, 100),
                'product_value' => $value,
                'total_amount' => $value,
                'payment_mode' => $cod ? 'cod' : 'prepaid',
                'cod_amount' => $cod ? round($request->codAmount, 2) : 0,
                'actual_weight' => max(0.01, round($request->weight, 3)),
            ],
            'pickup_details' => ['pickup_type' => $type === 'warehouse' ? 'warehouse' : 'seller'] + $origin,
            'return_details' => ['return_type' => $type === 'warehouse' ? 'origin' : 'seller'] + $origin,
            'product_details' => [[
                'sku_name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 200),
                'sku_id' => mb_substr($request->reference, 0, 50),
                'price' => $value,
                'additional_details' => ['quantity' => $request->pieces],
            ]],
        ]);

        $awb = $res->json('data.awb_number');
        // A repeat of the same order reports the AWB it already has.
        if (! $awb && $res->json('message') === 'Failure' && $res->json('AWB') && is_string($res->json('errors')) && str_contains($res->json('errors'), 'already created')) {
            $awb = $res->json('AWB');
        }
        if (! $res->successful() || ! $awb) {
            throw new CourierException('Shadowfax: ' . $this->error($res));
        }

        return new BookingResult(trackingNumber: (string) $awb, status: CourierStatus::BOOKED, rawStatus: $res->json('data.status') ?: 'new',
            meta: array_filter(['sort_code' => $res->json('data.sort_code')]));
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'v4/clients/orders/' . rawurlencode((string) $shipment->tracking_number) . '/track/');
        if (! $res->successful() || $res->json('message') !== 'Success') {
            throw new CourierException('Shadowfax: ' . $this->error($res));
        }

        $events = [];
        foreach ((array) $res->json('tracking_details') as $t) {
            $code = (string) ($t['status_id'] ?? '');
            $events[] = new TrackingEvent($this->map($code, $t['status'] ?? null), (string) ($t['status'] ?? $code), $this->time($t['created'] ?? null),
                $t['location'] ?? null, $t['remarks'] ?? null);
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = (string) $res->json('order_details.status');
        $status = $this->map($raw, $res->json('order_details.status_display'));
        $last = end($events) ?: null;

        return new TrackingResult(
            status: $status,
            rawStatus: $res->json('order_details.status_display') ?: ($raw ?: null),
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? ($last?->at) : null,
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post($this->base() . 'v3/clients/orders/cancel/', [
            'request_id' => (string) $shipment->tracking_number,
            'cancel_remarks' => 'Cancelled by merchant',
        ]);
        if (! $res->successful() || (int) $res->json('responseCode', 200) !== 200) {
            throw new CourierException('Shadowfax: ' . $this->error($res));
        }
    }

    /** Signed PDF link (valid 2 days); only before pickup. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->post($this->base() . 'client/generate_label/', ['awb_number' => (string) $shipment->tracking_number, 'file_type' => 'pdf']);
        $url = $res->json('data.label_url');
        if (! $res->successful() || ! $url) {
            throw new CourierException('Shadowfax: ' . $this->error($res));
        }

        return ['url' => $url];
    }

    /** Template set at onboarding, e.g. {awb_number, client_order_id, status, status_id, remarks, current_location}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $awb = $request->input('awb_number') ?? $request->input('awb');
        if (! $awb) {
            return null;
        }

        $code = (string) ($request->input('status_id') ?? '');
        $raw = (string) ($request->input('status') ?? $code);
        $status = $this->map($code, $raw);
        $at = $this->time($request->input('last_updated') ?? $request->input('updated_date'));

        return new WebhookUpdate((string) $awb, new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: [new TrackingEvent($status, $raw ?: 'Update', $at, $request->input('current_location'), $request->input('remarks') ?? $request->input('cancellation_remarks'))],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    /** status_id (a code like "ofd") first, then the status text (code or display string). */
    private function map(?string $code, ?string $text = null): ?string
    {
        foreach ([$code, $text] as $v) {
            $key = strtolower(trim((string) $v));
            if ($key !== '' && isset(self::STATUSES[$key])) {
                return self::STATUSES[$key];
            }
        }

        return $text ? StatusText::map($text) : null;
    }

    /** 10-digit Indian mobile. */
    private function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function client()
    {
        return $this->http()->withHeaders(['Authorization' => 'Token ' . $this->account->credential('api_token')]);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value, 'Asia/Kolkata') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $errors = $res->json('errors');
        if (is_string($errors) && $errors !== '') {
            return $errors;
        }
        if (is_array($errors) && $errors) {
            return collect($errors)->map(fn ($v, $k) => (is_string($k) ? $k . ': ' : '') . (is_array($v) ? implode(' ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v)) : (string) $v))->implode('; ');
        }
        foreach (['responseMsg', 'message', 'detail'] as $key) {
            $v = $res->json($key);
            if (is_string($v) && $v !== '' && $v !== 'Failure') {
                return $v;
            }
        }
        if (is_array($res->json()) && $res->json()) {
            return mb_substr(json_encode($res->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300);
        }

        return 'HTTP ' . $res->status();
    }
}
