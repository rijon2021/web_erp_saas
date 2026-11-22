@extends('layouts.store')

@section('content')
@php
  $shopUrl = store_route_or('store.shop');
  $images = $item->imageUrls();
  $icon = \App\Support\RentalTypes::definition($item->type)['icon'] ?? 'package';
  $flavour = \App\Support\RentalTheme::flavour([$item->type]);
  $times = [];
  for ($m = 0; $m < 1440; $m += 30) { $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
  $minDate = now()->addHours((int) ($rnSettings->website_min_notice_hours ?? 2))->format('Y-m-d');
  $maxDate = now()->addDays((int) ($rnSettings->website_max_days_ahead ?: 180))->format('Y-m-d');
  $head = $item->headline();

  // Key facts (cards' properties) + the full property table.
  $keyspecs = [];
  $rows = [];
  $tagsBlocks = [];
  foreach ($specs as $f) {
      $v = $item->spec($f['id']);
      $txt = \App\Support\RentalTypes::display($f, $v);
      if ($txt === null) continue;
      if (in_array($f['input'], ['multi', 'tags'], true)) {
          $tagsBlocks[] = ['label' => \App\Support\RentalTypes::specLabel($f), 'items' => $f['input'] === 'multi' ? array_map(fn ($o) => \App\Support\RentalTypes::optionLabel((string) $o), (array) $v) : (array) $v];
          continue;
      }
      if (! empty($f['card'])) $keyspecs[] = ['icon' => $f['icon'] ?? 'check', 'label' => \App\Support\RentalTypes::specLabel($f), 'value' => $txt];
      $rows[] = [\App\Support\RentalTypes::specLabel($f), $txt];
  }
  if (in_array($item->type, ['vehicle', 'equipment'], true)) {
      if ($item->brand) array_unshift($rows, [$item->type === 'vehicle' ? __('messages.RntMake') : __('messages.Brand'), $item->brand]);
      if ($item->model) $rows[] = [__('messages.RntModel'), $item->model];
      if ($item->year) $rows[] = [__('messages.RntYear'), $item->year];
  }
  if ($item->type === 'property') {
      foreach ([['bedrooms', 'bed', __('messages.RntBedrooms')], ['bathrooms', 'bath', __('messages.RntBathrooms')]] as [$col, $ic, $lb]) {
          if ($item->{$col} !== null) { $keyspecs[] = ['icon' => $ic, 'label' => $lb, 'value' => $item->{$col}]; $rows[] = [$lb, $item->{$col}]; }
      }
      if ($item->area !== null) { $a = rtrim(rtrim(number_format((float) $item->area, 1), '0'), '.').' '.($item->area_unit ?: 'm²'); $keyspecs[] = ['icon' => 'ruler', 'label' => __('messages.RntArea'), 'value' => $a]; $rows[] = [__('messages.RntArea'), $a]; }
      if ($item->floor) $rows[] = [__('messages.RntFloor'), $item->floor];
      $rows[] = [__('messages.RntFurnished'), $item->furnished ? __('messages.RntYes') : __('messages.RntNo')];
      if (! empty($item->amenities)) $tagsBlocks[] = ['label' => __('messages.RntAmenities'), 'items' => (array) $item->amenities];
  }
  if ((int) $item->quantity > 1) $rows[] = [__('messages.RntUnits'), $item->quantity];
  $keyspecs = array_slice($keyspecs, 0, 6);

  $rates = collect(\App\Models\RentalItem::RATE_COLUMNS)->map(fn ($c, $p) => $item->rateFor($p))->filter(fn ($r) => $r !== null);
  $place = collect([$item->address, $item->city])->filter()->implode(', ');
  $subParts = array_values(array_filter([
      $item->category ? ['tag', $item->category->name] : null,
      in_array($item->type, ['vehicle', 'equipment'], true) && trim($item->brand.' '.$item->model.' '.$item->year) !== '' ? [$icon, trim($item->brand.' '.$item->model.($item->year ? ' · '.$item->year : ''))] : null,
      $place !== '' ? ['map-pin', $place] : null,
  ]));
  $defaults = [];
  foreach ($formFields as $f) {
      if ($f['input'] === 'choice') $defaults[$f['id']] = $f['options'][0] ?? '';
      elseif ($f['input'] === 'bool') $defaults[$f['id']] = false;
      // An optional location starts on "—" so nobody sends the first branch by accident.
      elseif ($f['input'] === 'location') $defaults[$f['id']] = $f['mode'] === 'required' ? ($f['options'][0] ?? '') : '';
      else $defaults[$f['id']] = '';
  }
  $contact = [
      'name' => $customer ? (optional($customer->client)->name ?: $customer->username) : '',
      'phone' => $customer ? (optional($customer->client)->phone ?: '') : '',
      'email' => $customer ? ($customer->email ?: '') : '',
  ];
  $cfg = [
      'itemId' => $item->id,
      'quoteUrl' => route('store.rental.quote'),
      'bookUrl' => route('store.rental.book'),
      'calendarUrl' => route('store.rental.calendar', $item->id),
      'start' => $start ? $start->format('Y-m-d') : '',
      'startTime' => $start ? $start->format('H:i') : '10:00',
      'end' => $end ? $end->format('Y-m-d') : '',
      'endTime' => $end ? $end->format('H:i') : '10:00',
      'maxQty' => max(1, (int) $item->quantity),
      'fields' => $defaults,
      'contact' => $contact,
      'locale' => str_replace('_', '-', app()->getLocale()),
      'msg' => [
          'error' => __('messages.RntErrGeneric'),
          'states' => [
              'free' => __('messages.RntLegendFree'),
              'partial' => __('messages.RntLegendPartial'),
              'busy' => __('messages.RntLegendBusy'),
              'closed' => __('messages.RntLegendClosed'),
              'end' => __('messages.RntLegendEnd'),
              'loading' => '',
          ],
      ],
  ];
  $canBook = $rnBookingOpen && ! ($requireLogin && ! $customer);
@endphp

<div class="rn-container">
  <div class="rn-page-head" style="padding-bottom: 12px">
    <nav class="rn-crumbs" aria-label="{{ __('messages.RntBreadcrumb') }}">
      <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span>/</span>
      <a href="{{ $shopUrl }}{{ count($rnTypes) > 1 ? '?type='.$item->type : '' }}">{{ \App\Support\RentalTypes::typeLabel($item->type) }}</a>
      @if($item->category)<span>/</span><a href="{{ $shopUrl }}?{{ http_build_query(array_filter(['category' => $item->category->id, 'type' => count($rnTypes) > 1 ? $item->type : null])) }}">{{ $item->category->name }}</a>@endif
    </nav>
  </div>

  <div class="rn-item" x-data="rnItem(@js($cfg))">
    <div>
      {{-- Gallery --}}
      <div x-data="{ i: 0, n: {{ count($images) }} }">
        <div class="rn-gallery-main">
          @forelse($images as $k => $src)
            <img src="{{ $src }}" alt="{{ $item->name }}" x-show="i === {{ $k }}" @if($k > 0) x-cloak loading="lazy" @endif>
          @empty
            <div class="rn-card-ph"><x-store.icon :name="$icon" /></div>
          @endforelse
          @if(count($images) > 1)
            <button type="button" class="rn-gallery-nav prev" @click="i = (i + n - 1) % n" aria-label="{{ __('messages.RntPrevious') }}"><x-store.icon name="chevron-left" class="store-ico-flip" /></button>
            <button type="button" class="rn-gallery-nav next" @click="i = (i + 1) % n" aria-label="{{ __('messages.RntNext') }}"><x-store.icon name="chevron-right" class="store-ico-flip" /></button>
          @endif
          <div class="rn-badges">@if($item->featured)<span class="rn-badge rn-badge-accent"><x-store.icon name="star" />{{ __('messages.RntFeatured') }}</span>@endif</div>
        </div>
        @if(count($images) > 1)
          <div class="rn-thumbs">
            @foreach(array_slice($images, 0, 12) as $k => $src)
              <button type="button" :class="i === {{ $k }} && 'is-active'" @click="i = {{ $k }}" aria-label="{{ __('messages.RntPhoto', ['n' => $k + 1]) }}"><img src="{{ $src }}" alt="" loading="lazy"></button>
            @endforeach
          </div>
        @endif
      </div>

      <h1 class="rn-item-title">{{ $item->name }}</h1>
      @if($subParts)
        <div class="rn-item-sub">@foreach($subParts as [$ic, $txt])<span><x-store.icon :name="$ic" />{{ $txt }}</span>@endforeach</div>
      @endif

      @if($keyspecs)
        <div class="rn-keyspecs">
          @foreach($keyspecs as $k)<div class="rn-keyspec"><x-store.icon :name="$k['icon']" /><div><small>{{ $k['label'] }}</small><b>{{ $k['value'] }}</b></div></div>@endforeach
        </div>
      @endif

      @if(trim((string) $item->description) !== '')
        <div class="rn-block"><h2>{{ __('messages.RntAbout_'.$flavour) }}</h2><div class="rn-desc">{{ $item->description }}</div></div>
      @endif

      @if($rows)
        <div class="rn-block">
          <h2>{{ __('messages.RntSpecifications') }}</h2>
          <table class="rn-specs"><tbody>@foreach($rows as [$label, $value])<tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table>
        </div>
      @endif

      @foreach($tagsBlocks as $tb)
        <div class="rn-block"><h2>{{ $tb['label'] }}</h2><div class="rn-tags">@foreach($tb['items'] as $t)<span class="rn-tag"><x-store.icon name="check" />{{ $t }}</span>@endforeach</div></div>
      @endforeach

      @if($rates->isNotEmpty())
        <div class="rn-block">
          <h2>{{ __('messages.RntPrices') }}</h2>
          <div class="rn-rates">
            @foreach($rates as $p => $r)
              <div class="rn-rate {{ $p === $item->default_period ? 'is-main' : '' }}"><small>{{ __('messages.RntPeriodName_'.$p) }}</small><b>{{ store_money($r) }}</b></div>
            @endforeach
          </div>
          <p class="rn-note">
            @if($showDeposit && (float) $item->deposit_amount > 0){{ __('messages.RntDepositNote', ['amount' => store_money($item->deposit_amount)]) }} @endif
            @if($item->min_duration){{ __('messages.RntMinNote', ['n' => $item->min_duration, 'unit' => __('messages.RntUnit_'.($item->min_duration_unit ?: $item->default_period))]) }} @endif
            {{ __('messages.RntCheapestNote') }}
          </p>
        </div>
      @endif

      {{-- Availability calendar --}}
      <div class="rn-block" id="availability">
        <div class="rn-cal-head">
          <h2 style="margin: 0">{{ __('messages.RntAvailability') }}</h2>
          <div style="display: flex; gap: 6px">
            <button type="button" class="rn-icon-btn" @click="shiftMonths(-1)" :disabled="!canPrev" aria-label="{{ __('messages.RntPrevious') }}"><x-store.icon name="chevron-left" class="store-ico-flip" /></button>
            <button type="button" class="rn-icon-btn" @click="shiftMonths(1)" aria-label="{{ __('messages.RntNext') }}"><x-store.icon name="chevron-right" class="store-ico-flip" /></button>
          </div>
        </div>
        <p class="rn-note" style="margin-top: 0">{{ __('messages.RntCalendarHelp') }}</p>
        <div class="rn-cal-months">
          <template x-for="m in months" :key="m.key">
            <div class="rn-cal-month">
              <h4 x-text="m.title"></h4>
              <div class="rn-cal-grid">
                <template x-for="(d, di) in dow" :key="'dow' + di"><div class="rn-cal-dow" x-text="d"></div></template>
                <template x-for="c in m.cells" :key="c.key">
                  <button type="button" class="rn-day" :class="dayClass(c)" :disabled="!c.date || c.state === 'closed' || c.state === 'loading'"
                          @click="pickDay(c)" x-text="c.n" :aria-label="c.date ? c.date + ' — ' + stateLabel(c.state) : ''"></button>
                </template>
              </div>
            </div>
          </template>
        </div>
        <div class="rn-legend">
          <span><i style="background: var(--rn-ok-soft); box-shadow: inset 0 0 0 1px var(--rn-ok)"></i>{{ __('messages.RntLegendFree') }}</span>
          @if((int) $item->quantity > 1)<span><i style="background: var(--rn-warn-soft); box-shadow: inset 0 0 0 1px var(--rn-warn)"></i>{{ __('messages.RntLegendPartial') }}</span>@endif
          <span><i style="background: var(--rn-bad-soft); box-shadow: inset 0 0 0 1px var(--rn-bad)"></i>{{ __('messages.RntLegendBusy') }}</span>
          <span><i style="background: var(--rn-accent)"></i>{{ __('messages.RntLegendSelected') }}</span>
          <span x-show="months.some(m => m.cells.some(c => c.state === 'end'))" x-cloak><i style="background: var(--rn-soft); box-shadow: inset 0 0 0 1px var(--rn-line-2)"></i>{{ __('messages.RntLegendEnd') }}</span>
        </div>
      </div>
    </div>

    {{-- Booking panel --}}
    {{-- The aside is the sticky element (its grid cell is only as tall as the panel);
         the long details form scrolls with the page instead. --}}
    <aside class="rn-book-aside" :class="step === 'details' && 'is-static'">
      <div class="rn-book">
        @if($head)
          <div class="rn-book-price"><span class="rn-price" style="font-size: 28px">{{ store_money($head['rate']) }}</span><small style="color: var(--rn-muted); font-weight: 600">{{ __('messages.RntPer_'.$head['period']) }}</small></div>
        @endif
        @if($showDeposit && (float) $item->deposit_amount > 0)
          <div class="rn-help" style="margin-top: 0">{{ __('messages.RntDeposit') }}: {{ store_money($item->deposit_amount) }}</div>
        @endif

        <div class="rn-book-grid">
          <div class="rn-field">
            <span class="rn-label">{{ __('messages.RntPickup_'.$flavour) }}</span>
            <div class="rn-when">
              <input type="date" class="rn-input" x-model="start" @change="changed()" min="{{ $minDate }}" max="{{ $maxDate }}" aria-label="{{ __('messages.RntStartDate') }}">
              <select class="rn-input" x-model="startTime" @change="changed()" aria-label="{{ __('messages.RntStartTime') }}">@foreach($times as $tm)<option value="{{ $tm }}">{{ $tm }}</option>@endforeach</select>
            </div>
          </div>
          <div class="rn-field">
            <span class="rn-label">{{ __('messages.RntReturn_'.$flavour) }}</span>
            <div class="rn-when">
              {{-- No max: only the START is limited by the booking horizon. --}}
              <input type="date" class="rn-input" x-model="end" @change="changed()" :min="start || '{{ $minDate }}'" aria-label="{{ __('messages.RntEndDate') }}">
              <select class="rn-input" x-model="endTime" @change="changed()" aria-label="{{ __('messages.RntEndTime') }}">@foreach($times as $tm)<option value="{{ $tm }}">{{ $tm }}</option>@endforeach</select>
            </div>
          </div>
          @if((int) $item->quantity > 1)
            <label class="rn-field" style="grid-column: 1 / -1">
              <span class="rn-label">{{ __('messages.RntQuantity') }}</span>
              <input type="number" class="rn-input" x-model.number="qty" min="1" :max="maxQty" @change="changed()">
            </label>
          @endif
        </div>

        <div x-show="loading" class="rn-quote" x-cloak><span class="rn-quote-row"><span>{{ __('messages.RntChecking') }}</span><span class="rn-spin"></span></span></div>
        <template x-if="!loading && quote && quote.message">
          <div class="rn-alert" :class="quote.ok ? 'rn-alert-info' : 'rn-alert-warn'" style="margin: 10px 0"><x-store.icon name="alert-circle" /><span x-text="quote.message"></span></div>
        </template>
        <template x-if="!loading && quote && quote.ok">
          <div class="rn-quote">
            <div class="rn-quote-row"><span x-text="quote.periods_label + ' × ' + quote.rate_label + (qty > 1 ? ' × ' + qty : '')"></span><span x-text="quote.rent_label"></span></div>
            @if($showDeposit)<div class="rn-quote-row" x-show="quote.deposit > 0"><span>{{ __('messages.RntDepositRefundable') }}</span><span x-text="quote.deposit_label"></span></div>@endif
            <div class="rn-quote-row rn-quote-total"><span>{{ __('messages.RntEstimatedTotal') }}</span><span x-text="quote.rent_label"></span></div>
            <div class="rn-help" style="margin: 0">{{ __('messages.RntNothingToPayNow') }}</div>
          </div>
        </template>
        {{-- Booking errors, outside the form: a "dates" error sends the visitor back to the dates step. --}}
        <div class="rn-alert rn-alert-bad" x-show="message" x-cloak role="alert" style="margin-top: 12px"><x-store.icon name="alert-circle" /><span x-text="message"></span></div>

        @if(! $rnBookingOpen)
          <div class="rn-alert rn-alert-info" style="margin-top: 12px"><x-store.icon name="phone" /><span>{{ __('messages.RntBookingByPhone') }} @if($s->contact_phone)<a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}" class="rn-link">{{ $s->contact_phone }}</a>@endif</span></div>
        @elseif($requireLogin && ! $customer)
          {{-- Relative path: the login page only accepts same-origin "/…" redirects. --}}
          <a href="{{ store_auth_url('login') }}?redirect={{ urlencode(request()->getRequestUri()) }}" class="rn-btn rn-btn-block" style="margin-top: 10px"><x-store.icon name="log-in" />{{ __('messages.RntSignInToBook') }}</a>
        @else
          <button type="button" class="rn-btn rn-btn-block" style="margin-top: 6px" x-show="step === 'dates'" :disabled="!quote || !quote.ok || loading" @click="step = 'details'; $nextTick(() => $refs.name && $refs.name.focus())">
            <x-store.icon name="calendar-check" />{{ __('messages.RntRequestBooking') }}
          </button>
        @endif

        @if($canBook)
        <form x-show="step === 'details'" x-cloak @submit.prevent="submit($event.target)" novalidate enctype="multipart/form-data">
          <div class="rn-form-section">
            <h3>{{ __('messages.RntYourDetails') }}</h3>
            <div class="rn-form-grid">
              <label class="rn-field rn-full"><span class="rn-label">{{ __('messages.RntYourName') }}</span>
                <input type="text" name="name" x-ref="name" class="rn-input" :class="err('name') && 'is-invalid'" x-model="contact.name" autocomplete="name" required maxlength="150">
                <div class="rn-error" x-show="err('name')" x-text="err('name')"></div></label>
              <label class="rn-field"><span class="rn-label">{{ __('messages.Phone') }}</span>
                <input type="tel" name="phone" class="rn-input" :class="err('phone') && 'is-invalid'" x-model="contact.phone" autocomplete="tel" required maxlength="40">
                <div class="rn-error" x-show="err('phone')" x-text="err('phone')"></div></label>
              <label class="rn-field"><span class="rn-label">{{ __('messages.Email') }}</span>
                <input type="email" name="email" class="rn-input" :class="err('email') && 'is-invalid'" x-model="contact.email" autocomplete="email" required maxlength="190">
                <div class="rn-error" x-show="err('email')" x-text="err('email')"></div></label>
            </div>
          </div>

          @if($formFields)
          <div class="rn-form-section">
            <h3>{{ __('messages.RntBookingDetails_'.$flavour) }}</h3>
            <div class="rn-form-grid">
              @foreach($formFields as $f)
                @php
                  $fid = $f['id'];
                  $fname = $f['input'] === 'file' ? 'field_files['.$fid.']' : 'fields['.$fid.']';
                  $ekey = ($f['input'] === 'file' ? 'field_files.' : 'fields.').$fid;
                  $wide = in_array($f['input'], ['textarea', 'choice', 'file', 'bool', 'location'], true);
                  $label = \App\Support\RentalTypes::fieldLabel($f);
                  $optional = $f['mode'] !== 'required' ? ' <span class="rn-opt">('.e(__('messages.RntOptional')).')</span>' : '';
                @endphp
                <div class="rn-field {{ $wide ? 'rn-full' : '' }}" @if($fid === 'delivery_address') x-show="fields.fulfilment === 'delivery'" @endif>
                  @if($f['input'] === 'bool')
                    <label class="rn-check"><input type="checkbox" name="{{ $fname }}" value="1" x-model="fields['{{ $fid }}']"><span>{{ $label }}</span></label>
                  @elseif($f['input'] === 'choice')
                    <span class="rn-label" id="rnl_{{ $fid }}">{!! e($label).$optional !!}</span>
                    <div class="rn-seg" role="radiogroup" aria-labelledby="rnl_{{ $fid }}">
                      @foreach($f['options'] as $o)
                        <label><input type="radio" name="{{ $fname }}" value="{{ $o }}" x-model="fields['{{ $fid }}']">{{ \App\Support\RentalTypes::optionLabel($o) }}</label>
                      @endforeach
                    </div>
                  @else
                    <label for="rnf_{{ $fid }}" class="rn-label">{!! e($label).$optional !!}</label>
                    @if($f['input'] === 'textarea')
                      <textarea id="rnf_{{ $fid }}" name="{{ $fname }}" class="rn-input" :class="err('{{ $ekey }}') && 'is-invalid'" x-model="fields['{{ $fid }}']" maxlength="1000" rows="3"></textarea>
                    @elseif($f['input'] === 'location')
                      <select id="rnf_{{ $fid }}" name="{{ $fname }}" class="rn-input" :class="err('{{ $ekey }}') && 'is-invalid'" x-model="fields['{{ $fid }}']">
                        @if($f['mode'] !== 'required')<option value="">—</option>@endif
                        @foreach($f['options'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                      </select>
                    @elseif($f['input'] === 'file')
                      <input id="rnf_{{ $fid }}" type="file" name="{{ $fname }}" class="rn-input" :class="err('{{ $ekey }}') && 'is-invalid'" accept="image/jpeg,image/png,image/webp,application/pdf">
                      <div class="rn-help">{{ __('messages.RntFileHelp') }}</div>
                    @elseif($f['input'] === 'number')
                      <input id="rnf_{{ $fid }}" type="number" name="{{ $fname }}" class="rn-input" :class="err('{{ $ekey }}') && 'is-invalid'" x-model="fields['{{ $fid }}']" min="{{ $f['min'] ?? 0 }}" max="{{ $f['max'] ?? 100000 }}" inputmode="numeric">
                    @elseif($f['input'] === 'date')
                      <input id="rnf_{{ $fid }}" type="date" name="{{ $fname }}" class="rn-input" :class="err('{{ $ekey }}') && 'is-invalid'" x-model="fields['{{ $fid }}']">
                    @else
                      <input id="rnf_{{ $fid }}" type="text" name="{{ $fname }}" class="rn-input" :class="err('{{ $ekey }}') && 'is-invalid'" x-model="fields['{{ $fid }}']" maxlength="120">
                    @endif
                  @endif
                  <div class="rn-error" x-show="err('{{ $ekey }}')" x-text="err('{{ $ekey }}')"></div>
                  @if($fid === 'driver_age' && $item->spec('min_driver_age'))<div class="rn-help">{{ __('messages.RntMinDriverAge', ['age' => (int) $item->spec('min_driver_age')]) }}</div>@endif
                  @if($fid === 'guests' && $item->spec('max_guests'))<div class="rn-help">{{ __('messages.RntMaxGuests', ['n' => (int) $item->spec('max_guests')]) }}</div>@endif
                </div>
              @endforeach
            </div>
          </div>
          @endif

          <div class="rn-form-section">
            <label class="rn-field"><span class="rn-label">{{ __('messages.RntNotes') }} <span class="rn-opt">({{ __('messages.RntOptional') }})</span></span>
              <textarea name="notes" class="rn-input" rows="3" maxlength="2000" placeholder="{{ __('messages.RntNotesPlaceholder') }}"></textarea></label>
            @if($terms !== '')
              <details style="margin: 12px 0 8px"><summary class="rn-link" style="cursor: pointer">{{ __('messages.RntReadTerms') }}</summary><div class="rn-desc rn-note">{{ $terms }}</div></details>
              <label class="rn-check"><input type="checkbox" name="accept_terms" value="1"><span>{{ __('messages.RntAcceptTerms') }}</span></label>
              <div class="rn-error" x-show="err('accept_terms')" x-text="err('accept_terms')"></div>
            @endif
            {{-- Honeypot: hidden from people, filled by bots. --}}
            <div class="rn-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          </div>

          <div style="display: flex; gap: 8px; margin-top: 14px">
            <button type="button" class="rn-btn rn-btn-ghost" @click="step = 'dates'">{{ __('messages.RntBack') }}</button>
            <button type="submit" class="rn-btn" style="flex: 1" :disabled="sending"><span class="rn-spin" x-show="sending" x-cloak></span>{{ __('messages.RntSendRequest') }}</button>
          </div>
          <p class="rn-note">{{ __('messages.RntRequestNote') }}</p>
        </form>
        @endif
      </div>
    </aside>
  </div>

  @if($similar->isNotEmpty())
    <section class="rn-section-sm" style="padding-bottom: 64px">
      <div class="rn-section-head"><h2 class="rn-h2" style="font-size: 26px">{{ __('messages.RntSimilar') }}</h2></div>
      <div class="rn-grid">@foreach($similar as $sim)@include('store.rental.partials.card', ['item' => $sim, 'showDeposit' => $showDeposit])@endforeach</div>
    </section>
  @endif
</div>

<script>
  window.rnItem = function (cfg) {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    return {
      start: cfg.start, startTime: cfg.startTime, end: cfg.end, endTime: cfg.endTime, qty: 1, maxQty: cfg.maxQty,
      quote: null, loading: false, step: 'dates', sending: false, errors: {}, message: '',
      fields: Object.assign({}, cfg.fields), contact: Object.assign({}, cfg.contact),
      monthOffset: 0, days: {}, months: [], dow: [], _timer: null, _seq: 0,
      get canPrev() { return this.monthOffset > 0; },
      init() {
        var fmt = new Intl.DateTimeFormat(cfg.locale, { weekday: 'narrow' });
        // Monday-first week: 2024-01-01 was a Monday.
        for (var i = 0; i < 7; i++) this.dow.push(fmt.format(new Date(2024, 0, 1 + i)));
        this.loadMonths();
        if (this.start && this.end) this.fetchQuote();
      },
      err(k) { var e = this.errors[k]; return e ? (Array.isArray(e) ? e[0] : e) : ''; },
      changed() {
        if (this.start && this.end && this.end < this.start) this.end = this.start;
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this.fetchQuote(), 250);
        this.buildMonths();
      },
      async fetchQuote() {
        if (!this.start || !this.end) { this.quote = null; return; }
        var seq = ++this._seq;
        this.loading = true;
        try {
          var res = await fetch(cfg.quoteUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ item_id: cfg.itemId, start_date: this.start, start_time: this.startTime, end_date: this.end, end_time: this.endTime, quantity: this.qty }) });
          var data = await res.json().catch(function () { return {}; });
          if (seq !== this._seq) return;
          this.quote = data && (data.ok !== undefined) ? data : { ok: false, message: (data && data.message) || cfg.msg.error };
        } catch (e) {
          if (seq === this._seq) this.quote = { ok: false, message: cfg.msg.error };
        } finally {
          if (seq === this._seq) this.loading = false;
        }
      },
      // ---- calendar ----
      shiftMonths(n) { this.monthOffset = Math.max(0, this.monthOffset + n); this.loadMonths(); },
      async loadMonths() {
        var first = new Date(); first.setDate(1); first.setMonth(first.getMonth() + this.monthOffset);
        this.buildMonths();
        try {
          var res = await fetch(cfg.calendarUrl + '?from=' + iso(first) + '&days=62', { headers: { 'Accept': 'application/json' } });
          var data = await res.json();
          (data.days || []).forEach((d) => { this.days[d.date] = d; });
        } catch (e) { /* calendar is a help, the quote is the truth */ }
        this.buildMonths();
      },
      buildMonths() {
        var out = [];
        for (var k = 0; k < 2; k++) {
          var m = new Date(); m.setDate(1); m.setMonth(m.getMonth() + this.monthOffset + k);
          var title = new Intl.DateTimeFormat(cfg.locale, { month: 'long', year: 'numeric' }).format(m);
          var lead = (m.getDay() + 6) % 7, cells = [];
          for (var i = 0; i < lead; i++) cells.push({ key: 'e' + k + i, date: null, n: '', state: 'empty' });
          var dim = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
          for (var d = 1; d <= dim; d++) {
            var date = iso(new Date(m.getFullYear(), m.getMonth(), d));
            var info = this.days[date];
            cells.push({ key: date, date: date, n: d, state: info ? info.state : 'loading' });
          }
          out.push({ key: m.getFullYear() + '-' + m.getMonth(), title: title, cells: cells });
        }
        this.months = out;
      },
      dayClass(c) {
        if (!c.date) return 'is-empty';
        var cls = { free: '', partial: 'is-partial', busy: 'is-busy', closed: 'is-closed', loading: 'is-closed', end: 'is-end' }[c.state] || '';
        if (c.date === this.start || c.date === this.end) cls += ' is-sel';
        else if (this.start && this.end && c.date > this.start && c.date < this.end) cls += ' is-range';
        return cls;
      },
      stateLabel(s) { return cfg.msg.states[s] || ''; },
      pickDay(c) {
        if (!c.date || c.state === 'closed' || c.state === 'loading') return;
        var choosingEnd = this.start && !this.end && c.date >= this.start;
        // A booked day can still be a return day (the booking may start later
        // that day; the quote decides). Days past the horizon are return-only.
        if (!choosingEnd && (c.state === 'busy' || c.state === 'end')) return;
        if (!choosingEnd) {
          // New start: drop any quote still on its way for the old dates.
          clearTimeout(this._timer); this._seq++; this.loading = false;
          this.start = c.date; this.end = ''; this.quote = null; this.message = ''; this.buildMonths();
          return;
        }
        this.end = c.date;
        this.changed();
      },
      // ---- booking ----
      async submit(form) {
        this.errors = {}; this.message = '';
        var fd = new FormData(form);
        fd.append('item_id', cfg.itemId);
        fd.append('start_date', this.start); fd.append('start_time', this.startTime);
        fd.append('end_date', this.end); fd.append('end_time', this.endTime);
        fd.append('quantity', this.qty);
        this.sending = true;
        try {
          var res = await fetch(cfg.bookUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } });
          var data = await res.json().catch(function () { return {}; });
          if (res.ok && data.redirect) { window.location.href = data.redirect; return; }
          if (res.ok && data.ok) { this.message = ''; return; }
          this.errors = data.errors || {};
          this.message = data.message || cfg.msg.error;
          if (this.errors.dates) { this.step = 'dates'; this.fetchQuote(); }
        } catch (e) {
          this.message = cfg.msg.error;
        } finally {
          this.sending = false;
        }
      },
    };
  };
</script>
@endsection
