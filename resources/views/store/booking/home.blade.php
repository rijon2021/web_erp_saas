@extends('layouts.store')

@section('content')
@php
  $heroImage = \App\Support\BookingTheme::imageUrl($opts['hero_image']);
  $aboutImage = \App\Support\BookingTheme::imageUrl($opts['about_image']);
  $types = \App\Support\BookingTypes::enabled();
  $flavour = \App\Support\BookingTheme::flavour($types);
  $flavourDef = \App\Support\BookingTypes::definition($flavour);
  $preferMode = $flavourDef['mode'] ?? 'slot';
  $startTab = in_array($preferMode, $bkModes, true) ? $preferMode : ($bkModes[0] ?? 'slot');
  $mapLink = $opts['map_url'] !== '' ? $opts['map_url'] : (trim((string) $s->contact_address) !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($s->contact_address) : null);
  // Worked-out hours have one row per weekday (Monday first); the owner's own text has free lines.
  $todayHours = ! empty($hours) && ! $hours[0]['raw'] ? ($hours[now()->isoWeekday() - 1]['hours'] ?? null) : null;
  $bookUrl = route('store.booking.book');
  $cfg = [
    'services' => $services->map(fn ($v) => ['id' => $v['id'], 'name' => $v['name'], 'duration' => $v['duration'], 'modes' => $v['modes'], 'staff' => $v['staff'], 'seats' => $v['seats']])->values(),
    'modes' => $bkModes,
    'tab' => $startTab,
    'windows' => collect($windows)->map(fn ($w, $k) => ['key' => $k, 'label' => __('messages.BkmWindow_'.$k), 'range' => $w['from'].' – '.$w['to']])->values(),
    'urls' => ['book' => $bookUrl, 'slots' => route('store.booking.slots'), 'days' => route('store.booking.days')],
    'today' => now()->toDateString(),
    'last' => now()->addDays(max(1, (int) ($bkSettings->max_days_ahead ?? 60)))->toDateString(),
    'locale' => str_replace('_', '-', app()->getLocale()),
    'staffWord' => \App\Support\BookingTypes::label('BkmStaffWord_'.$flavour, $flavourDef['staff_word'] ?? 'Staff member'),
    'durations' => $services->mapWithKeys(fn ($v) => [$v['id'] => \App\Services\Booking\BookingWebsiteService::durationLabel($v['duration'])]),
  ];
  $steps = [
    ['t' => __('messages.BkmStep1Title'), 'p' => __('messages.BkmStep1Text')],
    ['t' => __('messages.BkmStep2Title'), 'p' => __('messages.BkmStep2Text')],
    ['t' => __('messages.BkmStep3Title'), 'p' => __('messages.BkmStep3Text')],
  ];
@endphp

{{-- Hero + booking card --}}
<section id="book" class="bk-hero {{ $heroImage ? 'has-photo' : '' }}">
  @if($heroImage)<div class="bk-hero-photo"><img src="{{ $heroImage }}" alt=""></div>@endif
  <div class="bk-container">
    <div class="bk-hero-grid">
      <div class="bk-hero-copy">
        <span class="bk-pill"><i aria-hidden="true"></i>{{ $opts['hero_kicker'] !== '' ? $opts['hero_kicker'] : \App\Support\BookingTypes::typeLabel($flavour === 'mixed' ? ($types[0] ?? 'salon') : $flavour) }}</span>
        <h1 class="bk-hero-title">{{ $opts['hero_title'] }}</h1>
        <p class="bk-hero-sub">{{ $opts['hero_subtitle'] }}</p>
        <ul class="bk-facts">
          @if($todayHours)<li><x-store.icon name="clock" />{{ __('messages.BkmOpenToday', ['hours' => $todayHours]) }}</li>@endif
          @if($s->contact_address)<li><x-store.icon name="map-pin" />{{ $s->contact_address }}</li>@endif
          @if($s->contact_phone)<li><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a>@if($opts['whatsapp'] !== '') · <a href="https://wa.me/{{ $opts['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a>@endif</li>@endif
        </ul>
      </div>

      @if($bkSettings->hero_form ?? true)
      <div class="bk-card bk-hero-card" x-data="bkHero(@js($cfg))" x-cloak>
        @if($services->isEmpty() || ! $bkModes)
          <div class="bk-empty" style="padding: 24px 8px">
            <x-store.icon name="calendar" />
            <h3>{{ __('messages.BkmNoServicesTitle') }}</h3>
            <p>{{ __('messages.BkmNoServicesText') }}</p>
          </div>
        @else
          <div class="bk-seg" role="tablist" aria-label="{{ __('messages.BkmHowToBook') }}" x-show="modes.length > 1">
            <button type="button" role="tab" :aria-selected="tab === 'slot'" :class="{ 'is-on': tab === 'slot' }" @click="setTab('slot')">{{ __('messages.BkmTabSlot') }}</button>
            <button type="button" role="tab" :aria-selected="tab === 'request'" :class="{ 'is-on': tab === 'request' }" @click="setTab('request')">{{ __('messages.BkmTabRequest') }}</button>
          </div>
          <div class="bk-form">
            <label class="bk-field">{{ __('messages.BkmService') }}
              <select x-model.number="serviceId" @change="serviceChanged()">
                <template x-for="svc in services" :key="svc.id"><option :value="svc.id" x-text="svc.name + ' · ' + (durations[svc.id] || '')"></option></template>
              </select>
            </label>
            <p class="bk-note" x-show="service && !service.modes.includes(tab)" x-text="tab === 'slot' ? @js(__('messages.BkmOnlyRequest')) : @js(__('messages.BkmOnlySlot'))"></p>

            {{-- Book a time --}}
            <template x-if="tab === 'slot' && service && service.modes.includes('slot')">
              <div class="bk-form">
                <label class="bk-field" x-show="service.staff.length > 1"><span x-text="staffWord"></span>
                  <select x-model="staffId" @change="loadDays()">
                    <option value="">{{ __('messages.BkmAnyStaff') }}</option>
                    <template x-for="m in service.staff" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template>
                  </select>
                </label>
                <div>
                  <div class="bk-label">{{ __('messages.BkmDate') }}</div>
                  <div class="bk-days">
                    <template x-for="d in days" :key="d.date">
                      <button type="button" class="bk-chip" :class="{ 'is-on': d.date === date }" :disabled="!d.count" :aria-pressed="d.date === date" @click="pickDate(d.date)">
                        <small x-text="d.dow"></small><strong x-text="d.day"></strong>
                      </button>
                    </template>
                  </div>
                </div>
                <div>
                  <div class="bk-label">{{ __('messages.BkmFreeTimes') }}</div>
                  <div x-show="loading" class="bk-empty-times">{{ __('messages.BkmLoading') }}</div>
                  <div x-show="!loading && date && !slots.length" class="bk-empty-times">{{ __('messages.BkmNoTimesDay') }}</div>
                  <div x-show="!loading && !date" class="bk-empty-times">{{ __('messages.BkmNoTimesWeek') }}</div>
                  <div class="bk-times" x-show="!loading && slots.length">
                    <template x-for="sl in slots" :key="sl.time">
                      <button type="button" class="bk-chip" :class="{ 'is-on': sl.time === time }" :aria-pressed="sl.time === time" @click="time = sl.time" x-text="sl.time"></button>
                    </template>
                  </div>
                </div>
                <a :href="continueUrl()" class="bk-btn bk-btn-block" :class="{ 'is-disabled': !time }" :aria-disabled="!time" @click="if (!time) $event.preventDefault()">{{ __('messages.BkmContinue') }}</a>
              </div>
            </template>

            {{-- Send a request --}}
            <template x-if="tab === 'request' && service && service.modes.includes('request')">
              <div class="bk-form">
                <label class="bk-field">{{ __('messages.BkmPreferredDate') }}
                  <input type="date" x-model="prefDate" :min="today" :max="last">
                </label>
                <div>
                  <div class="bk-label">{{ __('messages.BkmPreferredTime') }}</div>
                  <div class="bk-windows">
                    <template x-for="w in windows" :key="w.key">
                      <button type="button" class="bk-chip" :class="{ 'is-on': w.key === win }" :aria-pressed="w.key === win" @click="win = w.key">
                        <strong x-text="w.label" style="font-size: 14px"></strong><small x-text="w.range"></small>
                      </button>
                    </template>
                  </div>
                </div>
                <a :href="continueUrl()" class="bk-btn bk-btn-block" @click="if (!prefDate || !win) $event.preventDefault()">{{ __('messages.BkmContinue') }}</a>
              </div>
            </template>

            <p class="bk-note"><x-store.icon name="info" /><span>{!! __('messages.BkmPendingNote') !!}</span></p>
          </div>
        @endif
      </div>
      @endif
    </div>
  </div>
</section>

{{-- Services --}}
@if($opts['sections']['services'])
<section id="services" class="bk-section" x-data="{ cat: 'all' }">
  <div class="bk-container">
    <div class="bk-section-head">
      <div>
        <h2 class="bk-h2">{{ $opts['services_title'] }}</h2>
        <p class="bk-sub">{{ __('messages.BkmServicesSub') }}</p>
      </div>
      @if($serviceCategories->count() > 1)
        <div class="bk-cats" role="group" aria-label="{{ __('messages.BkmCategories') }}">
          <button type="button" :class="{ 'is-on': cat === 'all' }" :aria-pressed="cat === 'all'" @click="cat = 'all'">{{ __('messages.BkmAll') }}</button>
          @foreach($serviceCategories as $c)
            <button type="button" :class="{ 'is-on': cat === {{ $c['id'] }} }" :aria-pressed="cat === {{ $c['id'] }}" @click="cat = {{ $c['id'] }}">{{ $c['name'] }}</button>
          @endforeach
        </div>
      @endif
    </div>
    @if($services->isEmpty())
      <div class="bk-empty"><x-store.icon name="calendar" /><h3>{{ __('messages.BkmNoServicesTitle') }}</h3><p>{{ __('messages.BkmNoServicesText') }}</p></div>
    @else
      <div class="bk-services">
        @foreach($services as $svc)
          <article class="bk-svc" x-show="cat === 'all' || cat === {{ (int) $svc['category_id'] }}">
            @if($svc['image'])<img src="{{ $svc['image'] }}" alt="" loading="lazy">@endif
            <div class="bk-svc-body">
              @if($svc['category'])<div class="bk-svc-cat">{{ $svc['category'] }}</div>@endif
              <h3>{{ $svc['name'] }}</h3>
              @if($svc['description'] !== '')<p>{{ $svc['description'] }}</p>@endif
              <div class="bk-svc-meta">
                <span><x-store.icon name="clock" />{{ \App\Services\Booking\BookingWebsiteService::durationLabel($svc['duration']) }}</span>
                @if($svc['seats'] > 1)<span><x-store.icon name="users" />{{ __('messages.BkmClassSeats', ['n' => $svc['seats']]) }}</span>@endif
                @if($opts['show_prices'] && $svc['price_label'] !== '')<span>{{ $svc['price_label'] }}</span>@endif
              </div>
              <a href="{{ $bookUrl }}?service={{ $svc['id'] }}" class="bk-btn bk-btn-outline bk-btn-sm">{{ in_array('slot', $svc['modes'], true) ? __('messages.BkmBookThis') : __('messages.BkmRequestThis') }}</a>
            </div>
          </article>
        @endforeach
      </div>
    @endif
  </div>
</section>
@endif

{{-- How it works --}}
@if($opts['sections']['how'])
<section id="how" class="bk-section bk-section-soft">
  <div class="bk-container">
    <h2 class="bk-h2" style="margin-bottom: 24px">{{ __('messages.BkmHowItWorks') }}</h2>
    <div class="bk-steps">
      @foreach($steps as $i => $st)
        <div class="bk-step"><b>{{ $i + 1 }}</b><h3>{{ $st['t'] }}</h3><p>{{ $st['p'] }}</p></div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- Team --}}
@if($opts['sections']['team'] && $team->isNotEmpty())
<section id="team" class="bk-section">
  <div class="bk-container">
    <h2 class="bk-h2" style="margin-bottom: 24px">{{ $opts['team_title'] }}</h2>
    <div class="bk-team">
      @foreach($team as $m)
        <div class="bk-member">
          @if($m['photo'])<img src="{{ $m['photo'] }}" alt="" class="bk-avatar">@else<span class="bk-avatar" style="background: {{ $m['color'] }}" aria-hidden="true">{{ $m['initial'] }}</span>@endif
          <div><strong>{{ $m['name'] }}</strong>@if($m['title'] !== '')<span>{{ $m['title'] }}</span>@endif</div>
        </div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- About --}}
@if($opts['sections']['about'] && ($opts['about_text'] !== '' || $aboutImage))
<section id="about" class="bk-section bk-section-soft">
  <div class="bk-container">
    <div class="bk-about">
      <div>
        <h2 class="bk-h2">{{ $opts['about_title'] }}</h2>
        @if($opts['about_text'] !== '')<p>{{ $opts['about_text'] }}</p>@endif
      </div>
      @if($aboutImage)<img src="{{ $aboutImage }}" alt="">@endif
    </div>
  </div>
</section>
@endif

{{-- Visit: hours + contact --}}
@if($opts['sections']['visit'])
<section id="visit" class="bk-section">
  <div class="bk-container">
    <div class="bk-visit">
      <div>
        <h2 class="bk-h2" style="margin-bottom: 16px">{{ __('messages.BkmOpeningHours') }}</h2>
        <table class="bk-hours">
          <tbody>
            @foreach($hours as $h)
              @if($h['raw'])
                <tr><th scope="row" colspan="2">{{ $h['day'] }}</th></tr>
              @else
                <tr><th scope="row">{{ $h['day'] }}</th><td class="{{ $h['hours'] ? '' : 'closed' }}">{{ $h['hours'] ?? __('messages.BkmClosed') }}</td></tr>
              @endif
            @endforeach
          </tbody>
        </table>
      </div>
      <div>
        <h2 class="bk-h2" style="margin-bottom: 16px">{{ __('messages.GetInTouch') }}</h2>
        <div class="bk-contact">
          @if($s->contact_address)<div><x-store.icon name="map-pin" />{{ $s->contact_address }}</div>@endif
          @if($s->contact_phone)<a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>@endif
          @if($opts['whatsapp'] !== '')<a href="https://wa.me/{{ $opts['whatsapp'] }}" target="_blank" rel="noopener"><x-store.icon name="whatsapp" />WhatsApp</a>@endif
          @if($s->contact_email)<a href="mailto:{{ $s->contact_email }}"><x-store.icon name="mail" />{{ $s->contact_email }}</a>@endif
          @if($mapLink)<a href="{{ $mapLink }}" target="_blank" rel="noopener" class="bk-btn bk-btn-ghost bk-btn-sm" style="align-self: flex-start"><x-store.icon name="map" />{{ __('messages.BkmOpenMap') }}</a>@endif
        </div>
      </div>
    </div>
  </div>
</section>
@endif

<script>
  window.bkHero = function (cfg) {
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    return {
      services: cfg.services, modes: cfg.modes, tab: cfg.tab, windows: cfg.windows, today: cfg.today, last: cfg.last, staffWord: cfg.staffWord, durations: cfg.durations,
      serviceId: cfg.services.length ? cfg.services[0].id : null, staffId: '', days: [], date: null, slots: [], time: null,
      prefDate: cfg.today, win: cfg.windows.length ? cfg.windows[0].key : null, loading: false, seq: 0,
      get service() { var id = this.serviceId; return this.services.find(function (s) { return s.id === id; }) || null; },
      init() {
        // Open on a tab the first service supports.
        if (this.service && !this.service.modes.includes(this.tab)) this.tab = this.service.modes[0];
        this.loadDays();
      },
      setTab(t) { this.tab = t; if (t === 'slot') this.loadDays(); },
      serviceChanged() {
        this.staffId = '';
        if (this.service && !this.service.modes.includes(this.tab) && this.modes.includes(this.service.modes[0])) this.tab = this.service.modes[0];
        this.loadDays();
      },
      async loadDays() {
        this.date = null; this.slots = []; this.time = null;
        if (!this.service || this.tab !== 'slot' || !this.service.modes.includes('slot')) return;
        var from = new Date(this.today + 'T00:00:00'), list = [];
        var fmt = new Intl.DateTimeFormat(cfg.locale, { weekday: 'short' });
        for (var i = 0; i < 7; i++) { var d = new Date(from); d.setDate(from.getDate() + i); list.push({ date: iso(d), dow: fmt.format(d), day: d.getDate(), count: 0 }); }
        var seq = ++this.seq;
        this.loading = true;
        try {
          var q = new URLSearchParams({ service_id: this.serviceId, from: list[0].date, to: list[6].date });
          if (this.staffId) q.set('staff_id', this.staffId);
          var res = await fetch(cfg.urls.days + '?' + q, { headers: { Accept: 'application/json' } });
          var data = res.ok ? await res.json() : { days: {} };
          if (seq !== this.seq) return;
          list.forEach(function (d) { d.count = (data.days || {})[d.date] || 0; });
          this.days = list;
          var first = list.find(function (d) { return d.count > 0; });
          if (first) await this.pickDate(first.date); else this.loading = false;
        } catch (e) { this.days = list; this.loading = false; }
      },
      async pickDate(date) {
        this.date = date; this.time = null; this.slots = []; this.loading = true;
        var seq = ++this.seq;
        try {
          var q = new URLSearchParams({ service_id: this.serviceId, date: date });
          if (this.staffId) q.set('staff_id', this.staffId);
          var res = await fetch(cfg.urls.slots + '?' + q, { headers: { Accept: 'application/json' } });
          var data = res.ok ? await res.json() : { slots: [] };
          if (seq === this.seq) this.slots = data.slots || [];
        } catch (e) { this.slots = []; }
        if (seq === this.seq) this.loading = false;
      },
      continueUrl() {
        var q = new URLSearchParams({ service: this.serviceId || '', mode: this.tab });
        if (this.tab === 'slot') { if (this.staffId) q.set('staff', this.staffId); if (this.date) q.set('date', this.date); if (this.time) q.set('time', this.time); }
        else { if (this.prefDate) q.set('date', this.prefDate); if (this.win) q.set('window', this.win); }
        return cfg.urls.book + '?' + q;
      },
    };
  };
</script>
@endsection
