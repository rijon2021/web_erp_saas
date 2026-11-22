{{-- Car Dealership theme: dark announcement bar + white header (logo,
     inventory nav, phone, account, cart, "Book a test drive") + a search row
     that slides open. Uses the shared layout's variables. --}}
@php
  $cdOpts = $electronicsOpts ?? \App\Support\CarsTheme::options($s);
  $storeNameCd = $s->store_name ?: __('messages.Store');
  $localeNamesCd = store_locales();
  $currencyOptionsCd = (Route::has('store.currency.switch') ? \App\Services\StoreCurrencyService::options() : collect());
  $activeCurrencyCd = store_currency();
  $announceCd = $cdOpts['announcement_text'] !== '' ? $cdOpts['announcement_text'] : (store_text($s, 'topbar_text_left') ?: '');
  $homeUrlCd = route('store.index');
  $cond = (string) request('condition', '');
  $onShop = request()->routeIs('store.shop');
  // Admin header menu wins; otherwise the dealership defaults.
  $navCd = $headerMenu
    ? collect($headerMenu)->map(fn ($mi) => ['label' => $mi['label'], 'url' => $mi['url'], 'active' => false])->all()
    : [
        ['label' => __('messages.CarInventory'), 'url' => route('store.shop'), 'active' => $onShop && $cond === ''],
        ['label' => __('messages.CarNewCars'), 'url' => route('store.shop', ['condition' => 'new']), 'active' => $onShop && $cond === 'new'],
        ['label' => __('messages.CarUsedCars'), 'url' => route('store.shop', ['condition' => 'used']), 'active' => $onShop && $cond === 'used'],
        ['label' => __('messages.CarFinance'), 'url' => $homeUrlCd.'#finance', 'active' => false],
        ['label' => __('messages.CarSellYourCar'), 'url' => $homeUrlCd.'#trade-in', 'active' => false],
        ['label' => __('messages.ContactUs'), 'url' => route('store.contact'), 'active' => request()->routeIs('store.contact')],
      ];
  $showCartCd = $cdOpts['allow_online_purchase'] || $cdOpts['enable_reserve'];
@endphp

@if($announceCd !== '' || $currencyOptionsCd->count() > 1 || count($localeNamesCd) > 1)
<div class="cd-announce">
  <div class="cd-container">
    <div class="cd-announce-side">
      @if($s->contact_address)<span>{{ \Illuminate\Support\Str::limit($s->contact_address, 48) }}</span>@endif
    </div>
    <div class="cd-announce-mid">{{ $announceCd }}</div>
    <div class="cd-announce-side">
      @if($currencyOptionsCd->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $activeCurrencyCd['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="cd-menu" style="min-width: 150px">
            @foreach($currencyOptionsCd as $co)<a href="{{ store_route_or('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>@endforeach
          </div>
        </div>
      @endif
      @if(count($localeNamesCd) > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $localeNamesCd[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="cd-menu" style="min-width: 150px">
            @foreach($localeNamesCd as $code => $label)<a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>@endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</div>
@endif

<header class="cd-header" x-data="{ search: false }" @keydown.escape.window="search = false">
  <div class="cd-container">
    <div class="cd-header-main">
      <button type="button" class="cd-icon-btn cd-show-sm" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}"><x-store.icon name="menu" /></button>
      <a href="{{ $homeUrlCd }}" class="cd-logo" aria-label="{{ $storeNameCd }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameCd }}">
        @else
          <span class="cd-logo-mark"><x-store.icon name="car" /></span><span>{{ $storeNameCd }}</span>
        @endif
      </a>
      <ul class="cd-nav" aria-label="{{ __('messages.Menu') }}">
        @foreach($navCd as $item)
          <li><a href="{{ $item['url'] }}" class="{{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a></li>
        @endforeach
      </ul>
      <div class="cd-actions">
        @if($s->contact_phone)
          <a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}" class="cd-phone"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>
        @endif
        <button type="button" class="cd-icon-btn" @click="search = !search; $nextTick(() => search && $root.querySelector('.cd-search-bar input[name=q]').focus())" :aria-expanded="search.toString()" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
        @if(!empty($cdOpts['show_theme_toggle']))
          <button type="button" class="cd-icon-btn cd-hide-sm" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}"><x-store.icon name="moon" class="inline dark:hidden" /><x-store.icon name="sun" class="hidden dark:inline" /></button>
        @endif
        @if($client)
          <div class="relative cd-hide-sm" x-data="dropdown()" @click.outside="close">
            <button type="button" class="cd-icon-btn" @click="toggle" aria-label="{{ $displayName }}">
              @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="">@else<x-store.icon name="user" />@endif
            </button>
            <div x-show="open" x-cloak x-transition class="cd-menu">
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              @if($walletNav ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              @if($licenseNav ?? false)<a href="{{ route('account.licenses') }}"><x-store.icon name="key" />{{ __('messages.License_My_Licenses') }}</a>@endif
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="cd-icon-btn cd-hide-sm" aria-label="{{ __('messages.SignIn') }}"><x-store.icon name="user" /></a>
        @endif
        @if($showCartCd)
          <button type="button" class="cd-icon-btn" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
            <x-store.icon name="cart" /><span class="cd-count cart-count">0</span>
          </button>
        @endif
        <a href="{{ route('store.shop') }}" class="cd-btn cd-hide-sm">{{ __('messages.CarBookTestDrive') }}</a>
      </div>
    </div>
  </div>

  <div class="cd-search-bar" x-show="search" x-cloak x-transition.opacity>
    <div class="cd-container">
      <form action="{{ route('store.shop') }}" method="GET" x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <x-store.icon name="search" />
        <input type="text" name="q" value="{{ request('q') }}" autocomplete="off" placeholder="{{ __('messages.CarSearchPlaceholder') }}" aria-label="{{ __('messages.Search') }}" x-model="q" @input.debounce.250ms="fetch">
        <button type="button" class="cd-icon-btn" @click="search = false" aria-label="{{ __('messages.Close') }}"><x-store.icon name="x" /></button>
        <div x-show="results.length" x-cloak class="cd-search-results">
          <template x-for="p in results" :key="p.id">
            <a :href="p.url">
              <img :src="p.image_url" :alt="p.name">
              <div style="min-width: 0; flex: 1"><div style="font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" x-text="p.name"></div></div>
              <div style="font-weight: 700" x-text="window.__HIDE_PRICES__ ? '' : (window.fmtMoney ? window.fmtMoney(p.display_price) : p.display_price)"></div>
            </a>
          </template>
        </div>
      </form>
    </div>
  </div>
</header>
