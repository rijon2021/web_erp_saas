{{-- Rental theme footer: brand + about line, opening hours, browse links,
     contact. --}}
@php
  $rnOpts = $electronicsOpts ?? \App\Support\RentalTheme::options($s);
  $rnStoreName = $s->store_name ?: __('messages.Store');
  $rnShop = store_route_or('store.shop');
  $rnHours = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $rnOpts['opening_hours']))));
  $rnFooterTypes = \App\Services\Rental\RentalWebsiteService::available() ? \App\Support\RentalTypes::enabled() : [];
  $rnLinks = $footerShopMenu ?: (count($rnFooterTypes) > 1
      ? array_map(fn ($t) => ['label' => \App\Support\RentalTypes::typeLabel($t), 'url' => $rnShop.'?type='.$t], $rnFooterTypes)
      : [['label' => __('messages.RntBrowse'), 'url' => $rnShop]]);
  $rnPages = \Illuminate\Support\Facades\Schema::hasTable('store_pages') ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug']) : collect();
@endphp
<footer class="rn-footer store-footer">
  <div class="rn-container">
    <div class="rn-footer-grid">
      <div>
        <div class="rn-footer-brand">{{ $rnStoreName }}</div>
        <p>{{ store_text($s, 'footer_text') ?? '' }}</p>
        @if(!empty($social))
          <div class="rn-social">
            @foreach($social as $item)
              @php $platform = strtolower(trim(is_array($item) ? ($item['platform'] ?? '') : '')); $url = is_array($item) ? ($item['url'] ?? '') : ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
              @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <h4>{{ __('messages.RntOpeningHours') }}</h4>
        @if($rnHours)
          <ul>@foreach($rnHours as $line)<li>{{ $line }}</li>@endforeach</ul>
        @else
          <p>{{ __('messages.RntCallUs') }}</p>
        @endif
      </div>
      <div>
        <h4>{{ __('messages.RntExplore') }}</h4>
        <ul>
          @foreach($rnLinks as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach(($footerSupportMenu ?? []) as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach($rnPages as $pg)<li><a href="{{ store_route_or('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>@endforeach
          @if($s->cookie_consent_enabled ?? true)<li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h4>{{ __('messages.GetInTouch') }}</h4>
        <ul>
          @if($s->contact_phone)<li><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($rnOpts['whatsapp'] !== '')<li><a href="https://wa.me/{{ $rnOpts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></li>@endif
          @if($s->contact_email)<li><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_address)<li>{{ $s->contact_address }}</li>@endif
        </ul>
      </div>
    </div>
    <div class="rn-footer-bottom">
      <span>© {{ date('Y') }} {{ $rnStoreName }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($rnOpts['payment_icons']))
        <span aria-label="{{ __('messages.SecurePayment') }}">VISA · MASTERCARD · PAYPAL</span>
      @endif
    </div>
  </div>
</footer>
