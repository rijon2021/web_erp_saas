@extends('layouts.store')

@section('content')
@php
  $heroImage = \App\Support\RestaurantTheme::imageUrl($opts['hero_image']);
  $aboutImage = \App\Support\RestaurantTheme::imageUrl($opts['about_image']);
  $dietLabels = [
    'vegetarian' => __('messages.RstDietVegetarian'), 'vegan' => __('messages.RstDietVegan'), 'spicy' => __('messages.RstDietSpicy'),
    'halal' => __('messages.RstDietHalal'), 'gluten_free' => __('messages.RstDietGlutenFree'),
  ];
  $byCat = $dishes->groupBy('category_id');
  $mapLink = $opts['map_url'] !== '' ? $opts['map_url'] : (trim((string) $s->contact_address) !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($s->contact_address) : null);
  $menuPayload = [
    'dishes' => $dishes->map(fn ($d) => [
      'id' => $d['id'], 'name' => $d['name'], 'description' => $d['description'], 'price' => $d['price'], 'image' => $d['image_url'],
      'category_id' => $d['category_id'], 'dietary' => $d['dietary'], 'spice' => $d['spice_level'], 'prep' => $d['prep_minutes'], 'calories' => $d['calories'] ?? null,
      'ok' => $d['available'] && ! $d['sold_out'], 'groups' => $d['groups'], 'variants' => $d['variants'],
    ])->values(),
    'hidePrices' => $hidePrices,
    'q' => mb_substr((string) request('q', ''), 0, 100),
    't' => [
      'required' => __('messages.RstRequired'), 'optional' => __('messages.RstOptional'), 'upTo' => __('messages.RstUpTo'), 'choose' => __('messages.RstChoose'),
      'pickVariant' => __('messages.RstChooseOption'), 'added' => __('messages.RstAddedToBasket'),
    ],
  ];
  $fmt = fn ($v) => $hidePrices ? '' : store_money($v);
@endphp

<div x-data="rsMenu(@js($menuPayload))">

{{-- ===== Hero ===== --}}
@if($sections['hero'])
<section class="rs-hero {{ $heroImage ? '' : 'rs-hero-plain' }}">
  <div class="rs-container">
    <div class="rs-hero-grid">
      <div>
        @if($opts['hero_kicker'] !== '')<span class="rs-kicker">{{ $opts['hero_kicker'] }}</span>@endif
        <h1 class="rs-hero-title">{{ $opts['hero_title'] }}</h1>
        <p class="rs-hero-sub">{{ $opts['hero_subtitle'] }}</p>
        <div class="rs-hero-actions">
          <a href="#menu" class="rs-btn">{{ __('messages.RstOrderOnline') }}</a>
          @if($booking && $sections['booking'])<a href="#book" class="rs-btn rs-btn-ghost">{{ __('messages.RstBookTable') }}</a>@endif
        </div>
        <div class="rs-facts">
          @if(! empty($hours))<span><x-store.icon name="clock" />{{ $hours[0] }}</span>@endif
          @if($s->contact_phone)<span><x-store.icon name="phone" />{{ $s->contact_phone }}</span>@endif
          @if($s->contact_address)<span><x-store.icon name="map-pin" />{{ \Illuminate\Support\Str::limit($s->contact_address, 60) }}</span>@endif
        </div>
      </div>
      @if($heroImage)
        <div class="rs-hero-media"><img src="{{ $heroImage }}" alt="" fetchpriority="high"></div>
      @endif
    </div>
  </div>
</section>
@endif

{{-- ===== Menu ===== --}}
@if($sections['menu'])
<section class="rs-section" id="menu" style="padding-top: 20px">
  <div class="rs-container">
    <div class="rs-section-head">
      <div>
        <h2 class="rs-h2">{{ $opts['menu_title'] }}</h2>
        @if($opts['menu_subtitle'] !== '')<p class="rs-lead">{{ $opts['menu_subtitle'] }}</p>@endif
      </div>
    </div>

    @if($dishes->isEmpty())
      <p class="rs-empty">{{ __('messages.RstMenuComingSoon') }}</p>
    @else
      <div class="rs-tabs">
        <div class="rs-tabs-row" role="tablist" aria-label="{{ __('messages.RstMenu') }}">
          @foreach($menuCategories as $c)
            <button type="button" class="rs-chip" :class="{ 'is-on': cur === {{ $c->id }} }" @click="jump({{ $c->id }})" x-show="catVisible({{ $c->id }})">{{ $c->name }}</button>
          @endforeach
        </div>
        <div class="rs-tools">
          <label class="rs-search">
            <x-store.icon name="search" />
            <input type="search" x-model="q" placeholder="{{ __('messages.RstSearchMenu') }}" aria-label="{{ __('messages.RstSearchMenu') }}">
          </label>
          @if($opts['show_dietary_filters'] && count($dietary))
            @foreach($dietary as $tag)
              <button type="button" class="rs-chip" :class="{ 'is-on': diets.includes('{{ $tag }}') }" @click="toggleDiet('{{ $tag }}')" :aria-pressed="diets.includes('{{ $tag }}').toString()">{{ $dietLabels[$tag] ?? $tag }}</button>
            @endforeach
          @endif
        </div>
      </div>

      @foreach($menuCategories as $c)
        <div class="rs-cat" id="cat-{{ $c->id }}" data-cat="{{ $c->id }}" x-show="catVisible({{ $c->id }})">
          <h3 class="rs-cat-title">{{ $c->name }} <small>{{ trans_choice('messages.RstDishesCount', count($byCat[$c->id] ?? []), ['n' => count($byCat[$c->id] ?? [])]) }}</small></h3>
          <div class="rs-grid">
            @foreach($byCat[$c->id] ?? [] as $d)
              @php $ok = $d['available'] && ! $d['sold_out']; @endphp
              <button type="button" id="dish-{{ $d['id'] }}" class="rs-dish {{ $ok ? '' : 'is-off' }}" x-show="match({{ $d['id'] }})" @if($ok) @click="open({{ $d['id'] }})" @else aria-disabled="true" @endif>
                <span class="rs-dish-body">
                  <span class="rs-dish-name">{{ $d['name'] }}</span>
                  @if($d['description'] !== '')<span class="rs-dish-desc">{{ $d['description'] }}</span>@endif
                  @if(count($d['dietary']))
                    <span class="rs-tags">@foreach($d['dietary'] as $tag)<span class="rs-tag {{ $tag === 'spicy' ? 'spicy' : '' }}">{{ $dietLabels[$tag] ?? $tag }}</span>@endforeach</span>
                  @endif
                  <span class="rs-dish-foot">
                    <span class="rs-price">{{ count($d['variants']) ? __('messages.RstFrom', ['price' => $fmt(collect($d['variants'])->min('price'))]) : $fmt($d['price']) }}</span>
                    @if($ok)
                      <span class="rs-add" title="{{ __('messages.RstAdd') }}"><x-store.icon name="plus" /></span>
                    @else
                      <span class="rs-off">{{ $d['sold_out'] ? __('messages.RstSoldOut') : __('messages.RstNotServedNow') }}</span>
                    @endif
                  </span>
                </span>
                @if($opts['show_photos'])
                  <span class="rs-dish-photo"><img src="{{ $d['image_url'] }}" alt="" loading="lazy"></span>
                @endif
              </button>
            @endforeach
          </div>
        </div>
      @endforeach
      <p class="rs-empty" x-show="!anyVisible()" x-cloak>{{ __('messages.RstNoDishes') }}</p>
    @endif
  </div>
</section>
@endif

{{-- ===== Dish dialog ===== --}}
<template x-if="dish">
  <div class="rs-modal-bg" @click.self="close()" @keydown.escape.window="close()">
    <div class="rs-modal" role="dialog" aria-modal="true" :aria-label="dish.name">
      <div class="rs-modal-photo" x-show="{{ $opts['show_photos'] ? 'true' : 'false' }}">
        <img :src="dish.image" alt="">
        <button type="button" class="rs-icon-btn rs-modal-close" @click="close()" aria-label="{{ __('messages.Close') }}"><x-store.icon name="x" /></button>
      </div>
      <div class="rs-modal-body">
        <h3 x-text="dish.name"></h3>
        <p class="rs-dish-desc" style="-webkit-line-clamp: unset" x-show="dish.description" x-text="dish.description"></p>
        <div class="rs-meta">
          <span x-show="dish.prep" x-text="@js(__('messages.RstPrepMinutes')).replace(':n', dish.prep)"></span>
          <span x-show="dish.calories" x-text="dish.calories + ' kcal'"></span>
        </div>
        <template x-if="dish.variants.length">
          <div class="rs-grp">
            <div class="rs-grp-head"><span>{{ __('messages.RstChooseOption') }}</span><span class="rs-rule req">{{ __('messages.RstRequired') }}</span></div>
            <template x-for="v in dish.variants" :key="v.id">
              <label class="rs-opt"><input type="radio" name="rs-variant" :value="v.id" x-model.number="variant"><span x-text="v.name"></span><span class="p" x-text="money(v.price)"></span></label>
            </template>
          </div>
        </template>
        <template x-for="g in dish.groups" :key="g.id">
          <div class="rs-grp">
            <div class="rs-grp-head"><span x-text="g.name"></span><span class="rs-rule" :class="{ req: g.min > 0 }" x-text="rule(g)"></span></div>
            <template x-for="o in g.options" :key="o.id">
              <label class="rs-opt" :class="{ 'is-off': !o.available || full(g, o) }">
                <input :type="g.max === 1 ? 'radio' : 'checkbox'" :name="'rs-g' + g.id" :checked="picked[g.id].includes(o.id)" :disabled="!o.available || full(g, o)" @change="pick(g, o, $event.target.checked)">
                <span x-text="o.name + (o.available ? '' : ' · ' + @js(__('messages.RstSoldOut')))"></span>
                <span class="p" x-text="o.price ? '+' + money(o.price) : ''"></span>
              </label>
            </template>
          </div>
        </template>
        <p class="rs-hint" x-show="problem()" x-text="problem()"></p>
      </div>
      <div class="rs-modal-foot">
        <span class="rs-qty"><button type="button" @click="qty = Math.max(1, qty - 1)" aria-label="-">−</button><span x-text="qty"></span><button type="button" @click="qty = Math.min(50, qty + 1)" aria-label="+">+</button></span>
        <button type="button" class="rs-btn" style="flex: 1" :disabled="!!problem()" @click="add()">
          <span>{{ __('messages.RstAddToOrder') }}</span><span x-show="!hidePrices" x-text="'· ' + money(total())"></span>
        </button>
      </div>
    </div>
  </div>
</template>

</div>{{-- /rsMenu --}}

{{-- ===== Online booking ===== --}}
@if($booking && $sections['booking'])
<section class="rs-section" id="book">
  <div class="rs-container">
    <div class="rs-book" x-data="rsBooking(@js(['rules' => $booking, 'slotsUrl' => store_base_url('restaurant/booking-slots'), 'bookUrl' => store_base_url('restaurant/book'), 't' => ['pick' => __('messages.RstPickTime'), 'none' => __('messages.RstNoTimesLeft'), 'failed' => __('messages.RstSomethingWrong')]]))">
      <div>
        <h2 class="rs-h2">{{ $opts['booking_title'] }}</h2>
        <p class="rs-lead">{{ $opts['booking_text'] }}</p>
        @if(! empty($hours))
          <div class="rs-facts" style="flex-direction: column; gap: 6px; color: var(--rs-dark-muted)">
            @foreach($hours as $line)<span><x-store.icon name="clock" />{{ $line }}</span>@endforeach
          </div>
        @endif
        <p class="rs-lead" style="margin-top: 16px">{{ __('messages.RstLargeGroups', ['n' => $booking['max_party']]) }}</p>
      </div>
      <div class="rs-book-card">
        <template x-if="done">
          <div class="rs-ok" role="status">
            <div style="font-size: 18px; margin-bottom: 4px">{{ __('messages.RstBookingReceived') }}</div>
            <div x-text="@js(__('messages.RstBookingSummary')).replace(':n', done.party_size).replace(':date', done.date).replace(':time', done.time)"></div>
            <div style="font-weight: 400; margin-top: 6px">{{ __('messages.RstBookingConfirmLater') }}</div>
          </div>
        </template>
        <form x-show="!done" @submit.prevent="submit($el)" novalidate>
          <div class="rs-row2">
            <label class="rs-field">{{ __('messages.RstDate') }}
              <input type="date" x-model="date" :min="rules.first_date" :max="rules.last_date" @change="loadSlots()" required>
            </label>
            <label class="rs-field">{{ __('messages.RstPeople') }}
              <select x-model.number="party" @change="loadSlots()">
                <template x-for="n in rules.max_party" :key="n"><option :value="n" x-text="n"></option></template>
              </select>
            </label>
          </div>
          <div class="rs-field" style="margin-bottom: 0">{{ __('messages.RstTime') }}</div>
          <div class="rs-slots" role="radiogroup" aria-label="{{ __('messages.RstTime') }}">
            <template x-for="sl in slots" :key="sl.time">
              <button type="button" class="rs-slot" :class="{ 'is-on': time === sl.time }" :disabled="!sl.available" @click="time = sl.time" x-text="sl.time" role="radio" :aria-checked="(time === sl.time).toString()"></button>
            </template>
          </div>
          <p class="rs-hint" x-show="loading">…</p>
          <p class="rs-hint" x-show="!loading && slotsLoaded && !slots.some(x => x.available)" x-text="t.none"></p>
          <div x-show="time" x-cloak>
            <div class="rs-row2">
              <label class="rs-field">{{ __('messages.RstYourName') }}<input type="text" name="customer_name" maxlength="120" autocomplete="name" required></label>
              <label class="rs-field">{{ __('messages.RstPhone') }}<input type="tel" name="customer_phone" maxlength="40" autocomplete="tel" required></label>
            </div>
            <label class="rs-field">{{ __('messages.RstEmailOptional') }}<input type="email" name="customer_email" maxlength="191" autocomplete="email"></label>
            <label class="rs-field">{{ __('messages.RstBookingNote') }}<textarea name="note" maxlength="500" placeholder="{{ __('messages.RstBookingNoteHint') }}"></textarea></label>
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position: absolute; left: -9999px; width: 1px; height: 1px">
          </div>
          <p class="rs-err" x-show="err" x-text="err" role="alert"></p>
          <button type="submit" class="rs-btn" style="width: 100%" :disabled="!time || sending" x-text="time ? @js(__('messages.RstBookFor')).replace(':time', time) : t.pick"></button>
        </form>
      </div>
    </div>
  </div>
</section>
@endif

{{-- ===== About ===== --}}
@if($sections['about'] && $opts['about_text'] !== '')
<section class="rs-section" id="about">
  <div class="rs-container">
    <div class="rs-about" @if(! $aboutImage) style="grid-template-columns: 1fr; max-width: 760px" @endif>
      <div>
        <h2 class="rs-h2" style="margin-bottom: 14px">{{ $opts['about_title'] }}</h2>
        <div class="rs-prose">{{ $opts['about_text'] }}</div>
      </div>
      @if($aboutImage)<div class="rs-about-img"><img src="{{ $aboutImage }}" alt="" loading="lazy"></div>@endif
    </div>
  </div>
</section>
@endif

{{-- ===== Visit us ===== --}}
@if($sections['visit'])
<section class="rs-section" id="visit">
  <div class="rs-container">
    <h2 class="rs-h2" style="margin-bottom: 18px">{{ __('messages.RstVisitUs') }}</h2>
    <div class="rs-visit">
      <div class="rs-visit-card">
        <h3><x-store.icon name="map-pin" />{{ __('messages.RstAddress') }}</h3>
        <p>{{ $s->contact_address ?: '—' }}</p>
        @if($mapLink)<a href="{{ $mapLink }}" target="_blank" rel="noopener">{{ __('messages.RstGetDirections') }}</a>@endif
      </div>
      <div class="rs-visit-card">
        <h3><x-store.icon name="clock" />{{ __('messages.RstOpeningHours') }}</h3>
        @forelse($hours as $line)<p>{{ $line }}</p>@empty<p>—</p>@endforelse
      </div>
      <div class="rs-visit-card">
        <h3><x-store.icon name="phone" />{{ __('messages.GetInTouch') }}</h3>
        @if($s->contact_phone)<p><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></p>@endif
        @if($opts['whatsapp'] !== '')<p><a href="https://wa.me/{{ $opts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></p>@endif
        @if($s->contact_email)<p><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></p>@endif
      </div>
    </div>
  </div>
</section>
@endif

<script>
  window.rsMenu = function (cfg) {
    const T = cfg.t;
    const byId = {};
    cfg.dishes.forEach((d) => { byId[d.id] = d; });
    return {
      q: cfg.q || '', diets: [], cur: null, dish: null, variant: null, picked: {}, qty: 1, hidePrices: !!cfg.hidePrices,
      init() {
        const m = /^#dish-(\d+)$/.exec(location.hash || '');
        if (m && byId[Number(m[1])] && byId[Number(m[1])].ok) this.$nextTick(() => this.open(Number(m[1])));
        // Highlight the category in view.
        const cats = document.querySelectorAll('.rs-cat[data-cat]');
        if ('IntersectionObserver' in window && cats.length) {
          const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => { if (e.isIntersecting) this.cur = Number(e.target.dataset.cat); });
          }, { rootMargin: '-40% 0px -55% 0px' });
          cats.forEach((c) => io.observe(c));
        }
      },
      money(v) { return this.hidePrices ? '' : (window.fmtMoney ? window.fmtMoney(v) : Number(v || 0).toFixed(2)); },
      match(id) {
        const d = byId[id]; if (!d) return false;
        const q = this.q.trim().toLowerCase();
        if (q && d.name.toLowerCase().indexOf(q) === -1 && (d.description || '').toLowerCase().indexOf(q) === -1) return false;
        return this.diets.every((t) => (d.dietary || []).includes(t));
      },
      catVisible(cid) { return cfg.dishes.some((d) => d.category_id === cid && this.match(d.id)); },
      anyVisible() { return cfg.dishes.some((d) => this.match(d.id)); },
      toggleDiet(t) { this.diets = this.diets.includes(t) ? this.diets.filter((x) => x !== t) : this.diets.concat([t]); },
      jump(cid) {
        const el = document.getElementById('cat-' + cid);
        if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 150, behavior: 'smooth' });
        this.cur = cid;
      },
      rule(g) {
        if (g.min === 0) return g.max === 1 ? T.optional : T.upTo.replace(':n', g.max);
        return g.min === 1 && g.max === 1 ? T.required : T.choose.replace(':n', g.min);
      },
      open(id) {
        const d = byId[id]; if (!d || !d.ok) return;
        this.picked = {};
        d.groups.forEach((g) => { this.picked[g.id] = g.options.filter((o) => o.is_default && o.available).slice(0, g.max).map((o) => o.id); });
        this.variant = d.variants.length === 1 ? d.variants[0].id : null;
        this.qty = 1;
        if (!d.groups.length && !d.variants.length) { this.dish = d; this.add(); return; }
        this.dish = d;
        document.body.style.overflow = 'hidden';
      },
      close() { this.dish = null; document.body.style.overflow = ''; if (/^#dish-/.test(location.hash)) history.replaceState(null, '', location.pathname + location.search); },
      full(g, o) { return g.max > 1 && !this.picked[g.id].includes(o.id) && this.picked[g.id].length >= g.max; },
      pick(g, o, on) {
        if (g.max === 1) this.picked[g.id] = on ? [o.id] : [];
        else this.picked[g.id] = on ? this.picked[g.id].concat([o.id]) : this.picked[g.id].filter((x) => x !== o.id);
      },
      problem() {
        const d = this.dish; if (!d) return '';
        if (d.variants.length && !this.variant) return T.pickVariant;
        for (const g of d.groups) { if (this.picked[g.id].length < g.min) return g.name + ': ' + this.rule(g); }
        return '';
      },
      chosen() {
        const d = this.dish, out = [];
        d.groups.forEach((g) => g.options.forEach((o) => { if (this.picked[g.id].includes(o.id)) out.push(o); }));
        return out;
      },
      unit() {
        const d = this.dish, v = d.variants.find((x) => x.id === this.variant);
        return (v ? v.price : d.price) + this.chosen().reduce((a, o) => a + o.price, 0);
      },
      total() { return this.unit() * this.qty; },
      add() {
        const d = this.dish; if (!d || this.problem() || !window.CartLS) return;
        const v = d.variants.find((x) => x.id === this.variant);
        const opts = this.chosen();
        const ids = opts.map((o) => o.id);
        const item = {
          id: String(d.id) + (v ? ':' + v.id : '') + (ids.length ? (v ? '' : ':') + ':o' + ids.join('.') : ''),
          name: d.name + (v ? ' (' + v.name + ')' : ''), price: Math.round(this.unit() * 100) / 100, image: d.image,
          product_id: d.id, product_variant_id: v ? v.id : null,
        };
        if (ids.length) { item.option_ids = ids; item.options_label = opts.map((o) => o.name).join(' · '); }
        window.CartLS.add(item, this.qty);
        // A storefront bundle built before add-on support drops these fields:
        // put them on the cart line here so checkout still prices the add-ons.
        if (ids.length) {
          try {
            const KEY = 'shop.cart.v1';
            const c = JSON.parse(localStorage.getItem(KEY) || '{}');
            const row = (c.items || []).find((i) => String(i.id) === item.id);
            if (row && !Array.isArray(row.option_ids)) {
              Object.assign(row, { option_ids: ids, options_label: item.options_label, product_id: item.product_id, product_variant_id: item.product_variant_id });
              localStorage.setItem(KEY, JSON.stringify(c));
              window.dispatchEvent(new CustomEvent('cart:changed', { detail: c }));
            }
          } catch (e) { /* storage blocked: checkout will ask for the add-ons */ }
        }
        this.close();
        if (window.StoreUI && window.StoreUI.open) window.StoreUI.open('miniCart');
      },
    };
  };

  window.rsBooking = function (cfg) {
    return {
      rules: cfg.rules, t: cfg.t, date: cfg.rules.first_date, party: Math.min(2, cfg.rules.max_party), slots: [], slotsLoaded: false, time: '',
      loading: false, sending: false, err: '', done: null,
      init() { this.loadSlots(); },
      async loadSlots() {
        this.time = ''; this.err = ''; this.loading = true; this.slotsLoaded = false;
        try {
          const r = await fetch(cfg.slotsUrl + '?date=' + encodeURIComponent(this.date) + '&party_size=' + this.party, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const d = await r.json().catch(() => ({}));
          if (!r.ok) { this.slots = []; this.err = d.message || this.t.failed; return; }
          this.slots = d.slots || [];
        } catch (e) { this.slots = []; this.err = this.t.failed; } finally { this.loading = false; this.slotsLoaded = true; }
      },
      async submit(form) {
        if (this.sending || !this.time) return;
        const body = { date: this.date, time: this.time, party_size: this.party };
        new FormData(form).forEach((v, k) => { body[k] = v; });
        if (!String(body.customer_name || '').trim() || !String(body.customer_phone || '').trim()) { form.querySelector(!String(body.customer_name || '').trim() ? '[name=customer_name]' : '[name=customer_phone]').focus(); return; }
        this.sending = true; this.err = '';
        try {
          const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
          const r = await fetch(cfg.bookUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
          const d = await r.json().catch(() => ({}));
          if (!r.ok) {
            const msg = d.message || (d.errors && Object.values(d.errors)[0] && Object.values(d.errors)[0][0]) || this.t.failed;
            // The time may just have been taken: refresh the times, keep the message.
            if (r.status === 422) await this.loadSlots();
            this.err = msg;
            return;
          }
          this.done = d.booking || { party_size: this.party, date: this.date, time: this.time };
        } catch (e) { this.err = this.t.failed; } finally { this.sending = false; }
      },
    };
  };
</script>
@endsection
