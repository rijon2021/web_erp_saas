<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Central\Addon;
use App\Models\Central\TenantAddon;
use App\Services\Billing\AddonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant side of add-ons (Billing → Add-ons): list what is for sale, start a
 * purchase or a renewal. Paying happens on the hosted payment-link page the
 * response points to; the add-on switches on when that link is settled.
 */
class BillingAddonsController extends Controller
{
    /** Same rule as BillingApiController: the owner (user #1) or billing_view. */
    private function authorizeBilling(): void
    {
        $user = auth()->user();
        if ($user && (int) $user->id === 1) {
            return;
        }
        foreach ($user?->roles ?? [] as $role) {
            if ($role->permissions->pluck('name')->contains('billing_view')) {
                return;
            }
        }
        abort(403, 'You are not authorized to access billing.');
    }

    /** 422 instead of a 500 when the add-on migration has not run yet. */
    private function notReady(): ?JsonResponse
    {
        return Addon::tablesReady() ? null : response()->json(['message' => __('super.addons.err_not_for_sale')], 422);
    }

    /** GET /api/billing/addons */
    public function index(AddonService $service): JsonResponse
    {
        $this->authorizeBilling();

        $tenant = tenant();
        $subscription = $tenant ? $service->grantingSubscription($tenant) : null;

        return response()->json([
            // Off (Settings → Features) or not migrated: the page says "not available".
            'ready'    => Addon::marketplaceOn(),
            'has_plan' => (bool) $subscription?->plan,
            'plan'     => $subscription?->plan ? [
                'name'          => $subscription->plan->name,
                'billing_cycle' => $subscription->billing_cycle,
                'ends_at'       => $subscription->ends_at?->toIso8601String(),
                'on_trial'      => $subscription->status === \App\Models\Central\TenantSubscription::STATUS_TRIAL,
            ] : null,
            'addons'   => $tenant ? $service->offersFor($tenant) : [],
        ]);
    }

    /** POST /api/billing/addons/purchase {key, cycle} → {url} of the payment page */
    public function purchase(Request $request, AddonService $service): JsonResponse
    {
        $this->authorizeBilling();
        if ($r = $this->notReady()) {
            return $r;
        }

        $data = $request->validate([
            'key'      => ['required', 'string', 'max:80'],
            'cycle'    => ['required', Rule::in(Addon::CYCLES)],
            // Limit packs: how many packs (ignored for modules / themes).
            'quantity' => ['nullable', 'integer', 'min:1', 'max:' . Addon::MAX_PACKS],
        ]);

        try {
            $link = $service->startPurchase(tenant(), $data['key'], $data['cycle'], (int) ($data['quantity'] ?? 1));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $link->url()]);
    }

    /** POST /api/billing/addons/trial {key} — free trial, once per add-on */
    public function trial(Request $request, AddonService $service): JsonResponse
    {
        $this->authorizeBilling();
        if ($r = $this->notReady()) {
            return $r;
        }

        $data = $request->validate(['key' => ['required', 'string', 'max:80']]);

        try {
            $row = $service->startTrial(tenant(), $data['key']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'ends_at' => $row->ends_at?->toIso8601String()]);
    }

    /** POST /api/billing/addons/{id}/renew → {url} */
    public function renew(int $id, AddonService $service): JsonResponse
    {
        $this->authorizeBilling();
        if ($r = $this->notReady()) {
            return $r;
        }

        $row = TenantAddon::where('tenant_id', tenant()->id)->findOrFail($id);

        try {
            $link = $service->startRenewal($row);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $link->url()]);
    }
}
