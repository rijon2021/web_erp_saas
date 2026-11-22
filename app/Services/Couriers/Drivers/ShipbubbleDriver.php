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
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\RatePicker;
use App\Services\Couriers\Support\Shipper;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Shipbubble (shipbubble.com, Nigeria), from docs.shipbubble.com and checked
 * against the sandbox. One host for both modes; the key decides
 * (sb_sandbox_… / sb_prod_…), header `Authorization: Bearer <key>`.
 * Both addresses are validated first (address/validate → address_code, cached);
 * fetch_rates returns a single-use request_token plus courier_id/service_code
 * per option; labels books one and charges the Shipbubble wallet. Rate ids are
 * "token|courier_id|service_code"; book() re-quotes and matches courier +
 * service, because a token dies after one booking (or 7 days).
 * Cancel works only before the processing date. COD per courier
 * (is_cod_available). No label document in practice (Stocky prints it).
 * Webhooks: `x-ship-signature` = HMAC-SHA512 of the body with the API key.
 */
class ShipbubbleDriver extends AbstractCourierDriver
{
    private const BASE = 'https://api.shipbubble.com/v1/';

    /** Shipbubble's local time (rate ETAs, the 6 PM cut-off). */
    private const TZ = 'Africa/Lagos';

    /** Order statuses from the sandbox webhook simulator; package_status wording falls back to StatusText. */
    private const STATUSES = [
        'pending' => CourierStatus::BOOKED,
        'confirmed' => CourierStatus::BOOKED,
        'picked_up' => CourierStatus::PICKED_UP,
        'in_transit' => CourierStatus::IN_TRANSIT,
        'completed' => CourierStatus::DELIVERED,
        'delivered' => CourierStatus::DELIVERED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    /** Sandbox category names; ids are looked up per account (labels/categories). */
    private const CATEGORIES = [
        'Light weight items', 'Fashion wears', 'Electronics and gadgets', 'Health and beauty', 'Groceries',
        'Dry food and supplements', 'Hot food', 'Medical supplies', 'Furniture and fittings', 'Machinery',
        'Sensitive items (ATM cards, documents)',
    ];

    public static function meta(): array
    {
        return [
            'code' => 'shipbubble',
            'name' => 'Shipbubble',
            'region' => 'Nigeria',
            'countries' => ['NG'],
            'website' => 'https://shipbubble.com',
            'tracking_url' => 'https://trackshipment.shipbubble.com/shipment/{tracking}',
            'description' => 'Compare and book Nigerian and international couriers through Shipbubble, paid from your Shipbubble wallet, with cash on delivery where the courier offers it.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'From your Shipbubble dashboard. sb_sandbox_… for test mode, sb_prod_… for live.'],
            ],
            'settings' => [
                ['key' => 'sender_email', 'label' => 'Pickup email', 'type' => 'text', 'required' => true],
                ['key' => 'sender_state', 'label' => 'Pickup state', 'type' => 'text', 'help' => 'e.g. Lagos. Helps Shipbubble find the pickup address.'],
                ['key' => 'sender_country', 'label' => 'Pickup country', 'type' => 'text', 'help' => 'Empty = Nigeria.'],
                ['key' => 'fallback_email', 'label' => 'Email when the customer has none', 'type' => 'text',
                    'help' => 'Shipbubble needs a receiver email. Empty = the pickup email.'],
                ['key' => 'item_category', 'label' => 'Package category', 'type' => 'select',
                    'options' => array_map(fn ($c) => ['value' => $c, 'label' => $c], self::CATEGORIES),
                    'help' => 'Couriers quote by category. Empty = Light weight items.'],
                ['key' => 'service_type', 'label' => 'Service type', 'type' => 'select', 'options' => [
                    ['value' => 'pickup', 'label' => 'Courier picks up'],
                    ['value' => 'dropoff', 'label' => 'I drop off at a station'],
                ], 'help' => 'Empty = show both.'],
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

        $key = $this->account->credential('api_key');
        if (str_starts_with($key, 'sb_sandbox_') && ! $this->account->isTestMode()) {
            return ConnectionResult::failed('This is a sandbox key (sb_sandbox_…): switch the account to test mode or use your sb_prod_ key.');
        }
        if (str_starts_with($key, 'sb_prod_') && $this->account->isTestMode()) {
            return ConnectionResult::failed('This is a live key (sb_prod_…): switch the account to live mode or use your sb_sandbox_ key.');
        }

        $res = $this->client()->get(self::BASE . 'shipping/wallet/balance');
        if (! $this->ok($res)) {
            return ConnectionResult::failed('Shipbubble: ' . $this->error($res));
        }
        $balance = $res->json('data.balance');
        $message = 'Connected to Shipbubble.' . (is_numeric($balance)
            ? ' Wallet balance: ' . number_format((float) $balance, 2) . ' ' . ($res->json('data.currency') ?: 'NGN') . '.'
            : '');

        // Every quote starts from the pickup address: surface a bad one here, not as missing checkout rates.
        try {
            $this->senderCode();
            $message .= ' Pickup address verified.';
        } catch (CourierException $e) {
            $message .= ' Pickup address not verified yet — ' . preg_replace('/^Shipbubble:?\s*/', '', $e->getMessage());
        }

        return ConnectionResult::ok($message);
    }

    public function rates(ShipmentRequest $request): array
    {
        // Also quoted live at checkout, so don't hold a shopper much longer than other calls.
        $res = $this->client()->timeout(30)->post(self::BASE . 'shipping/fetch_rates', $this->payload($request));
        $token = (string) ($res->json('data.request_token') ?? '');
        if (! $this->ok($res) || $token === '') {
            throw new CourierException('Shipbubble: ' . $this->error($res));
        }

        $rates = collect($res->json('data.couriers') ?? [])
            ->filter(fn ($c) => is_array($c) && isset($c['total']) && ($c['courier_id'] ?? '') !== '' && ($c['service_code'] ?? '') !== '')
            // A COD booking can only go to a courier that collects cash.
            ->filter(fn ($c) => $request->codAmount <= 0 || ! empty($c['is_cod_available']))
            ->map(function ($c) use ($token) {
                $station = $c['dropoff_station']['name'] ?? null;

                return new Rate(
                    id: $token . '|' . $c['courier_id'] . '|' . $c['service_code'],
                    carrier: (string) ($c['courier_name'] ?? $c['courier_id']),
                    service: ($c['service_type'] ?? '') === 'dropoff' ? 'Drop-off' . ($station ? ' at ' . $station : '') : 'Pickup',
                    amount: (float) $c['total'],
                    currency: (string) ($c['currency'] ?? 'NGN'),
                    days: $this->days($c['delivery_eta_time'] ?? null),
                    meta: [
                        'request_token' => $token,
                        'courier_id' => (string) $c['courier_id'],
                        'service_code' => (string) $c['service_code'],
                        // Stable across quotes: live checkout rates store it and booking picks the same service by it.
                        'servicelevel' => $c['courier_id'] . ':' . $c['service_code'],
                        // What Shipbubble suggests charging the customer (differs from total on connected accounts).
                        'checkout_amount' => isset($c['rate_card_amount']) && is_numeric($c['rate_card_amount']) ? (float) $c['rate_card_amount'] : null,
                        'cod' => ! empty($c['is_cod_available']),
                        'eta' => $c['delivery_eta'] ?? null,
                    ],
                );
            })->values()->all();

        if (! $rates && $request->codAmount > 0 && $res->json('data.couriers')) {
            throw new CourierException('Shipbubble: no courier offers cash on delivery on this route — book it prepaid or choose another courier account.');
        }

        return $rates;
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rates = $this->rates($request);
        $pref = trim((string) ($request->service ?: ($this->account->default_service ?: '')));
        // A rate picked in "Compare rates" carries an older token: match the fresh quote by courier + service.
        if (substr_count($pref, '|') >= 2) {
            [, $courierId, $serviceCode] = explode('|', $pref, 3);
            $rate = collect($rates)->first(fn (Rate $r) => $r->meta['courier_id'] === $courierId && $r->meta['service_code'] === $serviceCode);
            if (! $rate) {
                throw new CourierException('Shipbubble: the chosen courier no longer offers this route — compare rates again.');
            }
        } else {
            $rate = RatePicker::pick($rates, $pref ?: null);
        }
        if (! $rate) {
            throw new CourierException($rates ? 'Shipbubble: no rate matches the chosen service.' : 'Shipbubble: no courier offers this route.');
        }

        $res = $this->client()->post(self::BASE . 'shipping/labels', array_filter([
            'request_token' => $rate->meta['request_token'],
            'courier_id' => $rate->meta['courier_id'],
            'service_code' => $rate->meta['service_code'],
            'is_cod_label' => $request->codAmount > 0 ? true : null,
        ], fn ($v) => $v !== null));

        $orderId = (string) ($res->json('data.order_id') ?? '');
        if (! $this->ok($res) || $orderId === '') {
            throw new CourierException('Shipbubble: ' . $this->error($res));
        }
        $fee = $res->json('data.payment.shipping_fee');

        return new BookingResult(
            trackingNumber: $orderId,
            charges: is_numeric($fee) ? (float) $fee : $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: 'pending (' . $rate->carrier . ')',
            meta: array_filter([
                'carrier' => $rate->carrier,
                'courier_id' => $rate->meta['courier_id'],
                'service_code' => $rate->meta['service_code'],
                'tracking_url' => $res->json('data.tracking_url'),
                'message' => $res->json('message'),
            ]),
        );
    }

    /** Only while the processing date has not passed. */
    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post(self::BASE . 'shipping/labels/cancel/' . rawurlencode((string) $shipment->tracking_number));
        if (! $this->ok($res)) {
            $msg = $this->error($res);
            throw new CourierException(stripos($msg, 'already processed') !== false
                ? 'Shipbubble: the shipment is already being processed and can no longer be cancelled.'
                : 'Shipbubble: ' . $msg);
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $orderId = (string) $shipment->tracking_number;
        $res = $this->client()->get(self::BASE . 'shipping/labels/list/' . rawurlencode($orderId));
        // The sandbox answers data: [...]; the docs show data.results.
        $list = $res->json('data.results') ?? $res->json('data');
        if (! $this->ok($res) || ! is_array($list)) {
            throw new CourierException('Shipbubble: ' . $this->error($res));
        }
        $data = collect($list)->first(fn ($o) => is_array($o) && ($o['order_id'] ?? null) === $orderId);
        if (! $data) {
            throw new CourierException('Shipbubble: shipment ' . $orderId . ' not found.');
        }

        return $this->result($data);
    }

    /**
     * Shipment events carry the same order object as tracking (status,
     * package_status with times, courier.tracking_code). Wallet events have
     * no order and are acknowledged without changes.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $signature = trim((string) $request->header('x-ship-signature'));
        $key = $this->account->credential('api_key');
        $raw = hash_hmac('sha512', $request->getContent(), $key, true);
        // The docs don't say hex or base64: accept either.
        if ($signature === '' || $key === '' || ! (hash_equals(bin2hex($raw), strtolower($signature)) || hash_equals(base64_encode($raw), $signature))) {
            throw new CourierException('Invalid Shipbubble webhook signature.');
        }

        $data = $request->input('data');
        $data = is_array($data) && isset($data['order_id']) ? $data : $request->all();
        $orderId = trim((string) ($data['order_id'] ?? ''));
        if ($orderId === '' || ! str_starts_with((string) $request->input('event', 'shipment.'), 'shipment.')) {
            return null;
        }

        $event = (string) $request->input('event', '');
        if ($event === 'shipment.cancelled') {
            $data['status'] = 'cancelled';
        }
        if ($event === 'shipment.cod.remitted') {
            $data['is_cod_remitted'] = true;
        }

        return new WebhookUpdate($orderId, $this->result($data));
    }

    // ------------------------------------------------------------------

    /** One order object (tracking list or webhook) → TrackingResult. */
    private function result(array $data): TrackingResult
    {
        $events = [];
        foreach ($data['package_status'] ?? [] as $p) {
            $raw = trim((string) ($p['status'] ?? ''));
            if ($raw !== '') {
                $events[] = new TrackingEvent(status: $this->map($raw), rawStatus: $raw, at: $this->time($p['datetime'] ?? null));
            }
        }
        // Courier scan lines; the shape is undocumented (empty in every sample).
        foreach ($data['events'] ?? [] as $e) {
            if (! is_array($e)) {
                continue;
            }
            $raw = trim((string) ($e['status'] ?? $e['description'] ?? $e['message'] ?? ''));
            if ($raw !== '') {
                $events[] = new TrackingEvent(
                    status: $this->map($raw),
                    rawStatus: $raw,
                    at: $this->time($e['datetime'] ?? $e['date'] ?? $e['time'] ?? null),
                    location: isset($e['location']) && is_string($e['location']) ? $e['location'] : null,
                );
            }
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = trim((string) ($data['status'] ?? ''));
        $status = $raw !== '' ? $this->map($raw) : null;
        $deliveredAt = null;
        foreach ($events as $e) {
            if ($e->status === CourierStatus::DELIVERED) {
                $deliveredAt = $e->at;
            }
        }

        return new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: $events,
            deliveredAt: $deliveredAt,
            codStatus: ! empty($data['is_cod_remitted']) ? 'remitted' : null,
        );
    }

    /** fetch_rates body: validated address codes, one item line, parcel box. */
    private function payload(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $senderEmail = $from['email'] ?: (string) $this->account->setting('fallback_email');
        $sender = $this->senderCode();

        $country = $request->countryCode('NG');
        $receiver = $this->addressCode(
            $request->consigneeName,
            $request->consigneeEmail ?: ($this->account->setting('fallback_email') ?: $senderEmail),
            // A checkout quote can come before the shopper typed a phone.
            trim($request->consigneePhone) !== '' ? $request->consigneePhone : $from['phone'],
            [$request->address, $request->city, (string) $request->state, $country === 'NG' ? 'Nigeria' : (string) ($request->country ?: $country)],
            $country,
            $request->latitude,
            $request->longitude,
        );

        $pieces = max(1, $request->pieces);
        // A COD label has no separate amount: the courier collects the package value,
        // so it must be what is due — not the sale total of a part-paid sale.
        $value = $request->codAmount > 0 ? $request->codAmount : max($request->declaredValue, 0);
        $dims = $request->dimensions() ?? Shipper::dimensions($this->account);
        $lagos = now(self::TZ);

        return array_filter([
            'sender_address_code' => $sender,
            'reciever_address_code' => $receiver, // sic — Shipbubble's spelling
            // Bookings after 6 PM Lagos time are processed the next day anyway.
            'pickup_date' => ($lagos->hour >= 18 ? $lagos->copy()->addDay() : $lagos)->toDateString(),
            'category_id' => $this->categoryId(),
            'package_items' => [[
                'name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 255),
                'description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 255),
                'unit_weight' => max(0.01, round($request->weight / $pieces, 2)),
                'unit_amount' => max(1, round($value / $pieces, 2)),
                'quantity' => $pieces,
            ]],
            'package_dimension' => [
                'length' => max(1, (float) $dims['length']),
                'width' => max(1, (float) $dims['width']),
                'height' => max(1, (float) $dims['height']),
            ],
            'service_type' => $this->account->setting('service_type') ?: null,
            'delivery_instructions' => $request->notes !== '' ? mb_substr($request->notes, 0, 250) : null,
        ], fn ($v) => $v !== null);
    }

    /** The account's pickup address as a Shipbubble address code. */
    private function senderCode(): int
    {
        $from = Shipper::address($this->account);
        $email = $from['email'] ?: (string) $this->account->setting('fallback_email');
        if (trim($from['phone']) === '' || $email === '') {
            throw new CourierException('Shipbubble: set the pickup phone and pickup email on the courier account.');
        }
        $country = (string) ($this->account->setting('sender_country') ?: 'Nigeria');

        return $this->addressCode($from['name'], $email, $from['phone'], [$from['street'], $from['city'], $from['state'], $country], 'NG', suffix: 'Store');
    }

    /** address/validate → address_code, cached per tenant + exact input for 30 days. */
    private function addressCode(string $name, string $email, string $phone, array $parts, string $country, ?float $lat = null, ?float $lng = null, string $suffix = 'Customer'): int
    {
        $address = implode(', ', array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));
        $body = array_filter([
            'name' => $this->fullName($name, $suffix),
            'email' => trim($email),
            'phone' => $country === 'NG' ? Phone::ng($phone) : '+' . preg_replace('/\D+/', '', $phone),
            'address' => mb_substr($address, 0, 255),
            'latitude' => $lat,
            'longitude' => $lng,
        ], fn ($v) => $v !== null && $v !== '');

        $key = 'courier_shipbubble_addr:' . (tenant('id') ?? 'central') . ':' . md5($this->account->credential('api_key') . json_encode($body));
        if ($code = Cache::get($key)) {
            return (int) $code;
        }

        $res = $this->client()->post(self::BASE . 'shipping/address/validate', $body);
        $code = (int) ($res->json('data.address_code') ?? 0);
        if (! $this->ok($res) || $code <= 0) {
            throw new CourierException('Shipbubble could not verify the address "' . $address . '": ' . $this->error($res));
        }
        Cache::put($key, $code, now()->addDays(30));

        return $code;
    }

    /**
     * Shipbubble wants a first and last name without digits or symbols
     * (apostrophes and hyphens pass, as in O'Brien-Obi). One word gets $suffix.
     */
    private function fullName(string $name, string $suffix): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\p{L}\s'-]+/u", ' ', $name)));
        if ($clean === '') {
            $clean = $suffix;
        }

        return mb_substr(str_contains($clean, ' ') ? $clean : $clean . ' ' . $suffix, 0, 100);
    }

    /** The account's category name → id from labels/categories (cached a day). */
    private function categoryId(): int
    {
        $wanted = mb_strtolower((string) ($this->account->setting('item_category') ?: 'Light weight items'));
        $key = 'courier_shipbubble_cat:' . md5($this->account->credential('api_key'));
        $list = Cache::get($key);
        if (! is_array($list)) {
            $res = $this->client()->get(self::BASE . 'shipping/labels/categories');
            $list = $res->json('data');
            if (! $this->ok($res) || ! is_array($list) || ! $list) {
                throw new CourierException('Shipbubble: could not load package categories — ' . $this->error($res));
            }
            Cache::put($key, $list, now()->addDay());
        }

        $match = collect($list)->first(fn ($c) => mb_strtolower((string) ($c['category'] ?? '')) === $wanted)
            ?? collect($list)->first(fn ($c) => str_contains(mb_strtolower((string) ($c['category'] ?? '')), 'light weight'))
            ?? $list[0];

        return (int) ($match['category_id'] ?? 0);
    }

    private function map(string $raw): ?string
    {
        $code = str_replace([' ', '-'], '_', mb_strtolower(trim($raw)));

        return self::STATUSES[$code] ?? StatusText::map($raw);
    }

    /** Whole days until a Lagos-time ETA ("2026-10-31 08:00:00"). */
    private function days(?string $eta): ?int
    {
        try {
            return $eta ? max(0, (int) ceil(now(self::TZ)->diffInSeconds(Carbon::parse($eta, self::TZ), false) / 86400)) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Tracking times are ISO with Z; plain ones are Lagos time. */
    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value, self::TZ)->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && $res->json('status') !== 'failed';
    }

    private function client()
    {
        return $this->http()->withToken($this->account->credential('api_key'));
    }

    private function error(Response $res): string
    {
        $json = $res->json();
        if (is_array($json)) {
            $errors = collect($json['errors'] ?? [])->flatten()->filter(fn ($v) => is_string($v) && $v !== '')->unique();
            $msg = is_string($json['message'] ?? null) ? $json['message'] : '';
            $more = $errors->reject(fn ($e) => $e === $msg)->take(3)->implode('; ');
            if ($msg !== '' || $more !== '') {
                return trim($msg . ($msg !== '' && $more !== '' ? ' — ' : '') . $more);
            }
        }

        return 'HTTP ' . $res->status();
    }
}
