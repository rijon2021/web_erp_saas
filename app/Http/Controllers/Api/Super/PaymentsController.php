<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Central\Super\PaymentController as WebPaymentController;
use App\Http\Controllers\Controller;
use App\Models\Central\TenantBillingPayment;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    use RunsWebActions;

    private const STATUSES = ['paid', 'pending', 'failed', 'refunded'];

    public function index(Request $request)
    {
        $query = TenantBillingPayment::with(['tenant', 'plan']);

        if (in_array($status = $request->query('status'), self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        if ($search = trim((string) $request->query('search'))) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('invoice_number', 'like', $like)
                  ->orWhere('transaction_id', 'like', $like)
                  ->orWhere('tenant_id', 'like', $like)
                  ->orWhereHas('tenant', fn ($t) => $t->where('data->company_name', 'like', $like));
            });
        }

        $page = $query->orderByDesc('created_at')->paginate(25);
        $since = now()->subDays(30);

        return response()->json([
            'data'   => collect($page->items())->map(fn ($p) => SuperPresenter::payment($p))->values(),
            'meta'   => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
            // Paid and failed over the last 30 days; pending is everything still open.
            'totals' => [
                'paid'    => SuperPresenter::money(TenantBillingPayment::where('status', 'paid')->where('paid_at', '>=', $since)->sum(\Illuminate\Support\Facades\DB::raw(TenantBillingPayment::revenueSql()))),
                'pending' => SuperPresenter::money(TenantBillingPayment::where('status', 'pending')->sum('amount')),
                'failed'  => SuperPresenter::money(TenantBillingPayment::where('status', 'failed')->where('created_at', '>=', $since)->sum('amount')),
            ],
        ]);
    }

    public function show(TenantBillingPayment $payment)
    {
        $payment->load(['tenant', 'plan']);

        return response()->json(['payment' => SuperPresenter::payment($payment)]);
    }

    public function markPaid(Request $request, TenantBillingPayment $payment)
    {
        return $this->act($request, 'markPaid', $payment);
    }

    public function markFailed(Request $request, TenantBillingPayment $payment)
    {
        return $this->act($request, 'markFailed', $payment);
    }

    public function markRefunded(Request $request, TenantBillingPayment $payment)
    {
        if ($payment->status !== TenantBillingPayment::STATUS_PAID) {
            return response()->json(['ok' => false, 'message' => 'Only a paid payment can be refunded.'], 422);
        }

        return $this->act($request, 'markRefunded', $payment);
    }

    /** What the record-payment form needs, prefilled from the workspace's subscription. */
    public function options(Request $request)
    {
        $prefill = null;
        if ($tenant = \App\Tenant::find($request->query('tenant_id'))) {
            $current = $tenant->currentSubscription();
            if ($current) {
                $cycle = $current->billing_cycle ?: 'monthly';
                $prefill = [
                    'plan_id'       => $current->plan_id,
                    'billing_cycle' => $cycle,
                    'amount'        => $current->plan ? (float) $current->plan->getPriceForCycle($cycle) : (float) $current->amount,
                ];
            }
        }

        return response()->json([
            'gateways' => collect(TenantBillingPayment::GATEWAYS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'currency' => \App\Models\Central\GeneralSetting::currencyCode(),
            'prefill'  => $prefill,
        ]);
    }

    /**
     * Record a payment taken outside the gateways (cash, bank, cheque…), same
     * as the web form: a paid payment applied to the subscription renews or
     * re-activates it and emails the receipt.
     */
    public function store(Request $request)
    {
        $request->merge([
            'currency' => $request->input('currency') ?: \App\Models\Central\GeneralSetting::currencyCode(),
            'status'   => $request->input('status') ?: TenantBillingPayment::STATUS_PAID,
        ]);

        return $this->runWebAction($request, fn () => app()->call([app(WebPaymentController::class), 'store'], ['request' => $request]));
    }

    /** The invoice PDF, same file as the web panel's download. */
    public function invoice(TenantBillingPayment $payment)
    {
        return app(WebPaymentController::class)->invoiceDownload($payment);
    }

    private function act(Request $request, string $method, TenantBillingPayment $payment)
    {
        $result = $this->runWebAction($request, fn () => app()->call([app(WebPaymentController::class), $method], ['payment' => $payment]));

        $data = $result->getData(true);
        $data['payment'] = SuperPresenter::payment($payment->fresh(['tenant', 'plan']));

        return response()->json($data, $result->getStatusCode());
    }
}
