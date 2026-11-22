{{-- Electronics Premium footer: brand column, link columns (admin footer
     menus, with category/support defaults), contact column, payment pills. --}}
@php
  $pxOpts = $electronicsOpts ?? \App\Support\PremiumElectronicsTheme::options($s);
  $pxName = $s->store_name ?: __('messages.Store');
  $pxShopLinks = $footerShopMenu ?: collect($categories->take(6))->map(fn ($c) => ['label' => $c->name, 'url' => route('store.shop', ['category' => $c->id])])->all();
  $pxCareLinks = $footerSupportMenu ?: [
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
      ['label' => __('messages.TrackOrder'), 'url' => $client ? route('account.orders') : $loginUrl],
  ];
  $pxPages = \Illuminate\Support\Facades\Schema::hasTable('store_pages')
      ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug'])
      : collect();
  $pxAddress = function_exists('store_address_lines') ? store_address_lines([$s->contact_address]) : array_filter([$s->contact_address]);
@endphp
@if(Route::has('store.compare') && ! request()->routeIs('store.compare'))
  {{-- Compare tray: the visitor's compare list (this browser), any page --}}
  <div class="px-tray" role="region" aria-label="{{ __('messages.PxCompareProducts') }}" x-data="pxCompareTray()" x-show="items.length" x-cloak>
    <div class="px-container">
      <strong>{{ __('messages.PxCompareProducts') }} <span class="px-muted" x-text="items.length + ' / 4'"></span></strong>
      <div class="px-tray-items">
        <template x-for="it in items" :key="it.id">
          <span class="px-tray-item">
            <img :src="it.image" alt="">
            <a :href="it.url" x-text="it.name"></a>
            <button type="button" @click="remove(it.id)" :aria-label="@js(__('messages.Remove')) + ': ' + it.name">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
          </span>
        </template>
      </div>
      <button type="button" class="px-clear" @click="clear()">{{ __('messages.PxClearAll') }}</button>
      <a :href="url" class="px-btn px-btn-primary px-btn-sm" :class="{ 'is-disabled': items.length < 2 }">{{ __('messages.PxCompareNow') }}</a>
    </div>
  </div>
  <script>
    window.pxCompareTray = function () {
      var base = @js(route('store.compare'));
      return {
        items: window.PxCompare.list(),
        init: function () { var self = this; window.addEventListener('px:compare', function (e) { self.items = e.detail; }); },
        get url() { return base + '?ids=' + this.items.map(function (x) { return x.id; }).join(','); },
        remove: function (id) { window.PxCompare.remove(id); },
        clear: function () { window.PxCompare.clear(); }
      };
    };
  </script>
@endif
<footer class="px-footer store-footer">
  <div class="px-container">
    <div class="px-footer-grid">
      <div class="px-footer-brand">
        <a href="{{ route('store.index') }}" class="px-logo">
          @if(!empty($s->logo_path))
            <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $pxName }}">
          @else
            <span class="px-logo-mark"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span>
            <span>{{ $pxName }}</span>
          @endif
        </a>
        <p>{{ store_text($s, 'footer_text') ?? __('messages.FooterAbout') }}</p>
        @if(!empty($social))
          <div class="px-social">
            @foreach($social as $item)
              @php
                $platform = is_array($item) ? ($item['platform'] ?? '') : '';
                $url = is_array($item) ? ($item['url'] ?? '') : '';
                $iconName = strtolower(trim($platform)); if ($iconName === 'x') $iconName = 'twitter-x';
              @endphp
              @if($platform && $url)
                <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($platform) }}"><x-store.icon :name="$iconName" /></a>
              @endif
            @endforeach
          </div>
        @endif
      </div>

      <nav aria-labelledby="px-f-shop">
        <h2 id="px-f-shop">{{ __('messages.Shop') }}</h2>
        <ul>
          <li><a href="{{ route('store.shop') }}">{{ __('messages.AllProducts') }}</a></li>
          @foreach($pxShopLinks as $mi)
            <li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>
          @endforeach
          <li><a href="{{ route('store.shop', ['deals' => 1]) }}">{{ __('messages.Deals') }}</a></li>
          @if(Route::has('store.compare'))<li><a href="{{ route('store.compare') }}">{{ __('messages.PxCompareProducts') }}</a></li>@endif
        </ul>
      </nav>

      <nav aria-labelledby="px-f-care">
        <h2 id="px-f-care">{{ __('messages.CustomerCare') }}</h2>
        <ul>
          @foreach($pxCareLinks as $mi)
            <li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>
          @endforeach
          @if($s->cookie_consent_enabled ?? true)<li><button type="button" onclick="window.openCookieSettings && window.openCookieSettings()">{{ __('messages.CookieSettings') }}</button></li>@endif
        </ul>
      </nav>

      <nav aria-labelledby="px-f-company">
        <h2 id="px-f-company">{{ __('messages.Company') }}</h2>
        <ul>
          <li><a href="{{ route('store.contact') }}">{{ __('messages.AboutUs') }}</a></li>
          @foreach($pxPages as $pg)
            <li><a href="{{ store_route_or('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>
          @endforeach
          @if($client)<li><a href="{{ $ordersUrl }}">{{ __('messages.Orders') }}</a></li>@endif
        </ul>
      </nav>

      @if($s->contact_phone || $s->contact_email || !empty($pxAddress))
        <div>
          <h2>{{ __('messages.GetInTouch') }}</h2>
          <ul class="px-footer-contact">
            @if($s->contact_phone)<li><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
            @if($s->contact_email)<li><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
            @if(!empty($pxAddress))<li><x-store.icon name="map-pin" /><span>{!! implode('<br>', array_map('e', (array) $pxAddress)) !!}</span></li>@endif
          </ul>
        </div>
      @endif
    </div>

    <div class="px-footer-bottom">
      <span>© {{ date('Y') }} {{ $pxName }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($pxOpts['payment_icons']))
        <span class="px-pay" aria-label="{{ __('messages.SecurePayment') }}">
          <span>{{ __('messages.PxPayCards') }}</span><span>{{ __('messages.PxPayWallets') }}</span><span>{{ __('messages.PxPayCod') }}</span>
        </span>
      @endif
    </div>
  </div>
</footer>
