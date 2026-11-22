<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $ok ? 'Unsubscribed' : 'Link not valid' }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f1f5f9; color: #0f172a; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }
        .card { background: #fff; max-width: 440px; width: 100%; border-radius: 18px; padding: 34px 28px; text-align: center; box-shadow: 0 12px 32px -12px rgba(15, 23, 42, .2); }
        .ic { width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; }
        .ok .ic { background: #dcfce7; color: #16a34a; }
        .bad .ic { background: #fee2e2; color: #dc2626; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: #64748b; margin: 0; line-height: 1.5; }
        .foot { margin-top: 18px; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card {{ $ok ? 'ok' : 'bad' }}">
        <div class="ic">
            @if ($ok)
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            @else
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            @endif
        </div>
        @if ($ok)
            <h1>You're unsubscribed</h1>
            <p>You won't receive marketing messages from us anymore. Messages about your own requests may still reach you.</p>
        @else
            <h1>This link is not valid</h1>
            <p>The unsubscribe link may be incomplete. Please use the link from the latest email, or reply to it and ask us to remove you.</p>
        @endif
        @if ($company)<div class="foot">{{ $company }}</div>@endif
    </div>
</div>
</body>
</html>
