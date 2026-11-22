{{-- Restaurant theme: optional dark announcement bar + cream header (logo,
     menu / book / visit links, phone, account, cart). Uses the shared
     layout's variables. --}}
@php
  $rsOpts = $electronicsOpts ?? \App\Support\RestaurantTheme::options($s);
  $storeNameRs = $s->store_name ?: __('messages.Store');
  $localeNamesRs = store_locales();
  $currencyOptionsRs = (Route::has('store.currency.switch') ? \App\Services\StoreCurrencyService::options() : collect());
  $activeCurrencyRs = store_currency();
  $announceRs = $rsOpts['announcement_text'] !== '' ? $rsOpts['announcement_text'] : (store_text($s, 'topbar_text_left') ?: '');
  $homeUrlRs = route('store.index');
  $bookingOnRs = $rsOpts['sections']['booking'] && app(\App\Services\Restaurant\OnlineBookingService::class)->enabled();
  // Admin header menu wins; otherwise the restaurant defaults.
  $navRs = $headerMenu
    ? collect($headerMenu)->map(fn ($mi) => ['label' => $mi['label'], 'url' => $mi['url']])->all()
    : array_values(array_filter([
        ['label' => __('messages.RstMenu'), 'url' => $homeUrlRs.'#menu'],
        $bookingOnRs ? ['label' => __('messages.RstBookTable'), 'url' => $homeUrlRs.'#book'] : null,
        $rsOpts['sections']['about'] && $rsOpts['about_text'] !== '' ? ['label' => __('messages.RstAbout'), 'url' => $homeUrlRs.'#about'] : null,
        ['label' => __('messages.RstVisitUs'), 'url' => $homeUrlRs.'#visit'],
        ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
      ]));
@endphp

@if($announceRs !== '' || $currencyOptionsRs->count() > 1 || count($localeNamesRs) > 1)
<div class="rs-announce">
  <div class="rs-container">
    <div style="min-width: 60px"></div>
    <div class="rs-announce-mid">{{ $announceRs }}</div>
    <div style="display: flex; gap: 10px; min-width: 60px; justify-content: flex-end">
      @if($currencyOptionsRs->count() > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $activeCurrencyRs['code'] }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="rs-menu" style="min-width: 150px">
            @foreach($currencyOptionsRs as $co)<a href="{{ store_route_or('store.currency.switch', $co->id) }}">{{ $co->code }} — {{ $co->symbol }}</a>@endforeach
          </div>
        </div>
      @endif
      @if(count($localeNamesRs) > 1)
        <div class="relative" x-data="dropdown()" @click.outside="close">
          <button type="button" @click="toggle">{{ $localeNamesRs[app()->getLocale()] ?? strtoupper(app()->getLocale()) }}<x-store.icon name="chevron-down" /></button>
          <div x-show="open" x-cloak x-transition class="rs-menu" style="min-width: 150px">
            @foreach($localeNamesRs as $code => $label)<a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>@endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</div>
@endif

<header class="rs-header">
  <div class="rs-container">
    <div class="rs-header-main">
      <button type="button" class="rs-icon-btn rs-show-sm" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="{{ __('messages.Menu') }}"><x-store.icon name="menu" /></button>
      <a href="{{ $homeUrlRs }}" class="rs-logo" aria-label="{{ $storeNameRs }}">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameRs }}">
        @else
          <span class="rs-logo-mark"><x-store.icon name="utensils" /></span><span>{{ $storeNameRs }}</span>
        @endif
      </a>
      <ul class="rs-nav" aria-label="{{ __('messages.Menu') }}">
        @foreach($navRs as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>@endforeach
      </ul>
      <div class="rs-actions">
        @if($s->contact_phone)
          <a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}" class="rs-phone"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>
        @endif
        @if(!empty($rsOpts['show_theme_toggle']))
          <button type="button" class="rs-icon-btn rs-hide-sm" onclick="window.StoreTheme && window.StoreTheme.toggle()" aria-label="{{ __('messages.ToggleTheme') }}"><x-store.icon name="moon" class="inline dark:hidden" /><x-store.icon name="sun" class="hidden dark:inline" /></button>
        @endif
        @if($client)
          <div class="relative rs-hide-sm" x-data="dropdown()" @click.outside="close">
            <button type="button" class="rs-icon-btn" @click="toggle" aria-label="{{ $displayName }}">
              @if($avatarSrc)<img src="{{ $avatarSrc }}" alt="">@else<x-store.icon name="user" />@endif
            </button>
            <div x-show="open" x-cloak x-transition class="rs-menu">
              <a href="{{ $accountUrl }}"><x-store.icon name="user" />{{ __('messages.Profile') }}</a>
              <a href="{{ $ordersUrl }}"><x-store.icon name="package" />{{ __('messages.Orders') }}</a>
              @if($walletNav ?? false)<a href="{{ route('account.wallet') }}"><x-store.icon name="wallet" />{{ __('messages.MyWallet') }}</a>@endif
              @if($licenseNav ?? false)<a href="{{ route('account.licenses') }}"><x-store.icon name="key" />{{ __('messages.License_My_Licenses') }}</a>@endif
              <hr>
              <form method="POST" action="{{ $logoutUrl }}">@csrf<button type="submit"><x-store.icon name="log-out" />{{ __('messages.Logout') }}</button></form>
            </div>
          </div>
        @else
          <a href="{{ $loginUrl }}" class="rs-icon-btn rs-hide-sm" aria-label="{{ __('messages.SignIn') }}"><x-store.icon name="user" /></a>
        @endif
        <button type="button" class="rs-icon-btn" @click="window.StoreUI.open('miniCart')" aria-label="{{ __('messages.Cart') }}">
          <x-store.icon name="cart" /><span class="rs-count cart-count">0</span>
        </button>
        @if($bookingOnRs)
          <a href="{{ $homeUrlRs }}#book" class="rs-btn rs-btn-sm rs-hide-sm">{{ __('messages.RstBookTable') }}</a>
        @endif
      </div>
    </div>
  </div>
</header>
