<?php

namespace App\Http\Controllers;

use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Models\RentalReservation;
use App\Services\Rental\RentalContractService;
use App\Traits\ScopesWarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Read-only views over bookings: the availability timeline (items × days,
 * with every contract and hold as a bar) and the pickups/returns calendar.
 */
class RentalAvailabilityController extends Controller
{
    use ScopesWarehouseAccess;

    /** Longest range one timeline request may cover. */
    private const MAX_DAYS = 186;

    public function __construct(private RentalContractService $service)
    {
    }

    /**
     * Items (filtered) plus every booking overlapping [start, end).
     *
     * With free_start/free_end, only items that still have `free_qty` units
     * free over that window are returned — "what can I rent for these dates?".
     */
    public function timeline(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'availability', RentalItem::class);
        $this->service->sync();

        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'free_start' => 'nullable|date',
            'free_end' => 'nullable|date|after:free_start',
            'free_qty' => 'nullable|integer|min:1',
        ]);

        $start = Carbon::parse($request->start)->startOfDay();
        $end = Carbon::parse($request->end)->startOfDay();
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $end = $start->copy()->addDays(self::MAX_DAYS);
        }

        $items = RentalItem::with('category')->whereNull('deleted_at')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('status', '!=', 'inactive'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('category_id'), fn ($q) => $q->where('rental_category_id', $request->category_id))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%"));
            });
        $this->scopeToWarehouses($items, 'warehouse_id', true);
        $items = $items->orderBy('type')->orderBy('name')->limit(300)->get();

        // "Free for these dates" filter.
        $freeQty = [];
        if ($request->filled('free_start') && $request->filled('free_end')) {
            $wanted = max(1, (int) $request->get('free_qty', 1));
            $fs = Carbon::parse($request->free_start)->setTimezone(config('app.timezone'));
            $fe = Carbon::parse($request->free_end)->setTimezone(config('app.timezone'));
            // One conflicts() pass for every item (asking for its whole stock):
            // an item with no problem is entirely free.
            $candidates = $items->reject(fn ($item) => $item->status === 'maintenance');
            $problems = collect($this->service->conflicts(
                $candidates->mapWithKeys(fn ($item) => [$item->id => max(1, (int) $item->quantity)])->all(), $fs, $fe
            ))->keyBy('item_id');
            $items = $candidates->filter(function ($item) use ($problems, $wanted, &$freeQty) {
                $free = $problems->has($item->id) ? (int) $problems[$item->id]['available'] : (int) $item->quantity;
                $freeQty[$item->id] = $free;

                return $free >= $wanted;
            })->values();
        }

        $ids = $items->pluck('id')->all();
        $now = Carbon::now();

        // Contracts touching the window. Closed ones are drawn up to when they
        // actually closed; overdue active ones run up to "now".
        $contracts = RentalContract::with(['client', 'items' => fn ($q) => $q->whereIn('rental_item_id', $ids)])
            ->whereNull('deleted_at')
            ->whereIn('status', $request->boolean('include_drafts')
                ? ['active', 'draft', 'ended', 'terminated', 'renewed']
                : ['active', 'ended', 'terminated', 'renewed'])
            ->whereHas('items', fn ($q) => $q->whereIn('rental_item_id', $ids))
            ->where('start_date', '<', $end)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $start)->orWhere('status', 'active'))
            ->get();

        $bookings = [];
        foreach ($contracts as $c) {
            $barEnd = $c->ended_at && $c->isClosed() ? $c->ended_at : $c->end_date;
            if ($c->status === 'active' && $c->end_date && $c->end_date->lt($now)) {
                $barEnd = $now->copy();
            }
            if ($barEnd && $barEnd->lte($start)) {
                continue;
            }
            foreach ($c->items as $line) {
                $bookings[] = [
                    'key' => 'c' . $c->id . '-' . $line->rental_item_id,
                    'kind' => 'contract',
                    'id' => $c->id,
                    'number' => $c->contract_number,
                    'item_id' => $line->rental_item_id,
                    'quantity' => (int) $line->quantity,
                    'customer_name' => optional($c->client)->name,
                    'status' => $c->status,
                    'stage' => $c->stage(),
                    'start' => $c->start_date->toIso8601String(),
                    'end' => $barEnd ? $barEnd->toIso8601String() : null,
                    'agreed_end' => optional($c->end_date)->toIso8601String(),
                    'open_ended' => $c->end_date === null,
                ];
            }
        }

        $holds = RentalReservation::with(['client', 'items' => fn ($q) => $q->whereIn('rental_item_id', $ids)])
            ->whereNull('deleted_at')
            ->where('status', 'held')
            ->where('hold_until', '>', $now)
            ->whereHas('items', fn ($q) => $q->whereIn('rental_item_id', $ids))
            ->where('start_date', '<', $end)
            ->where('end_date', '>', $start)
            ->get();

        foreach ($holds as $r) {
            foreach ($r->items as $line) {
                $bookings[] = [
                    'key' => 'r' . $r->id . '-' . $line->rental_item_id,
                    'kind' => 'reservation',
                    'id' => $r->id,
                    'number' => $r->reservation_number,
                    'item_id' => $line->rental_item_id,
                    'quantity' => (int) $line->quantity,
                    'customer_name' => optional($r->client)->name,
                    'status' => 'held',
                    'stage' => $r->stage(),
                    'start' => $r->start_date->toIso8601String(),
                    'end' => $r->end_date->toIso8601String(),
                    'hold_until' => $r->hold_until->toIso8601String(),
                    'open_ended' => false,
                ];
            }
        }

        // Maintenance windows (scheduled / running jobs).
        RentalMaintenance::whereNull('deleted_at')->whereIn('rental_item_id', $ids)
            ->whereIn('status', ['scheduled', 'in_progress'])->get()
            ->each(function (RentalMaintenance $m) use (&$bookings, $start, $end) {
                $w = $m->blockWindow();
                if (! $w || $w[0]->gte($end) || ($w[1] && $w[1]->lte($start))) {
                    return;
                }
                $bookings[] = [
                    'key' => 'm' . $m->id,
                    'kind' => 'maintenance',
                    'id' => $m->id,
                    'number' => $m->number,
                    'item_id' => $m->rental_item_id,
                    'quantity' => (int) $m->quantity,
                    'customer_name' => $m->title,
                    'status' => $m->status,
                    'stage' => $m->status,
                    'start' => $w[0]->toIso8601String(),
                    'end' => optional($w[1])->toIso8601String(),
                    'open_ended' => $w[1] === null,
                ];
            });

        return response()->json([
            'range' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'type' => $i->type,
                'status' => $i->status,
                'quantity' => (int) $i->quantity,
                'category_name' => optional($i->category)->name,
                'default_period' => $i->default_period,
                'headline_rate' => $i->rateFor($i->default_period),
                'image_url' => $i->coverUrl(),
                'free_qty' => $freeQty[$i->id] ?? null,
            ])->values(),
            'bookings' => $bookings,
        ]);
    }

    /**
     * Pickups (contract starts), returns (contract ends) and reservation
     * starts between two dates, one entry per event.
     */
    public function calendar(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'availability', RentalItem::class);
        $this->service->sync();

        $request->validate(['start' => 'required|date', 'end' => 'required|date|after:start']);
        $start = Carbon::parse($request->start)->startOfDay();
        $end = Carbon::parse($request->end)->endOfDay();

        $events = [];
        $summary = fn ($items) => $items->map(fn ($l) => $l->item_name . ($l->quantity > 1 ? ' ×' . $l->quantity : ''))->implode(', ');

        // Records with no branch belong to the whole company.
        $scope = $this->warehouseScopeIds();
        $inScope = fn ($q) => $scope === null ? $q : $q->where(fn ($w) => $w->whereNull('warehouse_id')->orWhereIn('warehouse_id', $scope));

        $contracts = RentalContract::with('client', 'items')->whereNull('deleted_at')
            ->whereIn('status', ['active', 'ended', 'terminated', 'renewed'])
            ->where(fn ($q) => $q->whereBetween('start_date', [$start, $end])->orWhereBetween('end_date', [$start, $end]))
            ->tap($inScope)
            ->get();

        foreach ($contracts as $c) {
            if ($c->start_date->between($start, $end)) {
                $events[] = $this->event('pickup', $c->start_date, $c, $c->contract_number, $summary($c->items));
            }
            if ($c->end_date && $c->end_date->between($start, $end)) {
                $events[] = $this->event('return', $c->end_date, $c, $c->contract_number, $summary($c->items));
            }
        }

        $holds = RentalReservation::with('client', 'items')->whereNull('deleted_at')
            ->where('status', 'held')->where('hold_until', '>', Carbon::now())
            ->whereBetween('start_date', [$start, $end])
            ->tap($inScope)
            ->get();
        foreach ($holds as $r) {
            $events[] = $this->event('reservation', $r->start_date, $r, $r->reservation_number, $summary($r->items));
        }

        usort($events, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return response()->json(['events' => $events]);
    }

    private function event(string $type, Carbon $date, $model, string $number, string $items): array
    {
        $isContract = $model instanceof RentalContract;

        return [
            'type' => $type,
            'date' => $date->toIso8601String(),
            'kind' => $isContract ? 'contract' : 'reservation',
            'id' => $model->id,
            'number' => $number,
            'customer_name' => optional($model->client)->name,
            'items' => $items,
            'status' => $model->status,
            // A return still due on an active contract vs one that already happened.
            'done' => $isContract && $type === 'return' && $model->isClosed(),
        ];
    }
}
