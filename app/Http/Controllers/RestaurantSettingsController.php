<?php

namespace App\Http\Controllers;

use App\Models\RestaurantSetting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Restaurant Management › Settings (permission rst_settings).
 * GET  restaurant/settings → {ready, settings, warehouses}
 * PUT  restaurant/settings → {settings}
 */
class RestaurantSettingsController extends BaseController
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_settings', RestaurantSetting::class);

        if (! RestaurantSetting::tablesReady()) {
            return response()->json(['ready' => false, 'settings' => null, 'warehouses' => []]);
        }

        return response()->json([
            'ready' => true,
            'settings' => $this->present(RestaurantSetting::current()),
            'warehouses' => $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_settings', RestaurantSetting::class);

        if (! RestaurantSetting::tablesReady()) {
            return response()->json(['message' => 'Run the latest database update first.'], 503);
        }

        $data = $request->validate([
            'order_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_]+$/'],
            'dine_in_enabled' => 'required|boolean',
            'takeaway_enabled' => 'required|boolean',
            'delivery_enabled' => 'required|boolean',
            'require_guest_count' => 'required|boolean',
            'service_charge_percent' => 'required|numeric|min:0|max:100',
            'service_charge_scope' => ['required', Rule::in(RestaurantSetting::SERVICE_CHARGE_SCOPES)],
            'tips_enabled' => 'required|boolean',
            'tip_presets' => 'nullable|array|max:6',
            'tip_presets.*' => 'numeric|min:0.5|max:100',
            'bill_rounding' => ['required', 'numeric', Rule::in(RestaurantSetting::ROUNDING_STEPS)],
            'max_discount_percent' => 'required|numeric|min:0|max:100',
            'default_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'late_ticket_minutes' => 'required|integer|min:1|max:240',
            'after_payment_table_status' => ['required', Rule::in(RestaurantSetting::AFTER_PAYMENT_STATUSES)],
            'kitchen_online_orders' => ['required', Rule::in(RestaurantSetting::KITCHEN_ONLINE_ORDERS)],
            'receipt_header' => 'nullable|string|max:1000',
            'receipt_footer' => 'nullable|string|max:1000',
            // Reservations (phase 7) — optional so an older page can still save.
            'reservation_minutes' => 'sometimes|required|integer|min:15|max:720',
            'reservation_hold_minutes' => 'sometimes|required|integer|min:0|max:240',
            'no_show_minutes' => 'sometimes|required|integer|min:0|max:240',
            'max_party_size' => 'sometimes|required|integer|min:1|max:500',
            // QR table ordering + online booking (phase 8)
            'qr_ordering_enabled' => 'sometimes|required|boolean',
            'qr_auto_send' => 'sometimes|required|boolean',
            'online_booking_enabled' => 'sometimes|required|boolean',
            'booking_from' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'booking_to' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'booking_slot_minutes' => 'sometimes|required|integer|in:15,30,60',
            'online_max_party' => 'sometimes|required|integer|min:1|max:500',
            'booking_notice_minutes' => 'sometimes|required|integer|min:0|max:10080',
            'booking_days_ahead' => 'sometimes|required|integer|min:1|max:365',
        ]);
        if (! \Illuminate\Support\Facades\Schema::hasColumn('rst_settings', 'qr_ordering_enabled')) {
            foreach (['qr_ordering_enabled', 'qr_auto_send', 'online_booking_enabled', 'booking_from', 'booking_to', 'booking_slot_minutes', 'online_max_party', 'booking_notice_minutes', 'booking_days_ahead'] as $k) {
                unset($data[$k]);
            }
        }
        if (isset($data['booking_from'], $data['booking_to']) && $data['booking_to'] <= $data['booking_from']) {
            return response()->json(['message' => __('The last booking time must be after the first.'), 'errors' => [
                'booking_to' => [__('The last booking time must be after the first.')],
            ]], 422);
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('rst_settings', 'reservation_minutes')) {
            unset($data['reservation_minutes'], $data['reservation_hold_minutes'], $data['no_show_minutes'], $data['max_party_size']);
        }

        // Only a branch the user may see (a changed value; an unchanged one is kept).
        if (! empty($data['default_warehouse_id']) && (int) $data['default_warehouse_id'] !== (int) RestaurantSetting::current()->default_warehouse_id) {
            $this->abortIfWarehouseDenied((int) $data['default_warehouse_id']);
        }
        if (! $data['dine_in_enabled'] && ! $data['takeaway_enabled'] && ! $data['delivery_enabled']) {
            return response()->json(['message' => __('Keep at least one order type switched on.'), 'errors' => [
                'dine_in_enabled' => [__('Keep at least one order type switched on.')],
            ]], 422);
        }

        $data['order_prefix'] = strtoupper($data['order_prefix']);
        $data['tip_presets'] = collect($data['tip_presets'] ?? [])->map(fn ($p) => round((float) $p, 2))->unique()->sort()->values()->all() ?: null;

        $setting = RestaurantSetting::current();
        $setting->fill($data)->save();

        return response()->json(['settings' => $this->present($setting->fresh())]);
    }

    private function present(RestaurantSetting $s): array
    {
        return [
            'order_prefix' => $s->order_prefix,
            'dine_in_enabled' => $s->dine_in_enabled,
            'takeaway_enabled' => $s->takeaway_enabled,
            'delivery_enabled' => $s->delivery_enabled,
            'require_guest_count' => $s->require_guest_count,
            'service_charge_percent' => (float) $s->service_charge_percent,
            'service_charge_scope' => $s->service_charge_scope,
            'tips_enabled' => $s->tips_enabled,
            'tip_presets' => $s->tipPresets(),
            'bill_rounding' => (float) $s->bill_rounding,
            'max_discount_percent' => (float) $s->max_discount_percent,
            'default_warehouse_id' => $s->default_warehouse_id,
            'late_ticket_minutes' => (int) $s->late_ticket_minutes,
            'after_payment_table_status' => $s->after_payment_table_status,
            'kitchen_online_orders' => $s->kitchen_online_orders,
            'receipt_header' => (string) ($s->receipt_header ?? ''),
            'receipt_footer' => (string) ($s->receipt_footer ?? ''),
            'reservation_minutes' => (int) ($s->reservation_minutes ?? 90),
            'reservation_hold_minutes' => (int) ($s->reservation_hold_minutes ?? 30),
            'no_show_minutes' => (int) ($s->no_show_minutes ?? 15),
            'max_party_size' => (int) ($s->max_party_size ?? 20),
            'qr_ordering_enabled' => (bool) ($s->qr_ordering_enabled ?? false),
            'qr_auto_send' => (bool) ($s->qr_auto_send ?? false),
            'online_booking_enabled' => (bool) ($s->online_booking_enabled ?? false),
            'booking_from' => (string) ($s->booking_from ?? '12:00'),
            'booking_to' => (string) ($s->booking_to ?? '22:00'),
            'booking_slot_minutes' => (int) ($s->booking_slot_minutes ?? 30),
            'online_max_party' => (int) ($s->online_max_party ?? 8),
            'booking_notice_minutes' => (int) ($s->booking_notice_minutes ?? 60),
            'booking_days_ahead' => (int) ($s->booking_days_ahead ?? 30),
        ];
    }
}
