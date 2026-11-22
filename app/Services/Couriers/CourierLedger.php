<?php

namespace App\Services\Couriers;

use App\Models\Account;
use App\Models\CourierSetting;
use App\Models\Expense;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Money a courier shipment produces in Stocky, each recorded at most once:
 *  - the COD payout → a sale payment (shipments.cod_payment_id)
 *  - the courier's delivery charges → an expense (shipments.charges_expense_id)
 * Both follow Courier Settings and mirror PaymentSalesController@store /
 * ExpensesController@store, so balances and reports treat them like any
 * payment or expense entered by hand.
 */
class CourierLedger
{
    /** Returns the new payment id, or null when nothing was recorded. */
    public function recordCodPayment(Shipment $shipment, ?User $user = null): ?int
    {
        $settings = CourierSetting::current();
        if (! $settings->recordsCodPayments() || $shipment->cod_payment_id || (float) $shipment->cod_amount <= 0) {
            return null;
        }

        $user = $user ?: ActingUser::resolve($shipment->user_id);
        if (! $user) {
            return null;
        }

        $paymentId = DB::transaction(function () use ($shipment, $settings, $user) {
            $shipment = Shipment::query()->lockForUpdate()->find($shipment->id);
            $sale = $shipment ? Sale::query()->whereNull('deleted_at')->lockForUpdate()->find($shipment->sale_id) : null;
            if (! $shipment || $shipment->cod_payment_id || ! $sale) {
                return null;
            }

            // Never pay more than is still due (part may have been paid by hand).
            $due = round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2);
            $amount = round(min((float) $shipment->cod_amount, $due), 2);
            if ($amount <= 0) {
                return null;
            }

            $payment = PaymentSale::create([
                'sale_id' => $sale->id,
                'Ref' => app(\App\Http\Controllers\PaymentSalesController::class)->getNumberOrder(),
                'date' => now()->toDateTimeString(),
                'account_id' => $settings->cod_account_id,
                'payment_method_id' => $settings->cod_payment_method_id,
                'montant' => $amount,
                'change' => 0,
                'notes' => trim('COD remitted by ' . ($shipment->courierAccount?->name ?? 'courier')
                    . ' · ' . $shipment->tracking_number
                    . ($shipment->cod_reference ? ' · ' . $shipment->cod_reference : '')),
                'user_id' => $user->id,
            ]);

            Account::adjustBalance($settings->cod_account_id, $amount);

            $paid = round((float) $sale->paid_amount + $amount, 2);
            $remaining = round((float) $sale->GrandTotal - $paid, 2);
            $sale->update([
                'paid_amount' => $paid,
                'payment_statut' => $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            ]);

            $shipment->forceFill(['cod_payment_id' => $payment->id])->save();

            return $payment->id;
        });

        if ($paymentId) {
            // Same follow-up as a payment entered by hand (non-blocking).
            try {
                $sale = Sale::find($shipment->sale_id);
                if ($sale && $sale->statut === 'completed') {
                    app(\App\Services\CommissionService::class)->calculateForSale($sale);
                }
            } catch (\Throwable $e) {
                Log::warning('Commission calculation after COD payment failed: ' . $e->getMessage(), ['shipment_id' => $shipment->id]);
            }
        }

        return $paymentId;
    }

    /** Returns the new expense id, or null when nothing was recorded. */
    public function recordChargesExpense(Shipment $shipment, ?User $user = null): ?int
    {
        $settings = CourierSetting::current();
        if (! $settings->charges_as_expense || ! $settings->charges_expense_category_id
            || $shipment->charges_expense_id || (float) $shipment->shipping_charges <= 0) {
            return null;
        }

        $user = $user ?: ActingUser::resolve($shipment->user_id);
        $sale = Sale::query()->find($shipment->sale_id);
        if (! $user || ! $sale) {
            return null;
        }

        return DB::transaction(function () use ($shipment, $settings, $user, $sale) {
            $shipment = Shipment::query()->lockForUpdate()->find($shipment->id);
            if (! $shipment || $shipment->charges_expense_id) {
                return null;
            }

            $amount = round((float) $shipment->shipping_charges, 2);
            $expense = Expense::create([
                'user_id' => $user->id,
                'date' => now()->toDateTimeString(),
                'Ref' => app(\App\Http\Controllers\ExpensesController::class)->getNumberOrder(),
                'payment_method_id' => $settings->cod_payment_method_id,
                'warehouse_id' => $sale->warehouse_id,
                'expense_category_id' => $settings->charges_expense_category_id,
                'account_id' => $settings->cod_account_id,
                'details' => 'Courier charges · ' . ($shipment->courierAccount?->name ?? 'courier')
                    . ' · ' . $shipment->tracking_number . ' · ' . $sale->Ref,
                'amount' => $amount,
            ]);

            Account::adjustBalance($settings->cod_account_id, -$amount);

            $shipment->forceFill(['charges_expense_id' => $expense->id])->save();

            return $expense->id;
        });
    }
}
