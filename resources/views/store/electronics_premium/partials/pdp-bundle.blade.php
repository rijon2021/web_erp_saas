{{-- Electronics Premium product page: "Frequently bought together" (real
     co-purchases) or "Complete your setup". Extras are single products, added
     with CartLS; this product goes through the page's own Add to cart button
     so a variant choice is respected. Expects $p, $premium, $hidePrices,
     $quoteOnly, $imgUrl, $minPrice, $variantPayload, $currency. --}}
@php
  $pxExtras = $premium['bundle'] ?? collect();
  $pxMainPrice = (float) (collect($variantPayload ?? [])->first()['display_price'] ?? $minPrice);
  $pxItems = $pxExtras->map(function ($x) use ($currency) {
      $img = product_image_url($x->primaryProductImageFilename());
      return [
          'id' => (int) $x->id, 'name' => (string) $x->name, 'price' => round((float) ($x->display_price ?? $x->price ?? 0), 2),
          'image' => $img, 'url' => product_url($x->id), 'currency' => $currency,
          'stock' => isset($x->stock) && ! ($x->always_in_stock ?? false) ? (int) $x->stock : null,
      ];
  })->values();
  $pxBundleCfg = json_encode(['mainPrice' => $pxMainPrice, 'items' => $pxItems, 'currency' => $currency], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
@if($pxItems->isNotEmpty() && ! $hidePrices && ! ($quoteOnly ?? false))
  <section class="px-bundle" aria-labelledby="px-bundle-h"
           x-data='pxBundle({!! $pxBundleCfg !!})'>
    <h2 id="px-bundle-h" class="px-h2" style="font-size: 26px; margin-bottom: 18px">{{ ($premium['bundle_kind'] ?? '') === 'bought' ? __('messages.PxBoughtTogether') : __('messages.PxCompleteSetup') }}</h2>
    <div class="px-bundle-row">
      <label class="px-bundle-item" :class="{ 'is-off': !main }">
        <input type="checkbox" x-model="main">
        <img src="{{ $imgUrl }}" alt="" loading="lazy">
        <span><b>{{ $p->name }}</b><small>{{ __('messages.PxThisItem') }}<template x-if="qty > 1"><span x-text="' × ' + qty"></span></template> · <span x-text="money(mainPrice * qty)">{{ store_money($pxMainPrice) }}</span></small></span>
      </label>
      <template x-for="(it, i) in items" :key="it.id">
        <div class="px-bundle-plus-wrap">
          <span class="px-bundle-plus" aria-hidden="true">+</span>
          <label class="px-bundle-item" :class="{ 'is-off': !it.on }">
            <input type="checkbox" x-model="it.on">
            <img :src="it.image" alt="" loading="lazy">
            <span><a :href="it.url" x-text="it.name" @click.stop></a><small x-text="money(it.price)"></small></span>
          </label>
        </div>
      </template>
      <div class="px-bundle-total">
        <span class="px-muted" x-text="countLabel"></span>
        <b x-text="money(total)">{{ store_money($pxMainPrice + $pxItems->sum('price')) }}</b>
        <button type="button" class="px-btn px-btn-primary" :disabled="count === 0" @click="addAll()" x-text="addLabel">{{ __('messages.PxAddSelected') }}</button>
      </div>
    </div>
  </section>
  <script>
    window.pxBundle = window.pxBundle || function (cfg) {
      var T = { item: @js(__('messages.PxBundleItem')), items: @js(__('messages.PxBundleItems')), add: @js(__('messages.PxAddSelected')), pick: @js(__('messages.PxChooseOptionsFirst')), unavailable: @js(__('messages.PxMainUnavailable')) };
      var mainBtn = function () { return document.getElementById('pdpAddBtn'); };
      var readQty = function () { var el = document.getElementById('pdpQty'); var q = parseInt(el ? el.value : '1', 10); return (isNaN(q) || q < 1) ? 1 : q; };
      return {
        // This product starts unticked when it cannot be bought (out of stock, no overselling).
        main: !(mainBtn() && mainBtn().disabled),
        qty: readQty(),
        mainPrice: Number(cfg.mainPrice) || 0,
        items: cfg.items.map(function (it) { return Object.assign({ on: true }, it); }),
        init: function () {
          var self = this;
          window.addEventListener('pdp:variant', function (e) { if (e.detail && e.detail.price != null) self.mainPrice = Number(e.detail.price); });
          // Add selected adds this product with the page's quantity: keep the total in step.
          var sync = function () { setTimeout(function () { self.qty = readQty(); }, 0); };
          ['pdpQty', 'pdpQtyMinus', 'pdpQtyPlus'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('click', sync); el.addEventListener('input', sync); el.addEventListener('change', sync); }
          });
        },
        get count() { return (this.main ? 1 : 0) + this.items.filter(function (i) { return i.on; }).length; },
        get total() { return (this.main ? this.mainPrice * this.qty : 0) + this.items.reduce(function (s, i) { return s + (i.on ? Number(i.price) : 0); }, 0); },
        get countLabel() { return (this.count === 1 ? T.item : T.items).replace(':count', this.count); },
        get addLabel() { return T.add + ' (' + this.count + ')'; },
        money: function (v) {
          try { return (typeof window.fmtMoney === 'function') ? window.fmtMoney(v) : window.storeWithCurrency(cfg.currency, Number(v).toFixed(2)); }
          catch (e) { return cfg.currency + ' ' + Number(v).toFixed(2); }
        },
        addAll: function () {
          var mainBtn = document.getElementById('pdpAddBtn');
          if (this.main && mainBtn && mainBtn.disabled) {
            // Variant products default to their first variant, so a disabled button means "not available" unless options are shown.
            var msg = document.getElementById('pdpOptionGroups') || document.getElementById('pdpVariants') ? T.pick : T.unavailable;
            if (window.StoreDialog) window.StoreDialog.alert(msg); else alert(msg);
            return;
          }
          if (window.CartLS) {
            this.items.forEach(function (it) {
              if (!it.on) return;
              var item = { id: String(it.id), name: it.name, price: Number(it.price), image: it.image, slug: String(it.id), currency: it.currency };
              if (it.stock !== null && it.stock !== undefined) item.stock = it.stock;
              window.CartLS.add(item, 1);
            });
          }
          if (this.main && mainBtn) mainBtn.click();
          else if (window.StoreUI) window.StoreUI.open('miniCart');
        }
      };
    };
  </script>
@endif
