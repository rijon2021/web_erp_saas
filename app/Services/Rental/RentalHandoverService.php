<?php

namespace App\Services\Rental;

use App\Models\RentalContract;
use App\Models\RentalContractItem;
use App\Models\RentalDepositTransaction;
use App\Models\RentalHandover;
use App\Models\RentalHandoverItem;
use App\Models\RentalInvoice;
use App\Models\RentalItem;
use App\Models\RentalPayment;
use App\Models\RentalSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handover (check-out), return (check-in) and the deposit ledger.
 *
 * Deposits: collected → held → refunded / applied to invoices / retained.
 * "Applied" creates a payment with source = deposit, so the invoices show
 * exactly what the deposit paid and the books move it out of the deposit
 * liability rather than out of cash.
 */
class RentalHandoverService
{
    public function __construct(
        private RentalContractService $contracts,
        private RentalBillingService $billing,
        private RentalAccounting $accounting,
    ) {
    }

    // ------------------------------------------------------------------
    // Deposits
    // ------------------------------------------------------------------

    public function collectDeposit(RentalContract $c, float $amount, array $opts = []): RentalDepositTransaction
    {
        $t = $this->depositRow($c, 'collected', $amount, $opts);
        $this->accounting->depositMoved($t);
        $this->contracts->log($c, 'deposit_collected', $opts['notes'] ?? null, ['amount' => $amount]);

        return $t;
    }

    public function refundDeposit(RentalContract $c, float $amount, array $opts = []): RentalDepositTransaction
    {
        $this->assertHeld($c, $amount);
        $t = $this->depositRow($c, 'refunded', $amount, $opts);
        $this->accounting->depositMoved($t);
        $this->contracts->log($c, 'deposit_refunded', $opts['notes'] ?? null, ['amount' => $amount]);

        return $t;
    }

    public function retainDeposit(RentalContract $c, float $amount, array $opts = []): RentalDepositTransaction
    {
        $this->assertHeld($c, $amount);
        $t = $this->depositRow($c, 'retained', $amount, $opts);
        $this->accounting->depositMoved($t);
        $this->contracts->log($c, 'deposit_retained', $opts['notes'] ?? null, ['amount' => $amount]);

        return $t;
    }

    /**
     * Pay this contract's open invoices (oldest first) out of the deposit.
     * `amount` null = as much as both the deposit and the debt allow.
     */
    public function applyDeposit(RentalContract $c, ?float $amount = null, array $opts = []): ?RentalDepositTransaction
    {
        $this->billing->run($c->id);
        $held = $c->depositHeld();
        $owed = RentalInvoice::where('rental_contract_id', $c->id)->whereIn('status', ['unpaid', 'partial'])
            ->get()->sum(fn ($i) => $i->balance());
        $amount = round($amount ?? min($held, $owed), 2);

        if ($amount <= 0) {
            return null;
        }
        $this->assertHeld($c, $amount);
        if ($amount > round($owed, 2) + 0.004) {
            abort(response()->json(['message' => 'Only ' . number_format($owed, 2) . ' is owed on this contract.'], 422));
        }

        $payment = RentalPayment::create([
            'payment_number' => $this->billing->nextPaymentNumber(),
            'rental_contract_id' => $c->id,
            'rental_customer_id' => $c->rental_customer_id,
            'client_id' => $c->client_id,
            'amount' => $amount,
            'source' => 'deposit',
            'payment_date' => Carbon::parse($opts['date'] ?? now())->toDateString(),
            'reference' => 'Deposit',
            'notes' => $opts['notes'] ?? null,
            'user_id' => optional(auth()->user())->id,
        ]);
        if ($this->billing->allocate($payment) > 0.004) {
            abort(response()->json(['message' => 'The deposit amount is more than what is owed.'], 422));
        }
        $this->accounting->paymentReceived($payment);

        $t = $this->depositRow($c, 'applied', $amount, array_merge($opts, ['rental_payment_id' => $payment->id]));
        $this->contracts->log($c, 'deposit_applied', null, ['amount' => $amount, 'payment' => $payment->payment_number]);

        return $t;
    }

    /** Undo one deposit movement (and the payment an "applied" one made). */
    public function reverseDeposit(RentalDepositTransaction $t): void
    {
        $c = RentalContract::findOrFail($t->rental_contract_id);
        if ($t->type === 'collected' && $c->depositHeld() - (float) $t->amount < -0.004) {
            abort(response()->json([
                'message' => 'Part of this deposit was already refunded, applied or kept. Undo those first.',
            ], 422));
        }

        if ($t->type === 'applied' && $t->rental_payment_id && ($p = RentalPayment::find($t->rental_payment_id))) {
            $this->billing->unallocate($p);
            $this->accounting->paymentReceived($p, true);
            $p->delete();
        } else {
            $this->accounting->depositMoved($t, true);
        }
        $t->delete();
        $this->contracts->log($c, 'deposit_reversed', null, ['type' => $t->type, 'amount' => (float) $t->amount]);
    }

    private function depositRow(RentalContract $c, string $type, float $amount, array $opts): RentalDepositTransaction
    {
        return RentalDepositTransaction::create([
            'rental_contract_id' => $c->id,
            'rental_customer_id' => $c->rental_customer_id,
            'type' => $type,
            'amount' => round($amount, 2),
            'transaction_date' => Carbon::parse($opts['date'] ?? now())->toDateString(),
            'payment_method_id' => $opts['payment_method_id'] ?? null,
            'account_id' => $opts['account_id'] ?? null,
            'rental_payment_id' => $opts['rental_payment_id'] ?? null,
            'rental_handover_id' => $opts['rental_handover_id'] ?? null,
            'reference' => $opts['reference'] ?? null,
            'notes' => $opts['notes'] ?? null,
            'user_id' => optional(auth()->user())->id,
        ]);
    }

    private function assertHeld(RentalContract $c, float $amount): void
    {
        $held = $c->depositHeld();
        if ($amount > $held + 0.004) {
            abort(response()->json(['message' => 'Only ' . number_format($held, 2) . ' of the deposit is held.'], 422));
        }
    }

    // ------------------------------------------------------------------
    // Late return
    // ------------------------------------------------------------------

    /**
     * What returning `lines` at `$at` costs beyond the agreed end: whole extra
     * periods (rounded up, after the buffer) × those lines' rent per period.
     */
    public function lateReturn(RentalContract $c, $lines, Carbon $at): array
    {
        $buffer = (int) RentalSetting::current()->buffer_hours;
        if (! $c->end_date || $at->lte($c->end_date->copy()->addHours($buffer))) {
            return ['periods' => 0, 'amount' => 0.0];
        }
        $periods = $this->contracts->periodsBetween($c->end_date, $at, $c->rate_period);
        $perPeriod = (float) collect($lines)->sum(fn ($l) => (float) $l->amount);

        return ['periods' => $periods, 'amount' => round($periods * $perPeriod, 2)];
    }

    // ------------------------------------------------------------------
    // Check-out / check-in
    // ------------------------------------------------------------------

    /**
     * Items leave. $data: happened_at, lines[{id, condition, meter_reading}],
     * checklist[{label, checked}], notes, signed_name, signature (data URL),
     * deposit{amount, payment_method_id, account_id, reference}.
     */
    public function checkout(RentalContract $c, array $data, array $photos = []): RentalHandover
    {
        $at = $this->moment($data['happened_at'] ?? null);
        $stored = $this->storePhotos($photos);

        $handover = $this->guardPhotos($stored, fn () => DB::transaction(function () use ($c, $data, $stored, $at) {
            // Lock the contract and re-read its lines: a double-clicked submit
            // waits here and then finds nothing left to hand over.
            RentalContract::whereKey($c->id)->lockForUpdate()->first();
            $lines = $c->items()->whereNull('checked_out_at')->get()->keyBy('id');
            $chosen = collect($data['lines'] ?? [])->filter(fn ($l) => isset($lines[(int) $l['id']]));
            if ($chosen->isEmpty()) {
                abort(response()->json(['message' => 'Every item on this contract has already been handed over.'], 422));
            }

            $h = RentalHandover::create([
                'rental_contract_id' => $c->id,
                'type' => 'checkout',
                'happened_at' => $at,
                'checklist' => $data['checklist'] ?? [],
                'photos' => $stored,
                'signature' => $this->storeSignature($data['signature'] ?? null),
                'signed_name' => $data['signed_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => optional(auth()->user())->id,
            ]);

            foreach ($chosen as $l) {
                $line = $lines[(int) $l['id']];
                RentalHandoverItem::create([
                    'rental_handover_id' => $h->id,
                    'rental_contract_item_id' => $line->id,
                    'rental_item_id' => $line->rental_item_id,
                    'item_name' => $line->item_name,
                    'quantity' => $line->quantity,
                    'condition' => $l['condition'] ?? null,
                    'meter_reading' => $this->num($l['meter_reading'] ?? null),
                ]);
                $line->update(['checked_out_at' => $at]);
                $this->updateItem($line->rental_item_id, $l['condition'] ?? null, $this->num($l['meter_reading'] ?? null), false);
            }

            if (! $c->checked_out_at) {
                $c->update(['checked_out_at' => $at]);
            }
            $deposit = $data['deposit'] ?? [];
            if (! empty($deposit['amount']) && (float) $deposit['amount'] > 0) {
                $this->collectDeposit($c, (float) $deposit['amount'], [
                    'date' => $at,
                    'payment_method_id' => $deposit['payment_method_id'] ?? null,
                    'account_id' => $deposit['account_id'] ?? null,
                    'reference' => $deposit['reference'] ?? null,
                    'rental_handover_id' => $h->id,
                ]);
            }
            $this->contracts->log($c, 'checked_out', $data['notes'] ?? null, ['items' => $chosen->count(), 'handover_id' => $h->id]);

            return $h;
        }));

        $this->contracts->sync($c->items()->pluck('rental_item_id')->all());

        return $handover;
    }

    /**
     * Items come back. $data: happened_at, lines[{id, condition, meter_reading,
     * damage_notes, damage_charge, needs_maintenance}], late_charge,
     * charges[{description, amount}], checklist, notes, signed_name, signature,
     * close_contract, deposit{apply, refund_amount, retain_amount,
     * payment_method_id, account_id}.
     */
    public function checkin(RentalContract $c, array $data, array $photos = []): RentalHandover
    {
        $at = $this->moment($data['happened_at'] ?? null);
        $stored = $this->storePhotos($photos);

        $handover = $this->guardPhotos($stored, fn () => DB::transaction(function () use ($c, $data, $stored, $at) {
            // Lock the contract and re-read: a double-clicked submit must not
            // charge damage / late fees or move the deposit twice.
            RentalContract::whereKey($c->id)->lockForUpdate()->first();
            $c->refresh();
            $open = $c->items()->whereNull('returned_at')->get()->keyBy('id');
            $chosen = collect($data['lines'] ?? [])->filter(fn ($l) => isset($open[(int) $l['id']]));
            if ($chosen->isEmpty()) {
                abort(response()->json(['message' => 'Choose at least one item that is coming back.'], 422));
            }

            $h = RentalHandover::create([
                'rental_contract_id' => $c->id,
                'type' => 'checkin',
                'happened_at' => $at,
                'checklist' => $data['checklist'] ?? [],
                'photos' => $stored,
                'signature' => $this->storeSignature($data['signature'] ?? null),
                'signed_name' => $data['signed_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => optional(auth()->user())->id,
            ]);

            $charged = 0.0;
            foreach ($chosen as $l) {
                $line = $open[(int) $l['id']];
                $damage = round(max(0, (float) ($l['damage_charge'] ?? 0)), 2);
                RentalHandoverItem::create([
                    'rental_handover_id' => $h->id,
                    'rental_contract_item_id' => $line->id,
                    'rental_item_id' => $line->rental_item_id,
                    'item_name' => $line->item_name,
                    'quantity' => $line->quantity,
                    'condition' => $l['condition'] ?? null,
                    'meter_reading' => $this->num($l['meter_reading'] ?? null),
                    'damage_notes' => $l['damage_notes'] ?? null,
                    'damage_charge' => $damage,
                    'needs_maintenance' => ! empty($l['needs_maintenance']),
                ]);
                $line->update(['returned_at' => $at, 'checked_out_at' => $line->checked_out_at ?: $at]);
                $this->updateItem($line->rental_item_id, $l['condition'] ?? null, $this->num($l['meter_reading'] ?? null), false);
                if (! empty($l['needs_maintenance'])) {
                    // An open job keeps the item in maintenance until someone completes it.
                    $job = app(RentalMaintenanceService::class)->fromReturn(
                        $line->rental_item_id, $line->item_name, $l['damage_notes'] ?? null, $c->id, $h->id
                    );
                    app(RentalMaintenanceService::class)->start($job);
                }

                if ($damage > 0) {
                    $note = trim((string) ($l['damage_notes'] ?? ''));
                    $this->billing->charge($c, 'Damage: ' . $line->item_name . ($note !== '' ? ' — ' . Str::limit($note, 150) : ''), $damage, $at->copy());
                    $charged += $damage;
                }
            }

            $late = round(max(0, (float) ($data['late_charge'] ?? 0)), 2);
            if ($late > 0) {
                $this->billing->charge($c, 'Late return', $late, $at->copy());
                $charged += $late;
            }
            foreach ((array) ($data['charges'] ?? []) as $extra) {
                $amount = round(max(0, (float) ($extra['amount'] ?? 0)), 2);
                $desc = trim((string) ($extra['description'] ?? ''));
                if ($amount > 0 && $desc !== '') {
                    $this->billing->charge($c, Str::limit($desc, 250), $amount, $at->copy());
                    $charged += $amount;
                }
            }
            $h->update(['charges_total' => $charged]);

            $allBack = ! $c->items()->whereNull('returned_at')->exists();
            if ($allBack) {
                $c->update(['returned_at' => $at]);
            }
            $this->contracts->log($c, 'checked_in', $data['notes'] ?? null, [
                'items' => $chosen->count(), 'charges' => $charged, 'handover_id' => $h->id, 'all_back' => $allBack,
            ]);

            // Close first, so invoices for periods after the return are voided
            // before the deposit is weighed against what is owed.
            if ($allBack && ! empty($data['close_contract']) && $c->status === 'active') {
                $c->update(['status' => 'ended', 'ended_at' => $at]);
                $this->contracts->log($c, 'ended', null);
                $this->billing->onContractClosed($c->fresh());
            }

            $this->settleDeposit($c->fresh(), $data['deposit'] ?? [], $at, $h->id);

            return $h;
        }));

        $this->contracts->sync($c->items()->pluck('rental_item_id')->all());

        return $handover;
    }

    /** Apply → retain → refund, in that order, within what is held. */
    private function settleDeposit(RentalContract $c, array $d, Carbon $at, int $handoverId): void
    {
        $opts = [
            'date' => $at,
            'payment_method_id' => $d['payment_method_id'] ?? null,
            'account_id' => $d['account_id'] ?? null,
            'rental_handover_id' => $handoverId,
        ];
        if (! empty($d['apply'])) {
            $this->applyDeposit($c, null, $opts);
        }
        $retain = round((float) ($d['retain_amount'] ?? 0), 2);
        if ($retain > 0) {
            $this->retainDeposit($c, $retain, array_merge($opts, ['notes' => $d['retain_reason'] ?? null]));
        }
        // refund_rest: give back whatever is still held once the deposit has
        // covered what is owed — the figure the screen showed can be stale.
        $refund = ! empty($d['refund_rest'])
            ? round($c->fresh()->depositHeld(), 2)
            : round((float) ($d['refund_amount'] ?? 0), 2);
        if ($refund > 0) {
            $this->refundDeposit($c, $refund, $opts);
        }
    }

    /** Handover moment from the form (may carry the browser's offset); never in the future. */
    private function moment($value): Carbon
    {
        $at = $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : Carbon::now();
        if ($at->gt(Carbon::now()->addMinutes(5))) {
            abort(response()->json(['message' => 'The handover time cannot be in the future.'], 422));
        }

        return $at;
    }

    /** Run $fn; if it fails, delete the photos already moved into public/images. */
    private function guardPhotos(array $stored, callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            foreach ($stored as $name) {
                @unlink(upload_public_path(RentalHandover::FILE_DIR . '/' . $name));
            }
            throw $e;
        }
    }

    private function updateItem(int $itemId, ?string $condition, ?float $meter, bool $toMaintenance): void
    {
        $item = RentalItem::find($itemId);
        if (! $item) {
            return;
        }
        $changes = [];
        if ($condition && in_array($condition, RentalItem::CONDITIONS, true)) {
            $changes['condition'] = $condition;
        }
        if ($meter !== null) {
            $changes['meter_reading'] = $meter;
        }
        if ($toMaintenance) {
            $changes['status'] = 'maintenance';
        }
        if ($changes) {
            $item->update($changes);
        }
    }

    private function num($v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    /** Uploaded photos → the tenant upload folder (rental_handovers), random names. */
    private function storePhotos(array $files): array
    {
        if (! $files) {
            return [];
        }
        $dir = upload_public_path(RentalHandover::FILE_DIR);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $names = [];
        foreach (array_slice($files, 0, 20) as $file) {
            $name = Str::random(40) . '.' . \App\Services\Rental\RentalUpload::extension($file, 'image');
            $file->move($dir, $name);
            $names[] = $name;
        }

        return $names;
    }

    private function storeSignature(?string $dataUrl): ?string
    {
        if (! $dataUrl || ! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $m)) {
            return null;
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strncmp($png, "\x89PNG", 4) !== 0) {
            return null;
        }
        $dir = upload_public_path(RentalHandover::FILE_DIR);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = 'sig_' . Str::random(36) . '.png';
        file_put_contents($dir . '/' . $name, $png);

        return $name;
    }
}
