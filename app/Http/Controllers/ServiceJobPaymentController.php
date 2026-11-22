<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ServiceJob;
use App\Models\ServiceJobPayment;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ServiceJobPaymentController extends BaseController
{
    // -------------- List payments for a service job ---------------\\
    public function index(Request $request, $serviceJobId)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $job = ServiceJob::whereNull('deleted_at')->findOrFail($serviceJobId);

        $payments = ServiceJobPayment::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->with('payment_method:id,name')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobPayment $row) {
                return [
                    'id' => $row->id,
                    'Ref' => $row->Ref,
                    'date' => $row->date ? $row->date->format('Y-m-d') : null,
                    'montant' => (float) $row->montant,
                    'change' => (float) $row->change,
                    'payment_method_id' => $row->payment_method_id,
                    'payment_method' => $row->payment_method ? $row->payment_method->name : null,
                    'account_id' => $row->account_id,
                    'payment_kind' => $row->payment_kind,
                    'notes' => $row->notes,
                ];
            });

        return response()->json([
            'payments' => $payments,
            'totals' => [
                'total_amount' => (float) $job->total_amount,
                'paid_amount' => (float) $job->paid_amount,
                'balance_due' => (float) $job->total_amount - (float) $job->paid_amount,
                'payment_status' => $job->payment_status,
            ],
        ]);
    }

    // -------------- Store new payment ---------------\\
    public function store(Request $request, $serviceJobId)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $validated = $request->validate([
            'date' => 'required|date',
            'montant' => 'required|numeric|min:0.01',
            'change' => 'nullable|numeric|min:0',
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id', \App\Models\PaymentMethod::notCustomerWalletRule()],
            'account_id' => 'nullable|integer|exists:accounts,id',
            'payment_kind' => 'nullable|string|in:deposit,payment,refund',
            'notes' => 'nullable|string',
        ]);

        $job = ServiceJob::whereNull('deleted_at')->findOrFail($serviceJobId);

        // No try/catch inside the closure: returning from it COMMITS whatever
        // was already written (payment row + balance) while reporting failure.
        // Letting the exception escape rolls the whole payment back.
        // (Account::adjustBalance is an atomic increment, so no row lock needed.)
        DB::transaction(function () use ($validated, $job) {
            ServiceJobPayment::create([
                'Ref' => $this->getNumberOrder(),
                'service_job_id' => $job->id,
                'user_id' => Auth::id(),
                'date' => $validated['date'],
                'montant' => $validated['montant'],
                'change' => $validated['change'] ?? 0,
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'account_id' => $validated['account_id'] ?? null,
                'payment_kind' => $validated['payment_kind'] ?? 'payment',
                'notes' => $validated['notes'] ?? null,
            ]);

            Account::adjustBalance($validated['account_id'] ?? null, $validated['montant']);

            app(ServiceJobController::class)->recalcTotals($job->fresh());
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // -------------- Update payment ---------------\\
    public function update(Request $request, $serviceJobId, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $validated = $request->validate([
            'date' => 'required|date',
            'montant' => 'required|numeric|min:0.01',
            'change' => 'nullable|numeric|min:0',
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id', \App\Models\PaymentMethod::notCustomerWalletRule()],
            'account_id' => 'nullable|integer|exists:accounts,id',
            'payment_kind' => 'nullable|string|in:deposit,payment,refund',
            'notes' => 'nullable|string',
        ]);

        $job = ServiceJob::whereNull('deleted_at')->findOrFail($serviceJobId);
        $payment = ServiceJobPayment::whereNull('deleted_at')
            ->where('service_job_id', $job->id)
            ->findOrFail($id);

        DB::transaction(function () use ($validated, $job, $payment) {
            // Reverse old account balance
            Account::adjustBalance($payment->account_id, -$payment->montant);

            $payment->update([
                'date' => $validated['date'],
                'montant' => $validated['montant'],
                'change' => $validated['change'] ?? 0,
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'account_id' => $validated['account_id'] ?? null,
                'payment_kind' => $validated['payment_kind'] ?? $payment->payment_kind,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Apply new account balance
            Account::adjustBalance($validated['account_id'] ?? null, $validated['montant']);

            app(ServiceJobController::class)->recalcTotals($job->fresh());
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // -------------- Destroy payment ---------------\\
    public function destroy(Request $request, $serviceJobId, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $job = ServiceJob::whereNull('deleted_at')->findOrFail($serviceJobId);
        $payment = ServiceJobPayment::whereNull('deleted_at')
            ->where('service_job_id', $job->id)
            ->findOrFail($id);

        DB::transaction(function () use ($job, $payment) {
            Account::adjustBalance($payment->account_id, -$payment->montant);

            $payment->update(['deleted_at' => Carbon::now()]);
            app(ServiceJobController::class)->recalcTotals($job->fresh());
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // -------------- Reference generator ---------------\\
    public function getNumberOrder()
    {
        $last = DB::table('service_job_payments')->latest('id')->first();

        if ($last && $last->Ref) {
            $parts = explode('_', (string) $last->Ref);
            $next = isset($parts[1]) && is_numeric($parts[1]) ? ($parts[1] + 1) : 1112;

            return 'SJP_'.$next;
        }

        return 'SJP_1111';
    }
}
