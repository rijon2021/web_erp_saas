<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Booking;
use App\Models\BookingStaff;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingDepositService;
use App\Services\Booking\BookingWorkflow;
use App\Services\Booking\BookingWorkflowException;
use App\Support\BookingTypes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management workflow (permission bookings → BookingPolicy@update).
 *
 * GET  bookings/requests            ?tab=website|request|all&search → {rows, counts}  the Requests inbox
 * GET  bookings/day                 ?date&warehouse_id → {date, staff: [{…, windows, closed, bookings}], unassigned, range}
 * GET  bookings/{id}/details        → {booking}  (quick view: everything the action buttons need)
 * POST bookings/{id}/confirm        {staff_id?, date?, time?, end_time?, force?}
 * POST bookings/{id}/decline        {reason?}
 * POST bookings/{id}/cancel         {reason?}
 * POST bookings/{id}/reschedule     {staff_id?, date, time, end_time?, force?}
 * POST bookings/{id}/arrive | no-show | complete | restore {force?}
 * GET  bookings/{id}/charge         → quote + payment methods / accounts
 * POST bookings/{id}/charge         {payments: [{payment_method_id, amount, account_id?}], discount?, sale_uuid?}
 *
 * A step that cannot go ahead answers 422 {message, reason, clashes?, hours?, can_force?}.
 */
class BookingWorkflowController extends BaseController
{
    public function __construct(private BookingWorkflow $workflow) {}

    // ------------------------------------------------------------------
    // Inbox, day view, details
    // ------------------------------------------------------------------

    public function requests(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        if (! Booking::workflowReady()) {
            return response()->json(['ready' => false, 'rows' => [], 'counts' => ['website' => 0, 'request' => 0, 'all' => 0]]);
        }
        $data = $request->validate([
            'tab' => 'nullable|in:website,request,all',
            'search' => 'nullable|string|max:120',
        ]);
        $tab = $data['tab'] ?? 'website';

        $base = $this->scopeToWarehouses(Booking::query()->where('bookings.status', 'pending'), 'bookings.warehouse_id', true);
        $counts = [
            'website' => (clone $base)->where('source', 'website')->count(),
            'request' => (clone $base)->where('booking_mode', 'request')->count(),
            'all' => (clone $base)->count(),
        ];
        $rows = (clone $base)
            ->when($tab === 'website', fn ($q) => $q->where('source', 'website'))
            ->when($tab === 'request', fn ($q) => $q->where('booking_mode', 'request'))
            ->when(! empty($data['search']), function ($q) use ($data) {
                $s = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($data['search'])).'%';
                $q->where(fn ($w) => $w->where('Ref', 'like', $s)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('email', 'like', $s)));
            })
            ->with(['customer', 'product', 'staff'])
            ->orderByRaw('hold_until IS NULL')->orderBy('hold_until')->orderBy('created_at')
            ->limit(200)->get();

        return response()->json([
            'ready' => true,
            'rows' => $rows->map(fn ($b) => $this->present($b))->values(),
            'counts' => $counts,
            'limit' => 200,
        ]);
    }

    public function day(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        if (! Booking::workflowReady()) {
            return response()->json(['ready' => false]);
        }
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'warehouse_id' => 'nullable|integer',
        ]);
        $date = $data['date'];
        $wh = $data['warehouse_id'] ?? null;
        if ($wh) {
            $this->abortIfWarehouseDenied((int) $wh);
        }

        $staff = $this->scopeToWarehouses(BookingStaff::query()->active(), 'warehouse_id', true)
            ->when($wh, fn ($q) => $q->where(fn ($w) => $w->where('warehouse_id', $wh)->orWhereNull('warehouse_id')))
            ->orderBy('sort_order')->orderBy('name')->get();
        $bookings = $this->scopeToWarehouses(Booking::query()->whereDate('booking_date', $date), 'bookings.warehouse_id', true)
            ->when($wh, fn ($q) => $q->where(fn ($w) => $w->where('warehouse_id', $wh)->orWhereNull('warehouse_id')))
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->with(['customer', 'product', 'staff'])
            ->orderBy('booking_time')->get();

        $svc = new AvailabilityService();
        $lo = 8 * 60;
        $hi = 20 * 60;
        $cols = [];
        foreach ($staff as $member) {
            $windows = $svc->workingIntervals($member, $date);
            $closed = null;
            if (! $windows) {
                $closed = $svc->check(null, $member->id, $date, '00:00', '00:05')['reason'];
                $closed = in_array($closed, ['day_off', 'holiday', 'leave', 'time_off'], true) ? $closed : 'day_off';
            }
            foreach ($windows as [$a, $b]) {
                $lo = min($lo, $a);
                $hi = max($hi, $b);
            }
            $cols[] = [
                'id' => $member->id, 'name' => $member->name, 'color' => $member->color, 'photo_url' => $member->photoUrl(),
                'windows' => array_map(fn ($w) => ['from' => AvailabilityService::hhmm($w[0]), 'to' => AvailabilityService::hhmm($w[1])], $windows),
                'closed' => $closed,
                'bookings' => [],
            ];
        }
        // Bookings of someone switched off or removed since keep their own column.
        $missing = $bookings->pluck('staff_id')->filter()->unique()->diff(array_column($cols, 'id'))->values();
        foreach (BookingStaff::withTrashed()->whereIn('id', $missing)->orderBy('name')->get() as $member) {
            $cols[] = [
                'id' => $member->id, 'name' => $member->name, 'color' => $member->color, 'photo_url' => $member->photoUrl(),
                'windows' => [], 'closed' => null, 'removed' => true, 'bookings' => [],
            ];
        }
        $byStaff = array_flip(array_column($cols, 'id'));
        $unassigned = [];
        foreach ($bookings as $b) {
            $row = $this->present($b);
            $s = AvailabilityService::minutes($row['time']) ?? 0;
            $e = AvailabilityService::minutes($row['end_time'] ?? '') ?? ($s + 60);
            $lo = min($lo, $s);
            $hi = max($hi, $e);
            if ($b->staff_id && isset($byStaff[$b->staff_id])) {
                $cols[$byStaff[$b->staff_id]]['bookings'][] = $row;
            } else {
                $unassigned[] = $row;
            }
        }

        return response()->json([
            'ready' => true,
            'date' => $date,
            'staff' => $cols,
            'unassigned' => $unassigned,
            'range' => ['from' => AvailabilityService::hhmm(intdiv($lo, 60) * 60), 'to' => AvailabilityService::hhmm(min(1440, (int) ceil($hi / 60) * 60))],
            'warehouses' => $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function details(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $b = $this->find($id);

        return response()->json([
            'booking' => $this->present($b->load(['customer', 'product', 'staff']), true),
            // Phase 6: emails / WhatsApp messages about this booking, newest first.
            'messages' => Booking::notificationsReady()
                ? \App\Models\BookingMessage::where('booking_id', $b->id)->orderByDesc('id')->limit(30)->get()
                    ->map(fn ($m) => ['kind' => $m->kind, 'channel' => $m->channel, 'recipient' => $m->recipient, 'to' => $m->to, 'status' => $m->status, 'error' => $m->error, 'at' => $m->created_at?->toIso8601String()])
                : [],
            'staff' => BookingStaff::tablesReady()
                ? (new AvailabilityService())->serviceStaff((int) $b->product_id)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])->values()
                : [],
            // Phase 8: online deposit attempts, newest first.
            'payments' => Booking::depositsReady()
                ? \App\Models\BookingPayment::where('booking_id', $b->id)->orderByDesc('id')->limit(20)->get()
                    ->map(fn ($p) => [
                        'id' => $p->id, 'gateway' => $p->gateway, 'gateway_label' => BookingDepositService::LABELS[$p->gateway] ?? $p->gateway,
                        'amount' => (float) $p->amount, 'currency' => $p->currency, 'status' => $p->status,
                        'reference' => $p->transaction_id ?: $p->reference, 'error' => $p->error, 'at' => $p->created_at?->toIso8601String(),
                    ])
                : [],
        ]);
    }

    // ------------------------------------------------------------------
    // Deposit (phase 8)
    // ------------------------------------------------------------------

    /** POST bookings/{id}/deposit/waive — no deposit needed for this booking. */
    public function depositWaive(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);

        return $this->runDeposit(fn () => app(BookingDepositService::class)->waive($this->find($id), $user));
    }

    /** POST bookings/{id}/deposit/refunded {note?} — the deposit went back to the customer. */
    public function depositRefunded(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        return $this->runDeposit(fn () => app(BookingDepositService::class)->markRefunded($this->find($id), $user, $data['note'] ?? null));
    }

    /** POST bookings/{id}/deposit/request {amount?} — ask the customer for a deposit (on their status page + email). */
    public function depositRequest(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $data = $request->validate(['amount' => 'nullable|numeric|min:0.01|max:99999999']);

        return $this->runDeposit(fn () => app(BookingDepositService::class)->request($this->find($id), $user, isset($data['amount']) ? (float) $data['amount'] : null));
    }

    private function runDeposit(callable $step)
    {
        if (! Booking::depositsReady()) {
            return response()->json(['message' => __('Run the latest database update first.')], 503);
        }

        return $this->run($step);
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    public function confirm(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);

        return $this->run(fn () => $this->workflow->confirm($this->find($id), $user, $this->when($request), $request->boolean('force')));
    }

    public function decline(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->run(fn () => $this->workflow->decline($this->find($id), $user, $data['reason'] ?? null));
    }

    public function cancel(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->run(fn () => $this->workflow->cancel($this->find($id), $user, $data['reason'] ?? null));
    }

    public function reschedule(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $request->validate(['date' => 'required|date_format:Y-m-d', 'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d/']]);

        return $this->run(fn () => $this->workflow->reschedule($this->find($id), $user, $this->when($request), $request->boolean('force')));
    }

    public function arrive(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);

        return $this->run(fn () => $this->workflow->arrive($this->find($id), $user));
    }

    public function noShow(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);

        return $this->run(fn () => $this->workflow->noShow($this->find($id), $user));
    }

    public function complete(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);

        return $this->run(fn () => $this->workflow->complete($this->find($id), $user));
    }

    /** POST bookings/{id}/propose {staff_id?, date, time, end_time?, note?, force?} — suggest another time. */
    public function propose(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d/'],
            'note' => 'nullable|string|max:500',
        ]);

        return $this->run(fn () => $this->workflow->propose($this->find($id), $user, $this->when($request), $request->input('note'), $request->boolean('force')));
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);

        return $this->run(fn () => $this->workflow->restore($this->find($id), $user, $request->boolean('force')));
    }

    public function chargeQuote(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $b = $this->find($id);
        $sys = Setting::whereNull('deleted_at')->first();

        return response()->json([
            'booking' => $this->present($b->load(['customer', 'product', 'staff'])),
            'quote' => $this->workflow->quote($b, (float) $request->query('discount', 0)),
            'payment_methods' => PaymentMethod::whereNull('deleted_at')->active()->where('id', '!=', 1)->orderBy('id')
                ->get(['id', 'name', 'is_cash'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'is_cash' => (bool) $m->is_cash])->values(),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->account_name])->values(),
            'require_account' => Schema::hasColumn('settings', 'require_payment_account') && (bool) ($sys->require_payment_account ?? false),
        ]);
    }

    public function charge(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Booking::class);
        $data = $request->validate([
            'payments' => 'required|array|min:1|max:5',
            'payments.*.payment_method_id' => 'required|integer',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.account_id' => 'nullable|integer',
            'discount' => 'nullable|numeric|min:0',
            'sale_uuid' => 'nullable|string|max:64',
        ]);

        try {
            $result = $this->workflow->charge($this->find($id), $user, $data['payments'], (float) ($data['discount'] ?? 0), $data['sale_uuid'] ?? null);
        } catch (BookingWorkflowException $e) {
            return response()->json($e->toArray(), $e->getCode() ?: 422);
        }

        return response()->json([
            'sale_id' => $result['sale_id'],
            'change' => $result['change'],
            'grand_total' => $result['grand_total'],
            'booking' => $this->present($result['booking']->load(['customer', 'product', 'staff'])),
        ]);
    }

    // ------------------------------------------------------------------

    private function run(callable $step)
    {
        if (! Booking::workflowReady()) {
            return response()->json(['message' => __('Run the latest database update first.')], 503);
        }
        try {
            $b = $step();
        } catch (BookingWorkflowException $e) {
            return response()->json($e->toArray(), $e->getCode() ?: 422);
        }

        return response()->json(['booking' => $this->present($b->load(['customer', 'product', 'staff']))]);
    }

    private function find($id): Booking
    {
        if (! Booking::workflowReady()) {
            abort(response()->json(['message' => __('Run the latest database update first.')], 503));
        }
        $b = Booking::whereNull('deleted_at')->findOrFail((int) $id);
        if ($b->warehouse_id) {
            $this->abortIfWarehouseDenied((int) $b->warehouse_id);
        }

        return $b;
    }

    /** {staff_id?, date?, time?, end_time?} from the request; keys only when sent. */
    private function when(Request $request): array
    {
        $request->validate([
            'staff_id' => 'nullable|integer',
            'date' => 'nullable|date_format:Y-m-d',
            'time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d/'],
            'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d/'],
        ]);
        $out = [];
        foreach (['staff_id', 'date', 'time', 'end_time'] as $k) {
            if ($request->exists($k)) {
                $out[$k] = $request->input($k) === '' ? null : $request->input($k);
            }
        }

        return $out;
    }

    /** Everything the booking pages show and act on. */
    private function present(Booking $b, bool $full = false): array
    {
        $date = Carbon::parse($b->booking_date)->toDateString();
        $time = substr((string) $b->booking_time, 0, 5);
        $end = $b->booking_end_time ? substr((string) $b->booking_end_time, 0, 5) : null;
        $fields = [];
        $defs = BookingTypes::allFields();
        foreach ((array) ($b->request_fields ?? []) as $key => $value) {
            // `_contact` etc. are the website's own records, not form answers.
            if ($value === null || $value === '' || $value === [] || str_starts_with((string) $key, '_')) {
                continue;
            }
            $f = $defs[$key] ?? ['id' => $key, 'label' => ucfirst(str_replace('_', ' ', (string) $key)), 'input' => 'text'];
            if ($f['input'] === 'file' && is_array($value) && ! empty($value['file']) && preg_match('/^[A-Za-z0-9]+\.[a-z0-9]{2,5}$/', (string) $value['file'])) {
                $value = ['name' => (string) ($value['name'] ?? $value['file']), 'url' => \App\Services\Booking\BookingWebsiteService::fileUrl($value['file'])];
            }
            $fields[] = ['id' => $f['id'], 'k' => \Illuminate\Support\Str::studly($f['id']), 'label' => $f['label'], 'input' => $f['input'], 'value' => $value];
        }
        $holdUntil = $b->hold_until ? Carbon::parse($b->hold_until) : null;
        $starts = Carbon::createFromFormat('Y-m-d H:i', $date.' '.($time ?: '00:00'), config('app.timezone'));

        $row = [
            'id' => $b->id,
            'Ref' => $b->Ref,
            'status' => $b->status,
            'source' => $b->source ?? 'staff',
            'booking_mode' => $b->booking_mode,
            'business_type' => $b->business_type,
            'customer' => $b->customer ? ['id' => $b->customer->id, 'name' => $b->customer->name, 'phone' => $b->customer->phone, 'email' => $b->customer->email] : null,
            'product' => $b->product ? ['id' => $b->product->id, 'name' => $b->product->name] : ($b->product_name ? ['id' => null, 'name' => $b->product_name] : null),
            'staff' => $b->staff ? ['id' => $b->staff->id, 'name' => $b->staff->name, 'color' => $b->staff->color, 'removed' => (bool) $b->staff->deleted_at] : null,
            'date' => $date,
            'time' => $time,
            'end_time' => $end,
            'duration_minutes' => $b->duration_minutes,
            'seats' => (int) ($b->seats ?: 1),
            'price' => $b->price !== null ? (float) $b->price : null,
            'preferred_date' => $b->preferred_date ? Carbon::parse($b->preferred_date)->toDateString() : null,
            'preferred_window' => $b->preferred_window,
            'request_fields' => $fields,
            'notes' => $b->notes,
            'hold_until' => $holdUntil?->toIso8601String(),
            'hold_expired' => $holdUntil ? $holdUntil->isPast() : false,
            'confirmed_at' => $b->confirmed_at?->toIso8601String(),
            'cancel_reason' => $b->cancel_reason,
            'cancelled_by' => $b->cancelled_by,
            'sale_id' => $b->sale_id,
            'warehouse_id' => $b->warehouse_id,
            // Phase 6: a time staff suggested, waiting for the customer.
            'proposal' => Booking::notificationsReady() && $b->hasProposal() ? [
                'date' => Carbon::parse($b->proposed_date)->toDateString(),
                'time' => $b->proposed_time,
                'end_time' => $b->proposed_end_time,
                'staff' => $b->proposed_staff_id ? BookingStaff::withTrashed()->whereKey($b->proposed_staff_id)->value('name') : null,
                'at' => $b->proposed_at?->toIso8601String(),
                'note' => $b->proposal_note,
            ] : null,
            // Phase 8: deposit and where the CRM put the booking.
            'deposit' => Booking::depositsReady() && $b->deposit_status ? [
                'status' => $b->deposit_status,
                'amount' => (float) $b->deposit_amount,
                'paid' => (float) $b->deposit_paid,
                'paid_at' => $b->deposit_paid_at?->toIso8601String(),
                'gateway' => $b->deposit_gateway,
                'gateway_label' => $b->deposit_gateway ? (BookingDepositService::LABELS[$b->deposit_gateway] ?? $b->deposit_gateway) : null,
                'reference' => $b->deposit_reference,
                'note' => $b->deposit_note,
            ] : null,
            'crm' => Booking::depositsReady() && ($b->crm_lead_id || $b->crm_deal_id) ? ['lead_id' => $b->crm_lead_id, 'deal_id' => $b->crm_deal_id] : null,
            'status_url' => ($b->source ?? '') === 'website' && $b->public_token ? store_base_url('appointment/'.$b->public_token) : null,
            'created_at' => $b->created_at ? Carbon::parse($b->created_at)->toIso8601String() : null,
            'started' => $starts->isPast(),
            'actions' => $this->actionsFor($b, $starts),
        ];
        if ($full && $b->staff_id && in_array($b->status, ['pending', 'confirmed'], true) && BookingStaff::tablesReady()) {
            $row['check'] = (new AvailabilityService())->check($b->product_id ? (int) $b->product_id : null, (int) $b->staff_id, $date, $time, $end, (int) ($b->seats ?: 1), (int) $b->id);
        }

        return $row;
    }

    /** Which buttons make sense right now (the API enforces the same rules). */
    private function actionsFor(Booking $b, Carbon $starts): array
    {
        $s = $b->status;
        $charged = (bool) $b->sale_id;

        return array_values(array_filter([
            $s === 'pending' ? 'confirm' : null,
            $s === 'pending' ? 'decline' : null,
            in_array($s, ['pending', 'confirmed'], true) ? 'reschedule' : null,
            $s === 'confirmed' ? 'arrive' : null,
            $s === 'confirmed' && $starts->isPast() ? 'no_show' : null,
            in_array($s, ['confirmed', 'arrived'], true) ? 'complete' : null,
            in_array($s, ['confirmed', 'arrived', 'completed'], true) && ! $charged && $b->product_id ? 'charge' : null,
            in_array($s, ['pending', 'confirmed', 'arrived'], true) ? 'cancel' : null,
            in_array($s, ['cancelled', 'declined', 'no_show'], true) ? 'restore' : null,
            $s === 'pending' && ($b->source ?? '') === 'website' && $b->public_token && Booking::notificationsReady() ? 'propose' : null,
            // Phase 8
            Booking::depositsReady() && $b->deposit_status === 'due' ? 'deposit_waive' : null,
            Booking::depositsReady() && $b->deposit_status === 'paid' ? 'deposit_refunded' : null,
            Booking::depositsReady() && in_array($b->deposit_status, [null, 'waived', 'refunded'], true) && in_array($s, ['pending', 'confirmed'], true)
                && ($b->source ?? '') === 'website' && $b->public_token ? 'deposit_request' : null,
        ]));
    }
}
