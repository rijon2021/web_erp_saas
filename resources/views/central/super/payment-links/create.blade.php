@extends('central.super.layout')

@section('title', __('super.payment_links.create'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.payment-links.index') }}">{{ __('super.payment_links.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.payment_links.create') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.payment_links.create') }}</h1>
    <p class="page-subtitle">{{ __('super.payment_links.create_desc') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-link-45deg me-2 text-muted"></i>{{ __('super.payment_links.details') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.payment-links.store') }}" id="paymentLinkForm">
                    @csrf

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.payments.tenant') }} <span class="text-danger">*</span></label>
                        <select name="tenant_id" id="plTenant" class="form-control" required>
                            <option value="">{{ __('super.payments.select_tenant') }}</option>
                            @foreach($tenants as $tenant)
                                <option value="{{ $tenant->id }}"
                                        data-email="{{ $tenant->admin_email }}"
                                        {{ old('tenant_id', $selectedTenantId) === $tenant->id ? 'selected' : '' }}>
                                    {{ $tenant->company_name ?? $tenant->id }}
                                    @if($tenant->admin_email) — {{ $tenant->admin_email }} @endif
                                </option>
                            @endforeach
                        </select>
                        @error('tenant_id')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.common.title') }} <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="{{ old('title') }}" maxlength="190" required placeholder="{{ __('super.payment_links.title_hint') }}">
                        @error('title')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.common.description') }}</label>
                        <textarea name="description" class="form-control" rows="3" maxlength="2000" placeholder="{{ __('super.payment_links.description_hint') }}">{{ old('description') }}</textarea>
                        @error('description')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.amount') }} ({{ $linkCurrency }}) <span class="text-danger">*</span></label>
                            <div class="currency-input-wrap">
                                <span class="currency-input-symbol">{{ $currencySymbol }}</span>
                                <input type="number" name="amount" class="form-control currency-input" value="{{ old('amount') }}" step="0.01" min="0.01" required>
                            </div>
                            @error('amount')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.payment_links.expires') }}</label>
                            <input type="datetime-local" name="expires_at" class="form-control" value="{{ old('expires_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}">
                            <p class="text-muted fs-sm2 mt-1 mb-0">{{ __('super.payment_links.expires_hint') }}</p>
                            @error('expires_at')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="divider"></div>

                    <div class="form-group mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="send_email" value="1" id="plSendEmail" {{ (old('_token') ? old('send_email') : true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="plSendEmail">{{ __('super.payment_links.send_email_now') }}</label>
                        </div>
                    </div>
                    <div class="form-group mb-4" id="plEmailWrap">
                        <label class="form-label">{{ __('super.payment_links.email_to') }}</label>
                        <input type="email" name="email_to" id="plEmailTo" class="form-control" value="{{ old('email_to') }}" maxlength="190">
                        @error('email_to')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> {{ __('super.payment_links.create_btn') }}</button>
                        <a href="{{ route('super.payment-links.index') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card sticky-sidebar">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-lightbulb text-muted fs-base"></i>
                    <h3 class="mb-0 fw-700 tips-card-title">{{ __('super.payments.tips') }}</h3>
                </div>
                <div class="d-flex flex-column gap-3 tips-card-body">
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle tips-icon-info"></i>
                        <span>{{ __('super.payment_links.tip_1') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-whatsapp tips-icon-info"></i>
                        <span>{{ __('super.payment_links.tip_2') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-shield-check tips-icon-success"></i>
                        <span>{{ __('super.payment_links.tip_3') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets_super/js/payment-links.js') }}"></script>
@endpush
