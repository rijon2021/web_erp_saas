@extends('central.super.layout')

@section('title', __('super.tenants.tenant') . ' ' . $tenant->id)

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.tenants.index') }}">{{ __('super.tenants.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $tenant->id }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        @php
            $displayName = $tenant->company_name ?? ($tenant->domains->first()->domain ?? $tenant->id);
            $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
            $color = $colors[crc32($tenant->id) % count($colors)];
            $initial = strtoupper(mb_substr($displayName, 0, 1));
            // Only a provisioned workspace has a database, users and a live login.
            $isProvisioned = in_array($tenant->status, ['active', 'suspended', 'cancelled'], true);
        @endphp
        <div class="tenant-avatar tenant-avatar-{{ $color }} tenant-avatar-xl">{{ $initial }}</div>
        <div>
            <h1 class="mb-2xs">{{ $displayName }}</h1>
            <div class="d-flex align-items-center gap-2">
                <span class="badge badge-{{ $tenant->status }}">{{ ucfirst($tenant->status) }}</span>
                @if($tenant->isSuspendedForNonPayment())
                    <span class="text-muted fs-sm3" title="{{ __('central.SuspendedNonPaymentHint') }}"><i class="bi bi-credit-card me-1"></i>{{ __('central.SuspendedNonPayment') }}</span>
                @endif
                <span class="text-muted fs-sm3">{{ __('super.tenants.registered') }} {{ $tenant->created_at->format('M d, Y') }}</span>
            </div>
        </div>
    </div>
    <div class="btn-group-custom">
        @if(Auth::guard('central')->user()?->hasPermission('payments'))
        <a href="{{ route('super.payment-links.create', ['tenant_id' => $tenant->id]) }}" class="btn btn-secondary btn-sm">
            <i class="bi bi-link-45deg"></i> {{ __('super.payment_links.create') }}
        </a>
        @endif
        <a href="{{ route('super.tenants.edit', $tenant) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil"></i> {{ __('super.common.edit') }}
        </a>
        @if(! $isProvisioned)
            {{-- Suspend / cancel only apply to a live workspace; setup actions live in the banner below. --}}
        @elseif($tenant->status !== 'suspended')
            <form method="POST" action="{{ route('super.tenants.suspend', $tenant) }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-warning btn-sm" data-swal-action='{"title":@json(__('super.tenants.suspend')),"text":@json(__('super.tenants.confirm_suspend')),"icon":"warning","confirmText":@json(__('super.tenants.suspend')),"color":"#f59e0b"}'>
                    <i class="bi bi-pause-circle"></i> {{ __('super.tenants.suspend') }}
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('super.tenants.reactivate', $tenant) }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-success btn-sm" data-swal-action='{"title":@json(__('super.tenants.reactivate')),"text":@json(__('central.ReactivateTenantText')),"icon":"question","confirmText":@json(__('central.YesReactivate')),"color":"#10b981"}'>
                    <i class="bi bi-play-circle"></i> {{ __('super.tenants.reactivate') }}
                </button>
            </form>
        @endif
        @if($isProvisioned && $tenant->status !== 'cancelled')
            <form method="POST" action="{{ route('super.tenants.cancel', $tenant) }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-sm btn-cancel-tenant" data-swal-action='{"title":@json(__('super.common.cancel')),"text":@json(__('super.tenants.confirm_cancel')),"icon":"warning","confirmText":@json(__('super.common.yes')),"cancelText":@json(__('super.common.no')),"color":"#f43f5e"}'>
                    <i class="bi bi-x-circle"></i> {{ __('super.common.cancel') }}
                </button>
            </form>
        @endif
        <form method="POST" action="{{ route('super.tenants.destroy', $tenant) }}" class="d-inline">
            @csrf
            @method('DELETE')
            <button type="button" class="btn btn-danger btn-sm" data-swal-action='{"title":@json(__('super.tenants.delete_title')),"text":@json(__('super.tenants.delete_confirm')),"icon":"error","confirmText":@json(__('super.tenants.delete_confirm_btn')),"color":"#dc2626"}'>
                <i class="bi bi-trash"></i> {{ __('super.tenants.delete') }}
            </button>
        </form>
    </div>
</div>

@if($setup)
    @include('central.super.tenants.partials.setup-banner')
@endif

<div class="row g-4">
    {{-- Tenant details --}}
    <div class="col-lg-7">
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.tenants.details') }}</h2>
            </div>
            <div class="card-body-custom">
                <ul class="info-list">
                    <li>
                        <span class="info-label">{{ __('super.tenants.tenant_id') }}</span>
                        <span class="info-value text-mono">{{ $tenant->id }}</span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.common.status') }}</span>
                        <span class="info-value"><span class="badge badge-{{ $tenant->status }}">{{ ucfirst($tenant->status) }}</span></span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.tenants.plan') }}</span>
                        <span class="info-value">
                            @if($tenant->subscription?->plan)
                                <span class="fw-600">{{ $tenant->subscription->plan->name }}</span>
                                <span class="text-muted ms-1 fs-sm">— {{ $currencySymbol }}{{ number_format($tenant->subscription->plan->price, 2) }}/{{ __('super.plans.mo') }}</span>
                            @else
                                <span class="text-muted">{{ __('super.tenants.no_plan') }}</span>
                            @endif
                        </span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.tenants.subscription') }}</span>
                        <span class="info-value">
                            @if($tenant->subscription)
                                <span class="badge badge-{{ $tenant->subscription->status }}">{{ ucfirst($tenant->subscription->status) }}</span>
                            @else
                                <span class="text-muted">{{ __('super.tenants.no_subscription') }}</span>
                            @endif
                        </span>
                    </li>
                    @if($tenant->subscription?->trial_ends_at)
                    <li>
                        <span class="info-label">{{ __('super.tenants.trial_ends') }}</span>
                        <span class="info-value">
                            {{ $tenant->subscription->trial_ends_at->format('M d, Y') }}
                            @if($tenant->subscription->trial_ends_at->isFuture())
                                <span class="badge badge-trial ms-1">{{ $tenant->subscription->trial_ends_at->diffForHumans() }}</span>
                            @else
                                <span class="badge badge-cancelled ms-1">{{ __('super.tenants.expired') }}</span>
                            @endif
                        </span>
                    </li>
                    @endif
                    @if($tenant->locale)
                    <li>
                        <span class="info-label">{{ __('central.EmailLanguage') }}</span>
                        <span class="info-value">{{ strtoupper($tenant->locale) }}</span>
                    </li>
                    @endif
                    @if($tenant->admin_email)
                    <li>
                        <span class="info-label">{{ __('central.AdminEmail') }}</span>
                        <span class="info-value">{{ $tenant->admin_email }}</span>
                    </li>
                    @endif
                    <li>
                        <span class="info-label">{{ __('central.OwnerPhone') }}</span>
                        <span class="info-value">{{ $tenant->owner_phone ?: '—' }}</span>
                    </li>
                    <li>
                        <span class="info-label">{{ __('super.tenants.created') }}</span>
                        <span class="info-value">{{ $tenant->created_at->format('M d, Y \a\t h:i A') }}</span>
                    </li>
                    @if($tenant->updated_at && $tenant->updated_at->ne($tenant->created_at))
                    <li>
                        <span class="info-label">{{ __('super.tenants.last_updated') }}</span>
                        <span class="info-value">{{ $tenant->updated_at->format('M d, Y \a\t h:i A') }}</span>
                    </li>
                    @endif
                </ul>
            </div>
        </div>

        {{-- Plan limits & features --}}
        @if($tenant->subscription?->plan)
        @php $plan = $tenant->subscription->plan; @endphp
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-speedometer2 me-2 text-muted"></i>{{ __('super.tenants.plan_limits') }}</h2>
                <a href="{{ route('super.plans.edit', $plan) }}" class="btn btn-ghost btn-xs">
                    <i class="bi bi-pencil"></i> {{ __('super.tenants.edit_plan') }}
                </a>
            </div>
            <div class="card-body-custom">
                {{-- Limits with usage --}}
                <div class="mb-4">
                    <p class="form-label mb-2 text-section-label">{{ __('super.tenants.usage_limits') }}</p>
                    <div class="row g-2">
                        @foreach($plan->getFormattedLimits() as $key => $lim)
                        @php
                            $current = $usageStats[$key] ?? null;
                            $max = $lim['value'];
                            $isUnlimited = $max < 0;
                            $percentage = (!$isUnlimited && $max > 0 && $current !== null) ? min(100, round(($current / $max) * 100)) : 0;
                            $isNearLimit = $percentage >= 80;
                            $isAtLimit = $percentage >= 100;
                        @endphp
                        <div class="col-sm-6">
                            <div class="p-2 rounded-2 bg-surface-dark">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="d-flex align-items-center gap-2 limit-label">
                                        <i class="bi {{ $lim['icon'] }} icon-w-1rem"></i>
                                        {{ $lim['label'] }}
                                    </span>
                                    <span class="fw-700 fs-sm3" style="color: {{ $isAtLimit ? '#ef4444' : ($isNearLimit ? '#f59e0b' : ($isUnlimited ? 'var(--color-primary)' : 'var(--color-dark)')) }};">
                                        @if($current !== null && !$isUnlimited)
                                            {{ number_format($current) }}/{{ $lim['display'] }}
                                        @elseif($current !== null && $isUnlimited)
                                            {{ number_format($current) }} / <i class="bi bi-infinity"></i>
                                        @elseif($isUnlimited)
                                            <i class="bi bi-infinity"></i>
                                        @else
                                            {{ $lim['display'] }}
                                        @endif
                                    </span>
                                </div>
                                @if(!$isUnlimited && $current !== null && $max > 0)
                                <div class="usage-bar-track">
                                    <div style="height: 100%; width: {{ $percentage }}%; background: {{ $isAtLimit ? '#ef4444' : ($isNearLimit ? '#f59e0b' : '#6366f1') }}; border-radius: 2px; transition: width .3s;"></div>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Features --}}
                @php $activeFeatures = $plan->getActiveFeatures(); @endphp
                <div>
                    <p class="form-label mb-2 text-section-label">{{ __('super.tenants.feature_access') }}</p>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach(\App\Models\Central\Plan::availableFeatures() as $fKey => $fMeta)
                        @php $enabled = $plan->hasFeature($fKey); @endphp
                        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded-2 {{ $enabled ? 'feature-badge-enabled' : 'feature-badge-disabled' }}">
                            <i class="bi {{ $enabled ? 'bi-check-circle-fill' : 'bi-x-circle' }}"></i>
                            <span>{{ $fMeta['label'] }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Domains --}}
    <div class="col-lg-5">
        <div class="content-card h-100">
            <div class="card-header-custom">
                <h2><i class="bi bi-globe me-2 text-muted"></i>{{ __('super.tenants.domains') }}</h2>
                <span class="badge badge-trial badge-sm">{{ $tenant->domains->count() }} {{ Str::plural(__('central.DomainWord'), $tenant->domains->count()) }}</span>
            </div>
            <div class="card-body-custom">
                @if($tenant->domains->isNotEmpty())
                    @php
                        $customHosts = $tenant->customDomains->pluck('domain')->all();
                        $toggleOn = \App\Support\TenantDomainStatus::supported();
                        $offTarget = $toggleOn ? \App\Support\TenantDomainStatus::customDomainTarget((string) $tenant->id) : null;
                    @endphp
                    <div class="d-flex flex-column gap-2">
                        @foreach($tenant->domains as $d)
                        @php
                            $isCustomRow = in_array($d->domain, $customHosts, true);
                            $isOn = \App\Support\TenantDomainStatus::isActive($d);
                            $canOff = $toggleOn && $isOn && ! $isCustomRow && \App\Support\TenantDomainStatus::canDeactivate((string) $tenant->id, (int) $d->id);
                            $fullDomain = str_contains($d->domain, '.') ? $d->domain : $d->domain . '.' . (preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost'));
                        @endphp
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 domain-card">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-link-45deg text-muted"></i>
                                <div>
                                    <span class="text-mono fw-500 fs-sm4">{{ $fullDomain }}</span>
                                    @if(!str_contains($d->domain, '.'))
                                        <span class="text-muted d-block fs-xs2">{{ __('central.SubdomainLabel') }}: {{ $d->domain }}</span>
                                    @endif
                                    @if(! $isOn && $offTarget)
                                        <span class="text-muted d-block fs-xs2"><i class="bi bi-arrow-return-right"></i> {{ __('super.tenants.domain_off_hint', ['domain' => $offTarget]) }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                @if($isOn)
                                    <span class="badge badge-active badge-sm">{{ __('super.common.active') }}</span>
                                @else
                                    <span class="badge badge-suspended badge-sm">{{ __('super.tenants.domain_off') }}</span>
                                @endif
                                @if($toggleOn && ! $isCustomRow)
                                    @if($isOn && ! $canOff)
                                        <span class="text-muted fs-sm3" title="{{ __('super.tenants.domain_off_needs_custom') }}"><i class="bi bi-toggle-on"></i></span>
                                    @else
                                    <form method="POST" action="{{ route('super.tenants.domains.toggle', [$tenant, $d->id]) }}" class="d-inline">
                                        @csrf
                                        @if($isOn)
                                            @php $offConfirm = json_encode(['title' => __('super.tenants.domain_switch_off') . '?', 'text' => __('super.tenants.domain_switch_off_confirm', ['domain' => $fullDomain]), 'icon' => 'question', 'confirmText' => __('super.tenants.domain_switch_off'), 'color' => '#f59e0b']); @endphp
                                            <button type="button" class="btn p-0 border-0 bg-transparent text-success fs-sm3" title="{{ __('super.tenants.domain_switch_off') }}" data-swal-action='{!! e($offConfirm) !!}'>
                                                <i class="bi bi-toggle-on"></i>
                                            </button>
                                        @else
                                            <button type="submit" class="btn p-0 border-0 bg-transparent text-muted fs-sm3" title="{{ __('super.tenants.domain_switch_on') }}">
                                                <i class="bi bi-toggle-off"></i>
                                            </button>
                                        @endif
                                    </form>
                                    @endif
                                @endif
                                @if($tenant->domains->count() > 1)
                                <form method="POST" action="{{ route('super.tenants.domains.destroy', [$tenant, $d->id]) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" class="btn p-0 border-0 bg-transparent btn-delete-danger fs-sm3" data-swal-action='{"title":"Remove subdomain?","text":@json("Remove {$d->domain}? The tenant will no longer be accessible via this subdomain."),"icon":"warning","confirmText":"Yes, remove","color":"#ef4444"}'>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
                    <div class="mt-3 p-2 rounded-2 notice-warning">
                        <p class="mb-0 d-flex align-items-center gap-2 fs-xs3 text-warning-dark">
                            <i class="bi bi-info-circle-fill"></i>
                            {{ __('central.SharedHostingSubdomainNotice') }}
                        </p>
                    </div>
                    @endif
                @else
                    <div class="text-center py-4">
                        <i class="bi bi-globe text-muted fs-3xl"></i>
                        <p class="text-muted mt-2 mb-0 small">{{ __('super.tenants.no_domains') }}</p>
                    </div>
                @endif

                {{-- Add subdomain form --}}
                @php $baseDomain = preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost'); @endphp
                <div class="border-top-border mt-3 pt-3">
                    <form method="POST" action="{{ route('super.tenants.domains.store', $tenant) }}" id="add-subdomain-form">
                        @csrf
                        <label class="form-label form-label-sm">{{ __('central.AddSubdomain') }}</label>
                        <div class="d-flex gap-2">
                            <div class="input-group input-group-sm flex-grow-1">
                                <input type="text" name="domain" class="form-control form-control-sm form-control-sm-custom" placeholder="myshop" required pattern="[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?" value="{{ old('domain') }}">
                                <span class="input-group-text fs-xs3 text-muted">.{{ $baseDomain }}</span>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary-action">
                                <i class="bi bi-plus-lg me-1"></i>{{ __('central.Add') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@if($subscriptionPanel)
    @include('central.super.tenants.partials.subscription-panel')
@endif

{{-- Custom Domains --}}
@php
    $customDomainVerifier = app(\App\Services\CustomDomainVerifier::class);
    $centralHost = $customDomainVerifier->centralHost();
@endphp
<div class="content-card mt-4" id="custom-domains-card">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-globe2 me-2 text-muted"></i>{{ __('super.custom_domains.title') }}</h2>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline" data-bs-toggle="offcanvas" data-bs-target="#cdGuideDrawer" aria-controls="cdGuideDrawer">
                <i class="bi bi-book me-1"></i>{{ __('super.custom_domains.guide.button') }}
            </button>
            <span class="badge badge-trial badge-sm">{{ $tenant->customDomains->count() }}</span>
        </div>
    </div>
    <div class="card-body-custom">
        <p class="text-muted fs-sm mb-3">{{ __('super.custom_domains.subtitle') }}</p>

        @if($tenant->customDomains->isNotEmpty())
            <div class="d-flex flex-column gap-2 mb-3">
                @foreach($tenant->customDomains as $cd)
                <div class="d-flex align-items-center justify-content-between p-3 rounded-3 domain-card flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                        <i class="bi bi-{{ $cd->is_verified ? 'shield-check text-success' : 'shield-exclamation text-warning' }}"></i>
                        <div>
                            <span class="text-mono fw-500 fs-sm4">{{ $cd->domain }}</span>
                            <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                                @if($cd->is_verified)
                                    <span class="badge badge-active badge-sm">{{ __('super.custom_domains.status_verified') }}</span>
                                @else
                                    <span class="badge badge-trial badge-sm">{{ __('super.custom_domains.status_pending') }}</span>
                                @endif
                                @if($cd->is_primary)
                                    <span class="badge badge-active badge-sm">{{ __('super.custom_domains.primary_badge') }}</span>
                                @endif
                                <span class="badge badge-sm badge-ssl-{{ $cd->ssl_status }}">
                                    {{ __('super.custom_domains.ssl_' . $cd->ssl_status) }}
                                </span>
                                <span class="text-muted fs-xs2">
                                    {{ __('super.custom_domains.last_check') }}:
                                    {{ $cd->last_check_at ? $cd->last_check_at->diffForHumans() : __('super.custom_domains.never_checked') }}
                                </span>
                            </div>
                            @if(!$cd->is_verified && $cd->last_check_error)
                                <div class="text-danger fs-xs2 mt-1"><i class="bi bi-exclamation-circle me-1"></i>{{ $cd->last_check_error }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if(!$cd->is_verified)
                            <button type="button"
                                    class="btn btn-sm btn-primary-action js-verify-custom-domain"
                                    data-url="{{ route('super.tenants.custom-domains.verify', [$tenant, $cd->id]) }}">
                                <i class="bi bi-arrow-repeat me-1"></i>{{ __('super.custom_domains.verify') }}
                            </button>
                        @endif
                        @if($cd->is_verified)
                            <form method="POST" action="{{ route('super.tenants.custom-domains.check-ssl', [$tenant, $cd->id]) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline" title="{{ __('super.custom_domains.check_ssl_hint') }}">
                                    <i class="bi bi-shield-lock me-1"></i>{{ __('super.custom_domains.check_ssl') }}
                                </button>
                            </form>
                        @endif
                        @if($cd->is_verified && !$cd->is_primary)
                            <form method="POST" action="{{ route('super.tenants.custom-domains.primary', [$tenant, $cd->id]) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline">
                                    <i class="bi bi-star me-1"></i>{{ __('super.custom_domains.make_primary') }}
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('super.tenants.custom-domains.destroy', [$tenant, $cd->id]) }}" class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button type="button" class="btn p-0 border-0 bg-transparent btn-delete-danger fs-sm3"
                                    data-swal-action='{"title":@json(__('super.custom_domains.confirm_remove_title')),"text":@json(__('super.custom_domains.confirm_remove_text')),"icon":"warning","confirmText":@json(__('super.common.yes')),"cancelText":@json(__('super.common.no')),"color":"#ef4444"}'>
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- DNS instructions accordion --}}
            <details class="custom-domain-dns">
                <summary class="d-flex align-items-center gap-2 fw-600 text-muted">
                    <i class="bi bi-question-circle"></i>{{ __('super.custom_domains.dns_instructions_title') }}
                </summary>
                <div class="mt-3 p-3 rounded-3 notice-info">
                    <p class="mb-2 fs-sm">{{ __('super.custom_domains.dns_intro') }}</p>
                    <ul class="list-unstyled mb-0 fs-sm">
                        @foreach($tenant->customDomains as $cd)
                            @continue($cd->is_verified)
                            <li class="mb-3 pb-3 border-bottom">
                                <div class="text-mono fs-xs2 text-muted mb-1">{{ $cd->domain }}</div>
                                <div class="mb-1"><strong>{{ __('super.custom_domains.dns_cname_label') }}:</strong>
                                    {{ __('super.custom_domains.dns_cname_value', ['host' => $centralHost]) }}
                                </div>
                                <div class="mb-1"><strong>{{ __('super.custom_domains.dns_a_label') }}:</strong>
                                    {{ __('super.custom_domains.dns_a_value', ['host' => $centralHost]) }}
                                </div>
                                <div><strong>{{ __('super.custom_domains.dns_txt_label') }}:</strong>
                                    {{ __('super.custom_domains.dns_txt_value', ['domain' => $cd->domain, 'token' => $cd->verification_token]) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mb-0 fs-xs2 text-muted mt-2"><i class="bi bi-shield-lock me-1"></i>{{ __('super.custom_domains.ssl_hint') }}</p>
                </div>
            </details>
        @endif

        {{-- Add form --}}
        <div class="border-top-border mt-3 pt-3">
            <form method="POST" action="{{ route('super.tenants.custom-domains.store', $tenant) }}">
                @csrf
                <label class="form-label form-label-sm">{{ __('super.custom_domains.add') }}</label>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text" name="domain"
                           class="form-control form-control-sm form-control-sm-custom flex-grow-1"
                           style="min-width:260px;"
                           placeholder="{{ __('super.custom_domains.placeholder') }}"
                           value="{{ old('domain') }}"
                           required
                           pattern="[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+">
                    <button type="submit" class="btn btn-sm btn-primary-action">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('super.custom_domains.add') }}
                    </button>
                </div>
                @error('domain')
                    <div class="text-danger fs-xs2 mt-1">{{ $message }}</div>
                @enderror
            </form>
        </div>
    </div>
</div>

@include('central.super.tenants.partials.custom-domain-guide')

{{-- Admin Actions (provisioned tenants + failed tenants so a broken
     provision can be retried via Re-provision / Reset / Re-seed). --}}
@if(in_array($tenant->status, ['active', 'suspended', 'cancelled', 'failed']))
<div class="content-card mt-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-gear-wide-connected me-2 text-muted"></i>{{ __('central.AdminActions') }}</h2>
    </div>
    <div class="card-body-custom">
        <p class="admin-actions-hint"><i class="bi bi-info-circle"></i>{{ __('central.AdminActionsHint') }}</p>
        {{-- Each button sits in an .action-tip wrapper; the .action-tip-bubble
             sibling is revealed on hover / keyboard focus (CSS only, see
             central-shared.css) and explains what the action does and whether
             it keeps or destroys tenant data. --}}
        <div class="d-flex flex-wrap gap-2">
            {{-- Re-run Migrations --}}
            <div class="action-tip action-tip-migration">
                <form method="POST" action="{{ route('super.tenants.rerun-migrations', $tenant) }}" class="d-inline" id="form-rerun-migrations">
                    @csrf
                    <button type="button" class="btn btn-sm btn-migration" aria-describedby="tip-rerun-migrations" data-swal-action='{"title":@json(__('central.RerunMigrationsTitle')),"text":@json(__('central.RerunMigrationsText')),"icon":"info","confirmText":@json(__('central.YesRunMigrations')),"color":"#d97706"}'>
                        <i class="bi bi-arrow-repeat me-1"></i>{{ __('central.RerunMigrations') }}<i class="bi bi-info-circle action-tip-icon"></i>
                    </button>
                </form>
                <div class="action-tip-bubble" role="tooltip" id="tip-rerun-migrations">
                    <div class="action-tip-head"><i class="bi bi-arrow-repeat"></i>{{ __('central.RerunMigrations') }}</div>
                    <p>{{ __('central.RerunMigrationsHint') }}</p>
                    <span class="action-tip-tag is-safe"><i class="bi bi-shield-check"></i>{{ __('central.ActionKeepsData') }}</span>
                </div>
            </div>

            {{-- Re-seed --}}
            <div class="action-tip action-tip-reseed">
                <form method="POST" action="{{ route('super.tenants.reseed', $tenant) }}" class="d-inline" id="form-reseed">
                    @csrf
                    <button type="button" class="btn btn-sm btn-reseed" aria-describedby="tip-reseed" data-swal-action='{"title":@json(__('central.ReseedTitle')),"text":@json(__('central.ReseedText')),"icon":"info","confirmText":@json(__('central.YesReseed')),"color":"#6366f1"}'>
                        <i class="bi bi-database-down me-1"></i>{{ __('central.Reseed') }}<i class="bi bi-info-circle action-tip-icon"></i>
                    </button>
                </form>
                <div class="action-tip-bubble" role="tooltip" id="tip-reseed">
                    <div class="action-tip-head"><i class="bi bi-database-down"></i>{{ __('central.Reseed') }}</div>
                    <p>{{ __('central.ReseedHint') }}</p>
                    <span class="action-tip-tag is-safe"><i class="bi bi-shield-check"></i>{{ __('central.ActionKeepsData') }}</span>
                </div>
            </div>

            {{-- Re-provision (Full Re-install) --}}
            <div class="action-tip action-tip-reprovision">
                <form method="POST" action="{{ route('super.tenants.reprovision', $tenant) }}" class="d-inline" id="form-reprovision">
                    @csrf
                    <button type="button" class="btn btn-sm btn-reprovision" aria-describedby="tip-reprovision" data-swal-action='{"title":@json(__('central.ReprovisionTitle')),"html":@json(__('central.ReprovisionHtml')),"icon":"warning","confirmText":@json(__('central.YesReprovision')),"color":"#ea580c"}'>
                        <i class="bi bi-arrow-clockwise me-1"></i>{{ __('central.Reprovision') }}<i class="bi bi-info-circle action-tip-icon"></i>
                    </button>
                </form>
                <div class="action-tip-bubble" role="tooltip" id="tip-reprovision">
                    <div class="action-tip-head"><i class="bi bi-arrow-clockwise"></i>{{ __('central.Reprovision') }}</div>
                    <p>{{ __('central.ReprovisionHint') }}</p>
                    <span class="action-tip-tag is-danger"><i class="bi bi-exclamation-octagon"></i>{{ __('central.ActionDeletesData') }}</span>
                </div>
            </div>

            {{-- Reset Database (Drop & Migrate) --}}
            <div class="action-tip action-tip-reset">
                <form method="POST" action="{{ route('super.tenants.reset-database', $tenant) }}" class="d-inline" id="form-reset-db">
                    @csrf
                    <button type="button" class="btn btn-sm btn-reset-db" aria-describedby="tip-reset-db" data-swal-action='{"title":@json(__('central.ResetDatabaseTitle')),"html":@json(__('central.ResetDatabaseHtml')),"icon":"error","confirmText":@json(__('central.YesResetDatabase')),"color":"#dc2626"}'>
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ __('central.ResetDatabase') }}<i class="bi bi-info-circle action-tip-icon"></i>
                    </button>
                </form>
                <div class="action-tip-bubble" role="tooltip" id="tip-reset-db">
                    <div class="action-tip-head"><i class="bi bi-exclamation-triangle"></i>{{ __('central.ResetDatabase') }}</div>
                    <p>{{ __('central.ResetDatabaseHint') }}</p>
                    <span class="action-tip-tag is-danger"><i class="bi bi-exclamation-octagon"></i>{{ __('central.ActionDeletesData') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Database Configuration (only for provisioned tenants) --}}
@if(in_array($tenant->status, ['active', 'suspended', 'cancelled']))
@php
    $dbCreds = $tenant->getEffectiveDatabaseCredentials();
    $isCustomCreds = $tenant->hasDatabaseCredentials();
    // Own MySQL account created at auto-provisioning (not typed in by an admin).
    $isDedicatedUser = $tenant->usesDedicatedDatabaseUser();
@endphp
<div class="content-card mt-4">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-database-fill-gear me-2 text-muted"></i>{{ __('central.DatabaseConfiguration') }}</h2>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-test-connection" id="testConnBtn">
                <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
            </button>
            <button type="button" class="btn btn-sm btn-toggle-edit" id="btnToggleDbEdit">
                <i class="bi bi-pencil me-1"></i>{{ __('super.common.edit') }}
            </button>
        </div>
    </div>
    <div class="card-body-custom">
        <div id="db-test-result" class="mb-3"></div>

        {{-- Credential source indicator --}}
        <div class="mb-3">
            @if($isDedicatedUser)
            <span class="badge badge-config-auto">
                <i class="bi bi-shield-check me-1"></i>{{ __('super.tenants.db_dedicated_badge') }}
            </span>
            @elseif($isCustomCreds)
            <span class="badge badge-config-admin">
                <i class="bi bi-person-gear me-1"></i>{{ __('central.AdminConfiguredShared') }}
            </span>
            @else
            <span class="badge badge-config-auto">
                <i class="bi bi-gear me-1"></i>{{ __('central.AutoProvisionedVps') }}
            </span>
            @endif
        </div>

        {{-- Auto-provisioned on the shared central account: offer its own user. --}}
        @unless($isCustomCreds)
        <div class="mb-3 p-3 rounded-3 notice-warning">
            <p class="mb-2 fs-xs3 text-warning-dark">
                <i class="bi bi-info-circle-fill me-1"></i>{{ __('super.tenants.db_dedicated_hint') }}
            </p>
            <form method="POST" action="{{ route('super.tenants.dedicated-db-user', $tenant) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-person-lock me-1"></i>{{ __('super.tenants.db_dedicated_button') }}
                </button>
            </form>
        </div>
        @endunless
        {{-- Read-only view --}}
        <ul class="info-list" id="db-readonly-view">
            <li>
                <span class="info-label">{{ __('central.DbHost') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['host'] }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbName') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['database'] }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbUsername') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['username'] }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbPassword') }}</span>
                <span class="info-value text-mono">{{ $isCustomCreds ? '••••••••' : __('central.FromEnv') }}</span>
            </li>
            <li>
                <span class="info-label">{{ __('central.DbPort') }}</span>
                <span class="info-value text-mono">{{ $dbCreds['port'] }}</span>
            </li>
        </ul>
    </div>
</div>

{{-- Edit Database Credentials (separate card, toggled from the Database Configuration card) --}}
<div id="db-edit-form" class="content-card mt-4" style="display:none;">
    <div class="card-header-custom">
        <h2><i class="bi bi-pencil-square me-2 text-muted"></i>{{ __('central.EditDatabaseCredentials') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="mb-3 p-3 rounded-3" style="background:#fef2f2; border:1px solid #fecaca;">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill text-danger flex-shrink-0" style="font-size:1rem; margin-top:2px;"></i>
                <p class="mb-0" style="font-size:0.82rem; color:#991b1b;">{{ __('central.ActiveTenantDbWarning') }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('super.tenants.update-credentials', $tenant) }}" id="form-update-creds">
            @csrf
            @method('PUT')
            {{-- Subdomain info for shared hosting --}}
            @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
            @php $subdomainName = $tenant->domains->first()->domain ?? null; @endphp
            @if($subdomainName)
            <div class="mb-3 p-2 rounded-2 notice-warning">
                <p class="mb-0 d-flex align-items-center gap-2 fs-xs3 text-warning-dark">
                    <i class="bi bi-info-circle-fill"></i>
                    {{ __('central.SubdomainLabel') }}: <code class="code-badge-warning-sm">{{ $subdomainName }}.{{ preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost') }}</code>
                    — {{ __('central.EnsureSubdomainExists') }}
                </p>
            </div>
            @endif
            @endif
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                    <input type="text" name="db_host" class="form-control form-control-sm" value="{{ $dbCreds['host'] ?? 'localhost' }}" required class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                    <input type="text" name="db_name" class="form-control form-control-sm" value="{{ $dbCreds['database'] ?? '' }}" required placeholder="tenant_{{ $tenant->domains->first()->domain ?? 'name' }}" class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                    <input type="number" name="db_port" class="form-control form-control-sm" value="{{ $dbCreds['port'] ?? 3306 }}" required min="1" max="65535" class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbUser') }} <span class="text-danger">*</span></label>
                    <input type="text" name="db_username" class="form-control form-control-sm" value="{{ $dbCreds['username'] ?? '' }}" required placeholder="db_user" class="form-control-sm-custom">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">{{ __('central.DbPassword') }}</label>
                    <div class="input-group input-group-sm">
                        <input type="password" name="db_password" id="db-password-field" class="form-control form-control-sm" value="{{ $dbCreds['password'] ?? '' }}" class="form-control-sm-custom">
                        <button type="button" class="btn btn-outline-secondary fs-xs3" id="btnToggleDbPassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-sm btn-test-connection" id="btnTestFormConnection">
                        <i class="bi bi-plug me-1"></i>{{ __('central.Test') }}
                    </button>
                </div>
            </div>
            <div id="db-edit-test-result" class="mt-2"></div>
            <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top-border-light">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel-sm" id="btnCancelDbEdit">{{ __('central.Cancel') }}</button>
                <button type="button" class="btn btn-sm btn-primary-action approve-locked" id="btnSaveCreds" data-swal-action='{"title":@json(__('central.UpdateCredentialsTitle')),"text":@json(__('central.UpdateCredentialsText')),"icon":"question","confirmText":@json(__('central.YesSave')),"color":"#6366f1"}'>
                    <i class="bi bi-save me-1"></i>{{ __('central.SaveCredentials') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Billing currency (multi-currency pricing) — shown while the feature is on,
     or when this workspace already pays in a currency other than the main one. --}}
@php
    $tenantBillingCurrency = \App\Services\Billing\PlanPricing::tenantCurrency($tenant);
    $offeredCurrencies = \App\Support\SellingCurrencies::active();
@endphp
@if(\App\Support\SellingCurrencies::enabled() || $tenantBillingCurrency !== \App\Support\SellingCurrencies::main())
<div class="content-card mt-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-currency-exchange me-2 text-muted"></i>{{ __('super.currencies.tenant_card_title') }}</h2>
    </div>
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.tenants.billing-currency', $tenant) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-sm-5 col-md-4">
                <label class="form-label form-label-sm" for="billingCurrency">{{ __('super.currencies.tenant_currency') }}</label>
                <select id="billingCurrency" name="billing_currency" class="form-select form-select-sm">
                    @foreach(array_unique(array_merge($offeredCurrencies, [$tenantBillingCurrency])) as $code)
                        <option value="{{ $code }}" @selected($code === $tenantBillingCurrency)>{{ $code }} — {{ \App\Support\SellingCurrencies::symbol($code) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">{{ __('super.common.save') }}</button>
            </div>
            <p class="form-hint mb-0 mt-1">{{ __('super.currencies.tenant_hint') }}</p>
        </form>
    </div>
</div>
@endif

{{-- Add-ons: modules / premium themes on top of the plan --}}
@if(\App\Models\Central\Addon::tablesReady() && auth('central')->user()?->hasPermission('addons'))
@php
    $tenantAddons = \App\Models\Central\TenantAddon::where('tenant_id', $tenant->id)->latest('id')->limit(30)->get();
    $addonCatalog = \App\Models\Central\Addon::catalog();
@endphp
<div class="content-card mt-4">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-puzzle me-2 text-muted"></i>{{ __('super.addons.tenant_card_title') }}</h2>
        <a href="{{ route('super.addons.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('super.addons.manage_catalog') }}</a>
    </div>
    <div class="card-body-custom">
        @if($tenantAddons->isEmpty())
            <p class="text-muted">{{ __('super.addons.tenant_none') }}</p>
        @else
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('super.addons.col_addon') }}</th>
                            <th>{{ __('super.addons.col_cycle') }}</th>
                            <th>{{ __('super.addons.col_amount') }}</th>
                            <th>{{ __('super.addons.col_status') }}</th>
                            <th>{{ __('super.addons.col_ends') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tenantAddons as $ta)
                            <tr>
                                <td>{{ $ta->displayName() }}
                                    @if($ta->source === 'admin')<span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{ __('super.addons.source_admin') }}</span>@endif
                                </td>
                                <td>{{ __('super.addons.cycle_' . $ta->billing_cycle) }}</td>
                                <td dir="ltr">{{ $ta->currency }} {{ number_format((float) $ta->amount, 2) }}</td>
                                <td>@include('central.super.addons._status', ['row' => $ta])</td>
                                <td>{{ $ta->ends_at ? $ta->ends_at->format('M d, Y') : ($ta->status === 'active' ? __('super.addons.no_end') : '—') }}</td>
                                <td class="text-end">
                                    @if(in_array($ta->status, ['active', 'pending'], true))
                                        <form method="POST" action="{{ route('super.addons.revoke', $ta) }}" class="d-inline"
                                              onsubmit="return confirm(@js(__('super.addons.revoke_confirm')))">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('super.addons.revoke') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form method="POST" action="{{ route('super.addons.grant', $tenant) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label form-label-sm">{{ __('super.addons.grant_addon') }}</label>
                <select name="addon_key" class="form-select form-select-sm" required>
                    @foreach($addonCatalog as $key => $meta)
                        <option value="{{ $key }}">{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm">{{ __('super.addons.grant_until') }}</label>
                <input type="date" name="until" class="form-control form-control-sm" min="{{ now()->toDateString() }}">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm">{{ __('super.addons.grant_note') }}</label>
                <input type="text" name="note" class="form-control form-control-sm" maxlength="500">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm" title="{{ __('super.addons.grant_quantity_hint') }}">{{ __('super.addons.grant_quantity') }}</label>
                <input type="number" name="quantity" class="form-control form-control-sm" min="1" max="100" value="1" title="{{ __('super.addons.grant_quantity_hint') }}">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-gift me-1"></i>{{ __('super.addons.grant') }}</button>
            </div>
            <p class="form-hint mb-0 mt-1">{{ __('super.addons.grant_hint') }}</p>
        </form>
    </div>
</div>
@endif

{{-- Users (a workspace has users only once it is provisioned) --}}
@if($isProvisioned)
<div class="content-card mt-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-people me-2 text-muted"></i>{{ __('central.Users') }}</h2>
        <div class="d-flex align-items-center gap-2">
            @if(count($tenantUsers) > 0)
                @php $forceLogoutAllText = __('central.ForceLogoutAllText', ['tenant' => $displayName]); @endphp
                <form method="POST" action="{{ route('super.tenants.force-logout', $tenant) }}" class="d-inline">
                    @csrf
                    <button type="button" class="btn btn-outline btn-xs btn-outline-danger-inline" data-swal-action='{"title":@json(__('central.ForceLogoutAllTitle')),"text":@json($forceLogoutAllText),"icon":"warning","confirmText":@json(__('central.YesForceLogout')),"color":"#ef4444"}'>
                        <i class="bi bi-box-arrow-right"></i> {{ __('central.ForceLogoutAll') }}
                    </button>
                </form>
            @endif
            <a href="{{ route('super.tenants.users', $tenant) }}" class="btn btn-ghost btn-xs">
                {{ __('central.ViewAll', ['count' => $tenantUserCount]) }} <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
    <div class="card-body-custom p-0">
        @if(count($tenantUsers) > 0)
            <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="w-50px">#</th>
                        <th>{{ __('central.UserCol') }}</th>
                        <th>{{ __('central.EmailCol') }}</th>
                        <th>{{ __('central.Role') }}</th>
                        <th>{{ __('central.StatusCol') }}</th>
                        <th class="action-cell">{{ __('central.Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tenantUsers as $tUser)
                    <tr>
                        <td><span class="fw-600 text-muted">{{ $tUser->id }}</span></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-600 fs-sm4">{{ $tUser->firstname }} {{ $tUser->lastname }}</span>
                                @if((int) $tUser->id === 1)
                                    <span class="badge badge-trial badge-owner">{{ __('central.Owner') }}</span>
                                @endif
                            </div>
                        </td>
                        <td><span class="text-muted fs-sm3">{{ $tUser->email }}</span></td>
                        <td>
                            @php $role = $tUser->roles->first(); @endphp
                            @if($role)
                                <span class="badge badge-trial badge-sm">{{ $role->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($tUser->statut)
                                <span class="badge badge-active">{{ __('central.Active') }}</span>
                            @else
                                <span class="badge badge-cancelled">{{ __('central.Blocked') }}</span>
                            @endif
                        </td>
                        <td class="action-cell">
                            @if($tUser->statut && $tenant->isActive())
                                {{-- @json() splits its argument on commas, so the message is
                                     built first — otherwise the escaping flags are dropped and
                                     a name containing an apostrophe breaks the attribute. --}}
                                @php $impersonateText = __('central.LoginAsUserText', ['name' => $tUser->firstname . ' ' . $tUser->lastname]); @endphp
                                {{-- Opens in a new tab so the admin keeps this page; rel keeps the
                                     tenant origin from reaching back through window.opener. --}}
                                <form method="POST" action="{{ route('super.tenants.users.impersonate', [$tenant, $tUser->id]) }}" class="d-inline" target="_blank" rel="noopener">
                                    @csrf
                                    <button type="button" class="btn btn-outline btn-sm" data-swal-action='{"title":@json(__('central.LoginAsUserTitle')),"text":@json($impersonateText),"icon":"warning","confirmText":@json(__('central.YesLoginAsUser')),"color":"#6366f1"}'>
                                        <i class="bi bi-box-arrow-in-right"></i> {{ __('central.LoginAsUser') }}
                                    </button>
                                </form>
                            @endif
                            @php $forceLogoutText = __('central.ForceLogoutUserText', ['name' => $tUser->firstname . ' ' . $tUser->lastname]); @endphp
                            <form method="POST" action="{{ route('super.tenants.users.force-logout', [$tenant, $tUser->id]) }}" class="d-inline">
                                @csrf
                                <button type="button" class="btn btn-outline btn-sm" data-swal-action='{"title":@json(__('central.ForceLogoutUserTitle')),"text":@json($forceLogoutText),"icon":"warning","confirmText":@json(__('central.YesForceLogout')),"color":"#ef4444"}'>
                                    <i class="bi bi-box-arrow-right"></i> {{ __('central.ForceLogout') }}
                                </button>
                            </form>
                            <button type="button"
                                    class="btn btn-outline btn-sm"
                                    data-change-password-url="{{ route('super.tenants.users.password', [$tenant, $tUser->id]) }}"
                                    data-user-name="{{ $tUser->firstname }} {{ $tUser->lastname }}">
                                <i class="bi bi-key"></i> {{ __('central.ChangePassword') }}
                            </button>
                            @if($tUser->statut)
                                <form method="POST" action="{{ route('super.tenants.users.block', [$tenant, $tUser->id]) }}" class="d-inline">
                                    @csrf
                                    <button type="button" class="btn btn-outline btn-sm btn-outline-danger-inline" data-swal-action='{"title":@json(__('central.BlockUserTitle')),"text":@json(__('central.BlockUserText', ['name' => $tUser->firstname . ' ' . $tUser->lastname])),"icon":"warning","confirmText":@json(__('central.YesBlock')),"color":"#ef4444"}'>
                                        <i class="bi bi-slash-circle"></i> {{ __('central.Block') }}
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('super.tenants.users.unblock', [$tenant, $tUser->id]) }}" class="d-inline">
                                    @csrf
                                    <button type="button" class="btn btn-outline btn-sm btn-outline-success-inline" data-swal-action='{"title":@json(__('central.UnblockUserTitle')),"text":@json(__('central.UnblockUserText', ['name' => $tUser->firstname . ' ' . $tUser->lastname])),"icon":"question","confirmText":@json(__('central.YesUnblock')),"color":"#10b981"}'>
                                        <i class="bi bi-check-circle"></i> {{ __('central.Unblock') }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @if($tenantUserCount > 5)
                <div class="text-center py-3 border-top-border">
                    <a href="{{ route('super.tenants.users', $tenant) }}" class="text-primary fw-600 fs-sm3 text-no-decoration">
                        {{ __('central.ViewAllUsers', ['count' => $tenantUserCount]) }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            @endif
        @else
            <div class="text-center py-4">
                <i class="bi bi-people text-muted fs-3xl"></i>
                <p class="text-muted mt-2 mb-0 small">{{ __('central.NoUsersForTenant') }}</p>
            </div>
        @endif
    </div>
</div>
@endif

<div class="mt-4">
    <a href="{{ route('super.tenants.index') }}" class="back-link">
        <i class="bi bi-arrow-left"></i> {{ __('super.tenants.back_to_tenants') }}
    </a>
</div>

@push('scripts')
<script>
window.TenantShowData = {
    csrfToken: '{{ csrf_token() }}',
    status: @json($tenant->status),
    statusUrl: '{{ route("super.tenants.provisioning-status", $tenant) }}',
    testConnectionUrl: '{{ route("super.tenants.test-connection", $tenant) }}',
    testConnectionPreUrl: '{{ route("super.tenants.test-connection-pre") }}',
    createDatabasePreUrl: '{{ route("super.tenants.create-database-pre") }}',
    createDatabaseUrl: '{{ route("super.tenants.create-database", $tenant) }}',
    trans: {
        cancelText: @json(__('super.common.no')),
        approvingTitle: @json(__('central.ApprovingAndProvisioning')),
        processingTitle: @json(__('central.Processing')),
        provisioningHtml: @json(__('central.ProvisioningWaitHtml')),
        processingHtml: @json(__('central.ProcessingWaitHtml')),
        provisioningTimedOut: @json(__('central.ProvisioningTimedOut')),
        provisioningTimedOutText: @json(__('central.ProvisioningTimedOutText')),
        successTitle: @json(__('central.Success')),
        provisioningSuccessText: @json(__('central.ProvisioningSuccessText')),
        provisioningFailedTitle: @json(__('central.ProvisioningFailed')),
        testingConnection: @json(__('central.TestingConnection')),
        requestFailed: @json(__('central.RequestFailed')),
        fillDbFields: @json(__('central.FillDbFieldsBeforeTesting')),
        createDatabase: @json(__('central.CreateDatabase')),
        creatingDatabase: @json(__('central.CreatingDatabase')),
        testConnectionFirst: @json(__('central.TestConnectionFirst')),
        testConnectionFirstText: @json(__('central.TestConnectionFirstText')),
        testBeforeSavingText: @json(__('central.TestBeforeSavingText')),
        verifyingConnection: @json(__('central.VerifyingConnection')),
        connectionCheckFailed: @json(__('central.ConnectionCheckFailed')),
        customDomainVerifying:     @json(__('super.custom_domains.verifying')),
        customDomainVerifiedTitle: @json(__('super.custom_domains.status_verified')),
        customDomainFailedTitle:   @json(__('super.custom_domains.errors.unknown')),
        rejectTitle:               @json(__('central.RejectTenant')),
        rejectText:                @json(__('central.SetupRejectText')),
        rejectReasonPlaceholder:   @json(__('central.RejectionReasonPlaceholder')),
        rejectReasonRequired:      @json(__('central.SetupRejectReasonRequired')),
        elapsed:                   @json(__('central.SetupElapsed')),
    },
};
window.TenantUserPasswordData = {
    csrfToken: '{{ csrf_token() }}',
    trans: {
        title:           @json(__('central.ChangePasswordTitle')),
        subtitle:        @json(__('central.ChangePasswordSubtitle')),
        newPassword:     @json(__('central.NewPassword')),
        confirmPassword: @json(__('central.ConfirmNewPassword')),
        confirm:         @json(__('central.ChangePassword')),
        cancel:          @json(__('central.Cancel')),
        tooShort:        @json(__('central.PasswordTooShort')),
        mismatch:        @json(__('central.PasswordsDoNotMatch')),
    },
};
</script>
<script src="{{ asset('assets_super/js/tenants-show.js') }}"></script>
<script src="{{ asset('assets_super/js/tenant-user-password.js') }}"></script>
@endpush
@endsection
