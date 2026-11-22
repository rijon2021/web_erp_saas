<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Central\WebhookController;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PaymentController extends Controller
{
    // ── Dashboard ────────────────────────────────────────────────────

    public function dashboard(): View
    {
        $payments = TenantBillingPayment::on('central');

        // In the main currency (base_amount) so payments in other currencies
        // (multi-currency pricing) add up correctly.
        $totalRevenue = (clone $payments)->where('status', 'paid')->sum(DB::raw(TenantBillingPayment::revenueSql()));
        $totalTax     = (clone $payments)->where('status', 'paid')->sum('tax');
        $totalTransactions = (clone $payments)->count();
        $paidCount    = (clone $payments)->where('status', 'paid')->count();
        $failedCount  = (clone $payments)->where('status', 'failed')->count();
        $refundedCount = (clone $payments)->where('status', 'refunded')->count();
        $pendingCount = (clone $payments)->where('status', 'pending')->count();

        $monthlyRevenue = TenantBillingPayment::on('central')
            ->where('status', 'paid')
            ->where('paid_at', '>=', Carbon::now()->startOfMonth())
            ->sum(DB::raw(TenantBillingPayment::revenueSql()));

        $revenueChart = TenantBillingPayment::on('central')
            ->where('status', 'paid')
            ->where('paid_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->select(
                DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as month"),
                DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as revenue'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $months = collect();
        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->format('Y-m');
            $found = $revenueChart->firstWhere('month', $m);
            $months->push([
                'month'   => Carbon::now()->subMonths($i)->format('M Y'),
                'revenue' => $found ? (float) $found->revenue : 0,
                'count'   => $found ? $found->count : 0,
            ]);
        }

        $gatewayBreakdown = TenantBillingPayment::on('central')
            ->where('status', 'paid')
            ->select('gateway', DB::raw('SUM(' . TenantBillingPayment::revenueSql() . ') as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('gateway')
            ->get();

        $recentPayments = TenantBillingPayment::on('central')
            ->with(['tenant', 'plan'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('central.super.payments.dashboard', compact(
            'totalRevenue', 'totalTax', 'totalTransactions',
            'paidCount', 'failedCount', 'refundedCount', 'pendingCount',
            'monthlyRevenue', 'months', 'gatewayBreakdown', 'recentPayments'
        ));
    }

    // ── Transactions list ───────────────────────────────────────────

    public function index(Request $request): View
    {
        $query = TenantBillingPayment::on('central')->with(['tenant', 'plan']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('transaction_id', 'like', "%{$search}%")
                  ->orWhere('gateway_payment_id', 'like', "%{$search}%")
                  ->orWhereHas('tenant', function ($tq) use ($search) {
                      $tq->where('data->company_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($gateway = $request->input('gateway')) {
            $query->where('gateway', $gateway);
        }

        if ($planId = $request->input('plan_id')) {
            $query->where('plan_id', $planId);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $payments = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        $plans = Plan::on('central')->where('is_active', true)->orderBy('name')->get();

        return view('central.super.payments.index', compact('payments', 'plans'));
    }

    // ── Payment details ─────────────────────────────────────────────

    public function show(TenantBillingPayment $payment): View
    {
        $payment->load(['tenant.domains', 'plan', 'subscription.plan']);

        return view('central.super.payments.show', compact('payment'));
    }

    // ── Manual payment form ─────────────────────────────────────────

    public function create(Request $request): View
    {
        $tenants = Tenant::with(['subscription.plan', 'domains'])->orderByDesc('created_at')->get();
        $plans = Plan::on('central')->where('is_active', true)->orderBy('name')->get();
        $selectedTenantId = (string) $request->query('tenant_id', '');

        // Pre-fill plan / cycle / amount from the selected tenant's current subscription.
        $prefill = null;
        if ($selectedTenantId !== '' && ($selectedTenant = $tenants->firstWhere('id', $selectedTenantId))) {
            $current = $selectedTenant->currentSubscription();
            if ($current) {
                $prefill = [
                    'plan_id'       => $current->plan_id,
                    'billing_cycle' => $current->billing_cycle ?: 'monthly',
                    'amount'        => $current->plan ? $current->plan->getPriceForCycle($current->billing_cycle ?: 'monthly') : (float) $current->amount,
                ];
            }
        }

        return view('central.super.payments.create', compact('tenants', 'plans', 'selectedTenantId', 'prefill'));
    }

    /**
     * Record a payment taken outside the gateways (cash, bank, cheque…).
     *
     * A "paid" payment applied to the subscription goes through the same
     * lifecycle as a gateway payment: it renews an active subscription by one
     * cycle or re-activates a lapsed one, lifts a non-payment suspension and
     * emails the receipt. Plan / cycle on the form become the subscription's.
     */
    public function store(Request $request, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id'    => ['required', 'string', 'exists:central.tenants,id'],
            'plan_id'      => ['nullable', 'integer', 'exists:central.plans,id'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'tax'          => ['nullable', 'numeric', 'min:0'],
            'currency'     => ['required', 'string', 'size:3'],
            'gateway'      => ['required', 'string', 'in:' . implode(',', array_keys(TenantBillingPayment::GATEWAYS))],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'status'       => ['required', 'in:' . implode(',', TenantBillingPayment::STATUSES)],
            'notes'        => ['nullable', 'string'],
            'paid_at'      => ['nullable', 'date'],
        ]);

        $tenant = Tenant::find($validated['tenant_id']);
        $subscription = $tenant?->currentSubscription();
        $apply = $request->boolean('apply_to_subscription') && $subscription !== null;

        $validated['tenant_subscription_id'] = $subscription?->id;
        $validated['plan_id'] = $validated['plan_id'] ?? $subscription?->plan_id;
        $validated['tax'] = $validated['tax'] ?? 0;
        $paidAt = $validated['paid_at'] ?? null;
        $validated['paid_at'] = $validated['status'] === 'paid' ? ($paidAt ?? now()) : $paidAt;

        $applyPaid = $apply && $validated['status'] === TenantBillingPayment::STATUS_PAID;
        if ($applyPaid) {
            // Created pending, then settled by the lifecycle service below.
            $validated['status'] = TenantBillingPayment::STATUS_PENDING;
            $validated['paid_at'] = null;

            $plan = Plan::find($validated['plan_id']);
            if ($plan && ((int) $plan->id !== (int) $subscription->plan_id || $validated['billing_cycle'] !== $subscription->billing_cycle)) {
                $subscription->update([
                    'plan_id'       => $plan->id,
                    'billing_cycle' => $validated['billing_cycle'],
                    'amount'        => $plan->getPriceForCycle($validated['billing_cycle']),
                ]);
            }
        }

        $payment = TenantBillingPayment::create($validated);
        $message = __('super.flash.payment_recorded');

        if ($applyPaid) {
            $result = $lifecycle->markPaid($payment, ['transaction_id' => $validated['transaction_id'] ?? null]);
            if ($paidAt) {
                $payment->update(['paid_at' => Carbon::parse($paidAt)]);
            }
            if ($result['subscription_activated']) {
                $message .= __('super.flash.sub_activated_extra');
            } elseif ($result['subscription_renewed']) {
                $message .= __('super.flash.sub_renewed_extra');
            }
            if ($result['provisioning_dispatched']) {
                $message .= __('super.flash.provisioning_extra');
            }

            $subscription->refresh();
            \App\Models\Central\SubscriptionEvent::record($subscription, \App\Models\Central\SubscriptionEvent::ACTION_PAYMENT_RECORDED, [
                'payment_id' => $payment->id,
                'amount'     => (string) $payment->amount,
                'gateway'    => $payment->gateway,
                'ends_at'    => $subscription->ends_at?->toDateTimeString(),
            ], $validated['notes'] ?? null);
        }

        // Back to the tenant when the form was opened from its page.
        if ($request->input('return_to') === 'tenant' && $tenant) {
            return redirect()->route('super.tenants.show', $tenant)->with('success', $message);
        }

        return redirect()->route('super.payments.index')->with('success', $message);
    }

    // ── Status changes ──────────────────────────────────────────────

    public function markPaid(TenantBillingPayment $payment, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        if ($payment->isPaid()) {
            return back()->with('info', __('super.flash.payment_already_paid'));
        }

        if ($payment->status !== TenantBillingPayment::STATUS_PENDING) {
            return back()->with('error', __('super.flash.payment_only_pending_paid', ['status' => $payment->status]));
        }

        $result = $lifecycle->markPaid($payment);

        $message = __('super.flash.payment_marked_paid');
        if ($result['subscription_activated']) {
            $message .= __('super.flash.sub_activated_extra');
        } elseif ($result['subscription_renewed']) {
            $message .= __('super.flash.sub_renewed_extra');
        }
        if ($result['provisioning_dispatched']) {
            $message .= __('super.flash.provisioning_extra');
        }

        return back()->with('success', $message);
    }

    public function markFailed(TenantBillingPayment $payment, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        if ($payment->status !== TenantBillingPayment::STATUS_PENDING) {
            return back()->with('error', __('super.flash.payment_only_pending_failed'));
        }

        $lifecycle->markFailed($payment);

        return back()->with('success', __('super.flash.payment_marked_failed'));
    }

    public function markRefunded(TenantBillingPayment $payment, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->markRefunded($payment);

        return back()->with('success', __('super.flash.payment_marked_refunded'));
    }

    // ── Invoice PDF ─────────────────────────────────────────────────

    public function invoiceDownload(TenantBillingPayment $payment)
    {
        $payment->load(['tenant', 'plan']);

        $pdf = Pdf::loadView('central.super.payments.invoice-pdf', compact('payment'));

        return $pdf->download("invoice-{$payment->invoice_number}.pdf");
    }

    // ── Pending offline registrations ──────────────────────────────

    public function pendingRegistrations(Request $request): View
    {
        $registrations = PendingRegistration::where('gateway', 'offline')
            ->where('status', PendingRegistration::STATUS_PROCESSING)
            ->with('plan')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('central.super.payments.pending-registrations', compact('registrations'));
    }

    public function approveRegistration(PendingRegistration $registration, Request $request): RedirectResponse|JsonResponse
    {
        if ($registration->gateway !== 'offline' || $registration->status !== PendingRegistration::STATUS_PROCESSING) {
            $msg = __('super.flash.reg_cannot_approve');
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }

        try {
            $registration->update([
                'status'             => PendingRegistration::STATUS_PAID,
                'gateway_payment_id' => 'offline_approved_' . $registration->id,
                'transaction_id'     => 'offline_approved_' . $registration->id,
                'paid_at'            => now(),
            ]);

            $webhook = app(WebhookController::class);
            $webhook->provisionFromRegistration($registration, 'offline', skipProvisioning: true);

            $successMsg = __('super.flash.reg_approved', ['company' => $registration->company_name]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'      => true,
                    'company_name' => $registration->company_name,
                    'message'      => $successMsg,
                ]);
            }

            return back()->with('success', $successMsg);
        } catch (\Throwable $e) {
            Log::error("Failed to approve offline registration {$registration->id}: {$e->getMessage()}", ['exception' => $e]);
            $msg = __('super.flash.reg_approve_failed', ['error' => $e->getMessage()]);
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $msg], 500)
                : back()->with('error', $msg);
        }
    }

    public function rejectRegistration(PendingRegistration $registration): RedirectResponse
    {
        if ($registration->gateway !== 'offline' || $registration->status !== PendingRegistration::STATUS_PROCESSING) {
            return back()->with('error', __('super.flash.reg_cannot_reject'));
        }

        $registration->update([
            'status' => PendingRegistration::STATUS_FAILED,
        ]);

        \App\Services\EmailNotificationService::paymentProofRejected(
            (string) $registration->admin_email,
            $registration->company_name ?: $registration->subdomain,
            $registration->amount,
            Plan::find($registration->plan_id)?->name ?? ''
        );

        return back()->with('success', __('super.flash.reg_rejected', ['company' => $registration->company_name]));
    }

    // ── Invoices list ───────────────────────────────────────────────

    public function invoices(Request $request): View
    {
        $query = TenantBillingPayment::on('central')
            ->with(['tenant', 'plan'])
            ->whereNotNull('invoice_number');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('tenant', function ($tq) use ($search) {
                      $tq->where('data->company_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $invoices = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        return view('central.super.payments.invoices', compact('invoices'));
    }
}
