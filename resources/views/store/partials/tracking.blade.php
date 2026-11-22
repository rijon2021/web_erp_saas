{{-- Store Settings → Tracking & Analytics: the tenant's Google tag(s) and Meta
     Pixel(s), plus standard ecommerce events. IDs only (validated in
     App\Support\StoreTracking), so nothing tenant-written runs here.

     Consent: with the cookie banner on, Google analytics IDs (G-, GT-, GTM-)
     load after the "analytics" choice; Google Ads IDs (AW-, DC-) and the Meta
     Pixel after the "marketing" choice. Events recorded before a tracker
     loads are replayed to it once it does.

     A later change of mind (footer "Cookie settings") is passed on as Google
     consent-mode updates and Meta consent grant/revoke.

     Events: view_item / ViewContent (product page), add_to_cart / AddToCart
     (any CartLS.add), begin_checkout / InitiateCheckout (checkout page),
     purchase / Purchase (thank-you page, once per order, orders up to a day
     old) plus a Google Ads "conversion" per configured AW-…/label. Prices are
     in the base currency, like the cart and order totals.

     Include with ['consentBanner' => false] from layouts that cannot show the
     banner. --}}
@php
  $trk = \App\Support\StoreTracking::config($s ?? null, $consentBanner ?? true, $product ?? null);
@endphp
@if($trk)
<script>
(function(){
  var C = @json($trk);
  var MARKETING_GOOGLE = /^(AW|DC)-/;
  var events = [];
  var on = { gtag: false, gtm: false, meta: false };
  var configured = {};

  window.dataLayer = window.dataLayer || [];
  if (typeof window.gtag !== 'function') { window.gtag = function(){ window.dataLayer.push(arguments); }; }

  function addScript(src){
    var s = document.createElement('script'); s.async = true; s.src = src;
    document.head.appendChild(s);
  }
  function readConsent(){
    try {
      var m = document.cookie.match(/(?:^|; )cookie_consent=([^;]*)/);
      return m ? JSON.parse(decodeURIComponent(m[1])) : null;
    } catch (e) { return null; }
  }
  function allowed(cat, consent){ return !C.consent || !!(consent && consent[cat]); }

  // ---------- Event payloads ----------
  function baseId(id){ return String(id == null ? '' : id).split(':')[0]; }
  function num(v){ v = Number(v); return isFinite(v) ? Math.round(v * 100) / 100 : 0; }

  function toGoogle(ev){
    var p = {
      currency: C.currency,
      value: num(ev.value),
      items: ev.items.map(function(i){ return { item_id: i.id, item_name: i.name, price: num(i.price), quantity: i.qty }; })
    };
    if (ev.transaction_id) { p.transaction_id = ev.transaction_id; p.tax = num(ev.tax); p.shipping = num(ev.shipping); }
    return p;
  }
  var META_NAMES = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'Purchase' };
  function toMeta(ev){
    var p = {
      currency: C.currency,
      value: num(ev.value),
      content_type: 'product',
      content_ids: ev.items.map(function(i){ return i.id; }),
      contents: ev.items.map(function(i){ return { id: i.id, quantity: i.qty, item_price: num(i.price) }; })
    };
    if (ev.items.length === 1) p.content_name = ev.items[0].name;
    if (ev.name === 'begin_checkout' || ev.name === 'purchase') {
      p.num_items = ev.items.reduce(function(a, i){ return a + i.qty; }, 0);
    }
    return p;
  }

  // Google Ads conversions ("AW-1/label") for a purchase, limited to the AW-
  // tags in `bases` (those configured, or just configured after consent).
  function sendAds(ev, bases){
    if (ev.name !== 'purchase') return;
    (C.ads || []).forEach(function(sendTo){
      if (bases.indexOf(sendTo.split('/')[0]) === -1) return;
      try {
        window.gtag('event', 'conversion', { send_to: sendTo, value: num(ev.value), currency: C.currency, transaction_id: ev.transaction_id });
      } catch (e) {}
    });
  }
  function sendTo(target, ev){
    try {
      if (target === 'gtag') {
        window.gtag('event', ev.name, toGoogle(ev));
        sendAds(ev, Object.keys(configured));
      } else if (target === 'gtm') {
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({ event: ev.name, ecommerce: toGoogle(ev) });
      } else if (target === 'meta' && window.fbq) {
        var opts = ev.transaction_id ? { eventID: 'order_' + ev.transaction_id } : undefined;
        window.fbq('track', META_NAMES[ev.name], toMeta(ev), opts);
      }
    } catch (e) {}
  }
  function track(ev){
    if (!ev || !ev.items || !ev.items.length) return;
    events.push(ev);
    Object.keys(on).forEach(function(t){ if (on[t]) sendTo(t, ev); });
  }
  function replay(target){ events.forEach(function(ev){ sendTo(target, ev); }); }

  // ---------- Loaders ----------
  function consentState(consent){
    var a = allowed('analytics', consent) ? 'granted' : 'denied';
    var m = allowed('marketing', consent) ? 'granted' : 'denied';
    return { analytics_storage: a, ad_storage: m, ad_user_data: m, ad_personalization: m };
  }
  function loadGoogle(consent){
    // A changed choice reaches tags already running on this page (Google
    // consent mode is read by both gtag.js and Tag Manager).
    if (C.consent && (on.gtag || on.gtm)) window.gtag('consent', 'update', consentState(consent));

    var ids = C.gtag.filter(function(id){
      return !configured[id] && allowed(MARKETING_GOOGLE.test(id) ? 'marketing' : 'analytics', consent);
    });
    if (ids.length) {
      var first = !on.gtag;
      if (first) {
        if (C.consent && !on.gtm) window.gtag('consent', 'default', consentState(consent));
        addScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ids[0]));
        window.gtag('js', new Date());
      }
      ids.forEach(function(id){ configured[id] = true; window.gtag('config', id); });
      if (first) {
        on.gtag = true; replay('gtag');
      } else {
        // Ads tags allowed only now: send them the purchase already recorded.
        events.forEach(function(ev){ sendAds(ev, ids); });
      }
    }

    if (!on.gtm && C.gtm.length && allowed('analytics', consent)) {
      if (C.consent && !on.gtag) window.gtag('consent', 'default', consentState(consent));
      C.gtm.forEach(function(id){
        window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
        addScript('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(id));
      });
      on.gtm = true; replay('gtm');
    }
  }
  function loadMeta(consent){
    if (on.meta && C.consent) {
      window.fbq('consent', allowed('marketing', consent) ? 'grant' : 'revoke');
      return;
    }
    if (on.meta || !C.meta.length || !allowed('marketing', consent)) return;
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
      n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
      document,'script','https://connect.facebook.net/en_US/fbevents.js');
    C.meta.forEach(function(id){ window.fbq('init', id); });
    window.fbq('track', 'PageView');
    on.meta = true; replay('meta');
  }
  function apply(consent){ loadGoogle(consent); loadMeta(consent); }

  apply(C.consent ? readConsent() : null);
  if (C.consent) {
    document.addEventListener('cookie-consent:updated', function(e){ apply(e.detail || readConsent()); });
  }

  // ---------- Page events ----------
  var page = C.page || {};
  if (page.type === 'view_item' && page.item) {
    track({ name: 'view_item', value: page.item.price, items: [{ id: page.item.id, name: page.item.name, price: page.item.price, qty: 1 }] });
  }

  function cartItems(items){
    return (items || []).map(function(i){
      return { id: baseId(i.product_id || i.id), name: String(i.name || ''), price: num(i.price), qty: Number(i.qty) || 1 };
    });
  }
  function sumOf(items){ return items.reduce(function(a, i){ return a + i.price * i.qty; }, 0); }

  function hookCart(){
    var cart = window.CartLS;
    if (!cart || cart.__trk || typeof cart.add !== 'function') return;
    var orig = cart.add;
    cart.add = function(item){
      var id = item && item.id != null ? String(item.id) : '';
      var qtyOf = function(c){
        var row = (c && c.items || []).filter(function(i){ return String(i.id) === id; })[0];
        return row ? (Number(row.qty) || 0) : 0;
      };
      var before = 0;
      try { before = qtyOf(cart.get()); } catch (e) {}
      var res = orig.apply(this, arguments);
      try {
        var after = qtyOf(res);
        var row = (res.items || []).filter(function(i){ return String(i.id) === id; })[0];
        if (row && after > before) {
          var line = cartItems([row])[0];
          line.qty = after - before;
          track({ name: 'add_to_cart', value: line.price * line.qty, items: [line] });
        }
      } catch (e) {}
      return res;
    };
    cart.__trk = true;
  }

  function onReady(){
    hookCart();

    if (page.type === 'begin_checkout') {
      var cart = null;
      try { cart = window.CartLS ? window.CartLS.get() : JSON.parse(localStorage.getItem('shop.cart.v1') || 'null'); } catch (e) {}
      var items = cartItems(cart && cart.items);
      if (items.length) track({ name: 'begin_checkout', value: sumOf(items), items: items });
    }

    if (page.type === 'purchase') {
      var rec = null, sent = [];
      try { rec = JSON.parse(localStorage.getItem('shop.last_order') || 'null'); } catch (e) {}
      try { sent = JSON.parse(localStorage.getItem('shop.trk_sent') || '[]') || []; } catch (e) {}
      var oid = rec && (rec.order_no || rec.order_id);
      var status = rec && String(rec.payment_status || '');
      // An order from before tracking was set up, reopened days later, is not a new sale.
      var placed = rec && rec.placed_at ? Date.parse(rec.placed_at) : NaN;
      var fresh = isNaN(placed) || (Date.now() - placed) < 86400000;
      if (oid && fresh && sent.indexOf(String(oid)) === -1 && status !== 'failed' && status !== 'refunded') {
        var lines = cartItems(rec.items);
        var t = rec.totals || {};
        track({
          name: 'purchase', transaction_id: String(oid), items: lines,
          value: t.grand != null ? t.grand : sumOf(lines), tax: t.tax, shipping: t.shipping
        });
        sent.push(String(oid));
        try { localStorage.setItem('shop.trk_sent', JSON.stringify(sent.slice(-20))); } catch (e) {}
      }
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
})();
</script>
@endif
