@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $hidePrices = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
@endphp
<div class="px-container" style="padding-top: 24px">
  <nav aria-label="{{ __('messages.PxBreadcrumb') }}" class="px-muted" style="font-size: 13px; display: flex; gap: 8px">
    <a href="{{ route('store.index') }}" class="px-muted">{{ __('messages.Home') }}</a><span aria-hidden="true">/</span><span style="color: var(--px-ink)">{{ __('messages.PxCompareProducts') }}</span>
  </nav>

  <div class="px-head" style="margin-top: 12px">
    <div>
      <h1 class="px-h2" style="font-size: 36px">{{ __('messages.PxCompareProducts') }}</h1>
      <p class="px-sub">{{ $products->count() > 1 ? __('messages.PxCompareDiffHint') : __('messages.PxCompareEmptyHint') }}</p>
    </div>
    @if($products->isNotEmpty())
      <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
        @if(count($rows))
          <label class="px-cmp-toggle" style="border: 0; padding: 0" x-data="{ only: false }">
            <input type="checkbox" x-model="only" @change="document.querySelectorAll('[data-same]').forEach(r => r.hidden = only)">
            <span>{{ __('messages.PxOnlyDifferences') }}</span>
          </label>
        @endif
        <button type="button" class="px-clear" onclick="window.PxCompare.clear(); window.location.href = @js(route('store.compare'));">{{ __('messages.PxClearAll') }}</button>
      </div>
    @endif
  </div>

  @if($products->isEmpty())
    <div class="px-cmp" style="justify-content: center; text-align: center">
      <div class="px-cmp-copy" style="align-items: center">
        <p>{{ __('messages.PxCompareEmpty') }}</p>
        <a href="{{ route('store.shop') }}" class="px-btn px-btn-primary">{{ __('messages.PxBrowseAll') }}</a>
      </div>
    </div>
  @else
    <div class="px-table-wrap" tabindex="0" role="region" aria-label="{{ __('messages.PxCompareProducts') }}">
      <table class="px-table px-compare">
        <thead>
          <tr>
            <td></td>
            @foreach($products as $p)
              <th scope="col">
                @include('store.electronics_premium.partials.product-card', ['p' => $p, 'currency' => $currency, 'pxHasCompare' => false])
                <button type="button" class="px-clear" style="min-height: 36px" onclick="window.pxCompareRemove({{ (int) $p->id }})">{{ __('messages.Remove') }}</button>
              </th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @unless($hidePrices)
            <tr>
              <th scope="row">{{ __('messages.Price') }}</th>
              @foreach($products as $p)<td><b>{{ store_money((float) ($p->display_price ?? $p->price ?? 0)) }}</b></td>@endforeach
            </tr>
          @endunless
          <tr @if($products->pluck('brand.name')->unique()->count() <= 1) data-same @endif>
            <th scope="row">{{ __('messages.Brand') }}</th>
            @foreach($products as $p)<td>{{ $p->brand?->name ?? '—' }}</td>@endforeach
          </tr>
          @foreach($rows as $row)
            <tr @class(['is-diff' => $row['differs'] && $products->count() > 1]) @unless($row['differs']) data-same @endunless>
              <th scope="row">{{ $row['label'] }}</th>
              @foreach($row['values'] as $v)<td>{{ $v ?? '—' }}</td>@endforeach
            </tr>
          @endforeach
          <tr>
            <th scope="row">{{ __('messages.PxRating') }}</th>
            @foreach($products as $p)<td>{{ ! empty($p->rating_count) ? number_format((float) $p->rating_avg, 1).' ('.(int) $p->rating_count.')' : '—' }}</td>@endforeach
          </tr>
        </tbody>
      </table>
    </div>
  @endif
</div>

<script>
  window.pxCompareRemove = function (id) {
    window.PxCompare.remove(id);
    var ids = window.PxCompare.list().map(function (x) { return x.id; });
    window.location.href = @js(route('store.compare')) + '?ids=' + ids.join(',');
  };
  // The list lives in this browser: put it in the URL the first time.
  (function () {
    if (@json($hasIds)) return;
    var ids = window.PxCompare ? window.PxCompare.list().map(function (x) { return x.id; }) : [];
    if (ids.length) window.location.replace(@js(route('store.compare')) + '?ids=' + ids.join(','));
  })();
</script>
@include('store.partials.shop-modals-scripts', ['currency' => $currency])
@endsection
