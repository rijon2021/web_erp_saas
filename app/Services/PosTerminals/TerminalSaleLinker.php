<?php

namespace App\Services\PosTerminals;

use App\Models\PaymentSale;
use App\Models\PosTerminalTransaction as Txn;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * POS checkout lines paid on a device carry `terminal_transaction_id`.
 * claim() checks each one before the sale transaction opens (approved,
 * same checkout, same amount, not used by another sale, not reversed);
 * link() stamps the sale + payment onto it inside that transaction, so a
 * rolled-back sale leaves the charge unlinked (and voidable) again.
 */
final class TerminalSaleLinker
{
    /** @return array<int, Txn> payment line index => approved sale transaction */
    public static function claim(Request $request, $changeLineIndex): array
    {
        $claimed = [];
        $seen = [];

        foreach ((array) $request->input('payments', []) as $index => $payment) {
            $raw = is_array($payment) ? ($payment['terminal_transaction_id'] ?? null) : null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $fail = fn (string $msg) => throw ValidationException::withMessages(["payments.{$index}.terminal_transaction_id" => $msg]);
            $id = is_scalar($raw) && ctype_digit((string) $raw) ? (int) $raw : 0;
            if ($id <= 0) {
                $fail('The card payment on the device could not be found.');
            }
            if (! Schema::hasTable('pos_terminal_transactions')) {
                $fail(__('messages.Pd_Not_Ready'));
            }

            $txn = Txn::with('terminal')->find($id);

            if (! $txn || $txn->type !== Txn::TYPE_SALE) {
                $fail('The card payment on the device could not be found.');
            }
            if (! $txn->isApproved()) {
                $fail('The card payment on the device was not approved.');
            }
            if ($txn->sale_id !== null || isset($seen[$id])) {
                $fail('This card payment already belongs to another sale.');
            }
            if ($txn->sale_uuid && $txn->sale_uuid !== $request->input('sale_uuid')) {
                $fail('This card payment was taken for a different checkout.');
            }
            if ($txn->children()->whereIn('status', [Txn::STATUS_APPROVED, Txn::STATUS_PENDING])->exists()) {
                $fail('This card payment was refunded or voided on the device (or a refund is in progress).');
            }
            if (abs((float) $txn->base_amount - (float) ($payment['amount'] ?? 0)) > 0.01) {
                $fail('The payment amount does not match what the device charged.');
            }
            if ($changeLineIndex !== null && $index === $changeLineIndex) {
                $fail('Change cannot be given from a card payment.');
            }
            $methodId = $txn->terminal?->payment_method_id;
            if ($methodId && (int) ($payment['payment_method_id'] ?? 0) !== (int) $methodId) {
                $fail('The payment method does not match the device.');
            }

            $seen[$id] = true;
            $claimed[$index] = $txn;
        }

        return $claimed;
    }

    /** Inside the sale DB transaction, right after the line's PaymentSale is written. */
    public static function link(array $claimed, $index, Sale $sale, PaymentSale $payment): void
    {
        if (! isset($claimed[$index])) {
            return;
        }

        // Row lock (reverse() takes the same one): a parallel checkout that
        // claimed the same charge, or a refund / void started meanwhile,
        // makes this sale roll back instead of both going through.
        $row = Txn::whereKey($claimed[$index]->id)->lockForUpdate()->first();
        $reversing = $row && $row->children()->whereIn('status', [Txn::STATUS_APPROVED, Txn::STATUS_PENDING])->exists();

        if (! $row || $row->sale_id !== null || $row->status !== Txn::STATUS_APPROVED || $reversing) {
            throw new \RuntimeException('This card payment already belongs to another sale, or is being refunded.');
        }

        $row->forceFill(['sale_id' => $sale->id, 'payment_sale_id' => $payment->id])->save();
    }

    /** Lines paid on a device: Stripe must not try to charge them as well. */
    public static function isTerminalLine(array $payment): bool
    {
        $raw = $payment['terminal_transaction_id'] ?? null;

        return $raw !== null && $raw !== '';
    }
}
