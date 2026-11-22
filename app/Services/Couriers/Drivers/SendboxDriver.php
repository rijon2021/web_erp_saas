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
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Sendbox (Nigeria), from Sendbox's official docs and WooCommerce plugin.
 * Production https://live.sendbox.co/, staging
 * https://sandbox.staging.sendbox.co/; header `Authorization: <access
 * token>` (no Bearer; tokens are permanent). Quote → shipment (wallet
 * charged; "drafted" = booked on hold for low balance). Sendbox posts
 * tracking updates to the callback_url sent with each shipment (this
 * account's webhook URL). No documented cancel or label endpoints: cancel in
 * the Sendbox dashboard; Stocky prints the label.
 */
class SendboxDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://live.sendbox.co/';
    private const STAGING = 'https://sandbox.staging.sendbox.co/';

    private const STATUSES = [
        'pending' => CourierStatus::BOOKED, 'accepted' => CourierStatus::BOOKED, 'drafted' => CourierStatus::BOOKED,
        'pickup_started' => CourierStatus::PICKED_UP, 'pickup_completed' => CourierStatus::PICKED_UP,
        'in_transit' => CourierStatus::IN_TRANSIT,
        'in_delivery' => CourierStatus::OUT_FOR_DELIVERY, 'delivery_started' => CourierStatus::OUT_FOR_DELIVERY,
        'delivered' => CourierStatus::DELIVERED,
        'cancelled' => CourierStatus::CANCELLED,
        'rejected' => CourierStatus::FAILED_ATTEMPT,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'sendbox',
            'name' => 'Sendbox',
            'region' => 'Nigeria',
            'countries' => ['NG'],
            'website' => 'https://sendbox.co',
            'tracking_url' => null,
            'description' => 'Local and international deliveries from Nigeria through Sendbox: quotes, booking from your wallet and tracking.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'tracking' => true,
                'webhooks' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'access_token', 'label' => 'Access token', 'type' => 'password', 'required' => true,
                    'help' => 'Sendbox developer dashboard → your app → access token.'],
            ],
            'settings' => array_merge([
                ['key' => 'service_code', 'label' => 'Service', 'type' => 'select', 'options' => [
                    ['value' => 'standard', 'label' => 'Standard'], ['value' => 'premium', 'label' => 'Premium'], ['value' => 'expedited', 'label' => 'Expedited'],
                ], 'help' => 'Leave empty for Standard.'],
            ], Shipper::fields()),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'oauth/profile');

        return $res->successful()
            ? ConnectionResult::ok('Connected to Sendbox.')
            : ConnectionResult::failed('Sendbox: ' . $this->error($res));
    }

    public function rates(ShipmentRequest $request): array
    {
        $res = $this->client()->post($this->base() . 'shipping/shipment_delivery_quote', $this->payload($request));
        if (! $res->successful()) {
            throw new CourierException('Sendbox: ' . $this->error($res));
        }

        return collect($res->json('rates') ?? [])
            ->filter(fn ($r) => ($r['is_enabled'] ?? true) && isset($r['key']))
            ->map(fn ($r) => new Rate(
                id: (string) $r['key'],
                carrier: (string) ($r['name'] ?? 'Sendbox'),
                service: (string) ($r['code'] ?? $r['service_code'] ?? ''),
                amount: (float) ($r['fee'] ?? 0),
                currency: (string) ($r['currency'] ?? 'NGN'),
                days: $this->days($r['delivery_date'] ?? null),
                meta: ['code' => $r['code'] ?? $r['service_code'] ?? null],
            ))->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rate = RatePicker::pick($this->rates($request), $request->service ?: ($this->account->default_service ?: ($this->account->setting('service_code') ?: null)));
        if (! $rate) {
            throw new CourierException('Sendbox: no rate matches the chosen service.');
        }

        $body = $this->payload($request) + [
            'rate_key' => $rate->id,
            'rate_code' => $rate->meta['code'] ?? null,
            'reference_code' => mb_substr($request->reference, 0, 50),
            'callback_url' => url('/api/couriers/webhook/' . $this->account->webhook_token),
        ];
        $body['service_code'] = $rate->meta['code'] ?? $body['service_code'];

        $res = $this->client()->post($this->base() . 'shipping/shipments', $body);
        $s = $res->json() ?? [];
        $code = $s['tracking_code'] ?? $s['code'] ?? null;
        if (! $res->successful() || ! $code) {
            throw new CourierException('Sendbox: ' . $this->error($res));
        }

        $statusCode = (string) ($s['status_code'] ?? $s['status']['code'] ?? 'pending');

        return new BookingResult(
            trackingNumber: (string) $code,
            courierReference: (string) ($s['_id'] ?? $s['id'] ?? $s['pk'] ?? ''),
            charges: isset($s['fee']) ? (float) $s['fee'] : $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: $statusCode === 'drafted' ? 'drafted (wallet balance too low — pay it in Sendbox)' : $statusCode,
        );
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->post($this->base() . 'shipping/tracking', ['code' => (string) $shipment->tracking_number]);
        if (! $res->successful()) {
            throw new CourierException('Sendbox: ' . $this->error($res));
        }

        return $this->toResult($res->json() ?? []);
    }

    /** Tracking callbacks look like the tracking response (status_code, events[], code). */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $data = $request->all();
        $code = $data['code'] ?? $data['tracking_code'] ?? ($data['package']['code'] ?? null);
        if (! $code) {
            return null;
        }

        return new WebhookUpdate((string) $code, $this->toResult($data));
    }

    // ------------------------------------------------------------------

    private function toResult(array $d): TrackingResult
    {
        $events = [];
        foreach ($d['events'] ?? [] as $e) {
            $raw = $e['status_code'] ?? ($e['status']['code'] ?? null);
            $events[] = new TrackingEvent(
                status: self::STATUSES[$raw] ?? null,
                rawStatus: $e['status']['name'] ?? $raw,
                at: $this->time($e['date_created'] ?? $e['last_updated'] ?? null),
                location: $e['location_description'] ?? null,
                description: $e['description'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = $d['status_code'] ?? ($d['status']['code'] ?? null);
        $status = self::STATUSES[(string) $raw] ?? null;

        return new TrackingResult(
            status: $status,
            rawStatus: $d['status']['name'] ?? $raw,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED && $events ? $events[count($events) - 1]->at : null,
        );
    }

    /** Documented nested quote / shipment body. */
    private function payload(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $country = $request->countryCode($from['country'] ?: 'NG');
        $dims = Shipper::dimensions($this->account);
        $split = fn (string $name) => array_pad(explode(' ', trim($name), 2), 2, '');
        [$ofirst, $olast] = $split($from['name']);
        [$dfirst, $dlast] = $split($request->consigneeName);
        $value = round(max($request->declaredValue, $request->codAmount, 1), 2);

        return [
            'origin' => array_filter([
                'first_name' => $ofirst, 'last_name' => $olast, 'name' => $from['name'],
                'street' => $from['street'], 'city' => $from['city'], 'state' => $from['state'],
                'country' => $from['country'] ?: 'NG', 'post_code' => $from['zip'] ?: null,
                'phone' => '+' . Phone::intl($from['phone'], '234'), 'email' => $from['email'] ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
            'destination' => array_filter([
                'first_name' => $dfirst, 'last_name' => $dlast, 'name' => $request->consigneeName,
                'street' => $request->address, 'city' => $request->city, 'state' => $request->state,
                'country' => $country, 'post_code' => $request->zip,
                'phone' => '+' . Phone::intl($request->consigneePhone, $country === 'NG' ? '234' : ''),
                'email' => $request->consigneeEmail,
            ], fn ($v) => $v !== null && $v !== ''),
            'weight' => max(0.1, round($request->weight, 2)),
            'dimension' => $dims,
            'incoming_option' => 'pickup',
            'region' => $from['country'] ?: 'NG',
            'service_type' => $country === ($from['country'] ?: 'NG') ? 'local' : 'international',
            'package_type' => 'general',
            'total_value' => $value,
            'currency' => 'NGN',
            'channel_code' => 'api',
            'pickup_date' => now()->addDay()->toDateString(),
            'items' => [[
                'name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100),
                'description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 250),
                'weight' => max(0.1, round($request->weight, 2)),
                'quantity' => $request->pieces,
                'value' => $value,
            ]],
            'service_code' => $this->account->setting('service_code') ?: 'standard',
        ];
    }

    private function days(?string $date): ?int
    {
        $d = $this->time($date);

        return $d ? max(0, (int) now()->startOfDay()->diffInDays($d->startOfDay(), false)) : null;
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function client()
    {
        return $this->http()->withHeaders(['Authorization' => $this->account->credential('access_token')]);
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
        $json = $res->json();
        if (is_array($json)) {
            if (! empty($json['description'])) {
                return (string) $json['description'];
            }
            $flat = collect($json)->flatten()->filter(fn ($v) => is_string($v))->take(3)->implode('; ');
            if ($flat !== '') {
                return $flat;
            }
        }

        return 'HTTP ' . $res->status();
    }
}
