{{-- Electronics Premium product page: "Compare with similar" — this product
     and up to 3 from its category, one row per shared spec label. --}}
@php
  $pxCols = $premium['compare_columns'] ?? collect();
  $pxRows = $premium['compare_rows'] ?? [];
@endphp
@if($pxCols->count() > 1 && count($pxRows))
  <section class="mt-12" aria-labelledby="px-sim-h">
    <h2 id="px-sim-h" class="px-h2" style="font-size: 26px; margin-bottom: 18px">{{ __('messages.PxCompareSimilar') }}</h2>
    <div class="px-table-wrap" tabindex="0" role="region" aria-labelledby="px-sim-h">
      <table class="px-table">
        <thead>
          <tr>
            <td></td>
            @foreach($pxCols as $i => $col)
              <th scope="col" @class(['is-current' => $i === 0])>
                <a href="{{ product_url($col->id) }}" class="px-table-prod">
                  <img src="{{ product_image_url($col->primaryProductImageFilename()) }}" alt="" loading="lazy">
                  <span>{{ \Illuminate\Support\Str::limit($col->name, 48) }}</span>
                </a>
                @if($i === 0)<small class="px-kicker">{{ __('messages.PxThisItem') }}</small>@endif
              </th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @unless($hidePrices)
            <tr>
              <th scope="row">{{ __('messages.Price') }}</th>
              @foreach($pxCols as $i => $col)
                <td @class(['is-current' => $i === 0])><b>{{ store_money((float) ($col->display_price ?? $col->price ?? 0)) }}</b></td>
              @endforeach
            </tr>
          @endunless
          @foreach($pxRows as $row)
            <tr>
              <th scope="row">{{ $row['label'] }}</th>
              @foreach($row['values'] as $i => $v)
                <td @class(['is-current' => $i === 0])>{{ $v ?? '—' }}</td>
              @endforeach
            </tr>
          @endforeach
          <tr>
            <th scope="row">{{ __('messages.PxRating') }}</th>
            @foreach($pxCols as $i => $col)
              <td @class(['is-current' => $i === 0])>{{ ! empty($col->rating_count) ? number_format((float) $col->rating_avg, 1).' ('.(int) $col->rating_count.')' : '—' }}</td>
            @endforeach
          </tr>
        </tbody>
      </table>
    </div>
  </section>
@endif
