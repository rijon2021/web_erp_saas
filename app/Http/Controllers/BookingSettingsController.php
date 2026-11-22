<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\StoreSetting;
use App\Models\Warehouse;
use App\Services\Booking\BookingDepositService;
use App\Services\Crm\CrmCaptureService;
use App\Support\BookingTypes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Booking Management › Settings (permission bookings_settings) and the
 * business-type registry every booking page reads.
 * GET  bookings/settings → {ready, settings, registry, warehouses, store_theme, store_enabled, deposits, crm}
 * PUT  bookings/settings → {settings, registry}
 * GET  bookings/types    → registry + {ready} (any booking permission)
 */
class BookingSettingsController extends BaseController
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_settings', Booking::class);

        $store = StoreSetting::query()->first();
        $base = [
            'store_theme' => $store->theme ?? null,
            'store_enabled' => (bool) ($store->enabled ?? false),
        ];

        if (! BookingSetting::tablesReady()) {
            return response()->json($base + ['ready' => false, 'settings' => null, 'registry' => BookingTypes::forAdmin(), 'warehouses' => []]);
        }

        $s = BookingSetting::current();

        return response()->json($base + [
            'ready' => true,
            'settings' => $this->present($s),
            'registry' => BookingTypes::forAdmin($s),
            'warehouses' => $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']),
            'deposits' => $this->depositOptions(),
            'crm' => ['ready' => Booking::depositsReady(), 'available' => CrmCaptureService::enabled()],
        ]);
    }

    /** Which gateways could take deposits: set up and switched on under Online Store › Payment Gateway. */
    private function depositOptions(): array
    {
        if (! Booking::depositsReady()) {
            return ['ready' => false, 'gateways' => [], 'currency' => null];
        }
        $svc = app(BookingDepositService::class);
        $store = StoreSetting::query()->first();
        $gateways = [];
        foreach (BookingDepositService::GATEWAYS as $key => $method) {
            $gateways[] = [
                'key' => $key,
                'label' => BookingDepositService::LABELS[$key],
                'configured' => $svc->configured($key),
                'enabled' => StoreSetting::paymentMethodEnabled($method, $store),
            ];
        }

        return ['ready' => true, 'gateways' => $gateways, 'currency' => $svc->currency()];
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_settings', Booking::class);

        if (! BookingSetting::tablesReady()) {
            return response()->json(['message' => __('Run the latest database update first.')], 503);
        }

        $hhmm = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $data = $request->validate([
            'business_types' => 'present|array',
            'business_types.*' => ['string', Rule::in(BookingTypes::TYPES)],
            'slot_booking' => 'required|boolean',
            'request_booking' => 'required|boolean',
            'slot_step_minutes' => ['required', 'integer', Rule::in(BookingSetting::SLOT_STEPS)],
            'min_notice_minutes' => 'required|integer|min:0|max:43200',
            'max_days_ahead' => 'required|integer|min:1|max:730',
            'hold_minutes' => 'required|integer|min:0|max:10080',
            'auto_confirm' => 'required|boolean',
            'max_open_per_customer' => 'required|integer|min:1|max:20',
            'customer_cancel' => 'required|boolean',
            'cancel_notice_hours' => 'required|integer|min:0|max:720',
            'form_fields' => 'nullable|array',
            'time_windows' => 'required|array',
            'time_windows.*.enabled' => 'required|boolean',
            'time_windows.*.from' => ['required', $hhmm],
            'time_windows.*.to' => ['required', $hhmm],
            'service_areas' => 'nullable|array|max:50',
            'service_areas.*' => 'nullable|string|max:120',
            'notify_owner' => 'required|boolean',
            'notify_owner_email' => 'nullable|email|max:191',
            'notify_customer' => 'required|boolean',
            'reminder_hours' => ['required', 'integer', Rule::in(BookingSetting::REMINDER_HOURS)],
            'whatsapp_notify' => 'required|boolean',
            'hero_form' => 'required|boolean',
            'show_team' => 'required|boolean',
            'terms' => 'nullable|string|max:5000',
            'default_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            // Phase 8 (left out by pages from before it = unchanged)
            'deposit_type' => ['sometimes', 'required', Rule::in(BookingSetting::DEPOSIT_TYPES)],
            'deposit_value' => 'sometimes|required|numeric|min:0|max:99999999',
            'deposit_minutes' => 'sometimes|required|integer|min:5|max:1440',
            'deposit_auto_cancel' => 'sometimes|required|boolean',
            'deposit_on_requests' => 'sometimes|required|boolean',
            'deposit_note' => 'sometimes|nullable|string|max:500',
            'deposit_gateways' => 'sometimes|nullable|array',
            'deposit_gateways.*' => ['string', Rule::in(array_keys(BookingDepositService::GATEWAYS))],
            'crm_capture' => 'sometimes|required|boolean',
            'crm_request_deals' => 'sometimes|required|boolean',
        ]);
        if (! Booking::depositsReady()) {
            $data = array_diff_key($data, array_flip([
                'deposit_type', 'deposit_value', 'deposit_minutes', 'deposit_auto_cancel', 'deposit_on_requests',
                'deposit_note', 'deposit_gateways', 'crm_capture', 'crm_request_deals',
            ]));
        }
        if (($data['deposit_type'] ?? 'none') === 'percent' && (float) ($data['deposit_value'] ?? 0) > 100) {
            return $this->fail('deposit_value', __('A percentage deposit can be at most 100.'));
        }
        if (in_array($data['deposit_type'] ?? 'none', ['fixed', 'percent'], true) && (float) ($data['deposit_value'] ?? 0) <= 0) {
            return $this->fail('deposit_value', __('Give the deposit an amount.'));
        }

        if (! $data['slot_booking'] && ! $data['request_booking']) {
            return $this->fail('slot_booking', __('Keep at least one way to book switched on.'));
        }

        $windows = BookingSetting::cleanTimeWindows($data['time_windows']);
        foreach ($windows as $key => $w) {
            if ($w['to'] <= $w['from']) {
                return $this->fail('time_windows.'.$key.'.to', __('Each time window must end after it starts.'));
            }
        }
        if ($data['request_booking'] && ! array_filter($windows, fn ($w) => $w['enabled'])) {
            return $this->fail('time_windows', __('Keep at least one time window on for booking requests.'));
        }

        $setting = BookingSetting::current();

        // Only a branch the user may see (a changed value; an unchanged one is kept).
        if (! empty($data['default_warehouse_id']) && (int) $data['default_warehouse_id'] !== (int) $setting->default_warehouse_id) {
            $this->abortIfWarehouseDenied((int) $data['default_warehouse_id']);
        }

        $data['business_types'] = BookingTypes::normalize($data['business_types']) ?: null;
        $data['form_fields'] = BookingTypes::cleanFieldModes($data['form_fields'] ?? []);
        $data['time_windows'] = $windows;
        $data['service_areas'] = BookingTypes::cleanAreas($data['service_areas'] ?? []);
        $data['notify_owner_email'] = ($data['notify_owner_email'] ?? '') ?: null;
        $data['terms'] = trim((string) ($data['terms'] ?? '')) ?: null;
        if (array_key_exists('deposit_note', $data)) {
            $data['deposit_note'] = trim((string) $data['deposit_note']) ?: null;
        }
        if (array_key_exists('deposit_gateways', $data)) {
            $data['deposit_gateways'] = array_values(array_unique((array) $data['deposit_gateways'])) ?: null;
        }

        $setting->fill($data)->save();
        $setting = $setting->fresh();

        return response()->json(['settings' => $this->present($setting), 'registry' => BookingTypes::forAdmin($setting)]);
    }

    public function types(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_any', Booking::class);

        return response()->json(BookingTypes::forAdmin() + ['ready' => BookingSetting::tablesReady()]);
    }

    private function fail(string $field, string $message)
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function present(BookingSetting $s): array
    {
        return [
            'business_types' => BookingTypes::normalize($s->business_types),
            'slot_booking' => (bool) $s->slot_booking,
            'request_booking' => (bool) $s->request_booking,
            'slot_step_minutes' => (int) $s->slot_step_minutes,
            'min_notice_minutes' => (int) $s->min_notice_minutes,
            'max_days_ahead' => (int) $s->max_days_ahead,
            'hold_minutes' => (int) $s->hold_minutes,
            'auto_confirm' => (bool) $s->auto_confirm,
            'max_open_per_customer' => (int) $s->max_open_per_customer,
            'customer_cancel' => (bool) $s->customer_cancel,
            'cancel_notice_hours' => (int) $s->cancel_notice_hours,
            'form_fields' => BookingTypes::fieldModes($s),
            'time_windows' => $s->timeWindows(),
            'service_areas' => BookingTypes::serviceAreas($s),
            'notify_owner' => (bool) $s->notify_owner,
            'notify_owner_email' => (string) ($s->notify_owner_email ?? ''),
            'notify_customer' => (bool) $s->notify_customer,
            'reminder_hours' => (int) $s->reminder_hours,
            'whatsapp_notify' => (bool) $s->whatsapp_notify,
            'hero_form' => (bool) $s->hero_form,
            'show_team' => (bool) $s->show_team,
            'terms' => (string) ($s->terms ?? ''),
            'default_warehouse_id' => $s->default_warehouse_id,
        ] + (Booking::depositsReady() ? [
            'deposit_type' => in_array($s->deposit_type, BookingSetting::DEPOSIT_TYPES, true) ? $s->deposit_type : 'none',
            'deposit_value' => (float) $s->deposit_value,
            'deposit_minutes' => (int) $s->deposit_minutes,
            'deposit_auto_cancel' => (bool) $s->deposit_auto_cancel,
            'deposit_on_requests' => (bool) $s->deposit_on_requests,
            'deposit_note' => (string) ($s->deposit_note ?? ''),
            'deposit_gateways' => array_values((array) ($s->deposit_gateways ?? [])),
            'crm_capture' => (bool) $s->crm_capture,
            'crm_request_deals' => (bool) $s->crm_request_deals,
        ] : []);
    }
}
