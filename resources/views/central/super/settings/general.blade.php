@extends('central.super.layout')

@section('title', __('super.general_settings.title'))

@push('styles')
<link href="{{ \App\Support\SuperAsset::url('assets_super/css/settings-general.css') }}" rel="stylesheet">
@endpush

@section('content')
<div class="page-header">
    <h1>{{ __('super.general_settings.title') }}</h1>
    <p class="page-subtitle">{{ __('super.general_settings.subtitle') }}</p>
</div>

    <div class="settings-shell">
        {{-- ── Side navigation ───────────────────────────────────────── --}}
        <aside class="settings-side" aria-label="{{ __('super.general_settings.title') }}">
            <nav class="settings-nav" role="tablist" aria-orientation="vertical">
                <button type="button" class="settings-nav-item is-active" role="tab" data-tab="general" aria-selected="true" aria-controls="panel-general">
                    <i class="bi bi-sliders"></i>
                    <span>{{ __('super.general_settings.tab_general') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="branding" aria-selected="false" aria-controls="panel-branding">
                    <i class="bi bi-palette"></i>
                    <span>{{ __('super.general_settings.tab_branding') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="appearance" aria-selected="false" aria-controls="panel-appearance">
                    <i class="bi bi-brush"></i>
                    <span>{{ __('super.general_settings.tab_appearance') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="dashboard" aria-selected="false" aria-controls="panel-dashboard">
                    <i class="bi bi-grid-1x2"></i>
                    <span>{{ __('super.dash_custom.tab_title') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="company" aria-selected="false" aria-controls="panel-company">
                    <i class="bi bi-building"></i>
                    <span>{{ __('super.general_settings.tab_company') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="tenant" aria-selected="false" aria-controls="panel-tenant">
                    <i class="bi bi-buildings"></i>
                    <span>{{ __('super.general_settings.tab_tenant') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="reminders" aria-selected="false" aria-controls="panel-reminders">
                    <i class="bi bi-alarm"></i>
                    <span>{{ __('super.general_settings.tab_reminders') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="landing" aria-selected="false" aria-controls="panel-landing">
                    <i class="bi bi-globe"></i>
                    <span>{{ __('super.general_settings.tab_landing') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="payments" aria-selected="false" aria-controls="panel-payments">
                    <i class="bi bi-bank"></i>
                    <span>{{ __('super.general_settings.tab_payments') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="backup" aria-selected="false" aria-controls="panel-backup">
                    <i class="bi bi-shield-lock"></i>
                    <span>{{ __('super.general_settings.tab_backup') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="system" aria-selected="false" aria-controls="panel-system">
                    <i class="bi bi-hdd-rack"></i>
                    <span>{{ __('super.general_settings.tab_system') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="update" aria-selected="false" aria-controls="panel-update">
                    <i class="bi bi-cloud-arrow-down"></i>
                    <span>{{ __('super.general_settings.tab_update') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="security" aria-selected="false" aria-controls="panel-security">
                    <i class="bi bi-shield-lock"></i>
                    <span>{{ __('super.sidebar.security_settings') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="einvoicing" aria-selected="false" aria-controls="panel-einvoicing">
                    <i class="bi bi-receipt-cutoff"></i>
                    <span>{{ __('super.sidebar.einvoicing_settings') }}</span>
                </button>
                <button type="button" class="settings-nav-item" role="tab" data-tab="features" aria-selected="false" aria-controls="panel-features">
                    <i class="bi bi-toggles"></i>
                    <span>{{ __('super.platform_features.tab') }}</span>
                </button>
            </nav>
        </aside>

        {{-- ── Main content ──────────────────────────────────────────── --}}
        <div class="settings-main">
            <div class="settings-actionbar" id="settingsActionbar">
                <div class="settings-actionbar-meta">
                    <h2 class="settings-actionbar-title" id="settingsActionTitle">{{ __('super.general_settings.tab_general') }}</h2>
                    <p class="settings-actionbar-sub" id="settingsActionSub">{{ __('super.general_settings.tab_general_desc') }}</p>
                </div>
                <div class="settings-actionbar-actions">
                    <button type="submit" class="btn btn-primary" form="generalSettingsForm">
                        <i class="bi bi-check-lg"></i> {{ __('super.general_settings.save_settings') }}
                    </button>
                </div>
            </div>

            <form method="POST" action="{{ route('super.settings.general.update') }}" enctype="multipart/form-data" id="generalSettingsForm">
            @csrf
            @method('PUT')

            {{-- ── Panel: General ─────────────────────────────────── --}}
            <section class="settings-panel is-active" id="panel-general" role="tabpanel" data-panel="general"
                     data-title="{{ __('super.general_settings.tab_general') }}"
                     data-desc="{{ __('super.general_settings.tab_general_desc') }}">
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.app_name') }} <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        name="app_name"
                        class="form-control @error('app_name') is-invalid @enderror"
                        value="{{ old('app_name', $setting->app_name) }}"
                        placeholder="{{ __('super.general_settings.ph_app_name') }}"
                        required
                    >
                    @error('app_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.currency_code') }} <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                name="currency_code"
                                class="form-control text-upper-currency @error('currency_code') is-invalid @enderror"
                                value="{{ old('currency_code', $setting->currency_code ?? 'USD') }}"
                                placeholder="{{ __('super.general_settings.ph_currency_code') }}"
                                maxlength="3"
                                required
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.currency_code_hint') }}</p>
                            @error('currency_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.currency_symbol') }} <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                name="currency_symbol"
                                class="form-control max-w-120 @error('currency_symbol') is-invalid @enderror"
                                value="{{ old('currency_symbol', $setting->currency_symbol ?? '$') }}"
                                placeholder="{{ __('super.general_settings.ph_currency_symbol') }}"
                                maxlength="5"
                                required
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.currency_symbol_hint') }}</p>
                            @error('currency_symbol')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Languages (default, on/off, RTL, translations) live on Super admin > Languages --}}
                <div class="form-group mt-4">
                    <label class="form-label">{{ __('super.languages.moved_title') }}</label>
                    <p class="form-hint mb-2">{{ __('super.languages.moved_text') }}</p>
                    <a href="{{ route('super.languages.index') }}" class="btn btn-outline btn-sm">
                        <i class="bi bi-globe2"></i> {{ __('super.languages.open_languages') }}
                    </a>
                </div>
            </section>

            {{-- ── Panel: Branding ────────────────────────────────── --}}
            <section class="settings-panel" id="panel-branding" role="tabpanel" data-panel="branding"
                     data-title="{{ __('super.general_settings.tab_branding') }}"
                     data-desc="{{ __('super.general_settings.tab_branding_desc') }}">
                {{-- Logo Upload --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.logo') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.logo_hint') }}</p>
                    <div class="upload-area" id="logoUploadArea">
                        <div class="upload-preview" id="logoPreview" style="{{ $setting->getLogoUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getLogoUrl() }}" alt="{{ __('super.general_settings.logo') }}" id="logoPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="logoInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->logo_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeLogoBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="logoPlaceholder" style="{{ $setting->getLogoUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-image upload-icon-lg"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_logo') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.logo_formats') }}</p>
                        </div>
                        <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="d-none">
                    </div>
                    @error('logo')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Favicon Upload --}}
                <div class="form-group">
                    <label class="form-label">{{ __('super.general_settings.favicon') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.favicon_hint') }}</p>
                    <div class="upload-area upload-area-sm" id="faviconUploadArea">
                        <div class="upload-preview upload-preview-sm" id="faviconPreview" style="{{ $setting->getFaviconUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getFaviconUrl() }}" alt="{{ __('super.general_settings.favicon') }}" id="faviconPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="faviconInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->favicon_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeFaviconBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="faviconPlaceholder" style="{{ $setting->getFaviconUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-app upload-icon-md"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_favicon') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.favicon_formats') }}</p>
                        </div>
                        <input type="file" name="favicon" id="faviconInput" accept="image/png,image/x-icon,image/jpeg,image/svg+xml" class="d-none">
                    </div>
                    @error('favicon')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>
            </section>

            {{-- ── Panel: Appearance ──────────────────────────────── --}}
            <section class="settings-panel" id="panel-appearance" role="tabpanel" data-panel="appearance"
                     data-title="{{ __('super.general_settings.tab_appearance') }}"
                     data-desc="{{ __('super.general_settings.tab_appearance_desc') }}">
                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="show_site_name" value="1" class="form-check-input" {{ old('show_site_name', $setting->show_site_name ?? true) ? 'checked' : '' }}>
                        <span><i class="bi bi-type me-1"></i>{{ __('super.general_settings.show_site_name') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.show_site_name_hint') }}</p>
                </div>

                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="show_customizer_button" value="1" class="form-check-input" {{ old('show_customizer_button', $setting->show_customizer_button ?? true) ? 'checked' : '' }}>
                        <span><i class="bi bi-palette-fill me-1"></i>{{ __('super.general_settings.show_customizer') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.show_customizer_hint') }}</p>
                </div>

                @include('central.super.settings.partials.default-color-field', [
                    'name'    => 'super_primary_color',
                    'value'   => $setting->superPrimaryColor(),
                    'builtin' => \App\Models\Central\GeneralSetting::BUILTIN_SUPER_COLOR,
                    'label'   => __('super.general_settings.super_primary_color'),
                    'hint'    => __('super.general_settings.super_primary_color_hint'),
                ])

                <div class="form-group">
                    <label class="form-label" for="dashboard_footer_text">
                        <i class="bi bi-layout-text-window-reverse me-1"></i>{{ __('super.general_settings.dashboard_footer_text') }}
                    </label>
                    <input
                        type="text"
                        id="dashboard_footer_text"
                        name="dashboard_footer_text"
                        class="form-control @error('dashboard_footer_text') is-invalid @enderror"
                        value="{{ old('dashboard_footer_text', $setting->dashboard_footer_text) }}"
                        maxlength="500"
                        placeholder="{{ __('super.general_settings.dashboard_footer_text_placeholder') }}"
                    >
                    @error('dashboard_footer_text')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <p class="form-hint mt-1">{{ __('super.general_settings.dashboard_footer_text_hint') }}</p>
                </div>

                {{-- Landing fonts (applied across all landing layouts) --}}
                @php
                    $fontOptions = \App\Models\Central\GeneralSetting::landingFontOptions();
                    // When a custom font is uploaded, offer it in both dropdowns.
                    if ($setting->landing_custom_font_path && $setting->landing_custom_font_name) {
                        $fontOptions = [$setting->landing_custom_font_name => __('super.general_settings.custom_font_prefix') . ': ' . $setting->landing_custom_font_name] + $fontOptions;
                    }
                @endphp
                <div class="form-row mt-4">
                    <div class="form-group">
                        <label class="form-label" for="landing_font">
                            <i class="bi bi-fonts me-1"></i>{{ __('super.general_settings.landing_font') }}
                        </label>
                        <select id="landing_font" name="landing_font" class="form-select @error('landing_font') is-invalid @enderror">
                            <option value="">{{ __('super.general_settings.landing_font_default') }}</option>
                            @foreach($fontOptions as $value => $label)
                                <option value="{{ $value }}" {{ old('landing_font', $setting->landing_font) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('landing_font')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_font_hint') }}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="landing_heading_font">
                            <i class="bi bi-type-h1 me-1"></i>{{ __('super.general_settings.landing_heading_font') }}
                        </label>
                        <select id="landing_heading_font" name="landing_heading_font" class="form-select @error('landing_heading_font') is-invalid @enderror">
                            <option value="">{{ __('super.general_settings.landing_heading_font_default') }}</option>
                            @foreach($fontOptions as $value => $label)
                                <option value="{{ $value }}" {{ old('landing_heading_font', $setting->landing_heading_font) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('landing_heading_font')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_heading_font_hint') }}</p>
                    </div>
                </div>

                {{-- Upload a custom font --}}
                <div class="form-row mt-3">
                    <div class="form-group">
                        <label class="form-label" for="landing_custom_font_name">
                            <i class="bi bi-cloud-arrow-up me-1"></i>{{ __('super.general_settings.custom_font_name') }}
                        </label>
                        <input
                            type="text"
                            id="landing_custom_font_name"
                            name="landing_custom_font_name"
                            class="form-control @error('landing_custom_font_name') is-invalid @enderror"
                            value="{{ old('landing_custom_font_name', $setting->landing_custom_font_name) }}"
                            maxlength="50"
                            placeholder="{{ __('super.general_settings.custom_font_name_placeholder') }}"
                        >
                        @error('landing_custom_font_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="custom_font_file">{{ __('super.general_settings.custom_font_file') }}</label>
                        <input type="file" id="custom_font_file" name="custom_font_file" class="form-control @error('custom_font_file') is-invalid @enderror" accept=".woff2,.woff,.ttf,.otf">
                        @error('custom_font_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @if($setting->landing_custom_font_path)
                            <p class="form-hint mt-1">
                                {{ __('super.common.current') }}:
                                <a href="{{ asset($setting->landing_custom_font_path) }}" target="_blank">{{ $setting->landing_custom_font_name }}</a>
                            </p>
                            <label class="form-switch-label mt-1">
                                <input type="checkbox" name="remove_custom_font" value="1" class="form-check-input">
                                <span class="text-danger">{{ __('super.general_settings.custom_font_remove') }}</span>
                            </label>
                        @endif
                    </div>
                </div>
                <p class="form-hint mt-1">{{ __('super.general_settings.custom_font_hint') }}</p>
            </section>

            {{-- ── Panel: Dashboard (super-admin dashboard layout) ── --}}
            @include('central.super.settings.partials.dashboard-tab')

            {{-- ── Panel: Company Info ────────────────────────────── --}}
            <section class="settings-panel" id="panel-company" role="tabpanel" data-panel="company"
                     data-title="{{ __('super.general_settings.tab_company') }}"
                     data-desc="{{ __('super.general_settings.tab_company_desc') }}">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.company_name') }}</label>
                            <input
                                type="text"
                                name="company_name"
                                class="form-control @error('company_name') is-invalid @enderror"
                                value="{{ old('company_name', $setting->company_name) }}"
                                placeholder="{{ __('super.general_settings.ph_company_name') }}"
                            >
                            @error('company_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.phone_number') }}</label>
                            <input
                                type="text"
                                name="phone"
                                class="form-control @error('phone') is-invalid @enderror"
                                value="{{ old('phone', $setting->phone) }}"
                                placeholder="{{ __('super.general_settings.ph_phone') }}"
                            >
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.email') }}</label>
                            <input
                                type="email"
                                name="email"
                                class="form-control @error('email') is-invalid @enderror"
                                value="{{ old('email', $setting->email) }}"
                                placeholder="{{ __('super.general_settings.ph_email') }}"
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.website_url') }}</label>
                            <input
                                type="url"
                                name="website"
                                class="form-control @error('website') is-invalid @enderror"
                                value="{{ old('website', $setting->website) }}"
                                placeholder="{{ __('super.general_settings.ph_website') }}"
                            >
                            @error('website')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">{{ __('super.general_settings.address') }}</label>
                    <input
                        type="text"
                        name="address"
                        class="form-control @error('address') is-invalid @enderror"
                        value="{{ old('address', $setting->address) }}"
                        placeholder="{{ __('super.general_settings.ph_address') }}"
                    >
                    @error('address')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </section>

            {{-- ── Panel: Tenant Settings ─────────────────────────── --}}
            <section class="settings-panel" id="panel-tenant" role="tabpanel" data-panel="tenant"
                     data-title="{{ __('super.general_settings.tab_tenant') }}"
                     data-desc="{{ __('super.general_settings.tab_tenant_desc') }}">

                <div class="settings-callout mb-4">
                    <div class="settings-callout-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <div class="settings-callout-body">
                        <h3 class="settings-callout-title">{{ __('super.general_settings.tenant_intro_title') }}</h3>
                        <p class="settings-callout-text">{{ __('super.general_settings.tenant_intro_text') }}</p>
                    </div>
                </div>

                {{-- Tenant Application Name --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.tenant_app_name') }}</label>
                    <input
                        type="text"
                        name="tenant_app_name"
                        class="form-control @error('tenant_app_name') is-invalid @enderror"
                        value="{{ old('tenant_app_name', $setting->tenant_app_name) }}"
                        placeholder="{{ __('super.general_settings.ph_tenant_app_name') }}"
                        maxlength="255"
                    >
                    <p class="form-hint mt-1">{{ __('super.general_settings.tenant_app_name_hint') }}</p>
                    @error('tenant_app_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Tenant Logo --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.tenant_logo') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.tenant_logo_hint') }}</p>
                    <div class="upload-area" id="tenantLogoUploadArea">
                        <div class="upload-preview" id="tenantLogoPreview" style="{{ $setting->getTenantLogoUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getTenantLogoUrl() }}" alt="{{ __('super.general_settings.tenant_logo') }}" id="tenantLogoPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="tenantLogoInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->tenant_logo_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeTenantLogoBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="tenantLogoPlaceholder" style="{{ $setting->getTenantLogoUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-image upload-icon-lg"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_tenant_logo') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.logo_formats') }}</p>
                        </div>
                        <input type="file" name="tenant_logo" id="tenantLogoInput" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="d-none">
                    </div>
                    @error('tenant_logo')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Tenant Favicon --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.tenant_favicon') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.tenant_favicon_hint') }}</p>
                    <div class="upload-area upload-area-sm" id="tenantFaviconUploadArea">
                        <div class="upload-preview upload-preview-sm" id="tenantFaviconPreview" style="{{ $setting->getTenantFaviconUrl() ? '' : 'display:none;' }}">
                            <img src="{{ $setting->getTenantFaviconUrl() }}" alt="{{ __('super.general_settings.tenant_favicon') }}" id="tenantFaviconPreviewImg">
                            <div class="upload-preview-actions">
                                <label for="tenantFaviconInput" class="btn btn-sm btn-outline" title="{{ __('super.general_settings.change') }}">
                                    <i class="bi bi-pencil"></i> {{ __('super.general_settings.change') }}
                                </label>
                                @if($setting->tenant_favicon_path)
                                <button type="button" class="btn btn-sm btn-outline-danger" id="removeTenantFaviconBtn" title="{{ __('super.general_settings.remove') }}">
                                    <i class="bi bi-trash"></i> {{ __('super.general_settings.remove') }}
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="upload-placeholder" id="tenantFaviconPlaceholder" style="{{ $setting->getTenantFaviconUrl() ? 'display:none;' : '' }}">
                            <i class="bi bi-app upload-icon-md"></i>
                            <p class="mb-1 fw-600 fs-sm4">{{ __('super.general_settings.upload_tenant_favicon') }}</p>
                            <p class="text-muted mb-0 fs-xs3">{{ __('super.general_settings.favicon_formats') }}</p>
                        </div>
                        <input type="file" name="tenant_favicon" id="tenantFaviconInput" accept="image/png,image/x-icon,image/jpeg,image/svg+xml" class="d-none">
                    </div>
                    @error('tenant_favicon')
                        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Default tenant colour: read live by every tenant app (not copied
                     into tenants), so it reaches existing workspaces too. --}}
                @include('central.super.settings.partials.default-color-field', [
                    'name'    => 'tenant_primary_color',
                    'value'   => $setting->tenantPrimaryColor(),
                    'builtin' => \App\Models\Central\GeneralSetting::BUILTIN_TENANT_COLOR,
                    'label'   => __('super.general_settings.tenant_primary_color'),
                    'hint'    => __('super.general_settings.tenant_primary_color_hint'),
                ])

                {{-- Apply to existing workspaces (the fields above only brand new ones).
                     Inputs belong to #applyTenantBrandingForm, outside the main form. --}}
                <div class="form-group mb-4">
                    <label class="form-label">{{ __('super.general_settings.apply_branding_title') }}</label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.apply_branding_hint') }}</p>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="logo" form="applyTenantBrandingForm" class="form-check-input"
                                   {{ $setting->tenant_logo_path ? 'checked' : 'disabled' }}>
                            <span>{{ __('super.general_settings.tenant_logo') }}</span>
                            @unless($setting->tenant_logo_path)
                                <span class="text-muted fs-xs3">{{ __('super.general_settings.apply_branding_upload_first') }}</span>
                            @endunless
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="favicon" form="applyTenantBrandingForm" class="form-check-input"
                                   {{ $setting->tenant_favicon_path ? 'checked' : 'disabled' }}>
                            <span>{{ __('super.general_settings.tenant_favicon') }}</span>
                            @unless($setting->tenant_favicon_path)
                                <span class="text-muted fs-xs3">{{ __('super.general_settings.apply_branding_upload_first') }}</span>
                            @endunless
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="app_name" form="applyTenantBrandingForm" class="form-check-input" checked>
                            <span>{{ __('super.general_settings.apply_branding_app_name', ['name' => $setting->getTenantDefaults()['app_name']]) }}</span>
                        </label>
                        @php $brandingDefaults = $setting->getTenantDefaults(); @endphp
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="footer" form="applyTenantBrandingForm" class="form-check-input">
                            <span>{{ __('super.general_settings.apply_branding_footer', ['value' => $brandingDefaults['footer_text']]) }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="developed_by" form="applyTenantBrandingForm" class="form-check-input">
                            <span>{{ __('super.general_settings.apply_branding_developed_by', ['value' => $brandingDefaults['developed_by']]) }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="page_title_suffix" form="applyTenantBrandingForm" class="form-check-input">
                            <span>{{ __('super.general_settings.apply_branding_page_title_suffix', ['value' => $brandingDefaults['page_title_suffix']]) }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="parts[]" value="company_name" form="applyTenantBrandingForm" class="form-check-input">
                            <span>{{ __('super.general_settings.apply_branding_company_name', ['value' => $brandingDefaults['company_name']]) }}</span>
                        </label>
                    </div>
                    @error('parts')
                        <div class="text-danger mb-2 fs-sm2">{{ $message }}</div>
                    @enderror
                    <button type="button" class="btn btn-outline-primary btn-sm" id="applyTenantBrandingBtn">
                        <i class="bi bi-arrow-repeat me-1"></i>{{ __('super.general_settings.apply_branding_button') }}
                    </button>
                </div>

                <hr class="settings-divider">

                {{-- Company info --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_company_name') }}</label>
                            <input
                                type="text"
                                name="tenant_company_name"
                                class="form-control @error('tenant_company_name') is-invalid @enderror"
                                value="{{ old('tenant_company_name', $setting->tenant_company_name) }}"
                                placeholder="{{ __('super.general_settings.ph_company_name') }}"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_company_name_hint') }}</p>
                            @error('tenant_company_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_email') }}</label>
                            <input
                                type="email"
                                name="tenant_email"
                                class="form-control @error('tenant_email') is-invalid @enderror"
                                value="{{ old('tenant_email', $setting->tenant_email) }}"
                                placeholder="{{ __('super.general_settings.ph_admin_email') }}"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_email_hint') }}</p>
                            @error('tenant_email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_phone') }}</label>
                            <input
                                type="text"
                                name="tenant_phone"
                                class="form-control @error('tenant_phone') is-invalid @enderror"
                                value="{{ old('tenant_phone', $setting->tenant_phone) }}"
                                placeholder="{{ __('super.general_settings.ph_phone') }}"
                            >
                            @error('tenant_phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_address') }}</label>
                            <input
                                type="text"
                                name="tenant_address"
                                class="form-control @error('tenant_address') is-invalid @enderror"
                                value="{{ old('tenant_address', $setting->tenant_address) }}"
                                placeholder="{{ __('super.general_settings.ph_address_short') }}"
                            >
                            @error('tenant_address')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                {{-- Currency & language --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_currency_code') }}</label>
                            <input
                                type="text"
                                name="tenant_currency_code"
                                class="form-control text-upper-currency @error('tenant_currency_code') is-invalid @enderror"
                                value="{{ old('tenant_currency_code', $setting->tenant_currency_code) }}"
                                placeholder="{{ __('super.general_settings.ph_currency_code_1') }}"
                                maxlength="3"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_currency_code_hint') }}</p>
                            @error('tenant_currency_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_currency_symbol') }}</label>
                            <input
                                type="text"
                                name="tenant_currency_symbol"
                                class="form-control @error('tenant_currency_symbol') is-invalid @enderror"
                                value="{{ old('tenant_currency_symbol', $setting->tenant_currency_symbol) }}"
                                placeholder="{{ __('super.general_settings.ph_currency_sym_1') }}"
                                maxlength="5"
                            >
                            @error('tenant_currency_symbol')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_default_language') }}</label>
                            <select
                                name="tenant_default_language"
                                class="form-select @error('tenant_default_language') is-invalid @enderror"
                            >
                                <option value="">—</option>
                                @foreach($languages as $language)
                                    <option
                                        value="{{ $language->locale }}"
                                        {{ old('tenant_default_language', $setting->tenant_default_language) === $language->locale ? 'selected' : '' }}
                                    >
                                        {{ $language->name }} ({{ strtoupper($language->locale) }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_default_language_hint') }}</p>
                            @error('tenant_default_language')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Languages enabled in new tenants (null = all) --}}
                @php
                    $tenantEnabledLanguages = old(
                        'tenant_enabled_languages',
                        is_array($setting->tenant_enabled_languages) ? $setting->tenant_enabled_languages : \App\Support\TenantLanguages::locales()
                    );
                @endphp
                <div class="form-group mt-3" data-tenant-languages>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                        <label class="form-label mb-0">{{ __('super.general_settings.tenant_enabled_languages') }}</label>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-light" data-tenant-languages-toggle="1">{{ __('super.general_settings.tenant_enabled_languages_all') }}</button>
                            <button type="button" class="btn btn-sm btn-light" data-tenant-languages-toggle="0">{{ __('super.general_settings.tenant_enabled_languages_none') }}</button>
                        </div>
                    </div>
                    <p class="form-hint mb-2">{{ __('super.general_settings.tenant_enabled_languages_hint') }}</p>
                    <div class="row g-2">
                        @foreach(\App\Support\TenantLanguages::ALL as $tenantLanguage)
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-check d-flex align-items-center gap-2 mb-0">
                                    <input type="checkbox" class="form-check-input mt-0" name="tenant_enabled_languages[]"
                                           value="{{ $tenantLanguage['locale'] }}"
                                           {{ in_array($tenantLanguage['locale'], (array) $tenantEnabledLanguages, true) ? 'checked' : '' }}>
                                    <span>{{ $tenantLanguage['name'] }} <span class="text-muted">({{ $tenantLanguage['locale'] }})</span></span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('tenant_enabled_languages')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    @error('tenant_enabled_languages.*')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- Footer / suffix / developed by --}}
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_footer_text') }}</label>
                            <input
                                type="text"
                                name="tenant_footer_text"
                                class="form-control @error('tenant_footer_text') is-invalid @enderror"
                                value="{{ old('tenant_footer_text', $setting->tenant_footer_text) }}"
                                placeholder="{{ __('super.general_settings.ph_meta_title') }}"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_footer_text_hint') }}</p>
                            @error('tenant_footer_text')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_page_title_suffix') }}</label>
                            <input
                                type="text"
                                name="tenant_page_title_suffix"
                                class="form-control @error('tenant_page_title_suffix') is-invalid @enderror"
                                value="{{ old('tenant_page_title_suffix', $setting->tenant_page_title_suffix) }}"
                                placeholder="{{ __('super.general_settings.ph_meta_desc') }}"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_page_title_suffix_hint') }}</p>
                            @error('tenant_page_title_suffix')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.tenant_developed_by') }}</label>
                            <input
                                type="text"
                                name="tenant_developed_by"
                                class="form-control @error('tenant_developed_by') is-invalid @enderror"
                                value="{{ old('tenant_developed_by', $setting->tenant_developed_by) }}"
                                placeholder="{{ __('super.general_settings.ph_company_name') }}"
                            >
                            <p class="form-hint mt-1">{{ __('super.general_settings.tenant_developed_by_hint') }}</p>
                            @error('tenant_developed_by')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                {{-- Reserved Subdomains --}}
                <div class="form-group">
                    <label class="form-label" for="reserved_subdomains">
                        <i class="bi bi-shield-lock me-1"></i>{{ __('super.general_settings.reserved_subdomains') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.reserved_subdomains_hint') }}</p>
                    @php
                        $reservedValue = old(
                            'reserved_subdomains',
                            implode("\n", $setting->reserved_subdomains ?? [])
                        );
                    @endphp
                    <textarea
                        id="reserved_subdomains"
                        name="reserved_subdomains"
                        rows="5"
                        class="form-control @error('reserved_subdomains') is-invalid @enderror"
                        placeholder="{{ __('super.general_settings.ph_reserved_subs') }}"
                        maxlength="5000"
                    >{{ $reservedValue }}</textarea>
                    <p class="form-hint mt-1">
                        <i class="bi bi-info-circle me-1"></i>{{ __('super.general_settings.reserved_subdomains_system_note') }}
                    </p>
                    @error('reserved_subdomains')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- Demo Tenant Generator --}}
                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="demo_data_enabled" value="1" class="form-check-input"
                               {{ old('demo_data_enabled', $setting->demo_data_enabled ?? false) ? 'checked' : '' }}>
                        <span><i class="bi bi-box-seam me-1"></i>{{ __('super.general_settings.demo_data_enabled') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.demo_data_enabled_hint') }}</p>
                </div>
            </section>

            {{-- ── Panel: Subscription Reminders ──────────────────── --}}
            <section class="settings-panel" id="panel-reminders" role="tabpanel" data-panel="reminders"
                     data-title="{{ __('super.general_settings.tab_reminders') }}"
                     data-desc="{{ __('super.general_settings.tab_reminders_desc') }}">

                {{-- Master toggle: expiry reminders --}}
                <div class="form-group mb-4">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="subscription_reminders_enabled" value="1" class="form-check-input"
                               {{ old('subscription_reminders_enabled', $setting->remindersEnabled()) ? 'checked' : '' }}>
                        <span><i class="bi bi-bell me-1"></i>{{ __('super.general_settings.reminders_enabled') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.reminders_enabled_hint') }}</p>
                </div>

                {{-- Expiry reminder offsets --}}
                <div class="form-group mb-4">
                    <label class="form-label" for="subscription_reminder_offsets">
                        <i class="bi bi-calendar-event me-1"></i>{{ __('super.general_settings.reminder_offsets') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.reminder_offsets_hint') }}</p>
                    <input type="text" id="subscription_reminder_offsets" name="subscription_reminder_offsets"
                           class="form-control @error('subscription_reminder_offsets') is-invalid @enderror"
                           value="{{ old('subscription_reminder_offsets', implode(', ', $setting->reminderOffsets())) }}"
                           placeholder="7, 3, 1" maxlength="255">
                    @error('subscription_reminder_offsets')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Delivery channels --}}
                <div class="form-group mb-4">
                    <label class="form-label">
                        <i class="bi bi-broadcast me-1"></i>{{ __('super.general_settings.reminder_channels') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.reminder_channels_hint') }}</p>
                    @php $activeChannels = old('subscription_reminder_channels', $setting->reminderChannels()); @endphp
                    <div class="d-flex flex-wrap gap-3">
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="subscription_reminder_channels[]" value="email" class="form-check-input"
                                   {{ in_array('email', $activeChannels) ? 'checked' : '' }}>
                            <span><i class="bi bi-envelope me-1"></i>{{ __('super.general_settings.channel_email') }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="subscription_reminder_channels[]" value="sms" class="form-check-input"
                                   {{ in_array('sms', $activeChannels) ? 'checked' : '' }}>
                            <span><i class="bi bi-chat-dots me-1"></i>{{ __('super.general_settings.channel_sms') }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="subscription_reminder_channels[]" value="banner" class="form-check-input"
                                   {{ in_array('banner', $activeChannels) ? 'checked' : '' }}>
                            <span><i class="bi bi-window me-1"></i>{{ __('super.general_settings.channel_banner') }}</span>
                        </label>
                    </div>
                </div>

                {{-- In-app banner threshold --}}
                <div class="form-group mb-4">
                    <label class="form-label" for="subscription_banner_threshold_days">
                        <i class="bi bi-window-dock me-1"></i>{{ __('super.general_settings.banner_threshold') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.banner_threshold_hint') }}</p>
                    <input type="number" id="subscription_banner_threshold_days" name="subscription_banner_threshold_days"
                           class="form-control w-auto @error('subscription_banner_threshold_days') is-invalid @enderror"
                           value="{{ old('subscription_banner_threshold_days', $setting->bannerThresholdDays()) }}"
                           min="1" max="90">
                    @error('subscription_banner_threshold_days')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                {{-- SMS configuration (only relevant when the SMS channel is on) --}}
                <div class="form-group mb-4">
                    <label class="form-label" for="sms_gateway">
                        <i class="bi bi-broadcast-pin me-1"></i>{{ __('super.general_settings.sms_gateway') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.sms_gateway_hint') }}</p>
                    <select id="sms_gateway" name="sms_gateway" class="form-control filter-select w-auto @error('sms_gateway') is-invalid @enderror">
                        <option value="">{{ __('super.general_settings.sms_gateway_none') }}</option>
                        @foreach(\App\Models\Central\GeneralSetting::SMS_GATEWAYS as $gw)
                            <option value="{{ $gw }}" {{ old('sms_gateway', $setting->sms_gateway) === $gw ? 'selected' : '' }}>{{ ucfirst($gw) }}</option>
                        @endforeach
                    </select>
                    @error('sms_gateway')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="subscription_reminder_sms">
                        <i class="bi bi-chat-text me-1"></i>{{ __('super.general_settings.reminder_sms') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.sms_placeholders_hint') }}</p>
                    <textarea id="subscription_reminder_sms" name="subscription_reminder_sms" rows="3"
                              class="form-control @error('subscription_reminder_sms') is-invalid @enderror"
                              maxlength="480" placeholder="{{ \App\Models\Central\GeneralSetting::DEFAULT_REMINDER_SMS }}">{{ old('subscription_reminder_sms', $setting->subscription_reminder_sms) }}</textarea>
                    @error('subscription_reminder_sms')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="trial_reminder_sms">
                        <i class="bi bi-chat-text me-1"></i>{{ __('super.general_settings.trial_sms') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.sms_placeholders_hint') }}</p>
                    <textarea id="trial_reminder_sms" name="trial_reminder_sms" rows="3"
                              class="form-control @error('trial_reminder_sms') is-invalid @enderror"
                              maxlength="480" placeholder="{{ \App\Models\Central\GeneralSetting::DEFAULT_TRIAL_SMS }}">{{ old('trial_reminder_sms', $setting->trial_reminder_sms) }}</textarea>
                    @error('trial_reminder_sms')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- Trial reminders --}}
                <div class="form-group mb-4">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="trial_reminders_enabled" value="1" class="form-check-input"
                               {{ old('trial_reminders_enabled', $setting->trialRemindersEnabled()) ? 'checked' : '' }}>
                        <span><i class="bi bi-hourglass-split me-1"></i>{{ __('super.general_settings.trial_reminders_enabled') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.trial_reminders_enabled_hint') }}</p>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="trial_reminder_offsets">
                        <i class="bi bi-calendar-event me-1"></i>{{ __('super.general_settings.trial_reminder_offsets') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.trial_reminder_offsets_hint') }}</p>
                    <input type="text" id="trial_reminder_offsets" name="trial_reminder_offsets"
                           class="form-control @error('trial_reminder_offsets') is-invalid @enderror"
                           value="{{ old('trial_reminder_offsets', implode(', ', $setting->trialReminderOffsets())) }}"
                           placeholder="3, 1" maxlength="255">
                    @error('trial_reminder_offsets')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="settings-divider">

                {{-- After expiry: grace period, auto-suspend, auto-delete --}}
                <h3 class="fs-md2 fw-700 mb-1"><i class="bi bi-shield-exclamation me-1"></i>{{ __('central.AfterExpiryTitle') }}</h3>
                <p class="form-hint mb-3">{{ __('central.AfterExpiryHint') }}</p>

                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label" for="subscription_grace_days">{{ __('central.GraceDays') }}</label>
                        <input type="number" id="subscription_grace_days" name="subscription_grace_days"
                               class="form-control w-auto @error('subscription_grace_days') is-invalid @enderror"
                               value="{{ old('subscription_grace_days', $setting->subscriptionGraceDays()) }}" min="0" max="60">
                        <p class="form-hint mt-1">{{ __('central.GraceDaysHint') }}</p>
                        @error('subscription_grace_days')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="subscription_grace_mode">{{ __('central.GraceMode') }}</label>
                        @php $graceMode = old('subscription_grace_mode', $setting->subscriptionGraceMode()); @endphp
                        <select id="subscription_grace_mode" name="subscription_grace_mode" class="form-control filter-select w-auto">
                            <option value="full" {{ $graceMode === 'full' ? 'selected' : '' }}>{{ __('central.GraceModeFull') }}</option>
                            <option value="read_only" {{ $graceMode === 'read_only' ? 'selected' : '' }}>{{ __('central.GraceModeReadOnly') }}</option>
                        </select>
                        <p class="form-hint mt-1">{{ __('central.GraceModeHint') }}</p>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="suspend_unpaid_after_days">{{ __('central.SuspendUnpaidAfter') }}</label>
                        <input type="number" id="suspend_unpaid_after_days" name="suspend_unpaid_after_days"
                               class="form-control w-auto @error('suspend_unpaid_after_days') is-invalid @enderror"
                               value="{{ old('suspend_unpaid_after_days', (int) ($setting->suspend_unpaid_after_days ?? 7)) }}" min="0" max="365">
                        <p class="form-hint mt-1">{{ __('central.SuspendUnpaidAfterHint') }}</p>
                        @error('suspend_unpaid_after_days')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="delete_inactive_after_days">{{ __('central.DeleteInactiveAfter') }}</label>
                        <input type="number" id="delete_inactive_after_days" name="delete_inactive_after_days"
                               class="form-control w-auto @error('delete_inactive_after_days') is-invalid @enderror"
                               value="{{ old('delete_inactive_after_days', $setting->deleteInactiveAfterDays()) }}" min="0" max="3650">
                        <p class="form-hint mt-1">{{ __('central.DeleteInactiveAfterHint') }}</p>
                        @error('delete_inactive_after_days')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <p class="form-hint">
                    <i class="bi bi-info-circle me-1"></i>{!! __('super.general_settings.reminder_log_note', ['url' => route('super.subscriptions.reminders')]) !!}
                </p>
            </section>

            {{-- ── Panel: Landing ─────────────────────────────────── --}}
            <section class="settings-panel" id="panel-landing" role="tabpanel" data-panel="landing"
                     data-title="{{ __('super.general_settings.tab_landing') }}"
                     data-desc="{{ __('super.general_settings.tab_landing_desc') }}">
                {{-- Landing page mode: built-in / external redirect / disabled --}}
                @php
                    $landingMode = old('landing_mode', $setting->landingMode());
                    $landingModes = [
                        'builtin'  => ['bi-window', 'text-primary'],
                        'redirect' => ['bi-box-arrow-up-right', 'text-warning'],
                        'disabled' => ['bi-slash-circle', 'text-muted'],
                    ];
                @endphp
                <div class="form-group mb-4">
                    <label class="form-label d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-window text-muted"></i>{{ __('super.general_settings.landing_mode') }}
                    </label>
                    <p class="form-hint mb-3">{{ __('super.general_settings.landing_mode_hint') }}</p>
                    <div class="hosting-mode-options">
                        @foreach ($landingModes as $mode => [$icon, $tone])
                        <label class="hosting-mode-option {{ $landingMode === $mode ? 'active' : '' }}">
                            <input type="radio" name="landing_mode" value="{{ $mode }}" {{ $landingMode === $mode ? 'checked' : '' }}>
                            <div class="hosting-mode-content">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi {{ $icon }} {{ $tone }} hosting-mode-icon"></i>
                                    <span class="fw-700 fs-md2">{{ __('super.general_settings.landing_mode_' . $mode) }}</span>
                                </div>
                                <p class="text-muted mb-0 fs-sm">{{ __('super.general_settings.landing_mode_' . $mode . '_desc') }}</p>
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>

                {{-- Redirect: external URL + status code --}}
                <div data-landing-mode="redirect">
                    <div class="form-group">
                        <label class="form-label" for="landing_redirect_url">
                            <i class="bi bi-box-arrow-up-right me-1"></i>{{ __('super.general_settings.landing_redirect_url') }}
                        </label>
                        <input
                            type="url"
                            id="landing_redirect_url"
                            name="landing_redirect_url"
                            class="form-control @error('landing_redirect_url') is-invalid @enderror"
                            value="{{ old('landing_redirect_url', $setting->landing_redirect_url) }}"
                            maxlength="500"
                            pattern="https://.+"
                            placeholder="https://www.example.com"
                        >
                        @error('landing_redirect_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_redirect_url_hint') }}</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="landing_redirect_status">
                            <i class="bi bi-signpost-split me-1"></i>{{ __('super.general_settings.landing_redirect_status') }}
                        </label>
                        @php $redirectStatus = (int) old('landing_redirect_status', $setting->landingRedirectStatus()); @endphp
                        <select id="landing_redirect_status" name="landing_redirect_status" class="form-select @error('landing_redirect_status') is-invalid @enderror">
                            <option value="302" {{ $redirectStatus === 302 ? 'selected' : '' }}>{{ __('super.general_settings.landing_redirect_302') }}</option>
                            <option value="301" {{ $redirectStatus === 301 ? 'selected' : '' }}>{{ __('super.general_settings.landing_redirect_301') }}</option>
                        </select>
                        @error('landing_redirect_status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_redirect_status_hint') }}</p>
                    </div>
                </div>

                {{-- Disabled: what the main domain shows instead --}}
                <div data-landing-mode="disabled">
                    <div class="form-group">
                        <label class="form-label" for="landing_disabled_target">
                            <i class="bi bi-door-open me-1"></i>{{ __('super.general_settings.landing_disabled_target') }}
                        </label>
                        @php $disabledTarget = old('landing_disabled_target', $setting->landingDisabledTarget()); @endphp
                        <select id="landing_disabled_target" name="landing_disabled_target" class="form-select @error('landing_disabled_target') is-invalid @enderror">
                            <option value="login" {{ $disabledTarget === 'login' ? 'selected' : '' }}>{{ __('super.general_settings.landing_disabled_login') }}</option>
                            <option value="signup" {{ $disabledTarget === 'signup' ? 'selected' : '' }}>{{ __('super.general_settings.landing_disabled_signup') }}</option>
                        </select>
                        @error('landing_disabled_target')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <p class="form-hint mt-1">{{ __('super.general_settings.landing_disabled_target_hint') }}</p>
                    </div>
                </div>

                {{-- Links the owner's own website needs (the built-in pricing section is gone in these modes) --}}
                <div data-landing-mode="redirect disabled">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="bi bi-link-45deg me-1"></i>{{ __('super.general_settings.landing_links') }}
                        </label>
                        <p class="form-hint mb-2">{{ __('super.general_settings.landing_links_hint') }}</p>
                        @php
                            $landingLinks = [
                                [__('super.general_settings.landing_link_signin'), route('central.workspace.login')],
                                [__('super.general_settings.landing_link_signup'), route('central.register')],
                            ];
                            foreach ($publicPlans as $plan) {
                                $landingLinks[] = [__('super.general_settings.landing_link_plan', ['plan' => $plan->name]), route('central.register', ['plan' => $plan->id])];
                            }
                        @endphp
                        {{-- Multi-currency pricing: links that open the register page in one currency. --}}
                        @php $linkCurrencies = \App\Support\SellingCurrencies::active(); @endphp
                        @if(count($linkCurrencies) > 1)
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <label class="form-label form-label-sm mb-0" for="signupLinkCurrency">{{ __('super.currencies.link_currency') }}</label>
                            <select id="signupLinkCurrency" class="form-select form-select-sm" style="max-width: 180px">
                                <option value="">{{ __('super.currencies.link_currency_any') }}</option>
                                @foreach($linkCurrencies as $lc)
                                    <option value="{{ $lc }}">{{ $lc }} — {{ \App\Support\SellingCurrencies::symbol($lc) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        @foreach ($landingLinks as $linkIndex => [$linkLabel, $linkUrl])
                        <div class="input-group mb-2">
                            <span class="input-group-text">{{ $linkLabel }}</span>
                            <input type="text" class="form-control" value="{{ $linkUrl }}" readonly aria-label="{{ $linkLabel }}"
                                   @if($linkIndex > 0) data-signup-link="{{ $linkUrl }}" @endif>
                            <button type="button" class="btn btn-outline js-copy-link" data-url="{{ $linkUrl }}" @if($linkIndex > 0) data-signup-link="{{ $linkUrl }}" @endif title="{{ __('super.general_settings.landing_link_copy') }}"><i class="bi bi-clipboard"></i></button>
                        </div>
                        @endforeach
                    </div>
                </div>

                <hr class="settings-divider">

                <div class="form-group">
                    <label class="form-switch-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="show_admin_login" value="1" class="form-check-input" {{ old('show_admin_login', $footer->show_admin_login ?? false) ? 'checked' : '' }}>
                        <span><i class="bi bi-person-lock me-1"></i>{{ __('super.general_settings.show_admin_login') }}</span>
                    </label>
                    <p class="form-hint mt-1">{{ __('super.general_settings.show_admin_login_hint') }}</p>
                </div>
            </section>

            {{-- ── Panel: Payments / Bank ─────────────────────────── --}}
            <section class="settings-panel" id="panel-payments" role="tabpanel" data-panel="payments"
                     data-title="{{ __('super.general_settings.tab_payments') }}"
                     data-desc="{{ __('super.general_settings.tab_payments_desc') }}">
                @php $bank = $setting->getBankDetails(); @endphp

                {{-- Billing cycles offered to customers --}}
                <div class="form-group mb-4">
                    <label class="form-label">
                        <i class="bi bi-calendar-range me-1"></i>{{ __('super.general_settings.billing_cycles') }}
                    </label>
                    <p class="form-hint mb-2">{{ __('super.general_settings.billing_cycles_hint') }}</p>
                    @php $activeCycles = old('billing_cycles', \App\Models\Central\GeneralSetting::enabledBillingCycles()); @endphp
                    <div class="d-flex flex-wrap gap-3">
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="billing_cycles[]" value="monthly" class="form-check-input"
                                   {{ in_array('monthly', $activeCycles) ? 'checked' : '' }}>
                            <span><i class="bi bi-calendar-month me-1"></i>{{ __('super.general_settings.billing_cycle_monthly') }}</span>
                        </label>
                        <label class="form-switch-label d-flex align-items-center gap-2">
                            <input type="checkbox" name="billing_cycles[]" value="yearly" class="form-check-input"
                                   {{ in_array('yearly', $activeCycles) ? 'checked' : '' }}>
                            <span><i class="bi bi-calendar3 me-1"></i>{{ __('super.general_settings.billing_cycle_yearly') }}</span>
                        </label>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.bank_name') }}</label>
                            <input type="text" name="bank_details[bank_name]" class="form-control"
                                   value="{{ old('bank_details.bank_name', $bank['bank_name'] ?? '') }}"
                                   placeholder="{{ __('super.general_settings.ph_bank_name') }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.account_holder') }}</label>
                            <input type="text" name="bank_details[account_holder]" class="form-control"
                                   value="{{ old('bank_details.account_holder', $bank['account_holder'] ?? '') }}"
                                   placeholder="{{ __('super.general_settings.ph_company_name') }}">
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.account_number') }}</label>
                            <input type="text" name="bank_details[account_number]" class="form-control"
                                   value="{{ old('bank_details.account_number', $bank['account_number'] ?? '') }}"
                                   placeholder="{{ __('super.general_settings.ph_account_number') }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.iban') }}</label>
                            <input type="text" name="bank_details[iban]" class="form-control"
                                   value="{{ old('bank_details.iban', $bank['iban'] ?? '') }}"
                                   placeholder="{{ __('super.general_settings.ph_iban') }}">
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.swift_code') }}</label>
                            <input type="text" name="bank_details[swift]" class="form-control"
                                   value="{{ old('bank_details.swift', $bank['swift'] ?? '') }}"
                                   placeholder="{{ __('super.general_settings.ph_swift') }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.general_settings.branch') }}</label>
                            <input type="text" name="bank_details[branch]" class="form-control"
                                   value="{{ old('bank_details.branch', $bank['branch'] ?? '') }}"
                                   placeholder="{{ __('super.general_settings.ph_branch') }}">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">{{ __('super.general_settings.payment_instructions') }}</label>
                    <textarea name="bank_details[instructions]" class="form-control" rows="3"
                              placeholder="{{ __('super.general_settings.ph_bank_instructions') }}">{{ old('bank_details.instructions', $bank['instructions'] ?? '') }}</textarea>
                    <p class="form-hint mt-1">{{ __('super.general_settings.payment_instructions_hint') }}</p>
                </div>
            </section>

            {{-- ── Panel: Backup ──────────────────────────────────── --}}
            <section class="settings-panel" id="panel-backup" role="tabpanel" data-panel="backup"
                     data-title="{{ __('super.general_settings.tab_backup') }}"
                     data-desc="{{ __('super.general_settings.tab_backup_desc') }}">
                <div class="backup-head d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <h3 class="fs-md2 fw-700 mb-1"><i class="bi bi-database-down me-1"></i>{{ __('super.general_settings.backup_title') }}</h3>
                        <p class="form-hint mb-0">{{ __('super.general_settings.backup_hint') }}</p>
                    </div>
                    <button type="button" class="btn btn-primary" id="createBackupBtn">
                        <i class="bi bi-plus-lg me-1"></i>
                        <span class="btn-label">{{ __('super.general_settings.backup_create_now') }}</span>
                    </button>
                </div>

                <div class="backup-security-note">
                    <i class="bi bi-shield-check"></i>
                    <span>{{ __('super.general_settings.backup_security_note') }}</span>
                </div>

                <div class="backup-table-wrap" id="backupTableWrap">
                    <table class="backup-table" aria-live="polite">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('super.general_settings.backup_file') }}</th>
                                <th scope="col">{{ __('super.general_settings.backup_size') }}</th>
                                <th scope="col">{{ __('super.general_settings.backup_date') }}</th>
                                <th scope="col" class="text-end">{{ __('super.general_settings.backup_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody id="backupTableBody">
                            @forelse($backups as $b)
                                <tr data-name="{{ $b['name'] }}">
                                    <td><code class="backup-file-name">{{ $b['name'] }}</code></td>
                                    <td>{{ $b['size'] }}</td>
                                    <td>{{ $b['date'] }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('super.settings.general.backup.download', ['filename' => $b['name']]) }}"
                                           class="btn btn-sm btn-outline"
                                           title="{{ __('super.general_settings.backup_download') }}">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-delete-backup"
                                                data-name="{{ $b['name'] }}"
                                                title="{{ __('super.general_settings.backup_delete') }}">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr class="backup-empty-row">
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox d-block fs-xl mb-2"></i>
                                        {{ __('super.general_settings.backup_empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- ── Panel: System ──────────────────────────────────── --}}
            <section class="settings-panel" id="panel-system" role="tabpanel" data-panel="system"
                     data-title="{{ __('super.general_settings.tab_system') }}"
                     data-desc="{{ __('super.general_settings.tab_system_desc') }}">
                <div class="form-group mb-4">
                    <label class="form-label d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-hdd-rack text-muted"></i>{{ __('super.general_settings.hosting_mode') }}
                    </label>
                    <p class="form-hint mb-3">{{ __('super.general_settings.hosting_mode_hint') }}</p>
                    <div class="hosting-mode-options">
                        <label class="hosting-mode-option {{ ($setting->hosting_mode ?? 'vps') === 'vps' ? 'active' : '' }}">
                            <input type="radio" name="hosting_mode" value="vps" {{ ($setting->hosting_mode ?? 'vps') === 'vps' ? 'checked' : '' }}>
                            <div class="hosting-mode-content">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi bi-hdd-stack text-primary hosting-mode-icon"></i>
                                    <span class="fw-700 fs-md2">{{ __('super.general_settings.vps') }}</span>
                                </div>
                                <p class="text-muted mb-0 fs-sm">
                                    {{ __('super.general_settings.vps_desc') }}
                                </p>
                            </div>
                        </label>
                        <label class="hosting-mode-option {{ ($setting->hosting_mode ?? 'vps') === 'shared' ? 'active' : '' }}">
                            <input type="radio" name="hosting_mode" value="shared" {{ ($setting->hosting_mode ?? 'vps') === 'shared' ? 'checked' : '' }}>
                            <div class="hosting-mode-content">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi bi-cloud text-warning hosting-mode-icon"></i>
                                    <span class="fw-700 fs-md2">{{ __('super.general_settings.shared_hosting') }}</span>
                                </div>
                                <p class="text-muted mb-0 fs-sm">
                                    {{ __('super.general_settings.shared_hosting_desc') }}
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <hr class="settings-divider">

                <div class="form-group mb-4">
                    <label class="form-label d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-terminal text-muted"></i>{{ __('super.general_settings.env_section') }}
                    </label>
                    <p class="form-hint mb-3">
                        {!! __('super.general_settings.env_warning', ['file' => '<code>.env</code>']) !!}
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.general_settings.env_select') }}</label>
                                <select
                                    name="app_env"
                                    class="form-control form-select @error('app_env') is-invalid @enderror"
                                >
                                    @php $currentEnv = old('app_env', $env['APP_ENV'] ?? 'production'); @endphp
                                    <option value="local"      {{ $currentEnv === 'local' ? 'selected' : '' }}>local</option>
                                    <option value="staging"    {{ $currentEnv === 'staging' ? 'selected' : '' }}>staging</option>
                                    <option value="production" {{ $currentEnv === 'production' ? 'selected' : '' }}>production</option>
                                </select>
                                <p class="form-hint mt-1">{!! __('super.general_settings.env_hint', ['mode' => '<strong>' . __('super.general_settings.env_production') . '</strong>']) !!}</p>
                                @error('app_env')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.general_settings.debug_mode') }}</label>
                                <select
                                    name="app_debug"
                                    class="form-control form-select @error('app_debug') is-invalid @enderror"
                                >
                                    @php $currentDebug = old('app_debug', $env['APP_DEBUG'] ?? 'false'); @endphp
                                    <option value="false" {{ $currentDebug === 'false' ? 'selected' : '' }}>{{ __('super.general_settings.debug_off') }}</option>
                                    <option value="true"  {{ $currentDebug === 'true'  ? 'selected' : '' }}>{{ __('super.general_settings.debug_on') }}</option>
                                </select>
                                <p class="form-hint mt-1">{!! __('super.general_settings.debug_hint', ['mode' => '<strong>' . __('super.general_settings.debug_off_word') . '</strong>']) !!}</p>
                                @error('app_debug')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.general_settings.app_url') }}</label>
                                <input
                                    type="url"
                                    name="app_url"
                                    class="form-control @error('app_url') is-invalid @enderror"
                                    value="{{ old('app_url', $env['APP_URL'] ?? '') }}"
                                    placeholder="{{ __('super.general_settings.ph_example_url') }}"
                                >
                                <p class="form-hint mt-1">{{ __('super.general_settings.app_url_hint') }}</p>
                                @error('app_url')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="settings-divider">

                <div class="settings-callout">
                    <div class="settings-callout-icon">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>
                    <div class="settings-callout-body">
                        <h3 class="settings-callout-title">{{ __('super.general_settings.clear_cache_title') }}</h3>
                        <p class="settings-callout-text">{{ __('super.general_settings.clear_cache_desc') }}</p>
                    </div>
                    <div class="settings-callout-action">
                        <button type="button" class="btn btn-outline-warning" id="clearCacheBtn">
                            <i class="bi bi-trash3 me-1"></i> {{ __('super.general_settings.clear_central_cache') }}
                        </button>
                    </div>
                </div>
            </section>

            {{-- ── Panel: Update App ──────────────────────────────── --}}
            <section class="settings-panel" id="panel-update" role="tabpanel" data-panel="update"
                     data-title="{{ __('super.general_settings.tab_update') }}"
                     data-desc="{{ __('super.general_settings.tab_update_desc') }}">
                <div class="update-intro">
                    <div class="update-intro-icon">
                        <i class="bi bi-cloud-arrow-down"></i>
                    </div>
                    <div>
                        <h3 class="update-intro-title">{{ __('super.general_settings.update_intro_title') }}</h3>
                        <p class="update-intro-text mb-0">{{ __('super.general_settings.update_intro_text') }}</p>
                    </div>
                </div>

                <div class="update-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>{{ __('super.general_settings.update_warning_manual_changes') }}</span>
                </div>

                <ol class="update-steps">
                    <li class="update-step">
                        <div class="update-step-num">1</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step1_title') }}</h4>
                            <ul class="update-step-list">
                                <li>{!! __('super.general_settings.update_step1_item1', ['path' => '<code>/super/settings/general#tab=backup</code>']) !!}</li>
                                <li>{{ __('super.general_settings.update_step1_item2') }}</li>
                                <li>{{ __('super.general_settings.update_step1_item4') }}</li>
                            </ul>
                            <div class="update-step-or">{{ __('super.general_settings.update_or') }}</div>
                            <ul class="update-step-list">
                                <li>{{ __('super.general_settings.update_step1_item5') }}</li>
                            </ul>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">2</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step2_title') }}</h4>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">3</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step3_title') }}</h4>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">4</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step4_title') }}</h4>
                            <ul class="update-step-list update-step-list--files">
                                <li><code>.env</code> {{ __('super.general_settings.update_file_label') }}</li>
                                <li><code>storage</code> {{ __('super.general_settings.update_folder_label') }}</li>
                                <li><code>/public/images</code> {{ __('super.general_settings.update_folder_label') }}</li>
                            </ul>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">5</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step5_title') }}</h4>
                            <ul class="update-step-list update-step-list--files">
                                <li><code>.env</code> {{ __('super.general_settings.update_file_label') }}</li>
                                <li><code>storage</code> {{ __('super.general_settings.update_folder_label') }}</li>
                                <li><code>/public/images</code> {{ __('super.general_settings.update_folder_label') }}</li>
                            </ul>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">6</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{!! __('super.general_settings.update_step6_title', ['path' => '<code>' . rtrim(config('app.url'), '/') . '/update</code>']) !!}</h4>
                        </div>
                    </li>

                    <li class="update-step">
                        <div class="update-step-num">7</div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step7_title') }}</h4>
                        </div>
                    </li>

                    <li class="update-step update-step--done">
                        <div class="update-step-num"><i class="bi bi-check-lg"></i></div>
                        <div class="update-step-body">
                            <h4 class="update-step-title">{{ __('super.general_settings.update_step8_title') }}</h4>
                        </div>
                    </li>
                </ol>

                <div class="update-note">
                    <i class="bi bi-info-circle"></i>
                    <span>{{ __('super.general_settings.update_note_blank_page') }}</span>
                </div>
            </section>
            </form>

            {{-- ── Panel: Security (own form) ─────────────────────── --}}
            <section class="settings-panel" id="panel-security" role="tabpanel" data-panel="security" data-nosave="1"
                     data-title="{{ __('super.security_settings.title') }}"
                     data-desc="{{ __('super.security_settings.subtitle') }}">
                @include('central.super.settings.partials.security-panel', ['security' => $security])
            </section>

            {{-- ── Panel: E-Invoicing (own forms) ─────────────────── --}}
            <section class="settings-panel" id="panel-einvoicing" role="tabpanel" data-panel="einvoicing" data-nosave="1"
                     data-title="{{ __('super.einvoicing.title') }}"
                     data-desc="{{ __('super.einvoicing.subtitle') }}">
                @include('central.super.settings.partials.einvoicing-panel', ['einv' => $einv])
            </section>

            {{-- ── Panel: Features — optional platform features on/off (own form) ── --}}
            <section class="settings-panel" id="panel-features" role="tabpanel" data-panel="features" data-nosave="1"
                     data-title="{{ __('super.platform_features.title') }}"
                     data-desc="{{ __('super.platform_features.subtitle') }}">
                @include('central.super.settings.partials.features-panel')
            </section>
        </div>
    </div>

{{-- Hidden helper forms --}}
<form method="POST" action="{{ route('super.settings.general.clear-cache') }}" id="clearCacheForm" class="d-none">
    @csrf
</form>
<form method="POST" action="{{ route('super.settings.general.remove-logo') }}" id="removeLogoForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.remove-favicon') }}" id="removeFaviconForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.remove-tenant-logo') }}" id="removeTenantLogoForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.remove-tenant-favicon') }}" id="removeTenantFaviconForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
<form method="POST" action="{{ route('super.settings.general.apply-tenant-branding') }}" id="applyTenantBrandingForm" class="d-none">
    @csrf
</form>

@push('scripts')
<script>
window.GeneralSettingsData = {
    removeLogoConfirm:    @json(__('super.general_settings.remove_logo')),
    removeFaviconConfirm: @json(__('super.general_settings.remove_favicon')),
    removeTenantLogoConfirm:    @json(__('super.general_settings.remove_tenant_logo')),
    removeTenantFaviconConfirm: @json(__('super.general_settings.remove_tenant_favicon')),
    applyBrandingConfirm:       @json(__('super.general_settings.apply_branding_confirm')),
    applyBrandingSaveFirst:     @json(__('super.general_settings.apply_branding_save_first')),
    applyBrandingPickOne:       @json(__('super.general_settings.apply_branding_pick_one')),
    backupDeleteConfirm:  @json(__('super.general_settings.backup_delete_confirm')),
    backupDeleteTitle:    @json(__('super.general_settings.backup_delete_title')),
    backupYesDelete:      @json(__('super.general_settings.backup_yes_delete')),
    backupCreating:       @json(__('super.general_settings.backup_creating')),
    backupCreated:        @json(__('super.general_settings.backup_created')),
    backupDeleted:        @json(__('super.general_settings.backup_deleted')),
    backupFailed:         @json(__('super.general_settings.backup_failed')),
    backupDownload:       @json(__('super.general_settings.backup_download')),
    backupDelete:         @json(__('super.general_settings.backup_delete')),
    backupEmpty:          @json(__('super.general_settings.backup_empty')),
    cancelText:           @json(__('super.general_settings.cancel')),
    csrfToken:            @json(csrf_token()),
    routes: {
        backupStore:       @json(route('super.settings.general.backup.store')),
        backupDownloadTpl: @json(route('super.settings.general.backup.download', ['filename' => '__FILENAME__'])),
        backupDestroyTpl:  @json(route('super.settings.general.backup.destroy', ['filename' => '__FILENAME__'])),
    },
};
</script>
<script>
document.querySelectorAll('[data-tenant-languages-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
        var checked = button.getAttribute('data-tenant-languages-toggle') === '1';
        button.closest('[data-tenant-languages]').querySelectorAll('input[name="tenant_enabled_languages[]"]').forEach(function (box) {
            box.checked = checked;
        });
    });
});

// Signup links in a chosen currency (?currency=XXX appended; empty = visitor's choice).
document.getElementById('signupLinkCurrency')?.addEventListener('change', function () {
    var code = this.value;
    document.querySelectorAll('[data-signup-link]').forEach(function (el) {
        var base = el.getAttribute('data-signup-link');
        var url = code ? base + (base.indexOf('?') === -1 ? '?' : '&') + 'currency=' + encodeURIComponent(code) : base;
        if (el.tagName === 'INPUT') { el.value = url; } else { el.setAttribute('data-url', url); }
    });
});

// Default colour fields: the hex text input is what gets saved (empty = built-in);
// swatches and the native picker just fill it in.
document.querySelectorAll('[data-default-color]').forEach(function (field) {
    var builtin = field.getAttribute('data-builtin');
    var hex = field.querySelector('.default-color-hex');
    var picker = field.querySelector('.default-color-picker');
    var valid = function (v) { return /^#[0-9a-f]{6}$/i.test(v); };
    var sync = function () {
        var raw = hex.value.trim();
        if (raw && raw[0] !== '#') raw = '#' + raw;
        var effective = valid(raw) ? raw.toLowerCase() : builtin;
        picker.value = effective;
        field.querySelectorAll('.default-color-swatch').forEach(function (sw) {
            sw.classList.toggle('is-active', sw.getAttribute('data-color') === effective);
        });
    };
    var set = function (value) { hex.value = value ? value.toUpperCase() : ''; sync(); };

    field.querySelectorAll('.default-color-swatch').forEach(function (sw) {
        sw.addEventListener('click', function () { set(sw.getAttribute('data-color')); });
    });
    picker.addEventListener('input', function () { set(picker.value); });
    hex.addEventListener('input', sync);
    field.querySelector('.default-color-reset').addEventListener('click', function () { set(''); });
});
</script>
<script src="{{ \App\Support\SuperAsset::url('assets_super/js/settings-general.js') }}"></script>
@endpush

@endsection
