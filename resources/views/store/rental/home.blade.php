@extends('layouts.store')

@section('content')
@php
  $heroImage = \App\Support\RentalTheme::imageUrl($opts['hero_image']);
  $aboutImage = \App\Support\RentalTheme::imageUrl($opts['about_image']);
  $shopUrl = store_route_or('store.shop');
  $mapLink = $opts['map_url'] !== '' ? $opts['map_url'] : (trim((string) $s->contact_address) !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($s->contact_address) : null);
  $typeIcons = collect($rnTypes)->mapWithKeys(fn ($t) => [$t => \App\Support\RentalTypes::definition($t)['icon'] ?? 'package'])->all();
  $searchCats = $searchCategories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type])->values();
  $times = [];
  for ($m = 0; $m < 1440; $m += 30) { $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
  $minDate = now()->addHours((int) ($rnSettings->website_min_notice_hours ?? 2))->format('Y-m-d');
  $flavour = \App\Support\RentalTheme::flavour($rnTypes);
  $steps = [
    ['t' => __('messages.RntStep1Title'), 'p' => __('messages.RntStep1Text')],
    ['t' => __('messages.RntStep2Title'), 'p' => __('messages.RntStep2Text')],
    ['t' => __('messages.RntStep3Title_'.$flavour), 'p' => __('messages.RntStep3Text_'.$flavour)],
  ];
@endphp

{{-- Hero + search --}}
<section class="rn-hero">
  @if($heroImage)<div class="rn-hero-photo"><img src="{{ $heroImage }}" alt=""></div>@endif
  <div class="rn-container">
    @if($opts['hero_kicker'] !== '')<span class="rn-kicker"><x-store.icon name="star" class="w-4 h-4" />{{ $opts['hero_kicker'] }}</span>@endif
    <h1 class="rn-hero-title">{{ $opts['hero_title'] }}</h1>
    <p class="rn-hero-sub">{{ $opts['hero_subtitle'] }}</p>

    <form class="rn-search" method="GET" action="{{ $shopUrl }}"
          x-data="{ type: @js(count($rnTypes) > 1 ? '' : ($rnTypes[0] ?? '')), cats: @js($searchCats), get shown() { return this.cats.filter(c => !this.type || !c.type || c.type === this.type); } }">
      @if(count($rnTypes) > 1)
        <div class="rn-tabs" role="tablist" aria-label="{{ __('messages.RntWhatToRent') }}">
          <button type="button" role="tab" class="rn-tab" :class="type === '' && 'is-active'" :aria-selected="type === ''" @click="type = ''"><x-store.icon name="grid" />{{ __('messages.RntAllRentals') }}</button>
          @foreach($rnTypes as $t)
            <button type="button" role="tab" class="rn-tab" :class="type === '{{ $t }}' && 'is-active'" :aria-selected="type === '{{ $t }}'" @click="type = '{{ $t }}'"><x-store.icon :name="$typeIcons[$t]" />{{ \App\Support\RentalTypes::typeLabel($t) }}</button>
          @endforeach
        </div>
        <input type="hidden" name="type" :value="type" :disabled="!type">
      @endif
      <div class="rn-search-grid {{ $searchCats->isEmpty() ? 'no-cat' : '' }}">
        @if($searchCats->isNotEmpty())
          <label class="rn-field rn-search-cat">
            <span class="rn-label">{{ __('messages.RntCategory') }}</span>
            <select name="category" class="rn-input">
              <option value="">{{ __('messages.RntAnyCategory') }}</option>
              <template x-for="c in shown" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
            </select>
          </label>
        @endif
        <div class="rn-field">
          <span class="rn-label">{{ __('messages.RntPickup_'.$flavour) }}</span>
          <div class="rn-when">
            <input type="date" name="start" class="rn-input" min="{{ $minDate }}" aria-label="{{ __('messages.RntStartDate') }}">
            <select name="start_time" class="rn-input" aria-label="{{ __('messages.RntStartTime') }}">@foreach($times as $tm)<option value="{{ $tm }}" @selected($tm === '10:00')>{{ $tm }}</option>@endforeach</select>
          </div>
        </div>
        <div class="rn-field">
          <span class="rn-label">{{ __('messages.RntReturn_'.$flavour) }}</span>
          <div class="rn-when">
            <input type="date" name="end" class="rn-input" min="{{ $minDate }}" aria-label="{{ __('messages.RntEndDate') }}">
            <select name="end_time" class="rn-input" aria-label="{{ __('messages.RntEndTime') }}">@foreach($times as $tm)<option value="{{ $tm }}" @selected($tm === '10:00')>{{ $tm }}</option>@endforeach</select>
          </div>
        </div>
        <button type="submit" class="rn-btn rn-search-go"><x-store.icon name="search" />{{ __('messages.RntSearch') }}</button>
      </div>
    </form>
  </div>
</section>

{{-- Categories --}}
@if($opts['sections']['categories'] && $rnCategories->isNotEmpty())
<section class="rn-section">
  <div class="rn-container">
    <div class="rn-section-head">
      <div>
        <h2 class="rn-h2">{{ __('messages.RntBrowseByCategory') }}</h2>
      </div>
      <a href="{{ $shopUrl }}" class="rn-link">{{ __('messages.RntSeeAll') }}<x-store.icon name="arrow-right" class="store-ico-flip" /></a>
    </div>
    <div class="rn-cats">
      @foreach($rnCategories as $c)
        @php $img = $c->imageUrl(); @endphp
        <a href="{{ $shopUrl }}?{{ http_build_query(array_filter(['category' => $c->id, 'type' => $c->type])) }}" class="rn-cat {{ $img ? 'has-photo' : '' }}">
          @if($img)<img src="{{ $img }}" alt="" loading="lazy">@else<span class="rn-cat-ic"><x-store.icon :name="\App\Support\RentalTypes::definition($c->type ?: ($rnTypes[0] ?? 'other'))['icon'] ?? 'package'" /></span>@endif
          <span class="rn-cat-name">{{ $c->name }}</span>
          <span class="rn-cat-count">{{ trans_choice('messages.RntItemsCount', $c->items_count, ['n' => $c->items_count]) }}</span>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- Featured --}}
@if($opts['sections']['featured'])
<section class="rn-section {{ $opts['sections']['categories'] && $rnCategories->isNotEmpty() ? 'rn-band' : '' }}">
  <div class="rn-container">
    <div class="rn-section-head">
      <h2 class="rn-h2">{{ $opts['featured_title'] }}</h2>
      @if($featured->isNotEmpty())<a href="{{ $shopUrl }}" class="rn-link">{{ __('messages.RntSeeAll') }}<x-store.icon name="arrow-right" class="store-ico-flip" /></a>@endif
    </div>
    @if($featured->isNotEmpty())
      <div class="rn-grid">
        @foreach($featured as $item)
          @include('store.rental.partials.card', ['item' => $item, 'showDeposit' => $opts['show_deposit']])
        @endforeach
      </div>
    @else
      <div class="rn-empty">
        <x-store.icon :name="$typeIcons[$rnTypes[0] ?? 'other'] ?? 'package'" />
        <h3>{{ __('messages.RntNothingYet') }}</h3>
        <p>{{ __('messages.RntNothingYetText') }}</p>
      </div>
    @endif
  </div>
</section>
@endif

{{-- How it works --}}
@if($opts['sections']['how'])
<section class="rn-section" id="how">
  <div class="rn-container">
    <div class="rn-section-head"><div><h2 class="rn-h2">{{ __('messages.RntHowItWorks') }}</h2><p class="rn-sub">{{ __('messages.RntHowItWorksSub') }}</p></div></div>
    <div class="rn-steps">
      @foreach($steps as $i => $st)
        <div class="rn-step"><span class="rn-step-n">{{ $i + 1 }}</span><h3>{{ $st['t'] }}</h3><p>{{ $st['p'] }}</p></div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- Trust strip --}}
@if($opts['sections']['trust'] && ! empty($opts['trust']))
<section class="rn-section-sm rn-band">
  <div class="rn-container">
    <div class="rn-trust">
      @foreach($opts['trust'] as $row)
        <div class="rn-trust-item">
          <span class="rn-trust-ic"><x-store.icon :name="$row['icon'] ?? 'check-circle'" /></span>
          <div><b>{{ $row['title'] ?? '' }}</b><span>{{ $row['subtitle'] ?? '' }}</span></div>
        </div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- About --}}
@if($opts['sections']['about'] && $opts['about_text'] !== '')
<section class="rn-section" id="about">
  <div class="rn-container">
    <div class="rn-about {{ $aboutImage ? '' : 'no-photo' }}">
      @if($aboutImage)<div class="rn-about-photo"><img src="{{ $aboutImage }}" alt="" loading="lazy"></div>@endif
      <div>
        <h2 class="rn-h2">{{ $opts['about_title'] }}</h2>
        <p>{{ $opts['about_text'] }}</p>
      </div>
    </div>
  </div>
</section>
@endif

{{-- Visit / contact --}}
@if($opts['sections']['visit'] && ($hours || $s->contact_phone || $s->contact_email || $s->contact_address || $opts['whatsapp'] !== ''))
<section class="rn-section {{ $opts['sections']['about'] && $opts['about_text'] !== '' ? 'rn-band' : '' }}" id="visit">
  <div class="rn-container">
    <div class="rn-section-head"><h2 class="rn-h2">{{ __('messages.RntVisitUs') }}</h2></div>
    <div class="rn-visit">
      @if($hours)
        <div class="rn-visit-card"><h3><x-store.icon name="clock" />{{ __('messages.RntOpeningHours') }}</h3><ul>@foreach($hours as $line)<li>{{ $line }}</li>@endforeach</ul></div>
      @endif
      @if($s->contact_address)
        <div class="rn-visit-card"><h3><x-store.icon name="map-pin" />{{ __('messages.RntFindUs') }}</h3><ul><li>{{ $s->contact_address }}</li>@if($mapLink)<li><a href="{{ $mapLink }}" target="_blank" rel="noopener" class="rn-link">{{ __('messages.RntOpenMap') }}<x-store.icon name="external" /></a></li>@endif</ul></div>
      @endif
      @if($s->contact_phone || $s->contact_email || $opts['whatsapp'] !== '')
        <div class="rn-visit-card"><h3><x-store.icon name="phone" />{{ __('messages.GetInTouch') }}</h3><ul>
          @if($s->contact_phone)<li><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></li>@endif
          @if($opts['whatsapp'] !== '')<li><a href="https://wa.me/{{ $opts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></li>@endif
          @if($s->contact_email)<li><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></li>@endif
        </ul></div>
      @endif
    </div>
  </div>
</section>
@endif
@endsection
