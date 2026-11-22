@php $expAhead = $w['options']['ahead'] ?? 14; @endphp
<section class="dsh-card h-100">
    @include('central.super.dashboard.widgets._header', [
        'badge' => __('super.dashboard.ending_soon_sub', ['days' => $expAhead]),
        'link' => auth()->guard('central')->user()?->hasPermission('reminders') ? route('super.subscriptions.reminders') : route('super.tenants.index', ['status' => 'expiring']),
        'link_label' => auth()->guard('central')->user()?->hasPermission('reminders') ? __('super.dashboard.send_reminders') : __('super.dashboard.view_all_tenants'),
    ])
    <div class="dsh-card-body dsh-table-wrap">
        <table class="dsh-table">
            <thead>
                <tr>
                    <th>{{ __('super.tenants.tenant') }}</th>
                    <th>{{ __('super.tenants.plan') }}</th>
                    <th>{{ __('super.dash_custom.col_status') }}</th>
                    <th>{{ __('super.dashboard.col_ends') }}</th>
                    <th class="num">{{ __('super.dash_custom.col_amount') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($d['rows'] as $item)
                    @php
                        $expTenant = $item['tenant'];
                        $expSub = $item['subscription'];
                        $expName = $expTenant?->company_name ?: ($expTenant?->domains->first()->domain ?? $expSub->tenant_id);
                        $expDays = (int) now()->startOfDay()->diffInDays($item['ends_at']->copy()->startOfDay(), false);
                        $expCycle = $expSub->billing_cycle ? __('super.dash_custom.cycle_' . $expSub->billing_cycle) : null;
                    @endphp
                    <tr>
                        <td>
                            <div class="dsh-tenant">
                                @include('central.super.dashboard.widgets._avatar', ['name' => $expName])
                                <div>
                                    <div class="nm">{{ $expName }}</div>
                                    @if($expTenant?->domains->first())<div class="dm">{{ $expTenant->domains->first()->domain }}</div>@endif
                                </div>
                            </div>
                        </td>
                        <td>{{ $expSub->plan?->name ?? '—' }}@if($expCycle) <span class="dsh-muted">· {{ mb_strtolower($expCycle) }}</span>@endif</td>
                        <td>
                            @if($item['trial'])
                                <span class="dsh-pill dsh-pill--warn">{{ __('super.status.trial') }}</span>
                            @else
                                <span class="dsh-pill dsh-pill--good">{{ __('super.status.active') }}</span>
                            @endif
                        </td>
                        <td class="{{ $expDays <= 3 ? 'dsh-tone--down' : '' }}">{{ $expDays === 0 ? __('central.SubPanelEndsToday') : trans_choice('central.SubPanelDaysLeft', $expDays, ['days' => $expDays]) }}</td>
                        <td class="num">{{ $currencySymbol }}{{ number_format((float) $expSub->amount, 2) }}</td>
                        <td class="num">
                            @if($expTenant)
                                @if($item['trial'] && $expTenant->admin_email)
                                    <a href="mailto:{{ $expTenant->admin_email }}" class="dsh-row-action">{{ __('super.dashboard.action_reach_out') }}</a>
                                @else
                                    <a href="{{ route('super.tenants.show', $expTenant) }}#subscription-panel" class="dsh-row-action">{{ __('super.dashboard.action_view') }}</a>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
