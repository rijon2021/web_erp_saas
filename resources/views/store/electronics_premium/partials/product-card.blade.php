{{-- Electronics Premium product card. Same data-* contract as
     store/partials/product-card.blade.php so storefront.js (add to cart,
     quick view, request quote) keeps working; adds brand, spec chips
     (products.specs highlights) and the compare toggle. --}}
@php
  /** @var \App\Models\Product $p */
  $productSlug = (string) $p->id;
  $galleryUrls = collect($p->productGalleryFilenames())->map(fn ($f) => product_image_url_or_null($f))->filter()->values()->all();
  $imgUrl = product_image_url($p->primaryProductImageFilename());
  $descShort = \Illuminate\Support\Str::limit(strip_tags($p->note ?? ''), 600);
  $minPrice = (float) ($p->display_price ?? ($p->price ?? 0));
  $variants = collect($p->relationLoaded('variants') ? $p->variants : ($p->variants ?? []));
  $variantPayload = $variants->map(function ($v) {
    $final = (float) ($v->display_price ?? ($v->price ?? 0));
    return [
      'id' => (int) ($v->id ?? 0), 'name' => (string) ($v->name ?? ''), 'price' => (float) ($v->price ?? 0),
      'display_price' => $final, 'display_price_formatted' => store_money($final),
      'image' => product_image_url_or_null($v->image ?? null), 'stock' => (int) max(0, $v->stock ?? $v->qty ?? 0),
    ];
  })->values();
  $productStock = $variants->isEmpty() ? (int) max(0, $p->stock ?? 0) : null;
  $variantOptions = store_variant_options($p);
  $isPreorder = (bool) ($p->is_preorder ?? false);
  $preorderAlways = (bool) ($p->preorder_always ?? false);
  $preorderDate = $p->preorder_available_date ? store_date($p->preorder_available_date) : null;
  $quoteOnly = (($p->type ?? '') === 'is_service');
  $allowOverselling = isset($s) ? (bool) ($s->allow_overselling ?? true) : true;
  if ((bool) ($p->always_in_stock ?? false)) {
    $allowOverselling = true;
    $productStock = null;
    $variantPayload = $variantPayload->map(fn ($v) => ['stock' => null] + $v);
  }
  $hidePrices = !Auth::guard('store')->check() && isset($s) && ($s->hide_prices_for_guests ?? false);
  $currency = $currency ?? store_currency()['symbol'];

  $isPreorderActive = false;
  if ($isPreorder) {
    $outOfStock = $variants->isEmpty() ? ($productStock !== null && $productStock <= 0) : !$variantPayload->contains(fn ($v) => ($v['stock'] ?? 0) > 0);
    if ($preorderAlways || $outOfStock) { $isPreorderActive = true; }
  }
  if ($isPreorderActive || $allowOverselling) {
    $isAvailable = true;
  } elseif ($variants->isEmpty()) {
    $isAvailable = $productStock !== null && $productStock > 0;
  } else {
    $isAvailable = $variantPayload->contains(fn ($v) => ($v['stock'] ?? 0) > 0);
  }

  $compareAt = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
  $onSale = $compareAt !== null && $compareAt > $minPrice + 0.001;
  $pct = $onSale ? (int) round(($compareAt - $minPrice) / $compareAt * 100) : 0;
  $rating = isset($p->rating_avg) && $p->rating_avg !== null ? (float) $p->rating_avg : null;
  $ratingCount = (int) ($p->rating_count ?? 0);
  $isNew = $p->created_at && $p->created_at->gt(now()->subDays(30));
  $labelBadges = store_product_badges($p);
  $brandName = $p->relationLoaded('brand') ? ($p->brand?->name) : null;
  $specChips = \App\Support\StoreSpecs::highlights($p, 3);
  $showSold = !empty($showSold) && $productStock !== null && $productStock > 0 && $productStock <= 10 && !$allowOverselling;
  $compareItem = ['id' => (int) $p->id, 'name' => (string) $p->name, 'image' => $imgUrl, 'url' => product_url($p->id), 'price' => $hidePrices ? '' : store_money($minPrice)];
  $pxHasCompare = $pxHasCompare ?? Route::has('store.compare');
@endphp
<article class="product-card px-card">
  <a href="{{ product_url($p->id) }}" class="px-card-media" tabindex="-1" aria-hidden="true">
    <img src="{{ $imgUrl }}" alt="" loading="lazy" onerror="this.onerror=null;this.src='{{ product_image_url('') }}'">
    <span class="px-card-badges">
      @if($onSale && $pct > 0)<span class="px-off">-{{ $pct }}%</span>@endif
      @if($isPreorderActive)<span class="px-pre">{{ __('messages.PreOrder') }}</span>
      @elseif(!$isAvailable)<span class="px-out">{{ __('messages.OutOfStock') }}</span>
      @elseif($isNew && !$onSale)<span class="px-new">{{ __('messages.New') }}</span>@endif
      @foreach($labelBadges as $badge)
        <span class="px-new">{{ $badge['text'] }}</span>
      @endforeach
    </span>
  </a>
  <button type="button" class="px-card-quick js-quick-view" aria-label="{{ __('messages.QuickView') }}: {{ $p->name }}"
          data-id="{{ $p->id }}" data-slug="{{ $productSlug }}" data-name="{{ e($p->name) }}"
          data-price="{{ number_format($minPrice, \App\utils\helpers::price_decimals(), '.', '') }}"
          data-image="{{ $imgUrl }}" data-gallery='@json($galleryUrls)' data-currency="{{ $currency }}"
          data-description="{{ e($descShort) }}" data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}"
          data-delivery-date="{{ $preorderDate ?? '' }}" data-quote-only="{{ $quoteOnly ? '1' : '0' }}" data-variants='@json($variantPayload)' data-options='@json($variantOptions)'>
    <x-store.icon name="eye" />
  </button>

  <span class="px-card-brand">{{ $brandName ?? '' }}</span>
  <h3 class="px-card-title" title="{{ $p->name }}"><a href="{{ product_url($p->id) }}">{{ $p->name }}</a></h3>

  @if($specChips)
    <div class="px-chips">
      @foreach($specChips as $chip)<span title="{{ $chip['label'] }}: {{ $chip['value'] }}">{{ $chip['value'] }}</span>@endforeach
    </div>
  @endif

  @if($rating !== null && $ratingCount > 0)
    <span class="px-stars"><x-store.icon name="star-fill" /><b>{{ number_format($rating, 1) }}</b>({{ \App\Support\ElectronicsTheme::compact($ratingCount) }})</span>
  @elseif(($s->show_stock ?? true) && !$quoteOnly && $productStock !== null && !$allowOverselling && !$showSold)
    <span class="px-card-stock">{{ $productStock > 0 ? __('messages.X_in_stock', ['count' => $productStock]) : __('messages.OutOfStock') }}</span>
  @endif

  @if($showSold)
    <div class="px-sold">
      <div class="px-sold-bar"><i style="width: {{ max(8, (int) round($productStock / 10 * 100)) }}%"></i></div>
      <span>{{ __('messages.PxOnlyLeft', ['count' => $productStock]) }}</span>
    </div>
  @endif

  <div class="px-card-foot">
    @if(empty($hidePrices) && !$quoteOnly)
      <span class="px-price"><b>{{ store_money($minPrice) }}</b>@if($onSale)<s>{{ store_money($compareAt) }}</s>@endif</span>
      <button type="button" class="px-add js-add-to-cart {{ $isPreorderActive ? 'is-pre' : '' }}"
              @if(!$isAvailable) disabled @endif
              aria-label="{{ $isPreorderActive ? __('messages.PreOrderNow') : __('messages.AddToCart') }}: {{ $p->name }}"
              data-out-of-stock="{{ $isAvailable ? '0' : '1' }}" data-is-preorder="{{ $isPreorderActive ? '1' : '0' }}"
              data-id="{{ $p->id }}" data-slug="{{ $productSlug }}" data-name="{{ e($p->name) }}"
              data-price="{{ number_format($minPrice, \App\utils\helpers::price_decimals(), '.', '') }}"
              data-image="{{ $imgUrl }}" data-gallery='@json($galleryUrls)' data-currency="{{ $currency }}" data-qty="1"
              data-product-id="{{ $p->id }}" data-product-image="{{ $imgUrl }}" data-variants='@json($variantPayload)' data-options='@json($variantOptions)'
              data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}"
              data-added-label="{{ __('messages.Added') }}">
        @if($isPreorderActive)<x-store.icon name="clock" />@else<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>@endif
      </button>
    @elseif($quoteOnly)
      <button type="button" class="px-btn px-btn-ghost px-btn-sm px-quote js-request-quote" data-id="{{ $p->id }}" data-name="{{ e($p->name) }}">{{ __('messages.RequestQuotation') }}</button>
    @else
      <a href="{{ $loginUrl ?? route('store.login.show') }}" class="px-link">{{ __('messages.SignIn') }}</a>
    @endif
  </div>
  <div class="js-add-status"></div>

  @if($pxHasCompare)
    <label class="px-cmp-toggle" x-data='pxCompareToggle(@json($compareItem, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP))' :class="{ 'is-on': on }">
      <input type="checkbox" :checked="on" @change="toggle($event)">
      <span x-text="text">{{ __('messages.PxAddToCompare') }}</span>
    </label>
  @endif
</article>
