<?php

namespace App\Services;

use App\Events\CommissionReceiptCreated;
use App\Models\Account;
use App\Models\CommissionProgram;
use App\Models\CommissionReceipt;
use App\Models\CommissionRule;
use App\Models\Sale;
use App\Models\SaleCommission;
use App\Models\SaleReturn;
use App\Models\SalesAgent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    /** Sale ids whose recalculation is already waiting for the current transaction to commit. */
    protected static array $queued = [];

    /**
     * Bring a sale's commissions in line with the sale as it is now. Safe to
     * call any number of times (sale saved, paid, returned, deleted...).
     *
     * Each program / rule / agent "line" gets the amount it should pay now:
     *  - unpaid rows (pending, approved) are updated in place, or removed when
     *    the line no longer pays; an approved row whose amount changes goes
     *    back to pending for another review;
     *  - paid rows are never touched: the difference becomes a pending
     *    adjustment row (negative = claw-back, netted on the next receipt).
     *
     * A line whose latest row a user cancelled stays cancelled, unless
     * $recalculateCancelled (sale saved in Edit Sale, Recalculate in the report).
     *
     * @return SaleCommission[] rows created or changed
     */
    public function calculateForSale(Sale $sale, bool $recalculateCancelled = false): array
    {
        return DB::transaction(function () use ($sale, $recalculateCancelled) {
            // Locked fresh copy: two saves of one sale cannot both create rows,
            // and paid_amount written by another instance is seen.
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->first();
            if (! $sale) {
                return [];
            }

            $rows = SaleCommission::where('sale_id', $sale->id)->orderBy('id')->get();
            $lines = $rows->groupBy(fn ($r) => $this->lineKey($r->commission_program_id, $r->commission_rule_id, $r->sales_agent_id));
            $desired = $this->desiredLines($sale, $lines);

            $changed = [];
            foreach ($lines->keys()->merge(array_keys($desired))->unique() as $key) {
                $lineRows = $lines->get($key, collect());
                if (! $recalculateCancelled && optional($lineRows->last())->status === 'cancelled') {
                    continue;
                }
                if ($row = $this->syncLine($sale, $key, $lineRows, $desired[$key] ?? null)) {
                    $changed[] = $row;
                }
            }

            return $changed;
        });
    }

    /**
     * Recalculate a sale that already has commissions once the current
     * transaction commits (model observers: returns, payment edits, deletes).
     * Creating the first commissions stays with the sale screens, POS and
     * payments, so imports and online orders never start earning here.
     */
    public function queueRecalculation(int $saleId): void
    {
        if (! $saleId || isset(static::$queued[$saleId])) {
            return;
        }
        static::$queued[$saleId] = true;

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use ($saleId) {
                unset(static::$queued[$saleId]);
            });
        }

        DB::afterCommit(function () use ($saleId) {
            unset(static::$queued[$saleId]);
            try {
                if (! SaleCommission::where('sale_id', $saleId)->exists()) {
                    return;
                }
                if ($sale = Sale::find($saleId)) {
                    $this->calculateForSale($sale);
                }
            } catch (\Throwable $e) {
                Log::warning('Commission recalculation failed (non-blocking): '.$e->getMessage(), ['sale_id' => $saleId]);
            }
        });
    }

    /** Apply one line's target amount to its rows; returns the row written, if any. */
    protected function syncLine(Sale $sale, string $key, Collection $lineRows, ?array $want): ?SaleCommission
    {
        $live = $lineRows->where('status', '!=', 'cancelled');
        $paid = round((float) $live->where('status', 'paid')->sum('commission_amount'), 4);
        $open = $live->whereIn('status', ['pending', 'approved'])->values();
        $remaining = round(($want['amount'] ?? 0.0) - $paid, 4);

        // One open row per line; extras can only come from older versions.
        $keep = $open->firstWhere('status', 'pending') ?? $open->first();
        foreach ($open as $row) {
            if ($row->id !== optional($keep)->id) {
                $row->delete();
            }
        }

        if (abs($remaining) < 0.0001) {
            // Nothing (more) owed: an unpaid row is obsolete.
            optional($keep)->delete();

            return null;
        }

        $attrs = [
            'base_amount' => $want['base'] ?? 0,
            'commission_amount' => $remaining,
            'is_adjustment' => $paid != 0.0,
        ];

        if ($keep) {
            $amountChanged = round((float) $keep->commission_amount, 4) !== $remaining;
            if (! $amountChanged
                && round((float) $keep->base_amount, 4) === round((float) $attrs['base_amount'], 4)
                && (bool) $keep->is_adjustment === $attrs['is_adjustment']) {
                return null;
            }
            if ($amountChanged) {
                // A new figure needs a new review.
                $attrs += ['status' => 'pending', 'calculated_at' => now()];
            }
            $keep->update($attrs);

            return $keep;
        }

        [$programId, $ruleId, $agentId] = array_map('intval', explode(':', $key));

        return SaleCommission::create($attrs + [
            'sale_id' => $sale->id,
            'sales_agent_id' => $agentId,
            'commission_program_id' => $programId,
            'commission_rule_id' => $ruleId,
            'rule_terms' => $want['terms'] ?? $live->pluck('rule_terms')->filter()->last(),
            'status' => 'pending',
            'calculated_at' => now(),
        ]);
    }

    /**
     * What each line should pay for the sale as it is now:
     * key => ['base' => float, 'amount' => float, 'terms' => array].
     */
    protected function desiredLines(Sale $sale, Collection $lines): array
    {
        if ($sale->deleted_at !== null || $sale->statut !== 'completed') {
            return [];
        }
        $agent = $this->resolveAgentForSale($sale);
        if (! $agent) {
            return [];
        }

        [$saleTotal, $paidAmount] = $this->netAmounts($sale);
        $date = $sale->date ? Carbon::parse($sale->date) : now();

        // Rules that apply to this agent on the sale's date...
        $candidates = [];
        $programs = CommissionProgram::active()
            ->validAt($date)
            ->with(['commissionRules' => function ($q) use ($agent) {
                $q->active()->forAgent($agent->id);
            }])
            ->get();
        foreach ($programs as $program) {
            foreach ($program->commissionRules as $rule) {
                $candidates[$this->lineKey($program->id, $rule->id, $agent->id)] = [
                    'program' => $program, 'rule' => $rule, 'terms' => $this->termsOf($rule),
                ];
            }
        }

        // ...plus lines this agent already earns on this sale, priced with the
        // terms they were first earned under, so editing or switching off a
        // rule later never reprices or claws back older sales.
        foreach ($lines as $key => $lineRows) {
            $live = $lineRows->where('status', '!=', 'cancelled');
            if ($live->isEmpty() || (int) $live->first()->sales_agent_id !== (int) $agent->id) {
                continue;
            }
            $terms = $live->pluck('rule_terms')->filter()->last();
            if (isset($candidates[$key])) {
                if ($terms) {
                    $candidates[$key]['terms'] = $terms;
                }
                continue;
            }
            $rule = CommissionRule::withTrashed()->find($live->first()->commission_rule_id);
            $program = CommissionProgram::withTrashed()->find($live->first()->commission_program_id);
            if ($rule && $program) {
                $candidates[$key] = ['program' => $program, 'rule' => $rule, 'terms' => $terms ?: $this->termsOf($rule)];
            }
        }

        $desired = [];
        foreach (collect($candidates)->groupBy(fn ($c) => $c['program']->id, true) as $group) {
            $highestOnly = $group->first()['program']->rule_mode === CommissionProgram::MODE_HIGHEST_PRIORITY;
            // Highest priority first; on a tie a Specific Agent rule beats All Agents, then the oldest rule.
            $ordered = $group->sort(fn ($a, $b) => [
                (int) $b['rule']->priority, $b['rule']->applies_to === 'specific_agent', $a['rule']->id,
            ] <=> [
                (int) $a['rule']->priority, $a['rule']->applies_to === 'specific_agent', $b['rule']->id,
            ]);

            foreach ($ordered as $key => $c) {
                $base = ($c['terms']['source'] ?? 'sale_total') === 'paid_amount' ? $paidAmount : $saleTotal;
                $amount = $this->commissionFor($base, $c['terms']);
                if ($amount <= 0) {
                    continue; // does not qualify: the next rule gets its chance
                }
                $desired[$key] = ['base' => $base, 'amount' => $amount, 'terms' => $c['terms']];
                if ($highestOnly) {
                    break;
                }
            }
        }

        return $desired;
    }

    /**
     * [sale total, paid amount] net of returns: received returns reduce the
     * total, refunds reduce what was paid, and paid never exceeds the total.
     */
    protected function netAmounts(Sale $sale): array
    {
        $returns = SaleReturn::where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->get(['GrandTotal', 'paid_amount', 'statut']);

        $total = max(0.0, (float) $sale->GrandTotal - (float) $returns->where('statut', 'received')->sum('GrandTotal'));
        $paid = max(0.0, min((float) $sale->paid_amount - (float) $returns->sum('paid_amount'), $total));

        return [$total, $paid];
    }

    protected function resolveAgentForSale(Sale $sale): ?SalesAgent
    {
        if ($sale->sales_agent_id) {
            return SalesAgent::where('id', $sale->sales_agent_id)->where('is_active', true)->first();
        }
        // POS salesperson switch: the picked seller is credited before the cashier.
        if ($sale->seller_id) {
            $agent = SalesAgent::where('user_id', $sale->seller_id)->where('is_active', true)->first();
            if ($agent) {
                return $agent;
            }
        }
        if ($sale->user_id) {
            return SalesAgent::where('user_id', $sale->user_id)->where('is_active', true)->first();
        }
        return null;
    }

    protected function lineKey($programId, $ruleId, $agentId): string
    {
        return (int) $programId.':'.(int) $ruleId.':'.(int) $agentId;
    }

    /** The parts of a rule that decide the amount, stored on each commission. */
    protected function termsOf(CommissionRule $rule): array
    {
        return [
            'type' => $rule->type,
            'source' => $rule->source,
            'value' => (float) $rule->value,
            'min_threshold' => $rule->min_threshold !== null ? (float) $rule->min_threshold : null,
            'max_cap' => $rule->max_cap !== null ? (float) $rule->max_cap : null,
        ];
    }

    protected function commissionFor(float $baseAmount, array $terms): float
    {
        if ($baseAmount <= 0) {
            return 0.0;
        }
        if (isset($terms['min_threshold']) && (float) $terms['min_threshold'] > $baseAmount) {
            return 0.0;
        }
        $value = (float) ($terms['value'] ?? 0);
        $amount = ($terms['type'] ?? 'percentage') === 'percentage'
            ? round($baseAmount * ($value / 100), 4)
            : $value;
        if (isset($terms['max_cap']) && $amount > (float) $terms['max_cap']) {
            $amount = (float) $terms['max_cap'];
        }
        return max(0.0, $amount);
    }

    /**
     * Generate next Ref for commission receipt.
     */
    public function generateReceiptRef(): string
    {
        $prefix = 'CR-';
        $last = CommissionReceipt::withTrashed()->orderBy('id', 'desc')->first();
        $num = $last ? ((int) preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $last->Ref) + 1) : 1;
        return $prefix . str_pad((string) $num, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Create a commission receipt and mark selected commissions as paid.
     */
    public function createReceiptAndMarkPaid(
        int $salesAgentId,
        array $commissionIds,
        string $ref,
        float $amount,
        $paidAt,
        ?int $paymentMethodId = null,
        ?string $notes = null,
        ?int $accountId = null
    ): CommissionReceipt {
        return DB::transaction(function () use ($salesAgentId, $commissionIds, $ref, $amount, $paidAt, $paymentMethodId, $notes, $accountId) {
            $receipt = CommissionReceipt::create([
                'sales_agent_id' => $salesAgentId,
                'Ref' => $ref,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'payment_method_id' => $paymentMethodId,
                'account_id' => $accountId,
                'notes' => $notes,
            ]);
            SaleCommission::whereIn('id', $commissionIds)
                ->where('sales_agent_id', $salesAgentId)
                ->where('status', 'approved')
                ->update(['status' => 'paid', 'commission_receipt_id' => $receipt->id]);
            $receipt = $receipt->fresh();
            // The payout leaves the chosen cash/bank account, exactly like an
            // Expense does, and the ledger listener posts
            // Dr Sales Commission Expense / Cr that account.
            if ($accountId && $amount > 0) {
                Account::adjustBalance($accountId, -$amount);
            }
            event(new CommissionReceiptCreated($receipt));
            return $receipt;
        });
    }
}
