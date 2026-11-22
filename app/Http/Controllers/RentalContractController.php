<?php

namespace App\Http\Controllers;

use App\Models\RentalAgreementTemplate;
use App\Models\RentalContract;
use App\Models\RentalContractAttachment;
use App\Models\RentalContractItem;
use App\Models\RentalCustomer;
use App\Models\RentalItem;
use App\Models\RentalDepositTransaction;
use App\Models\RentalPayment;
use App\Models\RentalInvoice;
use App\Models\RentalReservation;
use App\Models\RentalSetting;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Rental\RentalAgreementRenderer;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalContractService;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PDF;

/**
 * Rental contracts: CRUD, the lifecycle actions (activate, extend, renew,
 * terminate, end, cancel), on-screen signature, attachments and the PDF.
 *
 * Every action that can change who holds an item re-syncs those items'
 * statuses through RentalContractService::sync().
 */
class RentalContractController extends Controller
{
    use ScopesWarehouseAccess;

    private const SORTABLE = [
        'contract_number' => 'rental_contracts.contract_number',
        'start_date' => 'rental_contracts.start_date',
        'end_date' => 'rental_contracts.end_date',
        'total_amount' => 'rental_contracts.total_amount',
        'contract_value' => 'rental_contracts.contract_value',
        'customer_name' => 'clients.name',
        'created_at' => 'rental_contracts.created_at',
    ];

    private const ATTACHMENT_RULE = 'required|file|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,txt|max:10240';

    public function __construct(
        private RentalContractService $service,
        private RentalBillingService $billing,
    ) {
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalContract::class);
        $this->service->sync();

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = self::SORTABLE[$request->SortField] ?? 'rental_contracts.created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $soonDays = (int) RentalSetting::current()->expiring_soon_days;
        $query = $this->baseQuery();
        $summary = $this->stageCounts((clone $query), $soonDays);

        $this->applyStage($query, (string) $request->get('stage', ''), $soonDays);

        if ($request->filled('rental_customer_id')) {
            $query->where('rental_contracts.rental_customer_id', $request->rental_customer_id);
        }
        if ($request->filled('rental_item_id')) {
            $query->whereExists(fn ($q) => $q->selectRaw(1)->from('rental_contract_items')
                ->whereColumn('rental_contract_items.rental_contract_id', 'rental_contracts.id')
                ->where('rental_contract_items.rental_item_id', $request->rental_item_id));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rental_contracts.contract_number', 'LIKE', "%{$search}%")
                    ->orWhere('clients.name', 'LIKE', "%{$search}%")
                    ->orWhere('clients.phone', 'LIKE', "%{$search}%")
                    ->orWhereExists(fn ($s) => $s->selectRaw(1)->from('rental_contract_items')
                        ->whereColumn('rental_contract_items.rental_contract_id', 'rental_contracts.id')
                        ->where('rental_contract_items.item_name', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $rows = $query->with('items')
            ->orderBy($order, $dir)
            ->offset(($page * $perPage) - $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'totalRows' => $totalRows,
            'contracts' => $rows->map(fn ($c) => $this->present($c, $soonDays))->values(),
            'summary' => $summary,
        ]);
    }

    /** Rental customers for the contract form (blacklisted ones come back flagged). */
    public function customerSearch(Request $request)
    {
        $this->authorizeFormUse($request);

        $search = trim((string) $request->get('search', ''));

        $rows = RentalCustomer::query()
            ->join('clients', 'clients.id', '=', 'rental_customers.client_id')
            ->whereNull('rental_customers.deleted_at')
            ->whereNull('clients.deleted_at')
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('clients.name', 'LIKE', "%{$search}%")
                    ->orWhere('clients.phone', 'LIKE', "%{$search}%")
                    ->orWhere('rental_customers.id_number', 'LIKE', "%{$search}%");
            }))
            ->when($request->filled('id'), fn ($q) => $q->where('rental_customers.id', $request->id))
            ->orderBy('clients.name')->limit(20)
            ->get(['rental_customers.*', 'clients.name as client_name', 'clients.phone as client_phone']);

        return response()->json([
            'customers' => $rows->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->client_name,
                'phone' => $c->client_phone,
                'id_number' => $c->id_number,
                'id_status' => $c->idStatus(),
                'is_blacklisted' => (bool) $c->is_blacklisted,
                'blacklist_reason' => $c->blacklist_reason,
            ])->values(),
        ]);
    }

    /** Everything the form needs besides the customer list. */
    public function meta(Request $request)
    {
        $this->authorizeFormUse($request);

        $s = RentalSetting::current();

        return response()->json([
            'items' => RentalItem::whereNull('deleted_at')->where('status', '!=', 'inactive')->orderBy('name')->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'code' => $i->code,
                    'name' => $i->name,
                    'type' => $i->type,
                    'status' => $i->status,
                    'quantity' => (int) $i->quantity,
                    'default_period' => $i->default_period,
                    'rates' => collect(RentalItem::RATE_COLUMNS)->map(fn ($col, $p) => $i->rateFor($p))->all(),
                    'deposit_amount' => (float) $i->deposit_amount,
                    'image_url' => $i->coverUrl(),
                ])->values(),
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'defaults' => [
                'grace_days' => (int) $s->grace_days,
                'late_fee_type' => $s->late_fee_type,
                'late_fee_value' => (float) $s->late_fee_value,
                'late_fee_frequency' => $s->late_fee_frequency,
                'terms' => $s->agreement_terms,
                'tax_rate' => (float) optional(Setting::whereNull('deleted_at')->first())->default_tax,
                'hold_hours' => (int) ($s->default_hold_hours ?: 48),
            ],
            'next_reservation_number' => $this->service->nextReservationNumber(),
            'next_number' => $this->service->nextNumber(),
        ]);
    }

    /**
     * Live preview for the form: totals (same maths the save uses) and any
     * double-booking for the chosen dates.
     */
    public function calculate(Request $request)
    {
        $this->authorizeFormUse($request);

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'rate_period' => 'required|in:hour,day,week,month,year',
            'billing_type' => 'required|in:upfront,recurring',
            'items' => 'nullable|array',
        ]);

        $start = Carbon::parse($request->start_date)->setTimezone(config('app.timezone'));
        $end = $request->end_date ? Carbon::parse($request->end_date)->setTimezone(config('app.timezone')) : null;
        $lines = $this->lines($request);

        $totals = $this->service->calculate(
            $lines, $start, $end && $end->gt($start) ? $end : null,
            $request->rate_period, $request->billing_type,
            (float) $request->discount, (float) $request->tax_rate
        );

        return response()->json([
            'totals' => $totals,
            'conflicts' => $end && $end->lte($start) ? [] : $this->service->conflicts(
                $this->quantities($lines), $start, $end,
                $request->contract_id ? (int) $request->contract_id : null,
                $request->reservation_id
                    ? (int) $request->reservation_id
                    : ($request->contract_id ? $this->linkedHoldId((int) $request->contract_id) : null)
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalContract::class);

        [$data, $lines] = $this->validated($request);
        $activate = $request->boolean('activate');

        $contract = $this->service->transact(function () use ($data, $lines, $activate, $request) {
            if ($activate) {
                $this->assertAvailable($lines, $data['start_date'], $data['end_date']);
            }
            $contract = RentalContract::create(array_merge($data, [
                'contract_number' => $this->service->nextNumber(),
                'status' => $activate ? 'active' : 'draft',
                'activated_at' => $activate ? now() : null,
                'created_by' => optional($request->user('api'))->id,
            ]));
            $this->saveLines($contract, $lines);
            $this->service->log($contract, 'created');
            if ($activate) {
                $this->service->log($contract, 'activated');
            }

            return $contract;
        }, array_keys($this->quantities($lines)));
        if ($activate) {
            $this->billing->run($contract->id);
        }

        return response()->json(['success' => true, 'id' => $contract->id]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalContract::class);
        $this->service->sync();

        $c = $this->findContract($id, [
            'items.item', 'client', 'customer', 'warehouse', 'renewal', 'renewedFrom',
            'events' => fn ($q) => $q->orderBy('created_at', 'desc')->orderBy('id', 'desc'),
            'events.user', 'attachments',
        ]);

        $soonDays = (int) RentalSetting::current()->expiring_soon_days;

        return response()->json(['contract' => array_merge($this->present($c, $soonDays), [
            'customer' => [
                'id' => $c->rental_customer_id,
                'client_id' => $c->client_id,
                'name' => optional($c->client)->name,
                'phone' => optional($c->client)->phone,
                'email' => optional($c->client)->email,
                'id_number' => optional($c->customer)->id_number,
                'id_status' => $c->customer ? $c->customer->idStatus($soonDays) : null,
                'is_blacklisted' => (bool) optional($c->customer)->is_blacklisted,
            ],
            'warehouse_id' => $c->warehouse_id,
            'warehouse_name' => optional($c->warehouse)->name,
            'lines' => $c->items->map(fn ($l) => [
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
                'checked_out_at' => optional($l->checked_out_at)->toIso8601String(),
                'returned_at' => optional($l->returned_at)->toIso8601String(),
            ])->values(),
            'checked_out_at' => optional($c->checked_out_at)->toIso8601String(),
            'returned_at' => optional($c->returned_at)->toIso8601String(),
            'deposit_held' => $c->depositHeld(),
            'discount' => (float) $c->discount,
            'tax_rate' => (float) $c->tax_rate,
            'tax_amount' => (float) $c->tax_amount,
            'grace_days' => (int) $c->grace_days,
            'late_fee_type' => $c->late_fee_type,
            'late_fee_value' => (float) $c->late_fee_value,
            'late_fee_frequency' => $c->late_fee_frequency,
            'terms' => $c->terms,
            'notes' => $c->notes,
            'signed_name' => $c->signed_name,
            'signed_at' => optional($c->signed_at)->toIso8601String(),
            'signature_url' => RentalContract::fileUrl($c->signature),
            'activated_at' => optional($c->activated_at)->toIso8601String(),
            'ended_at' => optional($c->ended_at)->toIso8601String(),
            'terminated_at' => optional($c->terminated_at)->toIso8601String(),
            'termination_reason' => $c->termination_reason,
            'cancelled_at' => optional($c->cancelled_at)->toIso8601String(),
            'renewal' => $c->renewal ? ['id' => $c->renewal->id, 'contract_number' => $c->renewal->contract_number, 'status' => $c->renewal->status] : null,
            'renewed_from' => $c->renewedFrom ? ['id' => $c->renewedFrom->id, 'contract_number' => $c->renewedFrom->contract_number] : null,
            'events' => $c->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'note' => $e->note,
                'meta' => $e->meta,
                'user_name' => $e->user ? trim(($e->user->firstname ?? '') . ' ' . ($e->user->lastname ?? '')) ?: ($e->user->username ?? null) : null,
                'created_at' => optional($e->created_at)->toIso8601String(),
            ])->values(),
            'attachments' => $c->attachments->sortByDesc('created_at')->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'size' => (int) $a->size,
                'url' => RentalContract::fileUrl($a->file_name),
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])->values(),
        ])]);
    }

    /** Drafts and active contracts can be edited; closed ones are history. */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        if ($contract->isClosed()) {
            return $this->refuse('This contract is closed and can no longer be edited.');
        }

        [$data, $lines] = $this->validated($request, $contract);
        $contract->load('items');
        if ($reason = $this->billingLock($contract, $data, $lines)) {
            return $this->refuse($reason);
        }
        $unsign = $contract->signature && $this->agreementChanged($contract, $data, $lines);

        $before = $this->service->itemIds($contract);
        $this->service->transact(function () use ($contract, $data, $lines, $unsign) {
            if ($contract->status === 'active') {
                $this->assertAvailable($lines, $data['start_date'], $data['end_date'], array_filter([$contract->id, $contract->renewed_from_id]));
            }
            $contract->update($data);
            $this->syncLines($contract, $lines);
            $this->service->log($contract, 'updated');
            if ($unsign) {
                // The signed agreement no longer matches the contract.
                $this->deleteFile($contract->signature);
                $contract->update(['signed_name' => null, 'signature' => null, 'signed_at' => null]);
                $this->service->log($contract, 'signature_removed', 'The agreement changed after it was signed.');
            }
        }, array_merge($before, array_keys($this->quantities($lines))));
        if ($contract->status === 'active') {
            $this->billing->run($contract->id);
        }

        return response()->json(['success' => true, 'id' => $contract->id]);
    }

    /** Only drafts and cancelled contracts can be deleted — anything else is history. */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalContract::class);

        $contract = $this->findContract($id);
        if (! in_array($contract->status, ['draft', 'cancelled'], true)) {
            return $this->refuse('Only draft or cancelled contracts can be deleted. End or terminate it instead.');
        }
        $hasMoney = RentalInvoice::where('rental_contract_id', $contract->id)->where('status', '!=', 'void')->exists()
            || RentalPayment::whereNull('deleted_at')->where('rental_contract_id', $contract->id)->exists()
            || RentalDepositTransaction::whereNull('deleted_at')->where('rental_contract_id', $contract->id)->exists();
        if ($hasMoney) {
            return $this->refuse('This contract has invoices, payments or deposit entries, so it is kept for the records and cannot be deleted.');
        }

        $contract->delete();
        // A deleted renewal frees the contract it renewed; a deleted draft
        // hands its reservation back.
        RentalContract::where('renewal_id', $contract->id)->update(['renewal_id' => null]);
        RentalReservation::where('contract_id', $contract->id)->where('status', 'held')->update(['contract_id' => null]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function activate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id, ['items', 'customer']);
        if ($contract->status !== 'draft') {
            return $this->refuse('Only a draft can be activated.');
        }
        if (optional($contract->customer)->is_blacklisted) {
            return $this->refuse('This customer is blacklisted. Remove them from the blacklist before activating.');
        }
        if ($contract->items->isEmpty()) {
            return $this->refuse('Add at least one item before activating.');
        }

        $lines = $contract->items->map(fn ($l) => ['rental_item_id' => $l->rental_item_id, 'quantity' => $l->quantity])->all();
        $holdId = $this->linkedHoldId($contract->id);

        $this->service->transact(function () use ($contract, $holdId, $lines) {
            // A renewal starts where the contract it renews ends; that one
            // still holds the items until then, so it is not a clash.
            $this->assertAvailable($lines, $contract->start_date, $contract->end_date, array_filter([$contract->id, $contract->renewed_from_id]), $holdId);
            $contract->update(['status' => 'active', 'activated_at' => now()]);
            if ($holdId) {
                RentalReservation::whereKey($holdId)->update(['status' => 'converted', 'converted_at' => now()]);
            }
            $this->service->log($contract, 'activated');
        }, $this->service->itemIds($contract));
        $this->billing->run($contract->id);

        return response()->json(['success' => true]);
    }

    /** Push the end date later (or give an open-ended contract an end). */
    public function extend(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id, ['items']);
        if (! in_array($contract->status, ['draft', 'active'], true)) {
            return $this->refuse('Only draft or active contracts can be extended.');
        }

        $request->validate([
            'end_date' => 'required|date|after:' . $contract->start_date->toDateTimeString(),
            'note' => 'nullable|string|max:1000',
        ]);
        $newEnd = Carbon::parse($request->end_date)->setTimezone(config('app.timezone'));
        if ($contract->end_date && $newEnd->lte($contract->end_date)) {
            return $this->refuse('The new end date must be after the current one (' . $contract->end_date->format('Y-m-d H:i') . ').');
        }

        $this->service->transact(function () use ($contract, $newEnd, $request) {
            if ($contract->status === 'active') {
                $lines = $contract->items->whereNull('returned_at')
                    ->map(fn ($l) => ['rental_item_id' => $l->rental_item_id, 'quantity' => $l->quantity])->values()->all();
                $this->assertAvailable($lines, $contract->start_date, $newEnd, array_filter([$contract->id, $contract->renewed_from_id]));
            }
            $from = $contract->end_date;
            $contract->update(array_merge(['end_date' => $newEnd], $this->recalculate($contract, $newEnd)));
            $this->service->log($contract, 'extended', $request->note, [
                'from' => optional($from)->toIso8601String(),
                'to' => $newEnd->toIso8601String(),
            ]);
        }, $this->service->itemIds($contract));
        if ($contract->status === 'active') {
            $this->billing->run($contract->id);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Create the follow-on contract: same customer, items and terms, starting
     * where this one ends. It is created as a draft unless `activate` is set;
     * this contract stays active until its own end date, then reads "renewed".
     */
    public function renew(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalContract::class);

        $contract = $this->findContract($id, ['items.item', 'customer']);
        if (! in_array($contract->status, ['active', 'ended'], true)) {
            return $this->refuse('Only active or ended contracts can be renewed.');
        }
        if ($contract->renewal_id && RentalContract::whereNull('deleted_at')->whereKey($contract->renewal_id)->exists()) {
            return $this->refuse('This contract has already been renewed.');
        }
        if (optional($contract->customer)->is_blacklisted) {
            return $this->refuse('This customer is blacklisted and cannot be given a new contract.');
        }

        $request->validate([
            'start_date' => 'nullable|date',
            'periods' => 'required|integer|min:1|max:1000',
            'use_current_rates' => 'nullable|boolean',
            'activate' => 'nullable|boolean',
        ]);

        if (! $contract->end_date) {
            return $this->refuse('An open-ended contract keeps running until you end it, so it cannot be renewed. Extend it (give it an end date) first.');
        }
        $start = $request->start_date ? Carbon::parse($request->start_date)->setTimezone(config('app.timezone')) : $contract->end_date->copy();
        if ($start->lt($contract->end_date)) {
            return $this->refuse('A renewal starts where this contract ends (' . $contract->end_date->format('Y-m-d H:i') . ') or later.');
        }
        $end = $this->service->addPeriods($start, $contract->rate_period, (int) $request->periods);

        $lines = $contract->items->map(function ($l) use ($request, $contract) {
            $rate = $request->boolean('use_current_rates') && $l->item
                ? ($l->item->rateFor($contract->rate_period) ?? (float) $l->rate)
                : (float) $l->rate;

            return ['rental_item_id' => $l->rental_item_id, 'quantity' => (int) $l->quantity, 'rate' => $rate];
        })->all();

        $activate = $request->boolean('activate');

        $renewal = $this->service->transact(function () use ($contract, $start, $end, $lines, $activate, $request) {
            if ($activate) {
                // The old contract still holds the items until its end — that's
                // exactly when the renewal starts, so don't count it as a clash.
                $this->assertAvailable($lines, $start, $end, $contract->id);
            }
            $totals = $this->service->calculate(
                $lines, $start, $end, $contract->rate_period, $contract->billing_type,
                (float) $contract->discount, (float) $contract->tax_rate
            );

            $renewal = RentalContract::create(array_merge(
                $contract->only([
                    'rental_customer_id', 'client_id', 'warehouse_id', 'rate_period', 'billing_type',
                    'discount', 'tax_rate', 'deposit_amount', 'grace_days',
                    'late_fee_type', 'late_fee_value', 'late_fee_frequency', 'terms', 'notes',
                ]),
                $totals,
                [
                    'contract_number' => $this->service->nextNumber(),
                    'status' => $activate ? 'active' : 'draft',
                    'activated_at' => $activate ? now() : null,
                    'start_date' => $start,
                    'end_date' => $end,
                    'renewed_from_id' => $contract->id,
                    'created_by' => optional($request->user('api'))->id,
                ]
            ));
            $this->saveLines($renewal, $lines);

            $contract->update(['renewal_id' => $renewal->id]);
            $this->service->log($contract, 'renewal_created', null, ['renewal_id' => $renewal->id, 'number' => $renewal->contract_number]);
            $this->service->log($renewal, 'created', null, ['renewed_from_id' => $contract->id, 'number' => $contract->contract_number]);
            if ($activate) {
                $this->service->log($renewal, 'activated');
            }
            // An already-ended contract is closed out as renewed right away.
            if ($contract->status === 'ended') {
                $contract->update(['status' => 'renewed']);
            }

            return $renewal;
        }, $this->service->itemIds($contract));
        if ($activate) {
            $this->billing->run($renewal->id);
        }

        return response()->json(['success' => true, 'id' => $renewal->id]);
    }

    /** Close early: the items come back before the agreed end date. */
    public function terminate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        if ($contract->status !== 'active') {
            return $this->refuse('Only an active contract can be terminated.');
        }
        $request->validate([
            'date' => 'nullable|date',
            'reason' => 'required|string|max:2000',
        ], ['reason.required' => 'Please give a reason for terminating the contract.']);

        $date = $request->date ? Carbon::parse($request->date)->setTimezone(config('app.timezone')) : Carbon::now();
        if ($date->gt(Carbon::now()->addMinutes(5))) {
            return $this->refuse('The termination date cannot be in the future. Extend or shorten the contract instead, or terminate it on the day.');
        }
        $earliest = $contract->start_date->copy();
        $handed = $contract->items()->whereNotNull('checked_out_at')->min('checked_out_at');
        if ($handed && Carbon::parse($handed)->lt($earliest)) {
            $earliest = Carbon::parse($handed);
        }
        if ($date->lt($earliest)) {
            return $this->refuse('The termination date cannot be before the contract started (' . $earliest->format('Y-m-d H:i') . '). Cancel it instead.');
        }

        $this->service->transact(function () use ($contract, $date, $request) {
            $contract->update([
                'status' => 'terminated',
                'terminated_at' => $date,
                'ended_at' => $date,
                'termination_reason' => $request->reason,
            ]);
            $this->service->log($contract, 'terminated', $request->reason, ['date' => $date->toIso8601String()]);
        }, $this->service->itemIds($contract));
        $this->billing->onContractClosed($contract->fresh());

        return response()->json(['success' => true]);
    }

    /** Normal completion — the items are back, the agreement is fulfilled. */
    public function end(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        if ($contract->status !== 'active') {
            return $this->refuse('Only an active contract can be ended.');
        }
        $request->validate(['note' => 'nullable|string|max:1000']);

        $this->service->transact(function () use ($contract, $request) {
            $contract->update(['status' => 'ended', 'ended_at' => now()]);
            $this->service->log($contract, 'ended', $request->note);
        }, $this->service->itemIds($contract));
        $this->billing->onContractClosed($contract->fresh());

        return response()->json(['success' => true]);
    }

    /** Called off before it really started: drafts, or active ones not yet begun. */
    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        $notStarted = $contract->status === 'active' && $contract->start_date->gt(now());
        if ($contract->status !== 'draft' && ! $notStarted) {
            return $this->refuse('Only drafts or contracts that have not started yet can be cancelled. Terminate it instead.');
        }
        if ($contract->items()->whereNotNull('checked_out_at')->whereNull('returned_at')->exists()) {
            return $this->refuse('Some items have already been handed over. Check them in first, or terminate the contract.');
        }
        if (RentalPayment::whereNull('deleted_at')->where('rental_contract_id', $contract->id)->where('source', 'cash')->exists()) {
            return $this->refuse('Payments have been received on this contract. Delete or refund them first, or terminate the contract instead.');
        }
        $request->validate(['reason' => 'nullable|string|max:2000']);

        $this->service->transact(function () use ($contract, $request) {
            $contract->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            RentalContract::where('renewal_id', $contract->id)->update(['renewal_id' => null]);
            RentalReservation::where('contract_id', $contract->id)->where('status', 'held')->update(['contract_id' => null]);
            $this->service->log($contract, 'cancelled', $request->reason);
        }, $this->service->itemIds($contract));
        $this->billing->onContractClosed($contract->fresh());

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Signature + attachments
    // ------------------------------------------------------------------

    /** Store the customer's on-screen signature (PNG data URL from the pad). */
    public function sign(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        if ($contract->isClosed()) {
            return $this->refuse('This contract is closed; its signature can no longer change.');
        }
        $request->validate([
            'signed_name' => 'required|string|max:191',
            'signature' => ['required', 'string', 'max:2000000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
        ]);

        if (! $this->service->saveSignature($contract, $request->signed_name, $request->signature)) {
            return $this->refuse('The signature image is not valid.');
        }

        return response()->json(['success' => true]);
    }

    public function unsign(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        if ($contract->isClosed()) {
            return $this->refuse('This contract is closed; its signature can no longer change.');
        }
        $this->deleteFile($contract->signature);
        $contract->update(['signed_name' => null, 'signature' => null, 'signed_at' => null]);
        $this->service->log($contract, 'signature_removed');

        return response()->json(['success' => true]);
    }

    public function storeAttachment(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        $request->validate(['file' => self::ATTACHMENT_RULE, 'title' => 'nullable|string|max:191']);

        $upload = $request->file('file');
        $original = $upload->getClientOriginalName();
        $mime = $upload->getClientMimeType();
        $size = (int) $upload->getSize();

        $dir = upload_public_path(RentalContract::FILE_DIR);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = Str::random(40) . '.' . \App\Services\Rental\RentalUpload::extension($upload, 'document');
        $upload->move($dir, $file);

        $attachment = RentalContractAttachment::create([
            'rental_contract_id' => $contract->id,
            'title' => trim((string) $request->title) ?: pathinfo($original, PATHINFO_FILENAME),
            'file_name' => $file,
            'original_name' => Str::limit($original, 185, ''),
            'mime' => $mime,
            'size' => $size,
            'uploaded_by' => optional($request->user('api'))->id,
        ]);
        $this->service->log($contract, 'attachment_added', $attachment->title);

        return response()->json(['success' => true, 'id' => $attachment->id]);
    }

    public function destroyAttachment(Request $request, $id, $attachmentId)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalContract::class);

        $contract = $this->findContract($id);
        $attachment = RentalContractAttachment::where('rental_contract_id', $contract->id)->findOrFail($attachmentId);
        $this->deleteFile($attachment->file_name);
        $attachment->delete();
        $this->service->log($contract, 'attachment_removed', $attachment->title);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /** The agreement, merged into the chosen (or default) template. */
    public function pdf(Request $request, $id, \App\Services\Rental\RentalPdf $pdfs)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalContract::class);

        $contract = $this->findContract($id);
        $pdf = $pdfs->agreement($contract, $request->filled('template_id') ? (int) $request->template_id : null);
        $filename = 'Rental_Agreement_' . $contract->contract_number . '.pdf';

        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function baseQuery()
    {
        $query = RentalContract::query()
            ->join('clients', 'clients.id', '=', 'rental_contracts.client_id')
            ->whereNull('rental_contracts.deleted_at')
            ->select('rental_contracts.*', 'clients.name as customer_name', 'clients.phone as customer_phone');

        // Contracts with no branch belong to the whole company.
        $ids = $this->warehouseScopeIds();
        if ($ids !== null) {
            $query->where(fn ($q) => $q->whereNull('rental_contracts.warehouse_id')->orWhereIn('rental_contracts.warehouse_id', $ids));
        }

        return $query;
    }

    /** Stage filter: stored statuses, plus the date-derived active sub-stages. */
    private function applyStage($query, string $stage, int $soonDays): void
    {
        $now = Carbon::now();
        $soon = $now->copy()->addDays($soonDays);

        switch ($stage) {
            case '':
            case 'all':
                return;
            case 'upcoming':
                $query->where('rental_contracts.status', 'active')->where('rental_contracts.start_date', '>', $now);
                return;
            case 'current':
                // Active and running now: started, and not past its end.
                $query->where('rental_contracts.status', 'active')->where('rental_contracts.start_date', '<=', $now)
                    ->where(fn ($q) => $q->whereNull('rental_contracts.end_date')->orWhere('rental_contracts.end_date', '>=', $now));
                return;
            case 'expiring':
                $query->where('rental_contracts.status', 'active')->where('rental_contracts.start_date', '<=', $now)
                    ->whereBetween('rental_contracts.end_date', [$now, $soon]);
                return;
            case 'overdue':
                $query->where('rental_contracts.status', 'active')->where('rental_contracts.end_date', '<', $now);
                return;
            case 'closed':
                $query->whereIn('rental_contracts.status', ['ended', 'terminated', 'renewed']);
                return;
            default:
                if (in_array($stage, RentalContract::STATUSES, true)) {
                    $query->where('rental_contracts.status', $stage);
                }
        }
    }

    private function stageCounts($query, int $soonDays): array
    {
        $counts = [];
        foreach (['all', 'draft', 'active', 'upcoming', 'expiring', 'overdue', 'closed', 'cancelled'] as $stage) {
            $q = clone $query;
            $this->applyStage($q, $stage, $soonDays);
            $counts[$stage] = $q->count();
        }

        return $counts;
    }

    private function present(RentalContract $c, int $soonDays): array
    {
        $now = Carbon::now();
        $progress = null;
        if ($c->end_date && $c->end_date->gt($c->start_date)) {
            $total = $c->start_date->diffInSeconds($c->end_date);
            $done = $c->start_date->diffInSeconds($now, false);
            $progress = (int) max(0, min(100, round($done / max(1, $total) * 100)));
        }

        return [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'rental_customer_id' => $c->rental_customer_id,
            'customer_name' => $c->customer_name ?? optional($c->client)->name,
            'customer_phone' => $c->customer_phone ?? optional($c->client)->phone,
            'status' => $c->status,
            'stage' => $c->stage($soonDays),
            'start_date' => optional($c->start_date)->toIso8601String(),
            'end_date' => optional($c->end_date)->toIso8601String(),
            'days_left' => $c->end_date ? (int) floor($now->diffInDays($c->end_date, false)) : null,
            'progress' => $progress,
            'rate_period' => $c->rate_period,
            'billing_type' => $c->billing_type,
            'periods' => $c->periods,
            'rent_amount' => (float) $c->rent_amount,
            'total_amount' => (float) $c->total_amount,
            'contract_value' => $c->contract_value === null ? null : (float) $c->contract_value,
            'deposit_amount' => (float) $c->deposit_amount,
            'is_signed' => (bool) $c->signature,
            'renewal_id' => $c->renewal_id,
            'renewed_from_id' => $c->renewed_from_id,
            'items_summary' => $c->items->map(fn ($l) => $l->item_name . ($l->quantity > 1 ? ' ×' . $l->quantity : ''))->values(),
            'created_at' => optional($c->created_at)->toIso8601String(),
        ];
    }

    /** Validates a create/update body; returns [contract columns, lines]. */
    private function validated(Request $request, ?RentalContract $existing = null): array
    {
        $request->validate([
            'rental_customer_id' => 'required|integer|exists:rental_customers,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'rate_period' => 'required|in:hour,day,week,month,year',
            'billing_type' => 'required|in:upfront,recurring',
            'items' => 'required|array|min:1',
            'items.*.rental_item_id' => 'required|integer|distinct|exists:rental_items,id',
            'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'items.*.rate' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'deposit_amount' => 'nullable|numeric|min:0',
            'grace_days' => 'nullable|integer|min:0|max:365',
            'late_fee_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'late_fee_value' => 'nullable|numeric|min:0',
            'late_fee_frequency' => ['nullable', Rule::in(['once', 'daily'])],
            'terms' => 'nullable|string|max:50000',
            'notes' => 'nullable|string|max:5000',
        ], [
            'items.required' => 'Add at least one item.',
            'items.*.rental_item_id.distinct' => 'Each item can only be added once — raise its quantity instead.',
            'end_date.after' => 'The end must be after the start.',
        ], [
            'rental_customer_id' => 'customer',
            'items.*.rental_item_id' => 'item',
        ]);

        if (! $request->end_date && $request->billing_type === 'upfront') {
            abort(response()->json([
                'message' => 'An upfront contract needs an end date — open-ended contracts are billed every period.',
                'errors' => ['end_date' => ['An upfront contract needs an end date.']],
            ], 422));
        }
        if ($request->late_fee_type === 'percent' && (float) $request->late_fee_value > 100) {
            abort(response()->json([
                'message' => 'A percentage late fee cannot exceed 100.',
                'errors' => ['late_fee_value' => ['A percentage late fee cannot exceed 100.']],
            ], 422));
        }

        $customer = RentalCustomer::whereNull('deleted_at')->find($request->rental_customer_id);
        if (! $customer) {
            abort(response()->json(['message' => 'This rental customer no longer exists.', 'errors' => ['rental_customer_id' => ['Not found.']]], 422));
        }
        // Blacklisting blocks NEW business; an existing contract of the same
        // customer stays editable.
        $sameCustomer = $existing && (int) $existing->rental_customer_id === (int) $customer->id;
        if ($customer->is_blacklisted && ! $sameCustomer) {
            abort(response()->json([
                'message' => 'This customer is blacklisted and cannot be given a contract.',
                'errors' => ['rental_customer_id' => ['This customer is blacklisted.']],
            ], 422));
        }

        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied((int) $request->warehouse_id);
        }

        $start = Carbon::parse($request->start_date)->setTimezone(config('app.timezone'));
        $end = $request->end_date ? Carbon::parse($request->end_date)->setTimezone(config('app.timezone')) : null;
        $lines = $this->lines($request);

        $totals = $this->service->calculate(
            $lines, $start, $end, $request->rate_period, $request->billing_type,
            (float) $request->discount, (float) $request->tax_rate
        );
        $lateType = $request->late_fee_type ?: 'none';

        return [array_merge([
            'rental_customer_id' => $customer->id,
            'client_id' => $customer->client_id,
            'warehouse_id' => $request->warehouse_id ?: null,
            'start_date' => $start,
            'end_date' => $end,
            'rate_period' => $request->rate_period,
            'billing_type' => $request->billing_type,
            'discount' => (float) $request->discount,
            'tax_rate' => (float) $request->tax_rate,
            'deposit_amount' => (float) $request->deposit_amount,
            'grace_days' => (int) $request->grace_days,
            'late_fee_type' => $lateType,
            'late_fee_value' => $lateType === 'none' ? 0 : (float) $request->late_fee_value,
            'late_fee_frequency' => $request->late_fee_frequency ?: 'once',
            'terms' => $request->terms,
            'notes' => $request->notes,
        ], $totals), $lines];
    }

    /** Normalised lines from the request body. */
    private function lines(Request $request): array
    {
        return collect((array) $request->input('items', []))
            ->filter(fn ($l) => ! empty($l['rental_item_id']))
            ->map(fn ($l) => [
                'rental_item_id' => (int) $l['rental_item_id'],
                'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
                'rate' => (float) ($l['rate'] ?? 0),
            ])->values()->all();
    }

    /** [item_id => qty] from lines (duplicates summed). */
    private function quantities(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[$l['rental_item_id']] = ($out[$l['rental_item_id']] ?? 0) + (int) $l['quantity'];
        }

        return $out;
    }

    /** The contract form endpoints also serve the reservation form (same fields). */
    private function authorizeFormUse(Request $request): void
    {
        $user = $request->user('api');
        if (! $user->can('view', RentalContract::class) && ! $user->can('create', RentalReservation::class) && ! $user->can('update', RentalReservation::class)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /** Load a live contract by id, refusing contracts of a warehouse the user isn't assigned to. */
    private function findContract($id, array $with = []): RentalContract
    {
        $contract = RentalContract::with($with)->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($contract);

        return $contract;
    }

    /**
     * Update a contract's lines in place, matched by item: kept lines keep
     * their id and handover state (checked_out_at / returned_at), so editing
     * an active contract never "un-returns" an item or orphans handover rows.
     * A line that has been handed over cannot be removed.
     */
    private function syncLines(RentalContract $contract, array $lines): void
    {
        $existing = $contract->items()->get()->keyBy('rental_item_id');
        $wanted = collect($lines)->keyBy('rental_item_id');

        foreach ($existing as $itemId => $line) {
            if (! $wanted->has($itemId) && ($line->checked_out_at || $line->returned_at)) {
                abort($this->refuse("{$line->item_name} has already been handed over on this contract, so it cannot be removed. Check it in, or terminate the contract instead."));
            }
        }

        $items = RentalItem::withTrashed()->whereIn('id', $wanted->keys())->get()->keyBy('id');
        foreach ($wanted as $itemId => $l) {
            $values = [
                'quantity' => $l['quantity'],
                'rate' => $l['rate'],
                'amount' => round($l['rate'] * $l['quantity'], 2),
            ];
            if ($line = $existing->get($itemId)) {
                $line->update($values);
            } else {
                $item = $items->get($itemId);
                RentalContractItem::create(array_merge($values, [
                    'rental_contract_id' => $contract->id,
                    'rental_item_id' => $itemId,
                    'item_name' => $item ? $item->name : ('#' . $itemId),
                    'item_code' => optional($item)->code,
                ]));
            }
        }
        foreach ($existing as $itemId => $line) {
            if (! $wanted->has($itemId)) {
                $line->delete();
            }
        }
    }

    /**
     * Changes to an active contract that would make the bills already issued
     * wrong are refused (returns the reason), with the way to do it instead.
     */
    private function billingLock(RentalContract $c, array $data, array $lines): ?string
    {
        if ($c->status !== 'active') {
            return null;
        }
        $rent = RentalInvoice::where('rental_contract_id', $c->id)->where('kind', 'rent')->where('status', '!=', 'void');
        if (! (clone $rent)->exists()) {
            return null;
        }
        if (! $c->start_date->equalTo($data['start_date']) || $c->rate_period !== $data['rate_period'] || $c->billing_type !== $data['billing_type']) {
            return 'This contract has already been invoiced, so its start date, rental period and billing type can no longer change. Terminate it and create a new contract instead.';
        }
        if ($c->billing_type !== 'upfront') {
            return null; // recurring: new prices apply from the next bill
        }

        $old = $c->items->mapWithKeys(fn ($l) => [$l->rental_item_id => [(int) $l->quantity, round((float) $l->rate, 2)]])->sortKeys()->all();
        $new = collect($lines)->mapWithKeys(fn ($l) => [$l['rental_item_id'] => [(int) $l['quantity'], round((float) $l['rate'], 2)]])->sortKeys()->all();
        if ($old != $new || round((float) $c->discount, 2) != round((float) ($data['discount'] ?? 0), 2) || round((float) $c->tax_rate, 2) != round((float) ($data['tax_rate'] ?? 0), 2)) {
            return 'This contract was billed in advance, so its items and prices can no longer change. Add a charge for anything extra, or terminate it and create a new contract.';
        }
        $billedTo = (clone $rent)->max('period_end');
        if ($billedTo && $data['end_date'] && Carbon::parse($data['end_date'])->lt(Carbon::parse($billedTo))) {
            return 'This contract was billed in advance up to ' . Carbon::parse($billedTo)->format('Y-m-d H:i') . '. To end it earlier, terminate it.';
        }

        return null;
    }

    /** Agreement-relevant fields: changing any of them on a signed contract voids the signature. */
    private function agreementChanged(RentalContract $c, array $data, array $lines): bool
    {
        $old = $c->items->mapWithKeys(fn ($l) => [$l->rental_item_id => [(int) $l->quantity, round((float) $l->rate, 2)]])->sortKeys()->all();
        $new = collect($lines)->mapWithKeys(fn ($l) => [$l['rental_item_id'] => [(int) $l['quantity'], round((float) $l['rate'], 2)]])->sortKeys()->all();
        $end = $data['end_date'] ? Carbon::parse($data['end_date']) : null;
        $sameEnd = ($c->end_date === null && $end === null) || ($c->end_date && $end && $c->end_date->equalTo($end));

        return $old != $new
            || ! $c->start_date->equalTo($data['start_date']) || ! $sameEnd
            || $c->rate_period !== $data['rate_period'] || $c->billing_type !== $data['billing_type']
            || round((float) $c->discount, 2) != round((float) ($data['discount'] ?? 0), 2)
            || round((float) $c->tax_rate, 2) != round((float) ($data['tax_rate'] ?? 0), 2)
            || round((float) $c->deposit_amount, 2) != round((float) ($data['deposit_amount'] ?? 0), 2)
            || (string) $c->terms !== (string) ($data['terms'] ?? '')
            || (int) $c->rental_customer_id !== (int) $data['rental_customer_id'];
    }

    private function saveLines(RentalContract $contract, array $lines): void
    {
        $items = RentalItem::withTrashed()->whereIn('id', array_column($lines, 'rental_item_id'))->get()->keyBy('id');
        foreach ($lines as $l) {
            $item = $items->get($l['rental_item_id']);
            RentalContractItem::create([
                'rental_contract_id' => $contract->id,
                'rental_item_id' => $l['rental_item_id'],
                'item_name' => $item ? $item->name : ('#' . $l['rental_item_id']),
                'item_code' => optional($item)->code,
                'quantity' => $l['quantity'],
                'rate' => $l['rate'],
                'amount' => round($l['rate'] * $l['quantity'], 2),
            ]);
        }
    }

    /** Totals for a new end date, keeping everything else. */
    private function recalculate(RentalContract $c, Carbon $end): array
    {
        $lines = $c->items->map(fn ($l) => ['rate' => (float) $l->rate, 'quantity' => (int) $l->quantity])->all();

        return $this->service->calculate(
            $lines, $c->start_date, $end, $c->rate_period, $c->billing_type, (float) $c->discount, (float) $c->tax_rate
        );
    }

    private function assertAvailable(array $lines, $start, $end, int|array|null $ignoreId = null, ?int $ignoreHoldId = null): void
    {
        $problems = $this->service->conflicts(
            $this->quantities($lines),
            $start instanceof Carbon ? $start : Carbon::parse($start),
            $end ? ($end instanceof Carbon ? $end : Carbon::parse($end)) : null,
            $ignoreId,
            $ignoreHoldId
        );

        if ($problems) {
            abort(response()->json([
                'message' => $this->service->describeConflicts($problems),
                'conflicts' => $problems,
            ], 422));
        }
    }

    /** The live reservation a draft was created from, if it still holds the items. */
    private function linkedHoldId(int $contractId): ?int
    {
        $id = RentalReservation::whereNull('deleted_at')->where('contract_id', $contractId)
            ->where('status', 'held')->value('id');

        return $id ? (int) $id : null;
    }

    private function refuse(string $message, array $extra = [])
    {
        return response()->json(array_merge(['message' => $message], $extra), 422);
    }

    private function deleteFile($filename): void
    {
        if (! $filename || ! is_string($filename) || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return;
        }
        $path = upload_public_path(RentalContract::FILE_DIR . '/' . $filename);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
