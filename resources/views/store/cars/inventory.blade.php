@extends('layouts.store')

@section('content')
@php
  $cdOpts = $opts;
  $hidePricesCd = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
  $condTitles = ['new' => __('messages.CarNewCars'), 'used' => __('messages.CarUsedCars'), 'certified' => __('messages.CarCertifiedCars')];
  $heading = trim($f['make'].' '.$f['model']) ?: ($condTitles[$f['condition']] ?? __('messages.CarAllInventory'));
  $years = [];
  if ($facets['years']['min'] && $facets['years']['max']) {
      $years = range($facets['years']['max'], $facets['years']['min']);
  }
  $mileageSteps = [10000, 25000, 50000, 75000, 100000, 150000];
  $unit = $opts['mileage_unit'] === 'mi' ? __('messages.CarMilesShort') : __('messages.CarKmShort');
  $groups = [
      'body' => ['label' => __('messages.CarBodyType'), 'values' => \App\Models\VehicleSpec::BODY_TYPES, 'prefix' => 'CarBody_', 'counts' => $facets['body']],
      'fuel' => ['label' => __('messages.CarFuel'), 'values' => \App\Models\VehicleSpec::FUELS, 'prefix' => 'CarFuel_', 'counts' => $facets['fuel']],
      'transmission' => ['label' => __('messages.CarGearbox'), 'values' => \App\Models\VehicleSpec::TRANSMISSIONS, 'prefix' => 'CarGear_', 'counts' => $facets['transmission']],
  ];
  $sorts = ['recommended' => __('messages.CarSortRecommended'), 'latest' => __('messages.CarSortLatest'), 'price_asc' => __('messages.CarSortPriceAsc'), 'price_desc' => __('messages.CarSortPriceDesc'), 'year_desc' => __('messages.CarSortYearDesc'), 'mileage_asc' => __('messages.CarSortMileageAsc')];
  if ($hidePricesCd) { unset($sorts['price_asc'], $sorts['price_desc']); }
  $storeCats = $categories->count() > 1 ? $categories : collect();
@endphp

<div class="cd-container" style="padding-top: 24px; padding-bottom: 24px" x-data="{ open: false }">
  <nav class="cd-crumbs" aria-label="{{ __('messages.CarBreadcrumb') }}"><a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span aria-hidden="true">/</span><span>{{ __('messages.CarInventory') }}</span></nav>

  <div class="cd-toolbar" style="margin-top: 14px">
    <div>
      <h1 class="cd-h1">{{ $heading }}</h1>
      <p class="cd-sub cd-num">{{ trans_choice('messages.CarMatchCount', $products->total(), ['count' => number_format($products->total())]) }}</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
      <button type="button" class="cd-btn cd-btn-ghost cd-btn-sm cd-filter-toggle" @click="open = !open" :aria-expanded="open.toString()" aria-controls="cdFilterForm"><x-store.icon name="sliders" />{{ __('messages.Filters') }}</button>
      <label class="cd-sort">{{ __('messages.SortBy') }}
        <select name="sort" form="cdFilterForm" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
          @foreach($sorts as $k => $label)<option value="{{ $k }}" @selected($f['sort'] === $k)>{{ $label }}</option>@endforeach
        </select>
      </label>
    </div>
  </div>

  @if($chips)
    <div class="cd-chips" style="margin-top: 16px">
      @foreach($chips as $chip)
        <a class="cd-chip" href="{{ $chip['url'] }}" aria-label="{{ __('messages.CarRemoveFilter', ['filter' => $chip['label']]) }}">{{ $chip['label'] }}<x-store.icon name="x" /></a>
      @endforeach
      <a href="{{ route('store.shop') }}" class="cd-link" style="padding: 0 8px">{{ __('messages.ClearFilters') }}</a>
    </div>
  @endif

  <div class="cd-inv" style="margin-top: 20px">
    {{-- Filters --}}
    <form id="cdFilterForm" class="cd-filters" :class="{ 'is-collapsed': !open }" action="{{ route('store.shop') }}" method="GET" aria-label="{{ __('messages.Filters') }}"
          x-data @change="if ($event.target.name === 'make') { const m = $el.querySelector('[name=model]'); if (m) m.value = ''; } $el.requestSubmit ? $el.requestSubmit() : $el.submit()">
      @if($f['q'] !== '')<input type="hidden" name="q" value="{{ $f['q'] }}">@endif

      <fieldset>
        <legend>{{ __('messages.CarCondition') }}</legend>
        <div class="cd-seg">
          <label><input type="radio" name="condition" value="" @checked($f['condition'] === '')>{{ __('messages.All') }}</label>
          @foreach(\App\Models\VehicleSpec::CONDITIONS as $c)
            @if(($facets['condition'][$c] ?? 0) > 0 || $f['condition'] === $c)
              <label><input type="radio" name="condition" value="{{ $c }}" @checked($f['condition'] === $c)>{{ __('messages.CarCondition_'.$c) }}</label>
            @endif
          @endforeach
        </div>
      </fieldset>

      @if($storeCats->isNotEmpty())
        <label class="cd-field"><span class="cd-filter-title" style="margin: 0">{{ __('messages.Category') }}</span>
          <select name="category">
            <option value="">{{ __('messages.All') }}</option>
            @foreach($storeCats as $c)<option value="{{ $c->id }}" @selected((int) $f['category'] === (int) $c->id)>{{ $c->name }}</option>@endforeach
          </select>
        </label>
      @endif

      <label class="cd-field"><span class="cd-filter-title" style="margin: 0">{{ __('messages.CarMake') }}</span>
        <select name="make">
          <option value="">{{ __('messages.CarAnyMake') }}</option>
          @foreach($facets['makes'] as $m)<option value="{{ $m['name'] }}" @selected($f['make'] === $m['name'])>{{ $m['name'] }} ({{ $m['count'] }})</option>@endforeach
        </select>
      </label>
      <label class="cd-field"><span class="cd-filter-title" style="margin: 0">{{ __('messages.CarModel') }}</span>
        <select name="model" @disabled($f['make'] === '' || !count($facets['models']))>
          <option value="">{{ __('messages.CarAnyModel') }}</option>
          @foreach($facets['models'] as $m)<option value="{{ $m['name'] }}" @selected($f['model'] === $m['name'])>{{ $m['name'] }} ({{ $m['count'] }})</option>@endforeach
        </select>
      </label>

      @if(!$hidePricesCd && count($priceSteps))
        <fieldset>
          <legend>{{ __('messages.Price') }}</legend>
          <div class="cd-field-row">
            <label class="cd-field">{{ __('messages.CarMin') }}
              <select name="min"><option value="">{{ __('messages.CarNoMin') }}</option>@foreach($priceSteps as $step)<option value="{{ $step }}" @selected($f['min'] !== null && (float) $f['min'] === (float) $step)>{{ store_money($step) }}</option>@endforeach</select>
            </label>
            <label class="cd-field">{{ __('messages.CarMax') }}
              <select name="max"><option value="">{{ __('messages.CarNoMax') }}</option>@foreach($priceSteps as $step)<option value="{{ $step }}" @selected($f['max'] !== null && (float) $f['max'] === (float) $step)>{{ store_money($step) }}</option>@endforeach</select>
            </label>
          </div>
        </fieldset>
      @endif

      @if($years)
        <fieldset>
          <legend>{{ __('messages.CarYear') }}</legend>
          <div class="cd-field-row">
            <label class="cd-field">{{ __('messages.CarFrom') }}
              <select name="year_min"><option value="">{{ __('messages.CarAny') }}</option>@foreach(array_reverse($years) as $y)<option value="{{ $y }}" @selected($f['year_min'] === $y)>{{ $y }}</option>@endforeach</select>
            </label>
            <label class="cd-field">{{ __('messages.CarTo') }}
              <select name="year_max"><option value="">{{ __('messages.CarAny') }}</option>@foreach($years as $y)<option value="{{ $y }}" @selected($f['year_max'] === $y)>{{ $y }}</option>@endforeach</select>
            </label>
          </div>
        </fieldset>
      @endif

      <label class="cd-field"><span class="cd-filter-title" style="margin: 0">{{ __('messages.CarMaxMileage') }}</span>
        <select name="mileage_max">
          <option value="">{{ __('messages.CarAny') }}</option>
          @foreach($mileageSteps as $m)<option value="{{ $m }}" @selected($f['mileage_max'] === $m)>{{ __('messages.CarUpToMileage', ['mileage' => number_format($m).' '.$unit]) }}</option>@endforeach
        </select>
      </label>

      @foreach($groups as $key => $g)
        @php $present = collect($g['values'])->filter(fn ($v) => ($g['counts'][$v] ?? 0) > 0 || in_array($v, $f[$key], true)); @endphp
        @if($present->isNotEmpty())
          <fieldset>
            <legend>{{ $g['label'] }}</legend>
            @foreach($present as $v)
              <label class="cd-check"><input type="checkbox" name="{{ $key }}[]" value="{{ $v }}" @checked(in_array($v, $f[$key], true))><span>{{ __('messages.'.$g['prefix'].$v) }}</span><small>{{ $g['counts'][$v] ?? 0 }}</small></label>
            @endforeach
          </fieldset>
        @endif
      @endforeach

      <noscript><button type="submit" class="cd-btn cd-btn-block">{{ __('messages.Apply') }}</button></noscript>
      <button type="submit" class="cd-btn cd-btn-block cd-filter-toggle">{{ trans_choice('messages.CarShowCount', $products->total(), ['count' => number_format($products->total())]) }}</button>
    </form>

    {{-- Results --}}
    <div class="cd-results">
      @if($products->count())
        <div class="cd-grid">
          @foreach($products as $p)@include('store.cars.partials.card', ['p' => $p])@endforeach
        </div>
        @if($products->hasPages())
          @php
            $current = $products->currentPage(); $last = $products->lastPage();
            $pages = collect([1, $last])->merge(range(max(1, $current - 1), min($last, $current + 1)))->unique()->sort()->values();
            $prev = null;
          @endphp
          <nav aria-label="{{ __('messages.CarPages') }}" style="display: flex; justify-content: center">
            <ul class="pagination">
              @if($products->onFirstPage())<li><span class="page-link disabled"><x-store.icon name="chevron-left" class="w-4 h-4" /></span></li>
              @else<li><a class="page-link" href="{{ $products->previousPageUrl() }}" aria-label="{{ __('messages.Previous') }}"><x-store.icon name="chevron-left" class="w-4 h-4" /></a></li>@endif
              @foreach($pages as $page)
                @if(!is_null($prev) && $page - $prev > 1)<li><span class="page-link disabled">…</span></li>@endif
                @if($page == $current)<li><span class="page-link active" aria-current="page">{{ $page }}</span></li>
                @else<li><a class="page-link" href="{{ $products->url($page) }}">{{ $page }}</a></li>@endif
                @php $prev = $page; @endphp
              @endforeach
              @if($products->hasMorePages())<li><a class="page-link" href="{{ $products->nextPageUrl() }}" aria-label="{{ __('messages.Next') }}"><x-store.icon name="chevron-right" class="w-4 h-4" /></a></li>
              @else<li><span class="page-link disabled"><x-store.icon name="chevron-right" class="w-4 h-4" /></span></li>@endif
            </ul>
          </nav>
        @endif
        @if(!empty($opts['show_monthly_on_cards']) && !$hidePricesCd)
          <p class="cd-note">{{ __('messages.CarRepExample', ['deposit' => $opts['deposit_percent'], 'term' => $opts['default_term'], 'apr' => rtrim(rtrim(number_format($opts['apr'], 2), '0'), '.')]) }} {{ $opts['finance_disclaimer'] }}</p>
        @endif
      @else
        <div class="cd-empty">
          @include('store.cars.partials.body-icon', ['type' => 'sedan'])
          <h2 class="cd-h2" style="font-size: 22px">{{ __('messages.CarNoMatches') }}</h2>
          <p class="cd-muted" style="margin: 0">{{ __('messages.CarNoMatchesHint') }}</p>
          <a href="{{ route('store.shop') }}" class="cd-btn cd-btn-ghost">{{ __('messages.ClearFilters') }}</a>
        </div>
      @endif
    </div>
  </div>
</div>
@endsection
