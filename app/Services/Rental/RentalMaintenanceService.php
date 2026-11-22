<?php

namespace App\Services\Rental;

use App\Models\RentalContractItem;
use App\Models\RentalExpense;
use App\Models\RentalInvoice;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Models\RentalMaintenanceSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maintenance jobs, preventive schedules, rental expenses and per-item
 * profitability.
 *
 * Item status: starting a job puts the item in `maintenance`; completing or
 * cancelling the last running job puts it back to `available`, after which
 * the contract sync re-derives rented/reserved. Scheduled jobs don't change
 * the status — they block the item over their window instead (see
 * RentalContractService::conflicts).
 */
class RentalMaintenanceService
{
    public function __construct(
        private RentalContractService $contracts,
        private RentalAccounting $accounting,
    ) {
    }

    public function nextNumber(): string
    {
        RentalContractService::lockNumbering();
        $last = RentalMaintenance::withTrashed()->where('number', 'LIKE', 'RMT-%')->pluck('number')
            ->map(fn ($n) => (int) substr($n, 4))->max();

        return 'RMT-' . str_pad((string) (($last ?: 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Jobs
    // ------------------------------------------------------------------

    public function create(array $data): RentalMaintenance
    {
        $user = auth()->user();
        $job = DB::transaction(fn () => RentalMaintenance::create(array_merge([
            'number' => $this->nextNumber(),
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $user instanceof \App\Models\User ? $user->id : null,
        ], $data)));

        if ($job->status === 'in_progress') {
            $this->start($job, true);
        }

        return $job;
    }

    /** A job opened from a return where the item was flagged "send to maintenance". */
    public function fromReturn(int $itemId, string $itemName, ?string $notes, int $contractId, int $handoverId): RentalMaintenance
    {
        return $this->create([
            'rental_item_id' => $itemId,
            'title' => 'Check after return: ' . Str::limit($itemName, 150),
            'type' => 'repair',
            'priority' => 'high',
            'source' => 'return',
            'rental_contract_id' => $contractId,
            'rental_handover_id' => $handoverId,
            'description' => $notes,
        ]);
    }

    public function start(RentalMaintenance $job, bool $already = false): void
    {
        if (! $already) {
            $job->update(['status' => 'in_progress', 'started_at' => $job->started_at ?: now()]);
        } elseif (! $job->started_at) {
            $job->update(['started_at' => now()]);
        }
        RentalItem::whereKey($job->rental_item_id)->update(['status' => 'maintenance']);
    }

    /**
     * Finish a job. $data: completed_at, resolution, cost, meter_reading,
     * condition, add_expense, payment_method_id, account_id.
     */
    public function complete(RentalMaintenance $job, array $data): void
    {
        DB::transaction(function () use ($job, $data) {
            $at = ! empty($data['completed_at']) ? Carbon::parse($data['completed_at'])->setTimezone(config('app.timezone')) : Carbon::now();
            $cost = round((float) ($data['cost'] ?? $job->cost), 2);
            $job->update([
                'status' => 'completed',
                'started_at' => $job->started_at ?: $at,
                'completed_at' => $at,
                'resolution' => $data['resolution'] ?? $job->resolution,
                'cost' => $cost,
                'meter_reading' => $data['meter_reading'] ?? $job->meter_reading,
            ]);

            $item = RentalItem::find($job->rental_item_id);
            if ($item) {
                $changes = [];
                if (! empty($data['condition']) && in_array($data['condition'], RentalItem::CONDITIONS, true)) {
                    $changes['condition'] = $data['condition'];
                }
                if (isset($data['meter_reading']) && $data['meter_reading'] !== null && $data['meter_reading'] !== '') {
                    $changes['meter_reading'] = (float) $data['meter_reading'];
                }
                if ($changes) {
                    $item->update($changes);
                }
            }

            if (! empty($data['add_expense']) && $cost > 0 && ! $job->expenses()->whereNull('deleted_at')->exists()) {
                $this->createExpense([
                    'rental_item_id' => $job->rental_item_id,
                    'rental_maintenance_id' => $job->id,
                    'category' => $job->type === 'repair' ? 'repair' : 'maintenance',
                    'amount' => $cost,
                    'expense_date' => $at->toDateString(),
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'account_id' => $data['account_id'] ?? null,
                    'vendor' => $job->vendor,
                    'description' => $job->number . ' — ' . $job->title,
                ]);
            }

            if ($job->schedule_id && ($s = RentalMaintenanceSchedule::find($job->schedule_id))) {
                $this->advanceSchedule($s, $at, $item ? $item->meter_reading : null);
            }

            $this->release($job->rental_item_id);
        });
    }

    public function cancel(RentalMaintenance $job, ?string $reason): void
    {
        $job->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
        $this->skipCycle($job);
        $this->release($job->rental_item_id);
    }

    /**
     * A schedule's job was cancelled or deleted without being done: move the
     * schedule on by one interval, or runSchedules() would open the same job
     * again straight away.
     */
    public function skipCycle(RentalMaintenance $job): void
    {
        if (! $job->schedule_id || ! ($s = RentalMaintenanceSchedule::whereNull('deleted_at')->find($job->schedule_id))) {
            return;
        }
        $changes = [];
        if ($s->interval_value && $s->interval_unit) {
            $from = $s->next_due_date && $s->next_due_date->gt(Carbon::today()) ? $s->next_due_date->copy() : Carbon::today();
            $changes['next_due_date'] = $this->contracts->addPeriods($from, $s->interval_unit, (int) $s->interval_value)->toDateString();
        }
        if ($s->meter_interval && $s->next_due_meter !== null) {
            $reading = (float) optional($s->item)->meter_reading;
            $changes['next_due_meter'] = round(max((float) $s->next_due_meter, $reading) + (float) $s->meter_interval, 2);
        }
        if ($changes) {
            $s->update($changes);
        }
    }

    /** Back to available once no job is running on the item; then re-derive rented/reserved. */
    public function release(int $itemId): void
    {
        $running = RentalMaintenance::whereNull('deleted_at')->where('rental_item_id', $itemId)
            ->where('status', 'in_progress')->exists();
        if ($running) {
            return;
        }
        RentalItem::whereKey($itemId)->where('status', 'maintenance')->update(['status' => 'available']);
        $this->contracts->sync([$itemId]);
    }

    // ------------------------------------------------------------------
    // Preventive schedules
    // ------------------------------------------------------------------

    /** Next due date/meter after a job was done. */
    public function advanceSchedule(RentalMaintenanceSchedule $s, Carbon $doneAt, $meter): void
    {
        $next = null;
        if ($s->interval_value && $s->interval_unit) {
            $next = $this->contracts->addPeriods($doneAt->copy()->startOfDay(), $s->interval_unit, (int) $s->interval_value);
        }
        $s->update([
            'last_done_at' => $doneAt->toDateString(),
            'last_meter' => $meter,
            'next_due_date' => optional($next)->toDateString(),
            'next_due_meter' => $this->nextMeter($s, $meter),
        ]);
    }

    /** Next meter target: the reading at completion (else the item's) + interval, else the old target + interval. */
    private function nextMeter(RentalMaintenanceSchedule $s, $meter): ?float
    {
        if (! $s->meter_interval) {
            return null;
        }
        $base = $meter ?? optional($s->item)->meter_reading ?? $s->next_due_meter;

        return $base === null ? null : round((float) $base + (float) $s->meter_interval, 2);
    }

    /**
     * Open a job for every active schedule that is coming due — by date
     * (within its lead days) or by meter — unless one is already open.
     * Returns how many jobs were created.
     */
    public function runSchedules(): int
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('rental-maintenance-schedules', 120);
        if (! $lock->get()) {
            return 0; // another request is already doing it
        }
        try {
            return $this->openDueJobs();
        } finally {
            $lock->release();
        }
    }

    private function openDueJobs(): int
    {
        $today = Carbon::today();
        $made = 0;

        RentalMaintenanceSchedule::with('item')->whereNull('deleted_at')->where('active', true)->get()
            ->each(function (RentalMaintenanceSchedule $s) use ($today, &$made) {
                if (! $s->item || $s->item->deleted_at) {
                    return;
                }
                $byDate = $s->next_due_date && $s->next_due_date->copy()->subDays((int) $s->lead_days)->lte($today);
                $byMeter = $s->next_due_meter !== null && $s->item->meter_reading !== null
                    && (float) $s->item->meter_reading >= (float) $s->next_due_meter;
                if (! $byDate && ! $byMeter) {
                    return;
                }
                $open = RentalMaintenance::whereNull('deleted_at')->where('schedule_id', $s->id)
                    ->whereIn('status', RentalMaintenance::OPEN)->exists();
                if ($open) {
                    return;
                }

                $start = ($byDate && $s->next_due_date->gte($today) ? $s->next_due_date->copy() : $today->copy())->setTime(9, 0);
                $this->create([
                    'rental_item_id' => $s->rental_item_id,
                    'title' => $s->title,
                    'type' => $s->type,
                    'priority' => 'normal',
                    'status' => 'scheduled',
                    'source' => 'schedule',
                    'schedule_id' => $s->id,
                    'scheduled_start' => $start,
                    'scheduled_end' => $start->copy()->addHours(max(1, (int) $s->duration_hours)),
                    'description' => $s->notes,
                ]);
                $made++;
            });

        return $made;
    }

    // ------------------------------------------------------------------
    // Expenses
    // ------------------------------------------------------------------

    public function createExpense(array $data, $file = null): RentalExpense
    {
        if ($file) {
            $dir = upload_public_path(RentalExpense::FILE_DIR);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = Str::random(40) . '.' . \App\Services\Rental\RentalUpload::extension($file, 'receipt');
            $file->move($dir, $name);
            $data['attachment'] = $name;
        }
        $expense = RentalExpense::create(array_merge(['created_by' => optional(auth()->user())->id], $data));
        $this->accounting->expensePaid($expense);

        return $expense;
    }

    public function deleteExpense(RentalExpense $expense): void
    {
        $this->accounting->expensePaid($expense, true);
        $expense->delete();
    }

    // ------------------------------------------------------------------
    // Profitability
    // ------------------------------------------------------------------

    /**
     * Income attributed to one item: each non-void invoice of a contract the
     * item is on, split by the item's share of that contract's rent. Returns
     * [YYYY-MM => amount] by invoice issue month plus the total.
     */
    public function itemIncome(int $itemId, ?Carbon $from = null): array
    {
        $lines = RentalContractItem::where('rental_item_id', $itemId)->get(['rental_contract_id', 'amount']);
        if ($lines->isEmpty()) {
            return ['total' => 0.0, 'months' => []];
        }
        $share = [];
        $rents = RentalContractItem::whereIn('rental_contract_id', $lines->pluck('rental_contract_id'))
            ->selectRaw('rental_contract_id, SUM(amount) as rent')->groupBy('rental_contract_id')->pluck('rent', 'rental_contract_id');
        foreach ($lines as $l) {
            $rent = (float) ($rents[$l->rental_contract_id] ?? 0);
            $share[$l->rental_contract_id] = ($share[$l->rental_contract_id] ?? 0) + ($rent > 0 ? (float) $l->amount / $rent : 0);
        }

        $months = [];
        $total = 0.0;
        RentalInvoice::whereIn('rental_contract_id', array_keys($share))
            ->where('status', '!=', 'void')
            ->when($from, fn ($q) => $q->where('issue_date', '>=', $from->toDateString()))
            ->get(['rental_contract_id', 'amount', 'late_fee', 'late_fee_waived', 'issue_date'])
            ->each(function ($inv) use ($share, &$months, &$total) {
                $value = round($inv->total() * $share[$inv->rental_contract_id], 2);
                $key = $inv->issue_date->format('Y-m');
                $months[$key] = round(($months[$key] ?? 0) + $value, 2);
                $total += $value;
            });

        return ['total' => round($total, 2), 'months' => $months];
    }

    /** Days the item spent on contracts over the last N days, as a percentage. */
    public function utilization(RentalItem $item, int $days = 365): float
    {
        $from = Carbon::now()->subDays($days);
        $now = Carbon::now();
        $covered = 0.0;

        RentalContractItem::join('rental_contracts', 'rental_contracts.id', '=', 'rental_contract_items.rental_contract_id')
            ->where('rental_contract_items.rental_item_id', $item->id)
            ->whereIn('rental_contracts.status', ['active', 'ended', 'terminated', 'renewed'])
            ->whereNull('rental_contracts.deleted_at')
            ->get(['rental_contracts.start_date', 'rental_contracts.end_date', 'rental_contracts.ended_at',
                'rental_contract_items.returned_at', 'rental_contract_items.quantity'])
            ->each(function ($r) use ($from, $now, &$covered, $item) {
                $s = Carbon::parse($r->start_date)->max($from);
                $endRaw = $r->returned_at ?? $r->ended_at ?? $r->end_date;
                $e = ($endRaw ? Carbon::parse($endRaw) : $now)->min($now);
                if ($e->gt($s)) {
                    $covered += $s->diffInMinutes($e) / 1440 * min(1, (int) $r->quantity / max(1, (int) $item->quantity));
                }
            });

        return round(min(100, $covered / $days * 100), 1);
    }
}
