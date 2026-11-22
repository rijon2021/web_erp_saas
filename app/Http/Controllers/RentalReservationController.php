<?php

namespace App\Http\Controllers;

use App\Models\RentalContract;
use App\Models\RentalContractItem;
use App\Models\RentalCustomer;
use App\Models\RentalItem;
use App\Models\RentalReservation;
use App\Models\RentalReservationItem;
use App\Models\RentalSetting;
use App\Services\Rental\RentalContractService;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Reservations — temporary holds on rental items. A live hold blocks the
 * items like an active contract does (see RentalContractService::conflicts),
 * so every save is checked for double booking. A hold ends by conversion to a
 * contract, cancellation, or running out (sync() marks it expired).
 */
class RentalReservationController extends Controller
{
    use ScopesWarehouseAccess;

    private const SORTABLE = [
        'reservation_number' => 'rental_reservations.reservation_number',
        'start_date' => 'rental_reservations.start_date',
        'hold_until' => 'rental_reservations.hold_until',
        'total_amount' => 'rental_reservations.total_amount',
        'customer_name' => 'clients.name',
        'created_at' => 'rental_reservations.created_at',
    ];

    public function __construct(private RentalContractService $service)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalReservation::class);
        $this->service->sync();

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = self::SORTABLE[$request->SortField] ?? 'rental_reservations.created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = $this->baseQuery();
        $summary = [];
        $stages = ['all', 'held', 'expiring', 'converted', 'expired', 'cancelled'];
        if (RentalSetting::websiteReady()) {
            $stages[] = 'website';
        }
        foreach ($stages as $stage) {
            $q = clone $query;
            $this->applyStage($q, $stage);
            $summary[$stage] = $q->count();
        }

        $this->applyStage($query, (string) $request->get('stage', 'all'));
        if ($request->filled('rental_customer_id')) {
            $query->where('rental_reservations.rental_customer_id', $request->rental_customer_id);
        }
        if ($request->filled('source') && in_array($request->source, RentalReservation::SOURCES, true) && RentalSetting::websiteReady()) {
            $query->where('rental_reservations.source', $request->source);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rental_reservations.reservation_number', 'LIKE', "%{$search}%")
                    ->orWhere('clients.name', 'LIKE', "%{$search}%")
                    ->orWhere('clients.phone', 'LIKE', "%{$search}%")
                    ->orWhereExists(fn ($s) => $s->selectRaw(1)->from('rental_reservation_items')
                        ->whereColumn('rental_reservation_items.rental_reservation_id', 'rental_reservations.id')
                        ->where('rental_reservation_items.item_name', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $rows = $query->with('items', 'contract')->orderBy($order, $dir)
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'reservations' => $rows->map(fn ($r) => $this->present($r))->values(),
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalReservation::class);

        [$data, $lines] = $this->validated($request);

        $reservation = $this->service->transact(function () use ($data, $lines, $request) {
            $this->assertAvailable($lines, $data['start_date'], $data['end_date']);
            $r = RentalReservation::create(array_merge($data, [
                'reservation_number' => $this->service->nextReservationNumber(),
                'status' => 'held',
                'created_by' => optional($request->user('api'))->id,
            ]));
            $this->saveLines($r, $lines);

            return $r;
        }, array_column($lines, 'rental_item_id'));

        return response()->json(['success' => true, 'id' => $reservation->id]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalReservation::class);
        $this->service->sync();

        $r = $this->findReservation($id, ['items.item', 'client', 'customer', 'contract']);

        return response()->json(['reservation' => array_merge($this->present($r), [
            'warehouse_id' => $r->warehouse_id,
            'customer' => [
                'id' => $r->rental_customer_id,
                'name' => optional($r->client)->name,
                'phone' => optional($r->client)->phone,
                'email' => optional($r->client)->email,
                'id_status' => $r->customer ? $r->customer->idStatus() : null,
                'is_blacklisted' => (bool) optional($r->customer)->is_blacklisted,
            ],
            'lines' => $r->items->map(fn ($l) => [
                'id' => $l->id,
                'rental_item_id' => $l->rental_item_id,
                'item_name' => $l->item_name,
                'item_code' => $l->item_code,
                'item_type' => optional($l->item)->type,
                'item_status' => optional($l->item)->status,
                'image_url' => $l->item ? $l->item->coverUrl() : null,
                'quantity' => (int) $l->quantity,
                'rate' => (float) $l->rate,
                'amount' => (float) $l->amount,
            ])->values(),
            'discount' => (float) $r->discount,
            'tax_rate' => (float) $r->tax_rate,
            'tax_amount' => (float) $r->tax_amount,
            'notes' => $r->notes,
            'cancel_reason' => $r->cancel_reason,
            'cancelled_at' => optional($r->cancelled_at)->toIso8601String(),
            'converted_at' => optional($r->converted_at)->toIso8601String(),
            // Website booking: what the customer typed on the form.
            'request_fields' => RentalSetting::websiteReady() ? ($r->request_fields ?: null) : null,
            'status_url' => $r->public_token ? store_base_url('rentals/booking/' . $r->public_token) : null,
        ])]);
    }

    /**
     * Accept a website booking request: the hold is stretched to the end of
     * the rental (so the items stay blocked until then, or until it is
     * converted) and the customer is emailed.
     */
    public function confirm(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalReservation::class);
        if (! RentalSetting::websiteReady()) {
            return $this->refuse('Run the latest update first.');
        }

        $r = $this->findReservation($id, ['items']);
        if ($r->status !== 'held' || ! $r->isLive()) {
            return $this->refuse('Only a live hold can be confirmed. Extend the hold first if it ran out.');
        }
        if ($r->confirmed_at) {
            return $this->refuse('This booking is already confirmed.');
        }

        $this->service->transact(function () use ($r) {
            $fresh = RentalReservation::whereKey($r->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'held' || $fresh->confirmed_at) {
                abort($this->refuse('This booking was already confirmed or changed.'));
            }
            $until = $fresh->end_date && $fresh->end_date->gt($fresh->hold_until) ? $fresh->end_date : $fresh->hold_until;
            $fresh->update(['confirmed_at' => now(), 'hold_until' => $until]);
        }, $r->items->pluck('rental_item_id')->all());

        if ($r->source === 'website') {
            \App\Services\Rental\RentalWebsiteMailer::later('confirmed', $r);
        }

        return response()->json(['success' => true]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalReservation::class);

        $r = $this->findReservation($id);
        if ($r->status !== 'held') {
            return $this->refuse('Only a held reservation can be edited.');
        }
        if ($this->lockedByDraft($r)) {
            return $this->refuse('A draft contract was created from this reservation. Edit the draft contract instead.');
        }

        [$data, $lines] = $this->validated($request, $r);

        $before = $r->items()->pluck('rental_item_id')->all();
        $this->service->transact(function () use ($r, $data, $lines) {
            $this->assertAvailable($lines, $data['start_date'], $data['end_date'], $r->id);
            $r->update($data);
            $r->items()->delete();
            $this->saveLines($r, $lines);
        }, array_merge($before, array_column($lines, 'rental_item_id')));

        return response()->json(['success' => true, 'id' => $r->id]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalReservation::class);

        $r = $this->findReservation($id);
        if ($r->status === 'converted') {
            return $this->refuse('A converted reservation is part of its contract\'s history and cannot be deleted.');
        }
        if ($this->lockedByDraft($r)) {
            return $this->refuse('A draft contract was created from this reservation. Delete or cancel the draft contract first.');
        }
        $items = $r->items()->pluck('rental_item_id')->all();
        $this->service->transact(fn () => $r->delete(), $items);

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalReservation::class);

        $r = $this->findReservation($id);
        if ($r->status !== 'held') {
            return $this->refuse('Only a held reservation can be cancelled.');
        }
        if ($this->lockedByDraft($r)) {
            return $this->refuse('A draft contract was created from this reservation. Delete or cancel the draft contract first.');
        }
        $request->validate(['reason' => 'nullable|string|max:2000']);

        $this->service->transact(function () use ($r, $request) {
            $r->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $request->reason]);
        }, $r->items()->pluck('rental_item_id')->all());

        if (RentalSetting::websiteReady() && $r->source === 'website' && $request->boolean('notify_customer', true)) {
            \App\Services\Rental\RentalWebsiteMailer::later('cancelled', $r);
        }

        return response()->json(['success' => true]);
    }

    /** Keep the hold longer — or revive one that just expired, if the items are still free. */
    public function extendHold(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalReservation::class);

        $r = $this->findReservation($id, ['items']);
        if (! in_array($r->status, ['held', 'expired'], true)) {
            return $this->refuse('Only held or expired reservations can be extended.');
        }
        $request->validate(['hold_until' => 'required|date|after:now']);

        $this->service->transact(function () use ($r, $request) {
            if (! $r->isLive()) {
                $lines = $r->items->map(fn ($l) => ['rental_item_id' => $l->rental_item_id, 'quantity' => $l->quantity])->all();
                $this->assertAvailable($lines, $r->start_date, $r->end_date, $r->id);
            }
            $r->update(['status' => 'held', 'hold_until' => Carbon::parse($request->hold_until)->setTimezone(config('app.timezone'))]);
        }, $r->items->pluck('rental_item_id')->all());

        return response()->json(['success' => true]);
    }

    /**
     * Turn the hold into a contract (draft, or active straight away). Late-fee
     * terms and agreement wording come from Rental Settings, like any new
     * contract; everything else is copied from the reservation.
     */
    public function convert(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalContract::class);

        $r = $this->findReservation($id, ['items', 'customer']);
        if ($r->status !== 'held' || ! $r->isLive()) {
            return $this->refuse('Only a live hold can be converted. Extend the hold first if it ran out.');
        }
        if ($r->contract_id && RentalContract::whereNull('deleted_at')->whereKey($r->contract_id)->exists()) {
            return $this->refuse('A draft contract was already created from this reservation. Open and activate it instead.');
        }
        if (optional($r->customer)->is_blacklisted) {
            return $this->refuse('This customer is blacklisted and cannot be given a contract.');
        }

        $activate = $request->boolean('activate');

        $s = RentalSetting::current();
        $contract = $this->service->transact(function () use ($r, $s, $activate, $request) {
            // Re-read under lock: a double-clicked convert must not create two contracts.
            $fresh = RentalReservation::whereKey($r->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'held'
                || ($fresh->contract_id && RentalContract::whereNull('deleted_at')->whereKey($fresh->contract_id)->exists())) {
                abort($this->refuse('This reservation was already converted.'));
            }
            if ($activate) {
                $lines = $r->items->map(fn ($l) => ['rental_item_id' => $l->rental_item_id, 'quantity' => $l->quantity])->all();
                $this->assertAvailable($lines, $r->start_date, $r->end_date, $r->id);
            }
            $contract = RentalContract::create(array_merge(
                $r->only([
                    'rental_customer_id', 'client_id', 'warehouse_id', 'start_date', 'end_date',
                    'rate_period', 'billing_type', 'periods', 'rent_amount', 'discount', 'tax_rate',
                    'tax_amount', 'total_amount', 'contract_value', 'deposit_amount', 'notes',
                ]),
                [
                    'contract_number' => $this->service->nextNumber(),
                    'status' => $activate ? 'active' : 'draft',
                    'activated_at' => $activate ? now() : null,
                    'grace_days' => (int) $s->grace_days,
                    'late_fee_type' => $s->late_fee_type,
                    'late_fee_value' => (float) $s->late_fee_value,
                    'late_fee_frequency' => $s->late_fee_frequency,
                    'terms' => $s->agreement_terms,
                    'created_by' => optional($request->user('api'))->id,
                ]
            ));
            foreach ($r->items as $l) {
                RentalContractItem::create([
                    'rental_contract_id' => $contract->id,
                    'rental_item_id' => $l->rental_item_id,
                    'item_name' => $l->item_name,
                    'item_code' => $l->item_code,
                    'quantity' => $l->quantity,
                    'rate' => $l->rate,
                    'amount' => $l->amount,
                ]);
            }
            // A draft keeps the hold alive (linked to it) until it is activated,
            // so nobody else can take the items in between.
            $r->update($activate
                ? ['status' => 'converted', 'contract_id' => $contract->id, 'converted_at' => now()]
                : ['contract_id' => $contract->id]);
            $this->service->log($contract, 'created', null, ['reservation' => $r->reservation_number, 'reservation_id' => $r->id]);
            if ($activate) {
                $this->service->log($contract, 'activated');
            }

            return $contract;
        }, $r->items->pluck('rental_item_id')->all());
        if ($activate) {
            app(\App\Services\Rental\RentalBillingService::class)->run($contract->id);
        }

        return response()->json(['success' => true, 'contract_id' => $contract->id]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function baseQuery()
    {
        $query = RentalReservation::query()
            ->join('clients', 'clients.id', '=', 'rental_reservations.client_id')
            ->whereNull('rental_reservations.deleted_at')
            ->select('rental_reservations.*', 'clients.name as customer_name', 'clients.phone as customer_phone');

        $ids = $this->warehouseScopeIds();
        if ($ids !== null) {
            $query->where(fn ($q) => $q->whereNull('rental_reservations.warehouse_id')->orWhereIn('rental_reservations.warehouse_id', $ids));
        }

        return $query;
    }

    private function applyStage($query, string $stage): void
    {
        $now = Carbon::now();
        switch ($stage) {
            case 'held':
                $query->where('rental_reservations.status', 'held')->where('rental_reservations.hold_until', '>', $now);
                return;
            case 'expiring':
                $query->where('rental_reservations.status', 'held')
                    ->whereBetween('rental_reservations.hold_until', [$now, $now->copy()->addDay()]);
                return;
            case 'converted':
            case 'expired':
            case 'cancelled':
                $query->where('rental_reservations.status', $stage);
                return;
            case 'website':
                // Website requests still waiting for staff: live, unconfirmed, no draft contract.
                if (RentalSetting::websiteReady()) {
                    $query->where('rental_reservations.source', 'website')->where('rental_reservations.status', 'held')
                        ->where('rental_reservations.hold_until', '>', $now)
                        ->whereNull('rental_reservations.confirmed_at')->whereNull('rental_reservations.contract_id');
                }
                return;
        }
    }

    private function present(RentalReservation $r): array
    {
        return [
            'id' => $r->id,
            'reservation_number' => $r->reservation_number,
            'rental_customer_id' => $r->rental_customer_id,
            'customer_name' => $r->customer_name ?? optional($r->client)->name,
            'customer_phone' => $r->customer_phone ?? optional($r->client)->phone,
            'status' => $r->status,
            'stage' => $r->stage(),
            'start_date' => optional($r->start_date)->toIso8601String(),
            'end_date' => optional($r->end_date)->toIso8601String(),
            'hold_until' => optional($r->hold_until)->toIso8601String(),
            'hours_left' => $r->isLive() ? (int) floor(Carbon::now()->diffInHours($r->hold_until)) : null,
            'rate_period' => $r->rate_period,
            'billing_type' => $r->billing_type,
            'periods' => $r->periods,
            'rent_amount' => (float) $r->rent_amount,
            'total_amount' => (float) $r->total_amount,
            'contract_value' => $r->contract_value === null ? null : (float) $r->contract_value,
            'deposit_amount' => (float) $r->deposit_amount,
            'contract_id' => $r->contract_id,
            'contract_number' => optional($r->contract)->contract_number,
            'items_summary' => $r->items->map(fn ($l) => $l->item_name . ($l->quantity > 1 ? ' ×' . $l->quantity : ''))->values(),
            'created_at' => optional($r->created_at)->toIso8601String(),
            'source' => RentalSetting::websiteReady() ? ($r->source ?: 'staff') : 'staff',
            'confirmed_at' => RentalSetting::websiteReady() ? optional($r->confirmed_at)->toIso8601String() : null,
        ];
    }

    private function validated(Request $request, ?RentalReservation $existing = null): array
    {
        $request->validate([
            'rental_customer_id' => 'required|integer|exists:rental_customers,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'hold_until' => 'nullable|date',
            'rate_period' => 'required|in:hour,day,week,month,year',
            'billing_type' => 'required|in:upfront,recurring',
            'items' => 'required|array|min:1',
            'items.*.rental_item_id' => 'required|integer|distinct|exists:rental_items,id',
            'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'items.*.rate' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'deposit_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ], [
            'items.required' => 'Add at least one item.',
            'items.*.rental_item_id.distinct' => 'Each item can only be added once — raise its quantity instead.',
            'end_date.after' => 'The end must be after the start.',
            'hold_until.after' => 'The hold must run until a moment in the future.',
        ], ['rental_customer_id' => 'customer']);

        $customer = RentalCustomer::whereNull('deleted_at')->find($request->rental_customer_id);
        if (! $customer) {
            abort(response()->json(['message' => 'This rental customer no longer exists.', 'errors' => ['rental_customer_id' => ['Not found.']]], 422));
        }
        $sameCustomer = $existing && (int) $existing->rental_customer_id === (int) $customer->id;
        if ($customer->is_blacklisted && ! $sameCustomer) {
            abort(response()->json([
                'message' => 'This customer is blacklisted and cannot be given a reservation.',
                'errors' => ['rental_customer_id' => ['This customer is blacklisted.']],
            ], 422));
        }

        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied((int) $request->warehouse_id);
        }

        $start = Carbon::parse($request->start_date)->setTimezone(config('app.timezone'));
        $end = Carbon::parse($request->end_date)->setTimezone(config('app.timezone'));
        $lines = collect((array) $request->input('items', []))->map(fn ($l) => [
            'rental_item_id' => (int) $l['rental_item_id'],
            'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
            'rate' => (float) ($l['rate'] ?? 0),
        ])->values()->all();

        $totals = $this->service->calculate(
            $lines, $start, $end, $request->rate_period, $request->billing_type,
            (float) $request->discount, (float) $request->tax_rate
        );

        $holdHours = (int) (RentalSetting::current()->default_hold_hours ?: 48);
        $holdUntil = $request->hold_until
            ? Carbon::parse($request->hold_until)->setTimezone(config('app.timezone'))
            : ($existing ? $existing->hold_until : Carbon::now()->addHours($holdHours));
        $unchanged = $existing && $existing->hold_until && $existing->hold_until->diffInMinutes($holdUntil, true) < 1;
        if (! $unchanged && $holdUntil->lte(Carbon::now())) {
            abort(response()->json([
                'message' => 'The hold must run until a moment in the future.',
                'errors' => ['hold_until' => ['The hold must run until a moment in the future.']],
            ], 422));
        }

        return [array_merge([
            'rental_customer_id' => $customer->id,
            'client_id' => $customer->client_id,
            'warehouse_id' => $request->warehouse_id ?: null,
            'start_date' => $start,
            'end_date' => $end,
            'hold_until' => $holdUntil,
            'rate_period' => $request->rate_period,
            'billing_type' => $request->billing_type,
            'discount' => (float) $request->discount,
            'tax_rate' => (float) $request->tax_rate,
            'deposit_amount' => (float) $request->deposit_amount,
            'notes' => $request->notes,
        ], $totals), $lines];
    }

    private function findReservation($id, array $with = []): RentalReservation
    {
        $r = RentalReservation::with($with)->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($r);

        return $r;
    }

    /** A hold that a draft contract was created from belongs to that draft until it is activated. */
    private function lockedByDraft(RentalReservation $r): bool
    {
        return $r->contract_id && RentalContract::whereNull('deleted_at')->whereKey($r->contract_id)->where('status', 'draft')->exists();
    }

    private function saveLines(RentalReservation $r, array $lines): void
    {
        $items = RentalItem::withTrashed()->whereIn('id', array_column($lines, 'rental_item_id'))->get()->keyBy('id');
        foreach ($lines as $l) {
            $item = $items->get($l['rental_item_id']);
            RentalReservationItem::create([
                'rental_reservation_id' => $r->id,
                'rental_item_id' => $l['rental_item_id'],
                'item_name' => $item ? $item->name : ('#' . $l['rental_item_id']),
                'item_code' => optional($item)->code,
                'quantity' => $l['quantity'],
                'rate' => $l['rate'],
                'amount' => round($l['rate'] * $l['quantity'], 2),
            ]);
        }
    }

    private function assertAvailable(array $lines, $start, $end, ?int $ignoreReservationId = null): void
    {
        $qty = [];
        foreach ($lines as $l) {
            $qty[$l['rental_item_id']] = ($qty[$l['rental_item_id']] ?? 0) + (int) $l['quantity'];
        }

        $problems = $this->service->conflicts(
            $qty,
            $start instanceof Carbon ? $start : Carbon::parse($start),
            $end instanceof Carbon ? $end : Carbon::parse($end),
            null,
            $ignoreReservationId
        );

        if ($problems) {
            abort(response()->json([
                'message' => $this->service->describeConflicts($problems),
                'conflicts' => $problems,
            ], 422));
        }
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
