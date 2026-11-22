<?php

namespace App\Services\Couriers\Drivers;

use App\Models\Shipment;
use App\Services\CountryService;
use App\Services\Couriers\AbstractCourierDriver;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\CourierStatus;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\Rate;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\RatePicker;
use App\Services\Couriers\Support\Shipper;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;

/**
 * Topship (topship.africa, Nigeria), from api-topship.com/shipping/docs plus
 * probes of the public quote endpoints. Hosts api-topship.com/api (live) and
 * topship-staging.africa/api (sandbox), `Authorization: Bearer <key>` (keys
 * from tech@topship.africa). A GraphQL server behind REST paths: object
 * arguments go as JSON in one query parameter, and every error is HTTP 500
 * with {"message"}.
 * Quotes (get-shipment-rate, public) are per pricingTier, in KOBO; the tier is
 * the rate id, so a quote never expires. Booking = save-shipment (a draft)
 * then pay-from-wallet; a draft whose payment fails is cancelled again.
 * Pickup is optional: get-pickup-rates gives a partner + pickupId to add.
 * Cancel only while Draft/Confirmed. No COD, no webhooks (polling).
 */
class TopshipDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://api-topship.com/api/';
    private const STAGING = 'https://topship-staging.africa/api/';

    /** Topship's shipment VAT: 7.5% of the charges, sent with the booking. */
    private const VAT = 0.075;

    /** Address lines are capped at 45 characters each. */
    private const LINE = 45;

    /** shipmentStatus enum from the docs. Unlisted pickup hiccups stay unmapped (still polled). */
    private const STATUSES = [
        'draft' => CourierStatus::BOOKED,
        'paymentpending' => CourierStatus::BOOKED,
        'pendingconfirmation' => CourierStatus::BOOKED,
        'confirmed' => CourierStatus::BOOKED,
        'awaitingpickup' => CourierStatus::BOOKED,
        'awaitingdropoff' => CourierStatus::BOOKED,
        'pickupdispatched' => CourierStatus::BOOKED,
        'pickupinprogress' => CourierStatus::BOOKED,
        'riderassigned' => CourierStatus::BOOKED,
        'shipmentprocessing' => CourierStatus::BOOKED,
        'successfullypicked' => CourierStatus::PICKED_UP,
        'receivedathub' => CourierStatus::IN_TRANSIT,
        'arrivednigeria' => CourierStatus::IN_TRANSIT,
        'intransit' => CourierStatus::IN_TRANSIT,
        'assignedfordelivery' => CourierStatus::OUT_FOR_DELIVERY,
        'deliveryinprogress' => CourierStatus::OUT_FOR_DELIVERY,
        'deliveryfailed' => CourierStatus::FAILED_ATTEMPT,
        'delivered' => CourierStatus::DELIVERED,
        'cancelled' => CourierStatus::CANCELLED,
        'pickupcancelled' => CourierStatus::CANCELLED,
        'pickupcancelledbymerchant' => CourierStatus::CANCELLED,
        'pickupcancelledinternally' => CourierStatus::CANCELLED,
    ];

    /** Item categories (subset of Topship's enum) for the account form. */
    private const CATEGORIES = [
        'Others' => 'Other goods', 'Fashion' => 'Fashion', 'ClothingAndTextile' => 'Clothing & textile',
        'BeautyProducts' => 'Beauty products', 'Gadgets' => 'Gadgets', 'Phones' => 'Phones',
        'LaptopsAndTablets' => 'Laptops & tablets', 'ComputerSupplies' => 'Computer supplies',
        'OtherElectronicsOrGadgets' => 'Other electronics', 'Appliance' => 'Appliances', 'HomeDecor' => 'Home decor',
        'KitchenAccessories' => 'Kitchen accessories', 'Furniture' => 'Furniture', 'BabySupplies' => 'Baby supplies',
        'SportAccessories' => 'Sport accessories', 'Document' => 'Documents', 'FoodItems' => 'Food items',
        'Medication' => 'Medication', 'Wristwatches' => 'Wristwatches', 'CostumeJewelry' => 'Costume jewelry',
    ];

    public static function meta(): array
    {
        return [
            'code' => 'topship',
            'name' => 'Topship',
            'region' => 'Nigeria',
            'countries' => ['NG'],
            'website' => 'https://topship.africa',
            'tracking_url' => null,
            'description' => 'Ship within Nigeria and abroad with Topship (UPS, Aramex, last-mile partners and more), paid from your Topship wallet, with optional pickup and printable labels.',
            'capabilities' => [
                'booking' => true,
                'rates' => true,
                'cancel' => true,
                'tracking' => true,
                'labels' => true,
                'pickup' => true,
                'coordinates' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Email tech@topship.africa with your full name, work email, company phone and company name; they send a staging and a live key.'],
            ],
            'settings' => [
                ['key' => 'sender_email', 'label' => 'Pickup email', 'type' => 'text', 'required' => true],
                ['key' => 'sender_state', 'label' => 'Pickup state', 'type' => 'text', 'required' => true, 'help' => 'e.g. Lagos.'],
                ['key' => 'sender_zip', 'label' => 'Pickup postal code', 'type' => 'text'],
                ['key' => 'sender_latitude', 'label' => 'Pickup latitude', 'type' => 'number',
                    'help' => 'Optional. Some same-day partners (and the Topship sandbox) only quote with map points.'],
                ['key' => 'sender_longitude', 'label' => 'Pickup longitude', 'type' => 'number'],
                ['key' => 'fallback_email', 'label' => 'Email when the customer has none', 'type' => 'text',
                    'help' => 'Empty = the pickup email.'],
                ['key' => 'item_category', 'label' => 'Item category', 'type' => 'select',
                    'options' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(self::CATEGORIES), self::CATEGORIES),
                    'help' => 'Empty = Other goods.'],
                ['key' => 'collection_mode', 'label' => 'How parcels reach Topship', 'type' => 'select', 'options' => [
                    ['value' => 'dropoff', 'label' => 'I drop them off at a Topship hub'],
                    ['value' => 'pickup', 'label' => 'A pickup partner collects them (extra charge)'],
                ], 'help' => 'Same-day services that already include pickup ignore this. Empty = drop-off.'],
                ['key' => 'pickup_partner', 'label' => 'Preferred pickup partner', 'type' => 'text',
                    'help' => 'e.g. Dellyman. Empty = the cheapest pickup offered.'],
                ['key' => 'leave_unpaid', 'label' => 'Leave bookings unpaid', 'type' => 'switch',
                    'help' => 'Off = pay each booking from your Topship wallet right away. On = bookings stay drafts until you pay them in Topship.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'get-shipments', ['filter' => json_encode(['take' => 1, 'page' => 1])]);
        if (! $res->successful() || ! is_array($res->json()) || isset($res->json()['message'])) {
            return ConnectionResult::failed('Topship: ' . $this->error($res)
                . ($this->account->isTestMode() ? ' (test mode uses your staging key)' : ' (live mode uses your live key)'));
        }
        $count = $res->json('totalCount');

        return ConnectionResult::ok('Connected to Topship' . ($this->account->isTestMode() ? ' (staging)' : '') . '.'
            . (is_numeric($count) ? ' Shipments on the account: ' . (int) $count . '.' : ''));
    }

    public function rates(ShipmentRequest $request): array
    {
        $from = Shipper::address($this->account);
        $sender = array_filter([
            'cityName' => trim($from['city']),
            'countryCode' => $this->senderCountry(),
            'latitude' => is_numeric($this->account->setting('sender_latitude')) ? (float) $this->account->setting('sender_latitude') : null,
            'longitude' => is_numeric($this->account->setting('sender_longitude')) ? (float) $this->account->setting('sender_longitude') : null,
        ], fn ($v) => $v !== null && $v !== '');
        if (($sender['cityName'] ?? '') === '') {
            throw new CourierException('Topship: set the pickup city on the courier account.');
        }
        $receiver = array_filter([
            'cityName' => trim($request->city),
            'countryCode' => $request->countryCode('NG'),
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ], fn ($v) => $v !== null && $v !== '');
        if (($receiver['cityName'] ?? '') === '') {
            throw new CourierException('Topship: the delivery city is required.');
        }

        // Quotes ask every partner in turn; the sandbox is slow.
        $res = $this->client()->timeout(30)->get($this->base() . 'get-shipment-rate', ['shipmentDetail' => json_encode([
            'senderDetails' => $sender,
            'receiverDetails' => $receiver,
            'totalWeight' => max(0.1, round($request->weight, 2)),
        ])]);
        $list = $res->json();
        if (! $res->successful() || ! is_array($list) || isset($list['message'])) {
            throw new CourierException('Topship: ' . $this->error($res));
        }

        return collect($list)
            ->filter(fn ($r) => is_array($r) && is_numeric($r['cost'] ?? null) && ($r['pricingTier'] ?? '') !== '' && empty($r['isDisabled']))
            ->map(function ($r) {
                $charge = $this->money($r['cost'], $r['currency'] ?? 'KOBO');

                return new Rate(
                    id: (string) $r['pricingTier'],
                    carrier: (string) ($r['mode'] ?? $r['pricingTier']),
                    // The duration text is already the days column; checkout labels read "carrier service".
                    service: '',
                    // What the wallet pays: the quote plus Topship's 7.5% VAT.
                    amount: round($charge * (1 + self::VAT), 2),
                    currency: 'NGN',
                    days: $this->days($r['estimatedDeliveryDate'] ?? null) ?? $this->durationDays((string) ($r['duration'] ?? '')),
                    meta: [
                        'servicelevel' => (string) $r['pricingTier'],
                        'cost_kobo' => (int) round($charge * 100),
                        'doorstep' => $this->includesPickup((string) ($r['duration'] ?? '')),
                    ],
                );
            })->values()->all();
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $rates = $this->rates($request);
        $rate = RatePicker::pick($rates, trim((string) ($request->service ?: ($this->account->default_service ?: ''))) ?: null);
        if (! $rate) {
            throw new CourierException($rates ? 'Topship: no rate matches the chosen service — compare rates again.' : 'Topship: no service offers this route.');
        }

        $from = Shipper::address($this->account);
        $email = $from['email'] ?: (string) $this->account->setting('fallback_email');
        if (trim($from['phone']) === '' || $email === '' || trim($from['street']) === '') {
            throw new CourierException('Topship: set the pickup address, phone and email on the courier account.');
        }
        $senderCountry = $this->senderCountry();
        $receiverCountry = $request->countryCode('NG');

        $pickup = null;
        $mode = 'DropOff';
        if ($rate->meta['doorstep']) {
            $mode = 'PickUp'; // the quoted partner collects from the door; its price covers it
        } elseif ($this->account->setting('collection_mode') === 'pickup') {
            $pickup = $this->pickupRate($from, $senderCountry);
            $mode = 'PickUp';
        }

        $shipmentCharge = (int) $rate->meta['cost_kobo'];
        $pickupCharge = (int) ($pickup['charge'] ?? 0);
        $pieces = max(1, $request->pieces);
        $value = max($request->declaredValue, 0);

        $shipment = array_filter([
            'items' => [[
                'category' => array_key_exists((string) $this->account->setting('item_category'), self::CATEGORIES) ? $this->account->setting('item_category') : 'Others',
                'description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 250),
                'weight' => max(0.1, round($request->weight / $pieces, 2)),
                'quantity' => $pieces,
                'value' => round($value / $pieces, 2),
            ]],
            'itemCollectionMode' => $mode,
            'pricingTier' => $rate->meta['servicelevel'],
            'insuranceType' => 'None',
            'insuranceCharge' => 0,
            'discount' => 0,
            'shipmentRoute' => $senderCountry === $receiverCountry ? 'Domestic' : ($senderCountry === 'NG' ? 'Export' : 'Import'),
            'shipmentCharge' => $shipmentCharge,
            'pickupCharge' => $pickupCharge,
            'pickupId' => $pickup['id'] ?? null,
            'pickupPartner' => $pickup['partner'] ?? null,
            'deliveryLocation' => $pickup['location'] ?? null,
            'valueAddedTaxCharge' => (int) round(($shipmentCharge + $pickupCharge) * self::VAT),
            'senderDetail' => $this->party($from['name'], $email, $from['phone'], $from['street'], $from['city'], $from['state'], $from['zip'], $senderCountry),
            'receiverDetail' => $this->party(
                $request->consigneeName,
                $request->consigneeEmail ?: ($this->account->setting('fallback_email') ?: $email),
                $request->consigneePhone,
                $request->address,
                $request->city,
                (string) $request->state,
                (string) $request->zip,
                $receiverCountry,
            ),
        ], fn ($v) => $v !== null);

        $res = $this->client()->post($this->base() . 'save-shipment', ['shipment' => [$shipment]]);
        $saved = $this->shipmentFrom($res);
        $id = (string) ($saved['id'] ?? '');
        $tracking = (string) ($saved['trackingId'] ?? '');
        if ($id === '' || $tracking === '') {
            throw new CourierException('Topship: ' . $this->error($res));
        }

        $paid = false;
        if (! $this->account->setting('leave_unpaid')) {
            $after = null;
            try {
                $pay = $this->client()->post($this->base() . 'pay-from-wallet', ['detail' => ['shipmentId' => $id]]);
                $after = $this->shipmentFrom($pay);
                $reason = $this->error($pay);
            } catch (\Throwable $e) {
                // A timeout says nothing about the payment: ask Topship before undoing anything.
                $reason = $e->getMessage();
                try {
                    $after = $this->shipmentFrom($this->client()->get($this->base() . 'get-shipment/' . rawurlencode($id)));
                } catch (\Throwable $e) {
                    $after = null;
                }
            }
            if (! $after || empty($after['isPaid'])) {
                // Don't leave an unpaid draft behind a failed booking.
                $cancelled = false;
                try {
                    $cancelled = (bool) $this->shipmentFrom($this->client()->post($this->base() . 'cancel-shipment', ['id' => $id]));
                } catch (\Throwable $e) {
                }
                throw new CourierException('Topship: the wallet payment failed — ' . $reason . '. '
                    . ($cancelled
                        ? 'The draft was cancelled; top up the wallet and book again.'
                        : 'Draft ' . $tracking . ' may still be in your Topship account: pay or cancel it there before booking again.'));
            }
            $saved = $after + $saved;
            $paid = true;
        }

        // An unpaid draft can report totalCharge 0: fall back to what was sent.
        $total = is_numeric($saved['totalCharge'] ?? null) && (float) $saved['totalCharge'] > 0
            ? $this->money($saved['totalCharge'], $saved['currency'] ?? 'KOBO')
            : ($shipmentCharge + $pickupCharge) * (1 + self::VAT) / 100;

        return new BookingResult(
            trackingNumber: $tracking,
            courierReference: $id,
            labelUrl: $this->url($saved['label'] ?? null),
            charges: round($total, 2),
            status: CourierStatus::BOOKED,
            rawStatus: (string) ($saved['shipmentStatus'] ?? ($paid ? 'Confirmed' : 'Draft')),
            meta: array_filter([
                'carrier' => $rate->carrier,
                'pricing_tier' => $rate->meta['servicelevel'],
                'collection' => $mode,
                'pickup_partner' => $pickup['partner'] ?? null,
                'dropoff_hub' => $mode === 'DropOff' ? null : ($pickup['location'] ?? null),
                'paid' => $paid,
                'tracking_url' => $this->url($saved['trackingUrl'] ?? null),
                'invoice' => $this->url($saved['invoice'] ?? null),
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    /** Only Draft / Confirmed shipments; after that Topship support has to do it. */
    public function cancel(Shipment $shipment): void
    {
        $id = (string) ($shipment->courier_reference ?: '');
        if ($id === '') {
            throw new CourierException('Topship: this booking has no Topship shipment id — cancel it in the Topship dashboard.');
        }
        $res = $this->client()->post($this->base() . 'cancel-shipment', ['id' => $id]);
        $data = $this->shipmentFrom($res);
        if (! $data) {
            throw new CourierException('Topship: ' . $this->error($res) . ' (only Draft or Confirmed shipments can be cancelled; after that contact Topship support).');
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        // Current status from the shipment record (documented enum)…
        $current = null;
        if ($shipment->courier_reference) {
            $res = $this->client()->get($this->base() . 'get-shipment/' . rawurlencode((string) $shipment->courier_reference));
            $current = $this->shipmentFrom($res);
        }

        // …and the history lines from the tracking endpoint.
        $res = $this->client()->get($this->base() . 'track-shipment', ['trackingId' => (string) $shipment->tracking_number]);
        $body = $res->json();
        $events = [];
        // Errors are HTTP 500 {"message"}; a tracking record has its own message field.
        if ($res->successful() && is_array($body)) {
            // One record updated in place: its createdDate is the booking time, updatedDate the latest change.
            $lines = $this->trackingLines($body);
            $single = $lines === [$body];
            foreach ($lines as $line) {
                $raw = trim((string) ($line['status'] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $events[] = new TrackingEvent(
                    status: $this->map($raw),
                    rawStatus: $raw,
                    at: $this->time($single ? ($line['updatedDate'] ?? $line['createdDate'] ?? null) : ($line['createdDate'] ?? $line['updatedDate'] ?? null)),
                    location: is_string($line['itemLocation'] ?? null) && $line['itemLocation'] !== '' ? $line['itemLocation'] : null,
                    description: is_string($line['message'] ?? null) && $line['message'] !== '' ? mb_substr($line['message'], 0, 250) : null,
                );
            }
        } elseif (! $current) {
            throw new CourierException('Topship: ' . $this->error($res));
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $raw = trim((string) ($current['shipmentStatus'] ?? '')) ?: (string) (end($events) ? end($events)->rawStatus : '');
        $status = $raw !== '' ? $this->map($raw) : null;
        $deliveredAt = null;
        foreach ($events as $e) {
            if ($e->status === CourierStatus::DELIVERED) {
                $deliveredAt = $e->at;
            }
        }
        if ($status === CourierStatus::DELIVERED && ! $deliveredAt) {
            $deliveredAt = $this->time($current['updatedDate'] ?? null);
        }

        return new TrackingResult(status: $status, rawStatus: $raw ?: null, events: $events, deliveredAt: $deliveredAt);
    }

    /** The label document Topship generated (a URL). */
    public function label(Shipment $shipment): array
    {
        if ($shipment->label_url) {
            return ['url' => $shipment->label_url];
        }
        if (! $shipment->courier_reference) {
            throw new CourierException('Topship: this booking has no Topship shipment id.');
        }
        $data = $this->shipmentFrom($this->client()->get($this->base() . 'get-shipment/' . rawurlencode((string) $shipment->courier_reference)));
        $url = $this->url($data['label'] ?? null);
        if (! $url) {
            throw new CourierException('Topship has not generated the label yet' . (empty($data['isPaid']) ? ' — the shipment is still unpaid.' : '. Try again shortly.'));
        }

        return ['url' => $url];
    }

    // ------------------------------------------------------------------

    /** Cheapest pickup (or the preferred partner) for the account's address. */
    private function pickupRate(array $from, string $country): array
    {
        $lagos = now('Africa/Lagos');
        $res = $this->client()->timeout(30)->get($this->base() . 'get-pickup-rates', ['input' => json_encode([
            'senderDetail' => [
                'addressLine1' => mb_substr(trim($from['street']), 0, self::LINE),
                'addressLine2' => '',
                'country' => CountryService::name($country, 'en'),
                'countryCode' => $country,
                'state' => trim($from['state']),
                'city' => trim($from['city']),
            ],
            // Same-day pickups need the morning; later requests go out the next day.
            'pickupDate' => ($lagos->hour >= 11 ? $lagos->copy()->addDay()->setTime(9, 0) : $lagos->copy()->addMinutes(30))->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ])]);
        $list = $res->json();
        if (! $res->successful() || ! is_array($list) || isset($list['message'])) {
            throw new CourierException('Topship: no pickup available — ' . $this->error($res) . '. Switch the account to drop-off or try again.');
        }
        $offers = collect($list)->filter(fn ($p) => is_array($p) && is_numeric($p['pickupCharge'] ?? null) && ($p['pickupId'] ?? '') !== '');
        $want = mb_strtolower(trim((string) $this->account->setting('pickup_partner')));
        $pick = ($want !== '' ? $offers->first(fn ($p) => str_contains(mb_strtolower((string) ($p['partner'] ?? '')), $want)) : null)
            ?? $offers->sortBy('pickupCharge')->first();
        if (! $pick) {
            throw new CourierException('Topship: no pickup partner serves the pickup address — switch the account to drop-off.');
        }

        return [
            'id' => (string) $pick['pickupId'],
            'partner' => (string) ($pick['partner'] ?? ''),
            'location' => (string) ($pick['deliveryLocation'] ?? ''),
            'charge' => (int) round((float) $pick['pickupCharge']),
        ];
    }

    /** senderDetail / receiverDetail with the street split into 45-character lines. */
    private function party(string $name, string $email, string $phone, string $street, string $city, string $state, string $zip, string $country): array
    {
        $lines = $this->lines($street);

        return [
            'name' => mb_substr(trim($name) ?: 'Customer', 0, 100),
            'email' => trim($email),
            'phoneNumber' => $this->phone($phone, $country),
            'addressLine1' => $lines[0],
            'addressLine2' => $lines[1],
            'addressLine3' => $lines[2],
            'country' => CountryService::name($country, 'en'),
            'state' => trim($state),
            'city' => trim($city),
            'countryCode' => $country,
            'postalCode' => trim($zip),
        ];
    }

    /** +234… for Nigeria, + and the digits otherwise; empty stays empty (never a bare "+"). */
    private function phone(string $phone, string $country): string
    {
        $digits = $country === 'NG' ? Phone::intl($phone, '234') : preg_replace('/\D+/', '', $phone);

        return $digits === '' ? '' : '+' . $digits;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function lines(string $street): array
    {
        $lines = ['', '', ''];
        $i = 0;
        foreach (preg_split('/\s+/u', trim($street)) ?: [] as $word) {
            $word = mb_substr($word, 0, self::LINE);
            $next = $lines[$i] === '' ? $word : $lines[$i] . ' ' . $word;
            if (mb_strlen($next) > self::LINE) {
                if ($i === 2) {
                    break; // three lines are all Topship takes
                }
                $next = $word;
                $i++;
            }
            $lines[$i] = $next;
        }

        return $lines;
    }

    /** track-shipment: one record, a list of records, or a record holding a history list. */
    private function trackingLines($body): array
    {
        if (! is_array($body)) {
            return [];
        }
        if (array_is_list($body)) {
            return array_values(array_filter($body, 'is_array'));
        }
        foreach (['history', 'trackingHistory', 'events', 'tracking'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                return array_values(array_filter($body[$key], 'is_array'));
            }
        }

        return [$body];
    }

    /** A shipment object from a response: itself, the first of a list, or null on an error. */
    private function shipmentFrom(Response $res): ?array
    {
        $json = $res->json();
        if (! $res->successful() || ! is_array($json) || (isset($json['message']) && ! isset($json['id']))) {
            return null;
        }
        $one = array_is_list($json) ? ($json[0] ?? null) : $json;

        return is_array($one) && isset($one['id']) ? $one : null;
    }

    /** Same-day partner quotes ("Same day pick up and delivery") already collect from the door. */
    private function includesPickup(string $duration): bool
    {
        return (bool) preg_match('/pick[\s-]?up/i', $duration);
    }

    private function senderCountry(): string
    {
        return CountryService::toCode($this->account->setting('sender_country')) ?? 'NG';
    }

    /** Topship amounts are KOBO unless a currency says otherwise. */
    private function money($amount, ?string $currency): float
    {
        return strtoupper((string) $currency) === 'KOBO' || $currency === null || $currency === '' ? (float) $amount / 100 : (float) $amount;
    }

    private function map(string $raw): ?string
    {
        $code = preg_replace('/[^a-z]/', '', mb_strtolower($raw));

        return self::STATUSES[$code] ?? StatusText::map($raw);
    }

    private function days(?string $date): ?int
    {
        try {
            return $date ? max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($date), false) / 86400)) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Upper bound of "Delivery in 3 - 7 working days" / "8 - 12 weeks"; "next working day" / "same-day" = 1. */
    private function durationDays(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(?:-|to)?\s*(\d+)?\s*(working\s+)?(day|week)/i', $text, $m)) {
            $n = (int) (($m[2] ?? '') !== '' ? $m[2] : $m[1]);

            return stripos($m[4], 'week') === 0 ? $n * 7 : $n;
        }

        return preg_match('/next[\s-]+(working\s+)?day|same[\s-]?day/i', $text) ? 1 : null;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function url($value): ?string
    {
        return is_string($value) && preg_match('#^https?://#i', trim($value)) ? trim($value) : null;
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function client()
    {
        return $this->http()->withToken($this->account->credential('api_key'));
    }

    private function error(Response $res): string
    {
        $json = $res->json();
        if (is_array($json) && is_string($json['message'] ?? null) && $json['message'] !== '') {
            return $json['message'];
        }

        return 'HTTP ' . $res->status();
    }
}
