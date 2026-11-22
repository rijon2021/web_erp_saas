{{-- Booking theme: optional dark announcement bar + white header (logo,
     section links, account with "My bookings", Book now). Its own mobile drawer
     (#bkMobileNav): the layout's category drawer lists products. Uses the
     shared layout's variables. --}}
@php
  $bkOpts = $electronicsOpts ?? \App\Support\BookingTheme::options($s);
  $bkStoreName = $s->store_name ?: __('messages.Store');
  $bkLocales = store_locales();
  $bkCurrencies = (Route::has('store.currency.switch') ? \App\Services\StoreCurrencyService::options() : collect());
  $bkActiveCurrency = store_currency();
  $bkAnnounce = $bkOpts['announcement_text'];
  $bkHome = route('store.index');
  $bkBookUrl = Route::has('store.booking.book') ? route('store.booking.book') : $bkHome;
  $bkMyUrl = Route::has('account.appointments') ? route('account.appointments') : $accountUrl;
  // Back to this page after signing in (the login page only accepts "/…" redirects).
  $bkLoginUrl = $loginUrl.'?redirect='.urlencode(request()->getRequestUri());
  $bkNav = $headerMenu
    ? collect($headerMenu)->map(fn ($mi) => ['label' => $mi['label'], 'url' => $mi['url'], 'icon' => 'arrow-right'])->all()
    : array_values(array_filter([
        $bkOpts['sections']['services'] ? ['label' => __('messages.BkmServices'), 'url' => $bkHome.'#services', 'icon' => 'grid'] : null,
        $bkOpts['sections']['team'] ? ['label' => __('messages.BkmTeam'), 'url' => $bkHome.'#team', 'icon' => 'users'] : null,
        $bkOpts['sections']['how'] ? ['label' => __('messages.BkmHowItWorks'), 'url' => $bkHome.'#how', 'icon' => 'info'] : null,
        ['label' => __('messages.ContactUs'), 'url' => $bkOpts['sections']['visit'] ? $bkHome.'#visit' : route('store.contact'), 'icon' => 'mail'],
      ]));
@endphp

@if($bkAnnounce !== '' || $bkCurrencies->count() > 1 || count($bkLocales) > 1)
<div class="bk-announce">
  <div class="bk-container">
    <div style="min-width: 60px"></div>
    <div class="bk-announce-mid">{{ $bkAnnounce }}</div>
    <div style="display: flex; gap: 10px; min-width: 60px; justify-content: flex-end">
      @if($bkCurrencies->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $bkActiveCurrency['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="bk-menu" style="min-width: 150px">
            @foreach($bkCurrencies as $co)<a href="{{ store_route_or('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>@endforeach
          </div>
        </div>
      @endif
      @if(count($bkLocales) > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $bkLocales[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="bk-menu" style="min-width: 150px">
            @foreach($bkLocales as $code => $label)<a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>@endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</div>
@endif

<header class="bk-header">
  <div class="bk-container">
    <div class="bk-header-main">
      <button type="button" class="bk-icon-btn bk-show-sm" @click="window.StoreUI.open('bkMobileNav')" aria-label="{{ __('messages.Menu') }}"><x-store.icon name="menu" /></button>
      <a href="{{ $bkHome }}" class="bk-logo" aria-label="{{ $bkStoreName }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $bkStoreName }}">
        @else
          <span class="bk-logo-mark"><x-store.icon name="calendar-check" /></span><span>{{ $bkStoreName }}</span>
        @endif
      </a>
      <ul class="bk-nav" aria-label="{{ __('messages.Menu') }}">
        @foreach($bkNav as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>@endforeach
      </ul>
      <div class="bk-actions">
        @if(!empty($bkOpts['show_theme_toggle']))
          <button type="button" class="bk-icon-btn bk-hide-sm" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}"><x-store.icon name="moon" class="inline dark:hidden" /><x-store.icon name="sun" class="hidden dark:inline" /></button>
        @endif
        @if($client)
          <div class="relative" x-data="dropdown()" @click.outside="close">
            <button type="button" class="bk-icon-btn" @click="toggle" aria-label="{{ $displayName }}">
              @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="">@else<x-store.icon name="user" />@endif
            </button>
            <div x-show="open" x-cloak x-transition class="bk-menu">
              <a href="{{ $bkMyUrl }}"><x-store.icon name="calendar" />{{ __('messages.BkmMyBookings') }}</a>
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              @if($walletNav ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              @if($licenseNav ?? false)<a href="{{ route('account.licenses') }}"><x-store.icon name="key" />{{ __('messages.License_My_Licenses') }}</a>@endif
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $bkLoginUrl }}" class="bk-icon-btn" aria-label="{{ __('messages.SignIn') }}"><x-store.icon name="user" /></a>
        @endif
        <a href="{{ $bkBookUrl }}" class="bk-btn bk-btn-sm bk-hide-sm">{{ __('messages.BkmBookNow') }}</a>
      </div>
    </div>
  </div>
</header>

{{-- Mobile navigation drawer --}}
<div id="bkMobileNav" x-data="drawer({ side: 'start' })" @keydown.window="onEsc">
  <template x-teleport="body">
    <div x-show="isOpen" x-cloak class="fixed inset-0 z-[60]">
      <div x-show="isOpen" x-transition.opacity class="drawer-backdrop" @click="close"></div>
      <aside x-show="isOpen" x-transition class="drawer-panel drawer-start" aria-label="{{ __('messages.Menu') }}">
        <div class="drawer-header">
          <h5 class="font-semibold">{{ $bkStoreName }}</h5>
          <button type="button" class="btn btn-ghost btn-icon" @click="close" aria-label="{{ __('messages.Close') }}"><x-store.icon name="x" class="w-5 h-5" /></button>
        </div>
        <nav class="drawer-body p-0 bk-drawer-nav">
          <a href="{{ $bkHome }}"><x-store.icon name="home" />{{ __('messages.Home') }}</a>
          <a href="{{ $bkBookUrl }}"><x-store.icon name="calendar-check" />{{ __('messages.BkmBookNow') }}</a>
          @foreach($bkNav as $item)<a href="{{ $item['url'] }}" @click="close"><x-store.icon :name="$item['icon']" />{{ $item['label'] }}</a>@endforeach
          @if($client)
            <a href="{{ $bkMyUrl }}"><x-store.icon name="calendar" />{{ __('messages.BkmMyBookings') }}</a>
          @else
            <a href="{{ $bkLoginUrl }}"><x-store.icon name="log-in" />{{ __('messages.SignIn') }}</a>
          @endif
          @if($s->contact_phone)
            <a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>
          @endif
        </nav>
      </aside>
    </div>
  </template>
</div>
