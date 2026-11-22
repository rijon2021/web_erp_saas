@php
  // QR table ordering (Restaurant module, phase 8): a phone-first menu for one
  // table. Stand-alone page (no store header/cart): dishes go on the table's
  // open order, the bill is paid with the staff.
  use Illuminate\Support\Str;
  $s = \App\Models\StoreSetting::first();
  $primary = $s->primary_color ?? '#b45309';
  $storeName = $s->store_name ?? config('app.name');
  $logo = ! empty($s->logo_path) ? (Str::startsWith($s->logo_path, ['/', 'http://', 'https://']) ? $s->logo_path : global_asset($s->logo_path)) : null;
  $base = store_base_url('table/'.$token);
  $t = fn ($k, $r = []) => __('messages.'.$k, $r);
  $i18n = [
    'add' => $t('RstAdd'), 'added' => $t('RstAddedToBasket'), 'soldOut' => $t('RstSoldOut'), 'notNow' => $t('RstNotServedNow'),
    'choose' => $t('RstChoose'), 'optional' => $t('RstOptional'), 'upTo' => $t('RstUpTo'), 'required' => $t('RstRequired'),
    'basket' => $t('RstYourBasket'), 'send' => $t('RstSendOrder'), 'sent' => $t('RstSentToKitchen'), 'sentWaiter' => $t('RstSentToWaiter'),
    'empty' => $t('RstBasketEmpty'), 'guests' => $t('RstHowManyGuests'), 'note' => $t('RstNoteForKitchen'), 'yourOrder' => $t('RstOrderedSoFar'),
    'st_new' => $t('RstStatusWaiting'), 'st_sent' => $t('RstStatusKitchen'), 'st_preparing' => $t('RstStatusPreparing'), 'st_ready' => $t('RstStatusReady'), 'st_served' => $t('RstStatusServed'),
    'bill' => $t('RstAskForBill'), 'billAsked' => $t('RstBillAsked'), 'all' => $t('RstAll'), 'search' => $t('RstSearchMenu'), 'failed' => $t('RstSomethingWrong'),
    'total' => $t('RstTotal'), 'close' => $t('RstClose'), 'remove' => $t('RstRemove'), 'qty' => $t('RstQty'), 'cleaning' => $t('RstTableBeingPrepared'),
    'noDishes' => $t('RstNoDishes'), 'payStaff' => $t('RstPayWithStaff'),
    'diet' => ['vegetarian' => $t('RstDietVegetarian'), 'vegan' => $t('RstDietVegan'), 'spicy' => $t('RstDietSpicy'), 'halal' => $t('RstDietHalal'), 'gluten_free' => $t('RstDietGlutenFree')],
  ];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $t('RstTableTitle', ['table' => $tableName]) }} · {{ $storeName }}</title>
  <style>
    :root { --brand: {{ preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary) ? $primary : '#b45309' }}; --bg: #faf7f2; --card: #fff; --ink: #1f1a17; --muted: #6b625b; --line: #ece5dc; --ok: #15803d; --warn: #b45309; }
    @media (prefers-color-scheme: dark) { :root { --bg: #171412; --card: #221e1b; --ink: #f4efe9; --muted: #b3a89e; --line: #36302b; } }
    * { box-sizing: border-box; }
    body { margin: 0; font: 15px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); padding-bottom: 96px; }
    header { position: sticky; top: 0; z-index: 5; background: var(--card); border-bottom: 1px solid var(--line); padding: 10px 16px; display: flex; align-items: center; gap: 10px; }
    header img { height: 34px; width: auto; border-radius: 6px; }
    .brand { font-weight: 700; } .tbl { margin-inline-start: auto; background: var(--brand); color: #fff; font-weight: 700; padding: 4px 12px; border-radius: 999px; white-space: nowrap; }
    .wrap { max-width: 760px; margin: 0 auto; padding: 0 16px; }
    .search { margin: 12px 0 8px; width: 100%; padding: 11px 14px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font: inherit; }
    .cats { display: flex; gap: 8px; overflow-x: auto; padding: 4px 0 10px; scrollbar-width: none; }
    .cat { flex: none; border: 1px solid var(--line); background: var(--card); color: var(--ink); padding: 8px 14px; border-radius: 999px; font: inherit; font-weight: 600; cursor: pointer; }
    .cat.on { background: var(--brand); border-color: var(--brand); color: #fff; }
    h2 { font-size: 18px; margin: 18px 0 8px; }
    .dish { display: flex; gap: 12px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 10px; margin-bottom: 10px; }
    .dish img { width: 92px; height: 92px; object-fit: cover; border-radius: 10px; flex: none; background: var(--line); }
    .dish .body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
    .dish .name { font-weight: 700; } .desc { color: var(--muted); font-size: 13px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .foot { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .price { font-weight: 700; font-variant-numeric: tabular-nums; }
    .tags { display: flex; gap: 4px; flex-wrap: wrap; } .tag { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; background: #dcfce7; color: #166534; } .tag.spicy { background: #fee2e2; color: #991b1b; }
    .btn { border: 0; background: var(--brand); color: #fff; font: inherit; font-weight: 700; padding: 9px 16px; border-radius: 10px; cursor: pointer; min-height: 40px; }
    .btn:disabled { opacity: .45; cursor: not-allowed; } .btn.ghost { background: transparent; color: var(--brand); border: 1px solid var(--brand); } .btn.block { width: 100%; }
    .btn:focus-visible, .cat:focus-visible { outline: 3px solid color-mix(in srgb, var(--brand) 45%, transparent); outline-offset: 2px; }
    .off { font-size: 12px; font-weight: 700; color: var(--warn); }
    .bar { position: fixed; inset-inline: 0; bottom: 0; padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); background: var(--card); border-top: 1px solid var(--line); z-index: 6; }
    .bar .btn { width: 100%; max-width: 760px; display: flex; justify-content: space-between; margin: 0 auto; }
    .sheet-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9; display: none; }
    .sheet { position: fixed; inset-inline: 0; bottom: 0; max-height: 88vh; overflow-y: auto; background: var(--card); border-radius: 18px 18px 0 0; padding: 18px 16px calc(18px + env(safe-area-inset-bottom)); z-index: 10; display: none; max-width: 760px; margin: 0 auto; }
    .open { display: block; }
    .sheet h3 { margin: 0 0 4px; font-size: 19px; } .grp { margin: 14px 0; } .grp-h { display: flex; justify-content: space-between; font-weight: 700; margin-bottom: 6px; } .rule { font-size: 12px; color: var(--muted); font-weight: 600; }
    .opt { display: flex; align-items: center; gap: 10px; padding: 10px 4px; border-bottom: 1px solid var(--line); cursor: pointer; } .opt span:last-child { margin-inline-start: auto; color: var(--muted); }
    .opt input { width: 20px; height: 20px; accent-color: var(--brand); }
    .row { display: flex; align-items: center; gap: 10px; } .qty button { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 18px; cursor: pointer; }
    textarea, input[type=number] { width: 100%; font: inherit; padding: 10px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); }
    .line { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px dashed var(--line); } .line .meta { color: var(--muted); font-size: 13px; }
    .st { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--line); white-space: nowrap; align-self: flex-start; } .st.ready, .st.served { background: #dcfce7; color: #166534; } .st.preparing, .st.sent { background: #fef3c7; color: #92400e; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; margin: 16px 0; }
    .toast { position: fixed; top: 70px; inset-inline: 16px; max-width: 520px; margin: 0 auto; background: var(--ink); color: var(--bg); padding: 12px 14px; border-radius: 12px; z-index: 20; display: none; text-align: center; font-weight: 600; }
    .muted { color: var(--muted); } .err { color: #b91c1c; font-weight: 600; margin: 8px 0; }
  </style>
  {{-- Page views only (no cart here); honours a consent choice made on the store. --}}
  @include('store.partials.tracking')
</head>
<body>
  <header>
    @if($logo)<img src="{{ $logo }}" alt="">@endif
    <span class="brand">{{ $storeName }}</span>
    <span class="tbl">{{ $t('RstTableTitle', ['table' => $tableName]) }}</span>
  </header>

  <main class="wrap">
    <input id="q" class="search" type="search" placeholder="{{ $i18n['search'] }}" aria-label="{{ $i18n['search'] }}">
    <nav id="cats" class="cats" aria-label="{{ $t('RstMenu') }}"></nav>
    <div id="menu"><p class="muted">…</p></div>
    <section id="ordered" class="card" hidden>
      <div class="row" style="justify-content: space-between"><strong>{{ $i18n['yourOrder'] }}</strong><span id="ordTotal" class="price"></span></div>
      <div id="ordLines"></div>
      <p class="muted" style="font-size:13px; margin: 8px 0">{{ $i18n['payStaff'] }}</p>
      <button id="billBtn" class="btn ghost block" type="button">{{ $i18n['bill'] }}</button>
    </section>
  </main>

  <div class="bar" id="bar" hidden><button class="btn" id="barBtn" type="button"><span id="barCount"></span><span id="barTotal"></span></button></div>

  <div class="sheet-bg" id="bg"></div>
  <div class="sheet" id="pick" role="dialog" aria-modal="true" aria-labelledby="pickName"></div>
  <div class="sheet" id="basket" role="dialog" aria-modal="true" aria-label="{{ $i18n['basket'] }}"></div>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
(function () {
  var BASE = @json($base);
  var T = @json($i18n);
  var CUR = { symbol: @json((string) ($currency['symbol'] ?? '')), rate: Number(@json((float) ($currency['rate'] ?? 1))) || 1, dec: Number(@json((int) $decimals)), position: @json(\App\utils\helpers::currency_position()) };
  var KEY = 'rst.table.' + BASE.split('/').pop();
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var state = null, dishes = [], cats = [], cat = 'all', basket = [], picking = null, timer = null;
  try { basket = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { basket = []; }

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  // Symbol + amount per System Settings → Currency Position (CUR.position); this
  // page has no layout, so it mirrors window.storeWithCurrency inline.
  function money(v) {
    var sym = String(CUR.symbol || '').trim();
    var amt = Number(v || 0) * CUR.rate;
    var num = window.storeFmtNumber ? storeFmtNumber(amt, CUR.dec) : amt.toFixed(CUR.dec).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (!sym) return num;
    var pos = ['before', 'before_nospace', 'after', 'after_nospace'].indexOf(CUR.position) >= 0 ? CUR.position : 'before';
    var C = String.fromCharCode; // Arabic/Hebrew symbol -> bidi isolates (FSI/PDI) so the order holds on an LTR page
    var rtl = new RegExp('[' + C(0x0590) + '-' + C(0x08FF) + C(0xFB1D) + '-' + C(0xFDFF) + C(0xFE70) + '-' + C(0xFEFF) + ']').test(sym);
    var shown = rtl ? C(0x2068) + sym + C(0x2069) : sym;
    var sep = pos.slice(-8) === '_nospace' ? '' : ' ';
    return pos.indexOf('after') === 0 ? num + sep + shown : shown + sep + num;
  }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(basket)); } catch (e) {} }
  function toast(msg) { var el = $('toast'); el.textContent = msg; el.style.display = 'block'; clearTimeout(toast.t); toast.t = setTimeout(function () { el.style.display = 'none'; }, 3500); }
  function api(path, body) {
    return fetch(BASE + path, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: Object.assign({ Accept: 'application/json' }, body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } : {}),
      body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var m = d.message || (d.errors && Object.values(d.errors)[0] && Object.values(d.errors)[0][0]) || T.failed; throw new Error(m); } return d; }); });
  }

  function load(first) {
    return api('/data').then(function (d) {
      state = d;
      if (first) { dishes = d.dishes || []; cats = d.categories || []; renderCats(); }
      else { var byId = {}; (d.dishes || []).forEach(function (x) { byId[x.id] = x; }); dishes = dishes.map(function (x) { return byId[x.id] || x; }); }
      renderMenu(); renderOrdered(); renderBar();
    }).catch(function (e) { if (first) $('menu').innerHTML = '<p class="err">' + esc(e.message) + '</p>'; });
  }

  function renderCats() {
    var html = '<button type="button" class="cat on" data-c="all">' + esc(T.all) + '</button>';
    cats.forEach(function (c) { if (dishes.some(function (d) { return d.category_id === c.id; })) html += '<button type="button" class="cat" data-c="' + c.id + '">' + esc(c.name) + '</button>'; });
    $('cats').innerHTML = html;
  }
  $('cats').addEventListener('click', function (e) {
    var b = e.target.closest('.cat'); if (!b) return;
    cat = b.dataset.c === 'all' ? 'all' : Number(b.dataset.c);
    Array.prototype.forEach.call($('cats').children, function (x) { x.classList.toggle('on', x === b); });
    renderMenu();
  });
  $('q').addEventListener('input', renderMenu);

  function priceFrom(d) { return d.variants && d.variants.length ? Math.min.apply(null, d.variants.map(function (v) { return v.price; })) : d.price; }
  function renderMenu() {
    var q = $('q').value.trim().toLowerCase();
    var list = dishes.filter(function (d) { return (cat === 'all' || d.category_id === cat) && (!q || d.name.toLowerCase().indexOf(q) > -1 || (d.description || '').toLowerCase().indexOf(q) > -1); });
    if (!list.length) { $('menu').innerHTML = '<p class="muted">' + esc(T.noDishes) + '</p>'; return; }
    var canOrder = !state || state.can_order;
    var html = '';
    cats.concat([{ id: null, name: '' }]).forEach(function (c) {
      var inCat = list.filter(function (d) { return c.id === null ? !cats.some(function (k) { return k.id === d.category_id; }) : d.category_id === c.id; });
      if (!inCat.length) return;
      if (c.name && cat === 'all') html += '<h2>' + esc(c.name) + '</h2>';
      inCat.forEach(function (d) {
        var tags = (d.dietary || []).map(function (t) { return '<span class="tag ' + (t === 'spicy' ? 'spicy' : '') + '">' + esc(T.diet[t] || t) + '</span>'; }).join('');
        var off = d.sold_out ? T.soldOut : (!d.available ? T.notNow : '');
        html += '<article class="dish"><img src="' + esc(d.image_url) + '" alt="" loading="lazy"><div class="body"><div class="name">' + esc(d.name) + '</div>'
          + (d.description ? '<div class="desc">' + esc(d.description) + '</div>' : '') + (tags ? '<div class="tags">' + tags + '</div>' : '')
          + '<div class="foot"><span class="price">' + money(priceFrom(d)) + '</span>'
          + (off ? '<span class="off">' + esc(off) + '</span>' : '<button type="button" class="btn" data-add="' + d.id + '"' + (canOrder ? '' : ' disabled') + '>' + esc(T.add) + '</button>')
          + '</div></div></article>';
      });
    });
    $('menu').innerHTML = (state && !state.can_order ? '<p class="err">' + esc(T.cleaning) + '</p>' : '') + html;
  }
  $('menu').addEventListener('click', function (e) { var b = e.target.closest('[data-add]'); if (b) openPick(Number(b.dataset.add)); });

  // ---------- dish picker ----------
  function rule(g) { if (g.min === 0) return g.max === 1 ? T.optional : T.upTo.replace(':n', g.max); return g.min === 1 && g.max === 1 ? T.required : T.choose.replace(':n', g.min); }
  function openPick(id) {
    var d = dishes.find(function (x) { return x.id === id; }); if (!d) return;
    picking = { d: d, variant: d.variants.length === 1 ? d.variants[0].id : null, opts: {}, qty: 1, note: '' };
    d.groups.forEach(function (g) { picking.opts[g.id] = g.options.filter(function (o) { return o.is_default && o.available; }).slice(0, g.max).map(function (o) { return o.id; }); });
    if (!d.groups.length && !d.variants.length) { addLine(); return; }
    renderPick(); show('pick');
  }
  function pickTotal() {
    var p = picking, d = p.d, v = d.variants.find(function (x) { return x.id === p.variant; });
    var extra = 0; d.groups.forEach(function (g) { g.options.forEach(function (o) { if (p.opts[g.id].indexOf(o.id) > -1) extra += o.price; }); });
    return ((v ? v.price : d.price) + extra) * p.qty;
  }
  function pickError() {
    var p = picking; if (p.d.variants.length && !p.variant) return T.required;
    for (var i = 0; i < p.d.groups.length; i++) { var g = p.d.groups[i]; if (p.opts[g.id].length < g.min) return g.name + ': ' + rule(g); }
    return '';
  }
  function renderPick() {
    var p = picking, d = p.d, html = '<h3 id="pickName">' + esc(d.name) + '</h3>' + (d.description ? '<p class="muted">' + esc(d.description) + '</p>' : '');
    if (d.variants.length) {
      html += '<div class="grp"><div class="grp-h"><span>' + esc(T.choose.replace(':n', 1)) + '</span><span class="rule">' + esc(T.required) + '</span></div>';
      d.variants.forEach(function (v) { html += '<label class="opt"><input type="radio" name="v" value="' + v.id + '"' + (p.variant === v.id ? ' checked' : '') + '><span>' + esc(v.name) + '</span><span>' + money(v.price) + '</span></label>'; });
      html += '</div>';
    }
    d.groups.forEach(function (g) {
      var single = g.max === 1;
      html += '<div class="grp"><div class="grp-h"><span>' + esc(g.name) + '</span><span class="rule">' + esc(rule(g)) + '</span></div>';
      g.options.forEach(function (o) {
        var on = p.opts[g.id].indexOf(o.id) > -1, full = !single && !on && p.opts[g.id].length >= g.max;
        html += '<label class="opt"><input type="' + (single ? 'radio' : 'checkbox') + '" name="g' + g.id + '" data-g="' + g.id + '" value="' + o.id + '"' + (on ? ' checked' : '') + (!o.available || full ? ' disabled' : '') + '><span>' + esc(o.name) + (o.available ? '' : ' · ' + esc(T.soldOut)) + '</span><span>' + (o.price ? '+' + money(o.price) : '') + '</span></label>';
      });
      html += '</div>';
    });
    var err = pickError();
    html += '<div class="grp"><label class="muted" for="pn">' + esc(T.note) + '</label><textarea id="pn" rows="2" maxlength="255">' + esc(p.note) + '</textarea></div>'
      + '<div class="row"><div class="row qty"><button type="button" data-q="-1" aria-label="-">−</button><strong aria-label="' + esc(T.qty) + '">' + p.qty + '</strong><button type="button" data-q="1" aria-label="+">+</button></div>'
      + '<button type="button" class="btn" style="flex:1" id="pickAdd"' + (err ? ' disabled' : '') + '>' + esc(T.add) + ' · ' + money(pickTotal()) + '</button></div>'
      + (err ? '<p class="muted" style="font-size:13px">' + esc(err) + '</p>' : '');
    $('pick').innerHTML = html;
  }
  $('pick').addEventListener('change', function (e) {
    var el = e.target, p = picking;
    if (el.name === 'v') p.variant = Number(el.value);
    else if (el.dataset.g) {
      var gid = Number(el.dataset.g), g = p.d.groups.find(function (x) { return x.id === gid; });
      if (g.max === 1) p.opts[gid] = [Number(el.value)];
      else p.opts[gid] = Array.prototype.filter.call($('pick').querySelectorAll('input[data-g="' + gid + '"]:checked'), function () { return true; }).map(function (x) { return Number(x.value); });
    }
    renderPick();
  });
  $('pick').addEventListener('input', function (e) { if (e.target.id === 'pn') picking.note = e.target.value; });
  $('pick').addEventListener('click', function (e) {
    var q = e.target.closest('[data-q]'); if (q) { picking.qty = Math.max(1, Math.min(20, picking.qty + Number(q.dataset.q))); renderPick(); return; }
    if (e.target.id === 'pickAdd' && !pickError()) { addLine(); hide(); }
  });
  function addLine() {
    var p = picking, d = p.d, ids = [], labels = [];
    d.groups.forEach(function (g) { g.options.forEach(function (o) { if (p.opts[g.id].indexOf(o.id) > -1) { ids.push(o.id); labels.push(o.name); } }); });
    var v = d.variants.find(function (x) { return x.id === p.variant; });
    var unit = pickTotal() / p.qty;
    var key = d.id + ':' + (p.variant || '') + ':' + ids.join('.') + ':' + p.note;
    var ex = basket.find(function (l) { return l.key === key; });
    if (ex) ex.qty = Math.min(20, ex.qty + p.qty);
    else basket.push({ key: key, product_id: d.id, variant_id: p.variant, option_ids: ids, note: p.note || null, qty: p.qty, unit: unit, name: d.name + (v ? ' (' + v.name + ')' : ''), label: labels.join(' · ') });
    save(); renderBar(); toast(T.added);
  }

  // ---------- basket ----------
  function renderBar() {
    var n = basket.reduce(function (a, l) { return a + l.qty; }, 0);
    $('bar').hidden = !n;
    $('barCount').textContent = T.basket + ' · ' + n;
    $('barTotal').textContent = money(basket.reduce(function (a, l) { return a + l.qty * l.unit; }, 0));
  }
  $('barBtn').addEventListener('click', function () { renderBasket(); show('basket'); });
  function renderBasket(err) {
    var html = '<h3>' + esc(T.basket) + '</h3>';
    if (!basket.length) html += '<p class="muted">' + esc(T.empty) + '</p>';
    basket.forEach(function (l, i) {
      html += '<div class="line"><div style="flex:1"><strong>' + esc(l.name) + '</strong>' + (l.label ? '<div class="meta">' + esc(l.label) + '</div>' : '') + (l.note ? '<div class="meta">“' + esc(l.note) + '”</div>' : '')
        + '<div class="row qty" style="margin-top:6px"><button type="button" data-bq="' + i + '" data-d="-1" aria-label="-">−</button><strong>' + l.qty + '</strong><button type="button" data-bq="' + i + '" data-d="1" aria-label="+">+</button></div></div>'
        + '<div style="text-align:end"><div class="price">' + money(l.qty * l.unit) + '</div><button type="button" class="btn ghost" style="margin-top:6px; min-height:32px; padding:4px 10px" data-rm="' + i + '">' + esc(T.remove) + '</button></div></div>';
    });
    if (basket.length) {
      if (state && state.needs_guests) html += '<div class="grp"><label for="gs"><strong>' + esc(T.guests) + '</strong></label><input id="gs" type="number" min="1" max="50" inputmode="numeric" value="' + (Number(sessionStorage.getItem(KEY + '.g')) || '') + '"></div>';
      html += '<div class="row" style="justify-content:space-between; margin: 12px 0"><strong>' + esc(T.total) + '</strong><span class="price">' + money(basket.reduce(function (a, l) { return a + l.qty * l.unit; }, 0)) + '</span></div>'
        + (err ? '<p class="err">' + esc(err) + '</p>' : '') + '<button type="button" class="btn block" id="sendBtn">' + esc(T.send) + '</button>';
    }
    html += '<button type="button" class="btn ghost block" style="margin-top:8px" id="closeBtn">' + esc(T.close) + '</button>';
    $('basket').innerHTML = html;
  }
  $('basket').addEventListener('click', function (e) {
    var q = e.target.closest('[data-bq]'), rm = e.target.closest('[data-rm]');
    if (q) { var l = basket[Number(q.dataset.bq)]; l.qty = Math.max(1, Math.min(20, l.qty + Number(q.dataset.d))); save(); renderBar(); renderBasket(); return; }
    if (rm) { basket.splice(Number(rm.dataset.rm), 1); save(); renderBar(); renderBasket(); if (!basket.length) hide(); return; }
    if (e.target.id === 'closeBtn') { hide(); return; }
    if (e.target.id === 'sendBtn') sendOrder(e.target);
  });
  function sendOrder(btn) {
    var body = { items: basket.map(function (l) { return { product_id: l.product_id, variant_id: l.variant_id, qty: l.qty, option_ids: l.option_ids, note: l.note }; }) };
    var gs = $('gs'); if (gs) { if (!Number(gs.value)) { gs.focus(); return; } body.guests = Number(gs.value); try { sessionStorage.setItem(KEY + '.g', gs.value); } catch (e) {} }
    btn.disabled = true;
    api('/order', body).then(function (d) {
      basket = []; save(); state = Object.assign(state || {}, d); hide(); renderBar(); renderOrdered(); renderMenu();
      toast(d.auto_send ? T.sent : T.sentWaiter);
    }).catch(function (e) { btn.disabled = false; renderBasket(e.message); });
  }

  // ---------- what the table has ordered ----------
  function renderOrdered() {
    var o = state && state.order;
    $('ordered').hidden = !o || !o.items.length;
    if (!o) return;
    $('ordTotal').textContent = money(o.subtotal);
    $('ordLines').innerHTML = o.items.map(function (i) {
      return '<div class="line"><div style="flex:1"><strong>' + esc(i.qty) + '× ' + esc(i.name) + '</strong>' + (i.addons_label ? '<div class="meta">' + esc(i.addons_label) + '</div>' : '') + '</div><span class="st ' + esc(i.status) + '">' + esc(T['st_' + i.status] || i.status) + '</span></div>';
    }).join('');
    var b = $('billBtn'); b.disabled = !!state.bill_requested; b.textContent = state.bill_requested ? T.billAsked : T.bill;
  }
  $('billBtn').addEventListener('click', function () {
    var b = this; b.disabled = true;
    api('/bill', {}).then(function (d) { state = Object.assign(state, d); renderOrdered(); toast(T.billAsked); }).catch(function (e) { b.disabled = false; toast(e.message); });
  });

  function show(id) { $('bg').classList.add('open'); $(id).classList.add('open'); }
  function hide() { $('bg').classList.remove('open'); $('pick').classList.remove('open'); $('basket').classList.remove('open'); }
  $('bg').addEventListener('click', hide);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });

  renderBar();
  load(true);
  timer = setInterval(function () { if (!document.hidden) load(false); }, 20000);
})();
</script>
</body>
</html>
