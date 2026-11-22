<?php

namespace App\Services\Restaurant;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantArea;
use App\Models\RestaurantKitchenTicket;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant orders: the open check of a seated table, takeaway / delivery
 * orders, their dishes (priced through MenuService) and the kitchen tickets.
 *
 * Line life cycle: new → (send) sent → preparing → ready → served, or void.
 * Only "new" lines can be edited or removed; anything the kitchen has seen
 * is voided with a reason instead, so the record stays.
 */
class OrderService
{
    public function __construct(private MenuService $menu, private FloorService $floor)
    {
    }

    // ------------------------------------------------------------------ open

    /**
     * The open order of a table: seats the guests first when the table is
     * free / held, creates the order when the table has none yet.
     */
    public function openForTable(int $tableId, ?int $guests, ?int $waiterId): RestaurantOrder
    {
        return DB::transaction(function () use ($tableId, $guests, $waiterId) {
            $table = RestaurantTable::whereKey($tableId)->lockForUpdate()->first();
            if (! $table || ! $table->is_active) {
                throw new FloorException(__('This table no longer exists.'), 404);
            }
            if ($table->merged_into_id) {
                $table = RestaurantTable::whereKey($table->merged_into_id)->lockForUpdate()->firstOrFail();
            }
            if (in_array($table->status, ['free', 'reserved'], true)) {
                $table = $this->floor->seat($table->id, $guests, $waiterId);
            } elseif ($table->status === 'cleaning') {
                throw new FloorException(__('Table :table needs cleaning first.', ['table' => $table->name]));
            }
            $order = $this->openOrderOf($table->id);
            if ($order) {
                return $order;
            }
            $area = RestaurantArea::withTrashed()->find($table->area_id);

            $order = $this->create([
                'type' => 'dine_in',
                'table_id' => $table->id,
                'guests' => $table->guests,
                'waiter_id' => $table->waiter_id ?: $waiterId,
                'warehouse_id' => $area->warehouse_id ?? null,
            ]);
            // A booked party (phase 7): the order carries the guest's name and customer.
            if ($booking = app(ReservationService::class)->orderOpened($order)) {
                $order->update(array_filter([
                    'customer_name' => $booking->customer_name,
                    'customer_phone' => $booking->customer_phone,
                    'client_id' => $booking->client_id,
                ]));
            }

            return $order;
        });
    }

    /**
     * An order that arrives already priced and paid for elsewhere (the
     * online store, phase 8): saved closed as "paid" — it is never billed
     * here — with every line straight onto one kitchen ticket.
     *
     * @param  array  $lines  [{product_id, product_variant_id, name, qty, unit_price, addons_price, addons, addons_label, station_id, note?}]
     */
    public function createSent(array $attrs, array $lines): RestaurantOrder
    {
        return DB::transaction(function () use ($attrs, $lines) {
            $order = $this->create($attrs + ['status' => 'paid', 'closed_at' => now()]);
            $ticket = RestaurantKitchenTicket::create([
                'order_id' => $order->id, 'ref' => $order->ref.'-K1', 'status' => 'pending', 'sent_at' => now(),
            ]);
            foreach ($lines as $l) {
                $qty = max(0.001, (float) $l['qty']);
                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => (int) $l['product_id'],
                    'product_variant_id' => $l['product_variant_id'] ?? null,
                    'name' => mb_substr((string) $l['name'], 0, 191),
                    'qty' => $qty,
                    'unit_price' => round((float) $l['unit_price'], 2),
                    'addons_price' => round((float) ($l['addons_price'] ?? 0), 2),
                    'line_total' => round($qty * ((float) $l['unit_price'] + (float) ($l['addons_price'] ?? 0)), 2),
                    'addons' => $l['addons'] ?? [],
                    'addons_label' => ! empty($l['addons_label']) ? mb_substr($l['addons_label'], 0, 500) : null,
                    'note' => $this->str($l['note'] ?? null, 255),
                    'course' => 1,
                    'hold' => false,
                    'status' => 'sent',
                    'station_id' => $l['station_id'] ?? null,
                    'ticket_id' => $ticket->id,
                    'sent_at' => now(),
                ]);
            }
            $this->recalc($order);

            return $order->fresh();
        });
    }

    /** A takeaway or delivery order. */
    public function createStandalone(string $type, array $data, ?int $waiterId): RestaurantOrder
    {
        if (! in_array($type, ['takeaway', 'delivery'], true)) {
            throw new OrderException(__('Unknown order type.'));
        }
        if (! in_array($type, RestaurantSetting::current()->orderTypes(), true)) {
            throw new OrderException($type === 'delivery' ? __('Delivery orders are switched off in Restaurant Settings.') : __('Takeaway orders are switched off in Restaurant Settings.'));
        }
        if ($type === 'delivery' && trim((string) ($data['delivery_address'] ?? '')) === '') {
            throw new OrderException(__('Enter the delivery address.'));
        }

        return DB::transaction(fn () => $this->create(array_filter([
            'warehouse_id' => ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
        ]) + [
            'type' => $type,
            'waiter_id' => $waiterId,
            'client_id' => $data['client_id'] ?? null,
            'customer_name' => $this->str($data['customer_name'] ?? null, 120),
            'customer_phone' => $this->str($data['customer_phone'] ?? null, 40),
            'delivery_address' => $type === 'delivery' ? $this->str($data['delivery_address'] ?? null, 1000) : null,
            'note' => $this->str($data['note'] ?? null, 500),
        ]));
    }

    public function update(int $orderId, array $data): RestaurantOrder
    {
        return DB::transaction(function () use ($orderId, $data) {
            $order = $this->lockOpen($orderId);
            $changes = [];
            foreach (['customer_name' => 120, 'customer_phone' => 40, 'note' => 500, 'delivery_address' => 1000] as $k => $max) {
                if (array_key_exists($k, $data)) {
                    $changes[$k] = $this->str($data[$k], $max);
                }
            }
            if ($order->type === 'delivery' && array_key_exists('delivery_address', $changes) && ! $changes['delivery_address']) {
                throw new OrderException(__('Enter the delivery address.'));
            }
            if (array_key_exists('guests', $data)) {
                $changes['guests'] = $data['guests'] ? max(1, min(500, (int) $data['guests'])) : null;
                if ($order->table_id) {
                    RestaurantTable::whereKey($order->table_id)->update(['guests' => $changes['guests']]);
                }
            }
            if (array_key_exists('client_id', $data)) {
                $changes['client_id'] = $data['client_id'] ?: null;
            }
            $order->update($changes);

            return $order;
        });
    }

    // ------------------------------------------------------------------ lines

    /**
     * Add dishes: [{product_id, variant_id?, qty, option_ids[], note?, seat?, course?, hold?}, …].
     * Every line is checked (sold out, meal time, add-on rules) before any is saved.
     */
    public function addItems(int $orderId, array $lines, ?int $userId): RestaurantOrder
    {
        if (! $lines) {
            throw new OrderException(__('Add at least one dish.'));
        }

        return DB::transaction(function () use ($orderId, $lines, $userId) {
            $order = $this->lockOpen($orderId);
            $productIds = array_values(array_unique(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines)));
            $products = Product::whereIn('id', array_diff($productIds, BillingService::systemProductIds()))->whereNull('deleted_at')->where('is_active', 1)
                ->get(['id', 'name', 'price', 'type', 'is_variant'])->keyBy('id');
            $details = $this->menu->detailsFor($productIds);
            $periods = RestaurantSetting::current()->periodsAt();
            $rows = [];
            foreach ($lines as $l) {
                $p = $products[(int) ($l['product_id'] ?? 0)] ?? null;
                if (! $p) {
                    throw new OrderException(__('This dish is no longer on the menu.'));
                }
                $d = $details[$p->id] ?? null;
                if ($d && $d->isSoldOut()) {
                    throw new OrderException(__(':dish is sold out today.', ['dish' => $p->name]));
                }
                if (! $this->menu->availableNow($d, $periods)) {
                    throw new OrderException(__(':dish is not served at this time.', ['dish' => $p->name]));
                }
                $qty = round((float) ($l['qty'] ?? 1), 3);
                if ($qty <= 0 || $qty > 999) {
                    throw new OrderException(__('Enter a quantity between 1 and 999.'));
                }
                $name = $p->name;
                $unit = (float) $p->price;
                $variantId = ! empty($l['variant_id']) ? (int) $l['variant_id'] : null;
                if ($variantId) {
                    $v = ProductVariant::where('product_id', $p->id)->whereNull('deleted_at')->whereKey($variantId)->first(['id', 'name', 'price']);
                    if (! $v) {
                        throw new OrderException(__('Choose one of the dish\'s variants.'));
                    }
                    $unit = (float) $v->price;
                    $name .= ' ('.$v->name.')';
                } elseif ($p->type === 'is_variant' && ProductVariant::where('product_id', $p->id)->whereNull('deleted_at')->exists()) {
                    throw new OrderException(__('Choose a variant for :dish.', ['dish' => $p->name]));
                }
                try {
                    $sel = $this->menu->priceSelection($p->id, (array) ($l['option_ids'] ?? []));
                } catch (MenuException $e) {
                    throw new OrderException($p->name.': '.$e->getMessage());
                }
                $addons = (float) $sel['extra'];
                $rows[] = [
                    'order_id' => $order->id,
                    'product_id' => $p->id,
                    'product_variant_id' => $variantId,
                    'name' => mb_substr($name, 0, 191),
                    'qty' => $qty,
                    'unit_price' => round($unit, 2),
                    'addons_price' => round($addons, 2),
                    'line_total' => round($qty * ($unit + $addons), 2),
                    'addons' => $sel['options'],
                    'addons_label' => $sel['label'] !== '' ? mb_substr($sel['label'], 0, 500) : null,
                    'note' => $this->str($l['note'] ?? null, 255),
                    'seat' => ! empty($l['seat']) ? max(1, min(99, (int) $l['seat'])) : null,
                    'course' => max(1, min(5, (int) ($l['course'] ?? 1))),
                    'hold' => (bool) ($l['hold'] ?? false),
                    'status' => 'new',
                    'station_id' => $d->station_id ?? null,
                    'added_by' => $userId,
                ];
            }
            foreach ($rows as $row) {
                RestaurantOrderItem::create($row);
            }
            $this->recalc($order);

            return $order;
        });
    }

    /** Change a line that has not gone to the kitchen yet: qty, note, seat, course, hold. */
    public function updateItem(int $orderId, int $itemId, array $data): RestaurantOrderItem
    {
        return DB::transaction(function () use ($orderId, $itemId, $data) {
            $order = $this->lockOpen($orderId);
            $item = $this->item($order, $itemId);
            $this->assertUnpaid($item);
            if ($item->status !== 'new') {
                throw new OrderException(__('This dish has gone to the kitchen. Void it instead.'));
            }
            if (array_key_exists('qty', $data)) {
                $qty = round((float) $data['qty'], 3);
                if ($qty <= 0 || $qty > 999) {
                    throw new OrderException(__('Enter a quantity between 1 and 999.'));
                }
                $item->qty = $qty;
                $item->line_total = round($qty * ($item->unit_price + $item->addons_price), 2);
            }
            if (array_key_exists('note', $data)) {
                $item->note = $this->str($data['note'], 255);
            }
            if (array_key_exists('seat', $data)) {
                $item->seat = $data['seat'] ? max(1, min(99, (int) $data['seat'])) : null;
            }
            if (array_key_exists('course', $data)) {
                $item->course = max(1, min(5, (int) $data['course']));
            }
            if (array_key_exists('hold', $data)) {
                $item->hold = (bool) $data['hold'];
            }
            $item->save();
            $this->recalc($order);

            return $item;
        });
    }

    /** Remove a line that has not gone to the kitchen yet. */
    public function removeItem(int $orderId, int $itemId): void
    {
        DB::transaction(function () use ($orderId, $itemId) {
            $order = $this->lockOpen($orderId);
            $item = $this->item($order, $itemId);
            $this->assertUnpaid($item);
            if ($item->status !== 'new') {
                throw new OrderException(__('This dish has gone to the kitchen. Void it instead.'));
            }
            $item->delete();
            $this->recalc($order);
            $this->settleIfPaid($order);
        });
    }

    /** Void a line the kitchen has seen (permission rst_orders_void). */
    public function voidItem(int $orderId, int $itemId, string $reason, ?int $userId): RestaurantOrderItem
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new OrderException(__('Give a reason for the void.'));
        }

        return DB::transaction(function () use ($orderId, $itemId, $reason, $userId) {
            $order = $this->lockOpen($orderId);
            $item = $this->item($order, $itemId);
            if ($item->status === 'void') {
                throw new OrderException(__('This dish is already void.'));
            }
            $this->assertUnpaid($item);
            if ($item->status === 'new') {
                throw new OrderException(__('This dish has not gone to the kitchen yet. Remove it instead.'));
            }
            $item->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => mb_substr($reason, 0, 255)]);
            $this->recalc($order);
            $this->syncTicket($item->ticket_id);
            $this->settleIfPaid($order);

            return $item;
        });
    }

    // ------------------------------------------------------------------ kitchen

    /**
     * Send the new lines to the kitchen as one ticket. Lines on hold wait,
     * unless $fireCourse is given: then the new lines of that course go,
     * held or not.
     */
    public function send(int $orderId, ?int $userId, ?int $fireCourse = null): RestaurantKitchenTicket
    {
        return DB::transaction(function () use ($orderId, $userId, $fireCourse) {
            $order = $this->lockOpen($orderId);
            $q = RestaurantOrderItem::where('order_id', $order->id)->where('status', 'new')->lockForUpdate();
            $fireCourse ? $q->where('course', $fireCourse) : $q->where('hold', false);
            $items = $q->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new OrderException($fireCourse ? __('Nothing waiting in course :n.', ['n' => $fireCourse]) : __('Nothing new to send to the kitchen.'));
            }
            $n = RestaurantKitchenTicket::where('order_id', $order->id)->count() + 1;
            $courses = $items->pluck('course')->unique();
            $ticket = RestaurantKitchenTicket::create([
                'order_id' => $order->id,
                'ref' => $order->ref.'-K'.$n,
                'course' => $courses->count() === 1 ? $courses->first() : null,
                'status' => 'pending',
                'sent_by' => $userId,
                'sent_at' => now(),
            ]);
            RestaurantOrderItem::whereIn('id', $items->pluck('id'))->update([
                'status' => 'sent', 'ticket_id' => $ticket->id, 'sent_at' => now(), 'hold' => false, 'updated_at' => now(),
            ]);

            return $ticket;
        });
    }

    // ------------------------------------------------------------------ cancel / close

    /** Cancel the whole order (permission rst_orders_void): every line is voided with the reason. */
    public function cancel(int $orderId, string $reason, ?int $userId): RestaurantOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new OrderException(__('Give a reason for cancelling.'));
        }

        return DB::transaction(function () use ($orderId, $reason, $userId) {
            $order = $this->lockOpen($orderId);
            if ($this->hasPaidItems($order)) {
                throw new OrderException(__('Part of this order is already paid, so it cannot be cancelled. Void the unpaid dishes instead.'));
            }
            $this->closeAsCancelled($order, mb_substr($reason, 0, 255), $userId);

            return $order;
        });
    }

    /**
     * Before the guests at a table are cleared: an open order with dishes on it
     * blocks; an empty one is closed. Called inside FloorService::clear().
     */
    public function beforeTableCleared(int $tableId): void
    {
        if (! RestaurantOrder::tablesReady()) {
            return;
        }
        $order = $this->openOrderOf($tableId, true);
        if (! $order) {
            return;
        }
        // Paid in part and nothing left to pay (the rest voided): settled, the guests may go.
        if ($this->settleIfPaid($order)) {
            return;
        }
        if (RestaurantOrderItem::where('order_id', $order->id)->where('status', '!=', 'void')->exists()) {
            throw new FloorException(__('Table has an unpaid order (:ref). Take payment or cancel the order first.', ['ref' => $order->ref]));
        }
        $this->closeAsCancelled($order, 'Guests left without ordering', null);
    }

    // ------------------------------------------------------------------ table moves

    /** Guests moved to another table: their order goes with them. */
    public function tableMoved(int $fromTableId, int $toTableId): void
    {
        if (RestaurantOrder::tablesReady()) {
            $to = RestaurantTable::withTrashed()->find($toTableId);
            $area = $to ? RestaurantArea::withTrashed()->find($to->area_id) : null;
            RestaurantOrder::where('table_id', $fromTableId)->where('status', 'open')
                ->update(['table_id' => $toTableId, 'warehouse_id' => $area->warehouse_id ?? null, 'updated_at' => now()]);
        }
    }

    /**
     * A table was joined to another: its open order's lines move to the main
     * table's order (created if it has none) and its own order is closed.
     */
    public function tableMerged(int $childTableId, int $parentTableId): void
    {
        if (! RestaurantOrder::tablesReady()) {
            return;
        }
        $child = $this->openOrderOf($childTableId, true);
        if (! $child) {
            return;
        }
        $parent = $this->openOrderOf($parentTableId, true);
        if (! $parent) {
            $child->update(['table_id' => $parentTableId]);

            return;
        }
        RestaurantOrderItem::where('order_id', $child->id)->update(['order_id' => $parent->id]);
        RestaurantKitchenTicket::where('order_id', $child->id)->update(['order_id' => $parent->id]);
        if (\Illuminate\Support\Facades\Schema::hasTable('rst_order_payments')) {
            DB::table('rst_order_payments')->where('order_id', $child->id)->update(['order_id' => $parent->id]);
        }
        // Not a cancellation: the order lives on in the main table's order.
        $child->update(['status' => 'cancelled', 'closed_at' => now(), 'cancel_reason' => 'Merged into '.$parent->ref, 'subtotal' => 0]);
        $child->delete();
        $this->recalc($parent);
    }

    // ------------------------------------------------------------------ helpers

    public function openOrderOf(int $tableId, bool $lock = false): ?RestaurantOrder
    {
        $q = RestaurantOrder::where('table_id', $tableId)->where('status', 'open')->orderBy('id');

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public function recalc(RestaurantOrder $order): void
    {
        $order->subtotal = round((float) RestaurantOrderItem::where('order_id', $order->id)->where('status', '!=', 'void')->sum('line_total'), 2);
        $order->save();
    }

    private function create(array $attrs): RestaurantOrder
    {
        $s = RestaurantSetting::query()->orderBy('id')->lockForUpdate()->first() ?? RestaurantSetting::current();
        $prefix = strtoupper($s->order_prefix ?: 'RO');
        $last = RestaurantOrder::withTrashed()->where('ref', 'like', $prefix.'-%')->orderByDesc('id')->value('ref');
        $n = $last && preg_match('/-(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;
        do {
            $ref = $prefix.'-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (RestaurantOrder::withTrashed()->where('ref', $ref)->exists());

        return RestaurantOrder::create($attrs + [
            'ref' => $ref,
            'status' => 'open',
            'warehouse_id' => $attrs['warehouse_id'] ?? $s->default_warehouse_id,
            'opened_at' => now(),
            'subtotal' => 0,
        ]);
    }

    private function lockOpen(int $orderId): RestaurantOrder
    {
        $order = RestaurantOrder::whereKey($orderId)->lockForUpdate()->first();
        if (! $order) {
            throw new OrderException(__('This order no longer exists.'), 404);
        }
        if (! $order->isOpen()) {
            throw new OrderException(__('Order :ref is closed.', ['ref' => $order->ref]));
        }

        return $order;
    }

    private function item(RestaurantOrder $order, int $itemId): RestaurantOrderItem
    {
        $item = RestaurantOrderItem::where('order_id', $order->id)->whereKey($itemId)->lockForUpdate()->first();
        if (! $item) {
            throw new OrderException(__('This dish is no longer on the order.'), 404);
        }

        return $item;
    }

    /** A dish paid by a bill (phase 6) can no longer change. */
    private function assertUnpaid(RestaurantOrderItem $item): void
    {
        if (! empty($item->sale_id)) {
            throw new OrderException(__('This dish is already paid.'));
        }
    }

    private function hasPaidItems(RestaurantOrder $order): bool
    {
        return Schema::hasColumn('rst_order_items', 'sale_id')
            && RestaurantOrderItem::where('order_id', $order->id)->whereNotNull('sale_id')->exists();
    }

    private function closeAsCancelled(RestaurantOrder $order, string $reason, ?int $userId): void
    {
        RestaurantOrderItem::where('order_id', $order->id)->where('status', '!=', 'void')
            ->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason, 'updated_at' => now()]);
        RestaurantKitchenTicket::where('order_id', $order->id)->whereNotIn('status', ['served', 'void'])->update(['status' => 'void', 'updated_at' => now()]);
        $order->update(['status' => 'cancelled', 'closed_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'subtotal' => 0]);
    }

    /** A ticket whose every line is void is void too. */
    private function syncTicket(?int $ticketId): void
    {
        app(KitchenService::class)->syncTicket($ticketId);
    }

    /**
     * An order with paid dishes and nothing left to pay (the rest voided or
     * removed) is settled: closed as paid. Returns whether it was.
     */
    public function settleIfPaid(RestaurantOrder $order): bool
    {
        if (! $order->isOpen() || ! $this->hasPaidItems($order)) {
            return false;
        }
        if (RestaurantOrderItem::where('order_id', $order->id)->where('status', '!=', 'void')->whereNull('sale_id')->exists()) {
            return false;
        }
        $order->update(['status' => 'paid', 'closed_at' => now()]);

        return true;
    }

    /**
     * Send exactly these new lines to the kitchen (held or not) — used when a
     * bill is paid before the dishes were sent, so they are still cooked.
     */
    public function sendLines(int $orderId, array $itemIds, ?int $userId): ?RestaurantKitchenTicket
    {
        $ids = RestaurantOrderItem::where('order_id', $orderId)->where('status', 'new')->whereIn('id', $itemIds)->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }
        $order = $this->lockOpen($orderId);
        $n = RestaurantKitchenTicket::where('order_id', $order->id)->count() + 1;
        $ticket = RestaurantKitchenTicket::create([
            'order_id' => $order->id, 'ref' => $order->ref.'-K'.$n, 'status' => 'pending', 'sent_by' => $userId, 'sent_at' => now(),
        ]);
        RestaurantOrderItem::whereIn('id', $ids)->update(['status' => 'sent', 'ticket_id' => $ticket->id, 'sent_at' => now(), 'hold' => false, 'updated_at' => now()]);

        return $ticket;
    }

    private function str($v, int $max): ?string
    {
        $v = trim((string) (is_scalar($v) ? $v : ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
