<?php

namespace App\Http\Controllers;

use App\Models\RestaurantKitchenTicket;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantSetting;
use App\Models\RestaurantStation;
use App\Services\Restaurant\KitchenService;
use App\Services\Restaurant\OrderException;
use Illuminate\Http\Request;

/**
 * Restaurant Management › Kitchen display (permission rst_kitchen).
 * GET restaurant/kitchen?station_id= → open tickets (pending / preparing /
 * ready) + tickets served in the last 15 minutes (for recall).
 * Dishes without a station show on every station's screen.
 */
class RestaurantKitchenController extends BaseController
{
    public function __construct(private KitchenService $kitchen)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_kitchen', RestaurantSetting::class);
        $this->ready();
        $stationId = $request->filled('station_id') ? (int) $request->station_id : null;
        $late = RestaurantSetting::current()->late_ticket_minutes ?: 20;
        $now = now();
        // rst_orders.source arrives with the phase 8 migration.
        $orderCols = 'order:id,ref,type,table_id,guests,waiter_id,customer_name,customer_phone,note'.(\Illuminate\Support\Facades\Schema::hasColumn('rst_orders', 'source') ? ',source' : '');

        $tickets = RestaurantKitchenTicket::query()
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'preparing', 'ready'])
                ->orWhere(fn ($w) => $w->where('status', 'served')->where('served_at', '>=', $now->copy()->subMinutes(15))))
            ->whereHas('order', fn ($q) => $this->scopeToWarehouses($q->where('status', '!=', 'cancelled'), 'warehouse_id', true))
            ->with([$orderCols, 'order.table:id,name', 'order.waiter:id,username,firstname,lastname'])
            ->orderBy('sent_at')->limit(200)->get();
        $items = RestaurantOrderItem::whereIn('ticket_id', $tickets->pluck('id'))->orderBy('course')->orderBy('id')->get()->groupBy('ticket_id');
        $stationNames = RestaurantStation::withTrashed()->pluck('name', 'id');

        $counts = ['all' => 0];
        $out = [];
        foreach ($tickets as $t) {
            $lines = ($items[$t->id] ?? collect())->where('status', '!=', 'void');
            foreach ($lines->whereIn('status', ['sent', 'preparing'])->pluck('station_id')->unique() as $sid) {
                $key = $sid ? (string) $sid : 'none';
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            if (in_array($t->status, ['pending', 'preparing'], true)) {
                $counts['all']++;
            }
            if ($stationId) {
                $lines = $lines->filter(fn ($i) => $i->station_id === $stationId || $i->station_id === null);
                if ($lines->isEmpty()) {
                    continue;
                }
            }
            $age = $t->sent_at ? (int) $t->sent_at->diffInMinutes($now, true) : 0;
            $o = $t->order;
            $out[] = [
                'id' => $t->id,
                'ref' => $t->ref,
                'status' => $t->status,
                'course' => $t->course,
                'sent_at' => optional($t->sent_at)->toIso8601String(),
                'age_minutes' => $age,
                'ready_minutes' => $t->ready_at ? (int) $t->ready_at->diffInMinutes($now, true) : null,
                'served_minutes' => $t->served_at ? (int) $t->served_at->diffInMinutes($now, true) : null,
                'urgency' => $age >= $late ? 'late' : ($age >= (int) floor($late / 2) ? 'soon' : 'ok'),
                'order' => [
                    'id' => $o->id, 'ref' => $o->ref, 'type' => $o->type, 'guests' => $o->guests,
                    'table' => $o->table->name ?? null, 'customer_name' => $o->customer_name, 'note' => $o->note, 'source' => $o->source ?? 'staff',
                    'waiter' => $o->waiter ? (trim($o->waiter->firstname.' '.$o->waiter->lastname) ?: $o->waiter->username) : null,
                ],
                'items' => $lines->values()->map(fn (RestaurantOrderItem $i) => [
                    'id' => $i->id, 'name' => $i->name, 'qty' => (float) $i->qty, 'addons_label' => $i->addons_label,
                    'note' => $i->note, 'seat' => $i->seat, 'course' => $i->course, 'status' => $i->status,
                    'station' => $i->station_id ? ($stationNames[$i->station_id] ?? null) : null,
                ])->all(),
            ];
        }

        return response()->json([
            'tickets' => $out,
            'counts' => $counts,
            'stations' => RestaurantStation::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'late_minutes' => $late,
            'server_time' => $now->toIso8601String(),
        ]);
    }

    /** POST restaurant/kitchen/tickets/{id}/{action} — start | bump | served | recall */
    public function ticketAction(Request $request, $id, string $action)
    {
        $this->authorizeForUser($request->user('api'), 'rst_kitchen', RestaurantSetting::class);
        $this->ready();
        $this->assertTicketVisible((int) $id);
        $stationId = $request->filled('station_id') ? (int) $request->input('station_id') : null;
        try {
            $t = match ($action) {
                'start' => $this->kitchen->start((int) $id),
                'bump' => $this->kitchen->bump((int) $id, $stationId),
                'served' => $this->kitchen->served((int) $id),
                'recall' => $this->kitchen->recall((int) $id),
            };
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json(['ticket' => ['id' => $t->id, 'status' => $t->status]]);
    }

    /** POST restaurant/kitchen/items/{id}/toggle {done: bool} */
    public function toggleItem(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_kitchen', RestaurantSetting::class);
        $this->ready();
        $done = (bool) $request->validate(['done' => 'required|boolean'])['done'];
        $item = RestaurantOrderItem::find((int) $id);
        if (! $item || ! $item->ticket_id) {
            return response()->json(['message' => __('This dish is no longer on a kitchen ticket.')], 404);
        }
        $this->assertTicketVisible($item->ticket_id);
        try {
            $t = $this->kitchen->toggleItem($item->id, $done);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json(['ticket' => ['id' => $t->id, 'status' => $t->status]]);
    }

    private function ready(): void
    {
        if (! RestaurantOrder::tablesReady()) {
            abort(503, 'Run the latest database update first.');
        }
    }

    private function assertTicketVisible(int $ticketId): void
    {
        $orderId = RestaurantKitchenTicket::whereKey($ticketId)->value('order_id');
        if (! $orderId || ! $this->scopeToWarehouses(RestaurantOrder::query(), 'warehouse_id', true)->whereKey($orderId)->exists()) {
            abort(404, __('This kitchen ticket no longer exists.'));
        }
    }
}
