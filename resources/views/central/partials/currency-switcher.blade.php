{{-- Multi-currency pricing: lets a visitor see plan prices in another currency.
     Rendered only when the feature is on and more than one currency is offered
     ($currencySwitcher from CurrencySwitcher::data()). Keeps the other query
     parameters (plan, ref, …) and jumps back to the pricing section. --}}
@if(! empty($currencySwitcher))
    @php $switcherAnchor = $anchor ?? '#pricing'; @endphp
    <form method="GET" action="{{ url()->current() }}{{ $switcherAnchor }}" class="currency-switcher" role="search">
        @foreach(request()->except(['currency']) as $qk => $qv)
            @if(is_scalar($qv))
                <input type="hidden" name="{{ $qk }}" value="{{ $qv }}">
            @endif
        @endforeach
        <label class="currency-switcher__label" for="currencySwitcherSelect">
            <i class="bi bi-currency-exchange" aria-hidden="true"></i>
            <span>{{ __('landing.currency') }}</span>
        </label>
        <select id="currencySwitcherSelect" name="currency" class="currency-switcher__select" onchange="this.form.submit()">
            @foreach($currencySwitcher['options'] as $opt)
                <option value="{{ $opt['code'] }}" @selected($opt['code'] === $currencySwitcher['current'])>{{ $opt['label'] }}</option>
            @endforeach
        </select>
        <noscript><button type="submit">{{ __('landing.currency_apply') }}</button></noscript>
    </form>
    @once
        <style>
            .currency-switcher { display: inline-flex; align-items: center; gap: 8px; margin: 0 auto 24px; padding: 6px 8px 6px 12px;
                border: 1px solid rgba(127, 127, 127, 0.3); border-radius: 999px; font-size: 14px; background: rgba(255, 255, 255, 0.06); }
            .currency-switcher__label { display: inline-flex; align-items: center; gap: 6px; margin: 0; opacity: 0.8; }
            .currency-switcher__select { border: 0; background: transparent; font: inherit; color: inherit; cursor: pointer; padding: 2px 4px; }
            .currency-switcher__select option { color: #111; }
            .currency-switcher-wrap { display: flex; justify-content: center; }
        </style>
    @endonce
@endif
