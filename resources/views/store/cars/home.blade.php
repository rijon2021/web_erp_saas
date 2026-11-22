@extends('layouts.store')

@section('content')
@php
  $cdOpts = $opts;
  $hidePricesCd = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
  $heroTitle = $opts['hero_title'];
  $heroSub = $opts['hero_subtitle'];
  $bodyCounts = collect($facets['body'] ?? [])->filter(fn ($c) => $c > 0);
  $mapQuery = trim((string) $s->contact_address);
  $termsCd = array_values(array_filter(\App\Support\CarsTheme::TERMS, fn ($t) => $t >= 24 && $t <= 84));
  $calcPrice = $featured->isNotEmpty() ? (float) $featured->avg(fn ($p) => (float) $p->display_price) : 20000;
  $calcMax = max(1000, (int) (count($priceSteps) ? end($priceSteps) : $calcPrice * 3));
@endphp

{{-- ===== Hero (owner slides; copy-only when none) ===== --}}
<section class="cd-hero {{ $slides->isEmpty() ? 'cd-hero-plain' : '' }}"
  @if($slides->count() > 1) x-data="{ i: 0, n: {{ $slides->count() }}, t: null, go(k) { this.i = (k + this.n) % this.n; this.restart(); }, restart() { clearInterval(this.t); this.t = setInterval(() => { this.i = (this.i + 1) % this.n; }, 8000); } }" x-init="restart()" @endif>
  <div class="cd-container">
    @forelse($slides as $k => $slide)
      <div class="cd-hero-grid" @if($slides->count() > 1) x-show="i === {{ $k }}" @if($k > 0) x-cloak @endif @endif>
        <div class="cd-hero-copy">
          @if(!empty($slide['kicker']))<span class="cd-kicker">{{ $slide['kicker'] }}</span>@endif
          @if($k === 0)<h1 class="cd-hero-title">{{ $slide['title'] ?: $heroTitle }}</h1>@else<h2 class="cd-hero-title">{{ $slide['title'] ?: $heroTitle }}</h2>@endif
          @if(!empty($slide['subtitle']) || $k === 0)<p class="cd-hero-sub">{{ $slide['subtitle'] ?: $heroSub }}</p>@endif
          <div class="cd-hero-actions">
            <a href="{{ !empty($slide['primary_text']) ? $slide['primary_href'] : route('store.shop') }}" class="cd-btn">{{ $slide['primary_text'] ?: __('messages.CarBrowseInventory') }}</a>
            @if(!empty($slide['secondary_text']))<a href="{{ $slide['secondary_href'] }}" class="cd-btn cd-btn-on-dark">{{ $slide['secondary_text'] }}</a>
            @elseif($k === 0 && $sections['trade_in'] && \App\Models\VehicleSpec::tablesReady())<a href="#trade-in" class="cd-btn cd-btn-on-dark">{{ __('messages.CarValueTradeIn') }}</a>@endif
          </div>
          @if($slides->count() > 1)
            <div class="cd-hero-dots" role="group" aria-label="{{ __('messages.CarSlides') }}">
              @foreach($slides as $j => $_)<button type="button" :class="{ 'is-active': i === {{ $j }} }" @click="go({{ $j }})" aria-label="{{ __('messages.CarSlideN', ['n' => $j + 1]) }}"></button>@endforeach
            </div>
          @endif
        </div>
        @if(!empty($slide['image_url']))
          <div class="cd-hero-media"><img src="{{ $slide['image_url'] }}" alt="" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $k === 0 ? 'high' : 'auto' }}"></div>
        @endif
      </div>
    @empty
      <div class="cd-hero-grid">
        <div class="cd-hero-copy">
          <h1 class="cd-hero-title">{{ $heroTitle }}</h1>
          <p class="cd-hero-sub">{{ $heroSub }}</p>
          <div class="cd-hero-actions">
            <a href="{{ route('store.shop') }}" class="cd-btn">{{ __('messages.CarBrowseInventory') }}</a>
            @if($sections['trade_in'] && \App\Models\VehicleSpec::tablesReady())<a href="#trade-in" class="cd-btn cd-btn-on-dark">{{ __('messages.CarValueTradeIn') }}</a>@endif
          </div>
        </div>
      </div>
    @endforelse
  </div>
</section>

{{-- ===== Search panel ===== --}}
<div class="cd-container cd-search-wrap">
  @if($sections['search'])
    <form class="cd-search" action="{{ route('store.shop') }}" method="GET"
          x-data="{ make: '', models: @js($makeModels), get modelList() { return this.models[this.make] || []; } }">
      <div class="cd-seg" role="radiogroup" aria-label="{{ __('messages.CarCondition') }}">
        <label><input type="radio" name="condition" value="" checked>{{ __('messages.CarAllCars') }}</label>
        <label><input type="radio" name="condition" value="new">{{ __('messages.CarCondition_new') }}</label>
        <label><input type="radio" name="condition" value="used">{{ __('messages.CarCondition_used') }}</label>
      </div>
      <div class="cd-search-fields">
        <label class="cd-field">{{ __('messages.CarMake') }}
          <select name="make" x-model="make">
            <option value="">{{ __('messages.CarAnyMake') }}</option>
            @foreach($facets['makes'] as $m)<option value="{{ $m['name'] }}">{{ $m['name'] }} ({{ $m['count'] }})</option>@endforeach
          </select>
        </label>
        <label class="cd-field">{{ __('messages.CarModel') }}
          <select name="model" :disabled="!modelList.length">
            <option value="">{{ __('messages.CarAnyModel') }}</option>
            <template x-for="m in modelList" :key="m"><option :value="m" x-text="m"></option></template>
          </select>
        </label>
        @if(!$hidePricesCd && count($priceSteps))
          <label class="cd-field">{{ __('messages.CarMaxPrice') }}
            <select name="max">
              <option value="">{{ __('messages.CarNoMax') }}</option>
              @foreach($priceSteps as $step)<option value="{{ $step }}">{{ store_money($step) }}</option>@endforeach
            </select>
          </label>
        @endif
        <label class="cd-field">{{ __('messages.CarBodyType') }}
          <select name="body[]">
            <option value="">{{ __('messages.CarAnyBody') }}</option>
            @foreach(\App\Models\VehicleSpec::BODY_TYPES as $b)@if(($facets['body'][$b] ?? 0) > 0)<option value="{{ $b }}">{{ __('messages.CarBody_'.$b) }}</option>@endif @endforeach
          </select>
        </label>
        <button type="submit" class="cd-btn"><x-store.icon name="search" />{{ __('messages.CarSearchCars') }}</button>
      </div>
    </form>
  @endif

  {{-- ===== Body types ===== --}}
  @if($sections['body_types'] && $bodyCounts->count() > 1)
    <section class="cd-section">
      <div class="cd-head">
        <h2 class="cd-h2">{{ __('messages.CarBrowseByBody') }}</h2>
        <a href="{{ route('store.shop') }}" class="cd-link">{{ __('messages.CarAllInventory') }} →</a>
      </div>
      <div class="cd-tiles">
        @foreach(\App\Models\VehicleSpec::BODY_TYPES as $b)
          @if(($bodyCounts[$b] ?? 0) > 0)
            <a href="{{ route('store.shop', ['body' => [$b]]) }}" class="cd-tile">
              @include('store.cars.partials.body-icon', ['type' => $b])
              <span>{{ __('messages.CarBody_'.$b) }}</span>
              <small>{{ trans_choice('messages.CarCount', $bodyCounts[$b], ['count' => $bodyCounts[$b]]) }}</small>
            </a>
          @endif
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Featured ===== --}}
  @if($sections['featured'] && $featured->isNotEmpty())
    <section class="cd-section">
      <div class="cd-head">
        <h2 class="cd-h2">{{ $opts['featured_title'] }}</h2>
        <a href="{{ route('store.shop') }}" class="cd-link">{{ __('messages.ViewAll') }} →</a>
      </div>
      <div class="cd-grid">
        @foreach($featured as $p)@include('store.cars.partials.card', ['p' => $p])@endforeach
      </div>
      @if(!empty($opts['show_monthly_on_cards']) && !$hidePricesCd)
        <p class="cd-note">{{ __('messages.CarRepExample', ['deposit' => $opts['deposit_percent'], 'term' => $opts['default_term'], 'apr' => rtrim(rtrim(number_format($opts['apr'], 2), '0'), '.')]) }} {{ $opts['finance_disclaimer'] }}</p>
      @endif
    </section>
  @endif

  {{-- ===== Shop by make ===== --}}
  @if($sections['makes'] && $makes->count() > 1)
    <section class="cd-section">
      <div class="cd-head"><h2 class="cd-h2">{{ __('messages.CarShopByMake') }}</h2></div>
      <div class="cd-makes">
        @foreach($makes as $m)
          <a href="{{ route('store.shop', ['make' => $m['name']]) }}" class="cd-make">{{ $m['name'] }} <small>{{ $m['count'] }}</small></a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Finance ===== --}}
  @if($sections['finance'] && !$hidePricesCd)
    <section class="cd-section" id="finance">
      <div class="cd-panel cd-split">
        <div style="display: flex; flex-direction: column; gap: 14px">
          <span class="cd-kicker">{{ __('messages.CarFinance') }}</span>
          <h2 class="cd-h2">{{ $opts['finance_title'] }}</h2>
          <p class="cd-muted" style="margin: 0; line-height: 1.6">{{ $opts['finance_text'] }}</p>
          <ol class="cd-steps">
            <li><b>1</b>{{ __('messages.CarFinanceStep1') }}</li>
            <li><b>2</b>{{ __('messages.CarFinanceStep2') }}</li>
            <li><b>3</b>{{ __('messages.CarFinanceStep3') }}</li>
          </ol>
        </div>
        <div class="cd-calc" x-data="{ price: {{ (int) round($calcPrice) }}, dep: {{ (int) $opts['deposit_percent'] }}, term: {{ (int) $opts['default_term'] }}, apr: {{ (float) $opts['apr'] }},
              get monthly() { const P = Math.max(0, this.price * (1 - this.dep / 100)); const r = this.apr / 1200; if (!P) return 0; return r > 0 ? P * r / (1 - Math.pow(1 + r, -this.term)) : P / this.term; },
              money(v) { return window.fmtMoney ? window.fmtMoney(v) : Math.round(v); } }">
          <label class="cd-calc-row" for="cdCalcPrice"><span>{{ __('messages.CarCarPrice') }}</span><strong x-text="money(price)"></strong></label>
          <input id="cdCalcPrice" type="range" min="1000" max="{{ $calcMax }}" step="500" x-model.number="price">
          <label class="cd-calc-row" for="cdCalcDep"><span>{{ __('messages.CarDeposit') }}</span><strong x-text="dep + '% · ' + money(price * dep / 100)"></strong></label>
          <input id="cdCalcDep" type="range" min="0" max="50" step="5" x-model.number="dep">
          <div class="cd-terms" role="group" aria-label="{{ __('messages.CarTerm') }}">
            @foreach([36, 48, 60, 72] as $t)<button type="button" :class="{ 'is-active': term === {{ $t }} }" :aria-pressed="(term === {{ $t }}).toString()" @click="term = {{ $t }}">{{ __('messages.CarMonthsShort', ['n' => $t]) }}</button>@endforeach
          </div>
          <div class="cd-calc-total">
            <span class="cd-muted">{{ __('messages.CarEstimatedMonthly') }}</span>
            <span class="cd-price" x-text="money(monthly)"></span>
          </div>
          <p class="cd-note" style="margin: 0">{{ __('messages.CarAprExample', ['apr' => rtrim(rtrim(number_format($opts['apr'], 2), '0'), '.')]) }} {{ $opts['finance_disclaimer'] }}</p>
        </div>
      </div>
    </section>
  @endif

  {{-- ===== Trade-in ===== --}}
  @if($sections['trade_in'] && \App\Models\VehicleSpec::tablesReady())
    <section class="cd-section" id="trade-in">
      <div class="cd-dark-panel cd-split" x-data="cdLead({ type: 'trade_in' })">
        <div style="display: flex; flex-direction: column; gap: 10px">
          <h2 class="cd-h2">{{ $opts['trade_title'] }}</h2>
          <p style="margin: 0; line-height: 1.6">{{ $opts['trade_text'] }}</p>
        </div>
        <form class="cd-form" @submit.prevent="submit($el)" novalidate>
          <div class="cd-field-row">
            <label class="cd-field">{{ __('messages.CarPlate') }}<input name="trade_plate" required maxlength="40" placeholder="{{ __('messages.CarPlateExample') }}"></label>
            <label class="cd-field">{{ __('messages.CarMileageUnit', ['unit' => $opts['mileage_unit'] === 'mi' ? __('messages.CarMilesShort') : __('messages.CarKmShort')]) }}<input name="trade_mileage" type="number" min="0" inputmode="numeric"></label>
          </div>
          <label class="cd-field">{{ __('messages.CarYourCar') }}<input name="trade_vehicle" maxlength="190" placeholder="{{ __('messages.CarYourCarExample') }}"></label>
          <div class="cd-field-row">
            <label class="cd-field">{{ __('messages.Name') }}<input name="name" required maxlength="150" autocomplete="name"></label>
            <label class="cd-field">{{ __('messages.Phone') }}<input name="phone" type="tel" required maxlength="40" autocomplete="tel"></label>
          </div>
          <input type="text" name="website" class="cd-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <button type="submit" class="cd-btn" :disabled="sending">{{ __('messages.CarGetValuation') }}</button>
          <div x-show="ok" x-cloak class="cd-alert is-ok" role="status" x-text="ok"></div>
          <div x-show="err" x-cloak class="cd-alert is-err" role="alert" x-text="err"></div>
        </form>
      </div>
    </section>
  @endif

  {{-- ===== Latest arrivals ===== --}}
  @if($sections['latest'] && $latest->isNotEmpty())
    <section class="cd-section">
      <div class="cd-head">
        <h2 class="cd-h2">{{ $opts['latest_title'] }}</h2>
        <a href="{{ route('store.shop', ['sort' => 'latest']) }}" class="cd-link">{{ __('messages.ViewAll') }} →</a>
      </div>
      <div class="cd-grid">
        @foreach($latest as $p)@include('store.cars.partials.card', ['p' => $p])@endforeach
      </div>
    </section>
  @endif

  {{-- ===== Trust ===== --}}
  @if($sections['trust'] && count($opts['trust']))
    <section class="cd-section">
      <div class="cd-trust">
        @foreach($opts['trust'] as $item)
          <div class="cd-trust-item"><span><x-store.icon :name="$item['icon'] ?: 'check-circle'" fallback="check-circle" /></span><div><strong>{{ $item['title'] }}</strong>@if(!empty($item['subtitle']))<small>{{ $item['subtitle'] }}</small>@endif</div></div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Visit the showroom ===== --}}
  @if($sections['visit'] && ($mapQuery !== '' || $opts['opening_hours'] !== '' || $s->contact_phone))
    <section class="cd-section" id="visit">
      <div class="cd-split">
        <div class="cd-panel" style="display: flex; flex-direction: column; gap: 16px">
          <h2 class="cd-h2">{{ __('messages.CarVisitShowroom') }}</h2>
          @if($mapQuery !== '')<div><div class="cd-muted" style="font-size: 13px; font-weight: 600">{{ __('messages.Address') }}</div><div>{{ $mapQuery }}</div></div>@endif
          @if($opts['opening_hours'] !== '')<div><div class="cd-muted" style="font-size: 13px; font-weight: 600">{{ __('messages.CarOpeningHours') }}</div><div class="cd-hours">{{ $opts['opening_hours'] }}</div></div>@endif
          <div style="display: flex; gap: 10px; flex-wrap: wrap">
            @if($mapQuery !== '')<a class="cd-btn cd-btn-dark" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($mapQuery) }}" target="_blank" rel="noopener">{{ __('messages.CarGetDirections') }}</a>@endif
            @if($opts['whatsapp'] !== '')<a class="cd-btn cd-btn-ghost" href="https://wa.me/{{ $opts['whatsapp'] }}" target="_blank" rel="noopener"><x-store.icon name="whatsapp" />WhatsApp</a>
            @elseif($s->contact_phone)<a class="cd-btn cd-btn-ghost" href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>@endif
          </div>
        </div>
        @if($mapQuery !== '')
          <div class="cd-map"><iframe title="{{ __('messages.CarMap') }}" src="https://maps.google.com/maps?q={{ urlencode($mapQuery) }}&output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>
        @endif
      </div>
    </section>
  @endif
</div>

@include('store.cars.partials.lead-script')
@endsection
