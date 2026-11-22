@php
    $actionIcons = [
        'extended' => 'bi-calendar-plus', 'plan_changed' => 'bi-arrow-left-right', 'payment_recorded' => 'bi-cash',
        'activated' => 'bi-check-circle', 'suspended' => 'bi-pause-circle', 'cancelled' => 'bi-x-circle',
        'edited' => 'bi-pencil', 'credited' => 'bi-piggy-bank',
    ];
@endphp
<div class="content-card h-100">
    @include('central.super.dashboard.widgets._header', ['link' => route('super.subscriptions.index')])
    <div class="card-body-custom p-0">
        @if(! empty($d['unavailable']))
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-database-exclamation', 'text' => __('super.dash_custom.events_unavailable')])
        @elseif($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-clock-history'])
        @else
        <ul class="dash-feed">
            @foreach($d['rows'] as $event)
                @php $eventTenant = $d['tenants'][$event->tenant_id] ?? null; @endphp
                <li class="dash-feed-item">
                    <span class="dash-feed-icon"><i class="bi {{ $actionIcons[$event->action] ?? 'bi-dot' }}"></i></span>
                    <div class="dash-feed-body">
                        <div class="dash-feed-title">
                            {{ trans()->has('super.dash_custom.event.' . $event->action) ? __('super.dash_custom.event.' . $event->action) : ucfirst(str_replace('_', ' ', $event->action)) }}
                            —
                            @if($eventTenant)
                                <a href="{{ route('super.tenants.show', $eventTenant) }}#subscription-panel" class="dash-link">{{ $eventTenant->company_name ?: $eventTenant->id }}</a>
                            @else
                                <span class="text-muted">{{ $event->tenant_id }}</span>
                            @endif
                        </div>
                        <div class="dash-feed-meta">
                            {{ $event->admin?->name ?? __('central.SubEventSystem') }} · <span title="{{ $event->created_at?->format('M d, Y H:i') }}">{{ $event->created_at?->diffForHumans() }}</span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        @endif
    </div>
</div>
