@php
  $currency   = $currency ?? store_currency()['symbol'];
  $specSelected = $specSelected ?? [];
  $brandFilter = (int) request('brand', 0) > 0 ? \App\Models\Brand::query()->whereKey((int) request('brand'))->value('name') : null;
  $dealsFilter = request()->boolean('deals');
  $hasFilters = filled($q ?? null) || filled($cat ?? null) || filled($collection ?? null) || filled($min ?? null) || filled($max ?? null)
      || $specSelected || $brandFilter || $dealsFilter;
  // URL without one selected spec value (?spec[<label>][]=<value>).
  $withoutSpec = function (string $label, string $value) {
      $query = request()->except('page');
      $query['spec'][$label] = array_values(array_filter((array) ($query['spec'][$label] ?? []), fn ($v) => (string) $v !== $value));
      if (! $query['spec'][$label]) unset($query['spec'][$label]);
      if (empty($query['spec'])) unset($query['spec']);
      return route('store.shop', $query);
  };
@endphp

{{-- Applied filter chips --}}
@if($hasFilters)
  <div class="flex flex-wrap gap-2 mb-4">
    @if(filled($q))
      <a href="{{ route('store.shop', request()->except('q','page')) }}" class="chip chip-accent">
        <x-store.icon name="search" class="w-3 h-3" /> "{{ $q }}"
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @if(filled($cat))
      @php $catName = optional($categories->firstWhere('id', $cat))->name ?? $cat; @endphp
      <a href="{{ route('store.shop', request()->except('category','page')) }}" class="chip chip-accent">
        <x-store.icon name="tag" class="w-3 h-3" /> {{ $catName }}
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @if(filled($collection))
      @php
        $coObj  = $collections->first(fn($c) => (string)$c->slug === (string)$collection || (string)$c->id === (string)$collection);
        $coName = $coObj->title ?? $collection;
      @endphp
      <a href="{{ route('store.shop', request()->except('collection','page')) }}" class="chip chip-accent">
        <x-store.icon name="package" class="w-3 h-3" /> {{ $coName }}
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @if(filled($min))
      <a href="{{ route('store.shop', request()->except('min','page')) }}" class="chip chip-accent">
        {{ __('messages.Min') }}: {{ store_money((float)$min) }}
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @if(filled($max))
      <a href="{{ route('store.shop', request()->except('max','page')) }}" class="chip chip-accent">
        {{ __('messages.Max') }}: {{ store_money((float)$max) }}
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @if($brandFilter)
      <a href="{{ route('store.shop', request()->except('brand','page')) }}" class="chip chip-accent">
        {{ $brandFilter }}
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @if($dealsFilter)
      <a href="{{ route('store.shop', request()->except('deals','page')) }}" class="chip chip-accent">
        <x-store.icon name="zap" class="w-3 h-3" /> {{ __('messages.Deals') }}
        <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
      </a>
    @endif
    @foreach($specSelected as $specLabel => $specValues)
      @foreach($specValues as $specValue)
        <a href="{{ $withoutSpec((string) $specLabel, (string) $specValue) }}" class="chip chip-accent">
          {{ $specLabel }}: {{ $specValue }}
          <x-store.icon name="x" class="w-3 h-3 ms-1 opacity-70" />
        </a>
      @endforeach
    @endforeach
    <a href="{{ route('store.shop') }}" class="chip chip-danger">
      <x-store.icon name="x" class="w-3 h-3" /> {{ __('messages.ResetAll') }}
    </a>
  </div>
@endif

@if($products->count())
  @php $pxGrid = isset($s) && ($s->theme ?? null) === \App\Support\PremiumElectronicsTheme::KEY; @endphp
  <div class="{{ $pxGrid ? 'px-grid' : 'grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4' }}">
    @foreach($products as $p)
      @include($pxGrid ? 'store.electronics_premium.partials.product-card' : 'store.partials.product-card', ['p' => $p, 'currency' => $currency])
    @endforeach
  </div>

  @php $products->appends(request()->except('page')); @endphp
  @if ($products->hasPages())
    <div class="mt-8 flex flex-col items-center gap-3">
      @if($products->total() > 0)
        <div class="text-xs text-fg-muted">
          {{ __('messages.Showing') }}
          <strong>{{ $products->firstItem() }}</strong>–<strong>{{ $products->lastItem() }}</strong>
          {{ __('messages.of') }} <strong>{{ $products->total() }}</strong> {{ __('messages.productsLower') }}
        </div>
      @endif
      <nav aria-label="Product pagination">
        <ul class="pagination">
          @if ($products->onFirstPage())
            <li><span class="page-link disabled"><x-store.icon name="chevron-left" class="w-4 h-4" /></span></li>
          @else
            <li><a class="page-link" href="{{ $products->previousPageUrl() }}"><x-store.icon name="chevron-left" class="w-4 h-4" /></a></li>
          @endif
          @php
            $current = $products->currentPage();
            $last    = $products->lastPage();
            $window  = 1;
            $pages   = collect([1, $last])->merge(range(max(1, $current - $window), min($last, $current + $window)))->unique()->sort()->values();
            $prev = null;
          @endphp
          @foreach ($pages as $page)
            @if(!is_null($prev) && $page - $prev > 1)
              <li><span class="page-link disabled">…</span></li>
            @endif
            @if ($page == $current)
              <li><span class="page-link active">{{ $page }}</span></li>
            @else
              <li><a class="page-link" href="{{ $products->url($page) }}">{{ $page }}</a></li>
            @endif
            @php $prev = $page; @endphp
          @endforeach
          @if ($products->hasMorePages())
            <li><a class="page-link" href="{{ $products->nextPageUrl() }}"><x-store.icon name="chevron-right" class="w-4 h-4" /></a></li>
          @else
            <li><span class="page-link disabled"><x-store.icon name="chevron-right" class="w-4 h-4" /></span></li>
          @endif
        </ul>
      </nav>
    </div>
  @endif
@else
  <div class="empty-state py-16">
    <div class="empty-icon">
      <x-store.icon name="package" class="w-12 h-12" />
    </div>
    <h3>{{ __('messages.NoProductsFound') }}</h3>
    <p>{{ __('messages.TryAdjustingFiltersOrBrowseAll') }}</p>
    <a href="{{ route('store.shop') }}" class="btn btn-outline mt-4">{{ __('messages.ClearFilters') }}</a>
  </div>
@endif
