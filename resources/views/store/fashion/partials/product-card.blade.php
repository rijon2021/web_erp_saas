{{-- Fashion theme product card: 3:4 photo (second photo on hover), badges,
     quick view, a hover quick-add bar (one button per size for size-only
     products, else the variant picker), name, price, colour swatches and the
     size run with sold-out sizes struck through. Same data-* contract as
     store/partials/product-card.blade.php so storefront.js keeps working. --}}
@php
  /** @var \App\Models\Product $p */
  $faOpts = $faOpts ?? \App\Support\FashionTheme::options($s ?? null);
  $productSlug = (string) $p->id;
  $galleryUrls = collect($p->productGalleryFilenames())->map(fn ($f) => product_image_url_or_null($f))->filter()->values()->all();
  $imgUrl = product_image_url($p->primaryProductImageFilename());
  $altImgUrl = collect($galleryUrls)->first(fn ($u) => $u !== $imgUrl);
  $descShort = \Illuminate\Support\Str::limit(strip_tags($p->note ?? ''), 600);
  $minPrice = (float) ($p->display_price ?? ($p->price ?? 0));
  $variants = collect($p->relationLoaded('variants') ? $p->variants : ($p->variants ?? []));
  $variantPayload = $variants->map(function ($v) {
    $final = (float) ($v->display_price ?? ($v->price ?? 0));
    return ['id' => (int) ($v->id ?? 0), 'name' => (string) ($v->name ?? ''), 'price' => (float) ($v->price ?? 0), 'display_price' => $final, 'display_price_formatted' => store_money($final), 'image' => product_image_url_or_null($v->image ?? null), 'stock' => (int) max(0, $v->stock ?? $v->qty ?? 0)];
  })->values();
  $productStock = $variants->isEmpty() ? (int) max(0, $p->stock ?? 0) : null;
  $variantOptions = store_variant_options($p); // null = legacy flat list
  $isPreorder = (bool) ($p->is_preorder ?? false);
  $preorderAlways = (bool) ($p->preorder_always ?? false);
  $preorderDate = $p->preorder_available_date ? store_date($p->preorder_available_date) : null;
  $quoteOnly = (($p->type ?? '') === 'is_service');
  $allowOverselling = isset($s) ? (bool) ($s->allow_overselling ?? true) : true;
  // "Always in stock" products are sold regardless of stock: no cart cap, never shown out of stock.
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
  if ($isPreorderActive || $allowOverselling) { $isAvailable = true; }
  elseif ($variants->isEmpty()) { $isAvailable = $productStock !== null && $productStock > 0; }
  else { $isAvailable = $variantPayload->contains(fn ($v) => ($v['stock'] ?? 0) > 0); }
  $compareAt = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
  $onSale = $compareAt !== null && $compareAt > $minPrice + 0.001;
  $pct = $onSale ? (int) round(($compareAt - $minPrice) / $compareAt * 100) : 0;
  $rating = isset($p->rating_avg) && $p->rating_avg !== null ? (float) $p->rating_avg : null;
  $ratingCount = (int) ($p->rating_count ?? 0);
  $isNew = $p->created_at && $p->created_at->gt(now()->subDays(30));
  $labelBadges = store_product_badges($p);
  $fit = \App\Support\FashionTheme::cardSizes($variantOptions, $variantPayload, $allowOverselling || $isPreorderActive);
  $swatches = collect($fit['colors'])->filter(fn ($c) => !empty($c['swatch']));
  $canBuy = $isAvailable && empty($hidePrices) && !$quoteOnly;
  $priceAttr = fn ($amount) => number_format((float) $amount, \App\utils\helpers::price_decimals(), '.', '');
@endphp
<article class="product-card fa-card" data-category="{{ $p->category_id }}">
  <div class="fa-card-wrap">
    <a href="{{ product_url($p->id) }}" class="fa-card-media" aria-label="{{ $p->name }}">
      <img src="{{ $imgUrl }}" alt="{{ $p->name }}" loading="lazy" onerror="this.onerror=null;this.src='{{ product_image_url('') }}'">
      @if($altImgUrl)<img class="fa-alt" src="{{ $altImgUrl }}" alt="" loading="lazy" onerror="this.remove()">@endif
    </a>
    <div class="fa-badges">
      @if($onSale && $pct > 0)<span class="fa-badge is-sale">-{{ $pct }}%</span>@endif
      @if($isPreorderActive)<span class="fa-badge is-pre">{{ __('messages.PreOrder') }}</span>
      @elseif(!$isAvailable)<span class="fa-badge is-out">{{ __('messages.OutOfStock') }}</span>
      @elseif($isNew && !$onSale)<span class="fa-badge">{{ __('messages.New') }}</span>@endif
      @foreach($labelBadges as $badge)<span class="fa-badge">{{ $badge['text'] }}</span>@endforeach
    </div>
    <div class="fa-card-actions">
      <button type="button" class="fa-card-action js-quick-view" title="{{ __('messages.QuickView') }}" aria-label="{{ __('messages.QuickView') }}"
              data-id="{{ $p->id }}" data-slug="{{ $productSlug }}" data-name="{{ e($p->name) }}" data-price="{{ $priceAttr($minPrice) }}"
              data-image="{{ $imgUrl }}" data-gallery='@json($galleryUrls)' data-currency="{{ $currency }}" data-description="{{ e($descShort) }}"
              data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}" data-delivery-date="{{ $preorderDate ?? '' }}" data-quote-only="{{ $quoteOnly ? '1' : '0' }}" data-variants='@json($variantPayload)' data-options='@json($variantOptions)'><x-store.icon name="eye" /></button>
    </div>
    @if($canBuy && !empty($faOpts['quick_add']))
      <div class="fa-quick">
        @if($fit['quick'])
          <span class="fa-quick-label">{{ __('messages.FashionQuickAddSize') }}</span>
          <div class="fa-qa-row">
            @foreach($fit['sizes'] as $size)
              @php $v = $size['variant']; $vImg = $v['image'] && ! str_ends_with($v['image'], '/no-image.png') ? $v['image'] : $imgUrl; @endphp
              <button type="button" class="fa-qa" @disabled(!$size['available'])
                      aria-label="{{ $size['available'] ? __('messages.FashionAddSize', ['size' => $size['label']]) : __('messages.FashionSizeSoldOut', ['size' => $size['label']]) }}"
                      data-product-id="{{ $p->id }}" data-variant-id="{{ $v['id'] }}" data-variant-name="{{ $v['name'] }}"
                      data-name="{{ e($p->name) }}" data-price="{{ $priceAttr($v['display_price']) }}" data-image="{{ $vImg }}"
                      data-slug="{{ $productSlug }}" data-currency="{{ $currency }}" data-stock="{{ $isPreorderActive || $v['stock'] === null ? '' : $v['stock'] }}"
                      data-added-label="{{ __('messages.Added') }}">{{ $size['label'] }}</button>
            @endforeach
          </div>
        @else
          <button type="button" class="fa-btn js-add-to-cart"
                  data-out-of-stock="0" data-is-preorder="{{ $isPreorderActive ? '1' : '0' }}"
                  data-id="{{ $p->id }}" data-slug="{{ $productSlug }}" data-name="{{ e($p->name) }}" data-price="{{ $priceAttr($minPrice) }}"
                  data-image="{{ $imgUrl }}" data-gallery='@json($galleryUrls)' data-currency="{{ $currency }}" data-qty="1" data-product-id="{{ $p->id }}" data-product-image="{{ $imgUrl }}"
                  data-variants='@json($variantPayload)' data-options='@json($variantOptions)' data-stock="{{ $isPreorderActive ? '' : ($productStock !== null ? $productStock : '') }}" data-added-label="{{ __('messages.Added') }}">
            {{ $isPreorderActive ? __('messages.PreOrder') : ($variantPayload->isNotEmpty() ? __('messages.FashionChooseOptions') : __('messages.FashionQuickAdd')) }}
          </button>
        @endif
      </div>
    @endif
  </div>
  <div class="fa-card-body">
    <div class="fa-card-row">
      <h3 class="fa-card-title" title="{{ $p->name }}"><a href="{{ product_url($p->id) }}">{{ $p->name }}</a></h3>
      @if(empty($hidePrices) && !$quoteOnly)
        <span class="fa-price {{ $onSale ? 'is-sale' : '' }}">{{ store_money($minPrice) }}@if($onSale)<s>{{ store_money($compareAt) }}</s>@endif</span>
      @endif
    </div>
    @if($swatches->isNotEmpty())
      <div class="fa-swatches" aria-label="{{ __('messages.FashionColours', ['count' => count($fit['colors'])]) }}">
        @foreach($swatches as $c)<span class="fa-sw" style="background: {{ $c['swatch'] }}" title="{{ $c['label'] }}"></span>@endforeach
        <small>{{ __('messages.FashionColours', ['count' => count($fit['colors'])]) }}</small>
      </div>
    @endif
    @if(!empty($faOpts['show_card_sizes']) && $fit['sizes'])
      <div class="fa-sizes" aria-label="{{ __('messages.FashionSizes') }}">
        @foreach($fit['sizes'] as $size)<span class="{{ $size['available'] ? '' : 'is-out' }}">{{ $size['label'] }}</span>@endforeach
      </div>
    @endif
    @if($rating !== null && $ratingCount > 0)
      <div class="fa-stars"><span class="fa-stars-icons">@for($i = 1; $i <= 5; $i++)<x-store.icon name="star-fill" class="{{ $i <= round($rating) ? '' : 'is-empty' }}" />@endfor</span><span>({{ $ratingCount }})</span></div>
    @endif
    @if(!empty($hidePrices) && !$quoteOnly)
      <a href="{{ $loginUrl ?? store_auth_url('login') }}" class="fa-link" style="align-self: flex-start">{{ __('messages.SignIn') }}</a>
    @elseif($quoteOnly)
      <button type="button" class="fa-btn fa-btn-ghost fa-card-quote js-request-quote" data-id="{{ $p->id }}" data-name="{{ e($p->name) }}">{{ __('messages.RequestQuotation') }}</button>
    @endif
    <div class="js-add-status" aria-live="polite"></div>
  </div>
</article>
