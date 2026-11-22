<?php

namespace App\Services\Rental;

use App\Models\RentalContract;
use App\Models\RentalContractEvent;
use App\Models\RentalContractItem;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Models\RentalReservation;
use App\Models\RentalReservationItem;
use App\Models\RentalSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rules shared by everything that touches rental contracts:
 * numbering, period counting and pricing, double-booking checks, the
 * history log, and keeping each rental item's status in step with the
 * contracts that hold it.
 *
 * Item status is driven by ACTIVE contracts only (drafts never block
 * anything): an item out on a started contract is `rented`, one booked for
 * later is `reserved`, otherwise `available`. Items an operator put in
 * `maintenance` or `inactive` are never touched here.
 */
class RentalContractService
{
    /** Next contract number with the configured prefix: RNT-0001… (soft-deleted rows count too). */
    public function nextNumber(): string
    {
        self::lockNumbering();
        $prefix = trim((string) RentalSetting::current()->contract_prefix) ?: 'RNT';

        $last = RentalContract::withTrashed()
            ->where('contract_number', 'LIKE', $prefix . '-%')
            ->pluck('contract_number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))
            ->max();

        return $prefix . '-' . str_pad((string) (($last ?: 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Whole billing periods between two moments, rounded UP (a 26-hour daily
     * rental is 2 days). Calendar months/years are used, so Jan 31 → Feb 28
     * is one month.
     */
    public function periodsBetween(Carbon $start, Carbon $end, string $period): int
    {
        if ($end->lte($start)) {
            return 1;
        }

        switch ($period) {
            case 'hour':
                return max(1, (int) ceil($start->diffInMinutes($end) / 60));
            case 'day':
                return max(1, (int) ceil($start->diffInMinutes($end) / 1440));
            case 'week':
                return max(1, (int) ceil($start->diffInMinutes($end) / 10080));
            case 'month':
                $n = (int) $start->diffInMonths($end);
                return max(1, $start->copy()->addMonthsNoOverflow($n)->lt($end) ? $n + 1 : $n);
            case 'year':
                $n = (int) $start->diffInYears($end);
                return max(1, $start->copy()->addYearsNoOverflow($n)->lt($end) ? $n + 1 : $n);
        }

        return 1;
    }

    /** End moment after N periods — used by renewals and the form's quick-duration buttons. */
    public function addPeriods(Carbon $start, string $period, int $count): Carbon
    {
        $d = $start->copy();
        switch ($period) {
            case 'hour': return $d->addHours($count);
            case 'day': return $d->addDays($count);
            case 'week': return $d->addWeeks($count);
            case 'month': return $d->addMonthsNoOverflow($count);
            case 'year': return $d->addYearsNoOverflow($count);
        }

        return $d;
    }

    /**
     * The contract's money, from its lines.
     *
     *  rent_amount    Σ rate × qty — one period's rent
     *  upfront        one bill for the whole term: rent × periods − discount, + tax
     *  recurring      one bill per period: rent − discount, + tax; contract_value = bill × periods
     *
     * @param  array  $lines  [['rate' => x, 'quantity' => n], …]
     */
    public function calculate(array $lines, Carbon $start, ?Carbon $end, string $period, string $billing, float $discount, float $taxRate): array
    {
        $rent = 0.0;
        foreach ($lines as $line) {
            $rent += round((float) $line['rate'] * (int) $line['quantity'], 2);
        }

        $periods = $end ? $this->periodsBetween($start, $end, $period) : null;

        $base = $billing === 'upfront' ? $rent * ($periods ?: 1) : $rent;
        $net = max(0, round($base - $discount, 2));
        $tax = round($net * $taxRate / 100, 2);
        $total = round($net + $tax, 2);

        $value = $billing === 'upfront'
            ? $total
            : ($periods ? round($total * $periods, 2) : null);

        return [
            'rent_amount' => round($rent, 2),
            'periods' => $periods,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'contract_value' => $value,
        ];
    }

    /** Next reservation number with the configured prefix: RSV-0001… */
    public function nextReservationNumber(): string
    {
        self::lockNumbering();
        $prefix = trim((string) RentalSetting::current()->reservation_prefix) ?: 'RSV';

        $last = RentalReservation::withTrashed()
            ->where('reservation_number', 'LIKE', $prefix . '-%')
            ->pluck('reservation_number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))
            ->max();

        return $prefix . '-' . str_pad((string) (($last ?: 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Every booking that holds items, as flat rows: ACTIVE contracts and LIVE
     * reservations (held, hold not run out). Optionally limited to some items
     * and minus one contract / reservation (the one being edited).
     *
     * Rows: rental_item_id, quantity, number, start_date, end_date, kind
     */
    public function holdingRows(?array $itemIds = null, int|array|null $ignoreContractId = null, ?int $ignoreReservationId = null)
    {
        $contracts = RentalContractItem::query()
            ->join('rental_contracts', 'rental_contracts.id', '=', 'rental_contract_items.rental_contract_id')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('rental_contract_items.rental_item_id', $itemIds))
            ->where('rental_contracts.status', 'active')
            ->whereNull('rental_contracts.deleted_at')
            ->when($ignoreContractId, fn ($q) => $q->whereNotIn('rental_contracts.id', array_filter((array) $ignoreContractId)))
            // A line handed back early no longer holds its item.
            ->whereNull('rental_contract_items.returned_at')
            ->get([
                'rental_contract_items.rental_item_id',
                'rental_contract_items.quantity',
                'rental_contract_items.checked_out_at',
                'rental_contracts.contract_number as number',
                'rental_contracts.start_date',
                'rental_contracts.end_date',
            ])->each(fn ($r) => $r->kind = 'contract');

        $holds = RentalReservationItem::query()
            ->join('rental_reservations', 'rental_reservations.id', '=', 'rental_reservation_items.rental_reservation_id')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('rental_reservation_items.rental_item_id', $itemIds))
            ->where('rental_reservations.status', 'held')
            ->where('rental_reservations.hold_until', '>', Carbon::now())
            ->whereNull('rental_reservations.deleted_at')
            ->when($ignoreReservationId, fn ($q) => $q->where('rental_reservations.id', '!=', $ignoreReservationId))
            ->get([
                'rental_reservation_items.rental_item_id',
                'rental_reservation_items.quantity',
                'rental_reservations.reservation_number as number',
                'rental_reservations.start_date',
                'rental_reservations.end_date',
            ])->each(fn ($r) => $r->kind = 'reservation');

        return $contracts->concat($holds)->values();
    }

    /** Scheduled / running maintenance jobs as blocking rows (same shape as holdingRows). */
    public function maintenanceRows(array $itemIds)
    {
        return RentalMaintenance::whereNull('deleted_at')->whereIn('rental_item_id', $itemIds)
            ->whereIn('status', ['scheduled', 'in_progress'])->get()
            ->map(function (RentalMaintenance $m) {
                $w = $m->blockWindow();
                if (! $w) {
                    return null;
                }

                return (object) [
                    'rental_item_id' => $m->rental_item_id,
                    'quantity' => (int) $m->quantity,
                    'number' => $m->number,
                    'start_date' => $w[0],
                    'end_date' => $w[1],
                    'checked_out_at' => null,
                    'kind' => 'maintenance',
                ];
            })->filter()->values();
    }

    /**
     * Double-booking check for a set of lines over [start, end].
     *
     * Every ACTIVE contract and LIVE reservation holding one of the items counts against it,
     * widened by the settings' buffer on both sides. An active contract whose
     * end has passed still holds its items (they haven't come back), so its
     * effective end is "now" at the earliest. Open-ended contracts block
     * everything after their start.
     *
     * @param  array  $lines  [item_id => quantity]
     * @return array  one entry per problem: [item_id, item_name, reason, requested, available, contracts]
     */
    public function conflicts(array $lines, Carbon $start, ?Carbon $end, int|array|null $ignoreContractId = null, ?int $ignoreReservationId = null): array
    {
        if (! $lines) {
            return [];
        }

        $buffer = (int) RentalSetting::current()->buffer_hours;
        $from = $start->copy()->subHours($buffer);
        $to = $end ? $end->copy()->addHours($buffer) : null;
        $now = Carbon::now();

        $items = RentalItem::whereIn('id', array_keys($lines))->get()->keyBy('id');

        $held = $this->holdingRows(array_keys($lines), $ignoreContractId, $ignoreReservationId)
            ->concat($this->maintenanceRows(array_keys($lines)));
        // Latest planned end of the job(s) running on each item, for the
        // "in maintenance" check below.
        $runningUntil = RentalMaintenance::whereNull('deleted_at')->whereIn('rental_item_id', array_keys($lines))
            ->where('status', 'in_progress')->get()->groupBy('rental_item_id')
            ->map(fn ($jobs) => $jobs->contains(fn ($j) => ! $j->scheduled_end) ? null : $jobs->max('scheduled_end'));

        $problems = [];
        foreach ($lines as $itemId => $qty) {
            $item = $items->get($itemId);
            if (! $item || $item->deleted_at) {
                $problems[] = ['item_id' => $itemId, 'item_name' => '#' . $itemId, 'reason' => 'missing', 'requested' => $qty, 'available' => 0, 'contracts' => []];
                continue;
            }
            // Inactive items are never rentable. An item in maintenance is
            // blocked until its running job's planned end; with no job (or no
            // planned end) we can't know when it is back, so it stays blocked.
            $maintenanceEnds = $runningUntil->has($itemId) ? $runningUntil[$itemId] : false;
            $blockedByMaintenance = $item->status === 'maintenance'
                && ($maintenanceEnds === false || $maintenanceEnds === null || $from->lt(Carbon::parse($maintenanceEnds)));
            if ($item->status === 'inactive' || $blockedByMaintenance) {
                $problems[] = ['item_id' => $itemId, 'item_name' => $item->name, 'reason' => $item->status, 'requested' => $qty, 'available' => 0, 'contracts' => []];
                continue;
            }

            $overlapping = $held->where('rental_item_id', $itemId)->filter(function ($row) use ($from, $to, $now) {
                $s = Carbon::parse($row->start_date);
                // Handed over before the start: the item is already out.
                if (! empty($row->checked_out_at)) {
                    $s = $s->min(Carbon::parse($row->checked_out_at));
                }
                $e = $row->end_date ? Carbon::parse($row->end_date) : null;
                if ($e && $e->lt($now)) {
                    $e = $now->copy(); // overdue — still out
                }

                $startsBeforeWindowEnds = $to === null || $s->lt($to);
                $endsAfterWindowStarts = $e === null || $e->gt($from);

                return $startsBeforeWindowEnds && $endsAfterWindowStarts;
            });

            $taken = (int) $overlapping->sum('quantity');
            $available = max(0, (int) $item->quantity - $taken);

            if ($qty > $available) {
                $problems[] = [
                    'item_id' => $itemId,
                    'item_name' => $item->name,
                    'reason' => 'booked',
                    'requested' => (int) $qty,
                    'available' => $available,
                    'contracts' => $overlapping->pluck('number')->unique()->values()->all(),
                ];
            }
        }

        return $problems;
    }

    /** Human sentence for a conflicts() list, for 422 messages. */
    public function describeConflicts(array $problems): string
    {
        return collect($problems)->map(function ($p) {
            switch ($p['reason']) {
                case 'maintenance':
                    return "{$p['item_name']} is in maintenance.";
                case 'inactive':
                    return "{$p['item_name']} is inactive.";
                case 'missing':
                    return "Item {$p['item_name']} no longer exists.";
            }
            $by = $p['contracts'] ? ' (booked on ' . implode(', ', $p['contracts']) . ')' : '';

            return "{$p['item_name']}: {$p['available']} of the requested {$p['requested']} free for these dates{$by}.";
        })->implode(' ');
    }

    public function log(RentalContract $contract, string $type, ?string $note = null, array $meta = []): void
    {
        RentalContractEvent::create([
            'rental_contract_id' => $contract->id,
            'type' => $type,
            'note' => $note,
            'meta' => $meta ?: null,
            // Portal actions run under the portal guard; only staff users are recorded.
            'user_id' => auth()->user() instanceof \App\Models\User ? auth()->id() : null,
        ]);
    }

    /**
     * Brings stored statuses in line with the clock:
     *  1. an active contract that was renewed (its renewal is live) and has
     *     reached its end date becomes `renewed`;
     *  2. item statuses are recomputed from active contracts.
     *
     * Pass item ids to limit step 2 to those items (after a contract action);
     * with none, every item that is or should be rented/reserved is checked.
     * Cheap enough to run on every dashboard / list load.
     */
    public function sync(?array $itemIds = null): void
    {
        $now = Carbon::now();

        RentalContract::where('status', 'active')
            ->whereNotNull('renewal_id')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $now)
            ->whereHas('renewal', fn ($q) => $q->whereIn('status', ['active', 'ended', 'terminated', 'renewed']))
            ->get()
            ->each(function (RentalContract $c) {
                $c->update(['status' => 'renewed', 'ended_at' => $c->end_date]);
                $this->log($c, 'renewed', null, ['renewal_id' => $c->renewal_id]);
            });

        // Holds that ran out free their items.
        $expired = RentalReservation::where('status', 'held')->where('hold_until', '<=', $now)->pluck('id');
        if ($expired->isNotEmpty()) {
            $expiredItems = RentalReservationItem::whereIn('rental_reservation_id', $expired)->pluck('rental_item_id')->all();
            RentalReservation::whereIn('id', $expired)->update(['status' => 'expired']);
            if ($itemIds !== null) {
                $itemIds = array_merge($itemIds, $expiredItems);
            }
        }

        if ($itemIds === null) {
            $itemIds = RentalItem::whereNull('deleted_at')->whereIn('status', ['rented', 'reserved'])->pluck('id')
                ->merge($this->holdingRows()->pluck('rental_item_id'))
                ->unique()->values()->all();
        }
        if (! $itemIds) {
            return;
        }

        $lines = $this->holdingRows(array_values(array_unique($itemIds)));

        RentalItem::whereIn('id', $itemIds)->whereNull('deleted_at')
            ->whereNotIn('status', ['maintenance', 'inactive'])
            ->get()
            ->each(function (RentalItem $item) use ($lines, $now) {
                $mine = $lines->where('rental_item_id', $item->id);
                // Only a started CONTRACT puts an item out; a reservation, even
                // one whose dates have begun, only reserves it.
                // A line handed over early is out even before its start date.
                $isOut = fn ($l) => $l->kind === 'contract' && ($l->checked_out_at || Carbon::parse($l->start_date)->lte($now));
                $outNow = (int) $mine->filter($isOut)->sum('quantity');
                $later = (int) $mine->reject($isOut)->sum('quantity');

                // A pool (quantity > 1) only reads "rented" once every unit is out.
                if ($outNow > 0 && $outNow >= $item->quantity) {
                    $status = 'rented';
                } elseif ($outNow === 0 && $later > 0 && $item->quantity <= 1) {
                    $status = 'reserved';
                } else {
                    $status = 'available';
                }

                if ($item->status !== $status) {
                    $item->update(['status' => $status]);
                }
            });
    }

    /**
     * Store an on-screen signature (PNG data URL) on a contract, replacing any
     * earlier one. `$via` = 'staff' or 'portal' (kept in the history).
     * Returns false when the image is not a real PNG.
     */
    public function saveSignature(RentalContract $contract, string $name, string $dataUrl, string $via = 'staff'): bool
    {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $m)) {
            return false;
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strncmp($png, "\x89PNG", 4) !== 0) {
            return false;
        }

        $dir = upload_public_path(RentalContract::FILE_DIR);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = 'sig_' . \Illuminate\Support\Str::random(36) . '.png';
        file_put_contents($dir . '/' . $file, $png);

        $old = $contract->signature;
        if ($old && ! str_contains($old, '/') && ! str_contains($old, '\\')) {
            @unlink($dir . '/' . $old);
        }
        $contract->update(['signed_name' => $name, 'signature' => $file, 'signed_at' => now()]);
        $this->log($contract, 'signed', $via === 'portal' ? 'Signed by the customer in the portal' : null, ['name' => $name, 'via' => $via]);

        return true;
    }

    /** Ids of the items on a contract. */
    public function itemIds(RentalContract $contract): array
    {
        return $contract->items()->pluck('rental_item_id')->unique()->values()->all();
    }

    /** Runs $fn in a transaction, then re-syncs the given items. */
    /**
     * Run a booking change in a transaction. The items' rows are locked first
     * (FOR UPDATE), so two requests booking the same items are serialised and
     * an availability check made inside $fn sees the other one's result.
     */
    public function transact(callable $fn, array $itemIds = [])
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $result = DB::transaction(function () use ($fn, $ids) {
            if ($ids) {
                RentalItem::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            return $fn();
        });
        $this->sync($ids ?: null);

        return $result;
    }

    /**
     * Serialise document numbering (read max + 1) for the rest of the current
     * transaction by locking the single settings row. No-op outside one.
     */
    public static function lockNumbering(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::table('rental_settings')->orderBy('id')->limit(1)->lockForUpdate()->get(['id']);
        }
    }
}
