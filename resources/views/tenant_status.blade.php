{{-- Shown on a tenant domain while the workspace is not live (see
     EnsureTenantNotSuspended). Self-contained: the tenant database may not
     exist yet, so only central settings and file translations are used. --}}
@php
    try {
        $statusSettings = \App\Models\Central\GeneralSetting::instance();
    } catch (\Throwable) {
        $statusSettings = null;
    }
    $statusAppName = $statusSettings?->tenant_app_name ?: ($statusSettings?->app_name ?: config('app.name'));
    $supportEmail = $statusSettings?->email;
    $icons = [
        'pending'      => 'hourglass',
        'provisioning' => 'gear',
        'failed'       => 'alert',
        'rejected'     => 'block',
        'suspended'    => 'lock',
        'cancelled'    => 'block',
        'unpaid'       => 'lock',
    ];
    $icon = $icons[$state] ?? 'lock';
    $tone = in_array($state, ['pending', 'provisioning'], true) ? 'info' : (in_array($state, ['failed', 'rejected'], true) ? 'danger' : 'warn');
    $showSupport = $supportEmail && in_array($state, ['failed', 'rejected', 'suspended', 'cancelled'], true);
    $locale = app()->getLocale();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ in_array($locale, ['ar', 'ur', 'fa', 'he'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @if($state === 'provisioning')
        <meta http-equiv="refresh" content="15">
    @endif
    <title>{{ __('central.WorkspaceState_' . $state . '_title') }} · {{ $statusAppName }}</title>
    <style>
        :root { --bg: #f5f7fb; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0;
                --info: #6366f1; --info-tint: #eef2ff; --warn: #d97706; --warn-tint: #fffbeb; --danger: #dc2626; --danger-tint: #fef2f2; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --card: #111827; --text: #e5e7eb; --muted: #94a3b8; --line: #1f2937;
                    --info-tint: rgba(99,102,241,.15); --warn-tint: rgba(217,119,6,.15); --danger-tint: rgba(220,38,38,.15); }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .card { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line); border-radius: 16px;
                padding: 36px 32px; text-align: center; box-shadow: 0 10px 30px rgba(15,23,42,.06); }
        .icon { width: 64px; height: 64px; margin: 0 auto 20px; border-radius: 16px; display: flex; align-items: center; justify-content: center; }
        .tone-info .icon { background: var(--info-tint); color: var(--info); }
        .tone-warn .icon { background: var(--warn-tint); color: var(--warn); }
        .tone-danger .icon { background: var(--danger-tint); color: var(--danger); }
        .icon svg { width: 30px; height: 30px; }
        .spin { animation: spin 2.4s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spin { animation: none; } }
        h1 { font-size: 1.3rem; margin: 0 0 10px; line-height: 1.35; }
        p { margin: 0; color: var(--muted); font-size: .95rem; line-height: 1.6; }
        .actions { margin-top: 24px; display: flex; flex-direction: column; gap: 10px; align-items: center; }
        .btn { display: inline-block; padding: 11px 22px; border-radius: 10px; background: var(--info); color: #fff; text-decoration: none; font-weight: 600; font-size: .92rem; }
        .btn:hover { filter: brightness(.95); }
        .support { font-size: .85rem; }
        .support a { color: var(--info); }
        .brand { margin-top: 28px; font-size: .78rem; color: var(--muted); }
    </style>
</head>
<body>
    <main class="card tone-{{ $tone }}">
        <div class="icon" aria-hidden="true">
            @switch($icon)
                @case('hourglass')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12M6 22h12M7 2v4a5 5 0 0 0 10 0V2M7 22v-4a5 5 0 0 1 10 0v4"/></svg>
                    @break
                @case('gear')
                    <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
                    @break
                @case('alert')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                    @break
                @case('block')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg>
                    @break
                @default
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            @endswitch
        </div>

        <h1>{{ __('central.WorkspaceState_' . $state . '_title') }}</h1>
        <p>{{ __('central.WorkspaceState_' . $state . '_text') }}</p>

        @if($state === 'unpaid' || $showSupport)
            <div class="actions">
                @if($state === 'unpaid')
                    <a class="btn" href="{{ url('/login') }}">{{ __('central.WorkspaceOwnerSignIn') }}</a>
                @endif
                @if($showSupport)
                    <span class="support">{{ __('central.WorkspaceContactSupport') }} <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></span>
                @endif
            </div>
        @endif

        <div class="brand">{{ $statusAppName }}</div>
    </main>
</body>
</html>
