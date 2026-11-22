<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Services\Billing\PlanPricing;
use App\Support\IsoCurrencies;
use App\Support\PlatformFeatures;
use App\Support\SellingCurrencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Super admin → Settings → Features (optional platform features on/off) and
 * the multi-currency configuration page (Settings → Currencies).
 */
class PlatformFeaturesController extends Controller
{
    /** PUT settings/features — save every switch. */
    public function update(Request $request): RedirectResponse
    {
        $redirect = redirect()->to(route('super.settings.general') . '#tab=features');

        if (! PlatformFeatures::tableReady()) {
            return $redirect->with('error', __('super.platform_features.migration_needed'));
        }

        $data = $request->validate([
            'features'   => ['array'],
            'features.*' => ['nullable', 'boolean'],
        ]);

        foreach (array_keys(PlatformFeatures::FEATURES) as $key) {
            if (array_key_exists($key, $data['features'] ?? [])) {
                PlatformFeatures::set($key, (bool) $data['features'][$key], null, auth('central')->id());
            }
        }

        return $redirect->with('success', __('super.platform_features.saved'));
    }

    /**
     * POST tenants/{tenant}/billing-currency — the currency a workspace is
     * billed in from its next payment on (renewals, upgrades, add-ons).
     * Payments already made keep their own currency.
     */
    public function setTenantCurrency(Request $request, \App\Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'billing_currency' => ['required', 'string', 'size:3', IsoCurrencies::rule()],
        ]);
        $code = strtoupper($data['billing_currency']);

        // Only a currency on offer (or the one it already has).
        if (! SellingCurrencies::isOffered($code) && $code !== PlanPricing::tenantCurrency($tenant)) {
            return back()->with('error', __('super.currencies.not_offered', ['code' => $code]));
        }

        // The main currency = no override (the workspace follows the platform currency).
        $tenant->billing_currency = $code === SellingCurrencies::main() ? null : $code;
        $tenant->save();

        return back()->with('success', __('super.currencies.tenant_saved', ['code' => $code]));
    }

    /** GET settings/currencies — selling currencies + gateway coverage. */
    public function currencies(): View
    {
        $ready = SellingCurrencies::tableReady();
        $main = SellingCurrencies::main();
        $rows = collect(SellingCurrencies::rows())->except([$main]);

        $coverage = [$main => SellingCurrencies::gatewaysFor($main)];
        foreach ($rows as $code => $row) {
            $coverage[$code] = SellingCurrencies::gatewaysFor($code);
        }

        // Rates shown next to each currency, to help pick fixed prices.
        $rates = [];
        foreach ($rows->keys() as $code) {
            try {
                $rates[$code] = PlanPricing::rate($main, $code);
            } catch (\Throwable) {
                $rates[$code] = null;
            }
        }

        return view('central.super.settings.currencies', [
            'ready'      => $ready,
            'enabled'    => PlatformFeatures::enabled(PlatformFeatures::MULTI_CURRENCY),
            'main'       => $main,
            'rows'       => $rows,
            'coverage'   => $coverage,
            'rates'      => $rates,
            'available'  => array_values(array_diff(IsoCurrencies::codes(), array_merge([$main], $rows->keys()->all()))),
            'autoDetect' => (bool) PlatformFeatures::setting(PlatformFeatures::MULTI_CURRENCY, 'auto_detect', false),
            'roundings'  => SellingCurrencies::ROUNDINGS,
        ]);
    }

    /** PUT settings/currencies — save rows, add one, remove ticked ones, auto-detect. */
    public function updateCurrencies(Request $request): RedirectResponse
    {
        $back = redirect()->route('super.settings.currencies');
        if (! SellingCurrencies::tableReady()) {
            return $back->with('error', __('super.currencies.migration_needed'));
        }

        $main = SellingCurrencies::main();
        $data = $request->validate([
            'rows'              => ['array'],
            'rows.*.symbol'     => ['nullable', 'string', 'max:12'],
            'rows.*.rounding'   => ['nullable', Rule::in(SellingCurrencies::ROUNDINGS)],
            'rows.*.is_active'  => ['nullable', 'boolean'],
            'rows.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'rows.*.remove'     => ['nullable', 'boolean'],
            'add'               => ['nullable', 'string', 'size:3', IsoCurrencies::rule()],
            'auto_detect'       => ['nullable', 'boolean'],
        ]);

        $table = DB::connection('central')->table('platform_currencies');
        foreach ($data['rows'] ?? [] as $code => $row) {
            $code = strtoupper((string) $code);
            if ($code === $main) {
                continue;
            }
            if (! empty($row['remove'])) {
                // Its fixed plan prices go too; tenants already billed in it keep it.
                $table->clone()->where('code', $code)->delete();
                DB::connection('central')->table('plan_prices')->where('currency', $code)->delete();
                continue;
            }
            $table->clone()->where('code', $code)->update([
                'symbol'     => trim((string) ($row['symbol'] ?? '')) ?: null,
                'rounding'   => $row['rounding'] ?? 'whole',
                'is_active'  => ! empty($row['is_active']),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'updated_at' => now(),
            ]);
        }

        $add = strtoupper((string) ($data['add'] ?? ''));
        if ($add !== '' && $add !== $main && ! $table->clone()->where('code', $add)->exists()) {
            $table->clone()->insert([
                'code'       => $add,
                'symbol'     => null,
                'rounding'   => SellingCurrencies::decimals($add) === 0 ? '10' : 'whole',
                'is_active'  => true,
                'sort_order' => (int) $table->clone()->max('sort_order') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        PlatformFeatures::set(
            PlatformFeatures::MULTI_CURRENCY,
            PlatformFeatures::enabled(PlatformFeatures::MULTI_CURRENCY),
            ['auto_detect' => (bool) ($data['auto_detect'] ?? false)],
            auth('central')->id()
        );
        PlanPricing::forgetCache();

        return $back->with('success', __('super.currencies.saved'));
    }
}
