<?php

namespace App\Http\Controllers;

use App\Models\CourierSetting;
use App\Models\OnlineOrder;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\StoreSetting;
use App\Services\Couriers\CourierRegistry;
use App\Services\Couriers\CourierStatus;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Public parcel tracking on the storefront (/track). A customer finds a
 * parcel by its tracking number, or by order number + the phone number on
 * the order (last 7 digits must match). Only courier-reported progress is
 * shown — no address, no staff notes; the first name and destination city
 * only after the order + phone check, never for a bare tracking number.
 */
class StoreTrackingController extends Controller
{
    public const STEPS = [CourierStatus::BOOKED, CourierStatus::PICKED_UP, CourierStatus::IN_TRANSIT, CourierStatus::OUT_FOR_DELIVERY, CourierStatus::DELIVERED];

    public function show(Request $request)
    {
        if (! $this->available()) {
            abort(404);
        }

        $s = StoreSetting::first();
        $t = trim((string) $request->query('t', ''));
        $order = trim((string) $request->query('order', ''));
        $phone = trim((string) $request->query('phone', ''));
        $searched = $t !== '' || $order !== '';
        $shipment = null;

        if ($t !== '' && mb_strlen($t) <= 80) {
            $shipment = $this->courierShipments()->where('tracking_number', $t)->latest('id')->first();
        } elseif ($order !== '' && $phone !== '' && mb_strlen($order) <= 80) {
            $shipment = $this->byOrder($order, $phone);
        }

        return view('store.track', [
            's' => $s,
            'searched' => $searched,
            'query' => ['t' => $t, 'order' => $order, 'phone' => $phone],
            // Name and destination only for a lookup that proved the phone number.
            'tracking' => $shipment ? $this->present($shipment, $t === '') : null,
        ]);
    }

    private function available(): bool
    {
        try {
            return Schema::hasColumn('shipments', 'courier_status')
                && app(TenantLimitsService::class)->hasFeature('couriers')
                && CourierSetting::current()->trackingPageEnabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function courierShipments()
    {
        return Shipment::query()->whereNull('deleted_at')->whereNotNull('courier_account_id')->whereNotNull('tracking_number');
    }

    /** Order number (sale or online order ref) + phone on the order. */
    private function byOrder(string $ref, string $phone): ?Shipment
    {
        $digits = substr(preg_replace('/\D+/', '', $phone), -7);
        if (strlen($digits) < 7) {
            return null;
        }

        $sale = Sale::query()->whereNull('deleted_at')->where('Ref', $ref)->with('client:id,phone')->first();
        $orderPhone = null;
        if (! $sale) {
            $online = OnlineOrder::query()->where('ref', $ref)->whereNotNull('sale_id')->first();
            $sale = $online ? Sale::query()->whereNull('deleted_at')->with('client:id,phone')->find($online->sale_id) : null;
            $orderPhone = $online?->customer_phone;
        }
        if (! $sale) {
            return null;
        }

        $shipment = $this->courierShipments()->where('sale_id', $sale->id)->latest('id')->first();
        if (! $shipment) {
            return null;
        }

        $phones = array_filter([$sale->client?->phone, $orderPhone, $shipment->courier_meta['consignee']['phone'] ?? null]);
        foreach ($phones as $p) {
            if (str_ends_with(preg_replace('/\D+/', '', (string) $p), $digits)) {
                return $shipment;
            }
        }

        return null;
    }

    private function present(Shipment $shipment, bool $verified = false): array
    {
        $account = $shipment->courierAccount()->withTrashed()->first();
        $courierName = $account ? (CourierRegistry::meta($account->courier)['name'] ?? $account->name) : $shipment->courier;
        if ($account && $account->courier === 'manual') {
            $courierName = $account->name;
        }
        $status = $shipment->courier_status;

        // Courier-reported lines only: staff notes and notification records stay private.
        $events = $shipment->events()->whereIn('source', ['booking', 'webhook', 'poll', 'manual'])->get()
            // Staff lines only when they move the parcel (COD bookkeeping stays internal).
            ->filter(fn ($e) => $e->source === 'manual' ? (bool) $e->status : ($e->status || $e->raw_status))
            ->map(fn ($e) => [
                'status' => $e->status,
                'text' => $e->raw_status ?: null,
                // Courier details can name the recipient ("signed by …"): verified lookups only.
                'detail' => $verified && in_array($e->source, ['webhook', 'poll'], true) ? $e->description : null,
                'location' => $e->location,
                'at' => $e->event_at,
            ])->values();

        $stepIndex = array_search($status, self::STEPS, true);
        if ($stepIndex === false) {
            $stepIndex = match ($status) {
                CourierStatus::FAILED_ATTEMPT => 3,
                CourierStatus::RETURNING, CourierStatus::RETURNED => 2,
                default => -1,
            };
        }

        return [
            'tracking_number' => $shipment->tracking_number,
            'courier' => $courierName,
            'courier_link' => $shipment->trackingLink(),
            'status' => $status,
            'step' => $stepIndex,
            'name' => $verified ? trim(explode(' ', trim((string) $shipment->delivered_to))[0] ?? '') : '',
            'city' => $verified ? $shipment->destination_city : null,
            'booked_at' => $shipment->booked_at,
            'delivered_at' => $shipment->delivered_at,
            'events' => $events,
        ];
    }
}
