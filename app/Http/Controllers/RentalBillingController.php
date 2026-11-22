<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Currency;
use App\Models\PaymentMethod;
use App\Models\RentalContract;
use App\Models\RentalCustomer;
use App\Models\RentalInvoice;
use App\Models\RentalPayment;
use App\Models\Setting;
use App\Services\Rental\RentalAccounting;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalContractService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Traits\ScopesWarehouseAccess;
use Illuminate\Support\Facades\DB;
use PDF;

/**
 * Rental invoices and payments: lists, the contract billing view (issued
 * invoices + projected schedule), payments with allocation, manual charges,
 * voids, late-fee waivers and the invoice PDF.
 */
class RentalBillingController extends Controller
{
    use ScopesWarehouseAccess;

    public function __construct(
        private RentalBillingService $billing,
        private RentalContractService $contracts,
        private RentalAccounting $accounting,
    ) {
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    public function invoices(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalInvoice::class);
        $this->billing->run();

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $sortable = [
            'invoice_number' => 'rental_invoices.invoice_number', 'due_date' => 'rental_invoices.due_date',
            'amount' => 'rental_invoices.amount', 'customer_name' => 'clients.name', 'created_at' => 'rental_invoices.created_at',
        ];
        $order = $sortable[$request->SortField] ?? 'rental_invoices.due_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $base = RentalInvoice::query()
            ->join('clients', 'clients.id', '=', 'rental_invoices.client_id')
            ->join('rental_contracts', 'rental_contracts.id', '=', 'rental_invoices.rental_contract_id')
            ->select('rental_invoices.*', 'clients.name as customer_name', 'rental_contracts.contract_number', 'rental_contracts.grace_days as contract_grace')
            ->when($request->filled('rental_contract_id'), fn ($q) => $q->where('rental_invoices.rental_contract_id', $request->rental_contract_id))
            ->when($request->filled('rental_customer_id'), fn ($q) => $q->where('rental_invoices.rental_customer_id', $request->rental_customer_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('rental_invoices.invoice_number', 'LIKE', "%{$s}%")
                    ->orWhere('rental_contracts.contract_number', 'LIKE', "%{$s}%")
                    ->orWhere('clients.name', 'LIKE', "%{$s}%")
                    ->orWhere('rental_invoices.description', 'LIKE', "%{$s}%"));
            });

        $summary = $this->invoiceSummary(clone $base);
        $query = clone $base;
        $this->applyInvoiceStage($query, (string) $request->get('stage', 'open'));

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->orderBy($order, $dir)->orderBy('rental_invoices.id', 'desc')
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'invoices' => $rows->map(fn ($i) => $this->presentInvoice($i))->values(),
            'summary' => $summary,
        ]);
    }

    public function invoice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalInvoice::class);

        $inv = RentalInvoice::with('contract.items', 'client', 'allocations.payment.method')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($inv->contract);
        $this->billing->applyLateFees($inv->rental_contract_id);
        $inv->refresh();

        return response()->json(['invoice' => array_merge($this->presentInvoice($inv), [
            'customer' => [
                'id' => $inv->rental_customer_id,
                'name' => optional($inv->client)->name,
                'phone' => optional($inv->client)->phone,
                'email' => optional($inv->client)->email,
            ],
            'contract' => $inv->contract ? [
                'id' => $inv->contract->id,
                'contract_number' => $inv->contract->contract_number,
                'rate_period' => $inv->contract->rate_period,
                'grace_days' => (int) $inv->contract->grace_days,
                'late_fee_type' => $inv->contract->late_fee_type,
                'late_fee_value' => (float) $inv->contract->late_fee_value,
                'late_fee_frequency' => $inv->contract->late_fee_frequency,
                'lines' => $inv->kind === 'rent' ? $inv->contract->items->map(fn ($l) => [
                    'item_name' => $l->item_name, 'quantity' => (int) $l->quantity, 'rate' => (float) $l->rate, 'amount' => (float) $l->amount,
                ])->values() : [],
            ] : null,
            'payments' => $inv->allocations->filter(fn ($a) => $a->payment)->map(fn ($a) => [
                'id' => $a->payment->id,
                'payment_number' => $a->payment->payment_number,
                'payment_date' => optional($a->payment->payment_date)->toDateString(),
                'method' => optional($a->payment->method)->name,
                'reference' => $a->payment->reference,
                'amount' => (float) $a->amount,
            ])->values(),
            'notes' => $inv->notes,
            'void_reason' => $inv->void_reason,
            'voided_at' => optional($inv->voided_at)->toIso8601String(),
        ])]);
    }

    public function charge(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'manage', RentalInvoice::class);

        $request->validate([
            'rental_contract_id' => 'required|integer|exists:rental_contracts,id',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        $c = RentalContract::whereNull('deleted_at')->findOrFail($request->rental_contract_id);
        $this->abortIfDocumentWarehouseDenied($c);
        if (in_array($c->status, ['draft', 'cancelled'], true)) {
            return $this->refuse('Charges can only be added to contracts that went live.');
        }

        $inv = $this->billing->charge($c, $request->description, round((float) $request->amount, 2),
            $request->due_date ? Carbon::parse($request->due_date) : Carbon::today(), $request->notes);
        $this->contracts->log($c, 'charge_added', $request->description, ['invoice' => $inv->invoice_number, 'amount' => (float) $inv->amount]);

        return response()->json(['success' => true, 'id' => $inv->id]);
    }

    public function void(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', RentalInvoice::class);

        $inv = RentalInvoice::findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($inv->contract);
        if ($inv->status === 'void') {
            return $this->refuse('This invoice is already void.');
        }
        if ((float) $inv->paid_amount > 0) {
            return $this->refuse('This invoice has payments. Delete those payments first, then void it.');
        }
        $request->validate(['reason' => 'required|string|max:2000'], ['reason.required' => 'Please give a reason for voiding.']);

        DB::transaction(fn () => $this->billing->void($inv, $request->reason));
        if ($inv->contract) {
            $this->contracts->log($inv->contract, 'invoice_voided', $request->reason, ['invoice' => $inv->invoice_number]);
        }

        return response()->json(['success' => true]);
    }

    public function waiveLateFee(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', RentalInvoice::class);

        $inv = RentalInvoice::findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($inv->contract);
        if ($inv->status === 'void') {
            return $this->refuse('This invoice is void.');
        }
        $request->validate(['waive' => 'required|boolean']);
        if ($request->boolean('waive') && (float) $inv->paid_amount > (float) $inv->amount + 0.004) {
            return $this->refuse('Part of the late fee has already been paid. Delete that payment first, then waive the fee.');
        }

        DB::transaction(function () use ($inv, $request) {
            $inv->update(['late_fee_waived' => $request->boolean('waive')]);
            if (! $request->boolean('waive')) {
                $this->billing->applyLateFees($inv->rental_contract_id);
            }
            $this->billing->refreshStatus($inv->fresh());
            $this->accounting->syncLateFee($inv->fresh());
        });

        return response()->json(['success' => true]);
    }

    public function invoicePdf(Request $request, $id, \App\Services\Rental\RentalPdf $pdfs)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalInvoice::class);

        $inv = RentalInvoice::findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($inv->contract);
        $pdf = $pdfs->invoice($inv);
        $filename = 'Rental_Invoice_' . $inv->invoice_number . '.pdf';

        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    public function payments(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalInvoice::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = RentalPayment::with('allocations.invoice', 'method', 'account', 'contract')
            ->join('clients', 'clients.id', '=', 'rental_payments.client_id')
            ->select('rental_payments.*', 'clients.name as customer_name')
            ->whereNull('rental_payments.deleted_at')
            ->when($request->filled('rental_contract_id'), fn ($q) => $q->where('rental_payments.rental_contract_id', $request->rental_contract_id))
            ->when($request->filled('rental_customer_id'), fn ($q) => $q->where('rental_payments.rental_customer_id', $request->rental_customer_id))
            ->when($request->filled('payment_method_id'), fn ($q) => $q->where('rental_payments.payment_method_id', $request->payment_method_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('rental_payments.payment_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('rental_payments.payment_date', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('rental_payments.payment_number', 'LIKE', "%{$s}%")
                    ->orWhere('rental_payments.reference', 'LIKE', "%{$s}%")
                    ->orWhere('clients.name', 'LIKE', "%{$s}%"));
            });

        $total = (float) (clone $query)->sum('rental_payments.amount');
        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->orderBy('rental_payments.payment_date', $dir)->orderBy('rental_payments.id', $dir)
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'total_amount' => round($total, 2),
            'payments' => $rows->map(fn ($p) => [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'payment_date' => optional($p->payment_date)->toDateString(),
                'customer_name' => $p->customer_name,
                'rental_customer_id' => $p->rental_customer_id,
                'rental_contract_id' => $p->rental_contract_id,
                'contract_number' => optional($p->contract)->contract_number,
                'amount' => (float) $p->amount,
                'source' => $p->source,
                'method' => optional($p->method)->name,
                'account' => optional($p->account)->account_name,
                'reference' => $p->reference,
                'notes' => $p->notes,
                'invoices' => $p->allocations->map(fn ($a) => [
                    'id' => $a->rental_invoice_id,
                    'number' => optional($a->invoice)->invoice_number,
                    'amount' => (float) $a->amount,
                ])->values(),
            ])->values(),
        ]);
    }

    public function paymentMeta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalInvoice::class);

        return response()->json([
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name']),
            'default_payment_method_id' => optional(Setting::whereNull('deleted_at')->first())->default_payment_method_id,
            'default_account_id' => optional(Setting::whereNull('deleted_at')->first())->default_account_id,
        ]);
    }

    /**
     * Record money received. Scope: one invoice, else one contract, else all
     * of the customer's open invoices — oldest due first. Refuses more than
     * is owed in that scope (no silent credit balances).
     */
    public function storePayment(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pay', RentalInvoice::class);

        $request->validate([
            'rental_customer_id' => 'required_without_all:rental_contract_id,rental_invoice_id|nullable|integer|exists:rental_customers,id',
            'rental_contract_id' => 'nullable|integer|exists:rental_contracts,id',
            'rental_invoice_id' => 'nullable|integer|exists:rental_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date|before_or_equal:today',
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id', \App\Models\PaymentMethod::notCustomerWalletRule()],
            'account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
        ], [], ['payment_method_id' => 'payment method']);

        $invoice = $request->rental_invoice_id ? RentalInvoice::findOrFail($request->rental_invoice_id) : null;
        $contractId = $invoice ? $invoice->rental_contract_id : ($request->rental_contract_id ?: null);
        $contract = $contractId ? RentalContract::find($contractId) : null;
        $customerId = $invoice ? $invoice->rental_customer_id : ($contract ? $contract->rental_customer_id : (int) $request->rental_customer_id);
        $customer = RentalCustomer::withTrashed()->findOrFail($customerId);

        // Make sure today's bills and late fees exist before measuring what's owed.
        $this->billing->run($contractId);

        $owed = $invoice
            ? $invoice->fresh()->balance()
            : RentalInvoice::whereIn('status', ['unpaid', 'partial'])
                ->when($contractId, fn ($q) => $q->where('rental_contract_id', $contractId))
                ->when(! $contractId, fn ($q) => $q->where('rental_customer_id', $customerId))
                ->get()->sum(fn ($i) => $i->balance());

        $amount = round((float) $request->amount, 2);
        if ($owed <= 0) {
            return $this->refuse('Nothing is owed here — there is no open invoice to pay.');
        }
        if ($amount > round($owed, 2) + 0.004) {
            return $this->refuse('The amount is more than what is owed (' . number_format($owed, 2) . ').', ['owed' => round($owed, 2)]);
        }

        $payment = DB::transaction(function () use ($request, $amount, $customer, $contractId, $invoice, $customerId) {
            // Re-measure what is owed with the invoices locked, so two payments
            // sent at the same time can't both pass the overpayment check.
            $open = RentalInvoice::whereIn('status', ['unpaid', 'partial'])
                ->when($invoice, fn ($q) => $q->whereKey($invoice->id))
                ->when(! $invoice && $contractId, fn ($q) => $q->where('rental_contract_id', $contractId))
                ->when(! $invoice && ! $contractId, fn ($q) => $q->where('rental_customer_id', $customerId))
                ->lockForUpdate()->get();
            $owedNow = round($open->sum(fn ($i) => $i->balance()), 2);
            if ($amount > $owedNow + 0.004) {
                abort($this->refuse('The amount is more than what is owed (' . number_format($owedNow, 2) . ').', ['owed' => $owedNow]));
            }

            $payment = RentalPayment::create([
                'payment_number' => $this->billing->nextPaymentNumber(),
                'rental_contract_id' => $contractId,
                'rental_customer_id' => $customer->id,
                'client_id' => $customer->client_id,
                'amount' => $amount,
                'payment_date' => Carbon::parse($request->payment_date)->toDateString(),
                'payment_method_id' => $request->payment_method_id,
                'account_id' => $request->account_id ?: null,
                'reference' => $request->reference,
                'notes' => $request->notes,
                'user_id' => optional($request->user('api'))->id,
            ]);
            if ($this->billing->allocate($payment, optional($invoice)->id) > 0.004) {
                abort($this->refuse('The amount is more than what is owed.'));
            }
            $this->accounting->paymentReceived($payment);

            return $payment;
        });

        if ($contractId && ($c = RentalContract::find($contractId))) {
            $this->contracts->log($c, 'payment', null, ['payment' => $payment->payment_number, 'amount' => $amount]);
        }

        return response()->json(['success' => true, 'id' => $payment->id, 'payment_number' => $payment->payment_number]);
    }

    /** Reverses a payment: allocations undone, cash/bank balance and journal reversed. */
    public function destroyPayment(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'deletePayment', RentalInvoice::class);

        $payment = RentalPayment::whereNull('deleted_at')->findOrFail($id);
        if ($payment->source === 'deposit') {
            return $this->refuse('This payment was taken from the deposit. Undo it from the contract\'s Deposit tab so the deposit balance is restored too.');
        }
        DB::transaction(function () use ($payment) {
            $this->billing->unallocate($payment);
            $this->accounting->paymentReceived($payment, true);
            $payment->delete();
        });
        if ($payment->rental_contract_id && ($c = RentalContract::find($payment->rental_contract_id))) {
            $this->contracts->log($c, 'payment_deleted', null, ['payment' => $payment->payment_number, 'amount' => (float) $payment->amount]);
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Contract billing view
    // ------------------------------------------------------------------

    /** Issued invoices + the periods still to be billed, and the money summary. */
    public function contractBilling(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalInvoice::class);

        $c = RentalContract::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($c);
        $this->billing->run($c->id);

        $invoices = RentalInvoice::with('contract')->where('rental_contract_id', $c->id)->orderBy('due_date')->orderBy('id')->get();

        // Upcoming periods not issued yet (recurring, fixed or open-ended — up to 24 ahead).
        $projected = [];
        if ($c->billing_type === 'recurring' && in_array($c->status, ['draft', 'active'], true)) {
            $issued = $invoices->where('kind', 'rent')->pluck('sequence')->flip();
            for ($i = 0, $n = 0; $n < 24 && $i < 5000; $i++) {
                $from = $this->contracts->addPeriods($c->start_date, $c->rate_period, $i);
                if ($c->end_date && $from->gte($c->end_date)) {
                    break;
                }
                if (isset($issued[$i])) {
                    continue;
                }
                $to = $this->contracts->addPeriods($c->start_date, $c->rate_period, $i + 1);
                if ($c->end_date && $to->gt($c->end_date)) {
                    $to = $c->end_date->copy();
                }
                $projected[] = [
                    'sequence' => $i,
                    'period_start' => $from->toIso8601String(),
                    'period_end' => $to->toIso8601String(),
                    'due_date' => $from->toDateString(),
                    'amount' => (float) $c->total_amount,
                ];
                $n++;
            }
        } elseif ($c->billing_type === 'upfront' && $c->status === 'draft') {
            $projected[] = [
                'sequence' => 0,
                'period_start' => $c->start_date->toIso8601String(),
                'period_end' => optional($c->end_date)->toIso8601String(),
                'due_date' => $c->start_date->toDateString(),
                'amount' => (float) $c->total_amount,
            ];
        }

        $next = $invoices->filter(fn ($i) => $i->balance() > 0)->sortBy('due_date')->first();

        return response()->json([
            'summary' => array_merge($this->billing->summary($invoices, (int) $c->grace_days), [
                'next_due' => $next ? ['id' => $next->id, 'date' => $next->due_date->toDateString(), 'amount' => $next->balance()] : null,
            ]),
            'invoices' => $invoices->map(fn ($i) => $this->presentInvoice($i))->values(),
            'projected' => $projected,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function applyInvoiceStage($query, string $stage): void
    {
        $today = Carbon::today()->toDateString();
        switch ($stage) {
            case 'open':
                $query->whereIn('rental_invoices.status', ['unpaid', 'partial']);
                return;
            case 'overdue':
                $query->whereIn('rental_invoices.status', ['unpaid', 'partial'])
                    ->whereRaw('DATE_ADD(rental_invoices.due_date, INTERVAL rental_contracts.grace_days DAY) < ?', [$today]);
                return;
            case 'unpaid':
            case 'partial':
            case 'paid':
            case 'void':
                $query->where('rental_invoices.status', $stage);
                return;
        }
    }

    private function invoiceSummary($query): array
    {
        $today = Carbon::today()->toDateString();
        $open = (clone $query)->whereIn('rental_invoices.status', ['unpaid', 'partial'])->get();
        $balance = fn ($i) => $i->balance();

        $counts = [];
        foreach (['open', 'overdue', 'unpaid', 'partial', 'paid', 'void', 'all'] as $stage) {
            $q = clone $query;
            $this->applyInvoiceStage($q, $stage);
            $counts[$stage] = $q->count();
        }

        return [
            'counts' => $counts,
            'outstanding' => round($open->sum($balance), 2),
            'overdue' => round($open->filter(fn ($i) => $i->due_date->copy()->addDays((int) $i->contract_grace)->lt(Carbon::today()))->sum($balance), 2),
            'late_fees' => round($open->sum(fn ($i) => $i->effectiveLateFee()), 2),
            'collected_month' => round((float) RentalPayment::whereNull('deleted_at')
                ->whereDate('payment_date', '>=', Carbon::today()->startOfMonth())->sum('amount'), 2),
            'due_week' => round($open->filter(fn ($i) => $i->due_date->between(Carbon::today(), Carbon::today()->addDays(7)))->sum($balance), 2),
        ];
    }

    private function presentInvoice(RentalInvoice $i): array
    {
        $grace = (int) ($i->contract_grace ?? optional($i->contract)->grace_days ?? 0);

        return [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'rental_contract_id' => $i->rental_contract_id,
            'contract_number' => $i->contract_number ?? optional($i->contract)->contract_number,
            'rental_customer_id' => $i->rental_customer_id,
            'customer_name' => $i->customer_name ?? optional($i->client)->name,
            'kind' => $i->kind,
            'sequence' => $i->sequence,
            'description' => $i->description,
            'period_start' => optional($i->period_start)->toIso8601String(),
            'period_end' => optional($i->period_end)->toIso8601String(),
            'issue_date' => optional($i->issue_date)->toDateString(),
            'due_date' => optional($i->due_date)->toDateString(),
            'subtotal' => (float) $i->subtotal,
            'discount' => (float) $i->discount,
            'tax_amount' => (float) $i->tax_amount,
            'amount' => (float) $i->amount,
            'late_fee' => (float) $i->late_fee,
            'late_fee_waived' => (bool) $i->late_fee_waived,
            'total' => $i->total(),
            'paid_amount' => (float) $i->paid_amount,
            'balance' => $i->balance(),
            'status' => $i->status,
            'overdue' => $i->isOverdue($grace),
            'days_overdue' => $i->isOverdue($grace) ? (int) $i->due_date->copy()->addDays($grace)->diffInDays(Carbon::today()) : 0,
        ];
    }

    private function refuse(string $message, array $extra = [])
    {
        return response()->json(array_merge(['message' => $message], $extra), 422);
    }
}
