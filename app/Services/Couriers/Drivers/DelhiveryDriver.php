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
use App\Services\Couriers\Support\Shipper;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Delhivery B2C Express (India) — one.delhivery.com developer portal.
 * Static "Authorization: Token <token>" (separate staging token). Booking
 * (api/cmu/create.json) takes format=json&data=<json> against a warehouse
 * registered in Delhivery One (pickup_location = its exact name); weight in
 * grams. Status = StatusType (UD forward, RT return, DL final, CN cancelled)
 * + Status. A cancel before pickup leaves the parcel "Manifested", so the
 * cancel call itself is authoritative. Scan Push webhooks are set up by
 * Delhivery on request and carry no signature (the URL token is the key).
 */
class DelhiveryDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://track.delhivery.com/';
    private const STAGING = 'https://staging-express.delhivery.com/';

    /** NSL codes Delhivery treats as delivery attempts eligible for re-attempt. */
    private const NDR_CODES = ['EOD-74', 'EOD-15', 'EOD-104', 'EOD-43', 'EOD-86', 'EOD-11', 'EOD-69', 'EOD-6'];

    public static function meta(): array
    {
        return [
            'code' => 'delhivery',
            'name' => 'Delhivery',
            'region' => 'India',
            'countries' => ['IN'],
            'website' => 'https://www.delhivery.com',
            'tracking_url' => null,
            'description' => 'Delhivery Express parcels with COD, PDF labels, tracking and Scan Push status updates.',
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
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'required' => true,
                    'help' => 'Delhivery One → Settings → API Setup. Staging and live tokens differ; generating a new token revokes the old one.'],
            ],
            'settings' => [
                ['key' => 'pickup_location', 'label' => 'Pickup location (warehouse name)', 'type' => 'text', 'required' => true,
                    'help' => 'Exactly as registered in Delhivery One — case and spaces matter.'],
                ['key' => 'shipping_mode', 'label' => 'Shipping mode', 'type' => 'select', 'options' => [
                    ['value' => 'Surface', 'label' => 'Surface'], ['value' => 'Express', 'label' => 'Express'],
                ]],
                ['key' => 'label_size', 'label' => 'Label size', 'type' => 'select', 'options' => [
                    ['value' => '4R', 'label' => '4 × 6 in (4R)'], ['value' => 'A4', 'label' => 'A4'],
                ]],
                ['key' => 'parcel_length', 'label' => 'Default parcel length (cm)', 'type' => 'number'],
                ['key' => 'parcel_width', 'label' => 'Default parcel width (cm)', 'type' => 'number'],
                ['key' => 'parcel_height', 'label' => 'Default parcel height (cm)', 'type' => 'number'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        // No dedicated test endpoint: a serviceability lookup proves the token.
        $res = $this->client()->get($this->base() . 'c/api/pin-codes/json/', ['filter_codes' => '110001']);
        if (! $res->successful() || ! is_array($res->json('delivery_codes'))) {
            return ConnectionResult::failed('Delhivery: ' . $this->error($res));
        }

        return ConnectionResult::ok('Connected to Delhivery.');
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $location = trim((string) $this->account->setting('pickup_location'));
        if ($location === '') {
            throw new CourierException('Delhivery: set the pickup location (warehouse name) on the courier account.');
        }
        $d = Shipper::dimensions($this->account);
        $cod = $request->codAmount > 0;

        $shipment = array_filter([
            'name' => $this->clean(mb_substr($request->consigneeName, 0, 100)),
            'order' => mb_substr($request->reference, 0, 40) . '-' . Str::lower(Str::random(4)),
            'phone' => $this->phone($request->consigneePhone),
            'add' => $this->clean($request->address),
            'pin' => preg_replace('/\D+/', '', (string) $request->zip),
            'city' => $request->city,
            'state' => $request->state,
            'country' => 'India',
            'payment_mode' => $cod ? 'COD' : 'Prepaid',
            'cod_amount' => $cod ? round($request->codAmount, 2) : null,
            'total_amount' => round($request->declaredValue ?: $request->codAmount, 2),
            'products_desc' => $this->clean($request->itemsDescription ?: 'Goods'),
            'quantity' => (string) $request->pieces,
            'weight' => max(1, round($request->weight * 1000)),
            'shipment_length' => $d['length'],
            'shipment_width' => $d['width'],
            'shipment_height' => $d['height'],
            'shipping_mode' => $this->account->setting('shipping_mode') ?: 'Surface',
            'order_date' => now()->toDateTimeString(),
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->asForm()->post($this->base() . 'api/cmu/create.json', [
            'format' => 'json',
            'data' => json_encode(['shipments' => [$shipment], 'pickup_location' => ['name' => $location]]),
        ]);
        $pkg = $res->json('packages.0') ?? [];
        $waybill = $pkg['waybill'] ?? null;
        if (! $res->successful() || ! $waybill || strcasecmp((string) ($pkg['status'] ?? ''), 'Success') !== 0) {
            $remark = collect((array) ($pkg['remarks'] ?? []))->filter()->implode('; ') ?: $res->json('rmk');
            throw new CourierException('Delhivery: ' . ($remark ?: $this->error($res)));
        }

        return new BookingResult(trackingNumber: (string) $waybill, status: CourierStatus::BOOKED, rawStatus: 'Manifested',
            meta: ['order' => $shipment['order']]);
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'api/v1/packages/json/', ['waybill' => (string) $shipment->tracking_number]);
        $s = $res->json('ShipmentData.0.Shipment');
        if (! $res->successful() || ! is_array($s)) {
            throw new CourierException('Delhivery: ' . ($res->json('Error') ?: $this->error($res)));
        }

        $events = [];
        foreach ((array) ($s['Scans'] ?? []) as $scan) {
            $d = $scan['ScanDetail'] ?? $scan;
            $events[] = new TrackingEvent(
                $this->map($d['ScanType'] ?? null, $d['Scan'] ?? null, $d['StatusCode'] ?? null),
                (string) ($d['Scan'] ?? 'Scan'),
                $this->time($d['StatusDateTime'] ?? $d['ScanDateTime'] ?? null),
                $d['ScannedLocation'] ?? null,
                $d['Instructions'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));

        $st = (array) ($s['Status'] ?? []);
        $status = $this->map($st['StatusType'] ?? null, $st['Status'] ?? null, $st['StatusCode'] ?? $s['NSLCode'] ?? null);

        return new TrackingResult(
            status: $status,
            rawStatus: trim(($st['StatusType'] ?? '') . ' ' . ($st['Status'] ?? '')) ?: null,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $this->time($s['DeliveryDate'] ?? $st['StatusDateTime'] ?? null) : null,
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post($this->base() . 'api/p/edit', ['waybill' => (string) $shipment->tracking_number, 'cancellation' => 'true']);
        if (! $res->successful() || ! in_array($res->json('status'), [true, 'true', 'Success'], true)) {
            throw new CourierException('Delhivery: ' . ($res->json('remark') ?: $this->error($res)));
        }
    }

    /** pdf=true returns a link to the PDF (key name varies, so any PDF URL in the reply is taken). */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->get($this->base() . 'api/p/packing_slip', [
            'wbns' => (string) $shipment->tracking_number,
            'pdf' => 'true',
            'pdf_size' => $this->account->setting('label_size') ?: '4R',
        ]);
        if ($res->successful() && str_starts_with($res->body(), '%PDF')) {
            return ['pdf' => $res->body()];
        }
        $url = $res->json('packages.0.pdf_download_link') ?? $this->findUrl((array) $res->json());
        if (! $res->successful() || ! $url) {
            throw new CourierException('Delhivery: ' . ((int) $res->json('packages_found') === 0 ? 'no label for this waybill yet.' : $this->error($res)));
        }

        return ['url' => $url];
    }

    /** Scan Push: {"Shipment": {"AWB", "Status": {Status, StatusType, StatusDateTime, StatusLocation, Instructions}, "NSLCode"}}. */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $s = $request->input('Shipment');
        if (! is_array($s) || empty($s['AWB'])) {
            return null;
        }

        $st = (array) ($s['Status'] ?? []);
        $status = $this->map($st['StatusType'] ?? null, $st['Status'] ?? null, $s['NSLCode'] ?? $st['StatusCode'] ?? null);
        $at = $this->time($st['StatusDateTime'] ?? null);
        $raw = trim(($st['StatusType'] ?? '') . ' ' . ($st['Status'] ?? ''));

        return new WebhookUpdate((string) $s['AWB'], new TrackingResult(
            status: $status,
            rawStatus: $raw ?: null,
            events: [new TrackingEvent($status, ($st['Status'] ?? '') ?: 'Update', $at, $st['StatusLocation'] ?? null, $st['Instructions'] ?? null)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    public function map(?string $type, ?string $status, ?string $nsl = null): ?string
    {
        $type = strtoupper(trim((string) $type));
        $s = strtolower(trim((string) $status));

        return match (true) {
            $s === 'lost' => null, // shown as raw status; staff decide (claim, re-ship)
            $type === 'DL' && $s === 'rto' => CourierStatus::RETURNED,
            $type === 'DL' && $s === 'dto' => CourierStatus::DELIVERED,
            $type === 'DL' => CourierStatus::DELIVERED,
            $type === 'RT' => CourierStatus::RETURNING,
            $type === 'CN' => CourierStatus::CANCELLED,
            $type === 'PP' => CourierStatus::BOOKED,
            $type === 'PU' => $s === 'dispatched' ? CourierStatus::OUT_FOR_DELIVERY : CourierStatus::IN_TRANSIT,
            $type === 'UD' && in_array($s, ['manifested', 'not picked'], true) => CourierStatus::BOOKED,
            $type === 'UD' && $s === 'dispatched' => CourierStatus::OUT_FOR_DELIVERY,
            $type === 'UD' && $s === 'pending' && in_array(strtoupper((string) $nsl), self::NDR_CODES, true) => CourierStatus::FAILED_ATTEMPT,
            $type === 'UD' => CourierStatus::IN_TRANSIT,
            default => null,
        };
    }

    /** 10-digit Indian mobile. */
    private function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /** Delhivery rejects ; & # % \ in text fields. */
    private function clean(?string $value): string
    {
        return trim(str_replace([';', '&', '#', '%', '\\'], ' ', (string) $value));
    }

    private function findUrl(array $data): ?string
    {
        foreach ($data as $v) {
            if (is_string($v) && preg_match('#^https?://\S+#', $v) && str_contains(strtolower($v), 'pdf')) {
                return $v;
            }
            if (is_array($v) && ($found = $this->findUrl($v))) {
                return $found;
            }
        }

        return null;
    }

    private function client()
    {
        return $this->http()->withHeaders(['Authorization' => 'Token ' . $this->account->credential('api_token')]);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::STAGING : self::LIVE;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value, 'Asia/Kolkata') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        foreach (['rmk', 'error', 'Error', 'detail', 'message'] as $key) {
            $v = $res->json($key);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return 'HTTP ' . $res->status();
    }
}
