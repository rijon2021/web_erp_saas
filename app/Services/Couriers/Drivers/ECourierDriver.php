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
use Illuminate\Support\Str;

/**
 * eCourier (Bangladesh) — Merchant API v5.5 (Jan 2024). Every call is a JSON
 * POST with API-KEY / API-SECRET / USER-ID headers; bad credentials answer
 * 302 → 401, so redirects are not followed. Booking uses names (city, thana,
 * area) plus a package code from /packages. No webhooks: tracking is polled.
 * Tracking responses come in two shapes (doc: list + status arrays; live:
 * object + status objects), both accepted.
 */
class ECourierDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://backoffice.ecourier.com.bd/api/';
    private const SANDBOX = 'https://staging.ecourier.com.bd/api/';

    private const STATUSES = [
        'initiated' => CourierStatus::BOOKED, 'initiated api' => CourierStatus::BOOKED,
        'picked up' => CourierStatus::PICKED_UP,
        'in source branch' => CourierStatus::IN_TRANSIT, 'in transit' => CourierStatus::IN_TRANSIT,
        'in destination branch' => CourierStatus::IN_TRANSIT,
        'on the way to delivery' => CourierStatus::OUT_FOR_DELIVERY,
        'delivered' => CourierStatus::DELIVERED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'ecourier',
            'name' => 'eCourier',
            'region' => 'Bangladesh',
            'countries' => ['BD'],
            'website' => 'https://ecourier.com.bd',
            'tracking_url' => null,
            'description' => 'eCourier COD parcels by city and thana, PDF labels, tracking and COD payment status.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true,
                    'help' => 'Your eCourier merchant login ID.'],
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true],
                ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'package_code', 'label' => 'Package code', 'type' => 'text', 'required' => true,
                    'help' => 'e.g. #2431. "Test connection" lists the package codes on your account.'],
                ['key' => 'parcel_type', 'label' => 'Parcel type', 'type' => 'select', 'options' => [
                    ['value' => 'BOX', 'label' => 'Box'], ['value' => 'DOC', 'label' => 'Document'],
                ]],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->post('packages');
        if (! $res->successful() || ! is_array($res->json())) {
            return ConnectionResult::failed('eCourier: ' . $this->error($res));
        }
        $packages = collect($res->json())->map(fn ($p) => is_array($p)
            ? trim(($p['package_code'] ?? $p['value'] ?? '') . ' ' . ($p['package_name'] ?? $p['name'] ?? ''))
            : null)->filter()->implode(', ');

        return ConnectionResult::ok('Connected to eCourier.' . ($packages ? ' Packages: ' . Str::limit($packages, 300) : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        [$city, $thana] = array_pad(explode('|', (string) $request->courierCityCode, 2), 2, '');
        if ($city === '' || $thana === '') {
            throw new CourierException('eCourier: choose the eCourier city / thana.');
        }
        $zip = (string) $request->zip;

        $res = $this->post('order-place', array_filter([
            'recipient_name' => mb_substr($request->consigneeName, 0, 250),
            'recipient_mobile' => Phone::bd($request->consigneePhone),
            'recipient_city' => $city,
            'recipient_thana' => $thana,
            'recipient_area' => $this->area($zip) ?? $thana,
            'recipient_address' => mb_substr($request->address, 0, 250),
            'recipient_zip' => $zip !== '' ? $zip : null,
            'package_code' => (string) $this->account->setting('package_code'),
            'product_price' => (int) round($request->codAmount),
            'payment_method' => 'COD',
            'parcel_type' => $this->account->setting('parcel_type') ?: 'BOX',
            'product_id' => mb_substr($request->reference, 0, 20),
            'number_of_item' => $request->pieces,
            'actual_product_price' => $request->declaredValue > 0 ? (int) round($request->declaredValue) : null,
            'comments' => $request->notes ? mb_substr($request->notes, 0, 255) : null,
            'pick_address' => $this->account->pickup_address ?: null,
        ], fn ($v) => $v !== null && $v !== ''));

        $id = $res->json('ID');
        if (! $res->successful() || ! $id) {
            throw new CourierException('eCourier: ' . $this->error($res));
        }

        return new BookingResult(trackingNumber: (string) $id, status: CourierStatus::BOOKED, rawStatus: 'Initiated');
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->post('track', ['ecr' => (string) $shipment->tracking_number]);
        $data = $res->json('query_data');
        if (! $res->successful() || ! is_array($data)) {
            throw new CourierException('eCourier: ' . (is_string($data) ? $data : $this->error($res)));
        }
        if (array_is_list($data)) {
            $data = $data[0] ?? [];
        }

        $events = [];
        foreach ((array) ($data['status'] ?? []) as $row) {
            [$name, $comment, $time] = array_is_list((array) $row)
                ? array_pad((array) $row, 3, null)
                : [$row['status'] ?? null, $row['comment'] ?? null, $row['time'] ?? null];
            if (! $name) {
                continue;
            }
            $events[] = new TrackingEvent($this->map($name), (string) $name, $this->time($time), null, $comment ?: null);
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $last = end($events) ?: null;

        $paid = in_array(strtolower((string) ($data['paymentclear'] ?? '')), ['yes', '1', 'true', 'paid', 'cleared'], true);

        return new TrackingResult(
            status: $last?->status,
            rawStatus: $last?->rawStatus,
            events: $events,
            deliveredAt: $last?->status === CourierStatus::DELIVERED ? $last->at : null,
            charges: is_numeric($data['shipping_price'] ?? null) ? (float) $data['shipping_price'] : null,
            codStatus: $paid ? 'remitted' : null,
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->post('cancel-order', ['tracking' => (string) $shipment->tracking_number, 'comment' => 'Cancelled by merchant']);
        if (! $res->successful() || ! in_array($res->json('success'), [true, 'true'], true)) {
            throw new CourierException('eCourier: ' . $this->error($res));
        }
    }

    /** PDF slip URL. */
    public function label(Shipment $shipment): array
    {
        $res = $this->post('label-print', ['tracking' => (string) $shipment->tracking_number]);
        $url = $res->json('query_data');
        if (! $res->successful() || ! is_string($url) || ! str_starts_with($url, 'http')) {
            throw new CourierException('eCourier: ' . $this->error($res));
        }

        return ['url' => $url];
    }

    /** {code: "City|Thana", name: "Thana, City"} from /city-list + /thana-list. */
    public function cities(): array
    {
        $res = $this->post('city-list');
        if (! $res->successful() || ! is_array($res->json())) {
            throw new CourierException('eCourier: ' . $this->error($res));
        }

        $out = [];
        foreach ($res->json() as $c) {
            $city = is_array($c) ? (string) ($c['value'] ?? $c['name'] ?? '') : '';
            if ($city === '') {
                continue;
            }
            $thanas = $this->post('thana-list', ['city' => $city])->json('message');
            foreach (is_array($thanas) ? $thanas : [] as $t) {
                $thana = (string) ($t['value'] ?? $t['name'] ?? '');
                if ($thana !== '') {
                    $out[] = ['code' => $city . '|' . $thana, 'name' => $thana . ', ' . $city];
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------

    /** eCourier area for a post code (first match), when the order has one. */
    private function area(string $zip): ?string
    {
        if ($zip === '') {
            return null;
        }
        try {
            $list = $this->post('area-list', ['postcode' => $zip])->json('message');
            $first = is_array($list) ? ($list[0] ?? null) : null;

            return is_array($first) ? (($first['value'] ?? $first['name'] ?? null) ?: null) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function map(string $name): ?string
    {
        return self::STATUSES[mb_strtolower(trim($name))] ?? StatusText::map($name);
    }

    private function post(string $path, array $body = []): Response
    {
        return $this->http()->withoutRedirecting()->withHeaders([
            'API-KEY' => $this->account->credential('api_key'),
            'API-SECRET' => $this->account->credential('api_secret'),
            'USER-ID' => $this->account->credential('user_id'),
        ])->asJson()->post($this->base() . $path, (object) $body);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX : self::LIVE;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value, 'Asia/Dhaka') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        if ($res->redirect() || $res->status() === 401) {
            return 'Unauthorized — check the user ID, API key and secret.';
        }
        $errors = $res->json('errors');
        if (is_array($errors) && $errors) {
            return collect($errors)->flatten()->implode('; ');
        }
        $msg = $res->json('message');

        return (is_string($msg) && $msg !== '' ? $msg : null) ?? ('HTTP ' . $res->status());
    }
}
