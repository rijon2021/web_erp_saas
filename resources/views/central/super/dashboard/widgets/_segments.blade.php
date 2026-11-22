{{-- One proportional bar split by category, with the list of shares below.
     $spec: rows[label, value, text, share, of_max, color], total, extra_title, extra (bars rows) --}}
@if($spec['empty'])
    @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-pie-chart'])
@else
    <div class="dsh-segbar" aria-hidden="true">
        @foreach($spec['rows'] as $row)
            @if($row['value'] > 0)<i style="width: {{ $row['share'] }}%; background: {{ $row['color'] }}"></i>@endif
        @endforeach
    </div>
    <ul class="dsh-status-list">
        @foreach($spec['rows'] as $row)
            <li><i style="background: {{ $row['color'] }}"></i>{{ $row['label'] }}<span class="v">{{ $row['text'] }}</span><span class="p">{{ $row['share'] }}%</span></li>
        @endforeach
    </ul>
    @if(! empty($spec['extra']['rows']))
        <div class="dsh-extra">
            @if(! empty($spec['extra_title']))<div class="dsh-sub">{{ $spec['extra_title'] }}</div>@endif
            @include('central.super.dashboard.widgets._bars', ['spec' => $spec['extra'] + ['empty' => false]])
        </div>
    @endif
@endif
