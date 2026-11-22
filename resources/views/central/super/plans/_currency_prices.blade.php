{{-- Multi-currency pricing: this plan's price in each extra selling currency.
     Shown only while the feature is on and extra currencies exist.
     "Auto" = the main price converted at today's rate and rounded;
     "Fixed" = the prices typed here. $plan is null on the create page. --}}
@php
    $mcCodes = \App\Support\SellingCurrencies::enabled()
        ? array_values(array_diff(\App\Support\SellingCurrencies::active(), [\App\Support\SellingCurrencies::main()]))
        : [];
    $mcRows = $plan ? \App\Services\Billing\PlanPricing::rowsFor($plan) : [];
@endphp
@if($mcCodes)
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-currency-exchange me-2 text-muted"></i>{{ __('super.currencies.plan_prices_title') }}</h2>
        <a href="{{ route('super.settings.currencies') }}" class="text-muted fs-sm">{{ __('super.currencies.manage') }}</a>
    </div>
    <div class="card-body-custom">
        <p class="form-hint mb-3">{{ __('super.currencies.plan_prices_hint', ['main' => \App\Support\SellingCurrencies::main()]) }}</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('super.currencies.col_currency') }}</th>
                        <th>{{ __('super.currencies.col_mode') }}</th>
                        <th>{{ __('super.plans.monthly_price') }}</th>
                        <th>{{ __('super.plans.yearly_price') }}</th>
                        <th>{{ __('super.currencies.col_auto_now') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mcCodes as $code)
                        @php
                            $r = $mcRows[$code] ?? null;
                            $mode = old('currency_prices.' . $code . '.mode', $r->mode ?? 'auto');
                            $autoMonthly = $autoYearly = null;
                            if ($plan) {
                                try {
                                    $autoMonthly = \App\Support\SellingCurrencies::roundAuto((float) $plan->price * \App\Services\Billing\PlanPricing::rate(\App\Support\SellingCurrencies::main(), $code), $code);
                                    $autoYearly = \App\Support\SellingCurrencies::roundAuto((float) $plan->getPriceForCycle('yearly') * \App\Services\Billing\PlanPricing::rate(\App\Support\SellingCurrencies::main(), $code), $code);
                                } catch (\Throwable $e) {
                                    $autoMonthly = $autoYearly = null;
                                }
                            }
                        @endphp
                        <tr>
                            <td><strong>{{ $code }}</strong> <span class="text-muted">{{ \App\Support\SellingCurrencies::symbol($code) }}</span></td>
                            <td style="min-width: 120px">
                                <select name="currency_prices[{{ $code }}][mode]" class="form-select form-select-sm">
                                    <option value="auto" @selected($mode === 'auto')>{{ __('super.currencies.mode_auto') }}</option>
                                    <option value="fixed" @selected($mode === 'fixed')>{{ __('super.currencies.mode_fixed') }}</option>
                                </select>
                            </td>
                            <td style="min-width: 120px">
                                <input type="number" step="0.01" min="0" name="currency_prices[{{ $code }}][monthly]" class="form-control form-control-sm"
                                       value="{{ old('currency_prices.' . $code . '.monthly', $r->monthly_price ?? '') }}" placeholder="{{ $autoMonthly !== null ? $autoMonthly : '—' }}">
                            </td>
                            <td style="min-width: 120px">
                                <input type="number" step="0.01" min="0" name="currency_prices[{{ $code }}][yearly]" class="form-control form-control-sm"
                                       value="{{ old('currency_prices.' . $code . '.yearly', $r->yearly_price ?? '') }}" placeholder="{{ $autoYearly !== null ? $autoYearly : '—' }}">
                            </td>
                            <td class="text-muted fs-sm2">
                                @if($autoMonthly !== null)
                                    {{ \App\Support\SellingCurrencies::format($autoMonthly, $code) }} / {{ \App\Support\SellingCurrencies::format($autoYearly, $code) }}
                                @elseif($plan)
                                    {{ __('super.currencies.no_rate') }}
                                @else
                                    {{ __('super.currencies.auto_after_save') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
