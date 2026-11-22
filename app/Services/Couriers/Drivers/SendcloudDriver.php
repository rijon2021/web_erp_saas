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
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sendcloud (Europe — PostNL, DHL, DPD, GLS, UPS, Colissimo…), API v3 from
 * Sendcloud's official OpenAPI specs. https://panel.sendcloud.sc/api/v3,
 * HTTP Basic public:secret key. Shipping options (with quotes) →
 * shipments/announce (synchronous). Labels are PDFs from the parcel
 * documents endpoint. No sandbox: test with the free "sendcloud:letter"
 * option or cancel before the deadline. Webhooks: Sendcloud-Signature = hex
 * HMAC-SHA256 of the raw body with the secret key.
 */
class SendcloudDriver extends AbstractCourierDriver
{
    private const BASE = 'https://panel.sendcloud.sc/api/v3/';

    private const PHASES = [
        'announcing' => CourierStatus::BOOKED, 'announced' => CourierStatus::BOOKED, 'ready_to_send' => CourierStatus::BOOKED,
        'labelled' => CourierStatus::BOOKED, 'no_label' => CourierStatus::BOOKED, 'dropped' => CourierStatus::BOOKED,
        'accepted' => CourierStatus::PICKED_UP,
        'sorted' => CourierStatus::IN_TRANSIT, 'in_transit' => CourierStatus::IN_TRANSIT, 'customs' => CourierStatus::IN_TRANSIT, 'pup' => CourierStatus::IN_TRANSIT,
        'in_delivery' => CourierStatus::OUT_FOR_DELIVERY,
        'attempted' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED, 'delivery_forced' => CourierStatus::DELIVERED,
    ];

    private const EXCEPTIONS = [
        'address_error' => CourierStatus::FAILED_ATTEMPT, 'refused' => CourierStatus::FAILED_ATTEMPT,
        'returned_sender' => CourierStatus::RETURNING,
        // "cancelling" is still in progress (it can fail): only "cancelled" is final.
        'cancelled' => CourierStatus::CANCELLED, 'cancelled_upstream' => CourierStatus::CANCELLED,
    ];

    /** Webhook status ids confirmed in Sendcloud's specs. */
    private const STATUS_IDS = [
        1000 => CourierStatus::BOOKED, 3 => CourierStatus::IN_TRANSIT, 5 => CourierStatus::IN_TRANSIT,
        11 => CourierStatus::DELIVERED, 2000 => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'sendcloud',
            'name' => 'Sendcloud',
            'region' => 'Europe',
            'countries' => ['NL', 'BE', 'DE', 'FR', 'ES', 'IT', 'AT', 'GB'],
            'website' => 'https://www.sendcloud.com',
            'tracking_url' => null,
            'description' => 'PostNL, DHL, DPD, GLS, UPS, Colissimo and more through Sendcloud: compare options, announce shipments, print labels and track.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
            ],
            'credentials' => [
                ['key' => 'public_key', 'label' => 'Public key', 'type' => 'text', 'required' => true,
                    'help' => 'Sendcloud → Settings → Integrations → your API integration.'],
                ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'required' => true,
                    'help' => 'Also verifies the status updates Sendcloud sends.'],
            ],
            'settings' => array_merge([
                ['key' => 'sender_address_id', 'label' => 'Sender address ID', 'type' => 'number',
                    'help' => 'Optional. "Test connection" lists your Sendcloud sender addresses; otherwise the pickup address below is used.'],
            ], Shipper::fields()),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get(self::BASE . 'addresses/sender-addresses');
        if (! $res->successful()) {
            return ConnectionResult::failed('Sendcloud: ' . $this->error($res));
        }

        $addresses = collect($res->json('data') ?? [])
            ->map(fn ($a) => ($a['id'] ?? '?') . ' (' . ($a['city'] ?? '') . ')')->implode(', ');

        return ConnectionResult::ok('Connected to Sendcloud.' . ($addresses ? " Sender addresses: {$addresses}." : ''));
    }

    public function rates(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $res = $this->client()->post(self::BASE . 'shipping-options', [
            'from_address' => array_filter(['country_code' => $from['country'], 'postal_code' => $from['zip'], 'city' => $from['city']]),
            'to_address' => array_filter(['country_code' => $request->countryCode($from['country']), 'postal_code' => $request->zip, 'city' => $request->city]),
            'parcels' => [$this->parcel($request, false)],
            'calculate_quotes' => true,
        ]);
        if (! $res->successful()) {
            throw new CourierException('Sendcloud: ' . $this->error($res));
        }

        return collect($res->json('data') ?? [])->filter(fn ($o) => ! empty($o['code']))->map(function ($o) {
            $quote = $o['quotes'][0] ?? [];
            $lead = $quote['lead_time'] ?? null;

            return new Rate(
                id: (string) $o['code'],
                carrier: (string) ($o['carrier']['name'] ?? $o['carrier']['code'] ?? ''),
                service: (string) ($o['name'] ?? $o['code']),
                amount: (float) ($quote['price']['total']['value'] ?? 0),
                currency: (string) ($quote['price']['total']['currency'] ?? 'EUR'),
                days: is_numeric($lead) ? (int) ceil($lead / 24) : null,
                meta: ['contract_id' => $o['contract']['id'] ?? null],
            );
        })->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rates = $this->rates($request);
        $rate = RatePicker::pick($rates, $request->service ?: $this->account->default_service);
        if (! $rate) {
            throw new CourierException('Sendcloud: no shipping option matches "' . ($request->service ?: $this->account->default_service) . '".');
        }

        $from = Shipper::address($this->account);
        $toCountry = $request->countryCode($from['country']);
        $senderId = $this->account->setting('sender_address_id');

        $res = $this->client()->post(self::BASE . 'shipments/announce', array_filter([
            'to_address' => array_filter([
                'name' => $request->consigneeName,
                'address_line_1' => $request->address,
                'postal_code' => (string) $request->zip,
                'city' => $request->city,
                'country_code' => $toCountry,
                'state_province_code' => $request->state && preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $request->state) ? $request->state : null,
                'email' => $request->consigneeEmail,
                'phone_number' => $request->consigneePhone,
            ], fn ($v) => $v !== null && $v !== ''),
            'from_address' => $senderId ? ['sender_address_id' => (int) $senderId] : array_filter([
                'name' => $from['name'], 'company_name' => $from['company'] ?: null,
                'address_line_1' => $from['street'], 'postal_code' => $from['zip'], 'city' => $from['city'],
                'country_code' => $from['country'], 'email' => $from['email'] ?: null, 'phone_number' => $from['phone'] ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
            'ship_with' => ['type' => 'shipping_option_code', 'properties' => array_filter([
                'shipping_option_code' => $rate->id,
                'contract_id' => $rate->meta['contract_id'] ?? null,
            ])],
            'parcels' => [$this->parcel($request, $toCountry !== $from['country'])],
            'order_number' => mb_substr($request->reference, 0, 50),
            // Unique per announcement; doubles as an idempotency key.
            'external_reference_id' => mb_substr($request->reference, 0, 40) . '-' . Str::lower(Str::random(6)),
            'label_details' => ['mime_type' => 'application/pdf'],
        ]));

        $data = $res->json('data') ?? [];
        $parcel = $data['parcels'][0] ?? [];
        $errors = collect($data['errors'] ?? $res->json('errors') ?? [])->map(fn ($e) => is_array($e) ? ($e['detail'] ?? $e['message'] ?? $e['code'] ?? '') : (string) $e)->filter()->implode('; ');
        if (! $res->successful() || empty($parcel['tracking_number']) || ($parcel['status']['code'] ?? '') === 'ANNOUNCEMENT_FAILED') {
            throw new CourierException('Sendcloud: ' . ($errors ?: $this->error($res)));
        }

        return new BookingResult(
            trackingNumber: (string) $parcel['tracking_number'],
            courierReference: (string) ($data['id'] ?? ''),
            charges: $rate->amount,
            status: CourierStatus::BOOKED,
            rawStatus: $parcel['status']['message'] ?? 'Ready to send',
            meta: ['parcel_id' => $parcel['id'] ?? null, 'tracking_url' => $parcel['tracking_url'] ?? null, 'option' => $rate->id],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post(self::BASE . 'shipments/' . rawurlencode((string) $shipment->courier_reference) . '/cancel');
        if (! in_array($res->status(), [200, 202], true)) {
            throw new CourierException('Sendcloud: ' . ($res->status() === 409 ? 'this shipment can no longer be cancelled.' : $this->error($res)));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get(self::BASE . 'parcels/tracking/' . rawurlencode((string) $shipment->tracking_number));
        if (! $res->successful()) {
            throw new CourierException('Sendcloud: ' . $this->error($res));
        }

        $events = [];
        foreach ($res->json('events') ?? [] as $e) {
            $events[] = new TrackingEvent(
                status: $this->mapEvent($e),
                rawStatus: $e['sub_status'] ?? ($e['phase'] ?? null),
                at: $this->time($e['event_at'] ?? null),
                description: $e['description'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        // No top-level status: the newest event decides.
        $latest = $events ? $events[count($events) - 1] : null;
        $current = null;
        foreach (array_reverse($events) as $e) {
            if ($e->status) {
                $current = $e;
                break;
            }
        }

        return new TrackingResult(
            status: $current?->status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $current?->status === CourierStatus::DELIVERED ? $current->at : null,
        );
    }

    /** Label PDF of the parcel. */
    public function label(Shipment $shipment): array
    {
        $parcelId = $shipment->courier_meta['booking']['parcel_id'] ?? null;
        if (! $parcelId) {
            throw new CourierException('Sendcloud: the parcel id of this shipment is unknown.');
        }
        $res = $this->client()->withHeaders(['Accept' => 'application/pdf'])
            ->get(self::BASE . 'parcels/' . $parcelId . '/documents/label', ['paper_size' => 'A6']);
        if (! $res->successful() || ! str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            throw new CourierException('Sendcloud: ' . $this->error($res));
        }

        return ['pdf' => $res->body()];
    }

    /** parcel_status_changed, signed with the secret key. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $expected = hash_hmac('sha256', $request->getContent(), $this->account->credential('secret_key'));
        if (! hash_equals($expected, (string) $request->header('Sendcloud-Signature'))) {
            throw new CourierException('Invalid Sendcloud webhook signature.');
        }

        $parcel = $request->input('parcel');
        if ($request->input('action') !== 'parcel_status_changed' || ! is_array($parcel) || empty($parcel['tracking_number'])) {
            return null;
        }

        $id = isset($parcel['status']['id']) ? (int) $parcel['status']['id'] : null;
        $message = $parcel['status']['message'] ?? null;
        $status = ($id !== null ? (self::STATUS_IDS[$id] ?? null) : null) ?? StatusText::map($message);
        $at = $request->filled('timestamp') && is_numeric($request->input('timestamp'))
            ? Carbon::createFromTimestamp((int) ($request->input('timestamp') > 1e11 ? $request->input('timestamp') / 1000 : $request->input('timestamp')))
            : now();

        return new WebhookUpdate((string) $parcel['tracking_number'], new TrackingResult(
            status: $status,
            rawStatus: $message,
            events: [new TrackingEvent($status, $message, $at)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    private function mapEvent(array $e): ?string
    {
        $phase = $e['phase'] ?? null;
        if ($phase && $phase !== 'none' && isset(self::PHASES[$phase])) {
            return self::PHASES[$phase];
        }

        return self::EXCEPTIONS[$e['exception'] ?? ''] ?? null;
    }

    private function parcel(ShipmentRequest $request, bool $customs): array
    {
        $d = Shipper::dimensions($this->account);
        $weight = ['value' => number_format(max(0.001, $request->weight), 3, '.', ''), 'unit' => 'kg'];
        $parcel = [
            'weight' => $weight,
            'dimensions' => ['length' => (string) $d['length'], 'width' => (string) $d['width'], 'height' => (string) $d['height'], 'unit' => 'cm'],
        ];
        if ($customs) {
            // Item weight and price are per unit (Sendcloud multiplies by quantity).
            $pieces = max(1, $request->pieces);
            $parcel['parcel_items'] = [[
                'description' => mb_substr($request->itemsDescription ?: 'Merchandise', 0, 50),
                'quantity' => $pieces,
                'weight' => ['value' => number_format(max(0.001, $request->weight / $pieces), 3, '.', ''), 'unit' => 'kg'],
                'price' => ['value' => number_format(max(0.01, max(1, $request->declaredValue) / $pieces), 2, '.', ''), 'currency' => 'EUR'],
                'origin_country' => Shipper::address($this->account)['country'],
            ]];
        }

        return $parcel;
    }

    private function client()
    {
        return $this->http()->withBasicAuth($this->account->credential('public_key'), $this->account->credential('secret_key'));
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
        if (is_array($errors) && $errors) {
            return collect($errors)->map(fn ($e) => is_array($e) ? ($e['detail'] ?? $e['title'] ?? $e['code'] ?? '') : (string) $e)->filter()->implode('; ');
        }

        return $res->json('message') ?: $res->json('error.message') ?: ('HTTP ' . $res->status());
    }
}
