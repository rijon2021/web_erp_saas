<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\SubscriptionEvent;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One-click subscription actions on the super-admin tenant page. Each action
 * works on the tenant's current subscription and is written to the
 * subscription_events audit trail.
 */
class TenantSubscriptionPanelController extends Controller
{
    /**
     * Grant free days (rules in TenantSubscription::extendByDays()); a
     * workspace suspended for non-payment is restored.
     */
    public function extend(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $days = (int) $validated['days'];

        $subscription = $tenant->currentSubscription();
        if (! $subscription) {
            return back()->with('error', __('central.SubPanelNoSubscription'));
        }
        if (in_array($subscription->status, [TenantSubscription::STATUS_PENDING, TenantSubscription::STATUS_FAILED], true)) {
            return back()->with('error', __('central.SubPanelExtendPending'));
        }

        $before = [
            'status'        => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
            'ends_at'       => $subscription->ends_at?->toDateTimeString(),
        ];

        if (! $subscription->extendByDays($days)) {
            return back()->with('error', __('central.SubPanelNeverExpires'));
        }

        Tenant::liftNonPaymentSuspension($tenant->id);

        SubscriptionEvent::record($subscription, SubscriptionEvent::ACTION_EXTENDED, [
            'days'   => $days,
            'before' => $before,
            'after'  => [
                'status'        => $subscription->status,
                'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
                'ends_at'       => $subscription->ends_at?->toDateTimeString(),
            ],
        ], $validated['note'] ?? null);

        return back()->with('success', __('central.SubPanelExtended', [
            'days' => $days,
            'date' => ($subscription->trial_ends_at ?? $subscription->ends_at)->format('M d, Y'),
        ]));
    }

    /**
     * Move the current subscription to another plan and/or billing cycle
     * (rules in TenantSubscription::switchPlan()).
     */
    public function changePlan(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id'       => ['required', 'integer', 'exists:central.plans,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'amount'        => ['nullable', 'numeric', 'min:0'],
            'period'        => ['required', 'in:keep,restart'],
            'note'          => ['nullable', 'string', 'max:500'],
        ]);

        $subscription = $tenant->currentSubscription();
        if (! $subscription) {
            return back()->with('error', __('central.SubPanelNoSubscription'));
        }
        if (in_array($subscription->status, [TenantSubscription::STATUS_PENDING, TenantSubscription::STATUS_FAILED], true)) {
            return back()->with('error', __('central.SubPanelChangePending'));
        }

        $plan = Plan::findOrFail($validated['plan_id']);
        $cycle = $validated['billing_cycle'];
        $amount = $validated['amount'] !== null && $validated['amount'] !== ''
            ? (float) $validated['amount']
            : (float) $plan->getPriceForCycle($cycle);

        $before = [
            'plan_id'       => $subscription->plan_id,
            'plan'          => $subscription->plan?->name,
            'billing_cycle' => $subscription->billing_cycle,
            'amount'        => (string) $subscription->amount,
            'status'        => $subscription->status,
            'ends_at'       => $subscription->ends_at?->toDateTimeString(),
        ];

        $attributes = $subscription->switchPlan($plan, $cycle, $amount, $validated['period'] === 'restart');
        $subscription->load('plan');

        if (($attributes['status'] ?? null) === TenantSubscription::STATUS_ACTIVE) {
            Tenant::liftNonPaymentSuspension($tenant->id);
        }

        SubscriptionEvent::record($subscription, SubscriptionEvent::ACTION_PLAN_CHANGED, [
            'before' => $before,
            'after'  => [
                'plan_id'       => $subscription->plan_id,
                'plan'          => $subscription->plan?->name,
                'billing_cycle' => $subscription->billing_cycle,
                'amount'        => (string) $subscription->amount,
                'status'        => $subscription->status,
                'ends_at'       => $subscription->ends_at?->toDateTimeString(),
            ],
            'period' => $validated['period'],
        ], $validated['note'] ?? null);

        return back()->with('success', __('central.SubPanelPlanChanged', ['plan' => $plan->name]));
    }
}
