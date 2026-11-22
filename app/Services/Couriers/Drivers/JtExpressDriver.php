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
use App\Services\Couriers\Data\WebhookUpdate;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * J&T Express Middle East (Saudi Arabia & UAE) — the "J&T OPEN PLATFORM"
 * (open.jtjms-sa.com). Live https://openapi.jtjms-sa.com/webopenplatformapi/api/,
 * test https://demoopenapi.jtjms-sa.com/…. Form POST with one field,
 * bizContent (JSON); headers apiAccount + timestamp + digest =
 * base64(md5_raw(bizContent . privateKey)). Order calls also carry
 * customerCode + a business digest = base64(md5_raw(customerCode .
 * UPPER(md5_hex(password . "jadada236t2")) . privateKey)). Success = code "1".
 * Track pushes (after trace/subscribe) must be answered {"code":"1",…}.
 */
class JtExpressDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://openapi.jtjms-sa.com/webopenplatformapi/api/';
    private const TEST = 'https://demoopenapi.jtjms-sa.com/webopenplatformapi/api/';

    /** scanTypeCode → Stocky status. */
    private const SCAN_CODES = [
        10 => CourierStatus::PICKED_UP,
        50 => CourierStatus::IN_TRANSIT, 90 => CourierStatus::IN_TRANSIT, 92 => CourierStatus::IN_TRANSIT, 402 => CourierStatus::IN_TRANSIT,
        301 => CourierStatus::IN_TRANSIT, 302 => CourierStatus::IN_TRANSIT, 303 => CourierStatus::IN_TRANSIT, 304 => CourierStatus::IN_TRANSIT, 305 => CourierStatus::IN_TRANSIT,
        94 => CourierStatus::OUT_FOR_DELIVERY,
        110 => CourierStatus::FAILED_ATTEMPT,
        100 => CourierStatus::DELIVERED,
        172 => CourierStatus::RETURNING,
        1001 => CourierStatus::RETURNED,
    ];

    /** English scanType text → Stocky status (pushes carry text only). */
    private const SCAN_TEXT = [
        'pickup scan' => CourierStatus::PICKED_UP,
        'station sending' => CourierStatus::IN_TRANSIT, 'dc sending' => CourierStatus::IN_TRANSIT,
        'dc arrival' => CourierStatus::IN_TRANSIT, 'station arrival' => CourierStatus::IN_TRANSIT, 'change address' => CourierStatus::IN_TRANSIT,
        'delivery scan' => CourierStatus::OUT_FOR_DELIVERY,
        'abnormal parcel scan' => CourierStatus::FAILED_ATTEMPT,
        'sign scan' => CourierStatus::DELIVERED,
        'returned parcel scan' => CourierStatus::RETURNING,
        'returned signed' => CourierStatus::RETURNED,
    ];

    public static function meta(): array
    {
        return [
            'code' => 'jt_express',
            'name' => 'J&T Express (KSA / UAE)',
            'region' => 'Saudi Arabia / UAE',
            'countries' => ['SA', 'AE'],
            'website' => 'https://www.jtexpress-sa.com',
            'tracking_url' => null,
            'description' => 'J&T Express Middle East open platform: COD booking, labels, tracking, cancellation and track pushes.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'webhooks' => true,
                'cod' => true,
                'test_mode' => true,
            ],
            'credentials' => [
                ['key' => 'api_account', 'label' => 'API account', 'type' => 'text', 'required' => true,
                    'help' => 'From the J&T open platform (open.jtjms-sa.com).'],
                ['key' => 'private_key', 'label' => 'Private key', 'type' => 'password', 'required' => true],
                ['key' => 'customer_code', 'label' => 'Customer code', 'type' => 'text', 'required' => true],
                ['key' => 'customer_password', 'label' => 'Customer password', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'country', 'label' => 'Country', 'type' => 'select', 'options' => [
                    ['value' => 'KSA', 'label' => 'Saudi Arabia'], ['value' => 'UAE', 'label' => 'UAE'],
                ], 'help' => 'Leave empty for Saudi Arabia.'],
                ['key' => 'express_type', 'label' => 'Product', 'type' => 'select', 'options' => [
                    ['value' => 'EZKSA', 'label' => 'Standard (KSA)'], ['value' => 'EZ', 'label' => 'Standard (UAE)'],
                    ['value' => 'SDD', 'label' => 'Same day (KSA)'], ['value' => 'SDDUAE', 'label' => 'Same day (UAE)'],
                ], 'help' => 'Leave empty for the standard product of the country.'],
                ['key' => 'sender_province', 'label' => 'Pickup province / emirate', 'type' => 'text', 'required' => true,
                    'help' => 'As J&T names it, e.g. Riyadh or Dubai.'],
                ['key' => 'goods_type', 'label' => 'Goods type', 'type' => 'select', 'options' => [
                    ['value' => 'ITN4', 'label' => 'Others'], ['value' => 'ITN1', 'label' => 'Clothes'], ['value' => 'ITN2', 'label' => 'Documents'],
                    ['value' => 'ITN3', 'label' => 'Food'], ['value' => 'ITN5', 'label' => 'Digital'], ['value' => 'ITN6', 'label' => 'Daily necessities'],
                    ['value' => 'ITN7', 'label' => 'Fragile'],
                ], 'help' => 'Leave empty for Others.'],
                ['key' => 'drop_at_store', 'label' => 'I drop parcels at a J&T store', 'type' => 'switch',
                    'help' => 'Off = J&T picks up from you.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->call('order/getOrders', $this->withCustomer(['command' => 2, 'serialNumber' => ['UTE000000000']]));
        $code = (string) $res->json('code');
        if ($code === '145003030') {
            return ConnectionResult::failed('J&T: API account or private key rejected.');
        }
        if ($code === '145003031') {
            return ConnectionResult::failed('J&T: customer code or password rejected.');
        }

        if (! $res->successful()) {
            return ConnectionResult::failed('J&T: ' . $this->error($res));
        }
        // J&T answers HTTP 200 with an error code; success is code "1". The probe asks for
        // a made-up order, so "not found" also proves the keys were accepted.
        $msg = (string) $res->json('msg');
        if ($code === '1' || preg_match('/not\s*(exist|found)|no\s*data|empty/i', $msg)) {
            return ConnectionResult::ok('Connected to J&T Express.');
        }

        return ConnectionResult::failed('J&T: ' . $this->error($res));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $acc = $this->account;
        $country = $this->country();
        $province = $request->state ?: $request->courierCityName;
        if (! $province) {
            throw new CourierException('J&T needs the customer\'s province / emirate (State field).');
        }
        $orderId = mb_substr($request->reference, 0, 40) . '-' . Str::upper(Str::random(4));
        $cod = $request->codAmount > 0;
        $currency = $country === 'UAE' ? 'AED' : 'SAR';

        $biz = $this->withCustomer(array_filter([
            'txlogisticId' => $orderId,
            'expressType' => $acc->setting('express_type') ?: ($country === 'UAE' ? 'EZ' : 'EZKSA'),
            'orderType' => 1,
            'serviceType' => $acc->setting('drop_at_store') ? '02' : '01',
            'deliveryType' => '04',
            'goodsType' => $acc->setting('goods_type') ?: 'ITN4',
            'weight' => (string) min(30, max(0.01, round($request->weight, 2))),
            'operateType' => 1,
            'totalQuantity' => $request->pieces,
            'itemsValue' => $cod ? round($request->codAmount, 2) : null,
            'priceCurrency' => $cod ? $currency : null,
            'remark' => $request->notes ? mb_substr($request->notes, 0, 200) : null,
            'sender' => array_filter([
                'name' => mb_substr((string) ($acc->pickup_contact ?: $acc->name), 0, 32),
                'mobile' => (string) $acc->pickup_phone,
                'phone' => (string) $acc->pickup_phone,
                'countryCode' => $country,
                'prov' => (string) $acc->setting('sender_province'),
                'city' => (string) $acc->pickup_city,
                'address' => mb_substr((string) $acc->pickup_address, 0, 200),
            ], fn ($v) => $v !== ''),
            'receiver' => array_filter([
                'name' => mb_substr($request->consigneeName, 0, 32),
                'mobile' => $request->consigneePhone,
                'phone' => $request->consigneePhone,
                'countryCode' => $country,
                'prov' => $province,
                'city' => $request->courierCityName ?: $request->city,
                'address' => mb_substr($request->address, 0, 200),
                'postCode' => $request->zip,
            ], fn ($v) => $v !== null && $v !== ''),
            'items' => [[
                'itemName' => mb_substr($request->itemsDescription ?: 'Goods', 0, 100),
                'number' => $request->pieces,
                'itemValue' => round(max($request->declaredValue, $request->codAmount), 2),
                'priceCurrency' => $currency,
            ]],
        ], fn ($v) => $v !== null && $v !== ''));

        $res = $this->call('order/addOrder', $biz);
        $bill = $res->json('data.billCode');
        if (! $this->ok($res) || ! $bill) {
            throw new CourierException('J&T: ' . $this->error($res));
        }

        // Ask J&T to push this waybill's scans to the webhook (best-effort).
        try {
            $this->call('trace/subscribe', ['id' => $acc->credential('api_account'), 'list' => [
                ['traceNode' => '10&50&92&90&94&100&110&172&402&1001', 'waybillCode' => (string) $bill],
            ]]);
        } catch (\Throwable $e) {
            // Polling still covers this shipment.
        }

        return new BookingResult(
            trackingNumber: (string) $bill,
            courierReference: $orderId,
            charges: is_numeric($res->json('data.sumFreight')) ? (float) $res->json('data.sumFreight') : null,
            status: CourierStatus::BOOKED,
            rawStatus: 'Created',
            meta: ['sorting_code' => $res->json('data.sortingCode')],
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->call('order/cancelOrder', $this->withCustomer([
            'orderType' => 1,
            'txlogisticId' => (string) $shipment->courier_reference,
            'billCode' => (string) $shipment->tracking_number,
            'reason' => 'Cancelled by merchant',
        ]));
        if (! $this->ok($res)) {
            throw new CourierException('J&T: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->call('logistics/trace', ['billCodes' => (string) $shipment->tracking_number]);
        $item = collect($res->json('data') ?? [])->firstWhere('billCode', (string) $shipment->tracking_number);
        if (! $this->ok($res) || ! is_array($item)) {
            throw new CourierException('J&T: ' . $this->error($res));
        }

        return $this->toResult($item['details'] ?? []);
    }

    /** Pre-signed S3 PDF URL (valid 7 days). */
    public function label(Shipment $shipment): array
    {
        $res = $this->call('order/printOrderUrl', $this->withCustomer(['billCode' => (string) $shipment->tracking_number]));
        $data = $res->json('data');
        $url = is_string($data) ? $data : ($data['url'] ?? null);
        if (! $this->ok($res) || ! $url) {
            throw new CourierException('J&T: ' . $this->error($res));
        }

        return ['url' => $url];
    }

    /**
     * Track push: form field bizContent = {billCode, txlogisticId, details[]}.
     * Its digest scheme is not confirmed by J&T's docs, so the secret token
     * in this account's URL is the authentication.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $biz = $request->input('bizContent');
        $data = is_string($biz) ? json_decode($biz, true) : (is_array($biz) ? $biz : $request->all());
        if (! is_array($data) || empty($data['billCode'])) {
            return null;
        }

        return new WebhookUpdate((string) $data['billCode'], $this->toResult($data['details'] ?? []));
    }

    public function webhookAck(): array
    {
        return [200, ['code' => '1', 'msg' => 'success', 'data' => (object) []]];
    }

    // ------------------------------------------------------------------

    private function toResult(array $details): TrackingResult
    {
        $events = [];
        foreach ($details as $d) {
            if (! is_array($d)) {
                continue;
            }
            $text = $d['scanType'] ?? null;
            $code = isset($d['scanTypeCode']) && is_numeric($d['scanTypeCode']) ? (int) $d['scanTypeCode'] : null;
            $events[] = new TrackingEvent(
                status: ($code !== null ? (self::SCAN_CODES[$code] ?? null) : null)
                    ?? (self::SCAN_TEXT[mb_strtolower(trim((string) $text))] ?? StatusText::map($text)),
                rawStatus: $text,
                at: $this->time($d['scanTime'] ?? null),
                location: trim(implode(', ', array_filter([$d['scanNetworkName'] ?? null, $d['scanNetworkCity'] ?? null]))) ?: null,
                description: $d['desc'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;

        return new TrackingResult(
            status: $latest?->status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $latest?->status === CourierStatus::DELIVERED ? $latest->at : null,
        );
    }

    /** customerCode + business digest for order calls. */
    private function withCustomer(array $biz): array
    {
        $code = $this->account->credential('customer_code');
        $cipher = strtoupper(md5($this->account->credential('customer_password') . 'jadada236t2'));

        return ['customerCode' => $code, 'digest' => base64_encode(md5($code . $cipher . $this->account->credential('private_key'), true))] + $biz;
    }

    private function call(string $path, array $biz): Response
    {
        $json = json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->http()->asForm()->withHeaders([
            'apiAccount' => $this->account->credential('api_account'),
            'digest' => base64_encode(md5($json . $this->account->credential('private_key'), true)),
            'timestamp' => (string) (int) round(microtime(true) * 1000),
        ])->post(($this->account->isTestMode() ? self::TEST : self::LIVE) . $path, ['bizContent' => $json]);
    }

    private function country(): string
    {
        return strtoupper((string) ($this->account->setting('country') ?: 'KSA')) === 'UAE' ? 'UAE' : 'KSA';
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && (string) $res->json('code') === '1';
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
        $msg = $res->json('msg');
        $code = $res->json('code');

        return $msg ? trim($msg . ($code && (string) $code !== '1' ? " ({$code})" : '')) : ('HTTP ' . $res->status());
    }
}
