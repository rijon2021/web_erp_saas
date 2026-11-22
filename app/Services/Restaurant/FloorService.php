<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a table's live state: seat guests, ask for the bill, clear,
 * mark clean, hold for a reservation, move to another table, merge / unmerge.
 * Each runs in a transaction with the rows locked, so two waiters acting on
 * the same table at once cannot both win.
 *
 * Merged tables: the table guests sit at is the "parent"; the tables pushed
 * against it point at it through merged_into_id and show as occupied. Only
 * the parent carries guests, waiter and (from phase 4) the order.
 */
class FloorService
{
    /** Seat guests at a free (or reserved) table. */
    public function seat(int $tableId, ?int $guests, ?int $waiterId, ?string $note = null): RestaurantTable
    {
        return DB::transaction(function () use ($tableId, $guests, $waiterId, $note) {
            $t = $this->lock($tableId);
            $this->assertParent($t);
            if (! in_array($t->status, ['free', 'reserved'], true)) {
                throw new FloorException(__('Table :table is not free.', ['table' => $t->name]));
            }
            if (RestaurantSetting::current()->require_guest_count && ! $guests) {
                throw new FloorException(__('Enter the number of guests.'));
            }
            $this->apply($t, [
                'status' => 'occupied',
                'guests' => $guests ?: null,
                'seated_at' => now(),
                'waiter_id' => $waiterId,
                'note' => $note !== null ? mb_substr(trim($note), 0, 191) ?: null : $t->note,
            ]);

            return $t;
        });
    }

    /** Guests asked for the bill (occupied → bill), or want to order more (bill → occupied). */
    public function setBillRequested(int $tableId, bool $requested): RestaurantTable
    {
        return DB::transaction(function () use ($tableId, $requested) {
            $t = $this->lock($tableId);
            $this->assertParent($t);
            $from = $requested ? 'occupied' : 'bill';
            if ($t->status !== $from) {
                throw new FloorException(__('Table :table has no guests.', ['table' => $t->name]));
            }
            $this->apply($t, ['status' => $requested ? 'bill' : 'occupied']);
            $this->cascade($t, ['status' => $t->status]);

            return $t;
        });
    }

    /**
     * Guests have left: the table (and any table merged into it) becomes
     * "cleaning" or "free" per Restaurant Settings.
     */
    public function clear(int $tableId): RestaurantTable
    {
        return DB::transaction(function () use ($tableId) {
            $t = $this->lock($tableId);
            $this->assertParent($t);
            if (! $t->isInUse()) {
                throw new FloorException(__('Table :table has no guests.', ['table' => $t->name]));
            }
            // An unpaid order blocks; an empty one is closed (OrderService).
            app(OrderService::class)->beforeTableCleared($t->id);
            $next = RestaurantSetting::current()->after_payment_table_status === 'free' ? 'free' : 'cleaning';
            $reset = ['status' => $next, 'guests' => null, 'seated_at' => null, 'waiter_id' => null, 'merged_into_id' => null, 'note' => null];
            $this->cascade($t, $reset);
            $this->apply($t, $reset);
            app(ReservationService::class)->tableCleared($t->id);

            return $t;
        });
    }

    public function markClean(int $tableId): RestaurantTable
    {
        return $this->transition($tableId, 'cleaning', 'free', __('Table :table does not need cleaning.'));
    }

    /** Hold a free table (a reservation or walk-in hold), or release the hold. */
    public function setReserved(int $tableId, bool $reserved, ?string $note = null): RestaurantTable
    {
        return DB::transaction(function () use ($tableId, $reserved, $note) {
            $t = $this->lock($tableId);
            $this->assertParent($t);
            if ($t->status !== ($reserved ? 'free' : 'reserved')) {
                throw new FloorException($reserved ? __('Table :table is not free.', ['table' => $t->name]) : __('Table :table is not reserved.', ['table' => $t->name]));
            }
            $this->apply($t, ['status' => $reserved ? 'reserved' : 'free', 'note' => $reserved && $note !== null ? (mb_substr(trim($note), 0, 191) ?: null) : null]);

            return $t;
        });
    }

    /** Move the guests (and any merged tables) from one table to a free one. */
    public function move(int $fromId, int $toId): RestaurantTable
    {
        if ($fromId === $toId) {
            throw new FloorException(__('Choose a different table.'));
        }

        return DB::transaction(function () use ($fromId, $toId) {
            [$from, $to] = $this->lockMany([$fromId, $toId]);
            $this->assertParent($from);
            $this->assertParent($to);
            if (! $from->isInUse()) {
                throw new FloorException(__('Table :table has no guests.', ['table' => $from->name]));
            }
            if ($to->status !== 'free') {
                throw new FloorException(__('Table :table is not free.', ['table' => $to->name]));
            }
            $this->apply($to, $from->only(['status', 'guests', 'seated_at', 'waiter_id', 'note']));
            RestaurantTable::where('merged_into_id', $from->id)->where('id', '!=', $to->id)->update(['merged_into_id' => $to->id]);
            $this->apply($from, ['status' => 'free', 'guests' => null, 'seated_at' => null, 'waiter_id' => null, 'note' => null]);
            // The open order follows the guests.
            app(OrderService::class)->tableMoved($from->id, $to->id);
            app(ReservationService::class)->tableMoved($from->id, $to->id);

            return $to;
        });
    }

    /**
     * Push tables against a table with guests. Free tables simply join; a
     * table that has its own guests brings them along (guest counts add up).
     *
     * @param  int[]  $ids
     */
    public function merge(int $targetId, array $ids): RestaurantTable
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0 && $id !== $targetId)));
        if (! $ids) {
            throw new FloorException(__('Choose the tables to join.'));
        }

        return DB::transaction(function () use ($targetId, $ids) {
            $rows = $this->lockMany(array_merge([$targetId], $ids));
            $target = array_shift($rows);
            $this->assertParent($target);
            if (! $target->isInUse()) {
                throw new FloorException(__('Seat guests at :table before joining tables to it.', ['table' => $target->name]));
            }
            $guests = (int) $target->guests;
            foreach ($rows as $t) {
                $this->assertParent($t);
                if ($t->area_id !== $target->area_id) {
                    throw new FloorException(__('Only tables in the same area can be joined.'));
                }
                if ($t->status === 'reserved') {
                    throw new FloorException(__('Table :table is reserved.', ['table' => $t->name]));
                }
                if ($t->status === 'cleaning') {
                    throw new FloorException(__('Table :table needs cleaning first.', ['table' => $t->name]));
                }
                $guests += (int) $t->guests;
                RestaurantTable::where('merged_into_id', $t->id)->update(['merged_into_id' => $target->id]);
                // Its open order's dishes join the main table's order.
                app(OrderService::class)->tableMerged($t->id, $target->id);
                app(ReservationService::class)->tableMoved($t->id, $target->id);
                $this->apply($t, ['status' => $target->status, 'guests' => null, 'seated_at' => null, 'waiter_id' => null, 'merged_into_id' => $target->id]);
            }
            $this->apply($target, ['guests' => $guests ?: null]);

            return $target;
        });
    }

    /** Take a joined table away again; it becomes free. */
    public function unmerge(int $tableId): RestaurantTable
    {
        return DB::transaction(function () use ($tableId) {
            $t = $this->lock($tableId);
            if (! $t->merged_into_id) {
                throw new FloorException(__('Table :table is not joined to another table.', ['table' => $t->name]));
            }
            $this->apply($t, ['status' => 'free', 'merged_into_id' => null]);

            return $t;
        });
    }

    // ------------------------------------------------------------------

    private function transition(int $tableId, string $from, string $to, string $error): RestaurantTable
    {
        return DB::transaction(function () use ($tableId, $from, $to, $error) {
            $t = $this->lock($tableId);
            $this->assertParent($t);
            if ($t->status !== $from) {
                throw new FloorException(str_replace(':table', $t->name, $error));
            }
            $this->apply($t, ['status' => $to]);

            return $t;
        });
    }

    private function lock(int $id): RestaurantTable
    {
        $t = RestaurantTable::whereKey($id)->lockForUpdate()->first();
        if (! $t || ! $t->is_active) {
            throw new FloorException(__('This table no longer exists.'), 404);
        }

        return $t;
    }

    /** @return RestaurantTable[] in the order of $ids (locked in id order to avoid deadlocks) */
    private function lockMany(array $ids): array
    {
        $rows = RestaurantTable::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            $t = $rows[$id] ?? null;
            if (! $t || ! $t->is_active) {
                throw new FloorException(__('This table no longer exists.'), 404);
            }
            $out[] = $t;
        }

        return $out;
    }

    private function assertParent(RestaurantTable $t): void
    {
        if ($t->merged_into_id) {
            $parent = RestaurantTable::find($t->merged_into_id);
            throw new FloorException(__('Table :table is joined to :parent. Use :parent instead.', ['table' => $t->name, 'parent' => $parent->name ?? '?']));
        }
    }

    private function apply(RestaurantTable $t, array $changes): void
    {
        $statusChanged = array_key_exists('status', $changes) && $changes['status'] !== $t->status;
        $t->fill($changes);
        if ($statusChanged) {
            $t->status_changed_at = now();
        }
        $t->save();
    }

    /** Same change on every table merged into $parent. */
    private function cascade(RestaurantTable $parent, array $changes): void
    {
        $update = array_intersect_key($changes, array_flip(['status', 'merged_into_id']));
        if (array_key_exists('status', $update)) {
            $update['status_changed_at'] = now();
        }
        RestaurantTable::where('merged_into_id', $parent->id)->update($update + ['updated_at' => now()]);
    }
}
