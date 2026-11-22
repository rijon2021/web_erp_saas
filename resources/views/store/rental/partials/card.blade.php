{{-- Rental theme item card: cover photo, featured badge, category, name,
     make/model or place line, the type's card properties as chips, and the
     price — "from X / day", or the total for the dates being searched.
     Expects $item (RentalItem); optional $quote (RentalWebsiteService::pickPeriod
     for the searched dates), $linkQuery (dates to carry to the item page),
     $showDeposit. --}}
@php
  $rnCardSpecs = collect(\App\Support\RentalTypes::specFields($item->type))->filter(fn ($f) => ! empty($f['card']));
  $rnChips = [];
  foreach ($rnCardSpecs as $f) {
      $v = $item->spec($f['id']);
      $txt = \App\Support\RentalTypes::display($f, $v);
      if ($txt !== null) { $rnChips[] = ['icon' => $f['icon'] ?? 'check', 'text' => $txt, 'label' => \App\Support\RentalTypes::specLabel($f)]; }
  }
  if ($item->type === 'property') {
      if ($item->bedrooms !== null) { $rnChips[] = ['icon' => 'bed', 'text' => $item->bedrooms, 'label' => __('messages.RntBedrooms')]; }
      if ($item->bathrooms !== null) { $rnChips[] = ['icon' => 'bath', 'text' => $item->bathrooms, 'label' => __('messages.RntBathrooms')]; }
      if ($item->area !== null) { $rnChips[] = ['icon' => 'ruler', 'text' => rtrim(rtrim(number_format((float) $item->area, 1), '0'), '.').' '.($item->area_unit ?: 'm²'), 'label' => __('messages.RntArea')]; }
  }
  $rnChips = array_slice($rnChips, 0, 4);
  $rnMeta = match ($item->type) {
      'vehicle' => collect([trim($item->brand.' '.$item->model), $item->year])->filter()->implode(' · '),
      'equipment' => trim($item->brand.' '.$item->model),
      default => collect([$item->city])->filter()->implode(''),
  };
  $rnHead = $item->headline();
  $rnHref = store_base_url('rentals/'.$item->publicKey()).(! empty($linkQuery) ? '?'.http_build_query($linkQuery) : '');
  $rnIcon = \App\Support\RentalTypes::definition($item->type)['icon'] ?? 'package';
@endphp
<a href="{{ $rnHref }}" class="rn-card">
  <div class="rn-card-photo">
    @if($cover = $item->coverUrl())
      <img src="{{ $cover }}" alt="{{ $item->name }}" loading="lazy">
    @else
      <div class="rn-card-ph"><x-store.icon :name="$rnIcon" /></div>
    @endif
    <div class="rn-badges">
      @if($item->featured)<span class="rn-badge rn-badge-accent"><x-store.icon name="star" />{{ __('messages.RntFeatured') }}</span>@endif
      @if(! empty($quote))<span class="rn-badge rn-badge-ok"><x-store.icon name="check" />{{ __('messages.RntFreeForDates') }}</span>@endif
    </div>
  </div>
  <div class="rn-card-body">
    @if($item->category)<div class="rn-card-cat">{{ $item->category->name }}</div>@endif
    <h3 class="rn-card-title">{{ $item->name }}</h3>
    @if($rnMeta !== '')<div class="rn-card-meta">{{ $rnMeta }}</div>@endif
    @if($rnChips)
      <div class="rn-chips">
        @foreach($rnChips as $c)<span class="rn-chip" title="{{ $c['label'] }}"><x-store.icon :name="$c['icon']" /><span class="rn-sr">{{ $c['label'] }}: </span>{{ $c['text'] }}</span>@endforeach
      </div>
    @endif
    <div class="rn-card-foot">
      <div>
        @if(! empty($quote))
          <span class="rn-from">{{ trans_choice('messages.RntPeriods_'.$quote['period'], $quote['periods'], ['n' => $quote['periods']]) }}</span>
          <span class="rn-price">{{ store_money($quote['amount']) }}</span>
        @elseif($rnHead)
          <span class="rn-from">{{ __('messages.RntFrom') }}</span>
          <span class="rn-price">{{ store_money($rnHead['rate']) }} <small>{{ __('messages.RntPer_'.$rnHead['period']) }}</small></span>
        @else
          <span class="rn-from">{{ __('messages.RntPriceOnRequest') }}</span>
        @endif
      </div>
      @if(($showDeposit ?? true) && (float) $item->deposit_amount > 0)
        <div class="rn-deposit">{{ __('messages.RntDeposit') }}<br><b>{{ store_money($item->deposit_amount) }}</b></div>
      @endif
    </div>
  </div>
</a>
