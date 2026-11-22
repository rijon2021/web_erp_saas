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
 * Shippo — USPS, UPS, FedEx, DHL and more. https://api.goshippo.com,
 * `Authorization: ShippoToken <token>`, `Shippo-API-Version: 2018-02-08`
 * (test/live decided by the token). Flow: POST /shipments/ (async false →
 * rates) → POST /transactions/ {rate} (async false → label). Refund voids
 * an unused label. Webhooks (track_updated / transaction_updated) are
 * protected by the secret token in this account's URL; Shippo's optional
 * HMAC (enabled by Shippo on request) is verified when a secret is set.
 */
class ShippoDriver extends AbstractCourierDriver
{
    private const BASE = 'https://api.goshippo.com/';
    private const VERSION = '2018-02-08';

    public static function meta(): array
    {
        return [
            'code' => 'shippo',
            'name' => 'Shippo',
            'region' => 'USA / Global',
            'countries' => [],
            'website' => 'https://goshippo.com',
            'tracking_url' => null,
            'description' => 'USPS, UPS, FedEx, DHL and more through one Shippo account: compare rates, buy labels, track and void.',
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
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'required' => true,
                    'help' => 'Shippo → Settings → API. shippo_test_… buys free test labels.'],
                ['key' => 'hmac_secret', 'label' => 'Webhook HMAC secret', 'type' => 'password',
                    'help' => 'Only if Shippo enabled HMAC signing for your account.'],
            ],
            'settings' => array_merge(Shipper::fields(), [
                ['key' => 'carrier_accounts', 'label' => 'Carrier account IDs', 'type' => 'text',
                    'help' => 'Optional, comma-separated Shippo carrier account IDs to limit rating.'],
                ['key' => 'label_file_type', 'label' => 'Label format', 'type' => 'select', 'options' => [
                    ['value' => 'PDF_4x6', 'label' => 'PDF 4×6'], ['value' => 'PDF', 'label' => 'PDF (letter)'],
                    ['value' => 'PDF_A6', 'label' => 'PDF A6'], ['value' => 'PNG', 'label' => 'PNG'], ['value' => 'ZPLII', 'label' => 'ZPL'],
                ], 'help' => 'Leave empty for PDF 4×6.'],
                ['key' => 'enable_cod', 'label' => 'Ask the carrier to collect COD (UPS, USD)', 'type' => 'switch'],
            ]),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get(self::BASE . 'carrier_accounts/', ['results' => 25]);
        if (! $res->successful()) {
            return ConnectionResult::failed('Shippo: ' . $this->error($res));
        }

        $carriers = collect($res->json('results') ?? [])->where('active', true)->pluck('carrier')->unique()->implode(', ');

        return ConnectionResult::ok('Connected to Shippo.' . ($carriers ? " Active carriers: {$carriers}." : ''));
    }

    public function rates(ShipmentRequest $request): array
    {
        $shipment = $this->createShipment($request);

        return array_map(fn ($r) => new Rate(
            id: (string) $r['object_id'],
            carrier: (string) ($r['provider'] ?? ''),
            service: (string) ($r['servicelevel']['name'] ?? ''),
            amount: (float) ($r['amount'] ?? 0),
            currency: (string) ($r['currency'] ?? 'USD'),
            days: isset($r['estimated_days']) ? (int) $r['estimated_days'] : null,
            meta: ['carrier_account' => $r['carrier_account'] ?? null, 'servicelevel' => $r['servicelevel']['token'] ?? null],
        ), $shipment['rates'] ?? []);
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        // A rate chosen in "Compare rates" is a 32-hex Shippo object id.
        if ($request->service && preg_match('/^[a-f0-9]{32}$/i', $request->service)) {
            $rateId = $request->service;
        } else {
            $rate = RatePicker::pick($this->rates($request), $request->service ?: $this->account->default_service);
            if (! $rate) {
                throw new CourierException('Shippo: no rate matches "' . ($request->service ?: $this->account->default_service) . '".');
            }
            $rateId = $rate->id;
        }

        $res = $this->client()->post(self::BASE . 'transactions/', [
            'rate' => $rateId,
            'label_file_type' => $this->account->setting('label_file_type') ?: 'PDF_4x6',
            'async' => false,
            'metadata' => mb_substr($request->reference, 0, 100),
        ]);
        $t = $res->json() ?? [];
        if (! $res->successful() || ($t['status'] ?? '') !== 'SUCCESS' || empty($t['tracking_number'])) {
            $why = collect($t['messages'] ?? [])->pluck('text')->filter()->implode('; ');
            throw new CourierException('Shippo: ' . ($why ?: $this->error($res)));
        }

        $rate = is_array($t['rate'] ?? null) ? $t['rate'] : $this->fetch('rates/' . $rateId);

        return new BookingResult(
            trackingNumber: (string) $t['tracking_number'],
            courierReference: (string) $t['object_id'],
            labelUrl: $t['label_url'] ?? null,
            charges: isset($rate['amount']) ? (float) $rate['amount'] : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'PRE_TRANSIT',
            meta: [
                'carrier' => $this->carrierToken($rate),
                'provider' => $rate['provider'] ?? null,
                'service' => $rate['servicelevel']['name'] ?? null,
                'tracking_url_provider' => $t['tracking_url_provider'] ?? null,
            ],
        );
    }

    /** Refund (void) the unused label. */
    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post(self::BASE . 'refunds/', ['transaction' => $shipment->courier_reference, 'async' => false]);
        if (! $res->successful() || $res->json('status') === 'ERROR') {
            throw new CourierException('Shippo: ' . ($res->json('status') === 'ERROR' ? 'the refund was rejected (label already used?).' : $this->error($res)));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $carrier = $shipment->courier_meta['booking']['carrier'] ?? null;
        if (! $carrier) {
            throw new CourierException('Shippo: the carrier of this label is unknown.');
        }
        $res = $this->client()->get(self::BASE . 'tracks/' . rawurlencode($carrier) . '/' . rawurlencode((string) $shipment->tracking_number));
        if (! $res->successful()) {
            throw new CourierException('Shippo: ' . $this->error($res));
        }

        return $this->toResult($res->json() ?? []);
    }

    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        throw new CourierException('Shippo: no label on this shipment.');
    }

    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $secret = $this->account->credential('hmac_secret');
        if ($secret !== '') {
            $header = (string) $request->header('Shippo-Auth-Signature');
            parse_str(str_replace(',', '&', $header), $parts);
            $expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $request->getContent(), $secret);
            if (empty($parts['v1']) || ! hash_equals($expected, (string) $parts['v1'])) {
                throw new CourierException('Invalid Shippo webhook signature.');
            }
        }

        $event = $request->input('event');
        $data = $request->input('data');
        if (! is_array($data) || empty($data['tracking_number'])) {
            return null;
        }

        if ($event === 'track_updated') {
            return new WebhookUpdate((string) $data['tracking_number'], $this->toResult($data));
        }
        if ($event === 'transaction_updated') {
            $status = $this->trackingStatusOf($data['tracking_status'] ?? null);

            return new WebhookUpdate((string) $data['tracking_number'], new TrackingResult(status: $this->map($status, null), rawStatus: $status));
        }

        return null;
    }

    // ------------------------------------------------------------------

    private function createShipment(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $dims = $request->dimensions() ?? Shipper::dimensions($this->account);
        $toCountry = $request->countryCode($from['country']);

        $body = array_filter([
            'address_from' => array_filter([
                'name' => $from['name'], 'company' => $from['company'] ?: null, 'street1' => $from['street'], 'city' => $from['city'],
                'state' => $from['state'] ?: null, 'zip' => $from['zip'], 'country' => $from['country'],
                'phone' => $from['phone'] ?: null, 'email' => $from['email'] ?: null,
            ]),
            'address_to' => array_filter([
                'name' => $request->consigneeName, 'street1' => $request->address, 'city' => $request->city,
                'state' => $request->state, 'zip' => $request->zip, 'country' => $toCountry,
                'phone' => $request->consigneePhone, 'email' => $request->consigneeEmail,
            ]),
            'parcels' => [[
                'length' => (string) $dims['length'], 'width' => (string) $dims['width'], 'height' => (string) $dims['height'],
                'distance_unit' => 'cm',
                'weight' => (string) max(0.01, round($request->weight, 3)), 'mass_unit' => 'kg',
            ]],
            'customs_declaration' => $toCountry && $from['country'] && $toCountry !== $from['country'] ? [
                'certify' => true,
                'certify_signer' => $from['name'],
                'contents_type' => 'MERCHANDISE',
                'non_delivery_option' => 'RETURN',
                'eel_pfc' => 'NOEEI_30_37_a',
                'items' => [[
                    'description' => mb_substr($request->itemsDescription ?: 'Merchandise', 0, 45),
                    'quantity' => $request->pieces,
                    'net_weight' => (string) max(0.01, round($request->weight, 3)),
                    'mass_unit' => 'kg',
                    'value_amount' => (string) max(1, round($request->declaredValue, 2)),
                    'value_currency' => 'USD',
                    'origin_country' => $from['country'],
                ]],
            ] : null,
            'extra' => $this->account->setting('enable_cod') && $request->codAmount > 0
                ? ['COD' => ['amount' => number_format($request->codAmount, 2, '.', ''), 'currency' => 'USD', 'payment_method' => 'ANY']]
                : null,
            'carrier_accounts' => $this->carrierAccounts() ?: null,
            'metadata' => mb_substr($request->reference, 0, 100),
            'async' => false,
        ], fn ($v) => $v !== null);

        $res = $this->client()->post(self::BASE . 'shipments/', $body);
        $shipment = $res->json() ?? [];
        if (! $res->successful() || empty($shipment['object_id'])) {
            throw new CourierException('Shippo: ' . $this->error($res));
        }
        if (empty($shipment['rates'])) {
            $why = collect($shipment['messages'] ?? [])->pluck('text')->filter()->implode('; ');
            throw new CourierException('Shippo returned no rates' . ($why ? ": {$why}" : '.'));
        }

        return $shipment;
    }

    /** Carrier token for /tracks: from the rate's carrier account, else the service-level token. */
    private function carrierToken(array $rate): ?string
    {
        if (! empty($rate['carrier_account'])) {
            $account = $this->fetch('carrier_accounts/' . $rate['carrier_account']);
            if (! empty($account['carrier'])) {
                return (string) $account['carrier'];
            }
        }
        $token = (string) ($rate['servicelevel']['token'] ?? '');
        foreach (['dhl_express', 'dhl_ecommerce', 'canada_post', 'australia_post', 'usps', 'ups', 'fedex'] as $carrier) {
            if (str_starts_with($token, $carrier)) {
                return $carrier;
            }
        }

        return isset($rate['provider']) ? strtolower(str_replace(' ', '_', $rate['provider'])) : null;
    }

    private function fetch(string $path): array
    {
        try {
            $res = $this->client()->get(self::BASE . $path);

            return $res->successful() ? ($res->json() ?? []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function toResult(array $track): TrackingResult
    {
        $events = [];
        foreach ($track['tracking_history'] ?? [] as $e) {
            $loc = $e['location'] ?? [];
            $events[] = new TrackingEvent(
                status: $this->map($e['status'] ?? null, $e['substatus']['code'] ?? null),
                rawStatus: $e['substatus']['text'] ?? $e['status'] ?? null,
                at: $this->time($e['status_date'] ?? null),
                location: is_array($loc) ? (trim(implode(', ', array_filter([$loc['city'] ?? null, $loc['state'] ?? null, $loc['country'] ?? null]))) ?: null) : null,
                description: $e['status_details'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $current = is_array($track['tracking_status'] ?? null) ? $track['tracking_status'] : ['status' => $track['tracking_status'] ?? null];
        $status = $this->map($current['status'] ?? null, $current['substatus']['code'] ?? null);

        return new TrackingResult(
            status: $status,
            rawStatus: $current['substatus']['text'] ?? $current['status'] ?? null,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($current['status_date'] ?? null) : null,
        );
    }

    private function map(?string $status, ?string $sub): ?string
    {
        return match (strtoupper((string) $status)) {
            'PRE_TRANSIT' => CourierStatus::BOOKED,
            'TRANSIT' => match ($sub) {
                'package_accepted' => CourierStatus::PICKED_UP,
                'out_for_delivery' => CourierStatus::OUT_FOR_DELIVERY,
                'package_held', 'delivery_rescheduled' => CourierStatus::FAILED_ATTEMPT,
                default => CourierStatus::IN_TRANSIT,
            },
            'FAILURE' => CourierStatus::FAILED_ATTEMPT,
            'DELIVERED' => CourierStatus::DELIVERED,
            'RETURNED' => $sub === 'return_to_sender' ? CourierStatus::RETURNING : CourierStatus::RETURNED,
            default => null,
        };
    }

    private function trackingStatusOf($value): ?string
    {
        return is_array($value) ? ($value['status'] ?? null) : ($value !== null ? (string) $value : null);
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
        return $this->http()->withHeaders([
            'Authorization' => 'ShippoToken ' . $this->account->credential('api_token'),
            'Shippo-API-Version' => self::VERSION,
        ]);
    }

    private function error(Response $res): string
    {
        $json = $res->json();
        if (is_array($json)) {
            $flat = collect($json)->flatten()->filter(fn ($v) => is_string($v))->take(3)->implode('; ');
            if ($flat !== '') {
                return $flat;
            }
        }

        return 'HTTP ' . $res->status();
    }
}
