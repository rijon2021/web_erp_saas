<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaymentLink;
use App\Models\Central\TenantBillingPayment;
use App\Services\Billing\BillingPaymentVerifier;
use App\Services\Billing\PaymentLinkNotifier;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PaymentLinkController extends Controller
{
    public function __construct(protected PaymentLinkNotifier $notifier)
    {
    }

    // ── List ─────────────────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        if (! Schema::connection('central')->hasTable('payment_links')) {
            return redirect()->route('super.payments.dashboard')
                ->with('error', __('super.flash.payment_links_not_migrated'));
        }

        $query = PaymentLink::with('tenant');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('tenant_id', 'like', "%{$search}%")
                  ->orWhereHas('tenant', fn ($tq) => $tq->where('data->company_name', 'like', "%{$search}%"));
            });
        }

        if (($status = $request->input('status')) && in_array($status, PaymentLink::STATUSES, true)) {
            $query->withEffectiveStatus($status);
        }

        if ($tenantId = $request->input('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        $links = $query->orderByDesc('id')->paginate(25)->withQueryString();

        $stats = [
            'active' => PaymentLink::withEffectiveStatus(PaymentLink::STATUS_ACTIVE)->count(),
            'paid'   => PaymentLink::where('status', PaymentLink::STATUS_PAID)->count(),
            'collected' => (float) PaymentLink::where('status', PaymentLink::STATUS_PAID)->sum('amount'),
            'outstanding' => (float) PaymentLink::withEffectiveStatus(PaymentLink::STATUS_ACTIVE)->sum('amount'),
        ];

        return view('central.super.payment-links.index', compact('links', 'stats'));
    }

    // ── Create ───────────────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        if (! Schema::connection('central')->hasTable('payment_links')) {
            return redirect()->route('super.payments.dashboard')
                ->with('error', __('super.flash.payment_links_not_migrated'));
        }

        $tenants = Tenant::orderBy('id')->get()
            ->sortBy(fn (Tenant $t) => mb_strtolower($t->company_name ?? $t->id))
            ->values();

        return view('central.super.payment-links.create', [
            'tenants'          => $tenants,
            'selectedTenantId' => $request->input('tenant_id'),
            'linkCurrency'     => GeneralSetting::currencyCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id'   => ['required', 'string', 'exists:central.tenants,id'],
            'title'       => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
            'send_email'  => ['nullable', 'boolean'],
            'email_to'    => ['nullable', 'required_if:send_email,1', 'email', 'max:190'],
        ]);

        $link = PaymentLink::create([
            'tenant_id'   => $validated['tenant_id'],
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'amount'      => round((float) $validated['amount'], 2),
            // Gateways convert from the platform currency, and revenue reports
            // sum billing payments in it — so links are always issued in it.
            'currency'    => GeneralSetting::currencyCode(),
            'status'      => PaymentLink::STATUS_ACTIVE,
            'expires_at'  => $validated['expires_at'] ?? null,
            'created_by'  => Auth::guard('central')->id(),
        ]);

        $redirect = redirect()->route('super.payment-links.show', $link)
            ->with('success', __('super.flash.payment_link_created'));

        if ($request->boolean('send_email')) {
            $result = $this->notifier->sendEmail($link, $validated['email_to']);
            $link->logSend('email', $validated['email_to'], $result['ok'], Auth::guard('central')->id(), $result['error']);

            $redirect = $result['ok']
                ? $redirect->with('success', __('super.flash.payment_link_created_sent', ['email' => $validated['email_to']]))
                : $redirect->with('error', __('super.flash.payment_link_email_failed', ['error' => $result['error']]));
        }

        return $redirect;
    }

    // ── Show ─────────────────────────────────────────────────────────

    public function show(PaymentLink $paymentLink): View
    {
        $paymentLink->load(['tenant.domains', 'payment', 'creator', 'attempts' => fn ($q) => $q->orderByDesc('id')]);

        $tenant = $paymentLink->tenant;

        return view('central.super.payment-links.show', [
            'link'            => $paymentLink,
            'tenant'          => $tenant,
            'defaultEmail'    => $tenant?->admin_email,
            'defaultPhone'    => $tenant?->owner_phone,
            'whatsappMessage' => $this->notifier->whatsappMessage($paymentLink),
            'amountLabel'     => PaymentLinkNotifier::formatAmount($paymentLink),
        ]);
    }

    // ── Send ─────────────────────────────────────────────────────────

    public function sendEmail(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        $validated = $request->validate([
            'email_to' => ['required', 'email', 'max:190'],
        ]);

        if (! $paymentLink->isPayable()) {
            return back()->with('error', __('super.flash.payment_link_not_payable'));
        }

        $result = $this->notifier->sendEmail($paymentLink, $validated['email_to']);
        $paymentLink->logSend('email', $validated['email_to'], $result['ok'], Auth::guard('central')->id(), $result['error']);

        return $result['ok']
            ? back()->with('success', __('super.flash.payment_link_email_sent', ['email' => $validated['email_to']]))
            : back()->with('error', __('super.flash.payment_link_email_failed', ['error' => $result['error']]));
    }

    /**
     * Records the send and hands the browser to WhatsApp (wa.me click-to-chat)
     * with the message pre-filled; the admin presses Send in WhatsApp.
     */
    public function sendWhatsapp(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        $validated = $request->validate([
            'phone'   => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        if (! $paymentLink->isPayable()) {
            return back()->with('error', __('super.flash.payment_link_not_payable'));
        }

        $phone = PaymentLinkNotifier::normalizePhone($validated['phone']);
        if (! $phone) {
            return back()->withInput()->withErrors(['phone' => __('super.flash.payment_link_bad_phone')]);
        }

        // The link must survive an edited message — put it back if it was removed.
        $message = $validated['message'];
        if (! str_contains($message, $paymentLink->url())) {
            $message = rtrim($message) . "\n\n" . $paymentLink->url();
        }

        $paymentLink->logSend('whatsapp', '+' . $phone, true, Auth::guard('central')->id());

        return redirect()->away($this->notifier->whatsappUrl($phone, $message));
    }

    // ── Status ───────────────────────────────────────────────────────

    /**
     * Ask the gateway about every pending attempt now, instead of waiting for
     * a webhook or the stale-payment cron (useful when a webhook was missed).
     */
    public function refresh(PaymentLink $paymentLink, BillingPaymentVerifier $verifier): RedirectResponse
    {
        if ($paymentLink->isPaid()) {
            return back()->with('info', __('super.flash.payment_link_already_paid'));
        }

        // Superseded attempts are included: a payer may have finished an older
        // checkout whose webhook never arrived after starting a newer one.
        $pending = $paymentLink->attempts()
            ->whereIn('status', [TenantBillingPayment::STATUS_PENDING, TenantBillingPayment::STATUS_SUPERSEDED])
            ->whereNotNull('gateway_payment_id')
            ->where('gateway', '!=', 'offline')
            ->orderByDesc('id')
            ->get();

        foreach ($pending as $attempt) {
            if ($verifier->verify($attempt)) {
                break;
            }
        }

        return $paymentLink->fresh()->isPaid()
            ? back()->with('success', __('super.flash.payment_link_now_paid'))
            : back()->with('info', __('super.flash.payment_link_still_unpaid'));
    }

    public function cancel(PaymentLink $paymentLink): RedirectResponse
    {
        if ($paymentLink->status !== PaymentLink::STATUS_ACTIVE) {
            return back()->with('error', __('super.flash.payment_link_cannot_cancel'));
        }

        $paymentLink->update(['status' => PaymentLink::STATUS_CANCELLED]);

        return back()->with('success', __('super.flash.payment_link_cancelled'));
    }

    public function reactivate(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        $validated = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Paid and refunded links are closed — issue a new link instead.
        if (! in_array($paymentLink->effective_status, [PaymentLink::STATUS_CANCELLED, PaymentLink::STATUS_EXPIRED], true)) {
            return back()->with('error', __('super.flash.payment_link_cannot_reactivate'));
        }

        $paymentLink->update([
            'status'     => PaymentLink::STATUS_ACTIVE,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return back()->with('success', __('super.flash.payment_link_reactivated'));
    }
}
