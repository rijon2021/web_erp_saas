<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $super ? __('super.license.locked_title') : __('super.license.locked_public_title') }}</title>
    @php
        try { $favicon = \App\Models\Central\GeneralSetting::instance()->getPublicFaviconUrl(); } catch (\Throwable $e) { $favicon = null; }
    @endphp
    @if ($favicon)<link rel="icon" href="{{ $favicon }}">@endif
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/status-pages.css') }}" rel="stylesheet">
    <style>
        .suspended-container .lock-actions { margin-top: 1.5rem; display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .suspended-container .lock-btn { display: inline-block; padding: .6rem 1.2rem; border-radius: .5rem; background: #2563eb; color: #fff; text-decoration: none; font-weight: 600; }
        .suspended-container .lock-btn.secondary { background: transparent; color: #2563eb; border: 1px solid #2563eb; }
        .suspended-container .lock-hint { margin-top: 1rem; font-size: .9rem; opacity: .75; }
    </style>
</head>
<body class="page-suspended">
    <div class="suspended-container">
        @if ($super)
            <h1>{{ __('super.license.locked_title') }}</h1>
            <p>{{ __('super.license.locked_text') }}</p>
            <div class="lock-actions">
                <a class="lock-btn" href="{{ url('/super/settings/license') }}">{{ __('super.license.locked_action') }}</a>
                <a class="lock-btn secondary" href="{{ url('/super/login') }}">{{ __('super.license.locked_login') }}</a>
            </div>
            <p class="lock-hint">{{ __('super.license.locked_login_hint') }}</p>
        @else
            <h1>{{ __('super.license.locked_public_title') }}</h1>
            <p>{{ __('super.license.locked_public_text') }}</p>
        @endif
    </div>
</body>
</html>
