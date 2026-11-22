@php
  // Expected vars: $q, $cat, $collection, $min, $max, $sort, $categories, $collections
  // Optional: $facets (App\Support\StoreFacets::facets()), $sizes / $colors (selected)
  $isDrawer = $isDrawer ?? $isOffcanvas ?? false;
  $facetSizes = $facets['sizes'] ?? [];
  $facetColors = $facets['colors'] ?? [];
  $pickedSizes = array_map('mb_strtolower', $sizes ?? []);
  $pickedColors = array_map('mb_strtolower', $colors ?? []);
@endphp
@once
<style>
  .facet-group { border: 0; margin: 0; padding: 0; min-width: 0; }
  .facet-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .facet-chip { position: relative; display: inline-flex; }
  .facet-chip input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  .facet-chip span { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-width: 44px; height: 40px; padding: 0 12px;
                     border: 1px solid rgb(var(--color-border-strong)); border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer;
                     color: rgb(var(--color-fg-primary)); background: rgb(var(--color-bg-elevated)); transition: border-color .15s, background .15s; }
  .facet-chip span:hover { border-color: rgb(var(--color-fg-primary)); }
  .facet-chip input:checked + span { background: rgb(var(--color-fg-primary)); border-color: rgb(var(--color-fg-primary)); color: rgb(var(--color-bg-base)); }
  .facet-chip input:focus-visible + span { outline: 2px solid rgb(var(--color-accent-500)); outline-offset: 2px; }
  .facet-dot { width: 16px; height: 16px; border-radius: 999px; border: 1px solid rgba(0,0,0,.2); flex-shrink: 0; }
</style>
@endonce

<form method="get" action="{{ route('store.shop') }}" class="space-y-4">
  @foreach(request()->except(['page']) as $k => $v)
    @if(!in_array($k, ['q','category','collection','min','max','sort','size','color','spec']))
      @if(is_array($v))
        @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach
      @else
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
      @endif
    @endif
  @endforeach

  <div class="card">
    <div class="card-body space-y-4">
      <div class="flex items-center justify-between">
        <h6 class="font-semibold m-0">{{ __('messages.Filters') }}</h6>
        <a href="{{ route('store.shop') }}" class="text-xs text-fg-muted hover:text-accent-500">{{ __('messages.Reset') }}</a>
      </div>

      <div>
        <label class="form-label">{{ __('messages.Search') }}</label>
        <input type="text" name="q" value="{{ $q }}" class="input"
               placeholder="{{ __('messages.SearchProducts') }}">
      </div>

      <div>
        <label class="form-label">{{ __('messages.Category') }}</label>
        <select name="category" class="select">
          <option value="">{{ __('messages.AllCategories') }}</option>
          @foreach($categories as $c)
            <option value="{{ $c->id }}" @selected((string)$cat === (string)$c->id)>{{ $c->name }}</option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">{{ __('messages.Collection') }}</label>
        <select name="collection" class="select">
          <option value="">{{ __('messages.AllCollections') }}</option>
          @foreach($collections as $co)
            @php $isSelected = (string)$collection === (string)$co->slug || (string)$collection === (string)$co->id; @endphp
            <option value="{{ $co->slug }}" @selected($isSelected)>{{ $co->title ?: $co->slug }}</option>
          @endforeach
        </select>
      </div>

      @if($facetSizes)
        <fieldset class="facet-group">
          <legend class="form-label">{{ __('messages.Size') }}</legend>
          <div class="facet-chips">
            @foreach($facetSizes as $sz)
              <label class="facet-chip">
                <input type="checkbox" name="size[]" value="{{ $sz }}" @checked(in_array(mb_strtolower($sz), $pickedSizes, true))>
                <span>{{ $sz }}</span>
              </label>
            @endforeach
          </div>
        </fieldset>
      @endif

      @if($facetColors)
        <fieldset class="facet-group">
          <legend class="form-label">{{ __('messages.Colour') }}</legend>
          <div class="facet-chips">
            @foreach($facetColors as $co)
              <label class="facet-chip">
                <input type="checkbox" name="color[]" value="{{ $co['value'] }}" @checked(in_array(mb_strtolower($co['value']), $pickedColors, true))>
                <span>@if($co['swatch'])<i class="facet-dot" style="background: {{ $co['swatch'] }}" aria-hidden="true"></i>@endif{{ $co['value'] }}</span>
              </label>
            @endforeach
          </div>
        </fieldset>
      @endif

      {{-- Electronics Premium: spec filters (StoreSpecs facets) --}}
      @foreach(($specFacets ?? []) as $specLabel => $specValues)
        @php $pickedSpec = $specSelected[$specLabel] ?? []; @endphp
        <fieldset class="facet-group">
          <legend class="form-label">{{ $specLabel }}</legend>
          <div class="facet-chips">
            @foreach($specValues as $specValue => $specCount)
              <label class="facet-chip">
                <input type="checkbox" name="spec[{{ $specLabel }}][]" value="{{ $specValue }}" @checked(in_array((string) $specValue, $pickedSpec, true))>
                <span>{{ $specValue }} <small class="opacity-60">{{ $specCount }}</small></span>
              </label>
            @endforeach
          </div>
        </fieldset>
      @endforeach

      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="form-label">{{ __('messages.MinPrice') }}</label>
          <input type="number" step="0.01" min="0" name="min" value="{{ $min }}" class="input" placeholder="0">
        </div>
        <div>
          <label class="form-label">{{ __('messages.MaxPrice') }}</label>
          <input type="number" step="0.01" min="0" name="max" value="{{ $max }}" class="input" placeholder="9999">
        </div>
      </div>

      <div>
        <label class="form-label">{{ __('messages.Sort') }}</label>
        <select name="sort" class="select">
          <option value="latest" @selected(($sort ?? 'latest') === 'latest')>{{ __('messages.Latest') }}</option>
          <option value="price_asc" @selected($sort === 'price_asc')>{{ __('messages.PriceUp') }}</option>
          <option value="price_desc" @selected($sort === 'price_desc')>{{ __('messages.PriceDown') }}</option>
        </select>
      </div>

      <button class="btn btn-primary btn-block">
        <x-store.icon name="filter" class="w-4 h-4" />
        {{ $isDrawer ? __('messages.ApplyAndClose') : __('messages.ApplyFilters') }}
      </button>
    </div>
  </div>
</form>
