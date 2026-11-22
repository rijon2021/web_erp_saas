{{-- Booking theme footer: brand + footer text, opening hours, links, contact. --}}
@php
  $bkOpts = $electronicsOpts ?? \App\Support\BookingTheme::options($s);
  $bkStoreName = $s->store_name ?: __('messages.Store');
  $bkHome = route('store.index');
  $bkFooterHours = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $bkOpts['opening_hours']))));
  if (! $bkFooterHours && \App\Services\Booking\BookingWebsiteService::available()) {
      $bkFooterHours = collect(app(\App\Services\Booking\BookingWebsiteService::class)->openingHours())
          ->filter(fn ($r) => $r['hours'])->map(fn ($r) => $r['day'].': '.$r['hours'])->values()->all();
  }
  $bkPages = \Illuminate\Support\Facades\Schema::hasTable('store_pages') ? \App\Models\StorePage::query()->where('published', true)->orderBy('title')->take(6)->get(['id', 'title', 'slug']) : collect();
@endphp
<footer class="bk-footer store-footer">
  <div class="bk-container">
    <div class="bk-footer-grid">
      <div>
        <div class="bk-footer-brand">{{ $bkStoreName }}</div>
        <p>{{ store_text($s, 'footer_text') ?? '' }}</p>
        @if(!empty($social))
          <div class="bk-social">
            @foreach($social as $item)
              @php $platform = strtolower(trim(is_array($item) ? ($item['platform'] ?? '') : '')); $url = is_array($item) ? ($item['url'] ?? '') : ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
              @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <h4>{{ __('messages.BkmOpeningHours') }}</h4>
        @if($bkFooterHours)
          <ul>@foreach($bkFooterHours as $line)<li>{{ $line }}</li>@endforeach</ul>
        @else
          <p>{{ __('messages.BkmCallUs') }}</p>
        @endif
      </div>
      <div>
        <h4>{{ __('messages.BkmExplore') }}</h4>
        <ul>
          @if(Route::has('store.booking.book'))<li><a href="{{ route('store.booking.book') }}">{{ __('messages.BkmBookNow') }}</a></li>@endif
          @if($bkOpts['sections']['services'])<li><a href="{{ $bkHome }}#services">{{ __('messages.BkmServices') }}</a></li>@endif
          @foreach(($footerSupportMenu ?? []) as $mi)<li><a href="{{ $mi['url'] }}">{{ $mi['label'] }}</a></li>@endforeach
          @foreach($bkPages as $pg)<li><a href="{{ store_route_or('store.page', $pg->slug) }}">{{ $pg->title }}</a></li>@endforeach
          @if($s->cookie_consent_enabled ?? true)<li><a href="#" onclick="window.openCookieSettings && window.openCookieSettings(); return false;">{{ __('messages.CookieSettings') }}</a></li>@endif
        </ul>
      </div>
      <div>
        <h4>{{ __('messages.GetInTouch') }}</h4>
        <ul>
          @if($s->contact_phone)<li><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($bkOpts['whatsapp'] !== '')<li><a href="https://wa.me/{{ $bkOpts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></li>@endif
          @if($s->contact_email)<li><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
          @if($s->contact_address)<li>{{ $s->contact_address }}</li>@endif
        </ul>
      </div>
    </div>
    <div class="bk-footer-bottom">
      <span>© {{ date('Y') }} {{ $bkStoreName }}. {{ __('messages.AllRightsReserved') }}</span>
      @if(!empty($bkOpts['payment_icons']))
        <span aria-label="{{ __('messages.SecurePayment') }}">VISA · MASTERCARD · PAYPAL</span>
      @endif
    </div>
  </div>
</footer>
