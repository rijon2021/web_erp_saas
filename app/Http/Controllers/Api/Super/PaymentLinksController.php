<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Central\Super\PaymentLinkController as WebPaymentLinkController;
use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaymentLink;
use App\Services\Billing\PaymentLinkNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Payment links: a one-off amount a workspace pays on the public /pay page.
 * The app shares the link itself (WhatsApp, SMS…) through the phone's share
 * sheet, or asks the server to email it.
 */
class PaymentLinksController extends Controller
{
    use RunsWebActions;

    private const FILTERS = ['active', 'paid', 'expired', 'cancelled', 'refunded'];

    public function __construct(protected PaymentLinkNotifier $notifier)
    {
    }

    public function index(Request $request)
    {
        $query = PaymentLink::with('tenant');

        if (in_array($status = $request->query('status'), self::FILTERS, true)) {
            $query->withEffectiveStatus($status);
        }
        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }
        if ($search = trim((string) $request->query('search'))) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('tenant_id', 'like', $like)
                ->orWhereHas('tenant', fn ($t) => $t->where('data->company_name', 'like', $like)));
        }

        $page = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (PaymentLink $l) => $this->link($l))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
            'currency' => [
                'code'   => GeneralSetting::currencyCode(),
                'symbol' => GeneralSetting::currencySymbol(),
            ],
        ]);
    }

    /** Same rules as the web form (PaymentLinkController@store). */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tenant_id'   => ['required', 'string', 'exists:central.tenants,id'],
            'title'       => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ]);

        $link = PaymentLink::create([
            'tenant_id'   => $validated['tenant_id'],
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'amount'      => round((float) $validated['amount'], 2),
            'currency'    => GeneralSetting::currencyCode(),
            'status'      => PaymentLink::STATUS_ACTIVE,
            'expires_at'  => $validated['expires_at'] ?? null,
            'created_by'  => Auth::guard('central')->id(),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => __('super.flash.payment_link_created'),
            'link'    => $this->link($link->load('tenant')),
        ]);
    }

    public function show(PaymentLink $paymentLink)
    {
        return response()->json(['link' => $this->link($paymentLink->load('tenant'))]);
    }

    public function sendEmail(Request $request, PaymentLink $paymentLink)
    {
        return $this->runWebAction($request, fn () => app(WebPaymentLinkController::class)->sendEmail($request, $paymentLink));
    }

    public function cancel(Request $request, PaymentLink $paymentLink)
    {
        return $this->runWebAction($request, fn () => app(WebPaymentLinkController::class)->cancel($paymentLink));
    }

    private function link(PaymentLink $l): array
    {
        $status = $l->effective_status;

        return [
            'id'               => $l->id,
            'title'            => $l->title,
            'description'      => $l->description,
            'amount'           => (float) $l->amount,
            'amount_label'     => PaymentLinkNotifier::formatAmount($l),
            'status'           => $status,
            'tenant_id'        => $l->tenant_id,
            'company_name'     => $l->tenant?->company_name ?: $l->tenant_id,
            'admin_email'      => $l->tenant?->admin_email,
            'url'              => $l->url(),
            'share_message'    => $this->notifier->whatsappMessage($l),
            'expires_at'       => SuperPresenter::date($l->expires_at),
            'paid_at'          => SuperPresenter::date($l->paid_at),
            'last_sent_at'     => SuperPresenter::date($l->last_sent_at),
            'created_at'       => SuperPresenter::date($l->created_at),
            'can_send'         => $l->isPayable(),
            'can_cancel'       => $l->status === PaymentLink::STATUS_ACTIVE,
        ];
    }
}
