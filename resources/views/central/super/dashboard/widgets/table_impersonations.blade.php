<div class="content-card h-100">
    @include('central.super.dashboard.widgets._header', ['link' => route('super.impersonation-logs.index')])
    <div class="card-body-custom p-0">
        @if($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-incognito'])
        @else
        <div class="table-responsive">
            <table class="table table-hover mb-0 dash-table">
                <thead>
                    <tr>
                        <th>{{ __('super.dash_custom.col_admin') }}</th>
                        <th>{{ __('super.tenants.tenant') }}</th>
                        <th>{{ __('super.dash_custom.col_status') }}</th>
                        <th>{{ __('super.dash_custom.col_started') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($d['rows'] as $log)
                        <tr>
                            <td class="fw-600">{{ $log->admin_name ?: $log->admin_email }}</td>
                            <td>
                                <a href="{{ route('super.impersonation-logs.show', $log) }}" class="dash-link">{{ $log->tenant_name ?: $log->tenant_id }}</a>
                                @if($log->user_name)<span class="d-block text-muted fs-xs3">{{ $log->user_name }}</span>@endif
                            </td>
                            <td>
                                @if($log->status === \App\Models\Central\ImpersonationLog::STATUS_ACTIVE)
                                    <span class="badge badge-active">{{ __('central.Active') }}</span>
                                @elseif($log->status === \App\Models\Central\ImpersonationLog::STATUS_ENDED)
                                    <span class="badge badge-trial">{{ __('central.ImpersonationEnded') }}</span>
                                @elseif($log->status === \App\Models\Central\ImpersonationLog::STATUS_PENDING)
                                    <span class="badge badge-pending">{{ __('central.ImpersonationPending') }}</span>
                                @else
                                    <span class="badge badge-cancelled">{{ __('central.Impersonation' . ucfirst($log->status)) }}</span>
                                @endif
                            </td>
                            <td class="text-muted fs-sm2 text-nowrap">{{ $log->started_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
