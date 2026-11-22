<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\CourierSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tenant-wide courier behaviour (singleton): returned-parcel handling, COD
 * payment recording, courier charges as expenses, status poll interval,
 * customer notifications, the storefront tracking page and return-risk
 * thresholds (the last three need the 2026_10_07 tenant migration).
 */
class CourierSettingsController extends Controller
{
    public const SYNC_INTERVALS = [15, 30, 60, 120, 240, 360, 720, 1440];

    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $s = CourierSetting::current();
        $phase7 = CourierSetting::hasPhase7Columns();

        return response()->json([
            'settings' => [
                'return_action' => $s->return_action,
                'cod_auto_record' => (bool) $s->cod_auto_record,
                'cod_account_id' => $s->cod_account_id,
                'cod_payment_method_id' => $s->cod_payment_method_id,
                'charges_as_expense' => (bool) $s->charges_as_expense,
                'charges_expense_category_id' => $s->charges_expense_category_id,
                'sync_interval_minutes' => (int) $s->sync_interval_minutes,
                'notify_sms' => (bool) $s->notify_sms,
                'notify_email' => (bool) $s->notify_email,
                'notify_whatsapp' => (bool) $s->notify_whatsapp,
                'notify_statuses' => $s->notifyStatuses(),
                'notify_templates' => collect(CourierSetting::NOTIFY_STATUSES)->mapWithKeys(fn ($st) => [$st => $s->template($st)]),
                'tracking_page' => $phase7 ? (bool) ($s->tracking_page ?? true) : true,
                'risk_high_percent' => $s->riskHighPercent(),
                'risk_min_shipments' => $s->riskMinShipments(),
            ],
            'options' => [
                'accounts' => DB::table('accounts')->whereNull('deleted_at')->orderBy('account_name')
                    ->get(['id', 'account_name as name']),
                'payment_methods' => DB::table('payment_methods')->whereNull('deleted_at')->orderBy('name')
                    ->get(['id', 'name']),
                'expense_categories' => DB::table('expense_categories')->whereNull('deleted_at')->orderBy('name')
                    ->get(['id', 'name']),
                'sync_intervals' => self::SYNC_INTERVALS,
                'notify_statuses' => CourierSetting::NOTIFY_STATUSES,
                'default_templates' => CourierSetting::DEFAULT_TEMPLATES,
                // Phase 7 settings are saved only once the tenant migration has run.
                'phase7_ready' => $phase7,
                'tracking_page_url' => $this->trackingPageUrl(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $data = $request->validate([
            'return_action' => ['required', Rule::in(CourierSetting::RETURN_ACTIONS)],
            'cod_auto_record' => ['required', 'boolean'],
            'cod_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'cod_payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->whereNull('deleted_at')],
            'charges_as_expense' => ['required', 'boolean'],
            'charges_expense_category_id' => [
                'nullable', 'required_if:charges_as_expense,true,1', 'integer',
                Rule::exists('expense_categories', 'id')->whereNull('deleted_at'),
            ],
            'sync_interval_minutes' => ['required', 'integer', Rule::in(self::SYNC_INTERVALS)],
            'notify_sms' => ['sometimes', 'boolean'],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_whatsapp' => ['sometimes', 'boolean'],
            'notify_statuses' => ['sometimes', 'array'],
            'notify_statuses.*' => [Rule::in(CourierSetting::NOTIFY_STATUSES)],
            'notify_templates' => ['sometimes', 'array'],
            'notify_templates.*' => ['nullable', 'string', 'max:1000'],
            'tracking_page' => ['sometimes', 'boolean'],
            'risk_high_percent' => ['sometimes', 'integer', 'between:5,100'],
            'risk_min_shipments' => ['sometimes', 'integer', 'between:1,50'],
        ], [
            'charges_expense_category_id.required_if' => 'Choose the expense category courier charges are saved under.',
        ]);

        $s = CourierSetting::current();
        $s->fill([
            'return_action' => $data['return_action'],
            'cod_auto_record' => (bool) $data['cod_auto_record'],
            'cod_account_id' => $data['cod_account_id'] ?? null,
            'cod_payment_method_id' => $data['cod_payment_method_id'] ?? null,
            'charges_as_expense' => (bool) $data['charges_as_expense'],
            'charges_expense_category_id' => $data['charges_expense_category_id'] ?? null,
            'sync_interval_minutes' => (int) $data['sync_interval_minutes'],
        ]);

        if (CourierSetting::hasPhase7Columns()) {
            // Only texts that differ from the default are stored, so improved defaults reach everyone else.
            $templates = collect($data['notify_templates'] ?? [])
                ->only(CourierSetting::NOTIFY_STATUSES)
                ->map(fn ($t) => trim((string) $t))
                ->filter(fn ($t, $st) => $t !== '' && $t !== (CourierSetting::DEFAULT_TEMPLATES[$st] ?? null))
                ->all();

            $s->fill([
                'notify_sms' => (bool) ($data['notify_sms'] ?? $s->notify_sms),
                'notify_email' => (bool) ($data['notify_email'] ?? $s->notify_email),
                'notify_whatsapp' => (bool) ($data['notify_whatsapp'] ?? $s->notify_whatsapp),
                'notify_statuses' => array_key_exists('notify_statuses', $data)
                    ? array_values(array_intersect(CourierSetting::NOTIFY_STATUSES, $data['notify_statuses']))
                    : $s->notify_statuses,
                'notify_templates' => array_key_exists('notify_templates', $data) ? ($templates ?: null) : $s->notify_templates,
                'tracking_page' => (bool) ($data['tracking_page'] ?? $s->tracking_page ?? true),
                'risk_high_percent' => (int) ($data['risk_high_percent'] ?? $s->riskHighPercent()),
                'risk_min_shipments' => (int) ($data['risk_min_shipments'] ?? $s->riskMinShipments()),
            ]);
        }
        $s->save();

        return response()->json(['success' => true]);
    }

    /** Where customers track parcels, when the online store is on. */
    private function trackingPageUrl(): ?string
    {
        try {
            if (! DB::table('store_settings')->where('id', 1)->value('enabled')) {
                return null;
            }

            return store_base_url('track');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
