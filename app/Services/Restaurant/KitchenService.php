<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantKitchenTicket;
use App\Models\RestaurantOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Kitchen side of the restaurant tickets.
 *
 *   start   pending → preparing (dishes sent → preparing)
 *   toggle  one dish ready / back to preparing
 *   bump    every dish (or every dish of one station) ready
 *   served  ready dishes picked up by the waiter → served
 *   recall  a ready / served ticket back to preparing
 *
 * The ticket's own status always follows its dishes (syncTicket): all served
 * → served, all ready or served → ready, anything started → preparing.
 */
class KitchenService
{
    public function start(int $ticketId): RestaurantKitchenTicket
    {
        return DB::transaction(function () use ($ticketId) {
            $t = $this->lock($ticketId);
            RestaurantOrderItem::where('ticket_id', $t->id)->where('status', 'sent')->update(['status' => 'preparing', 'updated_at' => now()]);
            if (! $t->started_at) {
                $t->started_at = now();
            }

            return $this->sync($t);
        });
    }

    /** Mark one dish ready (done = true) or back to preparing. */
    public function toggleItem(int $itemId, bool $done): RestaurantKitchenTicket
    {
        return DB::transaction(function () use ($itemId, $done) {
            // Ticket first, then the dish: the same lock order as every other
            // kitchen action, so two cooks on one ticket never deadlock.
            $ticketId = RestaurantOrderItem::whereKey($itemId)->value('ticket_id');
            if (! $ticketId) {
                throw new OrderException(__('This dish is no longer on a kitchen ticket.'), 404);
            }
            $t = $this->lock((int) $ticketId);
            $item = RestaurantOrderItem::whereKey($itemId)->lockForUpdate()->first();
            if (! $item || (int) $item->ticket_id !== $t->id || $item->status === 'void') {
                throw new OrderException(__('This dish is no longer on a kitchen ticket.'), 404);
            }
            if ($done && in_array($item->status, ['sent', 'preparing'], true)) {
                $item->update(['status' => 'ready']);
            } elseif (! $done && $item->status === 'ready') {
                $item->update(['status' => 'preparing']);
            }
            if (! $t->started_at) {
                $t->started_at = now();
            }

            return $this->sync($t);
        });
    }

    /** Everything on the ticket ready — or only the dishes of one station. */
    public function bump(int $ticketId, ?int $stationId = null): RestaurantKitchenTicket
    {
        return DB::transaction(function () use ($ticketId, $stationId) {
            $t = $this->lock($ticketId);
            RestaurantOrderItem::where('ticket_id', $t->id)->whereIn('status', ['sent', 'preparing'])
                ->when($stationId, fn ($q) => $q->where(fn ($w) => $w->where('station_id', $stationId)->orWhereNull('station_id')))
                ->update(['status' => 'ready', 'updated_at' => now()]);
            if (! $t->started_at) {
                $t->started_at = now();
            }

            return $this->sync($t);
        });
    }

    /** The waiter picked the ready dishes up. */
    public function served(int $ticketId): RestaurantKitchenTicket
    {
        return DB::transaction(function () use ($ticketId) {
            $t = $this->lock($ticketId);
            $ready = RestaurantOrderItem::where('ticket_id', $t->id)->where('status', 'ready')->count();
            if (! $ready) {
                throw new OrderException(__('Nothing on :ref is ready yet.', ['ref' => $t->ref]));
            }
            RestaurantOrderItem::where('ticket_id', $t->id)->where('status', 'ready')->update(['status' => 'served', 'updated_at' => now()]);

            return $this->sync($t);
        });
    }

    /** Back on the screen: ready / served dishes return to preparing. */
    public function recall(int $ticketId): RestaurantKitchenTicket
    {
        return DB::transaction(function () use ($ticketId) {
            $t = $this->lock($ticketId);
            RestaurantOrderItem::where('ticket_id', $t->id)->whereIn('status', ['ready', 'served'])->update(['status' => 'preparing', 'updated_at' => now()]);
            $t->ready_at = null;
            $t->served_at = null;

            return $this->sync($t);
        });
    }

    /**
     * Mark an order's ready dishes served (waiter screen / floor alert).
     *
     * @return int how many dishes were marked served
     */
    public function servedForOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {
            // Lock the tickets (in id order) before their dishes — see toggleItem().
            $ticketIds = RestaurantOrderItem::where('order_id', $orderId)->where('status', 'ready')->pluck('ticket_id')->filter()->unique()->sort()->values();
            $tickets = $ticketIds->map(fn ($id) => RestaurantKitchenTicket::whereKey((int) $id)->lockForUpdate()->first())->filter();
            $n = RestaurantOrderItem::where('order_id', $orderId)->where('status', 'ready')->whereIn('ticket_id', $tickets->pluck('id'))->update(['status' => 'served', 'updated_at' => now()]);
            foreach ($tickets as $t) {
                $this->sync($t);
            }

            return $n;
        });
    }

    // ------------------------------------------------------------------

    private function lock(int $ticketId): RestaurantKitchenTicket
    {
        $t = RestaurantKitchenTicket::whereKey($ticketId)->lockForUpdate()->first();
        if (! $t) {
            throw new OrderException(__('This kitchen ticket no longer exists.'), 404);
        }
        if ($t->status === 'void') {
            throw new OrderException(__('Ticket :ref was cancelled.', ['ref' => $t->ref]));
        }
        $order = $t->order()->first(['id', 'status']);
        if ($order && $order->status === 'cancelled') {
            throw new OrderException(__('Ticket :ref was cancelled.', ['ref' => $t->ref]));
        }

        return $t;
    }

    /** Recompute a ticket after its dishes changed elsewhere (a void). */
    public function syncTicket(?int $ticketId): void
    {
        if ($ticketId && ($t = RestaurantKitchenTicket::whereKey($ticketId)->lockForUpdate()->first())) {
            $this->sync($t);
        }
    }

    /** Ticket status from its dishes (void dishes ignored). */
    private function sync(RestaurantKitchenTicket $t): RestaurantKitchenTicket
    {
        $statuses = RestaurantOrderItem::where('ticket_id', $t->id)->where('status', '!=', 'void')->pluck('status');
        if ($statuses->isEmpty()) {
            $t->status = 'void';
        } elseif ($statuses->every(fn ($s) => $s === 'served')) {
            $t->status = 'served';
            $t->served_at ??= now();
            $t->ready_at ??= now();
        } elseif ($statuses->every(fn ($s) => in_array($s, ['ready', 'served'], true))) {
            $t->status = 'ready';
            $t->ready_at ??= now();
            $t->served_at = null;
        } elseif ($t->started_at || $statuses->contains(fn ($s) => in_array($s, ['preparing', 'ready'], true))) {
            $t->status = 'preparing';
            $t->ready_at = null;
            $t->served_at = null;
        } else {
            $t->status = 'pending';
        }
        $t->save();

        return $t;
    }
}
