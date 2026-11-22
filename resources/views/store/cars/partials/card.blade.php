{{-- Car Dealership theme vehicle card: photo, condition / price-drop badges,
     photo count, year-make-model title, trim, four key specs, price with the
     struck-through old price and an estimated monthly payment. The whole card
     links to the vehicle page. --}}
@php
  /** @var \App\Models\Product $p */
  $cdOpts = $cdOpts ?? \App\Support\CarsTheme::options($s ?? null);
  $spec = $p->relationLoaded('vehicleSpec') ? $p->getRelation('vehicleSpec') : null;
  $imgUrl = product_image_url($p->primaryProductImageFilename());
  $photoCount = count($p->productGalleryFilenames());
  $title = $spec && $spec->headline() !== '' ? $spec->headline() : $p->name;
  $subtitle = $spec ? ($spec->trim ?: ($spec->headline() !== '' && $spec->headline() !== $p->name ? $p->name : '')) : '';
  $price = (float) ($p->display_price ?? ($p->price ?? 0));
  $compareAt = isset($p->compare_at_price) ? (float) $p->compare_at_price : null;
  $onSale = $compareAt !== null && $compareAt > $price + 0.001;
  $hidePrices = !Auth::guard('store')->check() && isset($s) && ($s->hide_prices_for_guests ?? false);
  $allowOverselling = isset($s) ? (bool) ($s->allow_overselling ?? true) : true;
  $variantsCd = collect($p->relationLoaded('variants') ? $p->variants : []);
  $inStock = (bool) ($p->always_in_stock ?? false) || $allowOverselling || (bool) ($p->is_preorder ?? false)
    || ($variantsCd->isEmpty() ? (int) ($p->stock ?? 0) > 0 : $variantsCd->contains(fn ($v) => (int) ($v->stock ?? 0) > 0));
  $sold = ($spec && $spec->is_sold) || ! $inStock;
  $specLines = $spec ? array_filter([
    'calendar' => $spec->year,
    'gauge' => \App\Support\CarsTheme::mileage($spec->mileage, $cdOpts),
    'fuel' => $spec->fuel ? __('messages.CarFuel_'.$spec->fuel) : null,
    'gearbox' => $spec->transmission ? __('messages.CarGear_'.$spec->transmission) : null,
  ]) : [];
@endphp
<article class="cd-card product-card" data-category="{{ $p->category_id }}">
  <a href="{{ product_url($p->id) }}" class="cd-card-media" tabindex="-1" aria-hidden="true">
    <img src="{{ $imgUrl }}" alt="" loading="lazy" onerror="this.onerror=null;this.src='{{ product_image_url('') }}'">
    @if($photoCount > 1)<span class="cd-photos"><x-store.icon name="camera" />{{ $photoCount }}</span>@endif
  </a>
  <div class="cd-badges">
    @if($sold)<span class="cd-badge is-sold">{{ __('messages.CarSold') }}</span>@endif
    @if($spec)
      <span class="cd-badge {{ $spec->condition === 'new' ? 'is-new' : ($spec->condition === 'certified' ? 'is-cert' : '') }}">{{ __('messages.CarCondition_'.$spec->condition) }}</span>
    @endif
    @if($onSale && !$hidePrices)<span class="cd-badge is-drop">{{ __('messages.CarPriceDrop') }}</span>@endif
  </div>
  <div class="cd-card-body">
    <div>
      <h3 class="cd-card-title"><a href="{{ product_url($p->id) }}">{{ $title }}</a></h3>
      @if($subtitle !== '')<div class="cd-trim">{{ $subtitle }}</div>@endif
    </div>
    @if($specLines)
      <div class="cd-specs">
        @foreach($specLines as $icon => $text)<span><x-store.icon :name="$icon" />{{ $text }}</span>@endforeach
      </div>
    @endif
    @if(!$hidePrices)
      <div class="cd-card-foot">
        <div>
          @if($onSale)<span class="cd-was">{{ store_money($compareAt) }}</span>@endif
          <span class="cd-price">{{ store_money($price) }}</span>
        </div>
        @if(!empty($cdOpts['show_monthly_on_cards']) && $price > 0 && !$sold)
          <span class="cd-monthly">{!! __('messages.CarPerMonthEst', ['amount' => '<strong>'.e(store_money(\App\Support\CarsTheme::monthly($price, $cdOpts))).'</strong>']) !!}</span>
        @endif
      </div>
    @endif
  </div>
</article>
