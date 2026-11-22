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
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lalamove (on-demand, Asia / LatAm / Gulf) — API v3, from the official docs,
 * the official JS SDK and the webhook deck. https://rest.lalamove.com
 * (sandbox rest.sandbox.lalamove.com). Every call is signed:
 * Authorization: hmac {key}:{ms}:{hex HMAC-SHA256("{ms}\r\n{METHOD}\r\n{path}\r\n\r\n{body}", secret)}
 * plus Market + Request-ID headers; bodies are wrapped in {"data": …}.
 * Booking = quotation (valid ~5 min, needs both stops' coordinates) → order.
 * No labels (the share link is the tracking page) and no native COD.
 * Webhooks are signed the same way over the webhook path and `data`.
 */
class LalamoveDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://rest.lalamove.com';
    private const SANDBOX = 'https://rest.sandbox.lalamove.com';

    private const CALLING = ['HK' => '852', 'TH' => '66', 'SG' => '65', 'MY' => '60', 'ID' => '62', 'PH' => '63', 'VN' => '84',
        'TW' => '886', 'BR' => '55', 'MX' => '52', 'JP' => '81', 'AE' => '971', 'SA' => '966'];

    private const STATUSES = [
        'ASSIGNING_DRIVER' => CourierStatus::BOOKED,
        'ON_GOING' => CourierStatus::BOOKED,
        'PICKED_UP' => CourierStatus::OUT_FOR_DELIVERY,
        'COMPLETED' => CourierStatus::DELIVERED,
        'CANCELED' => CourierStatus::CANCELLED,
        'EXPIRED' => CourierStatus::CANCELLED,
        'REJECTED' => CourierStatus::FAILED_ATTEMPT,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'lalamove',
            'name' => 'Lalamove',
            'region' => 'Asia / LatAm / Gulf',
            'countries' => array_keys(self::CALLING),
            'website' => 'https://www.lalamove.com',
            'tracking_url' => null,
            'description' => 'On-demand motorcycle, car and van delivery: instant quotes, booking, live share link and signed status webhooks.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'coordinates' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Lalamove Partner Portal → Developers (pk_test… / pk_prod…).'],
                ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'market', 'label' => 'Market', 'type' => 'select', 'required' => true, 'options' => array_map(
                    fn ($m) => ['value' => $m, 'label' => $m], array_keys(self::CALLING)
                )],
                ['key' => 'service_type', 'label' => 'Vehicle', 'type' => 'text',
                    'help' => 'e.g. MOTORCYCLE, CAR, VAN (as listed for your city). Leave empty for MOTORCYCLE.'],
                ['key' => 'language', 'label' => 'Language', 'type' => 'text', 'help' => 'e.g. en_PH. Leave empty for en_<market>.'],
                ['key' => 'origin_lat', 'label' => 'Pickup latitude', 'type' => 'number', 'required' => true],
                ['key' => 'origin_lng', 'label' => 'Pickup longitude', 'type' => 'number', 'required' => true],
                ['key' => 'pod', 'label' => 'Require proof of delivery', 'type' => 'switch'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->call('GET', '/v3/cities');
        if (! $res->successful()) {
            return ConnectionResult::failed('Lalamove: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to Lalamove (' . count($res->json('data') ?? []) . ' cities in ' . $this->market() . ').');
    }

    /** One quote for the configured vehicle; the id carries the quotation and both stop ids. */
    public function rates(ShipmentRequest $request): array
    {
        $q = $this->quote($request);
        $price = $q['priceBreakdown'] ?? [];

        return [new Rate(
            id: $q['quotationId'] . ':' . ($q['stops'][0]['stopId'] ?? '') . ':' . ($q['stops'][1]['stopId'] ?? ''),
            carrier: 'Lalamove',
            service: (string) ($q['serviceType'] ?? $this->serviceType()),
            amount: (float) ($price['total'] ?? 0),
            currency: (string) ($price['currency'] ?? ''),
            days: 0,
        )];
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        // A quote picked in "Compare rates" (fresh for ~5 minutes), else quote now.
        if ($request->service && preg_match('/^(\d+):(\d+):(\d+)$/', $request->service, $m)) {
            [$quotationId, $senderStop, $recipientStop] = [$m[1], $m[2], $m[3]];
        } else {
            $q = $this->quote($request);
            [$quotationId, $senderStop, $recipientStop] = [(string) $q['quotationId'], (string) ($q['stops'][0]['stopId'] ?? ''), (string) ($q['stops'][1]['stopId'] ?? '')];
        }

        $acc = $this->account;
        $res = $this->call('POST', '/v3/orders', ['data' => [
            'quotationId' => $quotationId,
            'sender' => ['stopId' => $senderStop, 'name' => (string) ($acc->pickup_contact ?: $acc->name), 'phone' => $this->phone($acc->pickup_phone)],
            'recipients' => [array_filter([
                'stopId' => $recipientStop,
                'name' => $request->consigneeName,
                'phone' => $this->phone($request->consigneePhone),
                'remarks' => trim($request->notes . ($request->codAmount > 0 ? ' · Collect ' . number_format($request->codAmount, 2) : '')) ?: null,
            ])],
            'isPODEnabled' => (bool) $acc->setting('pod'),
            'metadata' => ['reference' => $request->reference],
        ]]);
        $o = $res->json('data') ?? [];
        if (! $res->successful() || empty($o['orderId'])) {
            throw new CourierException('Lalamove: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: (string) $o['orderId'],
            labelUrl: null,
            charges: isset($o['priceBreakdown']['total']) ? (float) $o['priceBreakdown']['total'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: $o['status'] ?? 'ASSIGNING_DRIVER',
            meta: ['share_link' => $o['shareLink'] ?? null],
        );
    }

    /** Allowed while assigning a driver or within 5 minutes of matching. */
    public function cancel(Shipment $shipment): void
    {
        $res = $this->call('DELETE', '/v3/orders/' . rawurlencode((string) $shipment->tracking_number));
        if ($res->status() !== 204 && ! $res->successful()) {
            throw new CourierException('Lalamove: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->call('GET', '/v3/orders/' . rawurlencode((string) $shipment->tracking_number));
        $o = $res->json('data');
        if (! $res->successful() || ! is_array($o)) {
            throw new CourierException('Lalamove: ' . $this->error($res));
        }

        return $this->toResult($o);
    }

    /**
     * {apiKey, timestamp (s), signature, eventType, data:{order:{…}}}; signature =
     * hex HMAC-SHA256("{timestamp}\r\nPOST\r\n{path}\r\n\r\n{json(data)}", secret).
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $payload = $request->json()->all();
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $body = $request->getContent();
        // Sign the exact `data` JSON as received when it can be cut out; else re-encode compactly.
        // Objects (not arrays) keep empty {} as {} when re-encoding.
        $json = $this->rawDataJson($body) ?? $this->compactJson(json_decode($body)->data ?? $data);
        $raw = ($payload['timestamp'] ?? '') . "\r\nPOST\r\n/" . ltrim($request->path(), '/') . "\r\n\r\n" . $json;
        $expected = hash_hmac('sha256', $raw, $this->account->credential('api_secret'));
        if (! hash_equals($expected, (string) ($payload['signature'] ?? '')) || ($payload['apiKey'] ?? '') !== $this->account->credential('api_key')) {
            throw new CourierException('Invalid Lalamove webhook signature.');
        }

        $order = $data['order'] ?? null;
        if (! is_array($order) || empty($order['orderId'])) {
            return null;
        }

        return new WebhookUpdate((string) $order['orderId'], $this->toResult($order, (string) ($payload['eventType'] ?? '')));
    }

    // ------------------------------------------------------------------

    private function quote(ShipmentRequest $request): array
    {
        $acc = $this->account;
        if ($request->latitude === null || $request->longitude === null) {
            throw new CourierException('Lalamove needs the drop-off latitude and longitude.');
        }
        if (! is_numeric($acc->setting('origin_lat')) || ! is_numeric($acc->setting('origin_lng'))) {
            throw new CourierException('Set the pickup latitude / longitude on the Lalamove account.');
        }

        $res = $this->call('POST', '/v3/quotations', ['data' => [
            'serviceType' => $this->serviceType(),
            'language' => $acc->setting('language') ?: ('en_' . $this->market()),
            'stops' => [
                ['coordinates' => ['lat' => (string) $acc->setting('origin_lat'), 'lng' => (string) $acc->setting('origin_lng')], 'address' => trim($acc->pickup_address . ', ' . $acc->pickup_city, ', ')],
                ['coordinates' => ['lat' => (string) $request->latitude, 'lng' => (string) $request->longitude], 'address' => trim($request->address . ', ' . $request->city, ', ')],
            ],
            'item' => ['quantity' => (string) $request->pieces, 'categories' => ['OTHERS']],
        ]]);
        $q = $res->json('data') ?? [];
        if (! $res->successful() || empty($q['quotationId'])) {
            throw new CourierException('Lalamove: ' . $this->error($res));
        }

        return $q;
    }

    private function toResult(array $o, string $event = ''): TrackingResult
    {
        $raw = (string) ($o['status'] ?? '');
        $status = self::STATUSES[$raw] ?? null;
        // A failed proof of delivery on the drop-off stop is a failed attempt.
        foreach ($o['stops'] ?? [] as $i => $stop) {
            if ($i > 0 && strtoupper((string) ($stop['POD']['status'] ?? '')) === 'FAILED') {
                $status = CourierStatus::FAILED_ATTEMPT;
            }
        }
        // Lalamove orders carry no per-status time: a null event time keeps the
        // event hash stable, so repeated polls do not duplicate the timeline.
        return new TrackingResult(
            status: $status,
            rawStatus: $raw ?: ($event ?: null),
            events: $raw !== '' ? [new TrackingEvent($status, $raw, null, null, $event && $event !== 'ORDER_STATUS_CHANGED' ? $event : null)] : [],
            deliveredAt: $status === CourierStatus::DELIVERED ? now() : null,
        );
    }

    /** The literal `"data": {...}` object from the raw body (balanced braces). */
    private function rawDataJson(string $body): ?string
    {
        if (! preg_match('/"data"\s*:\s*\{/', $body, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $inString = false;
        for ($i = $start, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];
            if ($inString) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}' && --$depth === 0) {
                $segment = substr($body, $start, $i - $start + 1);

                // Only usable when it is already compact (as JSON.stringify sends it).
                return $segment === $this->compactJson(json_decode($segment)) ? $segment : null;
            }
        }

        return null;
    }

    private function compactJson($data): string
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }
    }

    private function call(string $method, string $path, ?array $body = null): Response
    {
        $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) (int) round(microtime(true) * 1000);
        $sig = hash_hmac('sha256', "{$ts}\r\n{$method}\r\n{$path}\r\n\r\n{$json}", $this->account->credential('api_secret'));

        $req = $this->http()->withHeaders([
            'Authorization' => 'hmac ' . $this->account->credential('api_key') . ':' . $ts . ':' . $sig,
            'Market' => $this->market(),
            'Request-ID' => (string) Str::uuid(),
        ]);
        $url = ($this->account->isTestMode() ? self::SANDBOX : self::LIVE) . $path;

        return match ($method) {
            'GET' => $req->get($url),
            'DELETE' => $req->delete($url),
            default => $req->withBody($json, 'application/json')->send($method, $url),
        };
    }

    private function phone(?string $phone): string
    {
        return '+' . Phone::intl($phone, self::CALLING[$this->market()] ?? '');
    }

    private function market(): string
    {
        $m = strtoupper((string) $this->account->setting('market'));

        return isset(self::CALLING[$m]) ? $m : 'PH';
    }

    private function serviceType(): string
    {
        return strtoupper((string) ($this->account->setting('service_type') ?: 'MOTORCYCLE'));
    }

    private function error(Response $res): string
    {
        $err = $res->json('errors.0');
        if (is_array($err)) {
            return trim(($err['id'] ?? '') . ' ' . ($err['message'] ?? ''));
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
