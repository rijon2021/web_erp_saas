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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OTO (Saudi Arabia / GCC aggregator) — REST API v2, from OTO's official
 * Postman collection. Production https://api.tryoto.com/rest/v2/, sandbox
 * https://staging-api.tryoto.com/rest/v2/. A permanent refresh token buys a
 * 1-hour Bearer access token (cached). Booking = createOrder with
 * createShipment + deliveryOptionId; the carrier AWB is assigned
 * asynchronously, so the shipment is tracked by the OTO orderId Stocky sends
 * (the tracking number stored here). Webhook "orderStatus" is signed:
 * base64(HMAC-SHA256("orderId:status:timestamp", secretKey)).
 */
class OtoDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://api.tryoto.com/rest/v2/';
    private const SANDBOX = 'https://staging-api.tryoto.com/rest/v2/';

    /** Dialling codes of the GCC countries OTO serves. */
    private const DIAL = ['SA' => '966', 'AE' => '971', 'KW' => '965', 'BH' => '973', 'QA' => '974', 'OM' => '968'];

    private const STATUSES = [
        'searchingDriver' => CourierStatus::BOOKED, 'shipmentCreated' => CourierStatus::BOOKED,
        'goingToPickup' => CourierStatus::BOOKED, 'arrivedPickup' => CourierStatus::BOOKED, 'pickupAttemted' => CourierStatus::BOOKED,
        'pickedUp' => CourierStatus::PICKED_UP,
        'arrivedOriginTerminal' => CourierStatus::IN_TRANSIT, 'departedTerminal' => CourierStatus::IN_TRANSIT,
        'arrivedTerminal' => CourierStatus::IN_TRANSIT, 'inTransit' => CourierStatus::IN_TRANSIT,
        'arrivedDestinationTerminal' => CourierStatus::IN_TRANSIT, 'arrivedDestination' => CourierStatus::IN_TRANSIT,
        'shipmentInProgress' => CourierStatus::IN_TRANSIT, 'shipmentOnHold' => CourierStatus::IN_TRANSIT,
        'heldForPickup' => CourierStatus::IN_TRANSIT, 'informationUpdate' => CourierStatus::IN_TRANSIT,
        'outForDelivery' => CourierStatus::OUT_FOR_DELIVERY,
        'undeliveredAttempt' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED,
        'returnProcessing' => CourierStatus::RETURNING,
        'returned' => CourierStatus::RETURNED,
        'shipmentCanceled' => CourierStatus::CANCELLED, 'canceled' => CourierStatus::CANCELLED, 'deleted' => CourierStatus::CANCELLED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'oto',
            'name' => 'OTO',
            'region' => 'Saudi Arabia / GCC',
            'countries' => ['SA', 'AE', 'KW', 'BH', 'QA', 'OM'],
            'website' => 'https://tryoto.com',
            'tracking_url' => null,
            'description' => 'Aramex, SMSA, J&T, SPL and more through OTO: compare carriers, COD booking, labels and signed status webhooks.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'refresh_token', 'label' => 'Refresh token', 'type' => 'password', 'required' => true,
                    'help' => 'OTO → Settings → API Integrations → Connect.'],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret key', 'type' => 'password',
                    'help' => 'Required for instant status updates. The secretKey of your OTO webhook; used to verify status updates.'],
            ],
            'settings' => array_merge([
                ['key' => 'pickup_location_code', 'label' => 'Pickup location code', 'type' => 'text', 'required' => true,
                    'help' => '"Test connection" lists your OTO pickup locations.'],
                ['key' => 'origin_city', 'label' => 'Origin city', 'type' => 'text',
                    'help' => 'City you ship from, as OTO names it (used for rates when the pickup city is empty).'],
                ['key' => 'country', 'label' => 'Country (ISO code)', 'type' => 'text', 'help' => 'Leave empty for SA.'],
                ['key' => 'currency', 'label' => 'Currency', 'type' => 'text', 'help' => 'Leave empty for SAR.'],
            ], array_slice(Shipper::fields(), 5)),
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $info = $this->client()->get($this->base() . 'accountInfo');
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }
        if (! $info->successful()) {
            return ConnectionResult::failed('OTO: ' . $this->error($info));
        }

        $loc = $this->client()->get($this->base() . 'getPickupLocationList', ['status' => 'active']);
        $codes = collect(array_merge($loc->json('warehouses') ?? [], $loc->json('branches') ?? []))
            ->map(fn ($w) => ($w['code'] ?? '?') . ' (' . ($w['city'] ?? '') . ')')->implode(', ');
        $credit = $info->json('remainingCredit');

        return ConnectionResult::ok('Connected to OTO' . ($credit !== null ? " (credit {$credit})" : '') . '.' . ($codes ? " Pickup locations: {$codes}." : ''));
    }

    public function rates(ShipmentRequest $request): array
    {
        $dims = Shipper::dimensions($this->account);
        $res = $this->client()->post($this->base() . 'checkOTODeliveryFee', array_filter([
            'originCity' => $this->account->pickup_city ?: $this->account->setting('origin_city'),
            'destinationCity' => $request->courierCityName ?: $request->city,
            'weight' => max(0.1, round($request->weight, 2)),
            'length' => $dims['length'], 'width' => $dims['width'], 'height' => $dims['height'],
            'totalDue' => round($request->codAmount, 2),
            'currency' => $this->currency(),
        ], fn ($v) => $v !== null && $v !== ''));
        if (! $res->successful() || $res->json('success') === false) {
            throw new CourierException('OTO: ' . $this->error($res));
        }

        return collect($res->json('deliveryCompany') ?? [])->map(fn ($o) => new Rate(
            id: (string) $o['deliveryOptionId'],
            carrier: (string) ($o['deliveryCompanyName'] ?? ''),
            service: (string) ($o['deliveryOptionName'] ?? ($o['serviceType'] ?? '')),
            amount: (float) ($o['price'] ?? 0) + ($request->codAmount > 0 ? (float) ($o['codCharge'] ?? 0) : 0),
            currency: (string) ($o['currency'] ?? $this->currency()),
            days: isset($o['avgDeliveryTime']) && is_numeric($o['avgDeliveryTime']) ? (int) $o['avgDeliveryTime'] : null,
        ))->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $optionId = $request->service && ctype_digit((string) $request->service) ? (int) $request->service : null;
        if (! $optionId) {
            $rate = RatePicker::pick($this->rates($request), $request->service ?: $this->account->default_service);
            if (! $rate) {
                throw new CourierException('OTO: no delivery option matches "' . ($request->service ?: $this->account->default_service) . '".');
            }
            $optionId = (int) $rate->id;
        }

        $dims = Shipper::dimensions($this->account);
        // OTO order ids are unique per account; a suffix lets a cancelled sale be booked again.
        $orderId = mb_substr($request->reference, 0, 40) . '-' . Str::lower(Str::random(4));
        $country = $request->countryCode($this->account->setting('country') ?: 'SA');

        $body = array_filter([
            'orderId' => $orderId,
            'payment_method' => $request->codAmount > 0 ? 'cod' : 'paid',
            'amount' => round(max($request->declaredValue, $request->codAmount), 2),
            'amount_due' => round($request->codAmount, 2),
            'currency' => $this->currency(),
            'customer' => array_filter([
                'name' => $request->consigneeName,
                'mobile' => Phone::intl($request->consigneePhone, self::DIAL[$country] ?? '966'),
                'email' => $request->consigneeEmail,
                'address' => $request->address,
                'city' => $request->courierCityName ?: $request->city,
                'state' => $request->state,
                'postcode' => $request->zip,
                'country' => $country,
            ], fn ($v) => $v !== null && $v !== ''),
            // price is per unit: OTO multiplies it by quantity.
            'items' => [['name' => mb_substr($request->itemsDescription ?: 'Goods', 0, 190), 'quantity' => $request->pieces, 'price' => round($request->declaredValue / max(1, $request->pieces), 2)]],
            'packageWeight' => max(0.1, round($request->weight, 2)),
            'packageCount' => $request->pieces,
            'boxLength' => $dims['length'], 'boxWidth' => $dims['width'], 'boxHeight' => $dims['height'],
            'pickupLocationCode' => (string) $this->account->setting('pickup_location_code'),
            'shippingNotes' => $request->notes ?: null,
            'ref1' => $request->reference,
            'createShipment' => true,
            'deliveryOptionId' => $optionId,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . 'createOrder', $body);
        if (! $res->successful() || $res->json('success') !== true) {
            throw new CourierException('OTO: ' . $this->error($res));
        }

        return new BookingResult(
            trackingNumber: $orderId,
            courierReference: $res->json('otoId') !== null ? (string) $res->json('otoId') : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'shipmentCreated',
            meta: ['delivery_option_id' => $optionId],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $status = $this->client()->post($this->base() . 'orderStatus', ['orderId' => $shipment->tracking_number]);
        $shipmentId = $status->json('shipmentId');

        $res = $shipmentId
            ? $this->client()->post($this->base() . 'cancelShipment', ['orderId' => $shipment->tracking_number, 'shipmentId' => (string) $shipmentId])
            : $this->client()->post($this->base() . 'cancelOrder', ['orderId' => $shipment->tracking_number]);

        if (! $res->successful() || $res->json('success') !== true) {
            throw new CourierException('OTO: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->post($this->base() . 'orderHistory', ['orderIds' => [(string) $shipment->tracking_number]]);
        $item = $res->json('items.0');
        if (! $res->successful() || ! is_array($item)) {
            throw new CourierException('OTO: ' . $this->error($res));
        }

        $events = [];
        foreach ($item['history'] ?? [] as $h) {
            $loc = $h['currentLocation'] ?? [];
            $events[] = new TrackingEvent(
                status: self::STATUSES[$h['status'] ?? ''] ?? null,
                rawStatus: $h['status'] ?? null,
                at: $this->time($h['date'] ?? null),
                location: is_array($loc) ? (trim(implode(', ', array_filter([$loc['currentCity'] ?? null, $loc['currentCountry'] ?? null]))) ?: null) : null,
                description: trim(($h['description'] ?? '') . (! empty($h['deliveryCompany']) ? ' · ' . $h['deliveryCompany'] : ''), ' ·') ?: null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = $item['status'] ?? null;
        $status = self::STATUSES[$raw ?? ''] ?? null;

        return new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED && $events ? $events[count($events) - 1]->at : null,
            meta: ['awb' => $item['shipmentId'] ?? null, 'carrier' => $item['deliveryCompany'] ?? null],
        );
    }

    /** OTO's hosted airway bill page. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->get($this->base() . 'print/' . rawurlencode((string) $shipment->tracking_number));
        $url = $res->json('printAWBURL');
        if (! $res->successful() || ! $url) {
            throw new CourierException('OTO: ' . ($res->json('success') === true ? 'the airway bill is not ready yet.' : $this->error($res)));
        }

        return ['url' => $url];
    }

    public function cities(): array
    {
        $country = strtoupper((string) ($this->account->setting('country') ?: 'SA'));
        $cities = [];
        for ($page = 1; $page <= 10; $page++) {
            $res = $this->client()->post($this->base() . 'getCities', ['country' => $country, 'perPage' => 500, 'page' => $page]);
            if (! $res->successful()) {
                throw new CourierException('OTO: ' . $this->error($res));
            }
            $batch = $res->json('getCities.Cities') ?? [];
            foreach ($batch as $c) {
                if (! empty($c['name'])) {
                    $cities[] = ['code' => $c['name'], 'name' => $c['name']];
                }
            }
            if (count($batch) < 500) {
                break;
            }
        }

        return $cities;
    }

    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $orderId = (string) $request->input('orderId');
        $status = (string) $request->input('status');
        $timestamp = (string) $request->input('timestamp');

        $secret = $this->account->credential('webhook_secret');
        if ($secret === '') {
            throw new CourierException('Set the OTO webhook secret key on the courier account: status updates are rejected until they can be verified.');
        }
        if ($secret !== '') {
            $expected = base64_encode(hash_hmac('sha256', "{$orderId}:{$status}:{$timestamp}", $secret, true));
            if (! hash_equals($expected, (string) $request->input('signature'))) {
                throw new CourierException('Invalid OTO webhook signature.');
            }
        }

        if ($orderId === '') {
            return null;
        }

        // shipmentError carries no status: record it on the timeline only.
        if ($status === '' && $request->filled('errorMessage')) {
            return new WebhookUpdate($orderId, new TrackingResult(status: null, events: [
                new TrackingEvent(null, 'shipmentError', $this->epoch($timestamp), null, (string) $request->input('errorMessage')),
            ]));
        }

        $mapped = self::STATUSES[$status] ?? null;
        $at = $this->epoch($timestamp);

        return new WebhookUpdate($orderId, new TrackingResult(
            status: $mapped,
            rawStatus: $status ?: null,
            events: [new TrackingEvent($mapped, $status ?: null, $at, null,
                trim(($request->input('note') ?? '') . ($request->filled('deliveryCompany') ? ' · ' . $request->input('deliveryCompany') : ''), ' ·') ?: null)],
            deliveredAt: $mapped === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    private function accessToken(): string
    {
        $key = 'courier_oto_token:' . (tenant('id') ?? 'central') . ':' . md5($this->account->mode . '|' . $this->account->credential('refresh_token'));

        return Cache::get($key) ?? (function () use ($key) {
            $res = $this->http()->post($this->base() . 'refreshToken', ['refresh_token' => $this->account->credential('refresh_token')]);
            $token = $res->json('access_token');
            if (! $res->successful() || ! $token) {
                throw new CourierException('OTO: refresh token rejected (' . $this->error($res) . ').');
            }
            $ttl = max(60, ((int) $res->json('expires_in', 3600)) - 300);
            Cache::put($key, $token, now()->addSeconds($ttl));

            return $token;
        })();
    }

    private function client()
    {
        return $this->http()->withToken($this->accessToken());
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE;
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->account->setting('currency') ?: 'SAR'));
    }

    private function epoch(string $ms): ?Carbon
    {
        return ctype_digit($ms) ? Carbon::createFromTimestampMs((int) $ms) : $this->time($ms);
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
        return $res->json('otoErrorMessage') ?: $res->json('errorMsg') ?: $res->json('message')
            ?: ($res->json('error.message') ?: ('HTTP ' . $res->status()));
    }
}
