@extends('central.super.layout')

@section('title', __('super.payment_links.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.payment_links.title') }}</h1>
        <p class="page-subtitle">{{ __('super.payment_links.subtitle') }}</p>
    </div>
    <a href="{{ route('super.payment-links.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.payment_links.create') }}</a>
</div>

{{-- Stats --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-primary"><i class="bi bi-link-45deg"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payment_links.stat_active') }}</div>
                    <div class="stat-value">{{ number_format($stats['active']) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-warning"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payment_links.stat_outstanding') }}</div>
                    <div class="stat-value">{{ $currencySymbol }}{{ number_format($stats['outstanding'], 2) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-success"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payment_links.stat_paid') }}</div>
                    <div class="stat-value">{{ number_format($stats['paid']) }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="content-card stat-card h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon stat-icon-info"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">{{ __('super.payment_links.stat_collected') }}</div>
                    <div class="stat-value">{{ $currencySymbol }}{{ number_format($stats['collected'], 2) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filters --}}
<div class="content-card mb-4">
    <div class="card-body-custom">
        <form method="GET" action="{{ route('super.payment-links.index') }}">
            <div class="row g-3">
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control" placeholder="{{ __('super.payment_links.search') }}" value="{{ request('search') }}">
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">{{ __('super.payments.all_statuses') }}</option>
                        @foreach(\App\Models\Central\PaymentLink::STATUSES as $s)
                            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ __('super.payment_links.status_' . $s) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if(request('tenant_id'))
                <input type="hidden" name="tenant_id" value="{{ request('tenant_id') }}">
            @endif
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> {{ __('super.common.filter') }}</button>
                <a href="{{ route('super.payment-links.index') }}" class="btn btn-secondary btn-sm">{{ __('super.common.clear') }}</a>
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-link-45deg me-2 text-muted"></i>{{ __('super.payment_links.title') }}</h2>
        <span class="text-muted fs-sm2">{{ $links->total() }}</span>
    </div>
    @if($links->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.common.title') }}</th>
                    <th>{{ __('super.payments.tenant') }}</th>
                    <th>{{ __('super.common.amount') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.payment_links.expires') }}</th>
                    <th>{{ __('super.payment_links.last_sent') }}</th>
                    <th>{{ __('super.common.created') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($links as $link)
                @php
                    $badge = match($link->effective_status) {
                        'paid' => 'yes',
                        'active' => 'info',
                        'expired' => 'pending',
                        'refunded' => 'info',
                        default => 'no',
                    };
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('super.payment-links.show', $link) }}" class="fw-600 table-link-primary">{{ \Illuminate\Support\Str::limit($link->title, 60) }}</a>
                    </td>
                    <td><span class="fw-600 fs-sm3">{{ $link->tenant->company_name ?? $link->tenant_id }}</span></td>
                    <td class="fw-700 fs-sm3">{{ \App\Services\Billing\PaymentLinkNotifier::formatAmount($link) }}</td>
                    <td><span class="badge badge-{{ $badge }}">{{ __('super.payment_links.status_' . $link->effective_status) }}</span></td>
                    <td class="fs-sm2">{{ $link->expires_at ? $link->expires_at->format('M d, Y H:i') : __('super.payment_links.no_expiry') }}</td>
                    <td class="fs-sm2">{{ $link->last_sent_at ? $link->last_sent_at->diffForHumans() : '—' }}</td>
                    <td class="fs-sm2">{{ $link->created_at->format('M d, Y') }}</td>
                    <td class="action-cell">
                        <div class="d-flex gap-1 justify-content-end">
                            @if($link->isPayable())
                                <button type="button" class="btn btn-outline btn-sm js-copy-link" data-url="{{ $link->url() }}" title="{{ __('super.payment_links.copy_link') }}"><i class="bi bi-clipboard"></i></button>
                            @endif
                            <a href="{{ route('super.payment-links.show', $link) }}" class="btn btn-outline btn-sm" title="{{ __('super.common.view') }}"><i class="bi bi-eye"></i></a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body-custom">
        {{ $links->links('vendor.pagination.bootstrap-5') }}
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-link-45deg"></i></div>
        <h3>{{ __('super.payment_links.empty_title') }}</h3>
        <p>{{ __('super.payment_links.empty_desc') }}</p>
        <a href="{{ route('super.payment-links.create') }}" class="btn btn-primary btn-sm mt-2"><i class="bi bi-plus-lg"></i> {{ __('super.payment_links.create') }}</a>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets_super/js/payment-links.js') }}"></script>
@endpush
