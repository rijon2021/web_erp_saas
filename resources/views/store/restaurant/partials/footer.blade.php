{{-- Restaurant theme footer. --}}
@php
  $rsOpts = $electronicsOpts ?? \App\Support\RestaurantTheme::options($s);
  $storeNameRs = $s->store_name ?: __('messages.Store');
  $homeUrlRs = route('store.index');
  $hoursRs = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $rsOpts['opening_hours']))));
  $linksRs = $footerShopMenu ?: [
      ['label' => __('messages.RstMenu'), 'url' => $homeUrlRs.'#menu'],
      ['label' => __('messages.RstVisitUs'), 'url' => $homeUrlRs.'#visit'],
      ['label' => __('messages.ContactUs'), 'url' => route('store.contact')],
  ];
  $pagesRs = \Illuminate\Support\Facades\Schema::hasTable('store_pages') ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug']) : collect();
@endphp
<footer class="rs-footer store-footer">
  <div class="rs-container">
    <div class="rs-footer-grid">
      <div>
        <div class="rs-footer-brand">{{ $storeNameRs }}</div>
        <p>{{ store_text($s, 'footer_text') ?? '' }}</p>
        @if(!empty($social))
          <div class="rs-social">
            @foreach($social as $item)
              @php $platform = strtolower(trim(is_array($item) ? ($item['platform'] ?? '') : '')); $url = is_array($item) ? ($item['url'] ?? '') : ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
              @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <h4>{{ __('messages.RstOpeningHours') }}</h4>
        @if($hoursRs)
          <ul>@foreach($hoursRs as $line)<li>{{ $line }}</li>@endforeach</ul>
        @else
          <p>{{ __('messages.ContactUs') }}</p>
        @endif
      </div>
      <div>
        <h4>{{ __('messages.RstExplore') }}</h4>
        <ul>
          @foreach($linksRs as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach(($footerSupportMenu ?? []) as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach($pagesRs as $pg)<li><a href="{{ store_route_or('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>@endforeach
          @if($s->cookie_consent_enabled ?? true)<li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h4>{{ __('messages.GetInTouch') }}</h4>
        <ul>
          @if($s->contact_phone)<li><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($rsOpts['whatsapp'] !== '')<li><a href="https://wa.me/{{ $rsOpts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></li>@endif
          @if($s->contact_email)<li><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_address)<li>{{ $s->contact_address }}</li>@endif
        </ul>
      </div>
    </div>
    <div class="rs-footer-bottom">
      <span>© {{ date('Y') }} {{ $storeNameRs }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($rsOpts['payment_icons']))
        <span aria-label="{{ __('messages.SecurePayment') }}">VISA · MASTERCARD · PAYPAL · {{ __('messages.CashOnDelivery') }}</span>
      @endif
    </div>
  </div>
</footer>
