{{-- Fashion & Apparel theme: black announcement bar + header (category nav
     with sub-category drop-downs, centred wordmark/logo, search / account /
     bag) + a search row that slides open. Uses the shared layout's variables. --}}
@php
  $faOpts = $electronicsOpts ?? \App\Support\FashionTheme::options($s);
  $storeNameFa = $s->store_name ?: __('messages.Store');
  $currentCat = (string) request('category', '');
  $localeNames = store_locales();
  $currencyOptionsFa = (Route::has('store.currency.switch') ? \App\Services\StoreCurrencyService::options() : collect());
  $activeCurrencyFa = store_currency();
  $announceText = $faOpts['announcement_text'] ?: (store_text($s, 'topbar_text_left') ?: __('messages.TopbarLeft'));
  $saleUrl = Route::has('store.flash_sales') ? route('store.flash_sales') : route('store.shop');
  // Admin header menu wins; otherwise the first categories with their sub-categories.
  $navItems = $headerMenu
    ? collect($headerMenu)->map(fn ($mi) => ['label' => $mi['label'], 'url' => $mi['url'], 'subs' => collect(), 'active' => false])
    : $categories->take(5)->map(fn ($c) => [
        'label' => $c->name,
        'url' => route('store.shop', ['category' => $c->id]),
        'subs' => ($c->subcategories ?? collect())->map(fn ($sub) => ['label' => $sub->name, 'url' => route('store.shop', ['category' => $c->id, 'sub_category' => $sub->id])]),
        'active' => $currentCat === (string) $c->id,
      ]);
@endphp

<div class="fa-announce">
  <div class="fa-container">
    <div class="fa-announce-side">
      <a href="{{ route('store.contact') }}">{{ __('messages.HelpCenter') }}</a>
    </div>
    <div class="fa-announce-mid">{{ $announceText }}</div>
    <div class="fa-announce-side is-end">
      @if($currencyOptionsFa->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $activeCurrencyFa['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="fa-menu" style="min-width: 150px">
            @foreach($currencyOptionsFa as $co)<a href="{{ store_route_or('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>@endforeach
          </div>
        </div>
      @endif
      @if(count($localeNames) > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $localeNames[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="fa-menu" style="min-width: 150px">
            @foreach($localeNames as $code => $label)<a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>@endforeach
          </div>
        </div>
      @endif
      <a href="{{ $client ? route('account.orders') : $loginUrl }}">{{ __('messages.TrackOrder') }}</a>
    </div>
  </div>
</div>

<header class="fa-header" x-data="{ search: false }" @keydown.escape.window="search = false">
  <div class="fa-container">
    <div class="fa-header-main">
      <div>
        <div class="fa-mobile-left">
          <button type="button" class="fa-icon-btn" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}"><x-store.icon name="menu" /></button>
          <button type="button" class="fa-icon-btn" @click="search = !search; $nextTick(() => search && $root.querySelector('.fa-search-bar input[name=q]').focus())" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
        </div>
        <ul class="fa-nav" aria-label="{{ __('messages.Categories') }}" style="margin: 0; padding: 0">
          <li><a href="{{ route('store.shop', ['sort' => 'latest']) }}" class="fa-nav-link">{{ __('messages.NewArrivals') }}</a></li>
          @foreach($navItems as $item)
            <li>
              <a href="{{ $item['url'] }}" class="fa-nav-link {{ $item['active'] ? 'is-active' : '' }}">{{ $item['label'] }}</a>
              @if($item['subs']->count())
                <div class="fa-mega">
                  @foreach($item['subs'] as $sub)<a href="{{ $sub['url'] }}">{{ $sub['label'] }}</a>@endforeach
                </div>
              @endif
            </li>
          @endforeach
          <li><a href="{{ $saleUrl }}" class="fa-nav-link is-sale {{ request()->routeIs('store.flash_sales') ? 'is-active' : '' }}">{{ __('messages.FashionSale') }}</a></li>
        </ul>
      </div>

      <a href="{{ route('store.index') }}" class="fa-logo" aria-label="{{ $storeNameFa }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameFa }}">
        @else
          {{ $storeNameFa }}
        @endif
      </a>

      <div class="fa-actions">
        <button type="button" class="fa-icon-btn fa-hide-sm" @click="search = !search; $nextTick(() => search && $root.querySelector('.fa-search-bar input[name=q]').focus())" :aria-expanded="search.toString()" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
        @if(!empty($faOpts['show_theme_toggle']))
          <button type="button" class="fa-icon-btn fa-hide-sm" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}"><x-store.icon name="moon" class="inline dark:hidden" /><x-store.icon name="sun" class="hidden dark:inline" /></button>
        @endif
        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="fa-icon-btn" @click="toggle" aria-label="{{ $displayName }}">
              @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="">@else<x-store.icon name="user" />@endif
            </button>
            <div x-show="open" x-cloak x-transition class="fa-menu">
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              @if($walletNav ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              @if($licenseNav ?? false)<a href="{{ route('account.licenses') }}"><x-store.icon name="key" />{{ __('messages.License_My_Licenses') }}</a>@endif
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="fa-icon-btn" aria-label="{{ __('messages.SignIn') }}"><x-store.icon name="user" /></a>
        @endif
        <button type="button" class="fa-icon-btn" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
          <x-store.icon name="bag" /><span class="fa-count cart-count">0</span>
        </button>
      </div>
    </div>
  </div>

  <div class="fa-search-bar" x-show="search" x-cloak x-transition.opacity>
    <div class="fa-container">
      <form action="{{ route('store.shop') }}" method="GET" x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <x-store.icon name="search" />
        <input type="text" name="q" x-ref="faSearch" value="{{ request('q') }}" autocomplete="off" placeholder="{{ __('messages.SearchProducts') }}" aria-label="{{ __('messages.Search') }}" x-model="q" @input.debounce.250ms="fetch">
        <button type="button" class="fa-icon-btn" @click="search = false" aria-label="{{ __('messages.Close') }}"><x-store.icon name="x" /></button>
        <div x-show="results.length" x-cloak class="fa-search-results">
          <template x-for="p in results" :key="p.id">
            <a :href="p.url">
              <img :src="p.image_url" :alt="p.name">
              <div class="min-w-0 flex-1"><div class="text-sm truncate" x-text="p.name"></div><div class="text-xs" style="color: var(--fa-muted)" x-text="p.code || ''"></div></div>
              <div class="text-sm" style="font-weight: 500" x-text="window.__HIDE_PRICES__ ? '' : window.storeWithCurrency('{{ $s->currency_code ?? '$' }}', p.display_price)"></div>
            </a>
          </template>
        </div>
      </form>
    </div>
  </div>
</header>
