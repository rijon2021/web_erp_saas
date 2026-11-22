<?php

namespace App\Http\Controllers;

use App\Models\RentalContract;
use App\Models\RentalInvoice;
use App\Models\RentalPayment;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Models\RentalMaintenanceSchedule;
use App\Models\RentalSetting;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalContractService;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rental dashboard + the module's settings page.
 *
 * The dashboard is one GET feeding every panel so the tiles always agree with
 * each other. Later phases (contracts, payments, maintenance) add their own
 * figures to the same payload.
 */
class RentalDashboardController extends Controller
{
    use ScopesWarehouseAccess;

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'dashboard', RentalItem::class);
        app(RentalContractService::class)->sync();
        app(RentalBillingService::class)->run();

        // Every figure is counted over the items this user may see.
        $visible = $this->scopeToWarehouses(
            RentalItem::whereNull('deleted_at'), 'warehouse_id', true
        )->pluck('id')->all();

        $base = fn () => RentalItem::whereNull('deleted_at')->whereIn('id', $visible);

        $byStatus = $base()->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status')->toArray();

        $byType = $base()->select('type', DB::raw('count(*) as aggregate'))
            ->groupBy('type')->pluck('aggregate', 'type')->toArray();

        $total = count($visible);
        $inactive = (int) ($byStatus['inactive'] ?? 0);
        $rented = (int) ($byStatus['rented'] ?? 0);
        $rentable = max(0, $total - $inactive);

        return response()->json([
            'contracts' => $this->contractFigures(),
            'billing' => $request->user('api')->can('view', \App\Models\RentalInvoice::class) ? $this->billingFigures() : null,
            'maintenance_jobs' => [
                'open' => RentalMaintenance::whereNull('deleted_at')->whereIn('rental_item_id', $visible)->whereIn('status', RentalMaintenance::OPEN)->count(),
                'urgent' => RentalMaintenance::whereNull('deleted_at')->whereIn('rental_item_id', $visible)->whereIn('status', RentalMaintenance::OPEN)->where('priority', 'urgent')->count(),
                'due_soon' => RentalMaintenanceSchedule::whereNull('deleted_at')->where('active', true)
                    ->whereNotNull('next_due_date')->where('next_due_date', '<=', Carbon::today()->addDays(14))->count(),
            ],
            'total' => $total,
            'by_status' => $byStatus,
            'by_type' => collect($byType)->map(fn ($count, $type) => ['type' => $type, 'count' => (int) $count])->values(),
            'available' => (int) ($byStatus['available'] ?? 0),
            'reserved' => (int) ($byStatus['reserved'] ?? 0),
            'rented' => $rented,
            'maintenance' => (int) ($byStatus['maintenance'] ?? 0),
            // Share of rentable (non-inactive) items currently out.
            'occupancy' => $rentable > 0 ? round(($rented / $rentable) * 100, 1) : 0,
            'portfolio_value' => (float) $base()->sum('purchase_cost'),
            // What the register would earn in a month at its monthly prices.
            'monthly_potential' => (float) $base()->where('status', '!=', 'inactive')
                ->whereNotNull('monthly_rate')->sum(DB::raw('monthly_rate * quantity')),
            'recent' => $base()->with('category')->orderBy('created_at', 'desc')->limit(6)->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'type' => $item->type,
                    'status' => $item->status,
                    'category_name' => optional($item->category)->name,
                    'default_period' => $item->default_period,
                    'headline_rate' => $item->rateFor($item->default_period),
                    'image_url' => $item->coverUrl(),
                ])->values(),
            'in_maintenance' => $base()->where('status', 'maintenance')->orderBy('updated_at', 'desc')->limit(8)
                ->get(['id', 'code', 'name', 'type', 'condition', 'updated_at'])
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'type' => $item->type,
                    'condition' => $item->condition,
                    'since' => optional($item->updated_at)->toDateString(),
                ])->values(),
        ]);
    }

    /**
     * Contract tiles + the "ending soon" list. Recurring rent is normalised to
     * a month so hourly, weekly and yearly contracts add up sensibly.
     */
    private function contractFigures(): array
    {
        $now = Carbon::now();
        $soonDays = (int) RentalSetting::current()->expiring_soon_days;
        $soon = $now->copy()->addDays($soonDays);
        $active = fn () => RentalContract::whereNull('deleted_at')->where('status', 'active');

        $perMonth = ['hour' => 730, 'day' => 30.4375, 'week' => 4.348, 'month' => 1, 'year' => 1 / 12];
        $monthly = $active()->where('billing_type', 'recurring')->where('start_date', '<=', $now)
            ->get(['rate_period', 'total_amount'])
            ->sum(fn ($c) => (float) $c->total_amount * ($perMonth[$c->rate_period] ?? 1));

        return [
            'active' => $active()->count(),
            'running' => $active()->where('start_date', '<=', $now)->count(),
            'upcoming' => $active()->where('start_date', '>', $now)->count(),
            'drafts' => RentalContract::whereNull('deleted_at')->where('status', 'draft')->count(),
            'expiring' => $active()->where('start_date', '<=', $now)->whereBetween('end_date', [$now, $soon])->count(),
            'overdue' => $active()->where('end_date', '<', $now)->count(),
            'monthly_recurring' => round($monthly, 2),
            'ending_soon' => $active()->with('client')->whereNotNull('end_date')
                ->where('end_date', '<=', $soon)->orderBy('end_date')->limit(8)->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'contract_number' => $c->contract_number,
                    'customer_name' => optional($c->client)->name,
                    'end_date' => $c->end_date->toIso8601String(),
                    'days_left' => (int) floor($now->diffInDays($c->end_date, false)),
                    'renewed' => (bool) $c->renewal_id,
                ])->values(),
        ];
    }

    /** Money owed and collected, plus the 6-month collection trend. */
    private function billingFigures(): array
    {
        $today = Carbon::today();
        $open = RentalInvoice::with('contract')->whereIn('status', ['unpaid', 'partial'])->get();
        $balance = fn ($i) => $i->balance();
        $overdue = $open->filter(fn ($i) => $i->isOverdue((int) optional($i->contract)->grace_days));

        $trend = [];
        for ($m = 5; $m >= 0; $m--) {
            $start = $today->copy()->startOfMonth()->subMonths($m);
            $end = $start->copy()->endOfMonth();
            $trend[] = [
                'month' => $start->format('M Y'),
                'collected' => round((float) RentalPayment::whereNull('deleted_at')->whereBetween('payment_date', [$start, $end])->sum('amount'), 2),
                'billed' => round((float) RentalInvoice::where('status', '!=', 'void')->whereBetween('issue_date', [$start, $end])->sum('amount'), 2),
            ];
        }

        return [
            'outstanding' => round($open->sum($balance), 2),
            'overdue' => round($overdue->sum($balance), 2),
            'overdue_count' => $overdue->count(),
            'due_week' => round($open->filter(fn ($i) => $i->due_date->between($today, $today->copy()->addDays(7)))->sum($balance), 2),
            'collected_month' => round((float) RentalPayment::whereNull('deleted_at')->whereDate('payment_date', '>=', $today->copy()->startOfMonth())->sum('amount'), 2),
            'deposits_held' => round((float) \App\Models\RentalDepositTransaction::whereNull('deleted_at')
                ->selectRaw("SUM(CASE WHEN type = 'collected' THEN amount ELSE -amount END) as held")->value('held'), 2),
            'trend' => $trend,
            'top_overdue' => $overdue->sortByDesc($balance)->take(6)->map(fn ($i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'customer_name' => optional($i->client)->name,
                'contract_number' => optional($i->contract)->contract_number,
                'balance' => $i->balance(),
                'due_date' => $i->due_date->toDateString(),
            ])->values(),
        ];
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', RentalItem::class);

        return response()->json(['settings' => $this->presentSettings(RentalSetting::current())]);
    }

    public function updateSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', RentalItem::class);

        $data = $request->validate([
            'item_code_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_]+$/'],
            'contract_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_]+$/'],
            'reservation_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_]+$/'],
            'invoice_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_]+$/'],
            'grace_days' => 'required|integer|min:0|max:365',
            'late_fee_type' => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'late_fee_value' => 'nullable|numeric|min:0',
            'late_fee_frequency' => ['required', Rule::in(['once', 'daily'])],
            'default_deposit' => 'nullable|numeric|min:0',
            'buffer_hours' => 'required|integer|min:0|max:720',
            'default_hold_hours' => 'nullable|integer|min:1|max:2160',
            'expiring_soon_days' => 'required|integer|min:1|max:365',
            'reminder_days_before' => 'required|integer|min:0|max:90',
            'invoice_lead_days' => 'nullable|integer|min:0|max:90',
            'checkout_checklist' => 'nullable|array|max:30',
            'checkout_checklist.*' => 'string|max:191',
            'checkin_checklist' => 'nullable|array|max:30',
            'checkin_checklist.*' => 'string|max:191',
            'reminders_enabled' => 'nullable|boolean',
            'reminder_channels' => 'nullable|array',
            'reminder_channels.*' => 'in:email,sms,whatsapp',
            'overdue_reminder_every_days' => 'nullable|integer|min:1|max:90',
            'expiry_reminder_days' => 'nullable|integer|min:1|max:365',
            'reminder_templates' => 'nullable|array',
            'reminder_templates.*.subject' => 'nullable|string|max:191',
            'reminder_templates.*.body' => 'nullable|string|max:2000',
            'agreement_terms' => 'nullable|string|max:20000',
            // Business type + website booking (sent only once the migration ran).
            'business_types' => 'sometimes|array|max:4',
            'business_types.*' => ['string', Rule::in(\App\Support\RentalTypes::TYPES)],
            'website_booking' => 'sometimes|boolean',
            'website_hold_hours' => 'sometimes|integer|min:1|max:720',
            'website_min_notice_hours' => 'sometimes|integer|min:0|max:720',
            'website_max_days_ahead' => 'sometimes|integer|min:1|max:730',
            'website_require_login' => 'sometimes|boolean',
            'website_fields' => 'sometimes|nullable|array',
            'pickup_locations' => 'sometimes|nullable|array|max:30',
            'pickup_locations.*' => 'nullable|string|max:120',
            'website_terms' => 'sometimes|nullable|string|max:20000',
        ], [
            'regex' => 'Use letters, digits and underscores only.',
        ]);

        if ($data['late_fee_type'] === 'percent' && (float) ($data['late_fee_value'] ?? 0) > 100) {
            return response()->json([
                'message' => 'A percentage late fee cannot exceed 100.',
                'errors' => ['late_fee_value' => ['A percentage late fee cannot exceed 100.']],
            ], 422);
        }

        $data['late_fee_value'] = $data['late_fee_type'] === 'none' ? 0 : ($data['late_fee_value'] ?? 0);
        $data['default_deposit'] = $data['default_deposit'] ?? 0;
        $data['default_hold_hours'] = $data['default_hold_hours'] ?? 48;
        $data['invoice_lead_days'] = $data['invoice_lead_days'] ?? 7;
        $data['reminders_enabled'] = (bool) ($data['reminders_enabled'] ?? false);
        $data['reminder_channels'] = array_values(array_unique($data['reminder_channels'] ?? []));
        $data['overdue_reminder_every_days'] = $data['overdue_reminder_every_days'] ?? 3;
        $data['expiry_reminder_days'] = $data['expiry_reminder_days'] ?? 14;
        if (isset($data['reminder_templates'])) {
            $data['reminder_templates'] = collect($data['reminder_templates'])
                ->only(\App\Models\RentalReminder::TYPES)
                ->map(fn ($t) => ['subject' => trim((string) ($t['subject'] ?? '')), 'body' => trim((string) ($t['body'] ?? ''))])
                ->all();
        }
        foreach (['checkout_checklist', 'checkin_checklist'] as $key) {
            $data[$key] = array_values(array_filter(array_map('trim', $data[$key] ?? []), 'strlen')) ?: null;
        }
        foreach (['item_code_prefix', 'contract_prefix', 'reservation_prefix', 'invoice_prefix'] as $key) {
            $data[$key] = strtoupper($data[$key]);
        }

        // Business type + website: dropped until migration 2026_10_25_000001 adds the columns.
        $websiteKeys = ['business_types', 'website_booking', 'website_hold_hours', 'website_min_notice_hours', 'website_max_days_ahead', 'website_require_login', 'website_fields', 'pickup_locations', 'website_terms'];
        if (! RentalSetting::websiteReady()) {
            $data = array_diff_key($data, array_flip($websiteKeys));
        } else {
            if (array_key_exists('business_types', $data)) {
                // Nothing ticked = all types (the module's behaviour before the setting).
                $data['business_types'] = \App\Support\RentalTypes::normalize($data['business_types']) ?: null;
            }
            if (array_key_exists('website_fields', $data)) {
                $data['website_fields'] = \App\Support\RentalTypes::cleanFieldModes($data['website_fields']) ?: null;
            }
            if (array_key_exists('pickup_locations', $data)) {
                $data['pickup_locations'] = \App\Support\RentalTypes::cleanLocations($data['pickup_locations'] ?? []) ?: null;
            }
            foreach (['website_booking', 'website_require_login'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $data[$flag] = (bool) $data[$flag];
                }
            }
        }

        $setting = RentalSetting::current();
        $setting->update($data);

        return response()->json(['success' => true, 'settings' => $this->presentSettings($setting->fresh())]);
    }

    /**
     * GET rental/types — the business-type registry for the admin pages
     * (item form fields, category and filter lists). Any user of the module
     * may read it; it holds no tenant data beyond the chosen types.
     */
    public function types(Request $request)
    {
        abort_unless($request->user('api'), 401);

        return response()->json(\App\Support\RentalTypes::forAdmin(RentalSetting::current()) + [
            'website_ready' => RentalSetting::websiteReady(),
        ]);
    }

    private function presentSettings(RentalSetting $s): array
    {
        return $this->presentBaseSettings($s) + $this->presentWebsiteSettings($s);
    }

    /** Business type + website booking part of the settings payload. */
    private function presentWebsiteSettings(RentalSetting $s): array
    {
        $ready = RentalSetting::websiteReady();
        $store = \App\Models\StoreSetting::query()->first();

        return [
            'website_ready' => $ready,
            'business_types' => $ready ? \App\Support\RentalTypes::normalize($s->business_types) : [],
            'website_booking' => $ready ? (bool) $s->website_booking : true,
            'website_hold_hours' => $ready ? (int) ($s->website_hold_hours ?: 24) : 24,
            'website_min_notice_hours' => $ready ? (int) ($s->website_min_notice_hours ?? 2) : 2,
            'website_max_days_ahead' => $ready ? (int) ($s->website_max_days_ahead ?: 180) : 180,
            'website_require_login' => $ready ? (bool) $s->website_require_login : false,
            'website_fields' => \App\Support\RentalTypes::fieldModes($ready ? $s : new RentalSetting()),
            'pickup_locations' => $ready ? \App\Support\RentalTypes::pickupLocations($s) : [],
            'website_terms' => $ready ? $s->website_terms : null,
            // So the page can say whether the Rental store theme is live.
            'store_theme' => $store->theme ?? null,
            'store_enabled' => (bool) ($store->enabled ?? false),
        ];
    }

    private function presentBaseSettings(RentalSetting $s): array
    {
        return [
            'item_code_prefix' => $s->item_code_prefix,
            'contract_prefix' => $s->contract_prefix,
            'reservation_prefix' => $s->reservation_prefix,
            'invoice_prefix' => $s->invoice_prefix,
            'grace_days' => (int) $s->grace_days,
            'late_fee_type' => $s->late_fee_type,
            'late_fee_value' => (float) $s->late_fee_value,
            'late_fee_frequency' => $s->late_fee_frequency,
            'default_deposit' => (float) $s->default_deposit,
            'buffer_hours' => (int) $s->buffer_hours,
            'default_hold_hours' => (int) ($s->default_hold_hours ?: 48),
            'expiring_soon_days' => (int) $s->expiring_soon_days,
            'reminder_days_before' => (int) $s->reminder_days_before,
            'invoice_lead_days' => (int) ($s->invoice_lead_days ?? 7),
            'checkout_checklist' => $s->checklistFor('checkout'),
            'checkin_checklist' => $s->checklistFor('checkin'),
            'reminders_enabled' => (bool) $s->reminders_enabled,
            'reminder_channels' => array_values((array) ($s->reminder_channels ?? [])),
            'overdue_reminder_every_days' => (int) ($s->overdue_reminder_every_days ?? 3),
            'expiry_reminder_days' => (int) ($s->expiry_reminder_days ?? 14),
            'reminder_templates' => collect(\App\Models\RentalReminder::TYPES)->mapWithKeys(fn ($t) => [$t => $s->reminderTemplate($t)])->all(),
            'whatsapp_active' => \App\Services\WhatsApp\WhatsAppGateway::isActive(),
            'agreement_terms' => $s->agreement_terms,
        ];
    }
}
