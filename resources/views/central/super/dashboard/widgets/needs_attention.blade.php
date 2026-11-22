@php
    $attnTotal = array_sum(array_column($d['items'], 'count'));
    $severity = fn (string $color) => match ($color) { 'red' => 'critical', 'amber' => 'warning', default => 'info' };
@endphp
<section class="dsh-card h-100">
    @include('central.super.dashboard.widgets._header', [
        'badge' => __('super.dashboard.waiting_on_you'),
        'pill' => $attnTotal > 0 ? trans_choice('super.dashboard.items_count', $attnTotal, ['count' => number_format($attnTotal)]) : null,
        'pill_tone' => 'crit',
    ])
    <div class="dsh-card-body">
        @if(empty($d['items']))
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-check2-circle', 'text' => __('super.dash_custom.all_clear')])
        @else
            <ul class="dsh-attn">
                @foreach($d['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}">
                            <span class="dsh-sev dsh-sev--{{ $severity($item['color']) }}"></span>
                            <span class="dsh-attn-body"><span class="dsh-attn-t">{{ $item['label'] }}</span>@if(! empty($item['desc']))<span class="dsh-attn-d">{{ $item['desc'] }}</span>@endif</span>
                            <span class="dsh-attn-n">{{ number_format($item['count']) }}</span>
                            <i class="bi bi-chevron-right dsh-attn-go"></i>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
