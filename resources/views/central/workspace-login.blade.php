<!DOCTYPE html>
@php
    $isRtl = \App\Models\Central\CentralLanguage::isRtl(app()->getLocale());
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
@endphp
<html lang="{{ app()->getLocale() }}" @if($isRtl) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('landing.workspace_login_title') }} — {{ $appName }}</title>
    @php $faviconUrl = $generalSettings->getFaviconUrl(); @endphp
    <link rel="icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}" type="image/x-icon">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/auth.css') }}" rel="stylesheet">
</head>
<body>

    <div class="login-hero">
        <div class="hero-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
        </div>
        <div class="hero-content">
            <div class="hero-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
            </div>
            <h2>{{ __('landing.workspace_login_title') }}</h2>
            <p>{{ __('landing.workspace_login_hero') }}</p>
        </div>
    </div>

    <div class="login-panel">
        <div class="login-form-wrapper">

            <a class="brand-logo" href="{{ route('central.welcome') }}">
                <div class="logo-icon">
                    <img src="{{ $generalSettings->getLogoUrl() ?: asset('images/logo-default.png') }}" alt="{{ $appName }}">
                </div>
                <span>{{ $appName }}</span>
            </a>

            <div class="form-heading">
                <h1>{{ __('landing.workspace_login_title') }}</h1>
                <p>{{ __('landing.workspace_login_subtitle') }}</p>
            </div>

            @if ($errors->any())
                <div class="login-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('central.workspace.login.submit') }}">
                @csrf

                <div class="form-group">
                    <label for="workspace">{{ __('landing.workspace_address') }}</label>
                    <div class="input-wrapper">
                        <input
                            id="workspace"
                            name="workspace"
                            type="text"
                            class="{{ $errors->has('workspace') ? 'is-invalid' : '' }}"
                            value="{{ old('workspace') }}"
                            required
                            autofocus
                            autocapitalize="none"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="{{ __('landing.workspace_placeholder', ['domain' => $baseDomain]) }}"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                        </svg>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="btn-submit">
                    <span class="btn-text">{{ __('landing.workspace_continue') }}</span>
                    <svg class="btn-arrow" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                    <span class="spinner"></span>
                </button>
            </form>

            <div class="divider"><span>{{ __('landing.or') }}</span></div>

            <a class="back-home" href="{{ route('central.register') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                {{ __('landing.create_workspace') }}
            </a>

            <div class="login-footer">
                &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.
            </div>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/forgot-password.js') }}"></script>
</body>
</html>
