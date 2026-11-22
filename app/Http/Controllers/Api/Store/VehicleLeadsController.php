<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\BaseController;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Models\User;
use App\Models\VehicleLead;
use App\Models\VehicleSpec;
use App\Services\Crm\CrmCaptureService;
use App\Support\CarsTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Car Dealership theme leads: the storefront forms post here (enquiry, test
 * drive, trade-in, finance, reservation) and Store › Vehicle Leads manages
 * them.
 */
class VehicleLeadsController extends BaseController
{
    /** POST /online_store/vehicle-lead — public. */
    public function store(Request $request)
    {
        if (! VehicleSpec::tablesReady()) {
            return response()->json(['success' => false, 'message' => __('messages.CarLeadUnavailable')], 503);
        }
        // Honeypot: bots fill every field.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['success' => true, 'message' => __('messages.CarLeadSent')], 201);
        }

        $type = (string) $request->input('type');
        $data = $request->validate([
            'type' => ['required', Rule::in(VehicleLead::TYPES)],
            'product_id' => [Rule::requiredIf(in_array($type, ['test_drive', 'reservation'], true)), 'nullable', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => [Rule::requiredIf($type === 'reservation'), 'nullable', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'preferred_date' => [Rule::requiredIf($type === 'test_drive'), 'nullable', 'date', 'after_or_equal:today'],
            'preferred_time' => ['nullable', Rule::in(VehicleLead::TIMES)],
            'trade_plate' => [Rule::requiredIf($type === 'trade_in'), 'nullable', 'string', 'max:40'],
            'trade_mileage' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'trade_vehicle' => ['nullable', 'string', 'max:190'],
            'finance_deposit' => ['nullable', 'numeric', 'min:0'],
            'finance_term' => ['nullable', 'integer', Rule::in(CarsTheme::TERMS)],
            'wants_finance' => ['nullable', 'boolean'],
            'has_trade_in' => ['nullable', 'boolean'],
        ]);

        $s = StoreSetting::firstOrFail();
        $opts = CarsTheme::options($s);

        $product = null;
        if (! empty($data['product_id'])) {
            $product = Product::query()->whereNull('deleted_at')->where('is_active', 1)->where('hide_from_online_store', 0)->forStorefrontWarehouses()
                ->whereKey($data['product_id'])->first(['id', 'name']);
            if (! $product) {
                return response()->json(['success' => false, 'message' => __('messages.CarNotAvailable')], 422);
            }
        }
        if ($data['type'] === 'reservation') {
            $spec = VehicleSpec::where('product_id', $product->id)->first();
            if (! $opts['enable_reserve'] || ! $opts['reserve_product_id'] || ($spec && (! $spec->reservable || $spec->is_sold))) {
                return response()->json(['success' => false, 'message' => __('messages.CarReserveUnavailable')], 422);
            }
        }

        $wantsFinance = (bool) ($data['wants_finance'] ?? false) || $data['type'] === 'finance';
        $user = Auth::guard('store')->user();
        $lead = VehicleLead::create([
            'type' => $data['type'],
            'product_id' => $product->id ?? null,
            'product_name' => $product->name ?? null,
            'client_id' => $user->client_id ?? null,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'message' => $data['message'] ?? null,
            'preferred_date' => $data['preferred_date'] ?? null,
            'preferred_time' => $data['preferred_time'] ?? null,
            'trade_plate' => $data['trade_plate'] ?? null,
            'trade_mileage' => $data['trade_mileage'] ?? null,
            'trade_vehicle' => $data['trade_vehicle'] ?? null,
            'finance_deposit' => $wantsFinance ? ($data['finance_deposit'] ?? null) : null,
            'finance_term' => $wantsFinance ? ($data['finance_term'] ?? null) : null,
            'wants_finance' => $wantsFinance,
            'has_trade_in' => (bool) ($data['has_trade_in'] ?? false) || $data['type'] === 'trade_in',
            'status' => 'new',
        ]);

        // The owner email (SMTP) and the CRM capture run after the response, so
        // a slow or unreachable mail server never keeps the visitor waiting.
        // Content-Length lets the browser finish reading the response even
        // where PHP runs as an Apache module and the connection stays open;
        // Connection: close stops it reusing that busy connection for the
        // next page.
        $tenant = function_exists('tenant') ? tenant() : null;
        $after = function () use ($lead, $opts) {
            if ($opts['notify_owner']) {
                $this->notifyOwner($lead);
            }
            if ($opts['crm_capture']) {
                $this->pushToCrm($lead);
            }
        };
        app()->terminating(fn () => $tenant ? $tenant->run($after) : $after());

        $response = response()->json([
            'success' => true,
            'id' => $lead->id,
            'message' => __($data['type'] === 'reservation' ? 'messages.CarReserveSent' : 'messages.CarLeadSent'),
        ], 201);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));
        $response->headers->set('Connection', 'close');

        return $response;
    }

    /** GET /store/vehicle-leads — admin list. */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'store_vehicle_leads', StoreSetting::class);

        if (! VehicleSpec::tablesReady()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'pages' => 1], 'counts' => ['types' => [], 'statuses' => []], 'users' => [], 'ready' => false]);
        }

        $query = VehicleLead::with(['product:id,name,code', 'assignee:id,username,firstname,lastname'])->orderByDesc('created_at');
        if ($request->filled('type') && in_array($request->type, VehicleLead::TYPES, true)) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status') && in_array($request->status, VehicleLead::STATUSES, true)) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term)->orWhere('product_name', 'like', $term)->orWhere('trade_plate', 'like', $term));
        }

        $rows = $query->paginate(max(1, min(100, (int) $request->input('per_page', 15))));

        $data = $rows->getCollection()->map(fn (VehicleLead $l) => [
            'id' => $l->id,
            'type' => $l->type,
            'status' => $l->status,
            'product_id' => $l->product_id,
            'product_name' => $l->product_name ?: optional($l->product)->name,
            'name' => $l->name,
            'email' => $l->email,
            'phone' => $l->phone,
            'message' => $l->message,
            'preferred_date' => optional($l->preferred_date)->toDateString(),
            'preferred_time' => $l->preferred_time,
            'trade_plate' => $l->trade_plate,
            'trade_mileage' => $l->trade_mileage,
            'trade_vehicle' => $l->trade_vehicle,
            'finance_deposit' => $l->finance_deposit !== null ? (float) $l->finance_deposit : null,
            'finance_term' => $l->finance_term,
            'wants_finance' => $l->wants_finance,
            'has_trade_in' => $l->has_trade_in,
            'assigned_to' => $l->assigned_to,
            'assignee' => $l->assignee ? (trim($l->assignee->firstname.' '.$l->assignee->lastname) ?: $l->assignee->username) : null,
            'staff_note' => $l->staff_note,
            'created_at' => optional($l->created_at)->toDateTimeString(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
            'counts' => [
                'types' => VehicleLead::selectRaw('type, COUNT(*) AS c')->groupBy('type')->pluck('c', 'type'),
                'statuses' => VehicleLead::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status'),
            ],
            'users' => User::where('statut', 1)->orderBy('username')->get(['id', 'username', 'firstname', 'lastname'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username]),
            'crm' => CrmCaptureService::enabled(),
            'ready' => true,
        ]);
    }

    /** POST /store/vehicle-leads/{id} — status, owner, staff note. */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'store_vehicle_leads', StoreSetting::class);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(VehicleLead::STATUSES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'staff_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $lead = VehicleLead::findOrFail($id);
        $lead->fill($data)->save();

        return response()->json(['success' => true]);
    }

    /** POST /store/vehicle-leads/{id}/crm — send (again) to the CRM. */
    public function toCrm(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'store_vehicle_leads', StoreSetting::class);

        if (! CrmCaptureService::enabled()) {
            return response()->json(['success' => false, 'message' => 'CRM is not enabled.'], 422);
        }
        $result = $this->pushToCrm(VehicleLead::findOrFail($id));

        return response()->json(['success' => (bool) $result, 'result' => $result]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'store_vehicle_leads', StoreSetting::class);

        VehicleLead::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /** CRM: a lead (or a note on the existing lead / customer). Never breaks the visitor's request. */
    private function pushToCrm(VehicleLead $lead): ?array
    {
        try {
            if (! CrmCaptureService::enabled()) {
                return null;
            }

            return app(CrmCaptureService::class)->capture('vehicle_lead', [
                'name' => $lead->name, 'email' => $lead->email, 'phone' => $lead->phone,
                'subject' => trim(__('messages.CarLeadType_'.$lead->type).($lead->product_name ? ' — '.$lead->product_name : '')),
                'message' => $lead->message,
                'extra' => array_filter($this->detailLines($lead), fn ($v) => $v !== null && $v !== ''),
            ], ['ref_id' => $lead->id, 'client_id' => $lead->client_id]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** label => value lines shared by the owner email and the CRM note. */
    private function detailLines(VehicleLead $lead): array
    {
        return [
            'Vehicle' => $lead->product_name,
            'Preferred date' => $lead->preferred_date ? $lead->preferred_date->toDateString().($lead->preferred_time ? ' ('.$lead->preferred_time.')' : '') : null,
            'Trade-in plate' => $lead->trade_plate,
            'Trade-in mileage' => $lead->trade_mileage !== null ? number_format($lead->trade_mileage) : null,
            'Trade-in vehicle' => $lead->trade_vehicle,
            'Finance deposit' => $lead->finance_deposit !== null ? number_format((float) $lead->finance_deposit, 2) : null,
            'Finance term' => $lead->finance_term ? $lead->finance_term.' months' : null,
            'Wants finance' => $lead->wants_finance ? 'Yes' : null,
            'Has a trade-in' => $lead->has_trade_in ? 'Yes' : null,
        ];
    }

    /** Best-effort email to the store owner. */
    private function notifyOwner(VehicleLead $lead): void
    {
        try {
            $this->Set_config_mail();
            $ownerEmail = StoreSetting::query()->value('contact_email')
                ?: optional(Setting::whereNull('deleted_at')->first())->email;
            if (! $ownerEmail) {
                return;
            }
            $lines = ['Type: '.__('messages.CarLeadType_'.$lead->type), 'Name: '.$lead->name, 'Phone: '.($lead->phone ?: '—'), 'Email: '.($lead->email ?: '—')];
            foreach ($this->detailLines($lead) as $label => $value) {
                if ($value !== null && $value !== '') {
                    $lines[] = $label.': '.$value;
                }
            }
            $lines[] = 'Message: '.($lead->message ?: '—');
            Mail::raw(implode("\n", $lines), function ($m) use ($ownerEmail, $lead) {
                $m->to($ownerEmail)->subject('New '.strtolower(__('messages.CarLeadType_'.$lead->type)).($lead->product_name ? ' — '.$lead->product_name : ''));
            });
        } catch (\Throwable $e) {
            Log::warning('Vehicle lead owner email failed: '.$e->getMessage());
        }
    }
}
