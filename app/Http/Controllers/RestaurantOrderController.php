<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantDishDetail;
use App\Models\RestaurantKitchenTicket;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\Restaurant\BillingService;
use App\Services\Restaurant\FloorException;
use App\Services\Restaurant\MenuService;
use App\Services\Restaurant\OrderException;
use App\Services\Restaurant\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant Management › waiter order screen.
 * rst_orders_take: menu, orders, open a table, add / change / remove new
 * dishes, send to the kitchen. rst_orders_void: void a dish the kitchen has
 * seen, cancel an order.
 */
class RestaurantOrderController extends BaseController
{
    public function __construct(private OrderService $orders, private MenuService $menu)
    {
    }

    /** GET restaurant/pos/menu → {categories, dishes, settings} */
    public function menu(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();

        $menu = $this->menu->catalog();

        return response()->json([
            'categories' => $menu['categories'],
            'dishes' => $menu['dishes'],
            'settings' => [
                'order_types' => RestaurantSetting::current()->orderTypes(),
            ],
        ]);
    }

    /** GET restaurant/orders?type=&status=open → open orders (dine-in tables + takeaway / delivery). */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $status = in_array($request->input('status'), RestaurantOrder::STATUSES, true) ? $request->input('status') : 'open';
        $q = RestaurantOrder::with(['table:id,name', 'waiter:id,username,firstname,lastname'])
            ->where('status', $status)
            ->when(in_array($request->input('type'), RestaurantOrder::TYPES, true), fn ($w) => $w->where('type', $request->input('type')))
            ->when($request->filled('search'), fn ($w) => $w->where(fn ($x) => $x->where('ref', 'like', '%'.$request->search.'%')
                ->orWhere('customer_name', 'like', '%'.$request->search.'%')->orWhere('customer_phone', 'like', '%'.$request->search.'%')))
            ->orderByDesc('opened_at')->limit(200);
        $q = $this->scopeOrders($q);
        $orders = $q->get();
        $counts = RestaurantOrderItem::whereIn('order_id', $orders->pluck('id'))->where('status', '!=', 'void')
            ->select('order_id', DB::raw('COUNT(*) AS c'), DB::raw("SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS n"))
            ->groupBy('order_id')->get()->keyBy('order_id');

        return response()->json(['data' => $orders->map(fn ($o) => [
            'id' => $o->id, 'ref' => $o->ref, 'type' => $o->type, 'status' => $o->status, 'source' => $o->source ?? 'staff',
            'table' => $o->table ? ['id' => $o->table->id, 'name' => $o->table->name] : null,
            'customer_name' => $o->customer_name, 'customer_phone' => $o->customer_phone,
            'waiter' => $o->waiter ? $this->userName($o->waiter) : null,
            'subtotal' => (float) $o->subtotal,
            'items' => (int) ($counts[$o->id]->c ?? 0),
            'new_items' => (int) ($counts[$o->id]->n ?? 0),
            'opened_at' => optional($o->opened_at)->toIso8601String(),
            'minutes' => $o->opened_at ? (int) $o->opened_at->diffInMinutes(now(), true) : null,
        ])->values()]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();

        return response()->json(['order' => $this->present($this->findOrder($id))]);
    }

    /** POST restaurant/tables/{tableId}/order {guests?} — the table's open order (seats a free table first). */
    public function openTable(Request $request, $tableId)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $data = $request->validate(['guests' => 'nullable|integer|min:1|max:500', 'waiter_id' => ['nullable', 'integer', 'exists:users,id']]);
        $table = RestaurantTable::find((int) $tableId);
        if (! $table || ! $this->tableVisible($table)) {
            return response()->json(['message' => __('This table no longer exists.')], 404);
        }
        // The waiter picked on the floor plan (default: whoever opens it).
        $waiter = $data['waiter_id'] ?? optional($request->user('api'))->id;

        return $this->run(fn () => $this->orders->openForTable($table->id, $data['guests'] ?? null, $waiter), fn ($o) => ['order' => $this->present($o->fresh())]);
    }

    /** POST restaurant/orders {type: takeaway|delivery, customer_name, customer_phone, delivery_address, note} */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $data = $request->validate([
            'type' => 'required|in:takeaway,delivery',
            'customer_name' => 'nullable|string|max:120',
            'customer_phone' => 'nullable|string|max:40',
            'delivery_address' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:500',
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        // The order belongs to a branch this user works in: the restaurant's
        // default when it is one of theirs, else their first branch.
        $scope = $this->warehouseScopeIds();
        $default = (int) RestaurantSetting::current()->default_warehouse_id;
        $data['warehouse_id'] = $scope === null
            ? ($default ?: null)
            : (in_array($default, array_map('intval', $scope), true) ? $default : ((int) ($scope[0] ?? 0) ?: null));

        return $this->run(fn () => $this->orders->createStandalone($data['type'], $data, optional($request->user('api'))->id), fn ($o) => ['order' => $this->present($o->fresh())], 201);
    }

    /** PUT restaurant/orders/{id} {guests?, note?, customer_name?, customer_phone?, delivery_address?} */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $data = $request->validate([
            'guests' => 'nullable|integer|min:1|max:500',
            'note' => 'nullable|string|max:500',
            'customer_name' => 'nullable|string|max:120',
            'customer_phone' => 'nullable|string|max:40',
            'delivery_address' => 'nullable|string|max:1000',
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        return $this->run(fn () => $this->orders->update($order->id, $data), fn ($o) => ['order' => $this->present($o->fresh())]);
    }

    /** POST restaurant/orders/{id}/items {items: [{product_id, variant_id?, qty, option_ids[], note?, seat?, course?, hold?}]} */
    public function addItems(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $data = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.qty' => 'required|numeric|min:0.001|max:999',
            'items.*.option_ids' => 'nullable|array|max:40',
            'items.*.option_ids.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.seat' => 'nullable|integer|min:1|max:99',
            'items.*.course' => 'nullable|integer|min:1|max:5',
            'items.*.hold' => 'nullable|boolean',
        ]);

        return $this->run(fn () => $this->orders->addItems($order->id, $data['items'], optional($request->user('api'))->id), fn ($o) => ['order' => $this->present($o->fresh())], 201);
    }

    /** PUT restaurant/orders/{id}/items/{itemId} {qty?, note?, seat?, course?, hold?} — new lines only */
    public function updateItem(Request $request, $id, $itemId)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $data = $request->validate([
            'qty' => 'sometimes|numeric|min:0.001|max:999',
            'note' => 'sometimes|nullable|string|max:255',
            'seat' => 'sometimes|nullable|integer|min:1|max:99',
            'course' => 'sometimes|integer|min:1|max:5',
            'hold' => 'sometimes|boolean',
        ]);

        return $this->run(fn () => $this->orders->updateItem($order->id, (int) $itemId, $data), fn () => ['order' => $this->present($order->fresh())]);
    }

    /** DELETE restaurant/orders/{id}/items/{itemId} — new lines only */
    public function removeItem(Request $request, $id, $itemId)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);

        return $this->run(fn () => $this->orders->removeItem($order->id, (int) $itemId), fn () => ['order' => $this->present($order->fresh())]);
    }

    /** POST restaurant/orders/{id}/items/{itemId}/void {reason} */
    public function voidItem(Request $request, $id, $itemId)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_void', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(fn () => $this->orders->voidItem($order->id, (int) $itemId, $reason, optional($request->user('api'))->id), fn () => ['order' => $this->present($order->fresh())]);
    }

    /** POST restaurant/orders/{id}/send {course?} — new lines (or one held course) to the kitchen */
    public function send(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $course = $request->validate(['course' => 'nullable|integer|min:1|max:5'])['course'] ?? null;

        return $this->run(fn () => $this->orders->send($order->id, optional($request->user('api'))->id, $course), fn ($t) => ['ticket' => ['id' => $t->id, 'ref' => $t->ref], 'order' => $this->present($order->fresh())], 201);
    }

    /** POST restaurant/orders/{id}/cancel {reason} */
    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_void', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(fn () => $this->orders->cancel($order->id, $reason, optional($request->user('api'))->id), fn ($o) => ['order' => $this->present($o->fresh())]);
    }

    /** POST restaurant/orders/{id}/served — the waiter picked up the dishes the kitchen marked ready. */
    public function served(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_orders_take', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $n = app(\App\Services\Restaurant\KitchenService::class)->servedForOrder($order->id);
        if (! $n) {
            return response()->json(['message' => __('Nothing on this order is ready yet.')], 422);
        }

        return response()->json(['served' => $n, 'order' => $this->present($order->fresh())]);
    }

    // ------------------------------------------------------------------

    private function run(callable $fn, callable $ok, int $status = 200)
    {
        try {
            $result = $fn();
        } catch (OrderException|FloorException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        } catch (\Illuminate\Database\QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                return response()->json(['message' => __('Someone else changed this order at the same moment. Please try again.')], 409);
            }
            throw $e;
        }

        return response()->json($ok($result), $status);
    }

    private function ready(): void
    {
        if (! RestaurantOrder::tablesReady()) {
            abort(503, 'Run the latest database update first.');
        }
    }

    private function findOrder($id): RestaurantOrder
    {
        $order = RestaurantOrder::find((int) $id);
        if (! $order || ! $this->orderVisible($order)) {
            abort(404, __('This order no longer exists.'));
        }

        return $order;
    }

    /** Staff limited to some warehouses only see orders of those (orders without a warehouse are shared). */
    private function scopeOrders($q)
    {
        return $this->scopeToWarehouses($q, 'warehouse_id', true);
    }

    private function orderVisible(RestaurantOrder $o): bool
    {
        return $this->scopeOrders(RestaurantOrder::query())->whereKey($o->id)->exists();
    }

    private function tableVisible(RestaurantTable $t): bool
    {
        return $this->scopeToWarehouses(\App\Models\RestaurantArea::withTrashed(), 'warehouse_id', true)->whereKey($t->area_id)->exists();
    }

    private function present(RestaurantOrder $o): array
    {
        $o->loadMissing(['table:id,name,guests', 'waiter:id,username,firstname,lastname']);
        $items = RestaurantOrderItem::where('order_id', $o->id)->orderBy('course')->orderBy('id')->get();
        $tickets = RestaurantKitchenTicket::where('order_id', $o->id)->orderBy('id')->get(['id', 'ref', 'status', 'course', 'sent_at']);
        $voiders = User::whereIn('id', $items->pluck('voided_by')->filter()->unique())->get(['id', 'username', 'firstname', 'lastname'])->keyBy('id');

        return [
            'id' => $o->id,
            'ref' => $o->ref,
            'type' => $o->type,
            'status' => $o->status,
            'table' => $o->table ? ['id' => $o->table->id, 'name' => $o->table->name] : null,
            'guests' => $o->guests,
            'waiter' => $o->waiter ? ['id' => $o->waiter->id, 'name' => $this->userName($o->waiter)] : null,
            'customer_name' => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'delivery_address' => $o->delivery_address,
            'note' => $o->note,
            'subtotal' => (float) $o->subtotal,
            'opened_at' => optional($o->opened_at)->toIso8601String(),
            'minutes' => $o->opened_at ? (int) $o->opened_at->diffInMinutes(now(), true) : null,
            'cancel_reason' => $o->cancel_reason,
            'source' => $o->source ?? 'staff',
            'items' => $items->map(fn (RestaurantOrderItem $i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id, 'name' => $i->name,
                'qty' => (float) $i->qty, 'unit_price' => (float) $i->unit_price, 'addons_price' => (float) $i->addons_price,
                'line_total' => (float) $i->line_total, 'addons_label' => $i->addons_label, 'note' => $i->note,
                'seat' => $i->seat, 'course' => $i->course, 'hold' => $i->hold, 'status' => $i->status,
                'sent_at' => optional($i->sent_at)->toIso8601String(),
                'void_reason' => $i->void_reason,
                'paid' => (bool) $i->sale_id,
                'voided_by' => $i->voided_by && isset($voiders[$i->voided_by]) ? $this->userName($voiders[$i->voided_by]) : null,
            ])->values(),
            'tickets' => $tickets->map(fn ($t) => ['id' => $t->id, 'ref' => $t->ref, 'status' => $t->status, 'course' => $t->course, 'sent_at' => optional($t->sent_at)->toIso8601String()])->values(),
            'held_courses' => $items->where('status', 'new')->where('hold', true)->pluck('course')->unique()->sort()->values(),
            'new_count' => $items->where('status', 'new')->where('hold', false)->count(),
            'ready_count' => $items->where('status', 'ready')->count(),
            'unpaid_count' => $items->where('status', '!=', 'void')->whereNull('sale_id')->count(),
        ];
    }

    private function userName(User $u): string
    {
        return trim($u->firstname.' '.$u->lastname) ?: (string) $u->username;
    }
}
