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
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;

/**
 * PostEx (Pakistan, COD). Built from the official "Merchant API Integration
 * Guide v4.1.9". Base https://api.postex.pk/services/integration/api/order/,
 * header `token`. Envelope {statusCode:"200", statusMessage, dist}. Cities
 * are names (operationalCityName); the airway bill is a PDF; COD settlement
 * comes from payment-status. No webhooks — status is polled. New orders stay
 * "Unbooked" until a load sheet is generated in the PostEx portal.
 */
class PostExDriver extends AbstractCourierDriver
{
    private const BASE = 'https://api.postex.pk/services/integration/api/order/';

    /** transactionStatus (lower-case) → Stocky status. */
    private const STATUSES = [
        'unbooked' => CourierStatus::BOOKED,
        'booked' => CourierStatus::BOOKED,
        'picked by postex' => CourierStatus::PICKED_UP,
        'en-route to postex warehouse' => CourierStatus::PICKED_UP,
        'postex warehouse' => CourierStatus::IN_TRANSIT,
        'out for delivery' => CourierStatus::OUT_FOR_DELIVERY,
        'attempted' => CourierStatus::FAILED_ATTEMPT,
        'delivery under review' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED,
        'out for return' => CourierStatus::RETURNING,
        'returned' => CourierStatus::RETURNED,
        'un-assigned by me' => CourierStatus::CANCELLED,
        'expired' => CourierStatus::CANCELLED,
    ];

    /** transactionStatusMessageCode → Stocky status (history lines). */
    private const HISTORY_CODES = [
        '0001' => CourierStatus::BOOKED,
        '0002' => CourierStatus::RETURNED,
        '0003' => CourierStatus::IN_TRANSIT,
        '0004' => CourierStatus::IN_TRANSIT,
        '0005' => CourierStatus::DELIVERED,
        '0006' => CourierStatus::RETURNED,
        '0007' => CourierStatus::RETURNED,
        '0008' => CourierStatus::FAILED_ATTEMPT,
        '0013' => CourierStatus::FAILED_ATTEMPT,
    ];

    /**
     * PostEx wording → Stocky status. Transit lines name the city
     * ("En-Route to Islamabad warehouse", "Arrived at Lahore warehouse"),
     * so they never match the fixed list; PostEx shows them as In-Transit.
     */
    private static function statusFor(?string $raw): ?string
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $raw)));
        if (isset(self::STATUSES[$t])) {
            return self::STATUSES[$t];
        }
        if (preg_match('/\bwarehouse\b/u', $t) && ! preg_match('/\breturn/u', $t)) {
            return CourierStatus::IN_TRANSIT;
        }

        return StatusText::map($raw);
    }

    public static function meta(): array
    {
        return [
            'code' => 'postex',
            'name' => 'PostEx',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://postex.pk',
            // The public tracking page reads ?cn= and looks the parcel up on load.
            'tracking_url' => 'https://postex.pk/tracking?cn={tracking}',
            'description' => 'COD deliveries across Pakistan. Orders, airway bills, tracking and COD settlement through the PostEx merchant API.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
                // "items" on create-order = number of items in the order, not parcels.
                'pieces_are_items' => true,
            ],
            'credentials' => [
                ['key' => 'token', 'label' => 'API token', 'type' => 'password', 'required' => true,
                    'help' => 'PostEx merchant portal → Settings → API token.'],
            ],
            'settings' => [
                ['key' => 'pickup_address_code', 'label' => 'Pickup address code', 'type' => 'text',
                    'help' => 'The addressCode of your pickup address. "Test connection" lists your codes. Empty = your only (or default) PostEx address.'],
                ['key' => 'store_address_code', 'label' => 'Store address code', 'type' => 'text',
                    'help' => 'Optional. Leave empty unless PostEx assigned you store codes for store-wise pickups; it is not your pickup address code.'],
                ['key' => 'order_type', 'label' => 'Order type', 'type' => 'select', 'options' => [
                    ['value' => 'Normal', 'label' => 'Normal'],
                    ['value' => 'Reversed', 'label' => 'Reversed'],
                    ['value' => 'Replacement', 'label' => 'Replacement'],
                ], 'help' => 'Leave empty for Normal.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get(self::BASE . 'v1/get-merchant-address');
        if (! $this->ok($res)) {
            return ConnectionResult::failed($this->error($res));
        }

        $codes = collect($res->json('dist') ?? [])
            ->map(fn ($a) => ($a['addressCode'] ?? '?') . ' (' . ($a['cityName'] ?? '') . ')')
            ->implode(', ');

        return ConnectionResult::ok('Connected to PostEx.' . ($codes ? " Pickup address codes: {$codes}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $body = array_filter([
            'orderRefNumber' => $request->reference,
            'invoicePayment' => round($request->codAmount, 2),
            'orderDetail' => $request->itemsDescription ?: null,
            'customerName' => $request->consigneeName,
            'customerPhone' => Phone::pk($request->consigneePhone),
            'deliveryAddress' => $request->address,
            'transactionNotes' => $request->notes ?: null,
            'cityName' => $request->courierCityName ?: $request->city,
            'invoiceDivision' => 1,
            'items' => $request->pieces,
            'pickupAddressCode' => $this->pickupAddressCode(),
            'storeAddressCode' => $this->account->setting('store_address_code'),
            'orderType' => $this->orderType($request->service)
                ?? $this->orderType($this->account->setting('order_type'))
                ?? 'Normal',
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post(self::BASE . 'v3/create-order', $body);
        $tracking = $res->json('dist.trackingNumber');
        if (! $this->ok($res) || ! $tracking) {
            throw new CourierException('PostEx: ' . $this->error($res) . $this->addressCodeHint($this->error($res), $body));
        }

        return new BookingResult(
            trackingNumber: (string) $tracking,
            status: CourierStatus::BOOKED,
            rawStatus: $res->json('dist.orderStatus'),
            meta: ['order_date' => $res->json('dist.orderDate')],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->put(self::BASE . 'v1/cancel-order', ['trackingNumber' => $shipment->tracking_number]);
        // PostEx answers HTTP 200 with statusCode != "200" when it refuses.
        if (! $this->ok($res)) {
            throw new CourierException('PostEx: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get(self::BASE . 'v1/track-order/' . rawurlencode((string) $shipment->tracking_number));
        if (! $this->ok($res)) {
            throw new CourierException('PostEx: ' . $this->error($res));
        }
        $d = $res->json('dist') ?? [];

        $events = [];
        foreach ($d['transactionStatusHistory'] ?? [] as $h) {
            $text = $h['transactionStatusMessage'] ?? null;
            $code = (string) ($h['transactionStatusMessageCode'] ?? '');
            // The guide documents no time per history line; read one if present.
            $at = $h['updatedAt'] ?? $h['createdAt'] ?? $h['transactionDate'] ?? $h['date'] ?? null;
            $events[] = new TrackingEvent(
                status: self::HISTORY_CODES[$code] ?? self::statusFor($text),
                rawStatus: $text,
                at: $this->time($at),
                description: $code !== '' ? "Code {$code}" : null,
            );
        }

        $raw = $d['transactionStatus'] ?? null;
        $status = self::statusFor($raw);

        $charges = null;
        $fees = (float) ($d['transactionFee'] ?? 0) + (float) ($d['transactionTax'] ?? 0)
            + (float) ($d['reversalFee'] ?? 0) + (float) ($d['reversalTax'] ?? 0);
        if ($fees > 0) {
            $charges = round($fees, 2);
        }

        [$codStatus, $codRef, $codAt] = $status === CourierStatus::DELIVERED ? $this->settlement($shipment) : [null, null, null];

        return new TrackingResult(
            status: $status,
            rawStatus: $raw,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($d['orderDeliveryDate'] ?? null) : null,
            codStatus: $codStatus,
            codReference: $codRef,
            codRemittedAt: $codAt,
            charges: $charges,
        );
    }

    /** The airway bill as PDF bytes. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->withHeaders(['Accept' => 'application/pdf'])
            ->get(self::BASE . 'v1/get-invoice', ['trackingNumbers' => $shipment->tracking_number]);

        if (! $res->successful() || ! str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            throw new CourierException('PostEx: ' . $this->error($res));
        }

        return ['pdf' => $res->body()];
    }

    /** PostEx cities are names; the name doubles as the code. */
    public function cities(): array
    {
        // No operationalCityType filter: PostEx rejects "Delivery" ("Failed to convert value of
        // type 'java.lang.String' to required type 'com.postex.enums…'"). The unfiltered list
        // flags each city with isDeliveryCity, which the filter below applies instead.
        $res = $this->client()->get(self::BASE . 'v2/get-operational-city');
        if (! $this->ok($res)) {
            $res = $this->client()->get(self::BASE . 'v1/get-operational-city');
        }
        if (! $this->ok($res)) {
            throw new CourierException('PostEx: ' . $this->error($res));
        }

        return collect($res->json('dist') ?? [])
            // The flag may arrive as a boolean or as "true"/"false"; a missing one counts as deliverable.
            ->filter(fn ($c) => filter_var($c['isDeliveryCity'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                && ! empty($c['operationalCityName']))
            ->map(fn ($c) => ['code' => $c['operationalCityName'], 'name' => $c['operationalCityName']])
            ->values()->all();
    }

    // ------------------------------------------------------------------

    /** Which courier-account setting a PostEx address-code rejection is about, and how to fix it. */
    private function addressCodeHint(string $error, array $body): string
    {
        $e = mb_strtoupper($error);
        if (str_contains($e, 'STORE ADDRESS CODE')) {
            return ' — the "Store address code" ("' . ($body['storeAddressCode'] ?? '') . '") on this courier account is not one PostEx knows.'
                . ' It is optional: clear it in Couriers → Courier Accounts unless PostEx gave you store codes.';
        }
        if (str_contains($e, 'PICKUP ADDRESS CODE')) {
            return ' — check the "Pickup address code" ("' . ($body['pickupAddressCode'] ?? '') . '") in Couriers → Courier Accounts;'
                . ' "Test connection" lists the valid codes.';
        }

        return '';
    }

    /** Valid PostEx orderType for $value (case-insensitive), else null. COD is not an order type. */
    private function orderType(?string $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'normal' => 'Normal',
            'reversed', 'reverse' => 'Reversed',
            'replacement' => 'Replacement',
            default => null,
        };
    }

    /**
     * The configured pickup address code; when none is set, the merchant's
     * only (or default) PostEx pickup address. Several addresses and no
     * setting → ask the user to choose, rather than guess a pickup point.
     */
    private function pickupAddressCode(): string
    {
        $code = trim((string) $this->account->setting('pickup_address_code'));
        if ($code !== '') {
            return $code;
        }

        $res = $this->client()->get(self::BASE . 'v1/get-merchant-address');
        $addresses = $this->ok($res) ? collect($res->json('dist') ?? [])->filter(fn ($a) => ! empty($a['addressCode'])) : collect();

        $pick = $addresses->count() === 1
            ? $addresses->first()
            : $addresses->first(fn ($a) => filter_var($a['isDefault'] ?? $a['default'] ?? false, FILTER_VALIDATE_BOOLEAN));
        if ($pick) {
            return (string) $pick['addressCode'];
        }

        $list = $addresses->map(fn ($a) => $a['addressCode'] . (! empty($a['cityName']) ? " ({$a['cityName']})" : ''))->implode(', ');
        throw new CourierException('PostEx: set the "Pickup address code" on this courier account (Couriers → Courier Accounts).'
            . ($list !== '' ? " Your PostEx codes: {$list}." : ' "Test connection" lists your codes.'));
    }

    /** COD settlement: settle=true means PostEx has paid the merchant. */
    private function settlement(Shipment $shipment): array
    {
        try {
            $res = $this->client()->get(self::BASE . 'v1/payment-status/' . rawurlencode((string) $shipment->tracking_number));
            if (! $this->ok($res) || ! $res->json('dist.settle')) {
                return [null, null, null];
            }
            $d = $res->json('dist');

            return [
                'remitted',
                ($d['cprNumber_2'] ?? null) ?: ($d['cprNumber_1'] ?? null),
                $this->time($d['settlementDate'] ?? null),
            ];
        } catch (\Throwable $e) {
            // A settlement lookup failure must not lose the tracking update.
            return [null, null, null];
        }
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && (string) $res->json('statusCode') === '200';
    }

    private function time($value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function client()
    {
        return $this->http()->withHeaders(['token' => $this->account->credential('token')]);
    }

    private function error(Response $res): string
    {
        return $res->json('statusMessage') ?: $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
