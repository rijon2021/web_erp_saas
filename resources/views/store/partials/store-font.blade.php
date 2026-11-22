{{--
    Storefront font override.
    Store Settings → Font family stores either a curated Google family (see
    \App\Models\Setting::DASHBOARD_GOOGLE_FONTS, shared with the dashboard picker)
    or a legacy raw CSS stack typed before the dropdown existed. Google families
    are fetched from the Google Fonts CDN here and applied AFTER the theme head
    so they win over the theme's default body font. Legacy stacks are still
    handled by the layout's own body rule, so this renders nothing for them.
    Only whitelisted family names reach the interpolations below.
    The chosen family covers EVERY element, theme display fonts included
    (headings, .section-title, .hero-title, newsletter, logo, prices, inline
    font-family styles): those fonts (Space Grotesk, Manrope, Fredoka,
    Playfair...) have no Arabic glyphs, so leaving them made RTL headings fall
    back to a system font. Code and icon fonts are left alone.
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
        body :where(*:not(code, pre, kbd, samp, .font-mono, svg, svg *, .bi, [class^="bi-"], [class*=" bi-"], .fa, .fas, .far, .fab, [class^="fa-"], [class*=" fa-"])),
        body :where(input, textarea, select, button)::placeholder {
            font-family: var(--store-font) !important;
        }
    </style>
@endif
