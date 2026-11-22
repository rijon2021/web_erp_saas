{{-- Fashion & Apparel theme footer. --}}
@php
  $faOpts = $electronicsOpts ?? \App\Support\FashionTheme::options($s);
  $storeNameFa = $s->store_name ?: __('messages.Store');
  $shopLinks = $footerShopMenu ?: collect($categories->take(5))->map(fn ($c) => ['label' => $c->name, 'url' => route('store.shop', ['category' => $c->id])])->all();
  $careLinks = $footerSupportMenu ?: [
      ['label' => __('messages.TrackOrder'), 'url' => $client ? route('account.orders') : $loginUrl],
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
  ];
  $pages = \Illuminate\Support\Facades\Schema::hasTable('store_pages') ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug']) : collect();
@endphp
<footer class="fa-footer store-footer">
  <div class="fa-container">
    <div class="fa-footer-grid">
      <div class="fa-footer-brand">
        <a href="{{ route('store.index') }}" class="fa-logo">
          @if(!empty($s->logo_path))<img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameFa }}">@else{{ $storeNameFa }}@endif
        </a>
        <p>{{ store_text($s, 'footer_text') ?? __('messages.FooterAbout') }}</p>
        @if(!empty($social))
          <div class="fa-social">
            @foreach($social as $item)
              @php $platform = strtolower(trim(is_array($item) ? ($item['platform'] ?? '') : '')); $url = is_array($item) ? ($item['url'] ?? '') : ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
              @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <h6>{{ __('messages.Shop') }}</h6>
        <ul>
          <li><a href="{{ route('store.shop', ['sort' => 'latest']) }}">{{ __('messages.NewArrivals') }}</a></li>
          @foreach($shopLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @if(Route::has('store.flash_sales'))<li><a href="{{ route('store.flash_sales') }}">{{ __('messages.FashionSale') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h6>{{ __('messages.CustomerCare') }}</h6>
        <ul>
          @foreach($careLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach($pages as $pg)<li><a href="{{ store_route_or('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>@endforeach
          @if($s->cookie_consent_enabled ?? true)<li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h6>{{ __('messages.GetInTouch') }}</h6>
        <ul class="fa-footer-contact">
          @if($s->contact_phone)<li><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($s->contact_email)<li><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_address)<li><x-store.icon name="map-pin" /><span>{{ $s->contact_address }}</span></li>@endif
          <li><a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a></li>
        </ul>
      </div>
    </div>
    <div class="fa-footer-bottom">
      <span>© {{ date('Y') }} {{ $storeNameFa }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($faOpts['payment_icons']))
        <div class="fa-pay" aria-label="{{ __('messages.SecurePayment') }}">
          <span>VISA</span><span>MASTERCARD</span><span>PAYPAL</span><span>{{ __('messages.CashOnDelivery') }}</span>
        </div>
      @endif
    </div>
  </div>
</footer>
