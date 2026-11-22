{{-- Electronics Premium header: top bar, logo / scoped search / account,
     compare, cart, then the nav row with the "All categories" mega menu.
     Expects the shared layout's variables ($s, $categories, $client,
     $assetPath, $loginUrl, $accountUrl, $ordersUrl, $logoutUrl…). --}}
@php
  $pxName = $s->store_name ?: __('messages.Store');
  $pxNavCats = $categories->take(7);
  $pxCurrentCat = (string) request('category', '');
  $pxIsDeals = request()->routeIs('store.flash_sales') || request('deals');
  $pxTopLeft = store_text($s, 'topbar_text_left');
  $pxTopRight = store_text($s, 'topbar_text_right');
  $pxLocales = store_locales();
  $pxCurrencies = (Route::has('store.currency.switch') ? \App\Services\StoreCurrencyService::options() : collect());
  $pxCurrency = store_currency();
  $pxHasCompare = Route::has('store.compare');

  // Mega menu extras: top brands and one featured product with a photo.
  $pxBrands = \App\Models\Brand::query()->whereNull('deleted_at')->orderBy('name')->take(8)->get(['id', 'name']);
  $pxPromo = \App\Models\Product::query()
      ->whereNull('deleted_at')->where('is_active', 1)->where('hide_from_online_store', 0)->forStorefrontWarehouses()
      ->whereNotNull('image')->where('image', '!=', '')->where('image', '!=', 'no-image.png')
      ->orderByDesc('is_featured')->orderByDesc('created_at')
      ->first(['id', 'name', 'image']);
  $pxFirstCat = $categories->first();
@endphp

<div class="px-topbar">
  <div class="px-container">
    <div class="px-topbar-left">{{ $pxTopLeft ?: __('messages.TopbarLeft') }}@if($pxTopRight)<span class="px-topbar-extra"> &nbsp;·&nbsp; {{ $pxTopRight }}</span>@endif</div>
    <div class="px-topbar-right">
      <a href="{{ $client ? route('account.orders') : $loginUrl }}"><x-store.icon name="package" />{{ __('messages.TrackOrder') }}</a>
      <a href="{{ route('store.contact') }}"><x-store.icon name="help-circle" />{{ __('messages.HelpCenter') }}</a>
      @if(count($pxLocales) > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle" :aria-expanded="open.toString()"><x-store.icon name="globe" />{{ $pxLocales[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="px-menu" style="min-width: 150px">
            @foreach($pxLocales as $code => $label)
              <a href="{{ route('lang.switch', $code) }}" @class(['font-semibold' => app()->getLocale() === $code])>{{ $label }}</a>
            @endforeach
          </div>
        </div>
      @endif
      @if($pxCurrencies->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle" :aria-expanded="open.toString()">{{ $pxCurrency['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="px-menu" style="min-width: 150px">
            @foreach($pxCurrencies as $co)
              <a href="{{ store_route_or('store.currency.switch', $co->id) }}" @class(['font-semibold' => (int) $co->id === (int) ($pxCurrency['id'] ?? 0)])>{{ $co->code }} — {{ $co->symbol }}</a>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</div>

<header class="px-header" x-data="{ mega: false, cat: {{ (int) ($pxFirstCat->id ?? 0) }} }" @keydown.escape.window="mega = false">
  <div class="px-container">
    <div class="px-header-main">
      <button type="button" class="px-icon-btn lg:hidden" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}">
        <x-store.icon name="menu" />
      </button>

      <a href="{{ route('store.index') }}" class="px-logo" aria-label="{{ $pxName }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $pxName }}">
        @else
          <span class="px-logo-mark"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span>
          <span>{{ $pxName }}</span>
        @endif
      </a>

      <form class="px-search" role="search" action="{{ route('store.shop') }}" method="GET"
            x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <label class="px-sr" for="px-search-cat">{{ __('messages.Categories') }}</label>
        <select id="px-search-cat" name="category">
          <option value="">{{ __('messages.AllCategories') }}</option>
          @foreach($categories as $c)
            <option value="{{ $c->id }}" @selected($pxCurrentCat === (string) $c->id)>{{ $c->name }}</option>
          @endforeach
        </select>
        <label class="px-sr" for="px-search-q">{{ __('messages.Search') }}</label>
        <input id="px-search-q" type="search" name="q" value="{{ request('q') }}" autocomplete="off"
               placeholder="{{ __('messages.PxSearchPlaceholder') }}"
               x-model="q" @input.debounce.250ms="fetch">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
        <div x-show="results.length" x-cloak class="px-search-results">
          <template x-for="p in results" :key="p.id">
            <a :href="p.url">
              <img :src="p.image_url" :alt="p.name">
              <div class="min-w-0 flex-1">
                <div class="text-sm font-medium truncate" x-text="p.name"></div>
                <div class="text-xs px-muted" x-text="p.code || ''"></div>
              </div>
              <div class="text-sm font-semibold" x-text="window.__HIDE_PRICES__ ? '' : window.storeWithCurrency('{{ $s->currency_code ?? '$' }}', p.display_price)"></div>
            </a>
          </template>
        </div>
      </form>

      <nav class="px-actions" aria-label="{{ __('messages.Account') }}">
        @if(!empty($electronicsOpts['show_theme_toggle']))
          <button type="button" class="px-icon-btn px-theme-toggle" x-data="pxThemeToggle()" @click="toggle()"
                  :aria-pressed="dark.toString()" :aria-label="label" :title="label"
                  aria-label="{{ __('messages.ToggleTheme') }}">
            {{-- Moon in light mode (switch to dark), sun in dark mode (switch to light). --}}
            <svg class="px-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            <svg class="px-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
          </button>
        @endif
        @if($pxHasCompare)
          <a href="{{ route('store.compare') }}" class="px-icon-btn" x-data="pxCompareBadge()" aria-label="{{ __('messages.PxCompareProducts') }}" :aria-label="label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M8 3v18M16 3v18M3 8h5M16 16h5"/></svg>
            <span class="px-badge" x-text="count" :data-count="count" data-count="0">0</span>
          </a>
        @endif

        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="px-icon-btn" @click="toggle" :aria-expanded="open.toString()" aria-label="{{ __('messages.Account') }}">
              @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="" style="width: 28px; height: 28px; border-radius: 99px; object-fit: cover">@else<x-store.icon name="user" />@endif
            </button>
            <div x-show="open" x-cloak x-transition class="px-menu">
              <div style="padding: 8px 10px 6px; font-size: 12px; color: var(--px-muted)">{{ __('messages.Hello') }}, <b style="color: var(--px-ink)">{{ $displayName }}</b></div>
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              @if($walletNav ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              @if($licenseNav ?? false)<a href="{{ route('account.licenses') }}"><x-store.icon name="key" />{{ __('messages.License_My_Licenses') }}</a>@endif
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="px-icon-btn" aria-label="{{ __('messages.SignIn') }}"><x-store.icon name="user" /></a>
        @endif

        <button type="button" class="px-cart" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
          <x-store.icon name="cart" />
          <span class="px-badge cart-count" data-count="0">0</span>
          <span class="px-cart-text"><small>{{ __('messages.Cart') }}</small><strong class="px-cart-total">{{ store_money(0) }}</strong></span>
        </button>
      </nav>
    </div>

    <div class="px-msearch">
      <form action="{{ route('store.shop') }}" method="GET" role="search">
        <label class="px-sr" for="px-msearch-q">{{ __('messages.Search') }}</label>
        <input id="px-msearch-q" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('messages.SearchProducts') }}" autocomplete="off">
        <button type="submit" aria-label="{{ __('messages.Search') }}"><x-store.icon name="search" /></button>
      </form>
    </div>
  </div>

  <nav class="px-nav" aria-label="{{ __('messages.Categories') }}" @click.outside="mega = false">
    <div class="px-container">
      <div class="px-nav-row">
        @if($categories->isNotEmpty())
          <button type="button" class="px-allcats" @click="mega = !mega" :aria-expanded="mega.toString()" aria-expanded="false" aria-controls="px-mega">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
            {{ __('messages.AllCategories') }}
            <svg class="px-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
        @endif
        <a href="{{ route('store.index') }}" class="px-nav-link {{ request()->routeIs('store.index') ? 'is-active' : '' }}">{{ __('messages.Home') }}</a>
        @foreach($pxNavCats as $category)
          <a href="{{ route('store.shop', ['category' => $category->id]) }}" class="px-nav-link {{ $pxCurrentCat === (string) $category->id ? 'is-active' : '' }}">{{ $category->name }}</a>
        @endforeach
        <a href="{{ route('store.shop', ['deals' => 1]) }}" class="px-nav-link px-deal {{ $pxIsDeals ? 'is-active' : '' }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>{{ __('messages.Deals') }}</a>
        <span class="px-nav-help">{{ __('messages.PxNeedHelp') }} <a href="{{ route('store.contact') }}">{{ __('messages.PxTalkToExpert') }}</a></span>
      </div>
    </div>

    @if($categories->isNotEmpty())
      <div class="px-mega" id="px-mega" x-show="mega" x-cloak x-transition.opacity>
        <div class="px-container">
          <div class="px-mega-panel">
            <div class="px-mega-cats">
              @foreach($categories as $category)
                <a href="{{ route('store.shop', ['category' => $category->id]) }}"
                   @mouseenter="cat = {{ (int) $category->id }}" @focus="cat = {{ (int) $category->id }}"
                   :class="{ 'is-on': cat === {{ (int) $category->id }} }">
                  <span>{{ $category->name }}</span>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </a>
              @endforeach
            </div>
            <div class="px-mega-body">
              @foreach($categories as $category)
                @php $subs = $category->subcategories ?? collect(); @endphp
                <div class="px-mega-col" x-show="cat === {{ (int) $category->id }}" @if(! $loop->first) x-cloak @endif>
                  <span class="px-kicker">{{ $category->name }}</span>
                  @foreach($subs->take(10) as $sub)
                    <a href="{{ route('store.shop', ['category' => $category->id, 'sub_category' => $sub->id]) }}">{{ $sub->name }}</a>
                  @endforeach
                  <a href="{{ route('store.shop', ['category' => $category->id]) }}" style="color: var(--px-accent-text); font-weight: 600">{{ __('messages.ViewAll') }} →</a>
                </div>
              @endforeach
              @if($pxBrands->isNotEmpty())
                <div class="px-mega-col">
                  <span class="px-kicker">{{ __('messages.ShopByBrand') }}</span>
                  @foreach($pxBrands as $b)
                    <a href="{{ route('store.shop', ['brand' => $b->id]) }}">{{ $b->name }}</a>
                  @endforeach
                </div>
              @endif
              <div class="px-mega-col">
                <span class="px-kicker">{{ __('messages.PxQuickLinks') }}</span>
                <a href="{{ route('store.shop', ['sort' => 'latest']) }}">{{ __('messages.NewArrivals') }}</a>
                <a href="{{ route('store.shop', ['deals' => 1]) }}">{{ __('messages.Deals') }}</a>
                @if($pxHasCompare)<a href="{{ route('store.compare') }}">{{ __('messages.PxCompareProducts') }}</a>@endif
                <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
              </div>
            </div>
            @if($pxPromo)
              <a href="{{ product_url($pxPromo->id) }}" class="px-mega-promo">
                <span class="px-kicker">{{ __('messages.PxFeatured') }}</span>
                <strong>{{ \Illuminate\Support\Str::limit($pxPromo->name, 60) }}</strong>
                <img src="{{ product_image_url($pxPromo->image) }}" alt="" loading="lazy">
                <span style="color: var(--px-accent-text); font-weight: 600">{{ __('messages.ShopNow') }} →</span>
              </a>
            @endif
          </div>
        </div>
      </div>
    @endif
  </nav>
</header>

<script>
  // Header cart total mirrors the drawer's subtotal.
  (function () {
    function sync() {
      var src = document.getElementById('mc-subtotal');
      var dst = document.querySelector('.px-cart-total');
      if (src && dst && src.textContent) dst.textContent = src.textContent;
      // Hide the count badge on an empty cart (.px-badge[data-count="0"]).
      document.querySelectorAll('.px-cart .cart-count').forEach(function (b) { b.setAttribute('data-count', String(b.textContent || '0').trim()); });
    }
    document.addEventListener('DOMContentLoaded', function () {
      sync();
      var src = document.getElementById('mc-subtotal');
      if (src && window.MutationObserver) new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true });
      var badge = document.querySelector('.px-cart .cart-count');
      if (badge && window.MutationObserver) new MutationObserver(sync).observe(badge, { childList: true, characterData: true, subtree: true });
      window.addEventListener('cart:updated', sync);
      window.addEventListener('cart:changed', sync);
    });
  })();
</script>
