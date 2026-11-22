<?php

namespace App\Http\Controllers;

use App\Models\RestaurantArea;
use App\Models\RestaurantReservation;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\RestaurantWaitlistEntry;
use App\Services\Restaurant\FloorException;
use App\Services\Restaurant\ReservationException;
use App\Services\Restaurant\ReservationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Restaurant › Reservations & waitlist (permission rst_reservations).
 * GET  restaurant/reservations?date=Y-m-d          the day: bookings, waitlist, tables, week strip
 * GET  restaurant/reservations/search?q=           upcoming bookings by name / phone
 * GET  restaurant/reservations/availability        tables free for date/time/minutes/party
 * POST restaurant/reservations · PUT restaurant/reservations/{id}
 * POST restaurant/reservations/{id}/{confirm|cancel|no_show|restore|seat}
 * POST restaurant/waitlist · PUT restaurant/waitlist/{id}
 * POST restaurant/waitlist/{id}/{notify|seat|left|back}
 */
class RestaurantReservationController extends BaseController
{
    public const ACTIONS = ['confirm', 'cancel', 'no_show', 'restore', 'seat'];

    public const WAIT_ACTIONS = ['notify', 'seat', 'left', 'back'];

    public function __construct(private ReservationService $reservations)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        if (! $this->isReady()) {
            return response()->json(['ready' => false]);
        }
        $date = $this->dateParam($request->input('date'));
        $dayStart = $date->copy()->startOfDay();
        $s = RestaurantSetting::current();

        $rows = $this->scoped(RestaurantReservation::query())->with('table:id,name')
            ->where('starts_at', '>=', $dayStart)->where('starts_at', '<', $dayStart->copy()->addDay())
            ->orderBy('starts_at')->orderBy('id')->get();
        $areas = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $tables = RestaurantTable::whereIn('area_id', $areas->pluck('id'))->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'area_id', 'name', 'seats', 'status', 'merged_into_id']);

        $waitlist = $this->scoped(RestaurantWaitlistEntry::query())
            ->where(fn ($q) => $q->whereIn('status', RestaurantWaitlistEntry::ACTIVE)->orWhere('updated_at', '>=', now()->subHours(3)))
            ->orderByRaw("CASE WHEN status IN ('waiting','notified') THEN 0 ELSE 1 END")->orderBy('created_at')->limit(100)->get();

        $weekEnd = $dayStart->copy()->addDays(7);
        // Same bookings the day header counts (completed ones included).
        $week = $this->scoped(RestaurantReservation::query())->whereIn('status', array_merge(RestaurantReservation::ACTIVE, ['completed']))
            ->where('starts_at', '>=', $dayStart)->where('starts_at', '<', $weekEnd)
            ->selectRaw('DATE(starts_at) AS d, COUNT(*) AS n, SUM(party_size) AS covers')->groupByRaw('DATE(starts_at)')->get()->keyBy('d');

        $active = $rows->whereIn('status', RestaurantReservation::ACTIVE);

        return response()->json([
            'ready' => true,
            'date' => $date->format('Y-m-d'),
            'today' => now()->format('Y-m-d'),
            'server_time' => now()->format('Y-m-d H:i'),
            'reservations' => $rows->map(fn ($r) => $this->present($r, $s))->values(),
            'waitlist' => $waitlist->map(fn ($e) => $this->presentWaiting($e, $areas))->values(),
            'areas' => $areas,
            'tables' => $tables->map(fn ($t) => ['id' => $t->id, 'area_id' => $t->area_id, 'name' => $t->name, 'seats' => $t->seats, 'status' => $t->status, 'joined' => (bool) $t->merged_into_id])->values(),
            'counts' => [
                'bookings' => $active->count() + $rows->where('status', 'completed')->count(),
                'covers' => (int) $active->sum('party_size') + (int) $rows->where('status', 'completed')->sum('party_size'),
                'to_arrive' => $rows->whereIn('status', RestaurantReservation::UPCOMING)->count(),
                'seated' => $rows->where('status', 'seated')->count(),
                'no_show' => $rows->where('status', 'no_show')->count(),
                'cancelled' => $rows->where('status', 'cancelled')->count(),
                'waiting' => $waitlist->whereIn('status', RestaurantWaitlistEntry::ACTIVE)->count(),
            ],
            'week' => collect(range(0, 6))->map(function ($i) use ($dayStart, $week) {
                $d = $dayStart->copy()->addDays($i)->format('Y-m-d');

                return ['date' => $d, 'bookings' => (int) ($week[$d]->n ?? 0), 'covers' => (int) ($week[$d]->covers ?? 0)];
            }),
            'settings' => $this->settings($s),
        ]);
    }

    public function search(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $term = trim((string) $request->input('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }
        $like = '%'.$term.'%';
        $s = RestaurantSetting::current();
        $rows = $this->scoped(RestaurantReservation::query())->with('table:id,name')
            ->where('starts_at', '>=', now()->subDays(1)->startOfDay())
            ->where(fn ($q) => $q->where('customer_name', 'like', $like)->orWhere('customer_phone', 'like', $like)->orWhere('customer_email', 'like', $like))
            ->orderBy('starts_at')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r, $s))->values()]);
    }

    public function availability(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $v = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => 'nullable|integer|min:15|max:720',
            'party_size' => 'required|integer|min:1|max:500',
            'exclude_id' => 'nullable|integer',
        ]);
        $start = Carbon::createFromFormat('Y-m-d H:i', $v['date'].' '.$v['time'], config('app.timezone'))->second(0);
        $end = $start->copy()->addMinutes((int) ($v['minutes'] ?? 0) ?: (int) RestaurantSetting::current()->reservation_minutes ?: 90);
        $areaIds = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->where('is_active', true)->pluck('id');

        return response()->json([
            'tables' => $this->reservations->availability(RestaurantTable::query()->whereIn('area_id', $areaIds), $start, $end, (int) $v['party_size'], $v['exclude_id'] ?? null),
            'ends_at' => $end->format('H:i'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $data = $this->bookingData($request);

        return $this->run(fn () => $this->reservations->save(null, $data, optional($request->user('api'))->id), fn ($r) => ['reservation' => $this->present($r->fresh('table'))], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $r = $this->findReservation($id);
        $data = $this->bookingData($request);

        return $this->run(fn () => $this->reservations->save($r->id, $data, optional($request->user('api'))->id), fn ($r) => ['reservation' => $this->present($r->fresh('table'))]);
    }

    public function action(Request $request, $id, string $action)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $r = $this->findReservation($id);
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
            'table_id' => 'nullable|integer',
            'waiter_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        if (! empty($data['table_id'])) {
            $this->assertTableVisible((int) $data['table_id']);
        } elseif ($action === 'seat' && $r->table_id) {
            // Seating at the booking's own table: that table must be one of the user's too.
            $this->assertTableVisible((int) $r->table_id);
        }
        $me = optional($request->user('api'))->id;

        return $this->run(fn () => match ($action) {
            'confirm' => $this->reservations->confirm($r->id),
            'cancel' => $this->reservations->cancel($r->id, $data['reason'] ?? null),
            'no_show' => $this->reservations->noShow($r->id),
            'restore' => $this->reservations->restore($r->id),
            'seat' => $this->reservations->seat($r->id, $data['table_id'] ?? null, $data['waiter_id'] ?? $me),
        }, fn ($r) => ['reservation' => $this->present($r->fresh('table'))]);
    }

    // ------------------------------------------------------------------ waitlist

    public function waitStore(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $data = $this->waitData($request);

        return $this->run(fn () => $this->reservations->addWaiting($data, optional($request->user('api'))->id), fn ($e) => ['entry' => $this->presentWaiting($e->fresh())], 201);
    }

    public function waitUpdate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $e = $this->findWaiting($id);
        $data = $this->waitData($request);

        return $this->run(fn () => $this->reservations->updateWaiting($e->id, $data), fn ($e) => ['entry' => $this->presentWaiting($e->fresh())]);
    }

    public function waitAction(Request $request, $id, string $action)
    {
        $this->authorizeForUser($request->user('api'), 'rst_reservations', RestaurantSetting::class);
        $this->ready();
        $e = $this->findWaiting($id);
        $data = $request->validate(['table_id' => 'nullable|integer', 'waiter_id' => ['nullable', 'integer', 'exists:users,id']]);
        if ($action === 'seat') {
            if (empty($data['table_id'])) {
                return response()->json(['message' => __('Choose a table for this party.')], 422);
            }
            $this->assertTableVisible((int) $data['table_id']);
        }

        return $this->run(fn () => match ($action) {
            'notify' => $this->reservations->notify($e->id),
            'seat' => $this->reservations->seatWaiting($e->id, (int) $data['table_id'], $data['waiter_id'] ?? optional($request->user('api'))->id),
            'left' => $this->reservations->leave($e->id),
            'back' => $this->reservations->leave($e->id, true),
        }, fn ($e) => ['entry' => $this->presentWaiting($e->fresh())]);
    }

    // ------------------------------------------------------------------

    private function bookingData(Request $request): array
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'nullable|string|max:40',
            'customer_email' => 'nullable|email|max:191',
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'party_size' => 'required|integer|min:1|max:500',
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => 'nullable|integer|min:15|max:720',
            'table_id' => 'nullable|integer',
            'area_id' => 'nullable|integer',
            'source' => 'nullable|in:'.implode(',', RestaurantReservation::SOURCES),
            'note' => 'nullable|string|max:500',
        ]);
        if (! empty($data['table_id'])) {
            $this->assertTableVisible((int) $data['table_id']);
        }
        if (! empty($data['area_id']) && ! $this->areaVisible((int) $data['area_id'])) {
            abort(404, __('This area no longer exists.'));
        }

        return $data;
    }

    private function waitData(Request $request): array
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'nullable|string|max:40',
            'party_size' => 'required|integer|min:1|max:500',
            'quoted_minutes' => 'nullable|integer|min:0|max:600',
            'area_id' => 'nullable|integer',
            'note' => 'nullable|string|max:255',
        ]);
        if (! empty($data['area_id']) && ! $this->areaVisible((int) $data['area_id'])) {
            abort(404, __('This area no longer exists.'));
        }

        return $data;
    }

    private function present(RestaurantReservation $r, ?RestaurantSetting $s = null): array
    {
        $s ??= RestaurantSetting::current();
        $now = now();
        $upcoming = $r->isUpcoming();
        $minutesTo = (int) floor(($r->starts_at->getTimestamp() - $now->getTimestamp()) / 60);

        return [
            'id' => $r->id,
            'customer_name' => $r->customer_name,
            'customer_phone' => $r->customer_phone,
            'customer_email' => $r->customer_email,
            'client_id' => $r->client_id,
            'party_size' => $r->party_size,
            'date' => $r->starts_at->format('Y-m-d'),
            'time' => $r->starts_at->format('H:i'),
            'end_time' => $r->ends_at->format('H:i'),
            'minutes' => (int) round(($r->ends_at->getTimestamp() - $r->starts_at->getTimestamp()) / 60),
            'table' => $r->table ? ['id' => $r->table->id, 'name' => $r->table->name] : null,
            'area_id' => $r->area_id,
            'status' => $r->status,
            'source' => $r->source,
            'note' => $r->note,
            'order_id' => $r->order_id,
            'cancel_reason' => $r->cancel_reason,
            'seated_at' => optional($r->seated_at)->format('H:i'),
            'minutes_to' => $minutesTo,
            // Due within the hold window, late (past the time), or past the no-show limit.
            'soon' => $upcoming && $minutesTo <= (int) $s->reservation_hold_minutes,
            'late' => $upcoming && $minutesTo < 0,
            'no_show_due' => $upcoming && -$minutesTo >= (int) $s->no_show_minutes,
        ];
    }

    private function presentWaiting(RestaurantWaitlistEntry $e, $areas = null): array
    {
        $areaName = $e->area_id ? ($areas ? optional($areas->firstWhere('id', $e->area_id))->name : optional(RestaurantArea::find($e->area_id))->name) : null;
        $waited = (int) $e->created_at->diffInMinutes($e->seated_at ?? $e->left_at ?? now(), true);

        return [
            'id' => $e->id,
            'customer_name' => $e->customer_name,
            'customer_phone' => $e->customer_phone,
            'party_size' => $e->party_size,
            'quoted_minutes' => $e->quoted_minutes,
            'waited_minutes' => $waited,
            'over_quote' => $e->quoted_minutes !== null && in_array($e->status, RestaurantWaitlistEntry::ACTIVE, true) && $waited > $e->quoted_minutes,
            'area_id' => $e->area_id,
            'area' => $areaName,
            'note' => $e->note,
            'status' => $e->status,
            'table_id' => $e->table_id,
            'added_at' => $e->created_at->format('H:i'),
            'notified_at' => optional($e->notified_at)->format('H:i'),
        ];
    }

    private function settings(RestaurantSetting $s): array
    {
        return [
            'reservation_minutes' => (int) $s->reservation_minutes,
            'reservation_hold_minutes' => (int) $s->reservation_hold_minutes,
            'no_show_minutes' => (int) $s->no_show_minutes,
            'max_party_size' => (int) $s->max_party_size,
            'require_guest_count' => (bool) $s->require_guest_count,
        ];
    }

    private function run(callable $fn, callable $ok, int $status = 200)
    {
        try {
            $result = $fn();
        } catch (ReservationException|FloorException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        } catch (\Illuminate\Database\QueryException $e) {
            // Two people booking the same table at the same moment: one wins, the other retries.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                return response()->json(['message' => __('Someone else changed this table at the same moment. Please try again.')], 409);
            }
            throw $e;
        }

        return response()->json($ok($result), $status);
    }

    private function isReady(): bool
    {
        return RestaurantArea::tablesReady() && RestaurantReservation::tablesReady();
    }

    private function ready(): void
    {
        if (! $this->isReady()) {
            abort(503, 'Run the latest database update first.');
        }
    }

    private function dateParam($v): Carbon
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $v, config('app.timezone'))->startOfDay();
            } catch (\Throwable $e) {
            }
        }

        return now()->startOfDay();
    }

    /** Staff limited to some warehouses only see that branch's bookings (bookings without one are shared). */
    private function scoped($q)
    {
        return $this->scopeToWarehouses($q, 'warehouse_id', true);
    }

    private function findReservation($id): RestaurantReservation
    {
        $r = RestaurantReservation::find((int) $id);
        if (! $r || ! $this->scoped(RestaurantReservation::query())->whereKey($r->id)->exists()) {
            abort(404, __('This booking no longer exists.'));
        }

        return $r;
    }

    private function findWaiting($id): RestaurantWaitlistEntry
    {
        $e = RestaurantWaitlistEntry::find((int) $id);
        if (! $e || ! $this->scoped(RestaurantWaitlistEntry::query())->whereKey($e->id)->exists()) {
            abort(404, __('This party is no longer on the waitlist.'));
        }

        return $e;
    }

    private function areaVisible(int $areaId): bool
    {
        return $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->whereKey($areaId)->exists();
    }

    private function assertTableVisible(int $tableId): void
    {
        $t = RestaurantTable::find($tableId);
        if (! $t || ! $this->areaVisible($t->area_id)) {
            abort(404, __('This table no longer exists.'));
        }
    }
}
