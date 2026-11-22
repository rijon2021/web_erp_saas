{{-- Electronics Premium skin (plan-gated). Self-contained CSS — no Tailwind
     rebuild needed. Light by default, dark when the visitor picks it with the
     header toggle (`dark` class on <html>). Re-tints the shared tokens so every
     storefront page (shop, cart, checkout, account) follows, and defines the
     `px-*` components used by this theme's header, footer, homepage and product
     page. Fonts (Space Grotesk, Inter, JetBrains Mono) come from the layout. --}}
@php
  $pxPal = \App\Support\PremiumElectronicsTheme::palette($s->primary_color ?: '#5B8CFF');
@endphp
<style>
  /* ---------- Tokens: light (default) ---------- */
  :root {
    --color-bg-base:     246 247 249;
    --color-bg-surface:  255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted:    241 243 246;
    --color-border-subtle: 227 231 237;
    --color-border-strong: 203 210 220;
    --color-fg-primary:   15 20 26;
    --color-fg-secondary: 58 68 82;
    --color-fg-muted:     90 100 114;
    --color-accent-400: {{ $pxPal['text_light_rgb'] }};
    --color-accent-500: {{ $pxPal['fill_rgb'] }};
    --color-accent-600: {{ $pxPal['fill_rgb'] }};
    --color-accent-glow: rgb({{ $pxPal['fill_rgb'] }} / .25);
    --px-ground: #F6F7F9;
    --px-surface: #FFFFFF;
    --px-raised: #F1F3F6;
    --px-line: #E3E7ED;
    --px-line-strong: #CBD2DC;
    --px-input-line: #D5DBE3;
    --px-hair: #E6E9EE;
    --px-ink: #0F141A;
    --px-soft: #3A4452;
    --px-muted: #5A6472;
    --px-faint: #6B7584;
    --px-dim: #9AA4B2;
    --px-mega-link: #1F2630;
    --px-header-bg: rgb(255 255 255 / .92);
    --px-hilite-bg: rgb({{ $pxPal['fill_rgb'] }} / .07);
    --px-hilite-line: rgb({{ $pxPal['fill_rgb'] }} / .35);
    --px-shadow-c: rgba(15, 20, 26, .16);
    --px-accent: {{ $pxPal['fill'] }};
    --px-on-accent: {{ $pxPal['on'] }};
    --px-accent-text: {{ $pxPal['text_light'] }};
    --px-accent-soft: rgb({{ $pxPal['fill_rgb'] }} / .10);
    --px-deal: #B45309;
    --px-on-deal: #FFFFFF;
    --px-deal-bg: #FFF4E0;
    --px-deal-line: #F5D9A8;
    --px-count-bg: #FFF4E0;
    --px-pre-bg: #FFF1D6;
    --px-ok: #15803D;
    --px-radius: 20px;
    --px-display: 'Space Grotesk', 'Inter', system-ui, sans-serif;
    --px-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
    color-scheme: light;
  }
  /* ---------- Tokens: dark (visitor's choice) ---------- */
  .dark {
    --color-bg-base:     10 12 16;
    --color-bg-surface:  18 22 28;
    --color-bg-elevated: 26 31 39;
    --color-bg-muted:    26 31 39;
    --color-border-subtle: 35 42 52;
    --color-border-strong: 57 66 79;
    --color-fg-primary:   242 244 247;
    --color-fg-secondary: 195 202 213;
    --color-fg-muted:     154 164 178;
    --color-accent-400: {{ $pxPal['text_rgb'] }};
    --color-accent-glow: rgb({{ $pxPal['fill_rgb'] }} / .35);
    --px-ground: #0A0C10;
    --px-surface: #12161C;
    --px-raised: #1A1F27;
    --px-line: #232A34;
    --px-line-strong: #39424F;
    --px-input-line: #2A323D;
    --px-hair: #1C222B;
    --px-ink: #F2F4F7;
    --px-soft: #C3CAD5;
    --px-muted: #9AA4B2;
    --px-faint: #7D8796;
    --px-dim: #5D6878;
    --px-mega-link: #E3E7ED;
    --px-header-bg: rgb(10 12 16 / .92);
    --px-hilite-bg: #111827;
    --px-hilite-line: #2F3B52;
    --px-shadow-c: rgba(0, 0, 0, .75);
    --px-accent-text: {{ $pxPal['text'] }};
    --px-accent-soft: rgb({{ $pxPal['fill_rgb'] }} / .14);
    --px-deal: #FFB547;
    --px-on-deal: #1A1305;
    --px-deal-bg: #2B2312;
    --px-deal-line: #4A3A1A;
    --px-count-bg: #1F1A10;
    --px-pre-bg: #3A2A10;
    --px-ok: #5FD39A;
    color-scheme: dark;
  }
  html { scroll-behavior: smooth; background: var(--px-ground); }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--px-ground); color: var(--px-ink); }
  ::selection { background: var(--px-accent); color: var(--px-on-accent); }

  /* Shared components (shop, cart, checkout…) re-tuned for this theme */
  .btn { border-radius: 12px; }
  .btn-primary { background: var(--px-accent) !important; color: var(--px-on-accent) !important; box-shadow: none; }
  .card, .product-card { border-radius: var(--px-radius); }
  .section-title, .hero-title, h1, h2 { font-family: var(--px-display); letter-spacing: -0.02em; }
  .input, .select, .textarea { border-radius: 12px; }
  .drawer-panel, .dialog-panel, .auth-panel { border-radius: 18px; }
  .price, .price-lg, .price-xl, .price-sm { font-family: var(--px-display); letter-spacing: -0.01em; }
  a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid var(--px-accent-text); outline-offset: 2px; }

  /* ---------- Layout ---------- */
  .px-container { width: 100%; max-width: 1320px; margin: 0 auto; padding: 0 16px; }
  @media (min-width: 1024px) { .px-container { padding: 0 24px; } }
  .px-section { padding: 36px 0 0; }
  @media (min-width: 1024px) { .px-section { padding-top: 64px; } }
  .px-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 14px 24px; margin-bottom: 20px; }
  .px-h2 { margin: 0; font-family: var(--px-display); font-weight: 700; font-size: 26px; line-height: 1.1; letter-spacing: -0.02em; color: var(--px-ink); display: flex; align-items: center; gap: 10px; }
  @media (min-width: 768px) { .px-h2 { font-size: 34px; } }
  .px-h2 svg { width: 26px; height: 26px; color: var(--px-deal); }
  .px-sub { margin: 6px 0 0; color: var(--px-muted); font-size: 14px; }
  .px-kicker { font-family: var(--px-mono); font-size: 12px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--px-accent-text); }
  .px-link { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 14px; color: var(--px-accent-text); white-space: nowrap; }
  .px-link:hover { text-decoration: underline; }
  .px-muted { color: var(--px-muted); }
  .px-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  .px-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 50px; padding: 0 24px; border-radius: 12px; font-size: 15px; font-weight: 600; border: 1px solid transparent; cursor: pointer; white-space: nowrap; transition: filter .15s, background .15s, border-color .15s; }
  .px-btn svg { width: 16px; height: 16px; }
  .px-btn-primary { background: var(--px-accent); color: var(--px-on-accent); }
  .px-btn-primary:hover { filter: brightness(1.08); color: var(--px-on-accent); }
  .px-btn-ghost { background: transparent; color: var(--px-ink); border-color: var(--px-line-strong); }
  .px-btn-ghost:hover { border-color: var(--px-soft); color: var(--px-ink); }
  .px-btn-sm { height: 42px; padding: 0 18px; font-size: 14px; }
  .px-btn:disabled { opacity: .45; cursor: not-allowed; }

  /* ---------- Top bar ---------- */
  .px-topbar { background: var(--px-surface); border-bottom: 1px solid var(--px-line); color: var(--px-soft); font-size: 12.5px; }
  .px-topbar .px-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 36px; }
  .px-topbar-left { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .px-topbar-right { display: none; align-items: center; gap: 2px; }
  @media (min-width: 768px) { .px-topbar-right { display: flex; } }
  .px-topbar-right a, .px-topbar-right button { color: var(--px-soft); padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; background: transparent; border: 0; cursor: pointer; }
  .px-topbar-right a:hover, .px-topbar-right button:hover { color: var(--px-ink); background: var(--px-raised); }
  .px-topbar svg { width: 14px; height: 14px; }

  /* ---------- Header ---------- */
  .px-header { position: sticky; top: 0; z-index: 40; background: var(--px-header-bg); backdrop-filter: saturate(1.4) blur(10px); -webkit-backdrop-filter: saturate(1.4) blur(10px); border-bottom: 1px solid var(--px-hair); }
  .px-header-main { display: flex; align-items: center; gap: 10px; min-height: 64px; }
  @media (min-width: 1024px) { .px-header-main { min-height: 80px; gap: 28px; } }
  .px-logo { display: inline-flex; align-items: center; gap: 10px; flex-shrink: 0; font-family: var(--px-display); font-weight: 700; font-size: 22px; letter-spacing: -0.02em; color: var(--px-ink); }
  .px-logo img { height: 36px; max-width: 170px; object-fit: contain; }
  @media (max-width: 1023.98px) {
    .px-logo { flex-shrink: 1; min-width: 0; }
    .px-logo > span:last-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  }
  .px-logo-mark { width: 34px; height: 34px; border-radius: 10px; background: var(--px-accent); color: var(--px-on-accent); display: grid; place-items: center; }
  .px-logo-mark svg { width: 18px; height: 18px; }
  .px-search { display: none; flex: 1; height: 46px; border: 1px solid var(--px-input-line); border-radius: 12px; background: var(--px-surface); position: relative; }
  .px-search:focus-within { border-color: var(--px-accent-text); }
  @media (min-width: 1024px) { .px-search { display: flex; } }
  .px-search select { appearance: none; background: var(--px-raised); color: var(--px-soft); border: 0; border-inline-end: 1px solid var(--px-input-line); border-start-start-radius: 11px; border-end-start-radius: 11px; padding-inline: 12px 30px; font-size: 13px; max-width: 170px; cursor: pointer; outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239AA4B2' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; }
  [dir="rtl"] .px-search select { background-position: left 10px center; }
  .px-search input { flex: 1; min-width: 0; background: transparent; border: 0; color: var(--px-ink); padding: 0 14px; font-size: 14px; outline: none; }
  .px-search input::placeholder { color: var(--px-faint); }
  .px-search > button { width: 52px; border: 0; background: var(--px-accent); color: var(--px-on-accent); display: grid; place-items: center; cursor: pointer; border-start-end-radius: 11px; border-end-end-radius: 11px; }
  .px-search > button svg { width: 18px; height: 18px; }
  .px-search-results { position: absolute; top: calc(100% + 6px); inset-inline: 0; z-index: 60; background: var(--px-surface); border: 1px solid var(--px-input-line); border-radius: 14px; box-shadow: 0 24px 48px -16px var(--px-shadow-c); max-height: 420px; overflow-y: auto; }
  .px-search-results a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; color: var(--px-ink); }
  .px-search-results a:hover { background: var(--px-raised); }
  .px-search-results img { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; background: var(--px-raised); }
  .px-actions { display: flex; align-items: center; gap: 4px; margin-inline-start: auto; }
  .px-icon-btn { position: relative; width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; color: var(--px-soft); background: transparent; border: 0; cursor: pointer; }
  .px-icon-btn:hover { background: var(--px-raised); color: var(--px-ink); }
  .px-icon-btn svg { width: 20px; height: 20px; }
  .px-badge { position: absolute; top: 4px; inset-inline-end: 4px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 99px; background: var(--px-accent); color: var(--px-on-accent); font-size: 10.5px; font-weight: 700; display: grid; place-items: center; line-height: 1; }
  .px-badge[data-count="0"] { display: none; }
  .px-theme-toggle .px-sun { display: none; }
  .dark .px-theme-toggle .px-moon { display: none; }
  .dark .px-theme-toggle .px-sun { display: block; }
  .px-cart { display: flex; align-items: center; gap: 10px; height: 44px; padding: 0 12px; border-radius: 12px; background: var(--px-raised); border: 1px solid var(--px-input-line); color: var(--px-ink); cursor: pointer; position: relative; }
  .px-cart svg { width: 20px; height: 20px; }
  .px-cart-text { display: none; flex-direction: column; line-height: 1.15; text-align: start; }
  @media (min-width: 1024px) { .px-cart-text { display: flex; } }
  .px-cart-text small { font-size: 11px; color: var(--px-muted); }
  .px-cart-text strong { font-size: 13.5px; font-weight: 600; }
  .px-cart .px-badge { top: -6px; inset-inline-end: -6px; }
  @media (min-width: 1024px) { .px-cart .px-badge { display: none; } }
  .px-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); min-width: 210px; background: var(--px-surface); border: 1px solid var(--px-input-line); border-radius: 14px; box-shadow: 0 24px 48px -16px var(--px-shadow-c); padding: 6px; z-index: 60; }
  .px-menu a, .px-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 10px; border-radius: 9px; font-size: 13.5px; color: var(--px-ink); background: transparent; border: 0; cursor: pointer; text-align: start; }
  .px-menu a:hover, .px-menu button:hover { background: var(--px-raised); }
  .px-menu svg { width: 16px; height: 16px; color: var(--px-muted); }
  .px-menu hr { border: 0; border-top: 1px solid var(--px-line); margin: 6px 0; }
  .px-msearch { padding: 0 0 12px; }
  @media (min-width: 1024px) { .px-msearch { display: none; } }
  .px-msearch form { position: relative; }
  .px-msearch input { width: 100%; height: 44px; border: 1px solid var(--px-input-line); border-radius: 12px; padding-inline: 14px 48px; font-size: 14px; background: var(--px-surface); color: var(--px-ink); outline: none; }
  .px-msearch button { position: absolute; inset-inline-end: 4px; top: 4px; width: 36px; height: 36px; border-radius: 9px; border: 0; background: var(--px-accent); color: var(--px-on-accent); display: grid; place-items: center; }
  .px-msearch svg { width: 16px; height: 16px; }

  /* Nav row + mega menu */
  .px-nav { display: none; position: relative; }
  @media (min-width: 1024px) { .px-nav { display: block; } }
  .px-nav-row { display: flex; align-items: center; gap: 4px; padding-bottom: 14px; }
  .px-allcats { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 10px; border: 0; background: var(--px-ink); color: var(--px-ground); font-weight: 600; font-size: 14px; cursor: pointer; margin-inline-end: 8px; }
  .px-allcats[aria-expanded="true"] { background: var(--px-accent); color: var(--px-on-accent); }
  .px-allcats svg { width: 16px; height: 16px; }
  .px-allcats .px-chev { width: 14px; height: 14px; transition: transform .15s; }
  .px-allcats[aria-expanded="true"] .px-chev { transform: rotate(180deg); }
  .px-nav-link { height: 40px; padding: 0 13px; display: inline-flex; align-items: center; gap: 6px; color: var(--px-soft); font-weight: 500; font-size: 14px; border-radius: 10px; white-space: nowrap; }
  .px-nav-link:hover, .px-nav-link.is-active { color: var(--px-ink); background: var(--px-raised); }
  .px-nav-link.px-deal { color: var(--px-deal); font-weight: 600; }
  .px-nav-link svg { width: 15px; height: 15px; }
  .px-nav-help { display: none; margin-inline-start: auto; font-size: 13px; color: var(--px-muted); white-space: nowrap; }
  @media (min-width: 1280px) { .px-nav-help { display: inline; } }
  .px-topbar-extra { display: none; }
  @media (min-width: 768px) { .px-topbar-extra { display: inline; } }
  .px-nav-help a { color: var(--px-accent-text); font-weight: 600; }
  .px-mega { position: absolute; top: 100%; inset-inline: 0; z-index: 55; }
  .px-mega-panel { background: var(--px-surface); border: 1px solid var(--px-input-line); border-radius: 20px; display: flex; overflow: hidden; min-height: 380px; box-shadow: 0 40px 80px -24px var(--px-shadow-c); }
  .px-mega-cats { width: 250px; flex: none; border-inline-end: 1px solid var(--px-line); padding: 10px; display: flex; flex-direction: column; gap: 2px; max-height: 460px; overflow-y: auto; }
  .px-mega-cats a { min-height: 42px; padding: 0 12px; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--px-soft); font-weight: 500; font-size: 14px; }
  .px-mega-cats a.is-on, .px-mega-cats a:hover { background: var(--px-raised); color: var(--px-ink); }
  .px-mega-cats svg { width: 14px; height: 14px; flex: none; }
  [dir="rtl"] .px-mega-cats svg { transform: scaleX(-1); }
  .px-mega-body { flex: 1; min-width: 0; padding: 28px 32px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 28px; align-content: start; }
  .px-mega-col { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
  .px-mega-col a { font-size: 14.5px; color: var(--px-mega-link); padding: 3px 0; }
  .px-mega-col a:hover { color: var(--px-accent-text); }
  .px-mega-promo { width: 320px; flex: none; margin: 14px; border-radius: 16px; background: var(--px-raised); padding: 22px; display: flex; flex-direction: column; gap: 10px; color: var(--px-ink); }
  .px-mega-promo img { width: 100%; flex: 1; min-height: 140px; max-height: 220px; object-fit: contain; border-radius: 12px; background: var(--px-surface); }
  .px-mega-promo strong { font-family: var(--px-display); font-size: 22px; font-weight: 600; letter-spacing: -0.02em; line-height: 1.15; }
  @media (max-width: 1279.98px) { .px-mega-promo { display: none; } }

  /* ---------- Full-image hero slider (hero_style = full) ---------- */
  .px-fhero { position: relative; overflow: hidden; background: #0b0d12; color: #fff; touch-action: pan-y; isolation: isolate; }
  .px-fhero--edge { width: 100%; }
  .px-fhero--contained { margin-top: 20px; border-radius: 28px; }
  .px-fhero-track { position: relative; height: clamp(440px, 68vh, 720px); }
  .px-fhero--medium .px-fhero-track { height: clamp(380px, 54vh, 540px); }
  .px-fhero--screen .px-fhero-track { height: max(480px, min(calc(100svh - 112px), 1000px)); }
  .px-fslide { position: absolute; inset: 0; opacity: 0; visibility: hidden; transition: opacity .9s ease, visibility 0s linear .9s; }
  .px-fslide.is-active { opacity: 1; visibility: visible; transition: opacity .9s ease, visibility 0s; z-index: 1; }
  .px-fslide-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transform: scale(1.06); transform-origin: center; }
  .px-fslide.is-active .px-fslide-img { animation: px-kenburns calc(var(--px-slide-ms, 7000ms) + 1500ms) ease-out forwards; }
  @keyframes px-kenburns { from { transform: scale(1.08); } to { transform: scale(1); } }
  .px-fslide-fill { position: absolute; inset: 0; background: radial-gradient(120% 120% at 15% 20%, var(--px-accent), #0b0d12 70%); }
  /* Scrim: keeps white text readable on any photo. Strength per slide. */
  .px-fslide-scrim { position: absolute; inset: 0; --px-scrim: .55; --px-scrim-dir: 90deg;
    background: linear-gradient(var(--px-scrim-dir), rgb(0 0 0 / var(--px-scrim)) 0%, rgb(0 0 0 / calc(var(--px-scrim) * .55)) 45%, rgb(0 0 0 / 0) 80%),
                linear-gradient(0deg, rgb(0 0 0 / calc(var(--px-scrim) * .7)) 0%, rgb(0 0 0 / 0) 40%); }
  .px-scrim--none { --px-scrim: 0; }
  .px-scrim--light { --px-scrim: .35; }
  .px-scrim--strong { --px-scrim: .78; }
  .px-fslide--right .px-fslide-scrim { --px-scrim-dir: 270deg; }
  [dir="rtl"] .px-fslide--left .px-fslide-scrim { --px-scrim-dir: 270deg; }
  [dir="rtl"] .px-fslide--right .px-fslide-scrim { --px-scrim-dir: 90deg; }
  .px-fslide--center .px-fslide-scrim { background: rgb(0 0 0 / calc(var(--px-scrim) * .75)); }
  .px-fhero *, .px-fhero *::before, .px-fhero *::after { box-sizing: border-box; }
  .px-fslide-inner { position: relative; z-index: 2; height: 100%; max-width: 1360px; margin: 0 auto; padding: 24px 22px 76px; display: flex; align-items: flex-end; }
  .px-fslide-actions a, .px-fslide-play { text-decoration: none; }
  @media (min-width: 768px) { .px-fslide-inner { padding: 0 56px; align-items: center; } }
  .px-fslide--center .px-fslide-inner { justify-content: center; text-align: center; }
  .px-fslide--right .px-fslide-inner { justify-content: flex-end; }
  .px-fslide-copy { max-width: 640px; display: flex; flex-direction: column; gap: 18px; align-items: flex-start; }
  .px-fslide--center .px-fslide-copy { align-items: center; }
  .px-fslide--right .px-fslide-copy { align-items: flex-end; text-align: end; }
  .px-fslide-kicker { font-family: var(--px-mono); font-size: 12.5px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #fff; opacity: .9;
    padding: 6px 12px; border-radius: 99px; background: rgb(255 255 255 / .14); border: 1px solid rgb(255 255 255 / .22); backdrop-filter: blur(6px); }
  .px-fslide-title { margin: 0; font-family: var(--px-display); font-weight: 700; color: #fff; letter-spacing: -0.035em; line-height: 1.02;
    font-size: clamp(36px, 6.2vw, 80px); text-shadow: 0 2px 24px rgb(0 0 0 / .25); text-wrap: balance; }
  .px-fslide-sub { margin: 0; font-size: clamp(15px, 1.5vw, 19px); line-height: 1.55; color: rgb(255 255 255 / .88); max-width: 520px; text-wrap: pretty; }
  .px-fslide-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px; align-items: center; }
  .px-fslide--center .px-fslide-actions { justify-content: center; }
  .px-btn-lg { height: 54px; padding: 0 28px; font-size: 15.5px; border-radius: 14px; }
  .px-btn-onimg { background: rgb(255 255 255 / .1); color: #fff; border-color: rgb(255 255 255 / .55); backdrop-filter: blur(6px); }
  .px-btn-onimg:hover { background: rgb(255 255 255 / .2); border-color: #fff; color: #fff; }
  .px-fslide-play { display: inline-flex; align-items: center; gap: 10px; height: 54px; padding: 0 18px 0 8px; border-radius: 99px; border: 0; background: transparent; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
  .px-fslide-play span { width: 40px; height: 40px; border-radius: 99px; background: #fff; color: #0b0d12; display: grid; place-items: center; box-shadow: 0 0 0 6px rgb(255 255 255 / .18); }
  .px-fslide-play svg { width: 12px; height: 12px; margin-inline-start: 2px; }
  .px-fslide-play:hover span { box-shadow: 0 0 0 9px rgb(255 255 255 / .22); }
  /* Copy enters with a short rise when its slide becomes active. */
  .px-fslide.is-active .px-fslide-copy > * { animation: px-rise .7s cubic-bezier(.2, .7, .2, 1) both; }
  .px-fslide.is-active .px-fslide-copy > *:nth-child(2) { animation-delay: .08s; }
  .px-fslide.is-active .px-fslide-copy > *:nth-child(3) { animation-delay: .16s; }
  .px-fslide.is-active .px-fslide-copy > *:nth-child(4) { animation-delay: .24s; }
  @keyframes px-rise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
  .px-fhero-arrow { position: absolute; top: 50%; z-index: 3; width: 52px; height: 52px; margin-top: -26px; border-radius: 99px; display: none; place-items: center; cursor: pointer;
    background: rgb(255 255 255 / .14); border: 1px solid rgb(255 255 255 / .3); color: #fff; backdrop-filter: blur(8px); transition: background .2s, opacity .2s; }
  .px-fhero-arrow:hover { background: rgb(255 255 255 / .28); }
  .px-fhero-arrow svg { width: 22px; height: 22px; }
  .px-fhero-arrow--prev { inset-inline-start: 20px; }
  .px-fhero-arrow--next { inset-inline-end: 20px; }
  [dir="rtl"] .px-fhero-arrow svg { transform: scaleX(-1); }
  @media (min-width: 768px) { .px-fhero-arrow { display: grid; opacity: .0; } .px-fhero:hover .px-fhero-arrow, .px-fhero-arrow:focus-visible { opacity: 1; } }
  @media (hover: none) and (min-width: 768px) { .px-fhero-arrow { opacity: 1; } }
  .px-fhero-nav { position: absolute; z-index: 3; left: 0; right: 0; bottom: 30px; max-width: 1360px; margin: 0 auto; padding: 0 22px; display: flex; align-items: center; gap: 10px; }
  /* Phones: smaller buttons so two fit per row and the copy stays clear of the dots. */
  @media (max-width: 767.98px) {
    .px-fslide-copy { gap: 14px; }
    .px-fslide-actions { gap: 10px; }
    .px-fslide-actions .px-btn-lg { height: 48px; padding: 0 20px; font-size: 15px; }
    .px-fslide-play { height: 48px; }
    .px-fslide-play span { width: 36px; height: 36px; }
  }
  @media (min-width: 768px) { .px-fhero-nav { padding: 0 56px; bottom: 36px; } }
  .px-fhero-dot { position: relative; width: 34px; height: 4px; padding: 0; border: 0; border-radius: 99px; background: rgb(255 255 255 / .32); overflow: hidden; cursor: pointer; }
  .px-fhero-dot::before { content: ''; position: absolute; inset: -10px 0; } /* bigger tap target */
  .px-fhero-dot.is-active { width: 64px; }
  .px-fhero-dot i { position: absolute; inset: 0; width: 0; background: #fff; border-radius: inherit; }
  .px-fhero-dot.is-active i { animation: px-progress var(--px-slide-ms, 7000ms) linear forwards; }
  .px-fhero.is-paused .px-fhero-dot.is-active i { animation: none; width: 100%; }
  @keyframes px-progress { to { width: 100%; } }
  .px-fhero-count { margin-inline-start: 8px; font-family: var(--px-mono); font-size: 12.5px; color: rgb(255 255 255 / .8); letter-spacing: .06em; }
  @media (prefers-reduced-motion: reduce) {
    .px-fslide, .px-fslide.is-active { transition: none; }
    .px-fslide.is-active .px-fslide-img, .px-fslide.is-active .px-fslide-copy > * { animation: none; transform: none; }
  }
  /* Promo tiles under the full slider: a row instead of a column. */
  .px-tiles--row { flex-direction: row; flex-wrap: wrap; margin-top: 20px; }
  .px-tiles--row .px-tile { flex: 1 1 320px; }

  /* ---------- Hero bento ---------- */
  .px-bento { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 20px; }
  .px-hero { flex: 999 1 620px; min-width: 0; position: relative; background: var(--px-surface); border: 1px solid var(--px-line); border-radius: 24px; overflow: hidden; }
  .px-slide { display: none; flex-wrap: wrap; gap: 28px; align-items: center; padding: 28px 22px 64px; }
  .px-slide.is-active { display: flex; }
  @media (min-width: 768px) { .px-slide { padding: 44px 44px 72px; } }
  .px-hero-copy { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 16px; align-items: flex-start; }
  .px-hero-title { margin: 0; font-family: var(--px-display); font-weight: 700; font-size: 38px; line-height: 1.02; letter-spacing: -0.03em; color: var(--px-ink); }
  @media (min-width: 768px) { .px-hero-title { font-size: 52px; } }
  @media (min-width: 1280px) { .px-hero-title { font-size: 60px; } }
  .px-hero-sub { margin: 0; font-size: 16px; line-height: 1.55; color: var(--px-soft); max-width: 440px; }
  .px-hero-price { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px 12px; }
  .px-hero-price b { font-family: var(--px-display); font-size: 26px; font-weight: 600; }
  .px-hero-price s { color: var(--px-faint); }
  .px-hero-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px; }
  .px-hero-media { flex: 1 1 280px; min-width: 0; position: relative; height: 300px; border-radius: 20px; background: var(--px-raised); display: grid; place-items: center; overflow: hidden; }
  @media (min-width: 768px) { .px-hero-media { height: 400px; } }
  .px-hero-media img { width: 100%; height: 100%; object-fit: cover; }
  .px-hero-media img.is-product { object-fit: contain; padding: 24px; }
  .px-video-btn { position: absolute; inset-inline-start: 16px; bottom: 16px; height: 40px; padding-inline: 8px 14px; border-radius: 99px; border: 1px solid var(--px-line-strong); background: var(--px-surface); color: var(--px-ink); font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
  .px-video-btn span { width: 26px; height: 26px; border-radius: 99px; background: var(--px-ink); color: var(--px-ground); display: grid; place-items: center; }
  .px-video-btn svg { width: 10px; height: 10px; }
  .px-chip { position: absolute; inset-inline-end: 16px; top: 16px; padding: 6px 12px; border-radius: 99px; background: var(--px-surface); border: 1px solid var(--px-input-line); font-size: 12.5px; display: inline-flex; gap: 6px; align-items: center; color: var(--px-ink); }
  .px-chip svg, .px-stars svg { width: 13px; height: 13px; color: var(--px-deal); }
  .px-hero-nav { position: absolute; inset-inline-start: 22px; bottom: 22px; display: flex; align-items: center; gap: 8px; }
  @media (min-width: 768px) { .px-hero-nav { inset-inline-start: 44px; bottom: 28px; } }
  .px-hero-nav button.px-dot { width: 6px; height: 6px; padding: 0; border: 0; border-radius: 99px; background: var(--px-line-strong); cursor: pointer; transition: width .2s, background .2s; }
  .px-hero-nav button.px-dot.is-active { width: 28px; background: var(--px-accent); }
  .px-hero-nav .px-count-label { margin-inline-start: 10px; font-family: var(--px-mono); font-size: 12px; color: var(--px-muted); }
  .px-tiles { flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
  .px-tile { flex: 1; display: flex; gap: 18px; align-items: center; background: var(--px-surface); border: 1px solid var(--px-line); border-radius: 24px; padding: 24px; color: var(--px-ink); transition: border-color .15s; min-height: 180px; }
  .px-tile:hover { border-color: var(--px-line-strong); color: var(--px-ink); }
  .px-tile-copy { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
  .px-tile-title { font-family: var(--px-display); font-size: 22px; font-weight: 600; letter-spacing: -0.02em; line-height: 1.15; }
  .px-tile-sub { color: var(--px-muted); font-size: 13.5px; }
  .px-tile-media { width: 132px; height: 132px; border-radius: 18px; background: var(--px-raised); flex: none; overflow: hidden; display: grid; place-items: center; }
  .px-tile-media img { width: 100%; height: 100%; object-fit: cover; }
  .px-tile-media img.is-product { object-fit: contain; padding: 10px; }
  .px-off { display: inline-block; padding: 3px 9px; border-radius: 7px; background: var(--px-deal-bg); color: var(--px-deal); font-size: 12px; font-weight: 700; line-height: 1.35; }
  .px-new { display: inline-block; padding: 3px 9px; border-radius: 7px; background: var(--px-accent); color: var(--px-on-accent); font-size: 12px; font-weight: 700; line-height: 1.35; }
  .px-price { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
  .px-price b { font-family: var(--px-display); font-weight: 600; font-size: 20px; color: var(--px-ink); }
  .px-price s { color: var(--px-faint); font-size: 13px; }
  .px-empty-hero { padding: 48px 28px; }

  /* Video dialog */
  .px-video-dialog { position: fixed; inset: 0; z-index: 90; background: rgba(0,0,0,.82); display: grid; place-items: center; padding: 16px; }
  .px-video-dialog > div { width: min(960px, 100%); aspect-ratio: 16 / 9; position: relative; }
  .px-video-dialog iframe { width: 100%; height: 100%; border: 0; border-radius: 16px; background: #000; }
  .px-video-close { position: absolute; top: -52px; inset-inline-end: 0; width: 44px; height: 44px; border-radius: 12px; border: 1px solid var(--px-line-strong); background: var(--px-surface); color: var(--px-ink); display: grid; place-items: center; cursor: pointer; }
  .px-video-close svg { width: 18px; height: 18px; }

  /* ---------- Trust strip ---------- */
  .px-trust { margin-top: 16px; border: 1px solid var(--px-line); border-radius: 18px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
  .px-trust-item { padding: 18px 22px; display: flex; gap: 14px; align-items: center; min-width: 0; }
  .px-trust-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--px-raised); display: grid; place-items: center; color: var(--px-accent-text); flex: none; }
  .px-trust-icon svg { width: 20px; height: 20px; }
  .px-trust-item strong { display: block; font-weight: 600; font-size: 14px; color: var(--px-ink); }
  .px-trust-item span { display: block; font-size: 13px; color: var(--px-muted); }

  /* ---------- Countdown ---------- */
  .px-countdown { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .px-countdown > span:first-child { color: var(--px-muted); font-size: 13px; }
  .px-count { display: flex; gap: 6px; align-items: center; font-family: var(--px-mono); font-weight: 600; font-size: 20px; color: var(--px-deal); }
  .px-count b { min-width: 48px; height: 48px; padding: 0 6px; border-radius: 12px; background: var(--px-count-bg); border: 1px solid var(--px-deal-line); display: grid; place-items: center; font-weight: 600; }

  /* ---------- Product grid + card ---------- */
  .px-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  @media (min-width: 768px) { .px-grid { grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; } }
  .product-card.px-card { position: relative; display: flex; flex-direction: column; gap: 10px; background: var(--px-surface); border: 1px solid var(--px-line); border-radius: 20px; padding: 12px; box-shadow: none; transition: border-color .15s; min-width: 0; }
  @media (min-width: 768px) { .product-card.px-card { padding: 14px; } }
  .product-card.px-card:hover { border-color: var(--px-line-strong); }
  .px-card-media { position: relative; display: block; aspect-ratio: 1 / 1; border-radius: 14px; background: var(--px-raised); overflow: hidden; }
  .px-card-media img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s; }
  .product-card.px-card:hover .px-card-media img { transform: scale(1.04); }
  .px-card-badges { position: absolute; top: 10px; inset-inline-start: 10px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
  .px-card-badges .px-out { background: var(--px-input-line); color: var(--px-ink); }
  .px-card-badges .px-pre { background: var(--px-pre-bg); color: var(--px-deal); }
  .px-card-badges span { padding: 3px 9px; border-radius: 7px; font-size: 11.5px; font-weight: 700; line-height: 1.35; }
  .px-card-quick { position: absolute; top: 8px; inset-inline-end: 8px; width: 36px; height: 36px; border-radius: 10px; border: 0; background: var(--px-surface); color: var(--px-soft); display: grid; place-items: center; cursor: pointer; opacity: 0; transition: opacity .15s; }
  .product-card.px-card:hover .px-card-quick, .px-card-quick:focus-visible { opacity: 1; }
  @media (hover: none) { .px-card-quick { opacity: 1; } }
  .px-card-quick svg { width: 17px; height: 17px; }
  .px-card-brand { font-family: var(--px-mono); font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--px-muted); min-height: 16px; }
  .px-card-title { margin: 0; font-weight: 600; font-size: 14.5px; line-height: 1.35; color: var(--px-ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .px-card-title a { color: inherit; }
  .px-card-title a:hover { color: var(--px-accent-text); }
  .px-chips { display: flex; flex-wrap: wrap; gap: 5px; }
  .px-chips span { padding: 3px 8px; border-radius: 6px; background: var(--px-raised); font-family: var(--px-mono); font-size: 11px; color: var(--px-soft); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .px-stars { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; color: var(--px-muted); }
  .px-stars b { color: var(--px-ink); font-weight: 600; }
  .px-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; }
  .px-add { width: 42px; height: 42px; border-radius: 12px; border: 0; background: var(--px-accent); color: var(--px-on-accent); display: grid; place-items: center; cursor: pointer; flex: none; }
  .px-add:hover:not(:disabled) { filter: brightness(1.08); }
  .px-add:disabled { opacity: .4; cursor: not-allowed; }
  .px-add.is-pre { background: var(--px-deal); color: var(--px-on-deal); }
  .px-add svg { width: 19px; height: 19px; }
  .px-card-stock { font-size: 12px; color: var(--px-muted); }
  .px-card .js-add-status { font-size: 11.5px; color: var(--px-ok); min-height: 0; }
  .px-card .js-add-status:empty { display: none; }
  .px-cmp-toggle { display: flex; gap: 8px; align-items: center; font-size: 12.5px; color: var(--px-muted); border-top: 1px solid var(--px-line); padding-top: 10px; cursor: pointer; min-height: 30px; }
  .px-cmp-toggle input { width: 16px; height: 16px; accent-color: var(--px-accent); }
  .px-cmp-toggle.is-on { color: var(--px-accent-text); }
  .px-sold { display: flex; flex-direction: column; gap: 6px; }
  .px-sold-bar { height: 6px; border-radius: 99px; background: var(--px-line); overflow: hidden; }
  .px-sold-bar i { display: block; height: 100%; border-radius: 99px; background: var(--px-deal); }
  .px-sold span { font-size: 12px; color: var(--px-muted); }
  .px-quote { width: 100%; height: auto; min-height: 40px; padding: 8px 12px; white-space: normal; font-size: 13px; }

  /* ---------- Categories ---------- */
  .px-cats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
  .px-cat { background: var(--px-surface); border: 1px solid var(--px-line); border-radius: 18px; padding: 20px 14px; display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; color: var(--px-ink); transition: border-color .15s; }
  .px-cat:hover { border-color: var(--px-line-strong); color: var(--px-ink); }
  .px-cat-media { width: 72px; height: 72px; border-radius: 99px; background: var(--px-raised); display: grid; place-items: center; color: var(--px-soft); overflow: hidden; }
  .px-cat-media img { width: 100%; height: 100%; object-fit: cover; }
  .px-cat-media svg { width: 30px; height: 30px; }
  .px-cat strong { font-weight: 600; font-size: 14px; line-height: 1.25; }
  .px-cat small { font-size: 12.5px; color: var(--px-muted); }

  /* ---------- Tabs ---------- */
  .px-tabs { display: flex; flex-wrap: wrap; gap: 4px; padding: 4px; border-radius: 12px; background: var(--px-surface); border: 1px solid var(--px-line); }
  .px-tab { height: 40px; padding: 0 16px; border-radius: 9px; border: 0; background: transparent; color: var(--px-soft); font-weight: 600; font-size: 14px; cursor: pointer; }
  .px-tab[aria-selected="true"] { background: var(--px-ink); color: var(--px-ground); }

  /* ---------- Compare promo ---------- */
  .px-cmp { background: var(--px-surface); border: 1px solid var(--px-line); border-radius: 24px; padding: 28px; display: flex; flex-wrap: wrap; gap: 24px 40px; align-items: center; }
  @media (min-width: 768px) { .px-cmp { padding: 40px; } }
  .px-cmp-copy { flex: 1 1 300px; display: flex; flex-direction: column; gap: 14px; align-items: flex-start; }
  .px-cmp-copy p { margin: 0; color: var(--px-muted); max-width: 400px; }
  .px-cmp-steps { flex: 1 1 360px; display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; counter-reset: pxstep; }
  .px-cmp-steps li { display: flex; gap: 14px; align-items: center; padding: 14px 16px; border: 1px solid var(--px-line); border-radius: 14px; color: var(--px-soft); counter-increment: pxstep; }
  .px-cmp-steps li::before { content: counter(pxstep); width: 30px; height: 30px; border-radius: 99px; background: var(--px-raised); color: var(--px-accent-text); font-family: var(--px-mono); font-weight: 600; display: grid; place-items: center; flex: none; }

  /* ---------- Brands ---------- */
  .px-brands { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
  .px-brand { height: 84px; border: 1px solid var(--px-line); border-radius: 16px; display: grid; place-items: center; padding: 0 16px; font-family: var(--px-display); font-weight: 700; font-size: 18px; letter-spacing: .06em; color: var(--px-muted); text-align: center; transition: border-color .15s, color .15s; }
  .px-brand:hover { border-color: var(--px-line-strong); color: var(--px-ink); }
  .px-brand img { max-height: 40px; max-width: 120px; object-fit: contain; }
  .px-label { margin: 0 0 18px; font-family: var(--px-mono); font-size: 13px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--px-muted); }

  /* ---------- Recently viewed ---------- */
  .px-recent { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: thin; }
  .px-recent a { flex: 0 0 240px; display: flex; gap: 12px; align-items: center; padding: 10px; border: 1px solid var(--px-line); border-radius: 16px; background: var(--px-surface); color: var(--px-ink); }
  .px-recent a:hover { border-color: var(--px-line-strong); color: var(--px-ink); }
  .px-recent img { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; background: var(--px-raised); flex: none; }
  .px-recent span { display: flex; flex-direction: column; min-width: 0; }
  .px-recent b { font-weight: 600; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .px-recent small { font-size: 13px; color: var(--px-soft); }
  .px-clear { background: none; border: 0; color: var(--px-muted); font: inherit; font-size: 14px; cursor: pointer; min-height: 44px; }
  .px-clear:hover { color: var(--px-ink); }

  /* ---------- Newsletter ---------- */
  .px-nl { border: 1px solid var(--px-hilite-line); background: var(--px-hilite-bg); border-radius: 24px; padding: 28px; display: flex; flex-wrap: wrap; gap: 20px 40px; align-items: center; justify-content: space-between; }
  @media (min-width: 768px) { .px-nl { padding: 40px; } }
  .px-nl h2 { margin: 0; font-family: var(--px-display); font-size: 28px; letter-spacing: -0.02em; }
  .px-nl p { margin: 8px 0 0; color: var(--px-muted); }
  .px-nl form { flex: 1 1 380px; display: flex; flex-wrap: wrap; gap: 8px; }
  .px-nl input { flex: 1 1 220px; min-width: 0; height: 50px; border-radius: 12px; border: 1px solid var(--px-input-line); background: var(--px-ground); color: var(--px-ink); padding: 0 16px; font-size: 14px; }
  .px-nl input:focus { border-color: var(--px-accent-text); outline: none; }
  .px-nl-note { flex-basis: 100%; font-size: 12px; color: var(--px-muted); }

  /* ---------- Footer ---------- */
  .px-footer { margin-top: 72px; border-top: 1px solid var(--px-hair); background: var(--px-ground); }
  .px-footer-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 32px; padding: 48px 0 28px; }
  .px-footer-brand p { color: var(--px-muted); font-size: 13.5px; line-height: 1.6; margin: 12px 0 16px; max-width: 320px; }
  .px-footer h2 { margin: 0 0 12px; font-family: 'Inter', system-ui, sans-serif; font-size: 14px; font-weight: 600; letter-spacing: 0; color: var(--px-ink); }
  .px-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .px-footer li a, .px-footer li button { color: var(--px-muted); font-size: 13.5px; background: none; border: 0; padding: 0; cursor: pointer; text-align: start; }
  .px-footer li a:hover, .px-footer li button:hover { color: var(--px-ink); }
  .px-footer-contact li { display: flex; gap: 9px; align-items: flex-start; color: var(--px-muted); font-size: 13.5px; }
  .px-footer-contact svg { width: 15px; height: 15px; color: var(--px-accent-text); flex: none; margin-top: 3px; }
  .px-social { display: flex; gap: 8px; }
  .px-social a { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: var(--px-raised); color: var(--px-soft); }
  .px-social a:hover { background: var(--px-accent); color: var(--px-on-accent); }
  .px-social svg { width: 15px; height: 15px; }
  .px-footer-bottom { border-top: 1px solid var(--px-hair); padding: 20px 0 32px; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; font-size: 12.5px; color: var(--px-muted); }
  .px-pay { display: flex; gap: 6px; flex-wrap: wrap; }
  .px-pay span { padding: 4px 10px; border: 1px solid var(--px-input-line); border-radius: 6px; }
  @media (max-width: 1023.98px) { .px-footer { padding-bottom: 5rem; } }

  /* ---------- Product page additions ---------- */
  .px-hl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 16px; }
  .px-hl > div { padding: 12px 14px; border-radius: 14px; border: 1px solid var(--px-line); display: flex; flex-direction: column; min-width: 0; }
  .px-hl span { font-family: var(--px-mono); font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--px-muted); }
  .px-hl b { font-weight: 600; color: var(--px-ink); overflow-wrap: anywhere; }
  .px-spec-groups { display: grid; gap: 16px; margin-bottom: 20px; max-width: 860px; }
  .px-spec-group { border: 1px solid var(--px-line); border-radius: 16px; overflow: hidden; }
  .px-spec-group .px-kicker { display: block; padding: 12px 16px; background: var(--px-surface); }
  .px-spec-group dl { margin: 0; }
  .px-spec-group dl > div { display: grid; grid-template-columns: minmax(120px, 1fr) 2fr; border-top: 1px solid var(--px-line); }
  .px-spec-group dt { padding: 11px 16px; color: var(--px-muted); font-size: 14px; }
  .px-spec-group dd { margin: 0; padding: 11px 16px; color: var(--px-ink); font-size: 14px; overflow-wrap: anywhere; }
  .px-bundle { margin-top: 48px; background: var(--px-surface); border: 1px solid var(--px-line); border-radius: 24px; padding: 20px; }
  @media (min-width: 768px) { .px-bundle { padding: 28px; } }
  .px-bundle-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
  .px-bundle-plus-wrap { display: flex; align-items: center; gap: 14px; flex: 1 1 280px; min-width: 0; }
  .px-bundle-plus { font-size: 22px; color: var(--px-dim); }
  .px-bundle-item { flex: 1 1 260px; min-width: 0; display: flex; gap: 12px; align-items: center; padding: 12px; border-radius: 16px; border: 1px solid var(--px-hilite-line); background: var(--px-hilite-bg); cursor: pointer; transition: opacity .15s; }
  .px-bundle-item.is-off { opacity: .55; border-color: var(--px-line); background: transparent; }
  .px-bundle-item input { width: 18px; height: 18px; accent-color: var(--px-accent); flex: none; align-self: flex-start; }
  .px-bundle-item img { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; background: var(--px-raised); flex: none; }
  .px-bundle-item > span { display: flex; flex-direction: column; min-width: 0; }
  .px-bundle-item b, .px-bundle-item a { font-weight: 600; font-size: 14px; color: var(--px-ink); overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .px-bundle-item a:hover { color: var(--px-accent-text); }
  .px-bundle-item small { color: var(--px-soft); font-size: 13px; }
  .px-bundle-total { margin-inline-start: auto; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; min-width: 200px; }
  .px-bundle-total b { font-family: var(--px-display); font-size: 28px; font-weight: 600; }
  @media (max-width: 767.98px) { .px-bundle-total { align-items: stretch; width: 100%; } .px-bundle-total b { font-size: 24px; } }
  .px-table-wrap { overflow-x: auto; border: 1px solid var(--px-line); border-radius: 16px; }
  .px-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 14px; }
  .px-table th, .px-table td { padding: 12px 16px; text-align: start; vertical-align: top; border-top: 1px solid var(--px-line); }
  .px-table thead th, .px-table thead td { border-top: 0; background: var(--px-surface); }
  .px-table tbody th { color: var(--px-muted); font-weight: 500; white-space: nowrap; }
  .px-table td { color: var(--px-ink); }
  .px-table .is-current { background: rgb({{ $pxPal['fill_rgb'] }} / .07); }
  .px-table-prod { display: flex; flex-direction: column; gap: 8px; color: var(--px-ink); font-weight: 600; }
  .px-table-prod:hover { color: var(--px-accent-text); }
  .px-table-prod img { width: 72px; height: 72px; border-radius: 12px; object-fit: cover; background: var(--px-raised); }
  .px-table .px-kicker { display: block; margin-top: 4px; font-size: 11px; }
  .px-sticky { position: fixed; inset-inline: 0; bottom: calc(64px + env(safe-area-inset-bottom)); z-index: 29; display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: var(--px-surface); border-top: 1px solid var(--px-input-line); transform: translateY(120%); transition: transform .2s; }
  .px-sticky.is-on { transform: none; }
  .px-sticky .px-btn { flex: 1; height: 48px; }
  .px-sticky-price { display: flex; flex-direction: column; line-height: 1.15; }
  .px-sticky-price b { font-family: var(--px-display); font-size: 20px; font-weight: 600; }
  .px-sticky-price s { font-size: 12px; color: var(--px-faint); }
  .px-sticky-spacer { height: 72px; }
  @media (min-width: 1024px) { .px-sticky, .px-sticky-spacer { display: none; } }

  /* ---------- Compare tray + page ---------- */
  .px-tray { position: fixed; inset-inline: 0; bottom: calc(64px + env(safe-area-inset-bottom)); z-index: 28; background: var(--px-surface); border-top: 1px solid var(--px-hilite-line); box-shadow: 0 -16px 40px -20px var(--px-shadow-c); }
  @media (min-width: 1024px) { .px-tray { bottom: 0; } }
  .px-tray .px-container { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding-top: 10px; padding-bottom: 10px; }
  .px-tray-items { display: flex; gap: 8px; flex: 1 1 320px; min-width: 0; overflow-x: auto; }
  .px-tray-item { display: inline-flex; align-items: center; gap: 8px; height: 48px; padding-inline: 6px 4px; border-radius: 12px; background: var(--px-raised); font-size: 13px; flex: none; max-width: 240px; }
  .px-tray-item img { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; background: var(--px-line); }
  .px-tray-item a { color: var(--px-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .px-tray-item button { width: 32px; height: 32px; border: 0; background: none; color: var(--px-muted); cursor: pointer; display: grid; place-items: center; flex: none; }
  .px-tray-item button svg { width: 14px; height: 14px; }
  .px-tray .is-disabled { opacity: .5; pointer-events: none; }
  @media (max-width: 1023.98px) { body:has(.px-sticky.is-on) .px-tray { display: none; } }
  .px-compare { min-width: 760px; table-layout: fixed; }
  .px-compare thead td:first-child, .px-compare tbody th { width: 170px; }
  .px-compare thead th { vertical-align: top; }
  .px-compare .px-card-media { aspect-ratio: auto; height: 170px; }
  .px-compare thead .product-card.px-card { background: transparent; border: 0; padding: 0; }
  .px-compare tr.is-diff th { color: var(--px-accent-text); }
  .px-compare tr.is-diff td { background: rgb({{ $pxPal['fill_rgb'] }} / .06); }

  [x-cloak] { display: none !important; }
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; scroll-behavior: auto !important; } }
</style>
@php
  $pxI18n = [
    'compare' => __('messages.PxCompareProducts'),
    'compareFull' => __('messages.PxCompareFull', ['max' => 4]),
    'inCompare' => __('messages.PxInCompare'),
    'addCompare' => __('messages.PxAddToCompare'),
    'toDark' => __('messages.PxSwitchToDark'),
    'toLight' => __('messages.PxSwitchToLight'),
  ];
@endphp
<script>
  window.PxI18n = {!! json_encode($pxI18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

  // ----- Compare list (up to 4 products, this browser only) -----
  window.PxCompare = {
    key: 'px.compare', max: 4,
    list: function () {
      try { var v = JSON.parse(localStorage.getItem(this.key) || '[]'); return Array.isArray(v) ? v.filter(function (x) { return x && x.id; }) : []; } catch (e) { return []; }
    },
    save: function (l) {
      try { localStorage.setItem(this.key, JSON.stringify(l)); } catch (e) {}
      window.dispatchEvent(new CustomEvent('px:compare', { detail: l }));
    },
    has: function (id) { return this.list().some(function (x) { return Number(x.id) === Number(id); }); },
    toggle: function (item) {
      var l = this.list();
      var i = l.findIndex(function (x) { return Number(x.id) === Number(item.id); });
      if (i >= 0) { l.splice(i, 1); this.save(l); return 'removed'; }
      if (l.length >= this.max) return 'full';
      l.push(item); this.save(l); return 'added';
    },
    remove: function (id) { this.save(this.list().filter(function (x) { return Number(x.id) !== Number(id); })); },
    clear: function () { this.save([]); }
  };
  window.addEventListener('storage', function (e) {
    if (e.key === 'px.compare') window.dispatchEvent(new CustomEvent('px:compare', { detail: window.PxCompare.list() }));
    if (e.key === 'px.recent') window.dispatchEvent(new CustomEvent('px:recent', { detail: window.PxRecent.list() }));
  });
  window.pxCompareBadge = function () {
    return {
      count: window.PxCompare.list().length,
      get label() { return window.PxI18n.compare + (this.count ? ' (' + this.count + ')' : ''); },
      init: function () { var self = this; window.addEventListener('px:compare', function (e) { self.count = e.detail.length; }); }
    };
  };
  window.pxCompareToggle = function (item) {
    return {
      on: window.PxCompare.has(item.id),
      get text() { return this.on ? window.PxI18n.inCompare : window.PxI18n.addCompare; },
      init: function () { var self = this; window.addEventListener('px:compare', function () { self.on = window.PxCompare.has(item.id); }); },
      toggle: function (e) {
        var r = window.PxCompare.toggle(item);
        if (r === 'full') {
          if (e && e.target) e.target.checked = false;
          if (window.StoreDialog) window.StoreDialog.alert(window.PxI18n.compareFull); else alert(window.PxI18n.compareFull);
        }
        this.on = window.PxCompare.has(item.id);
      }
    };
  };

  // ----- Light / dark toggle (light is the default; the choice is remembered) -----
  window.pxThemeToggle = function () {
    var isDark = function () { return document.documentElement.classList.contains('dark'); };
    return {
      dark: isDark(),
      get label() { return this.dark ? window.PxI18n.toLight : window.PxI18n.toDark; },
      init: function () {
        var self = this;
        // storefront.js / the layout may adjust the class after Alpine starts.
        if (window.MutationObserver) new MutationObserver(function () { self.dark = isDark(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
      },
      toggle: function () {
        var next = isDark() ? 'light' : 'dark';
        try { localStorage.setItem('store.theme', next); } catch (e) {}
        if (window.StoreTheme && window.StoreTheme.set) window.StoreTheme.set(next);
        else document.documentElement.classList.toggle('dark', next === 'dark');
        this.dark = isDark();
      }
    };
  };

  // ----- Countdown to a real end time (flash sale ends_at) -----
  window.pxCountdown = function (iso) {
    var end = Date.parse(iso);
    return {
      d: 0, hh: '--', mm: '--', ss: '--', t: null,
      init: function () { var self = this; this.tick(); this.t = setInterval(function () { self.tick(); }, 1000); },
      destroy: function () { clearInterval(this.t); },
      tick: function () {
        var left = Math.max(0, Math.floor(((isNaN(end) ? 0 : end) - Date.now()) / 1000));
        var pad = function (n) { return String(n).padStart(2, '0'); };
        this.d = Math.floor(left / 86400);
        this.hh = pad(Math.floor((left % 86400) / 3600));
        this.mm = pad(Math.floor((left % 3600) / 60));
        this.ss = pad(left % 60);
        if (left === 0) clearInterval(this.t);
      }
    };
  };

  // ----- Recently viewed (last 12 product pages, this browser only) -----
  window.PxRecent = {
    key: 'px.recent', max: 12,
    list: function () {
      try { var v = JSON.parse(localStorage.getItem(this.key) || '[]'); return Array.isArray(v) ? v.filter(function (x) { return x && x.id && x.url; }) : []; } catch (e) { return []; }
    },
    push: function (item) {
      var l = this.list().filter(function (x) { return Number(x.id) !== Number(item.id); });
      l.unshift(item);
      try { localStorage.setItem(this.key, JSON.stringify(l.slice(0, this.max))); } catch (e) {}
    },
    clear: function () {
      try { localStorage.removeItem(this.key); } catch (e) {}
      window.dispatchEvent(new CustomEvent('px:recent', { detail: [] }));
    }
  };
  window.pxRecent = function (excludeId) {
    return {
      items: window.PxRecent.list().filter(function (x) { return Number(x.id) !== Number(excludeId || 0); }),
      init: function () { var self = this; window.addEventListener('px:recent', function (e) { self.items = e.detail; }); },
      clear: function () { window.PxRecent.clear(); }
    };
  };

  // ----- Video dialog (product page; plain DOM, no Alpine scope needed) -----
  window.pxOpenVideo = function (src) {
    if (!/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(String(src))) return;
    var back = document.activeElement;
    var wrap = document.createElement('div');
    wrap.className = 'px-video-dialog';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-label', @js(__('messages.PxWatchVideo')));
    var box = document.createElement('div');
    var close = document.createElement('button');
    close.type = 'button'; close.className = 'px-video-close';
    close.setAttribute('aria-label', @js(__('messages.Close')));
    close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    var frame = document.createElement('iframe');
    frame.src = src; frame.title = @js(__('messages.PxWatchVideo'));
    frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen'; frame.allowFullscreen = true;
    box.appendChild(close); box.appendChild(frame); wrap.appendChild(box);
    function done() { document.removeEventListener('keydown', onKey); wrap.remove(); if (back && back.focus) back.focus(); }
    function onKey(e) { if (e.key === 'Escape') done(); }
    close.addEventListener('click', done);
    wrap.addEventListener('click', function (e) { if (e.target === wrap) done(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);
    close.focus();
  };

  // Card links without a product page fall back to the card's quick view.
  document.addEventListener('click', function (e) {
    var a = e.target.closest('.product-card a[href="#"]');
    if (!a) return;
    e.preventDefault();
    var card = a.closest('.product-card');
    var btn = card ? card.querySelector('.js-quick-view') : null;
    if (btn) btn.click();
  });
</script>
