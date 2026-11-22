@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $nlBtn = __('messages.Subscribe');
  $hidePrices = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
  $pxHasCompare = Route::has('store.compare');
  $pickTabs = collect([
      'top_picks' => __('messages.PxTabTrending'),
      'new_arrivals' => __('messages.NewArrivals'),
      'best_sellers' => __('messages.BestSellers'),
      'top_rated' => __('messages.PxTabTopRated'),
  ])->filter(fn ($label, $key) => isset($picks[$key]));
  $firstTab = $pickTabs->keys()->first();
  $star = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9 6.8 19.6l1-5.8L3.5 9.7l5.9-.9z"/></svg>';
  // Full-image slider (default) needs the owner's slides; without any, the
  // featured-product hero of the split layout is used.
  $pxFull = ($opts['hero_style'] ?? 'full') === 'full' && $slides->isNotEmpty();
@endphp

{{-- Outer wrapper holds the video dialog state for both the full-width
     slider (outside the centred container) and the sections below. --}}
<div class="px-home" x-data="{ video: null }" @keydown.escape.window="video = null">

@if($pxFull)
  @if(($opts['hero_width'] ?? 'edge') === 'contained')
    <div class="px-container">@include('store.electronics_premium.partials.hero-full')</div>
  @else
    @include('store.electronics_premium.partials.hero-full')
  @endif
@endif

<div class="px-container">

  {{-- ===== Hero: full-image slider above → its promo tiles as a row ===== --}}
  @if($pxFull)
    @if($banners->isNotEmpty() || $tileProducts->isNotEmpty())
      <section class="px-tiles px-tiles--row" aria-label="{{ __('messages.PxFeatured') }}">
        @include('store.electronics_premium.partials.hero-tiles')
      </section>
    @endif
  {{-- ===== Hero bento (split style) ===== --}}
  @elseif($slides->isNotEmpty() || $heroProduct || $banners->isNotEmpty() || $tileProducts->isNotEmpty())
    <section class="px-bento" aria-label="{{ __('messages.PxFeatured') }}">
      @if($slides->isNotEmpty())
        <div class="px-hero"
             x-data="{ i: 0, n: {{ $slides->count() }}, t: null,
                       go(k) { this.i = (k + this.n) % this.n; this.restart(); },
                       restart() { clearInterval(this.t); if (this.n > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) this.t = setInterval(() => { this.i = (this.i + 1) % this.n; }, 7000); } }"
             x-init="restart()" @mouseenter="clearInterval(t)" @mouseleave="restart()" @focusin="clearInterval(t)">
          @foreach($slides as $k => $slide)
            <div class="px-slide {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }" role="group" aria-roledescription="slide" aria-label="{{ $k + 1 }} / {{ $slides->count() }}">
              <div class="px-hero-copy">
                @if(!empty($slide['kicker']))<span class="px-kicker">{{ $slide['kicker'] }}</span>@endif
                @if($k === 0)
                  <h1 class="px-hero-title">{{ $slide['title'] ?: store_text($s, 'hero_title') }}</h1>
                @else
                  <h2 class="px-hero-title">{{ $slide['title'] ?: store_text($s, 'hero_title') }}</h2>
                @endif
                @if(!empty($slide['subtitle']))<p class="px-hero-sub">{{ $slide['subtitle'] }}</p>@endif
                <div class="px-hero-actions">
                  @if(!empty($slide['primary_text']))<a href="{{ $slide['primary_href'] }}" class="px-btn px-btn-primary">{{ $slide['primary_text'] }}</a>@endif
                  @if($slide['secondary_href'])<a href="{{ $slide['secondary_href'] }}" class="px-btn px-btn-ghost">{{ $slide['secondary_text'] }}</a>@endif
                </div>
              </div>
              @if($slide['image_url'] || $slide['video_link'])
                <div class="px-hero-media">
                  @if($slide['image_url'])
                    <img src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] ?? '' }}" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" @if($k === 0) fetchpriority="high" @endif>
                  @endif
                  @if($slide['video_embed'])
                    <button type="button" class="px-video-btn" @click="video = @js($slide['video_embed'])"><span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 3l15 9-15 9z"/></svg></span>{{ __('messages.PxWatchVideo') }}</button>
                  @elseif($slide['video_link'])
                    <a href="{{ $slide['video_link'] }}" target="_blank" rel="noopener" class="px-video-btn"><span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 3l15 9-15 9z"/></svg></span>{{ __('messages.PxWatchVideo') }}</a>
                  @endif
                </div>
              @endif
            </div>
          @endforeach
          @if($slides->count() > 1)
            <div class="px-hero-nav">
              @foreach($slides as $k => $slide)
                <button type="button" class="px-dot {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }" @click="go({{ $k }})" aria-label="{{ __('messages.PxSlideN', ['n' => $k + 1]) }}" :aria-current="(i === {{ $k }}).toString()"></button>
              @endforeach
              <span class="px-count-label" x-text="String(i + 1).padStart(2, '0') + ' / ' + String(n).padStart(2, '0')">01 / {{ str_pad((string) $slides->count(), 2, '0', STR_PAD_LEFT) }}</span>
            </div>
          @endif
        </div>
      @elseif($heroProduct)
        @php
          $hp = $heroProduct;
          $hpPrice = (float) ($hp->display_price ?? $hp->price ?? 0);
          $hpWas = isset($hp->compare_at_price) && (float) $hp->compare_at_price > $hpPrice + 0.001 ? (float) $hp->compare_at_price : null;
          $hpBrand = $hp->relationLoaded('brand') ? $hp->brand?->name : null;
          $hpSpecs = \App\Support\StoreSpecs::highlights($hp, 3);
        @endphp
        <div class="px-hero">
          <div class="px-slide is-active">
            <div class="px-hero-copy">
              <span class="px-kicker">{{ $hpBrand ?: __('messages.PxFeatured') }}</span>
              <h1 class="px-hero-title">{{ $hp->name }}</h1>
              @if($hpSpecs)
                <p class="px-hero-sub">{{ collect($hpSpecs)->map(fn ($r) => $r['value'])->implode(' · ') }}</p>
              @elseif(trim(strip_tags((string) $hp->note)) !== '')
                <p class="px-hero-sub">{{ \Illuminate\Support\Str::limit(trim(strip_tags((string) $hp->note)), 140) }}</p>
              @endif
              @unless($hidePrices)
                <div class="px-hero-price"><b>{{ store_money($hpPrice) }}</b>@if($hpWas)<s>{{ store_money($hpWas) }}</s>@endif</div>
              @endunless
              <div class="px-hero-actions">
                <a href="{{ product_url($hp->id) }}" class="px-btn px-btn-primary">{{ __('messages.ShopNow') }}</a>
                <a href="{{ route('store.shop') }}" class="px-btn px-btn-ghost">{{ __('messages.PxBrowseAll') }}</a>
              </div>
            </div>
            <a href="{{ product_url($hp->id) }}" class="px-hero-media" tabindex="-1" aria-hidden="true">
              <img class="is-product" src="{{ product_image_url($hp->primaryProductImageFilename()) }}" alt="" fetchpriority="high">
              @if(!empty($hp->rating_count))
                <span class="px-chip">{!! $star !!}{{ number_format((float) $hp->rating_avg, 1) }} · {{ trans_choice('messages.PxReviewsCount', (int) $hp->rating_count, ['count' => \App\Support\ElectronicsTheme::compact((int) $hp->rating_count)]) }}</span>
              @endif
            </a>
          </div>
        </div>
      @endif

      @if($banners->isNotEmpty() || $tileProducts->isNotEmpty())
        <div class="px-tiles">
          @include('store.electronics_premium.partials.hero-tiles')
        </div>
      @endif
    </section>
  @else
    <section class="px-hero px-empty-hero" style="margin-top: 20px">
      <h1 class="px-hero-title">{{ store_text($s, 'hero_title') ?: $s->store_name }}</h1>
      @if(store_text($s, 'hero_subtitle'))<p class="px-hero-sub" style="margin-top: 12px">{{ store_text($s, 'hero_subtitle') }}</p>@endif
    </section>
  @endif

  {{-- ===== Trust strip ===== --}}
  @if($sections['trust'] && count($opts['trust']))
    <section class="px-trust" aria-label="{{ __('messages.PxWhyShop') }}">
      @foreach($opts['trust'] as $item)
        <div class="px-trust-item">
          <span class="px-trust-icon"><x-store.icon :name="$item['icon'] ?: 'check-circle'" fallback="check-circle" /></span>
          <span><strong>{{ $item['title'] }}</strong>@if(!empty($item['subtitle']))<span>{{ $item['subtitle'] }}</span>@endif</span>
        </div>
      @endforeach
    </section>
  @endif

  {{-- ===== Deals of the day ===== --}}
  @if($sections['deals'] && $deals['products']->isNotEmpty())
    <section class="px-section" aria-labelledby="px-deals-h">
      <div class="px-head">
        <div>
          <h2 id="px-deals-h" class="px-h2"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>{{ $deals['title'] ?: __('messages.PxDealsTitle') }}</h2>
          <p class="px-sub">{{ $deals['ends_at'] ? __('messages.PxDealsSubTimed') : __('messages.PxDealsSub') }}</p>
        </div>
        <div class="px-countdown">
          @if($deals['ends_at'])
            <span>{{ __('messages.PxEndsIn') }}</span>
            <span class="px-count" x-data="pxCountdown(@js($deals['ends_at']->toIso8601String()))" role="timer" aria-live="off">
              <template x-if="d > 0"><b x-text="d + @js(__('messages.PxDayShort'))"></b></template>
              <b x-text="hh">--</b>:<b x-text="mm">--</b>:<b x-text="ss">--</b>
            </span>
          @endif
          <a href="{{ $deals['ends_at'] && Route::has('store.flash_sales') ? route('store.flash_sales') : route('store.shop', ['deals' => 1]) }}" class="px-link">{{ __('messages.PxViewAllDeals') }} →</a>
        </div>
      </div>
      <div class="px-grid">
        @foreach($deals['products'] as $p)
          @include('store.electronics_premium.partials.product-card', ['p' => $p, 'currency' => $currency, 'showSold' => true, 'pxHasCompare' => $pxHasCompare])
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Shop by category ===== --}}
  @if($sections['categories'] && $categoryTiles->isNotEmpty())
    <section class="px-section" aria-labelledby="px-cat-h">
      <div class="px-head">
        <h2 id="px-cat-h" class="px-h2">{{ __('messages.ShopByCategory') }}</h2>
        <a href="{{ route('store.shop') }}" class="px-link">{{ __('messages.ViewAllCategories') }} →</a>
      </div>
      <div class="px-cats">
        @foreach($categoryTiles as $tile)
          <a href="{{ $tile['url'] }}" class="px-cat">
            <span class="px-cat-media">
              @if($tile['image_url'])<img src="{{ $tile['image_url'] }}" alt="" loading="lazy">@else<x-store.icon :name="$tile['icon'] ?: 'grid'" fallback="grid" />@endif
            </span>
            <strong>{{ $tile['name'] }}</strong>
            <small>{{ trans_choice('messages.products', $tile['count'], ['count' => $tile['count']]) }}</small>
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Picked for you (tabs) ===== --}}
  @if($sections['picks'] && $pickTabs->isNotEmpty())
    <section class="px-section" aria-labelledby="px-picks-h" x-data="{ tab: '{{ $firstTab }}' }">
      <div class="px-head">
        <h2 id="px-picks-h" class="px-h2">{{ __('messages.PxPickedForYou') }}</h2>
        @if($pickTabs->count() > 1)
          <div class="px-tabs" role="tablist" aria-labelledby="px-picks-h">
            @foreach($pickTabs as $key => $label)
              <button type="button" role="tab" id="px-tab-{{ $key }}" class="px-tab" aria-controls="px-panel-{{ $key }}"
                      aria-selected="{{ $key === $firstTab ? 'true' : 'false' }}" :aria-selected="(tab === '{{ $key }}').toString()"
                      @click="tab = '{{ $key }}'">{{ $label }}</button>
            @endforeach
          </div>
        @endif
      </div>
      @foreach($pickTabs as $key => $label)
        <div class="px-grid" id="px-panel-{{ $key }}" role="tabpanel" aria-labelledby="px-tab-{{ $key }}" x-show="tab === '{{ $key }}'" {{ $key === $firstTab ? '' : 'x-cloak' }}>
          @foreach($picks[$key] as $p)
            @include('store.electronics_premium.partials.product-card', ['p' => $p, 'currency' => $currency, 'pxHasCompare' => $pxHasCompare])
          @endforeach
        </div>
      @endforeach
    </section>
  @endif

  {{-- ===== Compare promo ===== --}}
  @if($sections['compare'] && $pxHasCompare)
    <section class="px-section" aria-labelledby="px-cmp-h">
      <div class="px-cmp">
        <div class="px-cmp-copy">
          <span class="px-kicker">{{ __('messages.PxSideBySide') }}</span>
          <h2 id="px-cmp-h" class="px-h2">{{ __('messages.PxCompareTitle') }}</h2>
          <p>{{ __('messages.PxCompareText') }}</p>
          <a href="{{ route('store.compare') }}" class="px-btn px-btn-primary">{{ __('messages.PxOpenCompare') }}</a>
        </div>
        <ol class="px-cmp-steps">
          <li>{{ __('messages.PxCompareStep1') }}</li>
          <li>{{ __('messages.PxCompareStep2') }}</li>
          <li>{{ __('messages.PxCompareStep3') }}</li>
        </ol>
      </div>
    </section>
  @endif

  {{-- ===== Brands ===== --}}
  @if($sections['brands'] && $brands->isNotEmpty())
    <section class="px-section" aria-labelledby="px-brand-h">
      <h2 id="px-brand-h" class="px-label">{{ __('messages.ShopByBrand') }}</h2>
      <div class="px-brands">
        @foreach($brands as $b)
          <a href="{{ route('store.shop', ['brand' => $b->id]) }}" class="px-brand" title="{{ $b->name }}">
            @if($b->image && $b->image !== 'no-image.png')
              <img src="{{ global_asset(upload_path('brands').'/'.$b->image) }}" alt="{{ $b->name }}" loading="lazy" onerror="this.replaceWith(document.createTextNode(this.alt))">
            @else
              {{ $b->name }}
            @endif
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Recently viewed (this browser) ===== --}}
  @if($sections['recent'])
    <section class="px-section" aria-labelledby="px-recent-h" x-data="pxRecent()" x-show="items.length" x-cloak>
      <div class="px-head" style="align-items: center">
        <h2 id="px-recent-h" class="px-h2" style="font-size: 24px">{{ __('messages.PxRecentlyViewed') }}</h2>
        <button type="button" class="px-clear" @click="clear()">{{ __('messages.PxClearHistory') }}</button>
      </div>
      <div class="px-recent">
        <template x-for="r in items" :key="r.id">
          <a :href="r.url">
            <img :src="r.image" alt="" loading="lazy">
            <span><b x-text="r.name"></b><small x-text="r.price"></small></span>
          </a>
        </template>
      </div>
    </section>
  @endif

  {{-- ===== Newsletter ===== --}}
  @if($sections['newsletter'])
    <section class="px-section" aria-labelledby="px-nl-h">
      <div class="px-nl">
        <div style="flex: 1 1 340px">
          <h2 id="px-nl-h">{{ $opts['newsletter_title'] }}</h2>
          <p>{{ $opts['newsletter_subtitle'] }}</p>
        </div>
        <form id="newsletterForm">
          @csrf
          <label class="px-sr" for="newsletterEmail">{{ __('messages.Email') }}</label>
          <input name="email" type="email" id="newsletterEmail" placeholder="{{ __('messages.NewsletterEmailPlaceholder') }}" autocomplete="email" required>
          <button id="newsletterBtn" class="px-btn px-btn-primary" type="submit">{{ $nlBtn }}</button>
          <div id="newsletterMsg" class="px-nl-note" role="status">{{ __('messages.NoSpam') }}</div>
        </form>
      </div>
    </section>
  @endif

  {{-- Video dialog (hero "Watch the video") --}}
  <template x-if="video">
    <div class="px-video-dialog" role="dialog" aria-modal="true" aria-label="{{ __('messages.PxWatchVideo') }}" @click.self="video = null">
      <div>
        <button type="button" class="px-video-close" @click="video = null" aria-label="{{ __('messages.Close') }}" x-init="$nextTick(() => $el.focus())"><x-store.icon name="x" /></button>
        <iframe :src="video" title="{{ __('messages.PxWatchVideo') }}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
      </div>
    </div>
  </template>
</div>
</div>{{-- /.px-home --}}

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
