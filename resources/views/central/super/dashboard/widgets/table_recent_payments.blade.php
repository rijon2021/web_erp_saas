<section class="dsh-card h-100">
    @include('central.super.dashboard.widgets._header', [
        'badge' => __('super.dash_custom.widgets.table_recent_payments.desc'),
        'link' => route('super.payments.index'),
        'link_label' => __('super.dashboard.all_transactions'),
    ])
    <div class="dsh-card-body dsh-table-wrap">
        @if($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-receipt'])
        @else
        <table class="dsh-table">
            <thead>
                <tr>
                    <th>{{ __('super.tenants.tenant') }}</th>
                    <th>{{ __('super.tenants.plan') }}</th>
                    <th>{{ __('super.dash_custom.col_gateway') }}</th>
                    <th>{{ __('super.dash_custom.col_status') }}</th>
                    <th>{{ __('super.dashboard.col_when') }}</th>
                    <th class="num">{{ __('super.dash_custom.col_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($d['rows'] as $payment)
                    @php
                        $payTone = match ($payment->status) { 'paid' => 'good', 'failed' => 'crit', 'refunded' => 'info', default => 'warn' };
                        $payName = $payment->tenant?->company_name ?: ($payment->tenant?->domains->first()->domain ?? ($payment->tenant_id ?: '—'));
                        $payWhen = $payment->paid_at ?? $payment->created_at;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('super.payments.show', $payment) }}" class="dsh-tenant">
                                @include('central.super.dashboard.widgets._avatar', ['name' => $payName])
                                <div class="nm">{{ $payName }}</div>
                            </a>
                        </td>
                        <td class="text-nowrap">{{ $payment->plan?->name ?? '—' }}@if(($payment->billing_cycle ?? null) === 'yearly') <span class="dsh-muted">· {{ mb_strtolower(__('super.dash_custom.cycle_yearly')) }}</span>@endif</td>
                        <td>{{ $payment->gateway_label }}</td>
                        <td><span class="dsh-pill dsh-pill--{{ $payTone }}">{{ trans()->has('super.status.' . $payment->status) ? __('super.status.' . $payment->status) : ucfirst($payment->status) }}</span></td>
                        <td class="dsh-muted text-nowrap" title="{{ $payWhen?->format('M d, Y H:i') }}">{{ $payWhen?->diffForHumans(null, false, true) }}</td>
                        <td class="num text-nowrap">{{ $payment->status === 'refunded' ? '−' : '' }}{{ $currencySymbol }}{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</section>
