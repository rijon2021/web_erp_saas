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
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Steadfast Courier (Bangladesh), from Steadfast's own Laravel package and
 * WordPress plugin. https://portal.packzy.com/api/v1, headers Api-Key +
 * Secret-Key (repeated bad keys lock the IP out — never retry auth failures).
 * Booking needs no city/zone: routing is by address. Statuses ending in
 * "_approval_pending" are rider reports awaiting confirmation. No cancel or
 * label endpoint (Stocky prints the label). Webhooks carry the panel's
 * "Auth Token" as Authorization: Bearer.
 */
class SteadfastDriver extends AbstractCourierDriver
{
    private const BASE = 'https://portal.packzy.com/api/v1/';

    private const STATUSES = [
        'in_review' => CourierStatus::BOOKED,
        'pending' => CourierStatus::IN_TRANSIT,
        'hold' => CourierStatus::FAILED_ATTEMPT,
        'delivered_approval_pending' => CourierStatus::OUT_FOR_DELIVERY,
        'partial_delivered_approval_pending' => CourierStatus::OUT_FOR_DELIVERY,
        'cancelled_approval_pending' => CourierStatus::RETURNING,
        'delivered' => CourierStatus::DELIVERED,
        'partial_delivered' => CourierStatus::DELIVERED,
        // In Steadfast "cancelled" means the delivery failed and the parcel comes back.
        'cancelled' => CourierStatus::RETURNING,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'steadfast',
            'name' => 'Steadfast Courier',
            'region' => 'Bangladesh',
            'countries' => ['BD'],
            'website' => 'https://steadfast.com.bd',
            'tracking_url' => null,
            'description' => 'COD deliveries across Bangladesh: booking by address, tracking and instant status webhooks.',
            'capabilities' => [
                'booking' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Steadfast panel → API. Several wrong attempts lock access for an hour.'],
                ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'required' => true],
                ['key' => 'webhook_token', 'label' => 'Webhook auth token', 'type' => 'password',
                    'help' => 'Required for instant status updates. The "Auth Token (Bearer)" set with your Callback URL in the Steadfast panel.'],
            ],
            'settings' => [
                ['key' => 'point_delivery', 'label' => 'Customer collects from a Steadfast point', 'type' => 'switch'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        // One call only: repeated failures lock the account out.
        $res = $this->client()->get(self::BASE . 'get_balance');
        if (! $this->ok($res)) {
            return ConnectionResult::failed('Steadfast: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to Steadfast (balance ' . $res->json('current_balance') . ' BDT).');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $invoice = mb_substr($request->reference, 0, 90) . '-' . Str::lower(Str::random(4));
        $res = $this->client()->post(self::BASE . 'create_order', array_filter([
            'invoice' => $invoice,
            'recipient_name' => mb_substr($request->consigneeName, 0, 100),
            'recipient_phone' => Phone::bd($request->consigneePhone),
            'recipient_email' => $request->consigneeEmail,
            'recipient_address' => mb_substr(trim($request->address . ', ' . $request->city, ', '), 0, 250),
            'cod_amount' => (int) round($request->codAmount),
            'note' => $request->notes ? mb_substr($request->notes, 0, 480) : null,
            'item_description' => $request->itemsDescription ? mb_substr($request->itemsDescription, 0, 255) : null,
            'total_lot' => $request->pieces,
            'delivery_type' => $this->account->setting('point_delivery') ? 1 : 0,
        ], fn ($v) => $v !== null && $v !== ''));

        $c = $res->json('consignment') ?? [];
        if (! $this->ok($res) || empty($c['tracking_code'])) {
            throw new CourierException('Steadfast: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) $c['tracking_code'],
            courierReference: (string) ($c['consignment_id'] ?? ''),
            status: CourierStatus::BOOKED,
            rawStatus: $c['status'] ?? 'in_review',
            meta: ['invoice' => $invoice],
        );
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $path = $shipment->courier_reference
            ? 'status_by_cid/' . rawurlencode((string) $shipment->courier_reference)
            : 'status_by_trackingcode/' . rawurlencode((string) $shipment->tracking_number);
        $res = $this->client()->get(self::BASE . $path);
        if (! $this->ok($res)) {
            throw new CourierException('Steadfast: ' . $this->error($res));
        }

        $raw = (string) $res->json('delivery_status');

        return new TrackingResult(
            status: self::STATUSES[$raw] ?? null,
            rawStatus: $raw ?: null,
            // No time from Steadfast: null keeps the event hash stable, so polls add no duplicates.
            events: $raw !== '' ? [new TrackingEvent(self::STATUSES[$raw] ?? null, $raw, null)] : [],
        );
    }

    /**
     * Callback with Authorization: Bearer <panel token>. delivery_status
     * notifications carry status; tracking_update ones only a message.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $token = $this->account->credential('webhook_token');
        if ($token === '') {
            throw new CourierException('Set the Steadfast webhook auth token on the courier account: status updates are rejected until they can be verified.');
        }
        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            throw new CourierException('Invalid Steadfast webhook token.');
        }

        $id = $request->input('consignment_id');
        if (! $id) {
            return null;
        }

        $raw = (string) $request->input('status');
        $status = $raw !== '' ? (self::STATUSES[$raw] ?? null) : null;
        $message = $request->input('tracking_message');
        $at = $this->time($request->input('updated_at'));

        return new WebhookUpdate((string) $id, new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: [new TrackingEvent($status, $raw ?: 'Update', $at, null, $message)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    public function webhookAck(): array
    {
        return [200, ['status' => 'success', 'message' => 'Webhook received successfully.']];
    }

    // ------------------------------------------------------------------

    private function ok(Response $res): bool
    {
        return $res->successful() && (int) $res->json('status', 200) === 200;
    }

    private function time(?string $value): ?\Carbon\Carbon
    {
        try {
            return $value ? \Carbon\Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function client()
    {
        return $this->http()->withHeaders([
            'Api-Key' => $this->account->credential('api_key'),
            'Secret-Key' => $this->account->credential('secret_key'),
        ]);
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
