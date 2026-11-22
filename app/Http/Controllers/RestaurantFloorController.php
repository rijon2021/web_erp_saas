<?php

namespace App\Http\Controllers;

use App\Models\RestaurantArea;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\Restaurant\FloorException;
use App\Services\Restaurant\FloorService;
use Illuminate\Http\Request;

/**
 * Restaurant Management › Floor plan (permission rst_floor): the live table
 * map and the table actions (seat, bill requested, clear, clean, hold,
 * move, join). The page polls GET restaurant/floor.
 */
class RestaurantFloorController extends BaseController
{
    public const ACTIONS = ['seat', 'bill', 'resume', 'clear', 'clean', 'reserve', 'unreserve', 'move', 'merge', 'unmerge'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_floor', RestaurantSetting::class);
        if (! RestaurantArea::tablesReady()) {
            return response()->json(['ready' => false, 'areas' => []]);
        }

        $areas = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)
            ->where('is_active', true)
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'), 'tables.waiter:id,username,firstname,lastname'])
            ->orderBy('sort_order')->orderBy('id')->get();

        $names = $areas->flatMap->tables->pluck('name', 'id');
        // Open order per table (phase 4): ref, dishes, running total, dishes not yet sent.
        $orders = collect();
        if (\App\Models\RestaurantOrder::tablesReady()) {
            $orders = \App\Models\RestaurantOrder::where('status', 'open')->whereIn('table_id', $names->keys())->get(['id', 'ref', 'table_id', 'subtotal'])->keyBy('table_id');
            $lineCounts = \App\Models\RestaurantOrderItem::whereIn('order_id', $orders->pluck('id'))->where('status', '!=', 'void')
                ->select('order_id', \DB::raw('COUNT(*) AS c'), \DB::raw("SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS n"), \DB::raw("SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS r"))
                ->groupBy('order_id')->get()->keyBy('order_id');
            $orders = $orders->map(fn ($o) => ['id' => $o->id, 'ref' => $o->ref, 'subtotal' => (float) $o->subtotal, 'items' => (int) ($lineCounts[$o->id]->c ?? 0), 'new_items' => (int) ($lineCounts[$o->id]->n ?? 0), 'ready_items' => (int) ($lineCounts[$o->id]->r ?? 0)]);
        }
        $children = $areas->flatMap->tables->whereNotNull('merged_into_id')->groupBy('merged_into_id');
        $counts = array_fill_keys(RestaurantTable::STATUSES, 0);
        $now = now();
        $s = RestaurantSetting::current();

        // Next booking per table today (phase 7): parties not yet seated, from
        // those past the no-show limit to the end of the day.
        $bookings = collect();
        $waiting = 0;
        if (\App\Models\RestaurantReservation::tablesReady()) {
            $bookings = \App\Models\RestaurantReservation::whereIn('table_id', $names->keys())
                ->whereIn('status', \App\Models\RestaurantReservation::UPCOMING)
                ->where('starts_at', '>=', $now->copy()->subMinutes(max(60, (int) $s->no_show_minutes + 60)))
                ->where('starts_at', '<', $now->copy()->endOfDay())
                ->orderBy('starts_at')->get(['id', 'table_id', 'customer_name', 'party_size', 'starts_at', 'status'])
                ->groupBy('table_id')
                ->map(function ($rows) use ($now, $s) {
                    $r = $rows->first();
                    $to = (int) floor(($r->starts_at->getTimestamp() - $now->getTimestamp()) / 60);

                    return [
                        'id' => $r->id, 'name' => $r->customer_name, 'party_size' => $r->party_size, 'time' => $r->starts_at->format('H:i'),
                        'confirmed' => $r->status === 'confirmed', 'minutes_to' => $to,
                        'soon' => $to <= (int) $s->reservation_hold_minutes, 'late' => $to < 0,
                    ];
                });
            $waiting = $this->scopeToWarehouses(\App\Models\RestaurantWaitlistEntry::query(), 'warehouse_id', true)
                ->whereIn('status', \App\Models\RestaurantWaitlistEntry::ACTIVE)->count();
        }

        $out = $areas->map(function (RestaurantArea $a) use ($names, $children, &$counts, $now, $orders, $bookings) {
            return [
                'id' => $a->id,
                'name' => $a->name,
                'tables' => $a->tables->map(function (RestaurantTable $t) use ($names, $children, &$counts, $now, $orders, $bookings) {
                    $counts[$t->status] = ($counts[$t->status] ?? 0) + 1;

                    return [
                        'id' => $t->id,
                        'name' => $t->name,
                        'seats' => $t->seats,
                        'shape' => $t->shape,
                        'status' => $t->status,
                        'guests' => $t->guests,
                        'seated_at' => optional($t->seated_at)->toIso8601String(),
                        'minutes' => $t->seated_at ? (int) $t->seated_at->diffInMinutes($now, true) : null,
                        'since_minutes' => $t->status_changed_at ? (int) $t->status_changed_at->diffInMinutes($now, true) : null,
                        'waiter' => $t->waiter ? ['id' => $t->waiter->id, 'name' => $this->userName($t->waiter)] : null,
                        'merged_into' => $t->merged_into_id ? ['id' => $t->merged_into_id, 'name' => $names[$t->merged_into_id] ?? '?'] : null,
                        'merged' => ($children[$t->id] ?? collect())->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                        'note' => $t->note,
                        'order' => $orders[$t->id] ?? null,
                        'booking' => $bookings[$t->id] ?? null,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'ready' => true,
            'areas' => $out,
            'counts' => $counts,
            'waiting' => $waiting,
            'settings' => [
                'require_guest_count' => $s->require_guest_count,
                'after_payment_table_status' => $s->after_payment_table_status,
            ],
            'waiters' => User::where('statut', 1)->orderBy('username')->get(['id', 'username', 'firstname', 'lastname'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $this->userName($u)]),
            'me' => optional($request->user('api'))->id,
            'server_time' => $now->toIso8601String(),
        ]);
    }

    /** POST restaurant/floor/tables/{id}/{action} */
    public function action(Request $request, FloorService $floor, $id, string $action)
    {
        $this->authorizeForUser($request->user('api'), 'rst_floor', RestaurantSetting::class);
        if (! RestaurantArea::tablesReady()) {
            return response()->json(['message' => 'Run the latest database update first.'], 503);
        }
        $table = RestaurantTable::find((int) $id);
        if (! $table || ! $this->areaVisible($table->area_id)) {
            return response()->json(['message' => __('This table no longer exists.')], 404);
        }

        $data = $request->validate([
            'guests' => 'nullable|integer|min:1|max:500',
            'waiter_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => 'nullable|string|max:191',
            'to_table_id' => 'nullable|integer',
            'table_ids' => 'nullable|array|max:20',
            'table_ids.*' => 'integer',
        ]);
        foreach (array_filter(array_merge([$data['to_table_id'] ?? null], $data['table_ids'] ?? [])) as $otherId) {
            $other = RestaurantTable::find((int) $otherId);
            if (! $other || ! $this->areaVisible($other->area_id)) {
                return response()->json(['message' => __('This table no longer exists.')], 404);
            }
        }

        try {
            $result = match ($action) {
                'seat' => $floor->seat($table->id, $data['guests'] ?? null, $data['waiter_id'] ?? optional($request->user('api'))->id, $data['note'] ?? null),
                'bill' => $floor->setBillRequested($table->id, true),
                'resume' => $floor->setBillRequested($table->id, false),
                'clear' => $floor->clear($table->id),
                'clean' => $floor->markClean($table->id),
                'reserve' => $floor->setReserved($table->id, true, $data['note'] ?? null),
                'unreserve' => $floor->setReserved($table->id, false),
                'move' => $floor->move($table->id, (int) ($data['to_table_id'] ?? 0)),
                'merge' => $floor->merge($table->id, $data['table_ids'] ?? []),
                'unmerge' => $floor->unmerge($table->id),
            };
        } catch (FloorException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json(['success' => true, 'table_id' => $result->id, 'status' => $result->status]);
    }

    private function areaVisible(int $areaId): bool
    {
        return $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->whereKey($areaId)->exists();
    }

    private function userName(User $u): string
    {
        return trim($u->firstname.' '.$u->lastname) ?: (string) $u->username;
    }
}
