@extends('layouts.store')

@section('content')
@php
  $shopUrl = store_route_or('store.shop');
  $times = [];
  for ($m = 0; $m < 1440; $m += 30) { $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
  $flavour = \App\Support\RentalTheme::flavour($type ? [$type] : $rnTypes);
  $minDate = now()->addHours((int) ($rnSettings->website_min_notice_hours ?? 2))->format('Y-m-d');
  $title = $type ? \App\Support\RentalTypes::typeLabel($type) : __('messages.RntAllRentals');
  if ($categoryId && ($cat = $catalogCategories->firstWhere('id', $categoryId))) { $title = $cat->name; }
  $linkQuery = $start && $end ? ['start' => $start->format('Y-m-d'), 'start_time' => $start->format('H:i'), 'end' => $end->format('Y-m-d'), 'end_time' => $end->format('H:i')] : [];
  $hasFilters = $q !== '' || $categoryId || $activeFilters;
  $clearUrl = $shopUrl.(count($rnTypes) > 1 && $type ? '?type='.$type : '');
@endphp

<div class="rn-container">
  <div class="rn-page-head">
    <nav class="rn-crumbs" aria-label="{{ __('messages.RntBreadcrumb') }}"><a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span>/</span><span>{{ $title }}</span></nav>
    <h1 class="rn-h2">{{ $title }}</h1>
  </div>

  <form method="GET" action="{{ $shopUrl }}" id="rnFilters" x-data="{ open: false }">
    {{-- Dates --}}
    <div class="rn-datebar">
      <div class="rn-field">
        <span class="rn-label">{{ __('messages.RntPickup_'.$flavour) }}</span>
        <div class="rn-when">
          <input type="date" name="start" class="rn-input" min="{{ $minDate }}" value="{{ $start ? $start->format('Y-m-d') : request('start') }}" aria-label="{{ __('messages.RntStartDate') }}">
          <select name="start_time" class="rn-input" aria-label="{{ __('messages.RntStartTime') }}">@foreach($times as $tm)<option value="{{ $tm }}" @selected($tm === ($start ? $start->format('H:i') : (request('start_time') ?: '10:00')))>{{ $tm }}</option>@endforeach</select>
        </div>
      </div>
      <div class="rn-field">
        <span class="rn-label">{{ __('messages.RntReturn_'.$flavour) }}</span>
        <div class="rn-when">
          <input type="date" name="end" class="rn-input" min="{{ $minDate }}" value="{{ $end ? $end->format('Y-m-d') : request('end') }}" aria-label="{{ __('messages.RntEndDate') }}">
          <select name="end_time" class="rn-input" aria-label="{{ __('messages.RntEndTime') }}">@foreach($times as $tm)<option value="{{ $tm }}" @selected($tm === ($end ? $end->format('H:i') : (request('end_time') ?: '10:00')))>{{ $tm }}</option>@endforeach</select>
        </div>
      </div>
      <button type="submit" class="rn-btn"><x-store.icon name="search" />{{ __('messages.RntCheckAvailability') }}</button>
      @if($start || request('start'))
        <a class="rn-btn rn-btn-ghost" href="{{ $shopUrl }}?{{ http_build_query(request()->except(['start', 'start_time', 'end', 'end_time', 'page'])) }}">{{ __('messages.RntClearDates') }}</a>
      @endif
    </div>

    @if($dateProblem)
      <div class="rn-alert rn-alert-warn" style="margin-bottom: 16px"><x-store.icon name="alert-circle" /><span>{{ $dateProblem }}</span></div>
    @elseif($start && $end)
      <div class="rn-alert rn-alert-info" style="margin-bottom: 16px"><x-store.icon name="calendar" /><span>{{ __('messages.RntShowingFree', ['start' => $start->translatedFormat('d M Y H:i'), 'end' => $end->translatedFormat('d M Y H:i')]) }}</span></div>
    @endif

    <div class="rn-layout">
      {{-- Filters --}}
      <aside>
        <button type="button" class="rn-btn rn-btn-ghost rn-btn-block rn-show-sm" style="margin-bottom: 12px" @click="open = !open" :aria-expanded="open"><x-store.icon name="sliders" />{{ __('messages.RntFilters') }}</button>
        <div class="rn-filters rn-hide-sm" :class="open && 'is-open'">
          <div class="rn-filter">
            <h4>{{ __('messages.RntKeyword') }}</h4>
            <input type="search" name="q" class="rn-input" value="{{ $q }}" placeholder="{{ __('messages.RntSearchPlaceholder_'.$flavour) }}">
          </div>

          @if(count($rnTypes) > 1)
            <div class="rn-filter">
              <h4>{{ __('messages.RntWhatToRent') }}</h4>
              <div class="rn-filter-list">
                <label><input type="radio" name="type" value="" @checked(! $type) onchange="rnTypeChanged(this.form)">{{ __('messages.RntAllRentals') }}</label>
                @foreach($rnTypes as $t)
                  <label><input type="radio" name="type" value="{{ $t }}" @checked($type === $t) onchange="rnTypeChanged(this.form)">{{ \App\Support\RentalTypes::typeLabel($t) }}</label>
                @endforeach
              </div>
            </div>
          @elseif($type)
            <input type="hidden" name="type" value="{{ $type }}">
          @endif

          @if($catalogCategories->isNotEmpty())
            <div class="rn-filter">
              <h4>{{ __('messages.RntCategory') }}</h4>
              <div class="rn-filter-list">
                <label><input type="radio" name="category" value="" @checked(! $categoryId) onchange="this.form.submit()">{{ __('messages.RntAnyCategory') }}</label>
                @foreach($catalogCategories as $c)
                  <label><input type="radio" name="category" value="{{ $c->id }}" @checked($categoryId === $c->id) onchange="this.form.submit()"><span style="flex: 1">{{ $c->name }}</span><span class="rn-count">{{ $c->items_count }}</span></label>
                @endforeach
              </div>
            </div>
          @endif

          @foreach($specFilters as $f)
            <div class="rn-filter">
              <h4>{{ \App\Support\RentalTypes::specLabel($f) }}</h4>
              @if($f['input'] === 'select')
                <div class="rn-filter-list">
                  @foreach($f['options'] as $o)
                    <label><input type="checkbox" name="f_{{ $f['id'] }}[]" value="{{ $o }}" @checked(in_array($o, (array) ($activeFilters[$f['id']] ?? []), true)) onchange="this.form.submit()">{{ \App\Support\RentalTypes::optionLabel($o) }}</label>
                  @endforeach
                </div>
              @elseif($f['input'] === 'bool')
                <div class="rn-filter-list">
                  <label><input type="checkbox" name="f_{{ $f['id'] }}" value="1" @checked(! empty($activeFilters[$f['id']])) onchange="this.form.submit()">{{ __('messages.RntOnlyWith') }}</label>
                </div>
              @elseif($f['input'] === 'number')
                <select name="f_{{ $f['id'] }}" class="rn-input" onchange="this.form.submit()">
                  <option value="">{{ __('messages.RntAny') }}</option>
                  @foreach([1, 2, 3, 4, 5, 6, 7, 8, 9, 12, 15] as $n)
                    @if($n >= ($f['min'] ?? 0) && $n <= ($f['max'] ?? 999))<option value="{{ $n }}" @selected(($activeFilters[$f['id']] ?? null) === $n)>{{ $n }}+</option>@endif
                  @endforeach
                </select>
              @endif
            </div>
          @endforeach

          @if($type === 'property')
            <div class="rn-filter">
              <h4>{{ __('messages.RntBedrooms') }}</h4>
              <select name="bedrooms" class="rn-input" onchange="this.form.submit()">
                <option value="">{{ __('messages.RntAny') }}</option>
                @foreach([1, 2, 3, 4, 5] as $n)<option value="{{ $n }}" @selected(($activeFilters['bedrooms'] ?? null) === $n)>{{ $n }}+</option>@endforeach
              </select>
            </div>
          @endif

          <div class="rn-filter" style="display: flex; gap: 8px">
            <button type="submit" class="rn-btn rn-btn-sm" style="flex: 1">{{ __('messages.RntApply') }}</button>
            @if($hasFilters)<a href="{{ $clearUrl }}" class="rn-btn rn-btn-sm rn-btn-ghost">{{ __('messages.RntReset') }}</a>@endif
          </div>
        </div>
      </aside>

      {{-- Results --}}
      <div>
        <div class="rn-toolbar">
          <span class="rn-results">{{ trans_choice('messages.RntResults', $items->total(), ['n' => $items->total()]) }}</span>
          <label class="rn-field" style="display: flex; align-items: center; gap: 8px">
            <span class="rn-label" style="margin: 0">{{ __('messages.RntSortBy') }}</span>
            <select name="sort" class="rn-input" onchange="this.form.submit()">
              @foreach(['recommended', 'price_asc', 'price_desc', 'newest'] as $o)<option value="{{ $o }}" @selected($sort === $o)>{{ __('messages.RntSort_'.$o) }}</option>@endforeach
            </select>
          </label>
        </div>

        @if($items->count())
          <div class="rn-grid">
            @foreach($items as $item)
              @include('store.rental.partials.card', ['item' => $item, 'quote' => $quotes[$item->id] ?? null, 'linkQuery' => $linkQuery, 'showDeposit' => $opts['show_deposit']])
            @endforeach
          </div>

          @if($items->lastPage() > 1)
            <nav class="rn-pager" aria-label="{{ __('messages.RntPages') }}">
              @if($items->currentPage() > 1)<a href="{{ $items->url($items->currentPage() - 1) }}" aria-label="{{ __('messages.RntPrevious') }}"><x-store.icon name="chevron-left" class="store-ico-flip" /></a>@endif
              @for($p = max(1, $items->currentPage() - 2); $p <= min($items->lastPage(), $items->currentPage() + 2); $p++)
                @if($p === $items->currentPage())<span class="is-active" aria-current="page">{{ $p }}</span>@else<a href="{{ $items->url($p) }}">{{ $p }}</a>@endif
              @endfor
              @if($items->currentPage() < $items->lastPage())<a href="{{ $items->url($items->currentPage() + 1) }}" aria-label="{{ __('messages.RntNext') }}"><x-store.icon name="chevron-right" class="store-ico-flip" /></a>@endif
            </nav>
          @endif
        @else
          <div class="rn-empty">
            <x-store.icon name="search" />
            <h3>{{ $start && $end ? __('messages.RntNothingFree') : __('messages.RntNothingFound') }}</h3>
            <p>{{ $start && $end ? __('messages.RntNothingFreeText') : __('messages.RntNothingFoundText') }}</p>
            @if($hasFilters || $start)<p style="margin-top: 14px"><a href="{{ $clearUrl }}" class="rn-btn rn-btn-ghost rn-btn-sm">{{ __('messages.RntReset') }}</a></p>@endif
          </div>
        @endif
      </div>
    </div>
  </form>
</div>
<script>
  // Another type: its categories and properties differ, so drop the old picks before searching.
  window.rnTypeChanged = function (form) {
    form.querySelectorAll('input[name="category"]').forEach(function (el) { el.checked = el.value === ''; });
    form.querySelectorAll('[name^="f_"], [name="bedrooms"]').forEach(function (el) {
      if (el.type === 'checkbox' || el.type === 'radio') el.checked = false; else el.value = '';
    });
    form.submit();
  };
</script>
@endsection
