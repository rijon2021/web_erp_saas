@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $nlBtn = __('messages.Subscribe');
  $faOpts = $opts;
  $cardCols = fn ($n) => max(2, min(4, $n));
@endphp

{{-- ===== Hero: copy panel + photo, optional slider ===== --}}
@if($slides->isNotEmpty())
  <section class="fa-hero" x-data="{ i: 0, n: {{ $slides->count() }}, t: null, go(k) { this.i = (k + this.n) % this.n; this.restart(); }, restart() { clearInterval(this.t); if (this.n > 1) this.t = setInterval(() => { this.i = (this.i + 1) % this.n; }, 8000); } }" x-init="restart()">
    @foreach($slides as $k => $slide)
      <div class="fa-slide {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }">
        <div class="fa-hero-copy">
          @if(!empty($slide['kicker']))<span class="fa-kicker" style="margin: 0">{{ $slide['kicker'] }}</span>@endif
          <h1 class="fa-hero-title">{{ $slide['title'] ?: store_text($s, 'hero_title') }}</h1>
          @if(!empty($slide['subtitle']))<p class="fa-hero-sub">{{ $slide['subtitle'] }}</p>@endif
          <div class="fa-hero-actions">
            @if(!empty($slide['primary_text']))<a href="{{ $slide['primary_href'] }}" class="fa-btn">{{ $slide['primary_text'] }}</a>@endif
            @if(!empty($slide['secondary_text']))<a href="{{ $slide['secondary_href'] }}" class="fa-btn fa-btn-ghost">{{ $slide['secondary_text'] }}</a>@endif
          </div>
          @if($slides->count() > 1)
            <div class="fa-hero-nav">
              <b x-text="String(i + 1).padStart(2, '0')">01</b><span class="fa-bar"></span><span>{{ str_pad((string) $slides->count(), 2, '0', STR_PAD_LEFT) }}</span>
              <button type="button" @click="go(i - 1)" aria-label="{{ __('messages.Previous') }}"><x-store.icon name="chevron-left" class="store-ico-flip" /></button>
              <button type="button" @click="go(i + 1)" aria-label="{{ __('messages.Next') }}"><x-store.icon name="chevron-right" class="store-ico-flip" /></button>
            </div>
          @endif
        </div>
        <div class="fa-hero-media">
          @if(!empty($slide['image_url']))<img src="{{ $slide['image_url'] }}" alt="" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $k === 0 ? 'high' : 'auto' }}">@endif
        </div>
      </div>
    @endforeach
  </section>
@endif

<div class="fa-container">

  {{-- ===== Shop by category ===== --}}
  @if($sections['categories'] && $tiles->isNotEmpty())
    <section class="fa-section">
      <div class="fa-head">
        <div><span class="fa-kicker">{{ __('messages.ShopByCategory') }}</span><h2 class="fa-h2">{{ __('messages.FashionFindYourEdit') }}</h2></div>
        <a href="{{ route('store.shop') }}" class="fa-link">{{ __('messages.ViewAllCategories') }}</a>
      </div>
      <div class="fa-cats" style="--fa-cat-cols: {{ max(2, min(6, $tiles->count())) }}">
        @foreach($tiles as $t)
          <a href="{{ $t['href'] }}" class="fa-cat">
            @if(!empty($t['image_url']))<img src="{{ $t['image_url'] }}" alt="{{ $t['title'] }}" loading="lazy">@endif
            <span class="fa-cat-label"><strong>{{ $t['title'] }}</strong>@if(!empty($t['subtitle']))<small>{{ $t['subtitle'] }}</small>@endif</span>
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== New arrivals (category tabs filter in place) ===== --}}
  @if($sections['new_arrivals'] && $newArrivals->isNotEmpty())
    <section class="fa-section" x-data="{ tab: 0 }">
      <div class="fa-head">
        <div><span class="fa-kicker">{{ __('messages.FashionJustLanded') }}</span><h2 class="fa-h2">{{ $opts['new_arrivals_title'] }}</h2></div>
        @if($arrivalTabs->isNotEmpty())
          <div class="fa-tabs" role="tablist">
            <button type="button" class="fa-tab" role="tab" :class="{ 'is-active': tab === 0 }" :aria-selected="(tab === 0).toString()" @click="tab = 0">{{ __('messages.All') }}</button>
            @foreach($arrivalTabs as $tab)
              <button type="button" class="fa-tab" role="tab" :class="{ 'is-active': tab === {{ $tab['id'] }} }" :aria-selected="(tab === {{ $tab['id'] }}).toString()" @click="tab = {{ $tab['id'] }}">{{ $tab['name'] }}</button>
            @endforeach
          </div>
        @endif
      </div>
      <div class="fa-grid" style="--fa-cols: {{ $cardCols($opts['limits']['new_arrivals']) }}">
        @foreach($newArrivals as $p)
          <div x-show="tab === 0 || tab === {{ (int) $p->category_id }}">@include('store.fashion.partials.product-card', ['p' => $p, 'currency' => $currency])</div>
        @endforeach
      </div>
      <div style="display: flex; justify-content: center; margin-top: 48px">
        <a href="{{ route('store.shop', ['sort' => 'latest']) }}" class="fa-btn fa-btn-ghost">{{ __('messages.ViewAll') }}</a>
      </div>
    </section>
  @endif

</div>

{{-- ===== Editorial / lookbook (full bleed) ===== --}}
@if($sections['editorial'] && $banners->isNotEmpty())
  <section class="fa-section">
    @foreach($banners as $k => $b)
      <div class="fa-edit {{ $k % 2 ? 'is-flip' : '' }}">
        <div class="fa-edit-media">@if(!empty($b['image_url']))<img src="{{ $b['image_url'] }}" alt="{{ $b['title'] }}" loading="lazy">@endif</div>
        <div class="fa-edit-copy">
          @if(!empty($b['kicker']))<span class="fa-kicker">{{ $b['kicker'] }}</span>@endif
          @if(!empty($b['title']))<h2>{{ $b['title'] }}</h2>@endif
          @if(!empty($b['subtitle']))<p>{{ $b['subtitle'] }}</p>@endif
          @if(!empty($b['button_text']))<div><a href="{{ $b['href'] }}" class="fa-btn fa-btn-light">{{ $b['button_text'] }}</a></div>@endif
        </div>
      </div>
    @endforeach
  </section>
@endif

<div class="fa-container">

  {{-- ===== Sale ===== --}}
  @if($sections['sale'] && $sale->isNotEmpty())
    <section class="fa-section">
      <div class="fa-head">
        <div>@if($opts['sale_kicker'] !== '')<span class="fa-kicker" style="color: var(--fa-accent)">{{ $opts['sale_kicker'] }}</span>@endif<h2 class="fa-h2">{{ $opts['sale_title'] }}</h2></div>
        <a href="{{ Route::has('store.flash_sales') ? route('store.flash_sales') : route('store.shop') }}" class="fa-link">{{ __('messages.ViewAll') }}</a>
      </div>
      <div class="fa-grid" style="--fa-cols: {{ $cardCols($opts['limits']['sale']) }}">
        @foreach($sale as $p)@include('store.fashion.partials.product-card', ['p' => $p, 'currency' => $currency])@endforeach
      </div>
    </section>
  @endif

  {{-- ===== Best sellers ===== --}}
  @if($sections['popular'] && $popular->isNotEmpty())
    <section class="fa-section">
      <div class="fa-head">
        <div><h2 class="fa-h2">{{ $opts['popular_title'] }}</h2></div>
        <a href="{{ route('store.shop') }}" class="fa-link">{{ __('messages.ViewAll') }}</a>
      </div>
      <div class="fa-grid" style="--fa-cols: {{ $cardCols($opts['limits']['popular']) }}">
        @foreach($popular as $p)@include('store.fashion.partials.product-card', ['p' => $p, 'currency' => $currency])@endforeach
      </div>
    </section>
  @endif

  {{-- ===== Trust ===== --}}
  @if($sections['trust'] && count($opts['trust']))
    <section class="fa-section">
      <div class="fa-trust" style="--fa-trust-cols: {{ max(1, min(6, count($opts['trust']))) }}">
        @foreach($opts['trust'] as $item)
          <div class="fa-trust-item"><x-store.icon :name="$item['icon'] ?: 'check-circle'" fallback="check-circle" /><div><strong>{{ $item['title'] }}</strong>@if(!empty($item['subtitle']))<span>{{ $item['subtitle'] }}</span>@endif</div></div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Brands ===== --}}
  @if($sections['brands'] && $brands->isNotEmpty())
    <section class="fa-section">
      <div class="fa-head" style="justify-content: center"><span class="fa-kicker" style="margin: 0">{{ __('messages.ShopByBrand') }}</span></div>
      <div class="fa-brands">
        @foreach($brands as $b)
          <a href="{{ route('store.shop', ['brand' => $b->id]) }}" class="fa-brand" title="{{ $b->name }}">@if($b->image && $b->image !== 'no-image.png')<img src="{{ global_asset(upload_path('brands').'/'.$b->image) }}" alt="{{ $b->name }}" loading="lazy" onerror="this.remove()">@else<span>{{ $b->name }}</span>@endif</a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- ===== Newsletter ===== --}}
  @if($sections['newsletter'])
    <section class="fa-section">
      <div class="fa-newsletter">
        <span class="fa-kicker" style="margin: 0">{{ __('messages.FashionNewsletter') }}</span>
        <h2>{{ $opts['newsletter_title'] }}</h2>
        <p>{{ $opts['newsletter_subtitle'] }}</p>
        <form id="newsletterForm">@csrf
          <input name="email" type="email" id="newsletterEmail" placeholder="{{ __('messages.NewsletterEmailPlaceholder') }}" aria-label="{{ __('messages.NewsletterEmailPlaceholder') }}" required>
          <button id="newsletterBtn" class="fa-btn" type="submit">{{ $nlBtn }}</button>
        </form>
        <div id="newsletterMsg" class="fa-newsletter-note">{{ __('messages.NoSpam') }}</div>
      </div>
    </section>
  @endif

</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
