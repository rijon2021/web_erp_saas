@php $suNarrow = in_array($w['width'] ?? 'half', ['third', 'five'], true); @endphp
<section class="dsh-card h-100">
    @include('central.super.dashboard.widgets._header', [
        'badge' => __('super.dash_custom.widgets.table_recent_signups.desc'),
        'link' => route('super.tenants.index'),
        'link_label' => __('super.dashboard.view_all_tenants'),
    ])
    <div class="dsh-card-body dsh-table-wrap">
        @if($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-person-plus'])
        @else
        <table class="dsh-table">
            <thead>
                <tr>
                    <th>{{ __('super.tenants.tenant') }}</th>
                    @if(! $suNarrow)<th>{{ __('super.tenants.plan') }}</th>@endif
                    <th>{{ __('super.dash_custom.col_status') }}</th>
                    <th class="num">{{ __('super.dash_custom.col_joined') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($d['rows'] as $tenant)
                    @php
                        $suName = $tenant->company_name ?: $tenant->id;
                        $suTone = match ($tenant->status) {
                            \App\Tenant::STATUS_ACTIVE => 'good',
                            \App\Tenant::STATUS_PENDING, \App\Tenant::STATUS_SUSPENDED => 'warn',
                            \App\Tenant::STATUS_PROVISIONING => 'info',
                            \App\Tenant::STATUS_FAILED, \App\Tenant::STATUS_REJECTED => 'crit',
                            default => 'muted',
                        };
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('super.tenants.show', $tenant) }}" class="dsh-tenant">
                                @include('central.super.dashboard.widgets._avatar', ['name' => $suName])
                                <div>
                                    <div class="nm">{{ $suName }}</div>
                                    <div class="dm">{{ $tenant->domains->first()->domain ?? $tenant->admin_email }}</div>
                                </div>
                            </a>
                        </td>
                        @if(! $suNarrow)<td class="text-nowrap">{{ $tenant->subscription?->plan?->name ?? '—' }}</td>@endif
                        <td><span class="dsh-pill dsh-pill--{{ $suTone }}">{{ trans()->has('super.dash_custom.tenant_status.' . $tenant->status) ? __('super.dash_custom.tenant_status.' . $tenant->status) : ucfirst((string) $tenant->status) }}</span></td>
                        <td class="num dsh-muted text-nowrap" title="{{ $tenant->created_at?->format('M d, Y H:i') }}">{{ $tenant->created_at?->diffForHumans(null, false, true) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</section>
