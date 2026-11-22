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
use App\Services\Couriers\Support\Shipper;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Ninja Van (SG, MY, TH, ID, VN, PH, MM) — Order API from the official
 * OpenAPI specs. https://api.ninjavan.co/{cc}, sandbox (always Singapore)
 * https://api-sandbox.ninjavan.co/sg. OAuth client credentials → Bearer
 * (cached). Orders: POST /4.2/orders; waybill PDF /2.0/reports/waybill
 * (separate access audit); cancel DELETE /2.2/orders/{tn} (Pending Pickup
 * only). Ninja Van treats webhooks as the tracking channel (the pull API is
 * gated and no longer in the spec): X-Ninjavan-Hmac-Sha256 =
 * base64(HMAC-SHA256(raw body, client secret)).
 */
class NinjaVanDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://api.ninjavan.co/';
    private const SANDBOX = 'https://api-sandbox.ninjavan.co/sg/';

    private const TIMEZONES = ['SG' => 'Asia/Singapore', 'MY' => 'Asia/Kuala_Lumpur', 'TH' => 'Asia/Bangkok', 'ID' => 'Asia/Jakarta',
        'VN' => 'Asia/Ho_Chi_Minh', 'PH' => 'Asia/Manila', 'MM' => 'Asia/Yangon'];

    private const EVENTS = [
        'pending pickup' => CourierStatus::BOOKED, 'driver dispatched for pickup' => CourierStatus::BOOKED,
        'pickup exception, pending reschedule' => CourierStatus::BOOKED, 'pickup exception, reattempt scheduled' => CourierStatus::BOOKED,
        'pickup exception, pending retrieval from pudo' => CourierStatus::BOOKED,
        'pickup exception, max attempts reached' => CourierStatus::CANCELLED,
        'picked up, in transit to origin hub' => CourierStatus::PICKED_UP, 'pending pickup, shipper dropoff' => CourierStatus::PICKED_UP,
        'arrived at origin hub' => CourierStatus::IN_TRANSIT, 'arrived at transit hub' => CourierStatus::IN_TRANSIT,
        'arrived at destination hub' => CourierStatus::IN_TRANSIT, 'in transit to next sorting hub' => CourierStatus::IN_TRANSIT,
        'on vehicle for delivery' => CourierStatus::OUT_FOR_DELIVERY, 'at pudo, pending customer collection' => CourierStatus::OUT_FOR_DELIVERY,
        'delivered, received by customer' => CourierStatus::DELIVERED, 'delivered, left at doorstep' => CourierStatus::DELIVERED,
        'delivered, collected by customer' => CourierStatus::DELIVERED,
        'delivery exception, return to sender initiated' => CourierStatus::RETURNING,
        'returned to sender' => CourierStatus::RETURNED,
        'international transit, returned to sender at origin facility' => CourierStatus::RETURNED,
        'international transit, returned to xb warehouse' => CourierStatus::RETURNED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'ninjavan',
            'name' => 'Ninja Van',
            'region' => 'Southeast Asia',
            'countries' => array_keys(self::TIMEZONES),
            'website' => 'https://www.ninjavan.co',
            'tracking_url' => null,
            'description' => 'Ninja Van orders with COD, scheduled pickups, waybills and signed status webhooks.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                    'help' => 'Ninja Dashboard → Settings → IT Settings. Sandbox and production keys differ.'],
                ['key' => 'client_secret', 'label' => 'Client key', 'type' => 'password', 'required' => true,
                    'help' => 'Also verifies the webhooks Ninja Van sends.'],
            ],
            'settings' => array_merge([
                ['key' => 'country', 'label' => 'Country', 'type' => 'select', 'required' => true, 'options' => array_map(
                    fn ($c) => ['value' => $c, 'label' => $c], array_keys(self::TIMEZONES)
                )],
                ['key' => 'service_level', 'label' => 'Service level', 'type' => 'select', 'options' => [
                    ['value' => 'Standard', 'label' => 'Standard'], ['value' => 'Express', 'label' => 'Express'],
                    ['value' => 'Nextday', 'label' => 'Next day'], ['value' => 'Sameday', 'label' => 'Same day'],
                ], 'help' => 'Leave empty for Standard.'],
                ['key' => 'pickup_required', 'label' => 'Ninja Van picks up from me', 'type' => 'switch',
                    'help' => 'Off = you drop parcels at a Ninja Point.'],
            ], array_values(array_filter(Shipper::fields(), fn ($f) => $f['key'] !== 'sender_country'))), // country comes from the setting above
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $this->token(true);
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }

        return ConnectionResult::ok('Connected to Ninja Van (' . $this->country() . ').');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $cc = $this->country();
        $tz = self::TIMEZONES[$cc];
        $from = Shipper::address($this->account);
        $d = Shipper::dimensions($this->account);
        $pickup = (bool) $this->account->setting('pickup_required');
        $today = now($tz)->toDateString();

        $sender = array_filter([
            'name' => mb_substr($from['name'], 0, 255),
            'phone_number' => $from['phone'] ?: null,
            'email' => $from['email'] ?: null,
            'address' => $this->address($cc, $from['street'], $from['city'], $from['state'], $from['zip']),
        ]);

        $job = array_filter([
            'is_pickup_required' => $pickup,
            'pickup_service_type' => $pickup ? 'Scheduled' : null,
            'pickup_service_level' => $pickup ? 'Standard' : null,
            'pickup_date' => $pickup ? $today : null,
            'pickup_timeslot' => $pickup ? ['start_time' => '09:00', 'end_time' => '18:00', 'timezone' => $tz] : null,
            'pickup_approximate_volume' => $pickup ? 'Less than 3 Parcels' : null,
            'pickup_address' => $pickup ? $sender : null,
            'delivery_start_date' => $today,
            'delivery_timeslot' => ['start_time' => '09:00', 'end_time' => '22:00', 'timezone' => $tz],
            'delivery_instructions' => $request->notes ? $this->ascii($request->notes) : null,
            'dimensions' => ['weight' => max(0.1, round($request->weight, 2)), 'length' => $d['length'], 'width' => $d['width'], 'height' => $d['height']],
            'cash_on_delivery' => $request->codAmount > 0 ? round($request->codAmount, 2) : null,
            'items' => [['item_description' => $this->ascii($request->itemsDescription ?: 'Goods'), 'quantity' => $request->pieces]],
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . '4.2/orders', [
            'service_type' => 'Parcel',
            'service_level' => $request->service && in_array($request->service, ['Standard', 'Express', 'Sameday', 'Nextday'], true)
                ? $request->service : ($this->account->setting('service_level') ?: 'Standard'),
            'reference' => ['merchant_order_number' => mb_substr($request->reference, 0, 255)],
            'from' => $sender,
            'to' => array_filter([
                'name' => $this->ascii(mb_substr($request->consigneeName, 0, 255)),
                'phone_number' => $request->consigneePhone,
                'email' => $request->consigneeEmail,
                'address' => $this->address($cc, $request->address, $request->city, (string) $request->state, (string) $request->zip),
            ]),
            'parcel_job' => $job,
        ]);
        $tn = $res->json('tracking_number');
        if (! $res->successful() || ! $tn) {
            throw new CourierException('Ninja Van: ' . $this->error($res));
        }

        return new BookingResult(trackingNumber: (string) $tn, status: CourierStatus::BOOKED, rawStatus: 'Pending Pickup');
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->delete($this->base() . '2.2/orders/' . rawurlencode((string) $shipment->tracking_number));
        if (! $res->successful()) {
            throw new CourierException('Ninja Van: ' . $this->error($res));
        }
    }

    /** Waybill PDF (needs Ninja Van's waybill access audit). */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->withHeaders(['Accept' => 'application/pdf'])->get($this->base() . '2.0/reports/waybill', [
            'tid' => (string) $shipment->tracking_number,
            'hide_shipper_details' => 'false',
        ]);
        if (! $res->successful() || ! str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            throw new CourierException('Ninja Van: ' . ($res->status() === 403 ? 'waybill access is not enabled for this account yet (ask your Ninja Van account manager).' : $this->error($res)));
        }

        return ['pdf' => $res->body()];
    }

    /** {tracking_id, event, status, timestamp, is_parcel_on_rts_leg, …}; one event per call. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $this->account->credential('client_secret'), true));
        if (! hash_equals($expected, (string) $request->header('X-Ninjavan-Hmac-Sha256'))) {
            throw new CourierException('Invalid Ninja Van webhook signature.');
        }

        $tn = $request->input('tracking_id');
        $event = (string) $request->input('event');
        if (! $tn || $event === '') {
            return null;
        }

        $key = mb_strtolower(trim($event));
        $status = self::EVENTS[$key] ?? null;
        if ($status === null) {
            $status = str_starts_with($key, 'international transit') || str_starts_with($key, 'return to shipper exception')
                ? (str_starts_with($key, 'return') ? CourierStatus::RETURNING : CourierStatus::IN_TRANSIT)
                : (str_starts_with($key, 'delivery exception') ? CourierStatus::FAILED_ATTEMPT : StatusText::map($event));
        }
        // Transit / delivery events on the return leg mean the parcel is coming back.
        if ($request->boolean('is_parcel_on_rts_leg') && in_array($status, [CourierStatus::PICKED_UP, CourierStatus::IN_TRANSIT, CourierStatus::OUT_FOR_DELIVERY, CourierStatus::FAILED_ATTEMPT], true)) {
            $status = CourierStatus::RETURNING;
        }
        if ($key === 'parcel measurements update') {
            $status = null;
        }
        $at = $this->time($request->input('timestamp'));

        return new WebhookUpdate((string) $tn, new TrackingResult(
            status: $status,
            rawStatus: $event,
            events: [new TrackingEvent($status, $event, $at, $request->input('arrived_at_origin_hub_information.hub'), $request->input('rts_reason'))],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    /** Country-specific address keys. */
    private function address(string $cc, string $line, string $city, string $state, string $zip): array
    {
        $base = ['address1' => $this->ascii(mb_substr($line, 0, 255)), 'country' => $cc];
        $extra = match ($cc) {
            'SG' => ['postcode' => $zip],
            'MY' => ['city' => $city, 'state' => $state, 'postcode' => $zip],
            'TH' => ['district' => $city, 'province' => $state, 'postcode' => $zip],
            'ID' => ['city' => $city, 'province' => $state, 'postcode' => $zip],
            'PH' => ['city' => $city, 'province' => $state, 'postcode' => $zip],
            'VN' => ['city' => $state ?: $city, 'district' => $city],
            'MM' => ['township' => $city, 'state' => $state, 'postcode' => $zip],
            default => ['city' => $city, 'postcode' => $zip],
        };

        return $base + array_filter($extra, fn ($v) => $v !== '');
    }

    /** Ninja Van rejects extended UTF characters (emoji, symbols). */
    private function ascii(string $value): string
    {
        return trim(preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value));
    }

    private function token(bool $fresh = false): string
    {
        $key = 'courier_ninjavan_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $this->country() . '|' . $this->account->credential('client_id') . '|' . $this->account->credential('client_secret'));
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        $res = $this->http()->post($this->base() . '2.0/oauth/access_token', [
            'client_id' => $this->account->credential('client_id'),
            'client_secret' => $this->account->credential('client_secret'),
            'grant_type' => 'client_credentials',
        ]);
        $token = $res->json('access_token');
        if (! $res->successful() || ! $token) {
            throw new CourierException('Ninja Van: ' . $this->error($res));
        }
        $ttl = max(60, ((int) $res->json('expires_in', 3600)) - 300);
        Cache::put($key, $token, now()->addSeconds($ttl));

        return $token;
    }

    private function client()
    {
        return $this->http()->withToken($this->token());
    }

    private function country(): string
    {
        $c = strtoupper((string) $this->account->setting('country'));

        return isset(self::TIMEZONES[$c]) ? $c : 'SG';
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE . strtolower($this->country()) . '/';
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
        $e = $res->json('error');
        if (is_array($e)) {
            $details = collect($e['details'] ?? [])->map(fn ($d) => trim(($d['field'] ?? '') . ' ' . ($d['message'] ?? '')))->filter()->implode('; ');

            return trim(($e['message'] ?? $e['title'] ?? '') . ($details ? " ({$details})" : '')) ?: ('HTTP ' . $res->status());
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
