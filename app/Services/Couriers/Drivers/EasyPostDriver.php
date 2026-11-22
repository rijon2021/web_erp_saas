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

/**
 * EasyPost (API v2) — 100+ carriers (USPS, UPS, FedEx, DHL, Canada Post…).
 * https://api.easypost.com/v2, HTTP Basic with the API key as username; the
 * test or production key decides the mode. Flow: POST /shipments (rates) →
 * POST /shipments/{id}/buy. Parcels are inches / ounces. Refund voids an
 * unused label. Webhooks: tracker.updated, verified with X-Hmac-Signature
 * ("hmac-sha256-hex=" + HMAC-SHA256 of the raw body with the webhook secret).
 */
class EasyPostDriver extends AbstractCourierDriver
{
    private const BASE = 'https://api.easypost.com/v2/';

    private const STATUSES = [
        'pre_transit' => CourierStatus::BOOKED,
        'in_transit' => CourierStatus::IN_TRANSIT,
        'available_for_pickup' => CourierStatus::IN_TRANSIT,
        'out_for_delivery' => CourierStatus::OUT_FOR_DELIVERY,
        'failure' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED,
        'return_to_sender' => CourierStatus::RETURNING,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'easypost',
            'name' => 'EasyPost',
            'region' => 'USA / Global',
            'countries' => [],
            'website' => 'https://www.easypost.com',
            'tracking_url' => null,
            'description' => 'USPS, UPS, FedEx, DHL and 100+ carriers through one account: compare rates, buy labels, track and void.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'EasyPost → Account → API Keys. A test key buys free test labels.'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'password',
                    'help' => 'Required for instant status updates. The secret set on your EasyPost webhook; used to verify updates.'],
            ],
            'settings' => array_merge(Shipper::fields(), [
                ['key' => 'carrier_accounts', 'label' => 'Carrier account IDs', 'type' => 'text',
                    'help' => 'Optional, comma-separated ca_… IDs to limit which carriers are rated.'],
                ['key' => 'label_format', 'label' => 'Label format', 'type' => 'select', 'options' => [
                    ['value' => 'PDF', 'label' => 'PDF 4×6'], ['value' => 'PNG', 'label' => 'PNG 4×6'], ['value' => 'ZPL', 'label' => 'ZPL'],
                ], 'help' => 'Leave empty for PDF.'],
                ['key' => 'enable_cod', 'label' => 'Ask the carrier to collect COD', 'type' => 'switch',
                    'help' => 'Only some carriers support collect-on-delivery.'],
            ]),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        // /carrier_accounts only answers production keys; /addresses answers both.
        $res = $this->client()->get(self::BASE . 'addresses', ['page_size' => 1]);
        if ($res->status() === 401 || $res->status() === 403) {
            return ConnectionResult::failed('EasyPost: ' . $this->error($res));
        }

        $mode = str_starts_with($this->account->credential('api_key'), 'EZTK') ? ' (test key)' : '';

        return $res->successful()
            ? ConnectionResult::ok('Connected to EasyPost' . $mode . '.')
            : ConnectionResult::failed('EasyPost: ' . $this->error($res));
    }

    public function rates(ShipmentRequest $request): array
    {
        $shipment = $this->createShipment($request);

        return array_map(fn ($r) => new Rate(
            id: $shipment['id'] . '|' . $r['id'],
            carrier: (string) ($r['carrier'] ?? ''),
            service: (string) ($r['service'] ?? ''),
            amount: (float) ($r['rate'] ?? 0),
            currency: (string) ($r['currency'] ?? 'USD'),
            days: isset($r['delivery_days']) ? (int) $r['delivery_days'] : (isset($r['est_delivery_days']) ? (int) $r['est_delivery_days'] : null),
        ), $shipment['rates'] ?? []);
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        // A rate chosen in "Compare rates" arrives as "shp_…|rate_…".
        if ($request->service && preg_match('/^(shp_\w+)\|(rate_\w+)$/', $request->service, $m)) {
            [$shipmentId, $rateId] = [$m[1], $m[2]];
        } else {
            $rates = $this->rates($request);
            $rate = RatePicker::pick($rates, $request->service ?: $this->account->default_service);
            if (! $rate) {
                throw new CourierException('EasyPost: no rate matches "' . ($request->service ?: $this->account->default_service) . '".');
            }
            [$shipmentId, $rateId] = explode('|', $rate->id, 2);
        }

        $res = $this->client()->post(self::BASE . 'shipments/' . $shipmentId . '/buy', ['rate' => ['id' => $rateId]]);
        $s = $res->json() ?? [];
        if (! $res->successful() || empty($s['tracking_code'])) {
            throw new CourierException('EasyPost: ' . $this->error($res));
        }

        $label = $s['postage_label']['label_pdf_url'] ?? $s['postage_label']['label_url'] ?? null;

        return new BookingResult(
            trackingNumber: (string) $s['tracking_code'],
            courierReference: $s['id'] ?? $shipmentId,
            labelUrl: $label,
            charges: isset($s['selected_rate']['rate']) ? (float) $s['selected_rate']['rate'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'pre_transit',
            meta: [
                'carrier' => $s['selected_rate']['carrier'] ?? null,
                'service' => $s['selected_rate']['service'] ?? null,
                'tracker_id' => $s['tracker']['id'] ?? null,
                'public_url' => $s['tracker']['public_url'] ?? null,
            ],
        );
    }

    /** Void the unused label (EasyPost refunds the postage). */
    public function cancel(Shipment $shipment): void
    {
        if (! $shipment->courier_reference) {
            throw new CourierException('EasyPost: the shipment id is missing.');
        }
        $res = $this->client()->post(self::BASE . 'shipments/' . $shipment->courier_reference . '/refund');
        if (! $res->successful() || $res->json('refund_status') === 'rejected') {
            throw new CourierException('EasyPost: ' . ($res->json('refund_status') === 'rejected' ? 'the carrier rejected the refund.' : $this->error($res)));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $trackerId = $shipment->courier_meta['booking']['tracker_id'] ?? null;
        $res = $trackerId
            ? $this->client()->get(self::BASE . 'trackers/' . $trackerId)
            : $this->client()->get(self::BASE . 'shipments/' . $shipment->courier_reference);
        if (! $res->successful()) {
            throw new CourierException('EasyPost: ' . $this->error($res));
        }

        $tracker = $trackerId ? $res->json() : ($res->json('tracker') ?? null);
        if (! is_array($tracker)) {
            throw new CourierException('EasyPost has no tracker for this shipment yet.');
        }

        return $this->toResult($tracker);
    }

    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        throw new CourierException('EasyPost: no label on this shipment.');
    }

    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('webhook_secret');
        if ($secret === '') {
            throw new CourierException('Set the EasyPost webhook secret on the courier account: status updates are rejected until they can be verified.');
        }
        if ($secret !== '') {
            if (class_exists(\Normalizer::class)) {
                $secret = \Normalizer::normalize($secret, \Normalizer::FORM_KD) ?: $secret;
            }
            $expected = 'hmac-sha256-hex=' . hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, (string) $request->header('X-Hmac-Signature'))) {
                throw new CourierException('Invalid EasyPost webhook signature.');
            }
        }

        $tracker = $request->input('result');
        if (! str_starts_with((string) $request->input('description'), 'tracker.') || ! is_array($tracker) || empty($tracker['tracking_code'])) {
            return null;
        }

        return new WebhookUpdate((string) $tracker['tracking_code'], $this->toResult($tracker));
    }

    // ------------------------------------------------------------------

    private function createShipment(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $dims = Shipper::dimensions($this->account);
        $toCountry = $request->countryCode($from['country']);
        $oz = round(max(0.1, $request->weight * 35.274), 1);

        $options = ['label_format' => $this->account->setting('label_format') ?: 'PDF', 'label_size' => '4x6'];
        if ($this->account->setting('enable_cod') && $request->codAmount > 0) {
            $options['cod_amount'] = number_format($request->codAmount, 2, '.', '');
        }

        $body = ['shipment' => array_filter([
            'reference' => $request->reference,
            'to_address' => [
                'name' => $request->consigneeName, 'street1' => $request->address, 'city' => $request->city,
                'state' => $request->state, 'zip' => $request->zip, 'country' => $toCountry,
                'phone' => $request->consigneePhone, 'email' => $request->consigneeEmail,
            ],
            'from_address' => [
                'name' => $from['name'], 'company' => $from['company'] ?: null, 'street1' => $from['street'], 'city' => $from['city'],
                'state' => $from['state'], 'zip' => $from['zip'], 'country' => $from['country'],
                'phone' => $from['phone'], 'email' => $from['email'] ?: null,
            ],
            'parcel' => [
                'length' => round($dims['length'] / 2.54, 1), 'width' => round($dims['width'] / 2.54, 1),
                'height' => round($dims['height'] / 2.54, 1), 'weight' => $oz,
            ],
            'customs_info' => $toCountry && $from['country'] && $toCountry !== $from['country'] ? [
                'customs_certify' => true,
                'customs_signer' => $from['name'],
                'contents_type' => 'merchandise',
                'restriction_type' => 'none',
                'eel_pfc' => 'NOEEI 30.37(a)',
                'non_delivery_option' => 'return',
                'customs_items' => [[
                    'description' => mb_substr($request->itemsDescription ?: 'Merchandise', 0, 45),
                    'quantity' => $request->pieces,
                    'value' => number_format(max(1, $request->declaredValue), 2, '.', ''),
                    'weight' => $oz,
                    'origin_country' => $from['country'],
                ]],
            ] : null,
            'carrier_accounts' => $this->carrierAccounts() ?: null,
            'options' => $options,
        ], fn ($v) => $v !== null)];

        $res = $this->client()->post(self::BASE . 'shipments', $body);
        $shipment = $res->json() ?? [];
        if (! $res->successful() || empty($shipment['id'])) {
            throw new CourierException('EasyPost: ' . $this->error($res));
        }
        if (empty($shipment['rates'])) {
            $why = collect($shipment['messages'] ?? [])->pluck('message')->filter()->implode('; ');
            throw new CourierException('EasyPost returned no rates' . ($why ? ": {$why}" : '.'));
        }

        return $shipment;
    }

    private function toResult(array $t): TrackingResult
    {
        $events = [];
        $returning = false;
        foreach ($t['tracking_details'] ?? [] as $e) {
            $loc = $e['tracking_location'] ?? [];
            $mapped = $this->map($e['status'] ?? null, $e['status_detail'] ?? null);
            // After return-to-sender, a delivery scan means the parcel is back with the merchant.
            if ($mapped === CourierStatus::RETURNING) {
                $returning = true;
            } elseif ($returning && $mapped === CourierStatus::DELIVERED) {
                $mapped = CourierStatus::RETURNED;
            }
            $events[] = new TrackingEvent(
                status: $mapped,
                rawStatus: $e['status_detail'] ?? $e['status'] ?? null,
                at: $this->time($e['datetime'] ?? null),
                location: trim(implode(', ', array_filter([$loc['city'] ?? null, $loc['state'] ?? null, $loc['country'] ?? null]))) ?: null,
                description: $e['message'] ?? null,
            );
        }

        $status = $this->map($t['status'] ?? null, $t['status_detail'] ?? null);
        $last = $events ? $events[count($events) - 1] : null;
        if ($status === CourierStatus::RETURNING && in_array($t['status_detail'] ?? null, ['delivered', 'arrived_at_destination'], true)) {
            $status = CourierStatus::RETURNED;
        }
        if ($last?->status === CourierStatus::RETURNED) {
            $status = CourierStatus::RETURNED;
        }

        return new TrackingResult(
            status: $status,
            rawStatus: $t['status_detail'] ?? $t['status'] ?? null,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? ($last?->at) : null,
            returnedAt: $status === CourierStatus::RETURNED ? ($last?->at) : null,
        );
    }

    private function map(?string $status, ?string $detail): ?string
    {
        if ($status === 'in_transit' && in_array($detail, ['received_at_origin_facility', 'departed_origin_facility'], true)) {
            return CourierStatus::PICKED_UP;
        }
        if ($status === 'in_transit' && in_array($detail, ['delivery_exception', 'refused'], true)) {
            return CourierStatus::FAILED_ATTEMPT;
        }

        return self::STATUSES[$status ?? ''] ?? null;
    }

    private function carrierAccounts(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->account->setting('carrier_accounts')))));
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
        return $this->http()->withBasicAuth($this->account->credential('api_key'), '');
    }

    private function error(Response $res): string
    {
        $msg = $res->json('error.message');
        $fields = collect($res->json('error.errors') ?? [])->map(fn ($e) => trim(($e['field'] ?? '') . ' ' . ($e['message'] ?? '')))->filter()->implode('; ');

        return trim(($msg ?: 'HTTP ' . $res->status()) . ($fields ? " ({$fields})" : ''));
    }
}
