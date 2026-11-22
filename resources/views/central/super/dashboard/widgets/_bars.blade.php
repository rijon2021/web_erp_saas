{{-- Horizontal bar list: label, track scaled to the largest value, value. $spec: rows, empty --}}
@if(! empty($spec['empty']))
    @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-bar-chart'])
@else
    <div class="dsh-hbars">
        @foreach($spec['rows'] as $row)
            <div class="dsh-hbar" title="{{ $row['label'] }}: {{ $row['text'] }} ({{ $row['share'] }}%)">
                <span class="k">{{ $row['label'] }}</span>
                <span class="track"><span class="fill" style="width: {{ max(2, $row['of_max']) }}%; background: {{ $row['color'] }}"></span></span>
                <span class="v">{{ $row['text'] }}</span>
            </div>
        @endforeach
    </div>
    @if(! empty($spec['extra']['rows']))
        <div class="dsh-extra">
            @if(! empty($spec['extra_title']))<div class="dsh-sub">{{ $spec['extra_title'] }}</div>@endif
            <div class="dsh-hbars">
                @foreach($spec['extra']['rows'] as $row)
                    <div class="dsh-hbar" title="{{ $row['label'] }}: {{ $row['text'] }}">
                        <span class="k">{{ $row['label'] }}</span>
                        <span class="track"><span class="fill" style="width: {{ max(2, $row['of_max']) }}%; background: {{ $row['color'] }}"></span></span>
                        <span class="v">{{ $row['text'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endif
