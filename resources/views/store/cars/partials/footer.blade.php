{{-- Car Dealership theme footer. --}}
@php
  $cdOpts = $electronicsOpts ?? \App\Support\CarsTheme::options($s);
  $storeNameCd = $s->store_name ?: __('messages.Store');
  $homeUrlCd = route('store.index');
  $carLinks = $footerShopMenu ?: [
      ['label' => __('messages.CarAllInventory'), 'url' => route('store.shop')],
      ['label' => __('messages.CarNewCars'), 'url' => route('store.shop', ['condition' => 'new'])],
      ['label' => __('messages.CarUsedCars'), 'url' => route('store.shop', ['condition' => 'used'])],
      ['label' => __('messages.CarRecentlyAdded'), 'url' => route('store.shop', ['sort' => 'latest'])],
  ];
  $serviceLinks = $footerSupportMenu ?: [
      ['label' => __('messages.CarFinance'), 'url' => $homeUrlCd.'#finance'],
      ['label' => __('messages.CarSellYourCar'), 'url' => $homeUrlCd.'#trade-in'],
      ['label' => __('messages.CarBookTestDrive'), 'url' => route('store.shop')],
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
  ];
  $pagesCd = \Illuminate\Support\Facades\Schema::hasTable('store_pages') ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug']) : collect();
@endphp
<footer class="cd-footer store-footer">
  <div class="cd-container">
    <div class="cd-footer-grid">
      <div class="cd-footer-brand">
        <a href="{{ $homeUrlCd }}" class="cd-logo">
          @if(!empty($s->logo_path))<img src="{{ $assetPath($s->logo_path) }}" alt="{{ $storeNameCd }}">@else<span class="cd-logo-mark"><x-store.icon name="car" /></span><span>{{ $storeNameCd }}</span>@endif
        </a>
        <p>{{ store_text($s, 'footer_text') ?? __('messages.FooterAbout') }}</p>
        @if(!empty($social))
          <div class="cd-social">
            @foreach($social as $item)
              @php $platform = strtolower(trim(is_array($item) ? ($item['platform'] ?? '') : '')); $url = is_array($item) ? ($item['url'] ?? '') : ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
              @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <h6>{{ __('messages.CarCars') }}</h6>
        <ul>@foreach($carLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach</ul>
      </div>
      <div>
        <h6>{{ __('messages.CarServices') }}</h6>
        <ul>
          @foreach($serviceLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach($pagesCd as $pg)<li><a href="{{ store_route_or('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>@endforeach
          @if($s->cookie_consent_enabled ?? true)<li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h6>{{ __('messages.GetInTouch') }}</h6>
        <ul class="cd-footer-contact">
          @if($s->contact_phone)<li><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($cdOpts['whatsapp'] !== '')<li><x-store.icon name="whatsapp" /><a href="https://wa.me/{{ $cdOpts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></li>@endif
          @if($s->contact_email)<li><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_address)<li><x-store.icon name="map-pin" /><span>{{ $s->contact_address }}</span></li>@endif
          @if($cdOpts['opening_hours'] !== '')<li><x-store.icon name="clock" /><span class="cd-hours">{{ $cdOpts['opening_hours'] }}</span></li>@endif
        </ul>
      </div>
    </div>
    <div class="cd-footer-bottom">
      <span>© {{ date('Y') }} {{ $storeNameCd }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($cdOpts['payment_icons']))
        <div class="cd-pay" aria-label="{{ __('messages.SecurePayment') }}"><span>VISA</span><span>MASTERCARD</span><span>PAYPAL</span><span>{{ __('messages.CashOnDelivery') }}</span></div>
      @endif
    </div>
  </div>
</footer>
