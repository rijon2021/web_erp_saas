{{-- KPI card. $d: color, icon, value, label, link, link_label, detail, trend, suffix, delta, delta_tone, note, spark (WidgetData::kpi()).
     The "sixth" width renders the compact counter; anything wider is the hero tile. --}}
@php
    $tone = $d['delta_tone'] ?? ($d['trend'] === null ? null : ($d['trend'] >= 0 ? 'up' : 'down'));
    $deltaText = $d['delta'] ?? ($d['trend'] === null ? null : (($d['trend'] >= 0 ? '+' : '−') . abs($d['trend']) . '%'));
    $note = $d['note'] ?? $d['detail'] ?? null;
    $pipColor = match ($d['color']) { 'amber' => 'var(--dsh-warn)', 'indigo' => 'var(--dsh-critical)', 'blue' => 'var(--dsh-series-1)', default => 'var(--color-primary)' };
@endphp
@if($w['width'] === 'sixth')
    <a href="{{ $d['link'] ?? '#' }}" class="dsh-mini {{ $d['link'] ? '' : 'dsh-mini--static' }}">
        <span class="dsh-pip" style="background: {{ $pipColor }}"></span>
        <span class="dsh-mini-body">
            <span class="dsh-mini-n">{{ $d['value'] }}</span>
            <span class="dsh-mini-l">{{ $d['label'] }}@if($note) <span class="dsh-mini-amt">· {{ $note }}</span>@endif</span>
        </span>
    </a>
@else
    <section class="dsh-card dsh-kpi h-100" aria-label="{{ $d['label'] }}">
        <div class="dsh-kpi-top">
            <span class="dsh-kpi-label">{{ $d['label'] }}</span>
            @if($d['link'])
                <a href="{{ $d['link'] }}" class="dsh-kpi-icon" aria-label="{{ $d['label'] }}"><i class="bi {{ $d['icon'] }}"></i></a>
            @else
                <span class="dsh-kpi-icon"><i class="bi {{ $d['icon'] }}"></i></span>
            @endif
        </div>
        <div class="dsh-kpi-value">{{ $d['value'] }}@if($d['suffix']) <small>{{ $d['suffix'] }}</small>@endif</div>
        <div class="dsh-kpi-foot">
            <div class="dsh-kpi-meta">
                @if($deltaText !== null)
                    <span class="dsh-delta dsh-delta--{{ $tone ?? 'flat' }}">
                        @if($tone === 'up')<i class="bi bi-arrow-up-short"></i>@elseif($tone === 'down')<i class="bi bi-arrow-down-short"></i>@endif{{ $deltaText }}
                    </span>
                @endif
                @if($note)<span class="dsh-note">{{ $note }}</span>@endif
            </div>
            @if(! empty($d['spark']) && count($d['spark']) > 1)
                <svg class="dsh-spark" data-spark="{{ implode(',', $d['spark']) }}" aria-hidden="true">
                    @if($d['spark_title'])<title>{{ $d['spark_title'] }}</title>@endif
                </svg>
            @endif
        </div>
    </section>
@endif
