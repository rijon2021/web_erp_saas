<div class="content-card h-100">
    @include('central.super.dashboard.widgets._header', ['badge' => $d['range']])
    <div class="card-body-custom p-0">
        @if($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-trophy'])
        @else
        <div class="table-responsive">
            <table class="table table-hover mb-0 dash-table">
                <thead>
                    <tr>
                        <th class="dash-rank-col">#</th>
                        <th>{{ __('super.tenants.tenant') }}</th>
                        <th class="text-end">{{ __('super.dash_custom.col_payments') }}</th>
                        <th class="text-end">{{ __('super.dash_custom.col_total_paid') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($d['rows'] as $i => $row)
                        <tr>
                            <td><span class="dash-rank dash-rank--{{ min($i + 1, 4) }}">{{ $i + 1 }}</span></td>
                            <td>
                                @if($row['tenant'])
                                    <a href="{{ route('super.tenants.show', $row['tenant']) }}" class="fw-600 dash-link">{{ $row['tenant']->company_name ?: ($row['tenant']->domains->first()->domain ?? $row['tenant']->id) }}</a>
                                @else
                                    <span class="text-muted">{{ $row['tenant_id'] }}</span>
                                @endif
                            </td>
                            <td class="text-end text-muted">{{ number_format($row['payments']) }}</td>
                            <td class="text-end fw-700 text-nowrap">{{ $currencySymbol }}{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
