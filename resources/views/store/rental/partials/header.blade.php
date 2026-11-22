{{-- Rental theme: optional dark announcement bar + white header (logo, browse
     links per business type, how it works, contact, phone, account with "My
     bookings", Book now). Its own mobile drawer (#rnMobileNav): the layout's
     category drawer lists products. Uses the shared layout's variables. --}}
@php
  $rnOpts = $electronicsOpts ?? \App\Support\RentalTheme::options($s);
  $rnStoreName = $s->store_name ?: __('messages.Store');
  $rnLocales = store_locales();
  $rnCurrencies = (Route::has('store.currency.switch') ? \App\Services\StoreCurrencyService::options() : collect());
  $rnActiveCurrency = store_currency();
  // Only the theme's own announcement: the generic store top-bar text is shop copy ("free shipping…").
  $rnAnnounce = $rnOpts['announcement_text'];
  $rnHome = route('store.index');
  $rnShop = store_route_or('store.shop');
  $rnHeaderTypes = \App\Services\Rental\RentalWebsiteService::available() ? \App\Support\RentalTypes::enabled() : [];
  $rnMyUrl = Route::has('account.rentals') ? route('account.rentals') : $accountUrl;
  // Back to this page after signing in (the login page only accepts "/…" redirects; without one it opens checkout).
  $rnLoginUrl = $loginUrl.'?redirect='.urlencode(request()->getRequestUri());
  // Admin header menu wins; otherwise browse links (one per business type when there are several).
  $rnNav = $headerMenu
    ? collect($headerMenu)->map(fn ($mi) => ['label' => $mi['label'], 'url' => $mi['url'], 'icon' => 'arrow-right'])->all()
    : array_values(array_filter(array_merge(
        count($rnHeaderTypes) > 1
          ? array_map(fn ($t) => ['label' => \App\Support\RentalTypes::typeLabel($t), 'url' => $rnShop.'?type='.$t, 'icon' => (\App\Support\RentalTypes::definition($t)['icon'] ?? 'grid')], $rnHeaderTypes)
          : [['label' => __('messages.RntBrowse'), 'url' => $rnShop, 'icon' => (\App\Support\RentalTypes::definition($rnHeaderTypes[0] ?? 'other')['icon'] ?? 'grid')]],
        [
          $rnOpts['sections']['how'] ? ['label' => __('messages.RntHowItWorks'), 'url' => $rnHome.'#how', 'icon' => 'info'] : null,
          ['label' => __('messages.ContactUs'), 'url' => route('store.contact'), 'icon' => 'mail'],
        ]
      )));
@endphp

@if($rnAnnounce !== '' || $rnCurrencies->count() > 1 || count($rnLocales) > 1)
<div class="rn-announce">
  <div class="rn-container">
    <div style="min-width: 60px"></div>
    <div class="rn-announce-mid">{{ $rnAnnounce }}</div>
    <div style="display: flex; gap: 10px; min-width: 60px; justify-content: flex-end">
      @if($rnCurrencies->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $rnActiveCurrency['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="rn-menu" style="min-width: 150px">
            @foreach($rnCurrencies as $co)<a href="{{ store_route_or('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>@endforeach
          </div>
        </div>
      @endif
      @if(count($rnLocales) > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $rnLocales[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="rn-menu" style="min-width: 150px">
            @foreach($rnLocales as $code => $label)<a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>@endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</div>
@endif

<header class="rn-header">
  <div class="rn-container">
    <div class="rn-header-main">
      <button type="button" class="rn-icon-btn rn-show-sm" @click="window.StoreUI.open('rnMobileNav')" aria-label="{{ __('messages.Menu') }}"><x-store.icon name="menu" /></button>
      <a href="{{ $rnHome }}" class="rn-logo" aria-label="{{ $rnStoreName }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $rnStoreName }}">
        @else
          <span class="rn-logo-mark"><x-store.icon name="key" /></span><span>{{ $rnStoreName }}</span>
        @endif
      </a>
      <ul class="rn-nav" aria-label="{{ __('messages.Menu') }}">
        @foreach($rnNav as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>@endforeach
      </ul>
      <div class="rn-actions">
        @if($s->contact_phone)
          <a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}" class="rn-phone"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>
        @endif
        @if(!empty($rnOpts['show_theme_toggle']))
          <button type="button" class="rn-icon-btn rn-hide-sm" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}"><x-store.icon name="moon" class="inline dark:hidden" /><x-store.icon name="sun" class="hidden dark:inline" /></button>
        @endif
        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="rn-icon-btn" @click="toggle" aria-label="{{ $displayName }}">
              @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="">@else<x-store.icon name="user" />@endif
            </button>
            <div x-show="open" x-cloak x-transition class="rn-menu">
              <a href="{{ $rnMyUrl }}"><x-store.icon name="calendar" />{{ __('messages.RntMyBookings') }}</a>
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              @if($walletNav ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              @if($licenseNav ?? false)<a href="{{ route('account.licenses') }}"><x-store.icon name="key" />{{ __('messages.License_My_Licenses') }}</a>@endif
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $rnLoginUrl }}" class="rn-icon-btn" aria-label="{{ __('messages.SignIn') }}"><x-store.icon name="user" /></a>
        @endif
        <a href="{{ $rnShop }}" class="rn-btn rn-btn-sm rn-hide-sm">{{ __('messages.RntBookNow') }}</a>
      </div>
    </div>
  </div>
</header>

{{-- Mobile navigation drawer --}}
<div id="rnMobileNav" x-data="drawer({ side: 'start' })" @keydown.window="onEsc">
  <template x-teleport="body">
    <div x-show="isOpen" x-cloak class="fixed inset-0 z-[60]">
      <div x-show="isOpen" x-transition.opacity class="drawer-backdrop" @click="close"></div>
      <aside x-show="isOpen" x-transition class="drawer-panel drawer-start" aria-label="{{ __('messages.Menu') }}">
        <div class="drawer-header">
          <h5 class="font-semibold">{{ $rnStoreName }}</h5>
          <button type="button" class="btn btn-ghost btn-icon" @click="close" aria-label="{{ __('messages.Close') }}"><x-store.icon name="x" class="w-5 h-5" /></button>
        </div>
        <nav class="drawer-body p-0 rn-drawer-nav">
          <a href="{{ $rnHome }}"><x-store.icon name="home" />{{ __('messages.Home') }}</a>
          @foreach($rnNav as $item)<a href="{{ $item['url'] }}"><x-store.icon :name="$item['icon']" />{{ $item['label'] }}</a>@endforeach
          @if($client)
            <a href="{{ $rnMyUrl }}"><x-store.icon name="calendar" />{{ __('messages.RntMyBookings') }}</a>
          @else
            <a href="{{ $rnLoginUrl }}"><x-store.icon name="log-in" />{{ __('messages.SignIn') }}</a>
          @endif
          @if($s->contact_phone)
            <a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>
          @endif
        </nav>
      </aside>
    </div>
  </template>
</div>
