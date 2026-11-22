@extends('layouts.store')

@section('content')
@php
  $cdOpts = $opts;
  $p = $product;
  $hidePricesCd = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
  $images = collect($p->productGalleryFilenames())->map(fn ($f) => product_image_url_or_null($f))->filter()->values();
  if ($images->isEmpty()) { $images = collect([product_image_url($p->primaryProductImageFilename())]); }
  $title = $spec && $spec->headline() !== '' ? $spec->headline() : $p->name;
  $trimLine = $spec ? ($spec->trim ?: ($title !== $p->name ? $p->name : '')) : '';
  $price = (float) ($p->display_price ?? $p->price ?? 0);
  $compareAt = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
  $onSale = $compareAt !== null && $compareAt > $price + 0.001;
  $allowOverselling = (bool) ($s->allow_overselling ?? true) || (bool) ($p->always_in_stock ?? false) || (bool) ($p->is_preorder ?? false);
  $variantsCd = collect($p->relationLoaded('variants') ? $p->variants : []);
  $inStock = $allowOverselling || ($variantsCd->isEmpty() ? (int) ($p->stock ?? 0) > 0 : $variantsCd->contains(fn ($v) => (int) ($v->stock ?? 0) > 0));
  $sold = ($spec && $spec->is_sold) || ! $inStock;
  $mileage = $spec ? \App\Support\CarsTheme::mileage($spec->mileage, $opts) : null;
  $engine = $spec && ($spec->engine_size || $spec->power_hp)
    ? trim(($spec->engine_size ? number_format($spec->engine_size, 1).' L' : '').($spec->engine_size && $spec->power_hp ? ' · ' : '').($spec->power_hp ? $spec->power_hp.' '.__('messages.CarHp') : ''))
    : null;
  $label = fn ($prefix, $v) => $v ? __('messages.'.$prefix.$v) : null;
  $summary = $spec ? implode(' · ', array_filter([$mileage, $label('CarFuel_', $spec->fuel), $label('CarGear_', $spec->transmission)])) : '';
  $keySpecs = $spec ? array_filter([
      __('messages.CarYear') => $spec->year,
      __('messages.CarMileage') => $mileage,
      __('messages.CarFuel') => $label('CarFuel_', $spec->fuel),
      __('messages.CarGearbox') => $label('CarGear_', $spec->transmission),
      __('messages.CarEngine') => $engine,
      __('messages.CarBodyType') => $label('CarBody_', $spec->body_type),
      __('messages.CarColour') => $spec->exterior_color,
      __('messages.CarOwners') => $spec->owners !== null ? (string) $spec->owners : null,
  ], fn ($v) => $v !== null && $v !== '') : [];
  $vin = $spec && $spec->vin ? (mb_strlen($spec->vin) > 8 ? mb_substr($spec->vin, 0, 6).'…'.mb_substr($spec->vin, -4) : $spec->vin) : null;
  $specRows = $spec ? array_filter([
      __('messages.CarMake') => $spec->make,
      __('messages.CarModel') => $spec->model,
      __('messages.CarVariant') => $spec->trim,
      __('messages.CarCondition') => __('messages.CarCondition_'.$spec->condition),
      __('messages.CarYear') => $spec->year,
      __('messages.CarMileage') => $mileage,
      __('messages.CarBodyType') => $label('CarBody_', $spec->body_type),
      __('messages.CarFuel') => $label('CarFuel_', $spec->fuel),
      __('messages.CarGearbox') => $label('CarGear_', $spec->transmission),
      __('messages.CarDrivetrain') => $label('CarDrive_', $spec->drivetrain),
      __('messages.CarEngine') => $engine,
      __('messages.CarExterior') => $spec->exterior_color,
      __('messages.CarInterior') => $spec->interior,
      __('messages.CarDoorsSeats') => ($spec->doors || $spec->seats) ? trim(($spec->doors ?: '—').' / '.($spec->seats ?: '—')) : null,
      __('messages.CarOwners') => $spec->owners !== null ? (string) $spec->owners : null,
      __('messages.CarEconomy') => $spec->fuel_economy,
      __('messages.CarServiceHistory') => $label('CarService_', $spec->service_history),
      __('messages.CarVin') => $vin,
      __('messages.CarStockNo') => $p->code,
  ], fn ($v) => $v !== null && $v !== '') : array_filter([__('messages.CarStockNo') => $p->code]);
  $features = $spec ? array_values($spec->features ?? []) : [];
  $pills = $spec ? array_filter([
      $spec->service_history ? __('messages.CarServiceHistory').': '.__('messages.CarService_'.$spec->service_history) : null,
      $spec->owners !== null ? trans_choice('messages.CarPreviousOwners', $spec->owners, ['count' => $spec->owners]) : null,
  ]) : [];
  $leadOk = \App\Models\VehicleSpec::tablesReady();
  $tabs = array_filter(['enquiry' => __('messages.CarEnquire'), 'test_drive' => $sold ? null : __('messages.CarTestDrive'), 'reservation' => $reserve && !$sold ? __('messages.CarReserve') : null]);
  $whatsText = __('messages.CarWhatsAppText', ['car' => trim($title.' '.$trimLine), 'url' => route('store.product.show', $p->id)]);
  $termsCd = [36, 48, 60, 72];
  if (!in_array((int) $opts['default_term'], $termsCd, true)) { $termsCd[] = (int) $opts['default_term']; sort($termsCd); }
  $depStep = max(50, (int) (round($price / 200 / 50) * 50));
  $depMax = max($depStep, (int) (floor($price * 0.6 / $depStep) * $depStep));
  $depStart = (int) (round($price * $opts['deposit_percent'] / 100 / $depStep) * $depStep);
  $canBuy = $opts['allow_online_purchase'] && !$sold && !$hidePricesCd && $price > 0;
  $variantPayload = $variantsCd->map(fn ($v) => ['id' => (int) $v->id, 'name' => (string) $v->name, 'price' => (float) ($v->price ?? 0), 'display_price' => (float) ($v->display_price ?? $v->price ?? 0), 'display_price_formatted' => store_money((float) ($v->display_price ?? $v->price ?? 0)), 'image' => product_image_url_or_null($v->image ?? null), 'stock' => (int) max(0, $v->stock ?? 0)])->values();
  $priceAttr = fn ($amount) => number_format((float) $amount, \App\utils\helpers::price_decimals(), '.', '');
  $reserveItem = $reserve ? ['id' => (string) $reserve['id'], 'name' => $reserve['name'], 'price' => $reserve['price'], 'image' => $reserve['image'], 'slug' => (string) $reserve['id']] : null;
@endphp

<div class="cd-container" style="padding-top: 24px">
  <nav class="cd-crumbs" aria-label="{{ __('messages.CarBreadcrumb') }}">
    <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span aria-hidden="true">/</span>
    <a href="{{ route('store.shop') }}">{{ __('messages.CarInventory') }}</a>
    @if($spec && $spec->make)<span aria-hidden="true">/</span><a href="{{ route('store.shop', ['make' => $spec->make]) }}">{{ $spec->make }}</a>@endif
    @if($spec && $spec->make && $spec->model)<span aria-hidden="true">/</span><a href="{{ route('store.shop', ['make' => $spec->make, 'model' => $spec->model]) }}">{{ $spec->model }}</a>@endif
  </nav>

  <div class="cd-pdp" style="margin-top: 16px">
    <div class="cd-pdp-main">
      {{-- Gallery --}}
      <div class="cd-pdp-gallery" x-data="{ i: 0, imgs: @js($images->all()), go(k) { this.i = (k + this.imgs.length) % this.imgs.length; } }" @keydown.left="go(i - 1)" @keydown.right="go(i + 1)">
        <div class="cd-gallery-main">
          <img :src="imgs[i]" src="{{ $images->first() }}" alt="{{ $title }}" fetchpriority="high" onerror="this.onerror=null;this.src='{{ product_image_url('') }}'">
          <div class="cd-badges">
            @if($sold)<span class="cd-badge is-sold">{{ __('messages.CarSold') }}</span>@endif
            @if($spec)<span class="cd-badge {{ $spec->condition === 'new' ? 'is-new' : ($spec->condition === 'certified' ? 'is-cert' : '') }}">{{ __('messages.CarCondition_'.$spec->condition) }}</span>@endif
            @if($onSale && !$hidePricesCd)<span class="cd-badge is-drop">{{ __('messages.CarPriceDrop') }}</span>@endif
          </div>
          @if($images->count() > 1)
            <button type="button" class="cd-gallery-nav is-prev" @click="go(i - 1)" aria-label="{{ __('messages.Previous') }}"><x-store.icon name="chevron-left" /></button>
            <button type="button" class="cd-gallery-nav is-next" @click="go(i + 1)" aria-label="{{ __('messages.Next') }}"><x-store.icon name="chevron-right" /></button>
            <span class="cd-photos cd-num"><x-store.icon name="camera" /><span x-text="(i + 1) + ' / ' + imgs.length">1 / {{ $images->count() }}</span></span>
          @endif
        </div>
        @if($images->count() > 1)
          <div class="cd-thumbs" style="margin-top: 10px">
            @foreach($images->take(6) as $k => $src)
              <button type="button" :class="{ 'is-active': i === {{ $k }} }" @click="i = {{ $k }}" aria-label="{{ __('messages.CarShowPhoto', ['n' => $k + 1]) }}">
                <img src="{{ $src }}" alt="" loading="lazy">
                @if($k === 5 && $images->count() > 6)<span class="cd-more">+{{ $images->count() - 6 }}</span>@endif
              </button>
            @endforeach
          </div>
        @endif
      </div>

      @if($keySpecs)
        <section class="cd-box">
          <h2>{{ __('messages.CarKeySpecs') }}</h2>
          <div class="cd-keyspecs">
            @foreach($keySpecs as $k => $v)<div><span>{{ $k }}</span><strong>{{ $v }}</strong></div>@endforeach
          </div>
        </section>
      @endif

      @if(count($specRows) > 1)
        <section class="cd-box">
          <h2>{{ __('messages.CarSpecification') }}</h2>
          <dl class="cd-spec-table">
            @foreach($specRows as $k => $v)<div><dt>{{ $k }}</dt><dd>{{ $v }}</dd></div>@endforeach
          </dl>
        </section>
      @endif

      @if($features)
        <section class="cd-box">
          <h2>{{ __('messages.CarFeatures') }}</h2>
          <ul class="cd-features">
            @foreach($features as $feat)<li><x-store.icon name="check" />{{ $feat }}</li>@endforeach
          </ul>
        </section>
      @endif

      @if($p->note || $pills)
        <section class="cd-box">
          <h2>{{ __('messages.CarAboutThisCar') }}</h2>
          @if($p->note)<p class="cd-desc">{!! nl2br(e($p->note)) !!}</p>@endif
          @if($pills)<div class="cd-pills">@foreach($pills as $pill)<span>{{ $pill }}</span>@endforeach</div>@endif
        </section>
      @endif
    </div>

    {{-- Price, finance and lead forms --}}
    <aside class="cd-pdp-aside"
           x-data="{ price: {{ (float) $price }}, deposit: {{ $depStart }}, term: {{ (int) $opts['default_term'] }}, apr: {{ (float) $opts['apr'] }},
                     get monthly() { const P = Math.max(0, this.price - this.deposit); const r = this.apr / 1200; if (!P) return 0; return r > 0 ? P * r / (1 - Math.pow(1 + r, -this.term)) : P / this.term; },
                     money(v) { return window.fmtMoney ? window.fmtMoney(v) : Math.round(v); } }">
      <div class="cd-buy">
        <div>
          <h1 class="cd-h1">{{ $title }}</h1>
          @if($trimLine !== '' || $summary !== '')<p class="cd-sub">{{ implode(' · ', array_filter([$trimLine, $summary])) }}</p>@endif
        </div>
        @if($sold)<div class="cd-sold" role="status">{{ __('messages.CarSoldNotice') }}</div>@endif
        @if(!$hidePricesCd)
          <div style="display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap">
            <span class="cd-price-lg">{{ store_money($price) }}</span>
            @if($onSale)<span class="cd-was" style="font-size: 16px">{{ store_money($compareAt) }}</span>@endif
          </div>
        @else
          <a href="{{ store_auth_url('login') }}" class="cd-link">{{ __('messages.SignIn') }}</a>
        @endif

        @if(!$hidePricesCd && !$sold && $price > 0)
          <div class="cd-calc">
            <div class="cd-calc-row"><strong>{{ __('messages.CarFinanceCalculator') }}</strong><span class="cd-muted cd-num">{{ __('messages.CarAprExample', ['apr' => rtrim(rtrim(number_format($opts['apr'], 2), '0'), '.')]) }}</span></div>
            <label class="cd-calc-row" for="cdDep"><span class="cd-muted">{{ __('messages.CarDeposit') }}</span><strong x-text="money(deposit)"></strong></label>
            <input id="cdDep" type="range" min="0" max="{{ $depMax }}" step="{{ $depStep }}" x-model.number="deposit">
            <div class="cd-terms" role="group" aria-label="{{ __('messages.CarTerm') }}">
              @foreach($termsCd as $t)<button type="button" :class="{ 'is-active': term === {{ $t }} }" :aria-pressed="(term === {{ $t }}).toString()" @click="term = {{ $t }}">{{ __('messages.CarMonthsShort', ['n' => $t]) }}</button>@endforeach
            </div>
            <div class="cd-calc-total">
              <div><div class="cd-muted" style="font-size: 14px">{{ __('messages.CarEstimatedMonthly') }}</div><div class="cd-muted cd-num" style="font-size: 12px">{{ __('messages.CarTotalPayable') }} <span x-text="money(monthly * term + deposit)"></span></div></div>
              <span class="cd-price" x-text="money(monthly)"></span>
            </div>
            @if($opts['finance_disclaimer'] !== '')<p class="cd-note" style="margin: 0">{{ $opts['finance_disclaimer'] }}</p>@endif
          </div>
        @endif

        @if($canBuy)
          <button type="button" class="cd-btn cd-btn-dark cd-btn-block js-add-to-cart"
                  data-out-of-stock="0" data-is-preorder="0" data-id="{{ $p->id }}" data-slug="{{ $p->id }}" data-name="{{ e($p->name) }}" data-price="{{ $priceAttr($price) }}"
                  data-image="{{ $images->first() }}" data-gallery='@json($images->all())' data-currency="{{ store_currency()['symbol'] }}" data-qty="1" data-product-id="{{ $p->id }}" data-product-image="{{ $images->first() }}"
                  data-variants='@json($variantPayload)' data-options='@json(store_variant_options($p))' data-stock="{{ $variantsCd->isEmpty() && !$allowOverselling ? (int) ($p->stock ?? 0) : '' }}" data-added-label="{{ __('messages.Added') }}">
            <x-store.icon name="cart" />{{ __('messages.CarBuyOnline') }}
          </button>
          <div class="js-add-status" aria-live="polite"></div>
        @endif

        @if($leadOk && $tabs)
          <div x-data="cdLead({ type: 'enquiry', reserve: @js($reserveItem) })">
            @if(count($tabs) > 1)
              <div class="cd-tabs" role="tablist" aria-label="{{ __('messages.CarContactDealer') }}" style="--cd-tabs: {{ count($tabs) }}; margin-bottom: 12px">
                @foreach($tabs as $key => $tabLabel)
                  <button type="button" role="tab" :class="{ 'is-active': type === '{{ $key }}' }" :aria-selected="(type === '{{ $key }}').toString()" @click="setType('{{ $key }}')">{{ $tabLabel }}</button>
                @endforeach
              </div>
            @endif
            <form class="cd-form" @submit.prevent="submit($el)" novalidate>
              <input type="hidden" name="product_id" value="{{ $p->id }}">
              <div class="cd-field-row">
                <label class="cd-field">{{ __('messages.Name') }}<input name="name" required maxlength="150" autocomplete="name"></label>
                <label class="cd-field">{{ __('messages.Phone') }}<input name="phone" type="tel" required maxlength="40" autocomplete="tel"></label>
              </div>
              <label class="cd-field">{{ __('messages.Email') }}<input name="email" type="email" maxlength="190" autocomplete="email" :required="type === 'reservation'"></label>
              <template x-if="type === 'test_drive'">
                <div class="cd-field-row">
                  <label class="cd-field">{{ __('messages.CarPreferredDate') }}<input name="preferred_date" type="date" required min="{{ now()->toDateString() }}"></label>
                  <label class="cd-field">{{ __('messages.CarTime') }}
                    <select name="preferred_time">
                      @foreach(\App\Models\VehicleLead::TIMES as $tm)<option value="{{ $tm }}">{{ __('messages.CarTime_'.$tm) }}</option>@endforeach
                    </select>
                  </label>
                </div>
              </template>
              <template x-if="type === 'enquiry'">
                <div class="cd-form">
                  <label class="cd-field">{{ __('messages.Message') }}<textarea name="message" rows="3" maxlength="2000">{{ $sold ? __('messages.CarSimilarRequest') : __('messages.CarStillAvailable') }}</textarea></label>
                  <label class="cd-check"><input type="checkbox" name="has_trade_in" value="1"><span>{{ __('messages.CarHaveTradeIn') }}</span></label>
                  @if(!$hidePricesCd && !$sold)
                    <label class="cd-check"><input type="checkbox" name="wants_finance" value="1"><span>{{ __('messages.CarInterestedFinance') }}</span></label>
                    <input type="hidden" name="finance_deposit" :value="deposit">
                    <input type="hidden" name="finance_term" :value="term">
                  @endif
                </div>
              </template>
              @if($reserve)
                <template x-if="type === 'reservation'">
                  <div class="cd-reserve-box">
                    <div><span>{{ $reserve['name'] }}</span><span class="cd-num">{{ store_money($reserve['price']) }}</span></div>
                    <span class="cd-muted">{{ __('messages.CarReserveHint', ['days' => $opts['reserve_days']]) }}</span>
                  </div>
                </template>
              @endif
              <input type="text" name="website" class="cd-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
              <button type="submit" class="cd-btn cd-btn-block" :disabled="sending">
                <span x-show="type === 'enquiry'">{{ __('messages.CarSendEnquiry') }}</span>
                <span x-show="type === 'test_drive'" x-cloak>{{ __('messages.CarRequestTestDrive') }}</span>
                @if($reserve)<span x-show="type === 'reservation'" x-cloak>{{ __('messages.CarReserveFor', ['amount' => store_money($reserve['price'])]) }}</span>@endif
              </button>
              <div x-show="ok" x-cloak class="cd-alert is-ok" role="status" x-text="ok"></div>
              <div x-show="err" x-cloak class="cd-alert is-err" role="alert" x-text="err"></div>
            </form>
          </div>
        @endif

        @if($opts['whatsapp'] !== '' || $s->contact_phone)
          <div class="cd-2btn">
            @if($opts['whatsapp'] !== '')<a class="cd-btn cd-btn-ghost" href="https://wa.me/{{ $opts['whatsapp'] }}?text={{ rawurlencode($whatsText) }}" target="_blank" rel="noopener"><x-store.icon name="whatsapp" />WhatsApp</a>@endif
            @if($s->contact_phone)<a class="cd-btn cd-btn-ghost" href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ __('messages.CarCall') }}</a>@endif
          </div>
        @endif
      </div>
    </aside>
  </div>

  @if($similar->isNotEmpty())
    <section class="cd-section">
      <div class="cd-head"><h2 class="cd-h2">{{ __('messages.CarSimilarCars') }}</h2><a href="{{ route('store.shop') }}" class="cd-link">{{ __('messages.CarAllInventory') }} →</a></div>
      <div class="cd-grid">@foreach($similar as $sp)@include('store.cars.partials.card', ['p' => $sp])@endforeach</div>
    </section>
  @endif
</div>

@include('store.cars.partials.lead-script')
@if($canBuy)
  @include('store.partials.shop-modals-scripts', ['currency' => store_currency()['symbol']])
@endif
@endsection
