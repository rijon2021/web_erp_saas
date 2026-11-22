{{-- Electronics Premium product page, phones: an add-to-cart bar fixed above
     the bottom tab bar while the page's own button is off screen. It drives
     #pdpAddBtn, so variant choice, stock and pre-order rules stay in one place.
     Also records the product in "Recently viewed". --}}
@php
  $pxRecentItem = json_encode(['id' => (int) $p->id, 'name' => (string) $p->name, 'image' => $imgUrl, 'url' => product_url($p->id), 'price' => $hidePrices ? '' : store_money($minPrice)], JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
@if(! $hidePrices && ! $quoteOnly)
  <div class="px-sticky" id="pxSticky" aria-hidden="true" hidden>
    <span class="px-sticky-price"><b id="pxStickyPrice">{{ store_money($minPrice) }}</b>@if($compareAt && $compareAt > $minPrice)<s>{{ store_money($compareAt) }}</s>@endif</span>
    <button type="button" class="px-btn px-btn-primary" id="pxStickyAdd" tabindex="-1">{{ __('messages.AddToCart') }}</button>
  </div>
  <div class="px-sticky-spacer" aria-hidden="true"></div>
@endif
<script>
  (function () {
    if (window.PxRecent) {
      window.PxRecent.push({!! $pxRecentItem !!});
    }
    var bar = document.getElementById('pxSticky');
    var src = document.getElementById('pdpAddBtn');
    if (!bar || !src) return;
    bar.hidden = false;
    var price = document.getElementById('pdpPrice');
    var dst = document.getElementById('pxStickyPrice');
    var add = document.getElementById('pxStickyAdd');
    add.addEventListener('click', function () { src.click(); });
    function sync() {
      if (price && dst) dst.textContent = price.textContent;
      add.disabled = src.disabled;
    }
    sync();
    if (window.MutationObserver) {
      if (price) new MutationObserver(sync).observe(price, { childList: true, characterData: true, subtree: true });
      new MutationObserver(sync).observe(src, { attributes: true, attributeFilter: ['disabled'] });
    }
    // Show only while the page's own button is out of view.
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        var off = !entries[0].isIntersecting;
        bar.classList.toggle('is-on', off);
        bar.setAttribute('aria-hidden', off ? 'false' : 'true');
        add.tabIndex = off ? 0 : -1;
      }).observe(src);
    }
  })();
</script>
