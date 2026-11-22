<div class="content-card h-100">
    @include('central.super.dashboard.widgets._header', ['link' => route('super.logs-health.index')])
    <div class="card-body-custom p-0">
        @if($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-shield-check', 'text' => __('super.dash_custom.no_errors')])
        @else
        <ul class="dash-feed">
            @foreach($d['rows'] as $log)
                <li class="dash-feed-item">
                    <span class="dash-health-dot dash-health-dot--{{ $log->severity === 'critical' ? 'bad' : 'warn' }} dash-feed-dot" title="{{ __('super.logs_health.severity_' . $log->severity) }}"></span>
                    <div class="dash-feed-body">
                        <a href="{{ route('super.logs-health.show', $log) }}" class="dash-feed-title dash-link d-block text-truncate" title="{{ $log->message }}">{{ \Illuminate\Support\Str::limit($log->message, 90) }}</a>
                        <div class="dash-feed-meta">
                            {{ __('super.logs_health.severity_' . $log->severity) }} · {{ $log->type }}@if($log->tenant_id) · {{ $log->tenant_id }}@endif
                            · {{ ($log->occurred_at ?? $log->created_at)?->diffForHumans() }}
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>
