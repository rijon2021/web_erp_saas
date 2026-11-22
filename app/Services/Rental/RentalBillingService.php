<?php

namespace App\Services\Rental;

use App\Models\RentalContract;
use App\Models\RentalInvoice;
use App\Models\RentalPayment;
use App\Models\RentalPaymentAllocation;
use App\Models\RentalSetting;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns contracts into invoices and keeps invoices up to date.
 *
 *  - recurring: one invoice per period, issued `invoice_lead_days` before the
 *    period starts, due on the period's first day (rent in advance);
 *  - upfront: one invoice for the whole term, due on the start date;
 *  - late fees accrue on unsettled invoices once due date + grace has passed,
 *    using the contract's own terms (once or daily, fixed or %);
 *  - periods after a contract closed are never billed, and unpaid invoices
 *    for them are voided when it closes.
 */
class RentalBillingService
{
    /** Safety cap on invoices issued for one contract in one run. */
    private const MAX_PER_RUN = 500;

    public function __construct(
        private RentalContractService $contracts,
        private RentalAccounting $accounting,
    ) {
    }

    /** Issue due invoices and refresh late fees — cheap enough for every page load. */
    public function run(?int $contractId = null): void
    {
        RentalContract::whereNull('deleted_at')
            ->whereIn('status', ['active', 'ended', 'terminated', 'renewed'])
            ->when($contractId, fn ($q) => $q->whereKey($contractId))
            ->get()
            ->each(fn ($c) => $this->issueDue($c));

        $this->applyLateFees($contractId);
    }

    public function nextInvoiceNumber(): string
    {
        $prefix = trim((string) RentalSetting::current()->invoice_prefix) ?: 'RINV';

        return $this->next(RentalInvoice::query(), 'invoice_number', $prefix);
    }

    public function nextPaymentNumber(): string
    {
        return $this->next(RentalPayment::withTrashed(), 'payment_number', 'RP');
    }

    private function next($query, string $column, string $prefix): string
    {
        RentalContractService::lockNumbering();
        $last = $query->where($column, 'LIKE', $prefix . '-%')->pluck($column)
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))->max();

        return $prefix . '-' . str_pad((string) (($last ?: 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Where billing for a contract stops: the moment it actually closed, or
     * its agreed end. Null = open-ended and still running.
     */
    private function billingEnd(RentalContract $c): ?Carbon
    {
        if (in_array($c->status, ['terminated', 'ended', 'renewed'], true) && $c->ended_at) {
            return $c->end_date && $c->end_date->lt($c->ended_at) ? $c->end_date : $c->ended_at;
        }

        return $c->end_date;
    }

    /** Terminated / cancelled contracts never get more rent invoices. */
    private function closedEarly(RentalContract $c): bool
    {
        return in_array($c->status, ['terminated', 'cancelled'], true);
    }

    public function issueDue(RentalContract $c): void
    {
        if (! $c->activated_at && $c->status !== 'active') {
            return; // never went live (e.g. a draft that was closed)
        }

        if ($c->billing_type === 'upfront') {
            $rent = RentalInvoice::where('rental_contract_id', $c->id)->where('kind', 'rent');
            if (! (clone $rent)->exists()) {
                $this->create($c, 0, $c->start_date, $c->end_date ?: $c->start_date, [
                    'subtotal' => round((float) $c->rent_amount * max(1, (int) $c->periods), 2),
                ]);

                return;
            }
            // Extended after it was billed: invoice the added time once (the
            // sequence + period unique key keeps this idempotent).
            $billedTo = (clone $rent)->where('status', '!=', 'void')->max('period_end');
            if ($billedTo && $c->end_date && $c->end_date->gt(Carbon::parse($billedTo)) && ! $this->closedEarly($c)) {
                $from = Carbon::parse($billedTo);
                $periods = max(1, (int) $this->contracts->periodsBetween($from, $c->end_date, $c->rate_period));
                $sub = round((float) $c->rent_amount * $periods, 2);
                $tax = round($sub * (float) $c->tax_rate / 100, 2);
                $this->create($c, (int) (clone $rent)->max('sequence') + 1, $from, $c->end_date->copy(), [
                    'subtotal' => $sub,
                    'discount' => 0,
                    'tax_amount' => $tax,
                    'amount' => round($sub + $tax, 2),
                    'status' => $sub + $tax > 0 ? 'unpaid' : 'paid',
                ]);
            }

            return;
        }

        $lead = (int) (RentalSetting::current()->invoice_lead_days ?? 7);
        $horizon = Carbon::now()->addDays($lead);
        $stop = $this->billingEnd($c);
        $issued = RentalInvoice::where('rental_contract_id', $c->id)->where('kind', 'rent')->pluck('sequence')->flip();

        for ($i = 0, $made = 0; $made < self::MAX_PER_RUN; $i++) {
            $from = $this->contracts->addPeriods($c->start_date, $c->rate_period, $i);
            if ($from->gt($horizon) || ($stop && $from->gte($stop))) {
                break;
            }
            if (isset($issued[$i])) {
                continue;
            }
            $to = $this->contracts->addPeriods($c->start_date, $c->rate_period, $i + 1);
            if ($stop && $to->gt($stop)) {
                $to = $stop->copy();
            }
            $this->create($c, $i, $from, $to, ['subtotal' => (float) $c->rent_amount]);
            $made++;
        }
    }

    private function create(RentalContract $c, int $sequence, Carbon $from, Carbon $to, array $extra): void
    {
        try {
            $invoice = DB::transaction(fn () => RentalInvoice::create(array_merge([
                'invoice_number' => $this->nextInvoiceNumber(),
                'rental_contract_id' => $c->id,
                'rental_customer_id' => $c->rental_customer_id,
                'client_id' => $c->client_id,
                'kind' => 'rent',
                'sequence' => $sequence,
                'period_start' => $from,
                'period_end' => $to,
                'issue_date' => Carbon::today(),
                'due_date' => $from->copy()->startOfDay(),
                'discount' => (float) $c->discount,
                'tax_amount' => (float) $c->tax_amount,
                'amount' => (float) $c->total_amount,
                'status' => (float) $c->total_amount > 0 ? 'unpaid' : 'paid',
            ], $extra)));
            $this->accounting->invoiceIssued($invoice);
        } catch (QueryException $e) {
            // Another run issued this period first (unique key) — nothing to do.
            if (! str_contains($e->getMessage(), 'rental_invoices_period_unique')) {
                throw $e;
            }
        }
    }

    /** A one-off charge (damage, cleaning, extra hours…) on a contract. */
    public function charge(RentalContract $c, string $description, float $amount, Carbon $due, ?string $notes = null): RentalInvoice
    {
        $user = auth()->user();
        $invoice = DB::transaction(fn () => RentalInvoice::create([
            'invoice_number' => $this->nextInvoiceNumber(),
            'rental_contract_id' => $c->id,
            'rental_customer_id' => $c->rental_customer_id,
            'client_id' => $c->client_id,
            'kind' => 'charge',
            'description' => $description,
            'issue_date' => Carbon::today(),
            'due_date' => $due->copy()->startOfDay(),
            'subtotal' => $amount,
            'amount' => $amount,
            'status' => $amount > 0 ? 'unpaid' : 'paid',
            'notes' => $notes,
            'created_by' => $user instanceof \App\Models\User ? $user->id : null,
        ]));
        $this->accounting->invoiceIssued($invoice);

        return $invoice;
    }

    /**
     * Late fee for each unsettled invoice past due + grace:
     *   once  — fixed amount, or % of the invoice
     *   daily — the same, times the days late beyond the grace period
     */
    public function applyLateFees(?int $contractId = null): void
    {
        $today = Carbon::today();

        RentalInvoice::with('contract')
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('late_fee_waived', false)
            ->where('due_date', '<', $today)
            ->when($contractId, fn ($q) => $q->where('rental_contract_id', $contractId))
            ->get()
            ->each(function (RentalInvoice $inv) use ($today) {
                $c = $inv->contract;
                if (! $c || $c->status === 'cancelled' || $c->late_fee_type === 'none' || (float) $c->late_fee_value <= 0) {
                    return;
                }
                $lateFrom = $inv->due_date->copy()->addDays((int) $c->grace_days);
                if (! $lateFrom->lt($today)) {
                    return;
                }

                $unit = $c->late_fee_type === 'percent'
                    ? round((float) $inv->amount * (float) $c->late_fee_value / 100, 2)
                    : (float) $c->late_fee_value;
                $days = (int) $lateFrom->diffInDays($today);
                $fee = $c->late_fee_frequency === 'daily' ? round($unit * max(1, $days), 2) : $unit;

                if (abs($fee - (float) $inv->late_fee) >= 0.005) {
                    $inv->update(['late_fee' => $fee]);
                    $this->refreshStatus($inv);
                }
            });
    }

    /** paid / partial / unpaid from the amounts (void stays void). */
    public function refreshStatus(RentalInvoice $inv): void
    {
        if ($inv->status === 'void') {
            return;
        }
        $paid = (float) $inv->paid_amount;
        $total = $inv->total();
        $status = $paid + 0.005 >= $total ? 'paid' : ($paid <= 0 ? 'unpaid' : 'partial');
        if ($inv->status !== $status) {
            $inv->update(['status' => $status]);
        }
        // Settling (or waiving) is when an accrued late fee becomes a journal line.
        if ($status === 'paid' || $inv->late_fee_waived) {
            $this->accounting->syncLateFee($inv);
        }
    }

    /**
     * Spread a payment over invoices: the chosen one, else the contract's (or
     * the customer's) oldest unsettled invoices first. Returns the remainder
     * that found no invoice (callers refuse overpayments, so normally 0).
     */
    public function allocate(RentalPayment $payment, ?int $invoiceId = null): float
    {
        $left = (float) $payment->amount;

        $invoices = RentalInvoice::whereIn('status', ['unpaid', 'partial'])
            ->when($invoiceId, fn ($q) => $q->whereKey($invoiceId))
            ->when(! $invoiceId && $payment->rental_contract_id, fn ($q) => $q->where('rental_contract_id', $payment->rental_contract_id))
            ->when(! $invoiceId && ! $payment->rental_contract_id, fn ($q) => $q->where('rental_customer_id', $payment->rental_customer_id))
            ->orderBy('due_date')->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $inv) {
            if ($left <= 0.004) {
                break;
            }
            $take = min($left, $inv->balance());
            if ($take <= 0) {
                continue;
            }
            RentalPaymentAllocation::create([
                'rental_payment_id' => $payment->id,
                'rental_invoice_id' => $inv->id,
                'amount' => round($take, 2),
            ]);
            $inv->update(['paid_amount' => round((float) $inv->paid_amount + $take, 2)]);
            $this->refreshStatus($inv);
            $left = round($left - $take, 2);
        }

        return $left;
    }

    /** Undo a payment's allocations (the payment itself is soft-deleted by the caller). */
    public function unallocate(RentalPayment $payment): void
    {
        foreach ($payment->allocations()->with('invoice')->get() as $a) {
            if ($inv = $a->invoice) {
                $inv->update(['paid_amount' => max(0, round((float) $inv->paid_amount - (float) $a->amount, 2))]);
                // A late fee already journaled stays: it is still owed, now
                // as an open receivable again.
                $this->refreshStatus($inv->fresh());
            }
            $a->delete();
        }
    }

    public function void(RentalInvoice $inv, ?string $reason = null): void
    {
        $inv->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);
        $this->accounting->invoiceIssued($inv, true);
        $this->accounting->syncLateFee($inv);
    }

    /**
     * After a contract closes: void unpaid invoices for periods that start
     * after it closed (they were issued ahead of time). Cancelled contracts
     * lose every invoice nobody paid towards.
     */
    public function onContractClosed(RentalContract $c): void
    {
        $query = RentalInvoice::where('rental_contract_id', $c->id)->where('kind', 'rent')
            ->where('status', 'unpaid')->where('paid_amount', 0);

        if ($c->status !== 'cancelled') {
            // An upfront invoice is the price of the whole agreed term; closing
            // early doesn't cancel it (any refund is a deliberate decision).
            if ($c->billing_type === 'upfront') {
                return;
            }
            $stop = $this->billingEnd($c);
            if (! $stop) {
                return;
            }
            $query->where('period_start', '>=', $stop);
        }

        DB::transaction(fn () => $query->get()->each(fn ($inv) => $this->void($inv, 'Contract ' . $c->status)));
    }

    /**
     * Money summary for a set of invoices (contract or customer scope):
     * billed, paid, outstanding, overdue and accrued late fees.
     */
    public function summary($invoices, int $graceDays = 0): array
    {
        $live = $invoices->where('status', '!=', 'void');

        return [
            'billed' => round($live->sum(fn ($i) => $i->total()), 2),
            'paid' => round((float) $live->sum('paid_amount'), 2),
            'outstanding' => round($live->sum(fn ($i) => $i->balance()), 2),
            'overdue' => round($live->filter(fn ($i) => $i->isOverdue($graceDays ?: (int) optional($i->contract)->grace_days))->sum(fn ($i) => $i->balance()), 2),
            'late_fees' => round($live->sum(fn ($i) => $i->effectiveLateFee()), 2),
            'open_count' => $live->filter(fn ($i) => $i->balance() > 0)->count(),
        ];
    }
}
