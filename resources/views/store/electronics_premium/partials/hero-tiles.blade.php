{{-- Promo tiles: the owner's banners, else a top deal + a new arrival.
     Beside the slider (split style) or as a row under it (full style). --}}
@foreach($banners as $b)
  <a href="{{ $b['href'] }}" class="px-tile">
    <span class="px-tile-copy">
      @if(!empty($b['badge']))<span class="px-off">{{ $b['badge'] }}</span>@elseif(!empty($b['kicker']))<span class="px-kicker">{{ $b['kicker'] }}</span>@endif
      <span class="px-tile-title">{{ $b['title'] }}</span>
      @if(!empty($b['subtitle']))<span class="px-tile-sub">{{ $b['subtitle'] }}</span>@endif
      @if(!empty($b['button_text']))<span class="px-link">{{ $b['button_text'] }} →</span>@endif
    </span>
    @if($b['image_url'])<span class="px-tile-media"><img src="{{ $b['image_url'] }}" alt="" loading="lazy"></span>@endif
  </a>
@endforeach
@foreach($tileProducts as $tile)
  @php
    $tp = $tile['p'];
    $tpPrice = (float) ($tp->display_price ?? $tp->price ?? 0);
    $tpWas = isset($tp->compare_at_price) && (float) $tp->compare_at_price > $tpPrice + 0.001 ? (float) $tp->compare_at_price : null;
    $tpPct = $tpWas ? (int) round(($tpWas - $tpPrice) / $tpWas * 100) : 0;
  @endphp
  <a href="{{ product_url($tp->id) }}" class="px-tile">
    <span class="px-tile-copy">
      @if($tile['kind'] === 'deal' && $tpPct > 0)<span class="px-off">-{{ $tpPct }}%</span>@else<span class="px-kicker">{{ $tile['kind'] === 'deal' ? __('messages.Deals') : __('messages.PxJustLanded') }}</span>@endif
      <span class="px-tile-title">{{ \Illuminate\Support\Str::limit($tp->name, 48) }}</span>
      @unless($hidePrices)<span class="px-price"><b style="font-size: 16px">{{ store_money($tpPrice) }}</b>@if($tpWas)<s>{{ store_money($tpWas) }}</s>@endif</span>@endunless
      <span class="px-link">{{ __('messages.ShopNow') }} →</span>
    </span>
    <span class="px-tile-media"><img class="is-product" src="{{ product_image_url($tp->primaryProductImageFilename()) }}" alt="" loading="lazy"></span>
  </a>
@endforeach
