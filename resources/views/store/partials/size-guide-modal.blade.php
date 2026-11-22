{{-- Product size guide dialog (ported from stocky). Opened by any
     [data-size-guide-open] button on the product page; pass $sizeGuide
     (Product::activeSizeGuide()). Every theme: colours come from the shared
     tokens, so skins restyle it automatically. --}}
@if(!empty($sizeGuide))
@php
  $sgColumns = $sizeGuide->chart('columns');
  $sgRows = $sizeGuide->chart('rows');
  $sgHasTable = $sizeGuide->hasTable();
  // A guide is often just an uploaded chart image with no typed table — that
  // image IS the chart, so it is shown full width instead of as a thumbnail.
  $sgImage = $sizeGuide->imageUrl();
@endphp
<div id="size-guide-modal" class="sg-root" hidden role="dialog" aria-modal="true" aria-labelledby="sg-title">
  <div class="sg-overlay" data-sg-close></div>
  <div class="sg-card" tabindex="-1">
    <button type="button" class="sg-close" data-sg-close aria-label="{{ __('messages.Close') }}"><x-store.icon name="x" /></button>
    <div class="sg-head">
      @if($sgImage && $sgHasTable)<img class="sg-thumb" src="{{ $sgImage }}" alt="">@endif
      <div>
        <div class="sg-kicker">{{ __('messages.SizeGuide') }}</div>
        <h2 class="sg-title" id="sg-title">{{ $sizeGuide->name }}</h2>
      </div>
    </div>
    <div class="sg-body">
      @if($sgImage && ! $sgHasTable)
        <img class="sg-chart" src="{{ $sgImage }}" alt="{{ $sizeGuide->name }}">
      @endif
      @if($sgHasTable)
        <div class="sg-table-wrap">
          <table class="sg-table">
            <thead>
              <tr>@foreach($sgColumns as $col)<th scope="col">{{ $col }}</th>@endforeach</tr>
            </thead>
            <tbody>
              @foreach($sgRows as $row)
                <tr>
                  @foreach($sgColumns as $ci => $col)
                    @if($ci === 0)<th scope="row">{{ $row[$ci] ?? '' }}</th>@else<td>{{ $row[$ci] ?? '' }}</td>@endif
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @if($sgImage)<img class="sg-chart" src="{{ $sgImage }}" alt="{{ $sizeGuide->name }}" style="margin-top: 16px">@endif
      @endif
      <p class="sg-note">{{ __('messages.SizeGuideNote') }}</p>
    </div>
  </div>
</div>

<style>
  .sg-root { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .sg-root[hidden] { display: none; }
  .sg-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
  .sg-card { position: relative; z-index: 1; width: min(720px, 100%); max-height: 88vh; overflow: auto; outline: none;
             background: rgb(var(--color-bg-elevated)); color: rgb(var(--color-fg-primary));
             border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
  .sg-close { position: absolute; top: 10px; inset-inline-end: 10px; width: 40px; height: 40px; display: grid; place-items: center;
              background: none; border: 0; cursor: pointer; color: rgb(var(--color-fg-secondary)); border-radius: 999px; }
  .sg-close:hover { background: rgb(var(--color-bg-muted)); }
  .sg-head { display: flex; align-items: center; gap: 14px; padding: 20px 56px 16px 22px; border-bottom: 1px solid rgb(var(--color-border-subtle)); }
  .sg-thumb { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; background: rgb(var(--color-bg-muted)); }
  .sg-kicker { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: rgb(var(--color-fg-muted)); }
  .sg-title { font-size: 1.2rem; font-weight: 700; margin: 2px 0 0; }
  .sg-body { padding: 18px 22px 22px; }
  .sg-chart { display: block; width: 100%; height: auto; max-height: 62vh; object-fit: contain; border-radius: 8px; }
  .sg-table-wrap { overflow-x: auto; }
  .sg-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  .sg-table th, .sg-table td { padding: 10px 12px; text-align: center; border-bottom: 1px solid rgb(var(--color-border-subtle)); white-space: nowrap; }
  .sg-table thead th { background: rgb(var(--color-accent-500)); color: #fff; font-weight: 600; border-bottom: 0; }
  .sg-table tbody th { font-weight: 600; }
  .sg-table tbody tr:nth-child(even) { background: rgb(var(--color-bg-muted)); }
  .sg-note { margin: 14px 0 0; font-size: 13px; color: rgb(var(--color-fg-muted)); }
  .pdp-size-guide-link { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; padding: 0; background: none; border: 0; cursor: pointer;
                         font-size: 14px; color: rgb(var(--color-fg-secondary)); text-decoration: underline; text-underline-offset: 3px; }
  .pdp-size-guide-link:hover { color: rgb(var(--color-accent-500)); }
  .pdp-size-guide-link svg { width: 16px; height: 16px; }
</style>

<script>
(function () {
  var modal = document.getElementById('size-guide-modal');
  if (!modal) return;
  var card = modal.querySelector('.sg-card');
  var opener = null;
  function open(e) {
    opener = e && e.currentTarget;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    card.focus();
  }
  function close() {
    modal.hidden = true;
    document.body.style.overflow = '';
    if (opener) opener.focus();
  }
  document.querySelectorAll('[data-size-guide-open]').forEach(function (b) { b.addEventListener('click', open); });
  modal.querySelectorAll('[data-sg-close]').forEach(function (el) { el.addEventListener('click', close); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
</script>
@endif
