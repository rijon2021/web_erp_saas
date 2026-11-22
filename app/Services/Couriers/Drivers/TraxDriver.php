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
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Trax (Pakistan) — Sonic API v2.0. Live https://sonic.pk/api/, testing
 * https://app.sonic.pk/api/. Header `Authorization: <API key>` (no Bearer).
 * Success is body status 0 (HTTP stays 200 on errors). Cities and pickup
 * addresses are numeric IDs; the airway bill is a PDF. Webhooks (enabled in
 * the Trax portal) push shipment status, payment status and charges; they are
 * unsigned, so the secret token in this account's webhook URL authenticates them.
 */
class TraxDriver extends AbstractCourierDriver
{
    private const LIVE = 'https://sonic.pk/api/';
    private const TEST = 'https://app.sonic.pk/api/';

    public static function meta(): array
    {
        return [
            'code' => 'trax',
            'name' => 'Trax',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://slgtrax.com',
            'tracking_url' => null,
            'description' => 'Trax (Sonic) API: COD booking, airway bills, tracking, payments and instant status webhooks.',
            'capabilities' => [
                'booking' => true,
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
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true,
                    'help' => 'Trax portal → Profile → API key (bottom of the page).'],
            ],
            'settings' => [
                ['key' => 'pickup_address_id', 'label' => 'Pickup address ID', 'type' => 'number', 'required' => true,
                    'help' => '"Test connection" lists your pickup address IDs.'],
                ['key' => 'shipping_mode_id', 'label' => 'Shipping mode', 'type' => 'select', 'options' => [
                    ['value' => '1', 'label' => 'Rush'], ['value' => '2', 'label' => 'Saver Plus'],
                    ['value' => '3', 'label' => 'Swift'], ['value' => '4', 'label' => 'Same day'],
                ], 'help' => 'Leave empty for Rush.'],
                ['key' => 'charges_mode_id', 'label' => 'Charges mode', 'type' => 'select', 'options' => [
                    ['value' => '4', 'label' => 'Reimbursement (deducted from COD)'],
                    ['value' => '3', 'label' => 'Invoicing'],
                    ['value' => '2', 'label' => 'Consignee pays'],
                ], 'help' => 'Must match your Trax account type. Leave empty for Reimbursement.'],
                ['key' => 'item_product_type_id', 'label' => 'Product type ID', 'type' => 'number',
                    'help' => 'Trax product category (1 Apparel … 24 Other). Leave empty for 24.'],
                ['key' => 'delivery_type_id', 'label' => 'Delivery type', 'type' => 'select', 'options' => [
                    ['value' => '1', 'label' => 'Doorstep'], ['value' => '2', 'label' => 'Hub to hub'],
                ], 'help' => 'Required for corporate invoicing accounts only.'],
                ['key' => 'fallback_email', 'label' => 'Email when the customer has none', 'type' => 'text',
                    'help' => 'Trax requires a consignee email address.'],
                ['key' => 'show_shipper_contact', 'label' => 'Show your contact on the airway bill', 'type' => 'switch'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->client()->get($this->base() . 'pickup_addresses');
        if (! $this->ok($res)) {
            return ConnectionResult::failed('Trax: ' . $this->error($res));
        }

        $ids = collect($res->json('pickup_addresses') ?? [])
            ->map(fn ($p) => ($p['id'] ?? '?') . ' (' . ($p['city']['name'] ?? '') . ')')->implode(', ');

        return ConnectionResult::ok('Connected to Trax.' . ($ids ? " Pickup address IDs: {$ids}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $acc = $this->account;
        $city = $request->courierCityCode ?: $this->cityIdByName($request->courierCityName ?: $request->city);
        if (! $city) {
            throw new CourierException('Choose the Trax city for "' . $request->city . '".');
        }
        $email = $request->consigneeEmail ?: $acc->setting('fallback_email');
        if (! $email) {
            throw new CourierException('Trax needs a customer email. Add one, or set "Email when the customer has none" on the courier account.');
        }

        $body = array_filter([
            'service_type_id' => 1,
            'pickup_address_id' => (int) $acc->setting('pickup_address_id'),
            'information_display' => $acc->setting('show_shipper_contact') ? 1 : 0,
            'consignee_city_id' => (int) $city,
            'consignee_name' => mb_substr($request->consigneeName, 0, 100),
            'consignee_address' => mb_substr($request->address, 0, 190),
            'consignee_phone_number_1' => Phone::pk($request->consigneePhone),
            'consignee_email_address' => $email,
            'order_id' => mb_substr($request->reference, 0, 100),
            'item_product_type_id' => (int) ($acc->setting('item_product_type_id') ?: 24),
            'item_description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 190),
            'item_quantity' => $request->pieces,
            'item_insurance' => 0,
            'item_price' => $request->declaredValue > 0 ? (int) round($request->declaredValue) : null,
            'pickup_date' => now()->toDateString(),
            'special_instructions' => $request->notes ?: null,
            'estimated_weight' => round($request->weight, 2),
            'shipping_mode_id' => (int) (is_numeric($request->service) ? $request->service : ($acc->setting('shipping_mode_id') ?: 1)),
            'amount' => (int) round($request->codAmount),
            'payment_mode_id' => $request->codAmount > 0 ? 1 : 4,
            'charges_mode_id' => (int) ($acc->setting('charges_mode_id') ?: 4),
            'open_shipment' => $request->allowOpenParcel ? 1 : 0,
            'pieces_quantity' => min(10, max(1, $request->pieces)),
            'delivery_type_id' => $acc->setting('delivery_type_id') ? (int) $acc->setting('delivery_type_id') : null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->asForm()->post($this->base() . 'shipment/book', $body);
        $tracking = $res->json('tracking_number') ?? $res->json('tracking number');
        if (! $this->ok($res) || ! $tracking) {
            throw new CourierException('Trax: ' . $this->error($res));
        }

        return new BookingResult(trackingNumber: (string) $tracking, status: CourierStatus::BOOKED, rawStatus: 'Shipment - Booked');
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->asForm()->post($this->base() . 'shipment/cancel', ['tracking_number' => (string) $shipment->tracking_number]);
        if (! $this->ok($res)) {
            throw new CourierException('Trax: ' . $this->error($res));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->get($this->base() . 'shipment/track', ['tracking_number' => (string) $shipment->tracking_number, 'type' => 0]);
        if (! $this->ok($res)) {
            throw new CourierException('Trax: ' . $this->error($res));
        }

        $events = [];
        foreach ($res->json('details.tracking_history') ?? [] as $h) {
            $text = $h['status'] ?? null;
            $events[] = new TrackingEvent(
                status: StatusText::map($text),
                rawStatus: $text,
                at: isset($h['timestamp']) && is_numeric($h['timestamp']) ? Carbon::createFromTimestamp((int) $h['timestamp']) : $this->time($h['date_time'] ?? null),
                description: $h['status_reason'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;
        $status = $latest?->status;

        $codStatus = $codRef = null;
        $charges = null;
        if (CourierStatus::isFinal($status)) {
            $charges = $this->charges($shipment);
        }
        if ($status === CourierStatus::DELIVERED) {
            // A payment-status hiccup must not lose the tracking update itself.
            try {
                $pay = $this->client()->get($this->base() . 'shipment/payment_status', ['tracking_number' => (string) $shipment->tracking_number]);
                if ($this->ok($pay) && $this->paymentDone($pay->json('current_payment_status'))) {
                    $codStatus = 'remitted';
                }
            } catch (\Throwable $e) {
                // Checked again on the next status check.
            }
        }

        return new TrackingResult(
            status: $status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $latest->at : null,
            codStatus: $codStatus,
            codReference: $codRef,
            charges: $charges,
        );
    }

    /** Airway bill as PDF (type 1). */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->withHeaders(['Accept' => 'application/pdf'])
            ->get($this->base() . 'shipment/air_waybill', ['tracking_number' => (string) $shipment->tracking_number, 'type' => 1]);

        if ($res->successful() && str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            return ['pdf' => $res->body()];
        }

        throw new CourierException('Trax: ' . $this->error($res));
    }

    public function cities(): array
    {
        $res = $this->client()->get($this->base() . 'cities');
        if (! $this->ok($res)) {
            throw new CourierException('Trax: ' . $this->error($res));
        }

        return collect($res->json('cities') ?? [])
            ->filter(fn ($c) => isset($c['id'], $c['name']) && ! empty($c['delivery']))
            ->map(fn ($c) => ['code' => (string) $c['id'], 'name' => $c['name']])
            ->values()->all();
    }

    /**
     * Trax webhooks (JSON or form): shipment status {tracking_number, status,
     * date_time}; payment status adds payment_id; charges carry total_charges.
     */
    public function parseWebhook(Request $request): WebhookUpdate|array|null
    {
        $tn = $request->input('tracking_number');
        if (! $tn) {
            return null;
        }

        $text = $request->input('status');
        $at = $this->time($request->input('date_time'));

        if ($request->filled('payment_id')) {
            $done = $this->paymentDone($text);

            return new WebhookUpdate((string) $tn, new TrackingResult(
                status: null,
                rawStatus: null,
                events: [new TrackingEvent(null, $text, $at, null, 'Payment ' . $request->input('payment_id'))],
                codStatus: $done ? 'remitted' : null,
                codReference: $done ? (string) $request->input('payment_id') : null,
                codRemittedAt: $done ? $at : null,
            ));
        }

        if ($request->filled('total_charges')) {
            return new WebhookUpdate((string) $tn, new TrackingResult(status: null, charges: (float) $request->input('total_charges')));
        }

        $status = StatusText::map($text);

        return new WebhookUpdate((string) $tn, new TrackingResult(
            status: $status,
            rawStatus: $text,
            events: [new TrackingEvent($status, $text, $at)],
            deliveredAt: $status === CourierStatus::DELIVERED ? $at : null,
        ));
    }

    // ------------------------------------------------------------------

    private function charges(Shipment $shipment): ?float
    {
        try {
            $res = $this->client()->get($this->base() . 'shipment/charges', ['tracking_number' => (string) $shipment->tracking_number]);
            $total = $res->json('charges.total_charges');

            return $this->ok($res) && is_numeric($total) ? (float) $total : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** "Payment - Processed" and similar mean the COD was paid out. */
    private function paymentDone(?string $text): bool
    {
        $t = strtolower((string) $text);

        return str_contains($t, 'processed') || str_contains($t, 'paid') || str_contains($t, 'transferred');
    }

    private function cityIdByName(?string $name): ?string
    {
        $needle = mb_strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }
        foreach ($this->cities() as $c) {
            if (mb_strtolower(trim($c['name'])) === $needle) {
                return $c['code'];
            }
        }

        return null;
    }

    private function client()
    {
        return $this->http()->withHeaders(['Authorization' => $this->account->credential('api_key')]);
    }

    private function base(): string
    {
        return $this->account->isTestMode() ? self::TEST : self::LIVE;
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && (string) $res->json('status') === '0';
    }

    /** Trax dates are dd/mm/yyyy. */
    private function time(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        foreach (['d/m/Y h:i A', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim($value));
            } catch (\Throwable $e) {
                // try the next format
            }
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        $errors = $res->json('errors');
        if (is_array($errors) && $errors) {
            return collect($errors)->flatten()->implode('; ');
        }

        return $res->json('message') ?: ('HTTP ' . $res->status());
    }
}
