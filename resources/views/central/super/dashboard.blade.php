@extends('central.super.layout')

@section('title', __('super.dashboard.title'))

@push('styles')
<link href="{{ \App\Support\SuperAsset::url('assets_super/css/dashboard.css') }}" rel="stylesheet">
@endpush

@php
    $hour = now()->hour;
    $greeting = $hour < 12 ? __('super.dashboard.good_morning') : ($hour < 18 ? __('super.dashboard.good_afternoon') : __('super.dashboard.good_evening'));
    $adminUser = auth()->guard('central')->user();
    $compact = ($settings['density'] ?? 'comfortable') === 'compact';

    // Chart specs are rendered by dashboard.js once their containers exist
    // (the HTML-rendered kinds, segments and bars, need no script).
    $chartSpecs = collect($widgets)->where('group', 'chart')->map(fn ($w) => $w['data']['spec'])
        ->reject(fn ($spec) => in_array($spec['kind'], ['segments', 'bars'], true))->values()->all();
@endphp

@section('content')

{{-- Header: greeting, period chips and the two actions --}}
<div class="dsh-head">
    <div>
        @if($settings['show_greeting'] ?? true)
            <h1>{{ $greeting }}, {{ $adminUser->name ?? __('super.common.admin') }}</h1>
        @else
            <h1>{{ __('super.dashboard.title') }}</h1>
        @endif
        <p>{{ __('super.dashboard.subtitle_period', ['date' => now()->translatedFormat('l, j F Y')]) }} {{ __('super.dashboard.subtitle_compare') }}
            @if($period)
                <span class="dsh-head-note">{{ __('super.dashboard.period_active', ['period' => __('super.dashboard.period_' . $period)]) }}</span>
            @endif
        </p>
    </div>
    <div class="dsh-actions">
        <div class="dsh-range" role="group" aria-label="{{ __('super.dashboard.period') }}" title="{{ __('super.dashboard.period_hint') }}">
            @foreach($periods as $key)
                <a href="{{ route('super.dashboard', ['period' => $period === $key ? '' : $key]) }}"
                   class="{{ $period === $key ? 'is-active' : '' }}" aria-pressed="{{ $period === $key ? 'true' : 'false' }}">{{ __('super.dashboard.period_' . $key) }}</a>
            @endforeach
        </div>
        @if($adminUser?->hasPermission('tenants'))
            <a href="{{ route('super.tenants.create') }}" class="dsh-btn dsh-btn--primary"><i class="bi bi-plus-lg"></i>{{ __('super.dashboard.new_tenant') }}</a>
        @endif
    </div>
</div>

{{-- Widget grid (order, size and visibility from Settings > General > Dashboard) --}}
<div class="row {{ $compact ? 'g-2 g-xl-3' : 'g-3 g-xl-4' }} dash-grid dsh-grid {{ $compact ? 'dash-grid--compact' : '' }}">
    @php $stripOpen = false; @endphp
    @forelse($widgets as $i => $w)
        @php
            $partial = match ($w['group']) {
                'kpi'   => '_kpi',
                'chart' => '_chart',
                default => $w['key'],
            };
            // Consecutive mini KPIs ("sixth" width) share one strip card, as in the design.
            $isMini = $w['group'] === 'kpi' && $w['width'] === 'sixth';
            $nextMini = isset($widgets[$i + 1]) && $widgets[$i + 1]['group'] === 'kpi' && $widgets[$i + 1]['width'] === 'sixth';
        @endphp
        @if($isMini)
            @if(! $stripOpen)
                @php $stripOpen = true; @endphp
                <div class="col-12 dash-cell dash-cell--strip">
                    <div class="dsh-card dsh-strip">
            @endif
            <div class="dsh-strip-cell" data-widget="{{ $w['key'] }}">
                @include('central.super.dashboard.widgets._kpi', ['w' => $w, 'd' => $w['data']])
            </div>
            @if(! $nextMini)
                @php $stripOpen = false; @endphp
                    </div>
                </div>
            @endif
        @else
            <div class="{{ $w['column'] }} dash-cell dash-cell--{{ $w['group'] }}" data-widget="{{ $w['key'] }}">
                @include('central.super.dashboard.widgets.' . $partial, ['w' => $w, 'd' => $w['data']])
            </div>
        @endif
    @empty
        <div class="col-12">
            <div class="dsh-card">
                <div class="dsh-card-body">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-grid-1x2"></i></div>
                        <h3>{{ __('super.dash_custom.empty_title') }}</h3>
                        <p>{{ __('super.dash_custom.empty_desc') }}</p>
                    </div>
                </div>
            </div>
        </div>
    @endforelse
</div>

@push('scripts')
<script>
window.DashboardData = {
    tenantsUrl: @json(url('super/tenants')),
    csrfToken:  @json(csrf_token()),
    refreshMinutes: @json((int) ($settings['refresh'] ?? 0)),
    trans: {
        testingConnection:   @json(__('central.TestingConnection')),
        approveTenantTitle:  @json(__('central.ApproveTenantTitle')),
        approveTenantHtml:   @json(__('central.ApproveTenantHtml')),
        yesApprove:          @json(__('central.YesApprove')),
        cancelText:          @json(__('central.Cancel')),
        provisioningTitle:   @json(__('central.ProvisioningInProgress')),
        provisioningHtml:    @json(__('central.ApprovingInProgressHtml')),
        rejectRegistrationFor: @json(__('central.RejectRegistrationFor')),
        requestFailed:       @json(__('central.RequestFailed')),
        fillDbFields:        @json(__('central.FillDbFieldsBeforeTesting')),
        creatingDatabase:    @json(__('central.CreatingDatabase')),
        createDatabase:      @json(__('central.CreateDatabase')),
        testConnectionFirst: @json(__('central.TestConnectionFirst')),
        testConnectionFirstText: @json(__('central.TestConnectionFirstText')),
        tenantApprovedTitle: @json(__('central.TenantApproved')),
        tenantApprovedText:  @json(__('central.TenantApprovedText')),
        provisioningFailedTitle: @json(__('central.ProvisioningFailed')),
        provisioningTimedOutText: @json(__('central.ProvisioningTimedOutText')),
        verifyingConnection: @json(__('central.VerifyingConnection')),
        connectionCheckFailed: @json(__('central.ConnectionCheckFailed')),
    },
    charts: {
        specs:          @json($chartSpecs),
        currencySymbol: @json($currencySymbol),
        noDataLabel:    @json(__('super.dashboard.no_data')),
        noDataDesc:     @json(__('super.dash_custom.no_data_desc')),
    },
};
</script>
{{-- Deferred: the 500 KB chart library no longer blocks parsing; both run in
     order before DOMContentLoaded, which is when dashboard.js draws the charts. --}}
@if(! empty($chartSpecs))
<script src="{{ \App\Support\SuperAsset::url('assets_super/js/apexcharts.min.js') }}" defer></script>
@endif
<script src="{{ \App\Support\SuperAsset::url('assets_super/js/dashboard.js') }}" defer></script>
@endpush
@endsection
