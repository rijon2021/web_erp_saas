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
 * Shiip (goshiip.com, Nigeria), from developer.goshiip.com. Hosts
 * https://delivery.apiideraos.com/api/v2/token/ (live) and
 * delivery-staging.… (sandbox); header `Authorization: Bearer <secret key>`
 * (SSK_LIVE_… / SSK_TEST_…). tariffs/allprice quotes every carrier and
 * returns a redis_key; bookshipment takes that redis_key + the carrier's rate
 * id + the account's user id (user/myprofile) and charges the Shiip wallet.
 * Rate ids here are "redis_key|rate_id"; book() re-quotes and matches on the
 * rate id, so an expired redis_key never blocks a booking. shipment/assign
 * requests the pickup; cancel only files a request with Shiip operations.
 * No label endpoint (Stocky prints it) and no COD. Webhooks: POST every
 * 30 min, `X-Signature` = hex HMAC-SHA256 of the raw body.
 */
class ShiipDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://delivery.apiideraos.com/api/v2/token/';
    private const STAGING = 'https://delivery-staging.apiideraos.com/api/v2/token/';

    /** Shiip times are Lagos local time without an offset. */
    private const TZ = 'Africa/Lagos';

    /** Documented statuses; anything else goes through StatusText ("In Progress" stays unmapped). */
    private const STATUSES = [
        'pending' => CourierStatus::BOOKED,
        'picked up' => CourierStatus::PICKED_UP,
        'delivered' => CourierStatus::DELIVERED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'shiip',
            'name' => 'Shiip',
            'region' => 'Nigeria',
            'countries' => ['NG'],
            'website' => 'https://goshiip.com',
            'tracking_url' => null,
            'description' => 'Compare and book GIG, DHL, Kwik, Redstar and other Nigerian carriers through Shiip, paid from your Shiip wallet, with pickup requests and tracking.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'required' => true,
                    'help' => 'Shiip business dashboard → Settings → API. SSK_TEST_… for test mode, SSK_LIVE_… for live.'],
                ['key' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'password',
                    'help' => 'Only if Shiip gave you a separate secret for webhooks. Otherwise the secret key verifies them.'],
            ],
            'settings' => [
                ['key' => 'route_type', 'label' => 'Route type', 'type' => 'select', 'options' => [
                    ['value' => 'intrastate', 'label' => 'Intrastate (same state)'],
                    ['value' => 'interstate', 'label' => 'Interstate'],
                    ['value' => 'international', 'label' => 'International'],
                    ['value' => 'international_us', 'label' => 'International (US)'],
                    ['value' => 'frozen-international', 'label' => 'Frozen international'],
                ], 'help' => 'Leave empty to choose by address: other country → international, same state as the pickup → intrastate, else interstate.'],
                ['key' => 'auto_assign', 'label' => 'Request pickup right after booking', 'type' => 'switch',
                    'help' => 'Tells Shiip operations the parcel is ready. Cancelling after this may cost a fee.'],
                ['key' => 'sender_email', 'label' => 'Pickup email', 'type' => 'text', 'required' => true],
                ['key' => 'sender_state', 'label' => 'Pickup state', 'type' => 'text', 'help' => 'e.g. Lagos. Used to tell intrastate from interstate.'],
                ['key' => 'fallback_email', 'label' => 'Email when the customer has none', 'type' => 'text',
                    'help' => 'Shiip needs a receiver email. Empty = the pickup email.'],
                ['key' => 'item_category', 'label' => 'Item category', 'type' => 'text', 'help' => 'As Shiip names it, e.g. beauty, fashion, electronics. Empty = general.'],
                ['key' => 'hs_category', 'label' => 'HS category (international)', 'type' => 'text'],
                ['key' => 'hs_subcategory', 'label' => 'HS subcategory (international)', 'type' => 'text'],
                ['key' => 'hs_code', 'label' => 'HS code (international)', 'type' => 'text', 'help' => 'Six digits. Required by Shiip for international routes.'],
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

        $key = $this->account->credential('secret_key');
        if (str_starts_with($key, 'SSK_TEST_') && ! $this->account->isTestMode()) {
            return ConnectionResult::failed('This is a sandbox key (SSK_TEST_…): switch the account to test mode or use your SSK_LIVE_ key.');
        }
        if (str_starts_with($key, 'SSK_LIVE_') && $this->account->isTestMode()) {
            return ConnectionResult::failed('This is a live key (SSK_LIVE_…): switch the account to live mode or use your SSK_TEST_ key.');
        }

        $res = $this->client()->get($this->base() . 'user/myprofile');
        $profile = $res->json('data');
        if (! $res->successful() || ! is_array($profile) || empty($profile['id'])) {
            return ConnectionResult::failed('Shiip: ' . $this->error($res));
        }
        Cache::put($this->userKey(), (int) $profile['id'], now()->addDays(30));

        $who = trim((string) ($profile['companyName'] ?? '') ?: (string) ($profile['email'] ?? ''));
        $balance = isset($profile['balance']) && is_numeric($profile['balance']) ? ' Wallet balance: ' . number_format((float) $profile['balance'], 2) . ' NGN.' : '';

        return ConnectionResult::ok('Connected to Shiip' . ($who !== '' ? ' as ' . $who : '') . '.' . $balance);
    }

    public function rates(ShipmentRequest $request): array
    {
        // allprice asks every carrier in turn and can take longer than the default timeout.
        $res = $this->client()->timeout(55)->post($this->base() . 'tariffs/allprice', $this->payload($request));
        $redis = (string) ($res->json('data.redis_key') ?? '');
        if (! $res->successful() || $redis === '') {
            throw new CourierException('Shiip: ' . $this->error($res));
        }

        return collect($res->json('data.rates') ?? [])
            ->filter(fn ($r) => is_array($r) && ($r['status'] ?? false) && isset($r['amount']) && ($r['courier']['id'] ?? $r['id'] ?? null))
            ->map(function ($r) use ($redis) {
                $rateId = (string) ($r['courier']['id'] ?? $r['id']);

                return new Rate(
                    id: $redis . '|' . $rateId,
                    carrier: (string) ($r['courier']['name'] ?? $r['type'] ?? 'Shiip'),
                    service: '',
                    amount: (float) $r['amount'],
                    currency: (string) ($r['currency'] ?? 'NGN'),
                    days: isset($r['eta']) && is_numeric($r['eta']) ? (int) $r['eta'] : null,
                    meta: ['redis_key' => $redis, 'rate_id' => $rateId],
                );
            })->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rates = $this->rates($request);
        $pref = trim((string) ($request->service ?: ($this->account->default_service ?: '')));
        // A rate picked in "Compare rates" carries an older redis_key: match the fresh quote by rate id.
        if (str_contains($pref, '|')) {
            $wanted = substr($pref, strrpos($pref, '|') + 1);
            $rate = collect($rates)->first(fn (Rate $r) => $r->meta['rate_id'] === $wanted);
            if (! $rate) {
                throw new CourierException('Shiip: the chosen carrier no longer offers this route — compare rates again.');
            }
        } else {
            $rate = RatePicker::pick($rates, $pref ?: null);
        }
        if (! $rate) {
            throw new CourierException($rates ? 'Shiip: no rate matches the chosen service.' : 'Shiip: no carrier offers this route.');
        }

        $res = $this->client()->post($this->base() . 'bookshipment', array_filter([
            'redis_key' => $rate->meta['redis_key'],
            'user_id' => $this->userId(),
            'rate_id' => $rate->meta['rate_id'],
            'platform' => 'web2',
            'delivery_note' => $request->notes !== '' ? mb_substr($request->notes, 0, 250) : null,
        ], fn ($v) => $v !== null));

        $reference = (string) ($res->json('data.reference') ?? '');
        if (! $res->successful() || $reference === '' || $res->json('status') === false) {
            throw new CourierException('Shiip: ' . $this->error($res));
        }
        $shipmentId = $res->json('data.shipmentId');

        $meta = array_filter(['carrier' => $rate->carrier, 'rate_id' => $rate->meta['rate_id']]);
        if ($this->account->setting('auto_assign') && $shipmentId) {
            $meta['pickup'] = $this->assign((int) $shipmentId);
        }

        return new BookingResult(
            trackingNumber: $reference,
            courierReference: $shipmentId !== null ? (string) $shipmentId : null,
            charges: $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: 'Pending (' . $rate->carrier . ')',
            meta: $meta,
        );
    }

    /** Shiip only records the request; operations confirm it later. */
    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->get($this->base() . 'shipment/cancel/' . rawurlencode((string) $shipment->tracking_number));
        if (! $res->successful() || $res->json('status') === false) {
            throw new CourierException('Shiip: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        return $this->trackReference((string) $shipment->tracking_number);
    }

    private function trackReference(string $reference): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'shipment/track/' . rawurlencode($reference));
        $data = $res->json('data');
        if (! $res->successful() || ! is_array($data)) {
            throw new CourierException('Shiip: ' . $this->error($res));
        }

        $events = [];
        foreach ($data['status_history'] ?? [] as $h) {
            $raw = trim((string) ($h['status'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $events[] = new TrackingEvent(status: $this->map($raw), rawStatus: $raw, at: $this->time($h['timestamp'] ?? null));
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = trim((string) ($data['current_status'] ?? ''));
        $status = $this->map($raw);
        // Only read when the shipment turns delivered (here or from a webhook ahead of current_status).
        $deliveredAt = null;
        foreach ($events as $e) {
            if ($e->status === CourierStatus::DELIVERED) {
                $deliveredAt = $e->at;
            }
        }

        return new TrackingResult(status: $status, rawStatus: $raw ?: null, events: $events, deliveredAt: $deliveredAt);
    }

    /**
     * {"shipment": {"data": {reference, id, status, …}, "carrier": …}}, signed with X-Signature.
     * The push carries no event time, so the full history is read back from the track
     * endpoint: the timeline then matches what polling records, and a final status set
     * here (polling stops after it) still gets its real time.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $signature = strtolower(trim((string) $request->header('X-Signature')));
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        $body = $request->getContent();
        $valid = false;
        foreach (array_filter([$this->account->credential('webhook_secret'), $this->account->credential('secret_key')]) as $secret) {
            if ($signature !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            throw new CourierException('Invalid Shiip webhook signature.');
        }

        $data = $request->input('shipment.data') ?? $request->input('data');
        if (! is_array($data)) {
            return null;
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        $ref = $reference !== '' ? $reference : trim((string) ($data['id'] ?? ''));
        $raw = trim((string) ($data['status'] ?? ''));
        if ($ref === '' || $raw === '') {
            return null;
        }
        $status = $this->map($raw);

        if ($reference !== '') {
            try {
                $tracked = $this->trackReference($reference);
                if ($tracked->events) {
                    return new WebhookUpdate($ref, new TrackingResult(
                        // The pushed status is the newest; the history supplies the times.
                        status: $status ?? $tracked->status,
                        rawStatus: $status ? $raw : ($tracked->rawStatus ?? $raw),
                        events: $tracked->events,
                        deliveredAt: $tracked->deliveredAt,
                    ));
                }
            } catch (\Throwable $e) {
                // Fall back to the pushed status alone.
            }
        }

        return new WebhookUpdate($ref, new TrackingResult(
            status: $status,
            rawStatus: $raw,
            // No event time in the push: one timeline line per distinct status.
            events: [new TrackingEvent(status: $status, rawStatus: $raw)],
        ));
    }

    // ------------------------------------------------------------------

    /** Documented rate request: free-text addresses, 11-digit phones, items with category and price. */
    private function payload(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $country = $request->countryCode('NG');
        $dims = Shipper::dimensions($this->account);
        $type = $this->routeType($request, $from, $country);
        $pieces = max(1, $request->pieces);
        $value = max($request->declaredValue, $request->codAmount, 0);
        $senderEmail = $from['email'] ?: (string) $this->account->setting('fallback_email');
        if (trim($from['phone']) === '' || $senderEmail === '') {
            throw new CourierException('Shiip: set the pickup phone and pickup email on the courier account.');
        }
        $joined = fn (array $parts) => implode(', ', array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));

        $item = [
            'name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 255),
            'description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 255),
            'weight' => max(0.01, round($request->weight / $pieces, 2)),
            'category' => mb_substr((string) ($this->account->setting('item_category') ?: 'general'), 0, 255),
            // Shiip rejects item prices under 500.
            'amount' => max(500, round($value / $pieces, 2)),
            'quantity' => $pieces,
        ];
        if (str_starts_with($type, 'international') || $type === 'frozen-international') {
            $code = trim((string) $this->account->setting('hs_code'));
            if ($code === '') {
                throw new CourierException('Shiip: international shipments need an HS code — set it on the courier account.');
            }
            $item['hscode'] = [
                'category' => (string) $this->account->setting('hs_category'),
                'subcategory' => (string) $this->account->setting('hs_subcategory'),
                'code' => $code,
            ];
        }

        return [
            'type' => $type,
            'toAddress' => [
                'name' => mb_substr($request->consigneeName, 0, 255),
                'email' => $request->consigneeEmail ?: ($this->account->setting('fallback_email') ?: $senderEmail),
                'address' => mb_substr($joined([$request->address, $request->city, (string) $request->state, $country === 'NG' ? 'Nigeria' : (string) $request->country]), 0, 255),
                'phone' => $country === 'NG' ? Phone::ng($request->consigneePhone) : preg_replace('/\D+/', '', $request->consigneePhone),
            ],
            'fromAddress' => [
                'name' => mb_substr($from['name'], 0, 255),
                'email' => $senderEmail,
                'address' => mb_substr($joined([$from['street'], $from['city'], $from['state'], 'Nigeria']), 0, 255),
                'phone' => Phone::ng($from['phone']),
            ],
            'parcels' => [
                'length' => max(1, $dims['length']),
                'width' => max(1, $dims['width']),
                'height' => max(1, $dims['height']),
                'weight' => max(0.01, round($request->weight, 2)),
            ],
            'items' => [$item],
        ];
    }

    private function routeType(ShipmentRequest $request, array $from, string $country): string
    {
        if ($forced = $this->account->setting('route_type')) {
            return (string) $forced;
        }
        if ($country !== 'NG') {
            return 'international';
        }

        $norm = fn (?string $s) => trim(preg_replace('/\s+state$/', '', mb_strtolower(trim((string) $s))));
        if ($norm($from['state']) !== '' && $norm($request->state) !== '') {
            return $norm($from['state']) === $norm($request->state) ? 'intrastate' : 'interstate';
        }

        return $norm($from['city']) !== '' && $norm($from['city']) === $norm($request->city) ? 'intrastate' : 'interstate';
    }

    private function map(string $raw): ?string
    {
        return self::STATUSES[mb_strtolower($raw)] ?? StatusText::map($raw);
    }

    /** Request the pickup; a failure never undoes the booking. */
    private function assign(int $shipmentId): string
    {
        try {
            $res = $this->client()->post($this->base() . 'shipment/assign', ['shipment_id' => $shipmentId]);

            return $res->successful() && $res->json('status') !== false ? 'requested' : 'failed: ' . $this->error($res);
        } catch (\Throwable $e) {
            return 'failed: ' . $e->getMessage();
        }
    }

    /** bookshipment needs the account's Shiip user id, read once from the profile. */
    private function userId(): int
    {
        $key = $this->userKey();
        if ($id = Cache::get($key)) {
            return (int) $id;
        }

        $res = $this->client()->get($this->base() . 'user/myprofile');
        $id = (int) ($res->json('data.id') ?? 0);
        if (! $res->successful() || $id <= 0) {
            throw new CourierException('Shiip: could not read your user profile — ' . $this->error($res));
        }
        Cache::put($key, $id, now()->addDays(30));

        return $id;
    }

    private function userKey(): string
    {
        return 'courier_shiip_user:' . (tenant('id') ?? 'central') . ':' . md5($this->base() . $this->account->credential('secret_key'));
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function client()
    {
        return $this->http()->withToken($this->account->credential('secret_key'));
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value, self::TZ)->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $json = $res->json();
        if (is_array($json)) {
            $msg = $json['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
            $flat = collect(is_array($msg) ? $msg : ($json['errors'] ?? $json))->flatten()->filter(fn ($v) => is_string($v) && $v !== '')->take(3)->implode('; ');
            if ($flat !== '') {
                return $flat;
            }
        }

        return 'HTTP ' . $res->status();
    }
}
