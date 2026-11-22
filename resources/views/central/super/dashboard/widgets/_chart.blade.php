{{-- Chart card. Apex kinds are drawn by dashboard.js into #spec.el; the
     "segments" and "bars" kinds are plain HTML rendered here. --}}
@php $spec = $d['spec']; @endphp
<section class="dsh-card h-100">
    <div class="dsh-card-head">
        <div>
            <h2>{{ $d['title'] }}</h2>
            @if($d['badge'])<div class="dsh-sub">{{ $d['badge'] }}</div>@endif
        </div>
        @if(! empty($spec['series']) && count($spec['series']) > 1)
            <div class="dsh-legend">
                @foreach($spec['series'] as $i => $series)
                    <span><i style="background: {{ $spec['colors'][$i] ?? '#94a3b8' }}"></i>{{ $series['name'] }}</span>
                @endforeach
            </div>
        @elseif(! empty($d['link']))
            <a href="{{ $d['link'] }}" class="dsh-link">{{ __('super.dash_custom.view_all') }} <i class="bi bi-chevron-right"></i></a>
        @endif
    </div>
    <div class="dsh-card-body">
        @if(! empty($spec['stats']))
            <div class="dsh-stats">
                @foreach($spec['stats'] as $stat)
                    <div><b class="{{ ! empty($stat['tone']) ? 'dsh-tone--' . $stat['tone'] : '' }}">{{ $stat['value'] }}</b><span>{{ $stat['label'] }}</span></div>
                @endforeach
            </div>
        @endif
        @if($spec['kind'] === 'segments')
            @include('central.super.dashboard.widgets._segments', ['spec' => $spec])
        @elseif($spec['kind'] === 'bars')
            @include('central.super.dashboard.widgets._bars', ['spec' => $spec])
        @else
            <div id="{{ $spec['el'] }}" class="dsh-chart"></div>
        @endif
    </div>
</section>
