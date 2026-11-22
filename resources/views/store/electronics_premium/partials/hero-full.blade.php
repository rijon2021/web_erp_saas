{{--
  Electronics Premium — full-image hero slider (hero_style = full).
  Each slide's image fills the whole slide; the copy sits on it over a
  gradient scrim. Crossfade with a slow zoom, arrows, dots with an autoplay
  progress bar, swipe (touch / pen), ←/→ keys, pause on hover / focus / hidden
  tab; reduced-motion users get no zoom and no autoplay. Inactive slides are
  `inert`, so their buttons are never tabbed to.
  Expects: $slides, $opts, $s.
--}}
@php
  $pxH = in_array($opts['hero_height'] ?? 'large', \App\Support\PremiumElectronicsTheme::HERO_HEIGHTS, true) ? $opts['hero_height'] : 'large';
  $pxW = ($opts['hero_width'] ?? 'edge') === 'contained' ? 'contained' : 'edge';
  $pxSecs = (int) ($opts['hero_interval'] ?? 7);
  $pxN = $slides->count();
  $pxFocus = ['center' => '50% 50%', 'left' => '20% 50%', 'right' => '80% 50%', 'top' => '50% 15%', 'bottom' => '50% 85%'];
@endphp
@once
<script>
  /* Alpine component for the full-image hero (x-data="pxHero(n, seconds)"). */
  window.pxHero = function (n, seconds) {
    return {
      i: 0, n: n, t: null, paused: false, sx: null, sy: null,
      ms: Math.max(0, seconds || 0) * 1000,
      reduce: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
      start() {
        this.$el.style.setProperty('--px-slide-ms', (this.ms || 7000) + 'ms');
        this.schedule();
        document.addEventListener('visibilitychange', () => (document.hidden ? this.stop() : this.schedule()));
      },
      running() { return this.n > 1 && this.ms > 0 && !this.reduce && !this.paused; },
      schedule() { this.stop(); if (this.running()) this.t = setTimeout(() => this.go(this.i + 1), this.ms); },
      stop() { clearTimeout(this.t); this.t = null; },
      go(k) { this.i = (k + this.n) % this.n; this.schedule(); },
      next() { this.go(this.i + 1); },
      prev() { this.go(this.i - 1); },
      pause() { this.paused = true; this.stop(); },
      resume() { this.paused = false; this.schedule(); },
      down(e) { if (e.pointerType === 'mouse') return; this.sx = e.clientX; this.sy = e.clientY; },
      up(e) {
        if (this.sx === null) return;
        const dx = e.clientX - this.sx, dy = e.clientY - this.sy;
        this.sx = null;
        if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
        const rtl = document.documentElement.dir === 'rtl';
        (dx < 0) !== rtl ? this.next() : this.prev();
      },
    };
  };
</script>
@endonce
<section class="px-fhero px-fhero--{{ $pxH }} px-fhero--{{ $pxW }}"
         aria-roledescription="carousel" aria-label="{{ __('messages.PxFeatured') }}"
         x-data="pxHero({{ $pxN }}, {{ $pxSecs }})" x-init="start()"
         :class="{ 'is-paused': !running() }"
         @mouseenter="pause()" @mouseleave="resume()"
         {{-- Keyboard focus pauses; a tap/click on a dot or arrow must not (on
              phones focus never leaves, which would stop autoplay for good). --}}
         @focusin="$event.target.matches(':focus-visible') && pause()" @focusout="resume()"
         @keydown.arrow-left.prevent="prev()" @keydown.arrow-right.prevent="next()"
         @pointerdown="down($event)" @pointerup="up($event)" @pointercancel="sx = null">
  <div class="px-fhero-track">
    @foreach($slides as $k => $slide)
      <div class="px-fslide px-fslide--{{ $slide['align'] }} {{ $k === 0 ? 'is-active' : '' }}"
           :class="{ 'is-active': i === {{ $k }} }" :inert="i !== {{ $k }}" @if($k > 0) inert @endif
           role="group" aria-roledescription="slide" aria-label="{{ $k + 1 }} / {{ $pxN }}">
        @if($slide['image_url'])
          <img class="px-fslide-img" src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] ?? '' }}"
               style="object-position: {{ $pxFocus[$slide['focus']] ?? '50% 50%' }}"
               loading="{{ $k === 0 ? 'eager' : 'lazy' }}" @if($k === 0) fetchpriority="high" @endif decoding="async">
        @else
          <div class="px-fslide-fill" aria-hidden="true"></div>
        @endif
        <div class="px-fslide-scrim px-scrim--{{ $slide['overlay'] }}" aria-hidden="true"></div>
        <div class="px-fslide-inner">
          <div class="px-fslide-copy">
            @if(!empty($slide['kicker']))<span class="px-fslide-kicker">{{ $slide['kicker'] }}</span>@endif
            @if($k === 0)
              <h1 class="px-fslide-title">{{ $slide['title'] ?: store_text($s, 'hero_title') }}</h1>
            @else
              <h2 class="px-fslide-title">{{ $slide['title'] ?: store_text($s, 'hero_title') }}</h2>
            @endif
            @if(!empty($slide['subtitle']))<p class="px-fslide-sub">{{ $slide['subtitle'] }}</p>@endif
            <div class="px-fslide-actions">
              @if(!empty($slide['primary_text']))<a href="{{ $slide['primary_href'] }}" class="px-btn px-btn-primary px-btn-lg">{{ $slide['primary_text'] }}</a>@endif
              @if($slide['secondary_href'])<a href="{{ $slide['secondary_href'] }}" class="px-btn px-btn-onimg px-btn-lg">{{ $slide['secondary_text'] }}</a>@endif
              @if($slide['video_embed'])
                <button type="button" class="px-fslide-play" @click="video = @js($slide['video_embed'])">
                  <span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 3l15 9-15 9z"/></svg></span>{{ __('messages.PxWatchVideo') }}
                </button>
              @elseif($slide['video_link'])
                <a href="{{ $slide['video_link'] }}" target="_blank" rel="noopener" class="px-fslide-play">
                  <span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 3l15 9-15 9z"/></svg></span>{{ __('messages.PxWatchVideo') }}
                </a>
              @endif
            </div>
          </div>
        </div>
      </div>
    @endforeach
  </div>

  @if($pxN > 1)
    <button type="button" class="px-fhero-arrow px-fhero-arrow--prev" @click="prev()" aria-label="{{ __('messages.Previous') }}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="px-fhero-arrow px-fhero-arrow--next" @click="next()" aria-label="{{ __('messages.Next') }}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="px-fhero-nav">
      @foreach($slides as $k => $slide)
        <button type="button" class="px-fhero-dot {{ $k === 0 ? 'is-active' : '' }}" :class="{ 'is-active': i === {{ $k }} }"
                @click="go({{ $k }})" aria-label="{{ __('messages.PxSlideN', ['n' => $k + 1]) }}" :aria-current="(i === {{ $k }}).toString()"><i></i></button>
      @endforeach
      <span class="px-fhero-count" x-text="String(i + 1).padStart(2, '0') + ' / ' + String(n).padStart(2, '0')">01 / {{ str_pad((string) $pxN, 2, '0', STR_PAD_LEFT) }}</span>
    </div>
  @endif
</section>
