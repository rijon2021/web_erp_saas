<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantArea;
use App\Models\RestaurantOrder;
use App\Models\RestaurantReservation;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\RestaurantWaitlistEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Table bookings and the walk-in waitlist.
 *
 * A booking holds its table from starts_at to ends_at; two active bookings
 * (booked / confirmed / seated) can never overlap on one table. Bookings do
 * not change the table's live status on their own — the floor plan shows the
 * next booking on each table, and seating the party goes through
 * FloorService::seat like any other guests. When those guests leave
 * (FloorService::clear) the booking is completed.
 *
 * Times are wall-clock "Y-m-d H:i" in the tenant's timezone.
 */
class ReservationService
{
    public function __construct(private FloorService $floor)
    {
    }

    // ------------------------------------------------------------------ bookings

    /**
     * Create ($id null) or change a booking.
     *
     * @param  array  $data  customer_name, customer_phone?, customer_email?, client_id?, party_size, date (Y-m-d),
     *                       time (H:i), minutes?, table_id?, area_id?, source?, note?
     */
    public function save(?int $id, array $data, ?int $userId): RestaurantReservation
    {
        $s = RestaurantSetting::current();

        return DB::transaction(function () use ($id, $data, $userId, $s) {
            $r = $id ? $this->lockReservation($id) : new RestaurantReservation(['status' => 'booked', 'created_by' => $userId]);
            if ($id && ! in_array($r->status, RestaurantReservation::ACTIVE, true)) {
                throw new ReservationException(__('This booking is closed and can no longer be changed.'));
            }

            $name = $this->str($data['customer_name'] ?? null, 120);
            if (! $name) {
                throw new ReservationException(__('Enter the guest\'s name.'));
            }
            $party = (int) ($data['party_size'] ?? 0);
            if ($party < 1 || $party > max(1, (int) $s->max_party_size)) {
                throw new ReservationException(__('Party size must be between 1 and :n.', ['n' => max(1, (int) $s->max_party_size)]));
            }

            $r->fill([
                'customer_name' => $name,
                'customer_phone' => $this->str($data['customer_phone'] ?? null, 40),
                'customer_email' => $this->str($data['customer_email'] ?? null, 191),
                'client_id' => ! empty($data['client_id']) ? (int) $data['client_id'] : null,
                'party_size' => $party,
                'note' => $this->str($data['note'] ?? null, 500),
            ]);
            if (! $id) {
                $r->source = in_array($data['source'] ?? null, RestaurantReservation::SOURCES, true) ? $data['source'] : 'phone';
            }

            // Time and table can only move while the party has not arrived.
            if ($r->status !== 'seated') {
                $start = $this->parseStart($data['date'] ?? null, $data['time'] ?? null);
                $minutes = (int) ($data['minutes'] ?? 0) ?: (int) $s->reservation_minutes ?: 90;
                if ($minutes < 15 || $minutes > 720) {
                    throw new ReservationException(__('A booking lasts between 15 minutes and 12 hours.'));
                }
                $timeChanged = ! $id || ! $r->starts_at || ! $r->starts_at->equalTo($start);
                if ($timeChanged && $start->lt(now()->subMinutes(15))) {
                    throw new ReservationException(__('Choose a time that has not passed yet.'));
                }
                $end = $start->copy()->addMinutes($minutes);

                $tableId = ! empty($data['table_id']) ? (int) $data['table_id'] : null;
                $areaId = ! empty($data['area_id']) ? (int) $data['area_id'] : null;
                $warehouseId = null;
                if ($tableId) {
                    $table = RestaurantTable::whereKey($tableId)->where('is_active', true)->first();
                    if (! $table) {
                        throw new ReservationException(__('This table no longer exists.'), 404);
                    }
                    $this->assertTableFree($table, $start, $end, $id);
                    $areaId = $table->area_id;
                }
                if ($areaId) {
                    $area = RestaurantArea::find($areaId);
                    if (! $area) {
                        throw new ReservationException(__('This area no longer exists.'), 404);
                    }
                    $warehouseId = $area->warehouse_id;
                }
                $r->fill(['starts_at' => $start, 'ends_at' => $end, 'table_id' => $tableId, 'area_id' => $areaId, 'warehouse_id' => $warehouseId]);
            }
            $r->save();

            return $r;
        });
    }

    public function confirm(int $id): RestaurantReservation
    {
        return $this->transition($id, ['booked'], 'confirmed', __('Only a new booking can be confirmed.'));
    }

    public function cancel(int $id, ?string $reason): RestaurantReservation
    {
        return DB::transaction(function () use ($id, $reason) {
            $r = $this->lockReservation($id);
            if (! $r->isUpcoming()) {
                throw new ReservationException(__('Only a booking that has not arrived can be cancelled.'));
            }
            $r->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $this->str($reason, 255)]);

            return $r;
        });
    }

    public function noShow(int $id): RestaurantReservation
    {
        return DB::transaction(function () use ($id) {
            $r = $this->lockReservation($id);
            if (! $r->isUpcoming()) {
                throw new ReservationException(__('Only a booking that has not arrived can be marked as a no-show.'));
            }
            if ($r->starts_at->isFuture()) {
                throw new ReservationException(__('The booking time has not come yet.'));
            }
            $r->update(['status' => 'no_show', 'cancelled_at' => now()]);

            return $r;
        });
    }

    /** Undo a cancellation or no-show (the table must still be free at that time). */
    public function restore(int $id): RestaurantReservation
    {
        return DB::transaction(function () use ($id) {
            $r = $this->lockReservation($id);
            if (! in_array($r->status, ['cancelled', 'no_show'], true)) {
                throw new ReservationException(__('This booking is not cancelled.'));
            }
            if ($r->ends_at->isPast()) {
                throw new ReservationException(__('This booking\'s time has passed.'));
            }
            if ($r->table_id && ($t = RestaurantTable::find($r->table_id))) {
                $this->assertTableFree($t, $r->starts_at, $r->ends_at, $r->id);
            }
            $r->update(['status' => 'booked', 'cancelled_at' => null, 'cancel_reason' => null]);

            return $r;
        });
    }

    /**
     * The party arrived: seat them at their table (or the one given) through
     * FloorService::seat, so the table turns "occupied" like any other guests.
     */
    public function seat(int $id, ?int $tableId, ?int $waiterId): RestaurantReservation
    {
        return DB::transaction(function () use ($id, $tableId, $waiterId) {
            $r = $this->lockReservation($id);
            if (! $r->isUpcoming()) {
                throw new ReservationException(__('This booking has already been seated or closed.'));
            }
            $tableId = $tableId ?: $r->table_id;
            if (! $tableId) {
                throw new ReservationException(__('Choose a table for this party.'));
            }
            $table = RestaurantTable::whereKey($tableId)->where('is_active', true)->first();
            if (! $table) {
                throw new ReservationException(__('This table no longer exists.'), 404);
            }
            // Another booking may hold the table for (part of) this visit — also
            // the booking's own table when the party comes early.
            $this->assertTableFree($table, now(), now()->max($r->ends_at), $r->id);
            $this->floor->seat($table->id, $r->party_size, $waiterId, $this->str($r->customer_name.' · '.__('Booking'), 191));
            $area = RestaurantArea::find($table->area_id);
            $r->update(['status' => 'seated', 'seated_at' => now(), 'table_id' => $table->id, 'area_id' => $table->area_id, 'warehouse_id' => $area->warehouse_id ?? $r->warehouse_id]);

            return $r;
        });
    }

    // ------------------------------------------------------------------ hooks (FloorService / OrderService)

    /** The guests at a table left: their booking is complete. */
    public function tableCleared(int $tableId): void
    {
        if (RestaurantReservation::tablesReady()) {
            RestaurantReservation::where('table_id', $tableId)->where('status', 'seated')->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        }
    }

    /** The guests moved table: their booking goes with them. */
    public function tableMoved(int $fromTableId, int $toTableId): void
    {
        if (RestaurantReservation::tablesReady()) {
            RestaurantReservation::where('table_id', $fromTableId)->where('status', 'seated')->update(['table_id' => $toTableId, 'updated_at' => now()]);
        }
    }

    /** A dine-in order was opened on a table: link the seated booking and return it (for the guest's name). */
    public function orderOpened(RestaurantOrder $order): ?RestaurantReservation
    {
        if (! $order->table_id || ! RestaurantReservation::tablesReady()) {
            return null;
        }
        $r = RestaurantReservation::where('table_id', $order->table_id)->where('status', 'seated')->whereNull('order_id')->orderByDesc('seated_at')->first();
        if ($r) {
            $r->update(['order_id' => $order->id]);
        }

        return $r;
    }

    // ------------------------------------------------------------------ availability

    /**
     * Every active table with whether it fits the party and is free for the
     * slot; best choices first (free, fits, fewest spare seats).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $tables  pre-scoped table query
     */
    public function availability($tables, Carbon $start, Carbon $end, int $party, ?int $excludeId = null): array
    {
        $tables = $tables->where('rst_tables.is_active', true)->whereNull('rst_tables.merged_into_id')->with('area:id,name')->orderBy('sort_order')->orderBy('id')->get();
        $busy = RestaurantReservation::whereIn('table_id', $tables->pluck('id'))->whereIn('status', RestaurantReservation::ACTIVE)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('starts_at')->get(['id', 'table_id', 'customer_name', 'starts_at', 'party_size'])->groupBy('table_id');
        $nowSlot = $start->lte(now()->addMinutes(15)) && $end->gt(now());

        return $tables->map(function (RestaurantTable $t) use ($busy, $party, $nowSlot) {
            $clash = ($busy[$t->id] ?? collect())->first();
            // Guests sitting there right now also block a booking that starts now.
            $inUse = $nowSlot && in_array($t->status, ['occupied', 'bill', 'cleaning'], true);

            return [
                'id' => $t->id,
                'name' => $t->name,
                'area' => $t->area ? $t->area->name : null,
                'area_id' => $t->area_id,
                'seats' => $t->seats,
                'fits' => $t->seats >= $party,
                'free' => ! $clash && ! $inUse,
                'clash' => $clash ? ['id' => $clash->id, 'name' => $clash->customer_name, 'time' => $clash->starts_at->format('H:i')] : null,
                'in_use' => $inUse,
            ];
        })->sort(fn ($a, $b) => [! $a['free'], ! $a['fits'], $a['fits'] ? $a['seats'] : -$a['seats']] <=> [! $b['free'], ! $b['fits'], $b['fits'] ? $b['seats'] : -$b['seats']])->values()->all();
    }

    // ------------------------------------------------------------------ waitlist

    public function addWaiting(array $data, ?int $userId): RestaurantWaitlistEntry
    {
        $e = new RestaurantWaitlistEntry(['status' => 'waiting', 'added_by' => $userId]);

        return $this->fillWaiting($e, $data);
    }

    public function updateWaiting(int $id, array $data): RestaurantWaitlistEntry
    {
        return DB::transaction(function () use ($id, $data) {
            $e = $this->lockWaiting($id);
            if (! in_array($e->status, RestaurantWaitlistEntry::ACTIVE, true)) {
                throw new ReservationException(__('This party is no longer waiting.'));
            }

            return $this->fillWaiting($e, $data);
        });
    }

    /** The table is ready and the party was told (call / message). */
    public function notify(int $id): RestaurantWaitlistEntry
    {
        return DB::transaction(function () use ($id) {
            $e = $this->lockWaiting($id);
            if (! in_array($e->status, RestaurantWaitlistEntry::ACTIVE, true)) {
                throw new ReservationException(__('This party is no longer waiting.'));
            }
            $e->update(['status' => 'notified', 'notified_at' => now()]);

            return $e;
        });
    }

    public function seatWaiting(int $id, int $tableId, ?int $waiterId): RestaurantWaitlistEntry
    {
        return DB::transaction(function () use ($id, $tableId, $waiterId) {
            $e = $this->lockWaiting($id);
            if (! in_array($e->status, RestaurantWaitlistEntry::ACTIVE, true)) {
                throw new ReservationException(__('This party is no longer waiting.'));
            }
            $table = RestaurantTable::whereKey($tableId)->where('is_active', true)->first();
            if (! $table) {
                throw new ReservationException(__('This table no longer exists.'), 404);
            }
            $this->floor->seat($table->id, $e->party_size, $waiterId, $this->str($e->customer_name, 191));
            $e->update(['status' => 'seated', 'seated_at' => now(), 'table_id' => $table->id]);

            return $e;
        });
    }

    /** The party gave up (left), or — $back — came back to the queue. */
    public function leave(int $id, bool $back = false): RestaurantWaitlistEntry
    {
        return DB::transaction(function () use ($id, $back) {
            $e = $this->lockWaiting($id);
            if ($back) {
                if ($e->status !== 'left') {
                    throw new ReservationException(__('This party is still in the queue.'));
                }
                $e->update(['status' => 'waiting', 'left_at' => null]);
            } else {
                if (! in_array($e->status, RestaurantWaitlistEntry::ACTIVE, true)) {
                    throw new ReservationException(__('This party is no longer waiting.'));
                }
                $e->update(['status' => 'left', 'left_at' => now()]);
            }

            return $e;
        });
    }

    // ------------------------------------------------------------------ helpers

    private function fillWaiting(RestaurantWaitlistEntry $e, array $data): RestaurantWaitlistEntry
    {
        $name = $this->str($data['customer_name'] ?? null, 120);
        if (! $name) {
            throw new ReservationException(__('Enter the guest\'s name.'));
        }
        $party = (int) ($data['party_size'] ?? 0);
        $max = max(1, (int) RestaurantSetting::current()->max_party_size);
        if ($party < 1 || $party > $max) {
            throw new ReservationException(__('Party size must be between 1 and :n.', ['n' => $max]));
        }
        $areaId = ! empty($data['area_id']) ? (int) $data['area_id'] : null;
        $area = $areaId ? RestaurantArea::find($areaId) : null;
        $e->fill([
            'customer_name' => $name,
            'customer_phone' => $this->str($data['customer_phone'] ?? null, 40),
            'party_size' => $party,
            'quoted_minutes' => isset($data['quoted_minutes']) && $data['quoted_minutes'] !== '' && $data['quoted_minutes'] !== null ? max(0, min(600, (int) $data['quoted_minutes'])) : null,
            'area_id' => $area ? $area->id : null,
            'warehouse_id' => $area ? $area->warehouse_id : $e->warehouse_id,
            'note' => $this->str($data['note'] ?? null, 255),
        ]);
        $e->save();

        return $e;
    }

    private function assertTableFree(RestaurantTable $table, Carbon $start, Carbon $end, ?int $excludeId): void
    {
        // A table joined to another right now only matters for a booking today
        // (tables are usually split again by the next service).
        if ($table->merged_into_id && $start->lt(now()->endOfDay())) {
            throw new ReservationException(__('Table :table is joined to another table right now. Choose the main table.', ['table' => $table->name]));
        }
        $clash = RestaurantReservation::where('table_id', $table->id)->whereIn('status', RestaurantReservation::ACTIVE)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->lockForUpdate()->orderBy('starts_at')->first();
        if ($clash) {
            throw new ReservationException(__('Table :table is booked for :name at :time.', ['table' => $table->name, 'name' => $clash->customer_name, 'time' => $clash->starts_at->format('H:i')]));
        }
    }

    private function parseStart(?string $date, ?string $time): Carbon
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ReservationException(__('Choose the date and time of the booking.'));
        }
        try {
            $start = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, config('app.timezone'));
        } catch (\Throwable $e) {
            throw new ReservationException(__('Choose the date and time of the booking.'));
        }
        if ($start->format('Y-m-d H:i') !== $date.' '.$time) {
            throw new ReservationException(__('Choose the date and time of the booking.'));
        }

        return $start->second(0);
    }

    private function transition(int $id, array $from, string $to, string $error): RestaurantReservation
    {
        return DB::transaction(function () use ($id, $from, $to, $error) {
            $r = $this->lockReservation($id);
            if (! in_array($r->status, $from, true)) {
                throw new ReservationException($error);
            }
            $r->update(['status' => $to]);

            return $r;
        });
    }

    private function lockReservation(int $id): RestaurantReservation
    {
        $r = RestaurantReservation::whereKey($id)->lockForUpdate()->first();
        if (! $r) {
            throw new ReservationException(__('This booking no longer exists.'), 404);
        }

        return $r;
    }

    private function lockWaiting(int $id): RestaurantWaitlistEntry
    {
        $e = RestaurantWaitlistEntry::whereKey($id)->lockForUpdate()->first();
        if (! $e) {
            throw new ReservationException(__('This party is no longer on the waitlist.'), 404);
        }

        return $e;
    }

    private function str($v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
