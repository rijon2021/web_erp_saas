<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::withCount('tenantSubscriptions')->orderBy('price')->paginate(20);

        return view('central.super.plans.index', compact('plans'));
    }

    public function create(): View
    {
        $availableLimits = Plan::availableLimits();
        $availableFeatures = Plan::availableFeatures();

        return view('central.super.plans.create', compact('availableLimits', 'availableFeatures'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'unique:plans,slug'],
            'price'            => ['required', 'numeric', 'min:0'],
            'yearly_price'     => ['required', 'numeric', 'min:0'],
            'is_active'        => ['boolean'],
            'is_private'       => ['boolean'],
            'is_trial'         => ['boolean'],
            'trial_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'limits'           => ['nullable', 'array'],
            'limits.*'         => ['nullable', 'integer', 'min:-1'],
            'features'         => ['nullable', 'array'],
            'features.*'       => ['string'],
            'retention'        => ['nullable', 'array'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['is_private'] = $request->boolean('is_private');
        $validated['is_trial'] = $request->boolean('is_trial');
        $validated['trial_days'] = $validated['is_trial'] ? (int) ($validated['trial_days'] ?? 14) : 0;
        $validated['limits'] = $this->sanitizeLimits($request->input('limits', []));
        $validated['features'] = $request->input('features', []);
        $this->applyRetention($validated, $request);

        $plan = Plan::create($validated);
        $this->saveCurrencyPrices($plan, $request);

        return redirect()->route('super.plans.index')->with('success', __('super.plans.created'));
    }

    public function edit(Plan $plan): View
    {
        $plan->loadCount('tenantSubscriptions');
        $availableLimits = Plan::availableLimits();
        $availableFeatures = Plan::availableFeatures();

        return view('central.super.plans.edit', compact('plan', 'availableLimits', 'availableFeatures'));
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'slug'             => ['required', 'string', 'max:255', 'unique:plans,slug,' . $plan->id],
            'price'            => ['required', 'numeric', 'min:0'],
            'yearly_price'     => ['required', 'numeric', 'min:0'],
            'is_active'        => ['boolean'],
            'is_private'       => ['boolean'],
            'is_trial'         => ['boolean'],
            'trial_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'limits'           => ['nullable', 'array'],
            'limits.*'         => ['nullable', 'integer', 'min:-1'],
            'features'         => ['nullable', 'array'],
            'features.*'       => ['string'],
            'retention'        => ['nullable', 'array'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['is_private'] = $request->boolean('is_private');
        $validated['is_trial'] = $request->boolean('is_trial');
        $validated['trial_days'] = $validated['is_trial'] ? (int) ($validated['trial_days'] ?? 14) : 0;
        $validated['limits'] = $this->sanitizeLimits($request->input('limits', []));
        $validated['features'] = $request->input('features', []);
        $this->applyRetention($validated, $request);

        $plan->update($validated);
        $this->saveCurrencyPrices($plan, $request);

        return redirect()->route('super.plans.index')->with('success', __('super.plans.updated'));
    }

    /**
     * Multi-currency pricing: the plan's price in each extra selling currency
     * (currency_prices[CODE][mode|monthly|yearly]). "auto" = converted from the
     * main price at the live rate; "fixed" = the typed prices. Only while the
     * feature is on and migrated; currencies not posted are left untouched.
     */
    private function saveCurrencyPrices(Plan $plan, Request $request): void
    {
        if (! \App\Support\SellingCurrencies::enabled() || ! is_array($request->input('currency_prices'))) {
            return;
        }

        $request->validate([
            'currency_prices'           => ['array'],
            'currency_prices.*.mode'    => ['required', \Illuminate\Validation\Rule::in(['auto', 'fixed'])],
            'currency_prices.*.monthly' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'currency_prices.*.yearly'  => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $main = \App\Support\SellingCurrencies::main();
        $offered = \App\Support\SellingCurrencies::active();
        $table = \Illuminate\Support\Facades\DB::connection('central')->table('plan_prices');

        foreach ($request->input('currency_prices', []) as $code => $row) {
            $code = strtoupper((string) $code);
            if ($code === $main || ! in_array($code, $offered, true)) {
                continue;
            }
            $monthly = ($row['monthly'] ?? '') === '' ? null : round((float) $row['monthly'], 2);
            $yearly = ($row['yearly'] ?? '') === '' ? null : round((float) $row['yearly'], 2);
            // "Fixed" without a monthly price means nothing to fix — keep it automatic.
            $mode = ($row['mode'] ?? 'auto') === 'fixed' && $monthly !== null ? 'fixed' : 'auto';

            $table->clone()->updateOrInsert(
                ['plan_id' => $plan->id, 'currency' => $code],
                [
                    'mode'          => $mode,
                    'monthly_price' => $mode === 'fixed' ? $monthly : null,
                    'yearly_price'  => $mode === 'fixed' ? $yearly : null,
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );
        }

        \App\Services\Billing\PlanPricing::forgetCache();
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->tenantSubscriptions()->exists()) {
            return back()->withErrors(['plan' => __('super.plans.cannot_delete')]);
        }

        $plan->delete();

        return redirect()->route('super.plans.index')->with('success', __('super.plans.deleted'));
    }

    /**
     * Backup & retention policy (Plan::RETENTION_FIELDS), normalised. Skipped
     * until the central migration adding plans.retention has run, so the form
     * keeps saving on a database that is one migration behind.
     */
    private function applyRetention(array &$validated, Request $request): void
    {
        if (Schema::connection('central')->hasColumn('plans', 'retention')) {
            $validated['retention'] = Plan::sanitizeRetention((array) $request->input('retention', []));
        } else {
            unset($validated['retention']);
        }
    }

    /**
     * Strip out empty/null limit values, keep only valid entries.
     */
    private function sanitizeLimits(array $limits): array
    {
        $clean = [];

        foreach (array_keys(Plan::AVAILABLE_LIMITS) as $key) {
            if (isset($limits[$key]) && $limits[$key] !== '' && $limits[$key] !== null) {
                $value = (int) $limits[$key];
                if ($value !== 0) {
                    $clean[$key] = $value;
                }
            }
        }

        return $clean;
    }
}
