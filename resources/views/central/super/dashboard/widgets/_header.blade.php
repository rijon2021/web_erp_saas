{{-- Card header shared by the table / panel widgets. Optional: $badge (subtitle), $link, $pill (html-safe text), $pill_tone --}}
<div class="dsh-card-head">
    <div>
        <h2>{{ __('super.dash_custom.widgets.' . $w['key'] . '.name') }}</h2>
        @if(! empty($badge))<div class="dsh-sub">{{ $badge }}</div>@endif
    </div>
    <div class="dsh-card-tools">
        @if(! empty($pill))
            <span class="dsh-pill dsh-pill--{{ $pill_tone ?? 'muted' }}">{{ $pill }}</span>
        @endif
        @if(! empty($link))
            <a href="{{ $link }}" class="dsh-link">{{ $link_label ?? __('super.dash_custom.view_all') }} <i class="bi bi-chevron-right"></i></a>
        @endif
    </div>
</div>
