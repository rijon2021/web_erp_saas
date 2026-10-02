{{--
    Storefront font override.
    Store Settings → Font family stores either a curated Google family (see
    \App\Models\Setting::DASHBOARD_GOOGLE_FONTS, shared with the dashboard picker)
    or a legacy raw CSS stack typed before the dropdown existed. Google families
    are fetched from the Google Fonts CDN here and applied AFTER the theme head
    so they win over the theme's default body font. Legacy stacks are still
    handled by the layout's own body rule, so this renders nothing for them.
    Only whitelisted family names reach the interpolations below.
    Theme display classes (.section-title, .re-serif, ...) keep their own font.
--}}
@php
    $storeFont = trim((string) ($s->font_family ?? ''));
    $storeFontIsGoogle = \App\Models\Setting::isDashboardGoogleFont($storeFont);
@endphp
@if($storeFontIsGoogle)
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $storeFont) }}:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style id="store-font">
        :root { --store-font: '{{ $storeFont }}', 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        body,
        button, input, textarea, select {
            font-family: var(--store-font);
        }
    </style>
@endif
