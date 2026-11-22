@extends('layouts.store')

@section('content')
@php
  $types = \App\Support\BookingTypes::enabled();
  $flavour = \App\Support\BookingTheme::flavour($types);
  $flavourDef = \App\Support\BookingTypes::definition($flavour);
  $cfg = [
    'services' => $services->map(fn ($v) => [
      'id' => $v['id'], 'name' => $v['name'], 'description' => $v['description'], 'category_id' => $v['category_id'],
      'duration' => $v['duration'], 'duration_label' => \App\Services\Booking\BookingWebsiteService::durationLabel($v['duration']),
      'seats' => $v['seats'], 'price_label' => ($opts['show_prices'] ? $v['price_label'] : ''), 'modes' => $v['modes'], 'staff' => $v['staff'],
      // Deposit to pay when picking a free time: label per number of people.
      'deposit' => ! empty($v['deposit']) ? collect(range(1, min(50, max(1, $v['seats']))))->mapWithKeys(fn ($n) => [
        $n => \App\Services\Booking\BookingDepositService::money(app(\App\Services\Booking\BookingDepositService::class)->amountFor($v['id'], (float) $v['price'], $n)),
      ])->all() : null,
    ])->values(),
    'categories' => $serviceCategories,
    'fields' => $fieldsByService,
    'windows' => collect($windows)->map(fn ($w, $k) => ['key' => $k, 'label' => __('messages.BkmWindow_'.$k), 'range' => $w['from'].' – '.$w['to']])->values(),
    'initial' => $initial,
    'today' => $limits['today'],
    'last' => $limits['last'],
    'terms' => $terms !== '',
    'customer' => $customer ? ['name' => trim(($customer->name ?? '') ?: (($customer->firstname ?? '').' '.($customer->lastname ?? ''))), 'phone' => (string) ($customer->phone ?? ''), 'email' => (string) ($customer->email ?? '')] : null,
    'urls' => ['slots' => route('store.booking.slots'), 'days' => route('store.booking.days'), 'book' => route('store.booking.store')],
    'locale' => str_replace('_', '-', app()->getLocale()),
    'staffWord' => \App\Support\BookingTypes::label('BkmStaffWord_'.$flavour, $flavourDef['staff_word'] ?? 'Staff member'),
    'msg' => [
      'error' => __('messages.BkmErrGeneric'),
      'taken' => __('messages.BkmErrTaken'),
      'pickTime' => __('messages.BkmPickTimeFirst'),
      'anyStaff' => __('messages.BkmAnyStaff'),
      'anyStaffSub' => __('messages.BkmAnyStaffSub'),
      'notPicked' => __('messages.BkmNotPicked'),
      'seatsLeft' => __('messages.BkmSeatsLeft', ['n' => '__N__']),
      'classSeats' => __('messages.BkmClassSeats', ['n' => '__N__']),
      'pickDay' => __('messages.BkmPickADay'),
      'sending' => __('messages.BkmSending'),
      'sendRequest' => __('messages.BkmSendRequest'),
      'sendBooking' => __('messages.BkmSendBooking'),
      'depositNote' => __('messages.BkmDepBookNote', ['amount' => '__AMOUNT__']),
      'sendAndPay' => __('messages.BkmDepSendAndPay'),
    ],
  ];
@endphp

<div class="bk-page" x-data="bkBook(@js($cfg))" x-cloak>
  <div class="bk-container">
    @if($services->isEmpty() || ! $bkModes)
      <div class="bk-card bk-empty">
        <x-store.icon name="calendar" />
        <h3>{{ __('messages.BkmNoServicesTitle') }}</h3>
        <p>{{ __('messages.BkmNoServicesText') }}</p>
      </div>
    @else
    <ol class="bk-stepper" aria-label="{{ __('messages.BkmSteps') }}" :style="'grid-template-columns: repeat(' + steps.length + ', minmax(0, 1fr))'">
      <template x-for="(st, i) in steps" :key="st.key">
        <li :class="{ 'is-done': i < stepIndex, 'is-on': i === stepIndex }" :aria-current="i === stepIndex ? 'step' : null" x-text="(i + 1) + '. ' + st.label"></li>
      </template>
    </ol>

    <div class="bk-book">
      <main class="bk-book-main bk-card">
        {{-- 1. Service --}}
        <section x-show="step === 'service'">
          <h1>{{ __('messages.BkmChooseService') }}</h1>
          <p class="bk-sub" style="margin-bottom: 18px">{{ __('messages.BkmChooseServiceSub') }}</p>
          <div class="bk-cats" style="margin-bottom: 14px" x-show="categories.length > 1" role="group" aria-label="{{ __('messages.BkmCategories') }}">
            <button type="button" :class="{ 'is-on': cat === 'all' }" :aria-pressed="cat === 'all'" @click="cat = 'all'">{{ __('messages.BkmAll') }}</button>
            <template x-for="c in categories" :key="c.id"><button type="button" :class="{ 'is-on': cat === c.id }" :aria-pressed="cat === c.id" @click="cat = c.id" x-text="c.name"></button></template>
          </div>
          <div class="bk-options">
            <template x-for="svc in services" :key="svc.id">
              <button type="button" class="bk-option" x-show="cat === 'all' || cat === svc.category_id" :class="{ 'is-on': svc.id === serviceId }" :aria-pressed="svc.id === serviceId" @click="pickService(svc.id)">
                <span>
                  <strong x-text="svc.name"></strong>
                  <small x-text="[svc.duration_label, svc.description].filter(Boolean).join(' · ')"></small>
                </span>
                <span class="price" x-text="svc.price_label"></span>
              </button>
            </template>
          </div>
          <template x-if="service">
            <div style="margin-top: 18px; display: flex; flex-direction: column; gap: 14px">
              <div x-show="service.seats > 1" class="bk-field">{{ __('messages.BkmPeople') }}
                <div class="bk-stepper-seats">
                  <button type="button" @click="seats = Math.max(1, seats - 1)" aria-label="{{ __('messages.BkmFewer') }}">−</button>
                  <output x-text="seats" aria-live="polite"></output>
                  <button type="button" @click="seats = Math.min(service.seats, seats + 1)" aria-label="{{ __('messages.BkmMore') }}">+</button>
                  <span class="bk-hint" x-text="msg.classSeats.replace('__N__', service.seats)"></span>
                </div>
              </div>
              <div x-show="service.modes.length > 1" class="bk-seg" role="radiogroup" aria-label="{{ __('messages.BkmHowToBook') }}" style="margin: 0">
                <button type="button" role="radio" :aria-checked="mode === 'slot'" :class="{ 'is-on': mode === 'slot' }" @click="mode = 'slot'">{{ __('messages.BkmTabSlot') }}</button>
                <button type="button" role="radio" :aria-checked="mode === 'request'" :class="{ 'is-on': mode === 'request' }" @click="mode = 'request'">{{ __('messages.BkmTabRequest') }}</button>
              </div>
              <p class="bk-note" x-show="service.modes.length === 1 && service.modes[0] === 'request'"><x-store.icon name="info" /><span>{{ __('messages.BkmRequestOnlyNote') }}</span></p>
            </div>
          </template>
        </section>

        {{-- 2. Staff --}}
        <section x-show="step === 'staff'">
          <h1>{{ __('messages.BkmWhoWouldYouLike') }}</h1>
          <p class="bk-sub" style="margin-bottom: 18px">{{ __('messages.BkmWhoSub') }}</p>
          <div class="bk-people" x-show="service">
            <button type="button" class="bk-person" :class="{ 'is-on': !staffId }" :aria-pressed="!staffId" @click="staffId = null">
              <span class="bk-avatar" style="background: var(--bk-accent)" aria-hidden="true">★</span>
              <strong x-text="msg.anyStaff"></strong><small x-text="msg.anyStaffSub"></small>
            </button>
            <template x-for="m in (service ? service.staff : [])" :key="m.id">
              <button type="button" class="bk-person" :class="{ 'is-on': staffId === m.id }" :aria-pressed="staffId === m.id" @click="staffId = m.id">
                <span class="bk-avatar" style="background: var(--bk-ink-2)" aria-hidden="true" x-text="m.name.charAt(0).toUpperCase()"></span>
                <strong x-text="m.name"></strong>
              </button>
            </template>
          </div>
        </section>

        {{-- 3. Time --}}
        <section x-show="step === 'time'">
          <template x-if="mode === 'slot'">
            <div>
              <h1>{{ __('messages.BkmPickTime') }}</h1>
              <p class="bk-sub" style="margin-bottom: 18px">{{ __('messages.BkmPickTimeSub') }}</p>
              <div class="bk-time-wrap">
                <div>
                  <div class="bk-cal-head">
                    <button type="button" class="bk-icon-btn" :disabled="!canPrev()" @click="moveMonth(-1)" aria-label="{{ __('messages.BkmPrevMonth') }}"><x-store.icon name="chevron-left" class="store-ico-flip" /></button>
                    <strong x-text="monthTitle()"></strong>
                    <button type="button" class="bk-icon-btn" :disabled="!monthCanNext()" @click="moveMonth(1)" aria-label="{{ __('messages.BkmNextMonth') }}"><x-store.icon name="chevron-right" class="store-ico-flip" /></button>
                  </div>
                  <div class="bk-cal-dow" aria-hidden="true"><template x-for="d in dow" :key="d"><span x-text="d"></span></template></div>
                  <div class="bk-cal">
                    <template x-for="c in cells" :key="c.key">
                      <button type="button" class="bk-day" :class="{ 'is-on': c.date === date, 'is-blank': !c.date }" :disabled="!c.date || !c.count" :aria-pressed="c.date === date" :aria-label="c.date || null" @click="pickDate(c.date)" x-text="c.day"></button>
                    </template>
                  </div>
                  <p class="bk-note" style="margin-top: 10px" x-show="!calLoading && !cells.some(c => c.count)">{{ __('messages.BkmNoTimesMonth') }}</p>
                </div>
                <div>
                  <div class="bk-label" x-text="date ? dayTitle(date) : msg.pickDay"></div>
                  <div x-show="loading" class="bk-empty-times">{{ __('messages.BkmLoading') }}</div>
                  <div x-show="!loading && date && !slots.length" class="bk-empty-times">{{ __('messages.BkmNoTimesDay') }}</div>
                  <div class="bk-times" x-show="!loading && slots.length">
                    <template x-for="sl in slots" :key="sl.time">
                      <button type="button" class="bk-chip" :class="{ 'is-on': sl.time === time }" :aria-pressed="sl.time === time" @click="time = sl.time">
                        <strong x-text="sl.time" style="font-size: 15px"></strong>
                        <small x-show="sl.seats_left !== null" x-text="msg.seatsLeft.replace('__N__', sl.seats_left)"></small>
                      </button>
                    </template>
                  </div>
                  <p class="bk-note" style="margin-top: 12px" x-show="service && service.modes.includes('request')">
                    <x-store.icon name="info" /><span>{{ __('messages.BkmNoTimeSuits') }} <button type="button" class="bk-link" style="background: none; border: 0; padding: 0; cursor: pointer" @click="mode = 'request'; step = 'time'">{{ __('messages.BkmSendRequestInstead') }}</button></span>
                  </p>
                </div>
              </div>
            </div>
          </template>
          <template x-if="mode === 'request'">
            <div>
              <h1>{{ __('messages.BkmWhenSuits') }}</h1>
              <p class="bk-sub" style="margin-bottom: 18px">{{ __('messages.BkmWhenSuitsSub') }}</p>
              <div class="bk-form">
                <label class="bk-field" style="max-width: 280px">{{ __('messages.BkmPreferredDate') }}
                  <input type="date" x-model="date" :min="today" :max="last">
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
              </div>
            </div>
          </template>
        </section>

        {{-- 4. Details --}}
        <section x-show="step === 'details'">
          <h1>{{ __('messages.BkmYourDetails') }}</h1>
          @if(! $customer)
            {{-- Back to these steps after signing in (the login page only accepts "/…" redirects). --}}
            <p class="bk-sub" style="margin-bottom: 18px">{{ __('messages.BkmAlreadyCustomer') }} <a class="bk-link" href="{{ store_auth_url('login') }}?redirect={{ urlencode(request()->getRequestUri()) }}">{{ __('messages.SignIn') }}</a></p>
          @endif
          <form x-ref="form" class="bk-form" @submit.prevent="submit()" novalidate>
            <div class="bk-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="bk-grid-2">
              <label class="bk-field">{{ __('messages.BkmYourName') }} *
                <input type="text" name="name" x-model="contact.name" autocomplete="name" required maxlength="150">
                <span class="bk-err" x-show="errors.name" x-text="errors.name"></span>
              </label>
              <label class="bk-field">{{ __('messages.Phone') }} *
                <input type="tel" name="phone" x-model="contact.phone" autocomplete="tel" required maxlength="40">
                <span class="bk-err" x-show="errors.phone" x-text="errors.phone"></span>
              </label>
              <label class="bk-field">{{ __('messages.Email') }} *
                <input type="email" name="email" x-model="contact.email" autocomplete="email" required maxlength="190">
                <span class="bk-err" x-show="errors.email" x-text="errors.email"></span>
              </label>
            </div>
            <template x-for="f in fields" :key="serviceId + f.id">
              <div>
                <template x-if="f.input === 'bool'">
                  <label class="bk-check"><input type="checkbox" :name="'fields[' + f.id + ']'" value="1"><span x-text="f.label_text"></span></label>
                </template>
                <template x-if="f.input !== 'bool'">
                  <label class="bk-field">
                    <span><span x-text="f.label_text"></span><span x-show="f.mode === 'required'"> *</span><span class="bk-hint" x-show="f.mode !== 'required'"> ({{ __('messages.BkmOptional') }})</span></span>
                    <template x-if="f.input === 'textarea'"><textarea :name="'fields[' + f.id + ']'" rows="3" :required="f.mode === 'required'" maxlength="2000"></textarea></template>
                    <template x-if="f.input === 'text'"><input type="text" :name="'fields[' + f.id + ']'" :required="f.mode === 'required'" maxlength="191"></template>
                    <template x-if="f.input === 'number'"><input type="number" :name="'fields[' + f.id + ']'" :min="f.min" :max="f.max" :required="f.mode === 'required'"></template>
                    <template x-if="f.input === 'date'"><input type="date" :name="'fields[' + f.id + ']'" :required="f.mode === 'required'"></template>
                    <template x-if="f.input === 'file'"><input type="file" :name="'field_files[' + f.id + ']'" accept="image/jpeg,image/png,image/webp,application/pdf" :required="f.mode === 'required'"></template>
                    <template x-if="f.input === 'choice' || (f.input === 'area' && f.option_labels && f.option_labels.length)">
                      <select :name="'fields[' + f.id + ']'" :required="f.mode === 'required'">
                        <option value="">{{ __('messages.BkmChoose') }}</option>
                        <template x-for="o in (f.option_labels || [])" :key="o.value"><option :value="o.value" x-text="o.label"></option></template>
                      </select>
                    </template>
                    <template x-if="f.input === 'area' && !(f.option_labels && f.option_labels.length)"><input type="text" :name="'fields[' + f.id + ']'" :required="f.mode === 'required'" maxlength="120"></template>
                    <span class="bk-err" x-show="errors['fields.' + f.id] || errors['field_files.' + f.id]" x-text="errors['fields.' + f.id] || errors['field_files.' + f.id]"></span>
                  </label>
                </template>
              </div>
            </template>
            @if($terms !== '')
              <div class="bk-alert bk-alert-info" style="white-space: pre-line">{{ $terms }}</div>
              <label class="bk-check"><input type="checkbox" name="accept_terms" value="1" x-model="acceptTerms"><span>{{ __('messages.BkmAcceptTerms') }}</span></label>
              <span class="bk-err" x-show="errors.accept_terms" x-text="errors.accept_terms"></span>
            @endif
            <div class="bk-alert bk-alert-bad" role="alert" x-show="formError"><x-store.icon name="alert-circle" /><span x-text="formError"></span></div>
          </form>
        </section>

        <div class="bk-nav-row">
          <button type="button" class="bk-btn bk-btn-ghost" x-show="stepIndex > 0" @click="back()">{{ __('messages.BkmBack') }}</button>
          <span x-show="stepIndex === 0"></span>
          <button type="button" class="bk-btn" x-show="step !== 'details'" :disabled="!canNext()" @click="next()">{{ __('messages.BkmContinue') }}</button>
          <button type="button" class="bk-btn" x-show="step === 'details'" :disabled="sending || (needTerms && !acceptTerms)" @click="submit()">
            <span x-text="sending ? msg.sending : (mode === 'request' ? msg.sendRequest : (depositLabel() ? msg.sendAndPay : msg.sendBooking))"></span>
          </button>
        </div>
      </main>

      {{-- Summary --}}
      <aside class="bk-book-aside bk-card" aria-label="{{ __('messages.BkmYourBooking') }}">
        <h2 style="font-size: 18px; margin: 0 0 16px">{{ __('messages.BkmYourBooking') }}</h2>
        <dl class="bk-summary">
          <dt>{{ __('messages.BkmService') }}</dt><dd x-text="service ? service.name : msg.notPicked"></dd>
          <template x-if="service"><dt>{{ __('messages.BkmDuration') }}</dt></template>
          <template x-if="service"><dd x-text="service.duration_label"></dd></template>
          <template x-if="service && service.seats > 1"><dt>{{ __('messages.BkmPeople') }}</dt></template>
          <template x-if="service && service.seats > 1"><dd x-text="seats"></dd></template>
          <template x-if="mode === 'slot'"><dt x-text="staffWord"></dt></template>
          <template x-if="mode === 'slot'"><dd x-text="staffName()"></dd></template>
          <dt>{{ __('messages.BkmWhen') }}</dt><dd x-text="whenText()"></dd>
          <template x-if="service && service.price_label"><dt>{{ __('messages.BkmPrice') }}</dt></template>
          <template x-if="service && service.price_label"><dd x-text="service.price_label"></dd></template>
          <template x-if="depositLabel()"><dt>{{ __('messages.BkmDepToPay') }}</dt></template>
          <template x-if="depositLabel()"><dd><strong x-text="depositLabel()"></strong></dd></template>
        </dl>
        <p class="bk-note" style="margin-top: 16px" x-show="depositLabel()"><x-store.icon name="credit-card" /><span x-text="msg.depositNote.replace('__AMOUNT__', depositLabel() || '')"></span></p>
        <p class="bk-note" style="margin-top: 16px"><x-store.icon name="info" /><span>{!! __('messages.BkmPendingNote') !!}</span></p>
      </aside>
    </div>
    @endif
  </div>
</div>

<script>
  window.bkBook = function (cfg) {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function parse(s) { return new Date(s + 'T00:00:00'); }
    var labels = { service: @js(__('messages.BkmStepService')), staff: @js(__('messages.BkmStepStaff')), time: @js(__('messages.BkmStepTime')), details: @js(__('messages.BkmStepDetails')) };
    var init = cfg.initial || {};
    var first = cfg.services.find(function (s) { return s.id === init.service; }) || null;
    return {
      services: cfg.services, categories: cfg.categories, windows: cfg.windows, today: cfg.today, last: cfg.last, msg: cfg.msg, staffWord: cfg.staffWord,
      cat: 'all', serviceId: first ? first.id : null, staffId: init.staff || null, seats: Math.max(1, init.seats || 1),
      mode: first && init.mode && first.modes.includes(init.mode) ? init.mode : (first ? first.modes[0] : 'slot'),
      date: init.date || null, time: init.time || null, win: init.window || (cfg.windows[0] ? cfg.windows[0].key : null),
      step: 'service', slots: [], loading: false, calLoading: false, cells: [], view: null, seq: 0, calSeq: 0,
      contact: cfg.customer ? { name: cfg.customer.name, phone: cfg.customer.phone, email: cfg.customer.email } : { name: '', phone: '', email: '' },
      acceptTerms: false, needTerms: !!cfg.terms, errors: {}, formError: '', sending: false,
      dow: (function () { var f = new Intl.DateTimeFormat(cfg.locale, { weekday: 'short' }); var out = []; for (var i = 0; i < 7; i++) out.push(f.format(new Date(2024, 0, 1 + i))); return out; })(),
      get service() { var id = this.serviceId; return this.services.find(function (s) { return s.id === id; }) || null; },
      get fields() { return (cfg.fields[this.serviceId] || []); },
      get steps() {
        var out = [{ key: 'service', label: labels.service }];
        if (this.mode === 'slot' && this.service && this.service.staff.length > 1) out.push({ key: 'staff', label: labels.staff });
        out.push({ key: 'time', label: labels.time }, { key: 'details', label: labels.details });
        return out;
      },
      get stepIndex() { var k = this.step; return Math.max(0, this.steps.findIndex(function (s) { return s.key === k; })); },
      init() {
        if (this.service) {
          if (this.service.staff && this.staffId && !this.service.staff.some(function (m) { return m.id === this.staffId; }, this)) this.staffId = null;
          var ready = this.mode === 'slot' ? (this.date && this.time) : (this.date && this.win);
          this.step = ready ? 'details' : (this.steps[1] ? this.steps[1].key : 'time');
          if (this.step === 'time') this.openTime();
        }
        this.$watch('mode', () => { if (this.step === 'time') this.openTime(); });
      },
      pickService(id) {
        if (id !== this.serviceId) { this.serviceId = id; this.staffId = null; this.time = null; this.date = null; this.seats = 1; }
        if (!this.service.modes.includes(this.mode)) this.mode = this.service.modes[0];
      },
      canNext() {
        if (this.step === 'service') return !!this.service;
        if (this.step === 'staff') return true;
        if (this.step === 'time') return this.mode === 'slot' ? !!(this.date && this.time) : !!(this.date && this.win && this.date >= this.today && this.date <= this.last);
        return true;
      },
      next() {
        if (!this.canNext()) return;
        var i = this.stepIndex;
        this.step = this.steps[Math.min(i + 1, this.steps.length - 1)].key;
        if (this.step === 'time') this.openTime();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      },
      back() {
        this.step = this.steps[Math.max(0, this.stepIndex - 1)].key;
        if (this.step === 'time') this.openTime();
      },
      // ----- time step -----
      openTime() {
        if (this.mode !== 'slot') { if (!this.date) this.date = this.today; return; }
        var base = this.date ? parse(this.date) : parse(this.today);
        this.view = new Date(base.getFullYear(), base.getMonth(), 1);
        this.loadMonth();
        if (this.date) this.loadSlots(); else this.slots = [];
      },
      monthTitle() { return this.view ? new Intl.DateTimeFormat(cfg.locale, { month: 'long', year: 'numeric' }).format(this.view) : ''; },
      dayTitle(d) { return new Intl.DateTimeFormat(cfg.locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(parse(d)); },
      canPrev() { if (!this.view) return false; var t = parse(this.today); return this.view > new Date(t.getFullYear(), t.getMonth(), 1); },
      monthCanNext() { if (!this.view) return false; var l = parse(this.last); return this.view < new Date(l.getFullYear(), l.getMonth(), 1); },
      moveMonth(n) {
        if ((n < 0 && !this.canPrev()) || (n > 0 && !this.monthCanNext())) return;
        this.view = new Date(this.view.getFullYear(), this.view.getMonth() + n, 1);
        this.loadMonth();
      },
      async loadMonth() {
        var y = this.view.getFullYear(), m = this.view.getMonth();
        var startDow = (new Date(y, m, 1).getDay() + 6) % 7; // Monday first
        var daysIn = new Date(y, m + 1, 0).getDate();
        var cells = [];
        for (var i = 0; i < startDow; i++) cells.push({ key: 'b' + i, date: null, day: '', count: 0 });
        for (var d = 1; d <= daysIn; d++) { var dt = iso(new Date(y, m, d)); cells.push({ key: dt, date: (dt >= this.today && dt <= this.last) ? dt : null, day: d, count: 0 }); }
        this.cells = cells;
        var inRange = cells.filter(function (c) { return c.date; });
        if (!inRange.length || !this.service) return;
        var seq = ++this.calSeq;
        this.calLoading = true;
        try {
          var q = new URLSearchParams({ service_id: this.serviceId, from: inRange[0].date, to: inRange[inRange.length - 1].date, seats: this.seats });
          if (this.staffId) q.set('staff_id', this.staffId);
          var res = await fetch(cfg.urls.days + '?' + q, { headers: { Accept: 'application/json' } });
          var data = res.ok ? await res.json() : { days: {} };
          if (seq !== this.calSeq) return;
          this.cells = cells.map(function (c) { return Object.assign({}, c, { count: c.date ? ((data.days || {})[c.date] || 0) : 0 }); });
        } catch (e) { /* the day list stays empty */ }
        if (seq === this.calSeq) this.calLoading = false;
      },
      pickDate(d) { if (!d) return; this.date = d; this.time = null; this.loadSlots(); },
      async loadSlots() {
        if (!this.date || !this.service) return;
        var seq = ++this.seq;
        this.loading = true; this.slots = [];
        try {
          var q = new URLSearchParams({ service_id: this.serviceId, date: this.date, seats: this.seats });
          if (this.staffId) q.set('staff_id', this.staffId);
          var res = await fetch(cfg.urls.slots + '?' + q, { headers: { Accept: 'application/json' } });
          var data = res.ok ? await res.json() : { slots: [] };
          if (seq !== this.seq) return;
          this.slots = data.slots || [];
          if (this.time && !this.slots.some(function (s) { return s.time === this.time; }, this)) this.time = null;
        } catch (e) { this.slots = []; }
        if (seq === this.seq) this.loading = false;
      },
      // ----- summary -----
      depositLabel() {
        var d = this.service && this.mode === 'slot' ? this.service.deposit : null;
        if (!d) return '';
        return d[String(Math.min(this.seats || 1, 50))] || d['1'] || '';
      },
      staffName() {
        if (!this.staffId || !this.service) return this.msg.anyStaff;
        var id = this.staffId, m = this.service.staff.find(function (x) { return x.id === id; });
        return m ? m.name : this.msg.anyStaff;
      },
      whenText() {
        if (!this.date) return this.msg.notPicked;
        if (this.mode === 'slot') return this.time ? this.dayTitle(this.date) + ', ' + this.time : this.dayTitle(this.date);
        var w = this.windows.find((x) => x.key === this.win);
        return this.dayTitle(this.date) + (w ? ' · ' + w.label : '');
      },
      // ----- submit -----
      async submit() {
        if (this.sending) return;
        this.errors = {}; this.formError = '';
        var form = this.$refs.form;
        if (form && !form.reportValidity()) return;
        var fd = new FormData(form);
        fd.set('service_id', this.serviceId);
        fd.set('mode', this.mode);
        fd.set('date', this.date || '');
        fd.set('seats', this.seats);
        if (this.mode === 'slot') { fd.set('time', this.time || ''); if (this.staffId) fd.set('staff_id', this.staffId); } else { fd.set('window', this.win || ''); }
        this.sending = true;
        try {
          var res = await fetch(cfg.urls.book, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } });
          var data = {};
          try { data = await res.json(); } catch (e) { data = {}; }
          if (res.ok && data.redirect) { window.location.href = data.redirect; return; }
          if (data.errors) {
            var errs = {};
            Object.keys(data.errors).forEach(function (k) { errs[k] = (data.errors[k] || [])[0]; });
            this.errors = errs;
          }
          this.formError = data.message || this.msg.error;
          // The time went in the meantime: back to the times, fresh list.
          if (res.status === 409 && this.mode === 'slot') { this.time = null; this.step = 'time'; this.openTime(); }
        } catch (e) {
          this.formError = this.msg.error;
        } finally {
          this.sending = false;
        }
      },
    };
  };
</script>
@endsection
