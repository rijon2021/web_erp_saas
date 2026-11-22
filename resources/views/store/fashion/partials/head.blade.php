{{-- Fashion & Apparel theme skin. Embedded CSS (no Tailwind rebuild):
     editorial black-on-white with a serif display face and one accent for
     sale; `fa-*` components for header, footer and homepage; shared tokens
     re-tinted so every store page (shop, product, cart, account) matches. --}}
@php
  $faAccent = $s->primary_color ?: '#8a1c2b';
  // Stock defaults of the other themes read as "unset" here.
  if (in_array(strtolower($faAccent), ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#0f766e', '#1a7f6b', '#16a34a'], true)) { $faAccent = '#8a1c2b'; }
  $faHex = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '138 28 43';
      return hexdec(substr($hex,0,2)).' '.hexdec(substr($hex,2,2)).' '.hexdec(substr($hex,4,2));
  };
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400;0,6..96,500;1,6..96,400&family=Jost:wght@400;500;600&display=swap">
<style>
  :root {
    --color-bg-base: 255 255 255;
    --color-bg-surface: 250 248 245;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted: 244 241 236;
    --color-border-subtle: 228 224 218;
    --color-border-strong: 205 199 190;
    --color-fg-primary:   20 20 20;
    --color-fg-secondary: 74 74 74;
    --color-fg-muted:     107 107 107;
    --color-accent-400: 20 20 20;
    --color-accent-500: 20 20 20;
    --color-accent-600: 51 51 51;
    --color-accent-glow: rgba(20, 20, 20, 0.15);
    --fa-ink: #141414;
    --fa-ink-rgb: 20,20,20;
    --fa-muted: #6b6b6b;
    --fa-line: #e4e0da;
    --fa-stone: #f4f1ec;
    --fa-page: #ffffff;
    --fa-card: #ffffff;
    --fa-out: #757575;
    --fa-accent: {{ $faAccent }};
    --fa-accent-rgb: {{ str_replace(' ', ',', $faHex($faAccent)) }};
    --fa-serif: 'Bodoni Moda', Georgia, 'Times New Roman', serif;
  }
  .dark {
    --color-accent-400: 242 239 233; --color-accent-500: 242 239 233; --color-accent-600: 214 210 204;
    --fa-ink: #f2efe9; --fa-ink-rgb: 242,239,233; --fa-muted: #a8a29a; --fa-line: #2c2a27; --fa-stone: #1c1a18; --fa-page: #121110; --fa-card: #121110; --fa-out: #8c867e;
  }
  /* Dark mode turns the accent near-white, so the shared white-on-accent
     components need dark text to stay readable. */
  .dark .btn-primary, .dark .btn-primary:hover:not(:disabled), .dark .btn-accent-soft:hover:not(:disabled),
  .dark .page-link.is-active, .dark .page-link.active, .dark .product-action-btn:hover { color: #141414; }
  body { font-family: 'Jost', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--fa-page); }
  .section-title, .hero-title, .fa-h2, .fa-display { font-family: var(--fa-serif); font-weight: 400; letter-spacing: -0.01em; }
  .btn { border-radius: 0; letter-spacing: .08em; }
  .btn-primary { box-shadow: none; }
  .card { border-radius: 0; }
  .input, .select, .textarea { border-radius: 0; }
  .drawer-panel, .dialog-panel, .auth-panel { border-radius: 0; }
  .newsletter-panel { border-radius: 0; }
  .chip { border-radius: 0; }
  .price, .price-lg, .price-xl, .price-sm, .price-compare { font-family: 'Jost', system-ui, sans-serif; font-weight: 500; letter-spacing: 0; }

  /* Shared product card (shop, search, related) in the fashion look. */
  .product-card { border: 0; border-radius: 0; box-shadow: none !important; background: transparent; transform: none !important; }
  .product-card .product-media { aspect-ratio: 3 / 4; background: var(--fa-stone); }
  .product-card .product-body { padding: 14px 0 0; gap: 6px; }
  .product-card .product-title { font-size: 15px; font-weight: 400; min-height: 0; }
  .product-badge { border-radius: 0; font-family: 'Jost', sans-serif; font-weight: 500; letter-spacing: .14em; }
  .product-badge-sale { background: var(--fa-accent); }
  .product-action-btn { border-radius: 999px; }

  /* Product page (shared store/product.blade.php) in the fashion look. */
  .pdp-title { font-family: var(--fa-serif); font-weight: 400 !important; letter-spacing: -0.01em; line-height: 1.1; }
  @media (min-width: 1024px) { .pdp-title { font-size: 40px !important; } }
  .pdp-gallery-main { border: 0 !important; border-radius: 0 !important; background: var(--fa-stone) !important; }
  .pdp-gallery-main img { aspect-ratio: 3 / 4; object-fit: cover !important; max-height: none !important; width: 100%; }
  .pdp-thumb { border-radius: 0 !important; width: 72px !important; height: 96px !important; }
  .pdp-thumb.border-accent-500 { border-color: var(--fa-ink) !important; }
  #pdpPrice { font-weight: 500 !important; font-size: 24px !important; }
  .pdp-qty { border-radius: 0 !important; border-color: var(--fa-line) !important; }
  .chip-danger { color: #fff; background: var(--fa-accent); border-color: var(--fa-accent); }
  #pdpOptionGroups button, .pdp-variant { border-radius: 0 !important; min-width: 48px; }
  #pdpTabs .pdp-tab { letter-spacing: .12em; text-transform: uppercase; font-size: 13px; }
  .sg-root .sg-card { border-radius: 0; }
  .sg-root .sg-title { font-family: var(--fa-serif); font-weight: 400; font-size: 1.6rem; }
  .sg-root .sg-table thead th { background: var(--fa-ink); color: var(--fa-page); font-weight: 500; }
  main .pdp-size-guide-link, button.pdp-size-guide-link { color: var(--fa-ink); }
  .facet-chips .facet-chip span { border-radius: 0; }

  /* ---------- layout ---------- */
  .fa-container { width: 100%; max-width: 1360px; margin: 0 auto; padding: 0 16px; }
  @media (min-width: 1024px) { .fa-container { padding: 0 40px; } }
  .fa-section { padding: 48px 0 0; }
  @media (min-width: 1024px) { .fa-section { padding: 96px 0 0; } }
  .fa-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
  @media (min-width: 1024px) { .fa-head { margin-bottom: 32px; } }
  .fa-kicker { display: block; font-size: 12px; letter-spacing: .22em; text-transform: uppercase; color: var(--fa-muted); font-weight: 500; margin-bottom: 10px; }
  .fa-h2 { font-size: 32px; line-height: 1.1; color: var(--fa-ink); margin: 0; }
  @media (min-width: 1024px) { .fa-h2 { font-size: 44px; } }
  .fa-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; letter-spacing: .14em; text-transform: uppercase; color: var(--fa-ink); border-bottom: 1px solid var(--fa-ink); padding-bottom: 4px; white-space: nowrap; }
  .fa-link:hover { color: var(--fa-accent); border-color: var(--fa-accent); }
  .fa-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; height: 52px; padding: 0 32px; border: 1px solid var(--fa-ink); background: var(--fa-ink); color: var(--fa-page); font: 500 13px/1 'Jost', sans-serif; letter-spacing: .16em; text-transform: uppercase; cursor: pointer; transition: background .15s, color .15s, border-color .15s; white-space: nowrap; }
  .fa-btn:hover { background: transparent; color: var(--fa-ink); }
  .fa-btn-ghost { background: transparent; color: var(--fa-ink); }
  .fa-btn-ghost:hover { background: var(--fa-ink); color: var(--fa-page); }
  .fa-card-quote { width: 100%; height: auto; min-height: 40px; padding: 8px 12px; font-size: 11px; letter-spacing: .1em; line-height: 1.3; white-space: normal; text-align: center; }
  .fa-btn-light { background: #fff; color: #141414; border-color: #fff; }
  .fa-btn-light:hover { background: transparent; color: #fff; }
  .fa-btn svg { width: 16px; height: 16px; }

  /* ---------- announcement + header ---------- */
  .fa-announce { background: #141414; color: #fff; font-size: 12px; letter-spacing: .12em; text-transform: uppercase; }
  .fa-announce .fa-container { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 40px; }
  .fa-announce-mid { flex: 1; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .fa-announce-side { display: none; align-items: center; gap: 4px; }
  @media (min-width: 1024px) { .fa-announce-side { display: flex; min-width: 180px; } .fa-announce-side.is-end { justify-content: flex-end; } }
  .fa-announce-side a, .fa-announce-side button { color: #d6d2cc; padding: 4px 8px; background: transparent; border: 0; cursor: pointer; font: inherit; letter-spacing: inherit; text-transform: inherit; display: inline-flex; align-items: center; gap: 4px; }
  .fa-announce-side a:hover, .fa-announce-side button:hover { color: #fff; }
  .fa-announce-side svg { width: 12px; height: 12px; }
  .fa-header { position: sticky; top: 0; z-index: 40; background: var(--fa-page); border-bottom: 1px solid var(--fa-line); }
  .fa-header-main { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; height: 64px; gap: 12px; }
  @media (min-width: 1024px) { .fa-header-main { height: 84px; } }
  .fa-nav { display: none; align-items: center; gap: 4px 24px; flex-wrap: wrap; }
  @media (min-width: 1024px) { .fa-nav { display: flex; } }
  .fa-nav > li { position: relative; list-style: none; }
  .fa-nav-link { display: inline-flex; align-items: center; height: 40px; font-size: 13px; letter-spacing: .14em; text-transform: uppercase; font-weight: 500; color: var(--fa-ink); border-bottom: 1px solid transparent; }
  .fa-nav-link:hover, .fa-nav-link.is-active { border-bottom-color: var(--fa-ink); }
  .fa-nav-link.is-sale { color: var(--fa-accent); }
  .fa-nav-link.is-sale:hover { border-bottom-color: var(--fa-accent); }
  .fa-mega { display: none; position: absolute; top: 100%; inset-inline-start: -16px; min-width: 220px; background: var(--fa-card); border: 1px solid var(--fa-line); padding: 12px 16px; z-index: 50; box-shadow: 0 24px 40px -24px rgba(0,0,0,.25); }
  .fa-nav > li:hover > .fa-mega, .fa-nav > li:focus-within > .fa-mega { display: block; }
  .fa-mega a { display: block; padding: 7px 0; font-size: 14px; color: var(--fa-ink); }
  .fa-mega a:hover { color: var(--fa-accent); }
  .fa-mobile-left { display: flex; align-items: center; }
  @media (min-width: 1024px) { .fa-mobile-left { display: none; } }
  .fa-logo { justify-self: center; display: inline-flex; align-items: center; font-family: var(--fa-serif); font-weight: 500; font-size: 22px; letter-spacing: .22em; text-transform: uppercase; color: var(--fa-ink); white-space: nowrap; max-width: 46vw; overflow: hidden; text-overflow: ellipsis; }
  @media (min-width: 1024px) { .fa-logo { font-size: 30px; letter-spacing: .24em; } }
  .fa-logo img { height: 34px; max-width: 180px; object-fit: contain; }
  @media (min-width: 1024px) { .fa-logo img { height: 44px; max-width: 220px; } }
  .fa-actions { display: flex; align-items: center; justify-content: flex-end; gap: 2px; }
  .fa-icon-btn { position: relative; width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; background: transparent; border: 0; color: var(--fa-ink); cursor: pointer; padding: 0; }
  .fa-icon-btn:hover { color: var(--fa-accent); }
  .fa-icon-btn svg { width: 20px; height: 20px; }
  .fa-icon-btn img { width: 26px; height: 26px; border-radius: 50%; object-fit: cover; }
  .fa-count { position: absolute; top: 6px; inset-inline-end: 4px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 8px; background: var(--fa-ink); color: var(--fa-page); font-size: 10px; font-weight: 600; display: grid; place-items: center; line-height: 1; }
  .fa-hide-sm { display: none; }
  @media (min-width: 768px) { .fa-hide-sm { display: inline-flex; } }
  .fa-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 4px); min-width: 210px; background: var(--fa-card); border: 1px solid var(--fa-line); padding: 6px; z-index: 60; box-shadow: 0 24px 40px -24px rgba(0,0,0,.3); }
  .fa-menu a, .fa-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px; font-size: 14px; color: var(--fa-ink); background: transparent; border: 0; cursor: pointer; text-align: start; font-family: inherit; letter-spacing: 0; text-transform: none; }
  .fa-menu a:hover, .fa-menu button:hover { background: var(--fa-stone); }
  .fa-menu svg { width: 16px; height: 16px; color: var(--fa-muted); }
  .fa-menu hr { border: 0; border-top: 1px solid var(--fa-line); margin: 6px 0; }
  .fa-search-bar { border-top: 1px solid var(--fa-line); background: var(--fa-page); }
  .fa-search-bar form { position: relative; display: flex; align-items: center; gap: 12px; height: 64px; }
  .fa-search-bar input { flex: 1; min-width: 0; height: 44px; border: 0; border-bottom: 1px solid var(--fa-ink); background: transparent; font: 400 17px 'Jost', sans-serif; color: var(--fa-ink); outline: none; }
  .fa-search-results { position: absolute; top: 100%; left: 0; right: 0; z-index: 60; background: var(--fa-card); border: 1px solid var(--fa-line); max-height: 420px; overflow-y: auto; box-shadow: 0 24px 40px -24px rgba(0,0,0,.3); }
  .fa-search-results a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; color: var(--fa-ink); }
  .fa-search-results a:hover { background: var(--fa-stone); }
  .fa-search-results img { width: 40px; height: 52px; object-fit: cover; background: var(--fa-stone); }

  /* ---------- hero ---------- */
  .fa-hero { position: relative; display: grid; grid-template-columns: 1fr; background: var(--fa-stone); }
  @media (min-width: 1024px) { .fa-hero { grid-template-columns: 5fr 7fr; min-height: 640px; } }
  .fa-hero-copy { display: flex; flex-direction: column; justify-content: center; gap: 22px; padding: 40px 16px 48px; order: 2; }
  @media (min-width: 1024px) { .fa-hero-copy { padding: 72px 64px; order: 1; } }
  .fa-hero-title { font-family: var(--fa-serif); font-weight: 400; font-size: 40px; line-height: 1.04; letter-spacing: -0.01em; margin: 0; color: var(--fa-ink); }
  @media (min-width: 768px) { .fa-hero-title { font-size: 56px; } }
  @media (min-width: 1280px) { .fa-hero-title { font-size: 72px; } }
  .fa-hero-sub { margin: 0; font-size: 17px; line-height: 1.6; color: rgb(var(--color-fg-secondary)); max-width: 440px; }
  .fa-hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }
  .fa-hero-media { position: relative; order: 1; aspect-ratio: 4 / 5; overflow: hidden; background: var(--fa-line); }
  @media (min-width: 1024px) { .fa-hero-media { order: 2; aspect-ratio: auto; } }
  .fa-hero-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .fa-slide { display: none; }
  .fa-slide.is-active { display: contents; }
  .fa-hero-nav { display: flex; align-items: center; gap: 12px; margin-top: 12px; font-size: 13px; letter-spacing: .14em; color: var(--fa-muted); }
  .fa-hero-nav b { color: var(--fa-ink); font-weight: 600; }
  .fa-hero-nav span.fa-bar { width: 64px; height: 1px; background: var(--fa-ink); }
  .fa-hero-nav button { width: 36px; height: 36px; border: 1px solid var(--fa-line); background: transparent; color: var(--fa-ink); display: grid; place-items: center; cursor: pointer; }
  .fa-hero-nav button:hover { border-color: var(--fa-ink); }
  .fa-hero-nav button svg { width: 16px; height: 16px; }

  /* ---------- category tiles ---------- */
  .fa-cats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  @media (min-width: 1024px) { .fa-cats { grid-template-columns: repeat(var(--fa-cat-cols, 4), minmax(0, 1fr)); gap: 16px; } }
  .fa-cat { position: relative; display: block; aspect-ratio: 3 / 4; overflow: hidden; background: var(--fa-stone); color: var(--fa-ink); }
  .fa-cat img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform .8s ease; }
  .fa-cat:hover img { transform: scale(1.04); }
  .fa-cat-label { position: absolute; inset-inline: 10px; bottom: 10px; background: var(--fa-card); padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  @media (min-width: 1024px) { .fa-cat-label { inset-inline: 16px; bottom: 16px; padding: 16px 18px; } }
  .fa-cat-label strong { font-family: var(--fa-serif); font-weight: 400; font-size: 18px; }
  @media (min-width: 1024px) { .fa-cat-label strong { font-size: 22px; } }
  .fa-cat-label small { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--fa-muted); text-align: end; }

  /* ---------- tabs ---------- */
  .fa-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
  .fa-tab { height: 40px; padding: 0 18px; border: 1px solid var(--fa-line); background: var(--fa-card); color: var(--fa-ink); font: 500 12px 'Jost', sans-serif; letter-spacing: .14em; text-transform: uppercase; cursor: pointer; }
  .fa-tab:hover { border-color: var(--fa-ink); }
  .fa-tab.is-active { background: var(--fa-ink); border-color: var(--fa-ink); color: var(--fa-page); }

  /* ---------- product grid / card ---------- */
  .fa-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 32px 12px; }
  @media (min-width: 768px) { .fa-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 40px 20px; } }
  @media (min-width: 1024px) { .fa-grid { grid-template-columns: repeat(var(--fa-cols, 4), minmax(0, 1fr)); gap: 44px 24px; } }
  .product-card.fa-card { display: flex; flex-direction: column; background: transparent; border: 0; }
  .fa-card-wrap { position: relative; }
  .fa-card-media { position: relative; display: block; aspect-ratio: 3 / 4; overflow: hidden; background: var(--fa-stone); }
  .fa-card-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: opacity .45s ease, transform .8s ease; }
  .fa-card-media img.fa-alt { opacity: 0; }
  @media (hover: hover) {
    .product-card.fa-card:hover .fa-card-media img.fa-alt { opacity: 1; }
    .product-card.fa-card:hover .fa-card-media img { transform: scale(1.03); }
  }
  .fa-badges { position: absolute; top: 10px; inset-inline-start: 10px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; z-index: 2; pointer-events: none; }
  .fa-badge { display: inline-block; padding: 5px 9px; font-size: 10.5px; letter-spacing: .14em; text-transform: uppercase; font-weight: 500; color: #fff; background: #141414; }
  .fa-badge.is-sale { background: var(--fa-accent); }
  .fa-badge.is-out { background: #fff; color: #141414; }
  .fa-badge.is-pre { background: #9a5b2e; }
  .fa-card-actions { position: absolute; top: 6px; inset-inline-end: 6px; z-index: 2; }
  .fa-card-action { width: 40px; height: 40px; border-radius: 999px; border: 0; background: rgba(255,255,255,.92); color: #141414; display: grid; place-items: center; cursor: pointer; }
  .fa-card-action:hover { background: #141414; color: #fff; }
  .fa-card-action svg { width: 17px; height: 17px; }
  .fa-quick { position: absolute; inset-inline: 10px; bottom: 10px; z-index: 3; background: var(--fa-card); padding: 10px; display: flex; flex-direction: column; align-items: center; gap: 8px; opacity: 0; transform: translateY(8px); transition: opacity .25s, transform .25s; }
  .product-card.fa-card:hover .fa-quick, .product-card.fa-card:focus-within .fa-quick { opacity: 1; transform: none; }
  @media (hover: none) { .fa-quick { display: none; } }
  .fa-quick-label { font-size: 10.5px; letter-spacing: .16em; text-transform: uppercase; color: var(--fa-muted); }
  .fa-qa-row { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; }
  .fa-qa { min-width: 40px; height: 36px; padding: 0 8px; border: 1px solid var(--fa-line); background: var(--fa-card); color: var(--fa-ink); font: 500 12px 'Jost', sans-serif; cursor: pointer; }
  .fa-qa:hover:not(:disabled) { border-color: var(--fa-ink); }
  .fa-qa:disabled { color: var(--fa-out); text-decoration: line-through; cursor: not-allowed; border-color: var(--fa-line); opacity: .7; }
  .fa-qa.is-added { background: var(--fa-ink); color: var(--fa-page); border-color: var(--fa-ink); }
  .fa-quick .fa-btn { height: 40px; width: 100%; padding: 0 12px; font-size: 12px; }
  .fa-card-body { padding-top: 14px; display: flex; flex-direction: column; gap: 6px; }
  .fa-card-row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
  .fa-card-title { margin: 0; font-size: 15px; font-weight: 400; line-height: 1.35; color: var(--fa-ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .fa-card-title a:hover { color: var(--fa-accent); }
  .fa-price { white-space: nowrap; font-size: 15px; font-weight: 500; color: var(--fa-ink); text-align: end; }
  .fa-price.is-sale { color: var(--fa-accent); }
  .fa-price s { display: block; font-size: 13px; font-weight: 400; color: var(--fa-muted); }
  @media (min-width: 768px) { .fa-price s { display: inline; margin-inline-start: 6px; } }
  .fa-swatches { display: flex; align-items: center; gap: 6px; }
  .fa-sw { width: 14px; height: 14px; border-radius: 50%; border: 1px solid rgba(0,0,0,.18); display: inline-block; }
  .fa-swatches small { font-size: 12px; color: var(--fa-muted); }
  .fa-sizes { display: flex; flex-wrap: wrap; gap: 4px 10px; font-size: 12px; letter-spacing: .06em; color: var(--fa-ink); }
  .fa-sizes .is-out { color: var(--fa-out); text-decoration: line-through; }
  .fa-stars { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--fa-muted); }
  .fa-stars-icons { display: inline-flex; gap: 1px; color: var(--fa-ink); }
  .fa-stars-icons svg { width: 11px; height: 11px; }
  .fa-stars-icons .is-empty { color: var(--fa-line); }
  .fa-card .js-add-status { font-size: 12px; color: var(--fa-muted); min-height: 0; }

  /* ---------- editorial ---------- */
  .fa-edit { display: grid; grid-template-columns: 1fr; }
  @media (min-width: 1024px) { .fa-edit { grid-template-columns: repeat(2, minmax(0, 1fr)); min-height: 600px; } .fa-edit.is-flip .fa-edit-media { order: 2; } }
  .fa-edit + .fa-edit { margin-top: 4px; }
  .fa-edit-media { position: relative; min-height: 360px; background: var(--fa-stone); }
  .fa-edit-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .fa-edit-copy { background: #141414; color: #fff; padding: 48px 24px; display: flex; flex-direction: column; justify-content: center; gap: 20px; }
  @media (min-width: 1024px) { .fa-edit-copy { padding: 72px 64px; } }
  .fa-edit-copy .fa-kicker { color: #d6d2cc; margin: 0; }
  .fa-edit-copy h2 { margin: 0; font-family: var(--fa-serif); font-weight: 400; font-size: 36px; line-height: 1.06; }
  @media (min-width: 1024px) { .fa-edit-copy h2 { font-size: 56px; } }
  .fa-edit-copy p { margin: 0; font-size: 17px; line-height: 1.6; color: #d6d2cc; max-width: 440px; }

  /* ---------- trust ---------- */
  .fa-trust { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px 16px; border-top: 1px solid var(--fa-line); border-bottom: 1px solid var(--fa-line); padding: 32px 0; }
  @media (min-width: 1024px) { .fa-trust { grid-template-columns: repeat(var(--fa-trust-cols, 4), minmax(0, 1fr)); gap: 24px; padding: 36px 0; } }
  .fa-trust-item { display: flex; gap: 14px; align-items: flex-start; color: var(--fa-ink); }
  .fa-trust-item svg { width: 26px; height: 26px; flex-shrink: 0; stroke-width: 1.3; }
  .fa-trust-item strong { display: block; font-weight: 500; font-size: 15px; }
  .fa-trust-item span { display: block; font-size: 14px; color: var(--fa-muted); margin-top: 4px; }

  /* ---------- newsletter + brands ---------- */
  .fa-newsletter { background: var(--fa-stone); padding: 48px 20px; display: flex; flex-direction: column; align-items: center; gap: 16px; text-align: center; }
  @media (min-width: 1024px) { .fa-newsletter { padding: 64px 40px; } }
  .fa-newsletter h2 { margin: 0; font-family: var(--fa-serif); font-weight: 400; font-size: 30px; color: var(--fa-ink); }
  @media (min-width: 1024px) { .fa-newsletter h2 { font-size: 40px; } }
  .fa-newsletter p { margin: 0; color: rgb(var(--color-fg-secondary)); font-size: 16px; }
  .fa-newsletter form { display: flex; gap: 8px; width: 100%; max-width: 520px; margin-top: 8px; flex-wrap: wrap; }
  .fa-newsletter input { flex: 1 1 220px; min-width: 0; height: 52px; border: 1px solid var(--fa-ink); background: var(--fa-card); padding: 0 18px; font: 400 15px 'Jost', sans-serif; color: var(--fa-ink); outline: none; }
  .fa-newsletter-note { font-size: 13px; color: var(--fa-muted); min-height: 18px; }
  .fa-brands { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px 40px; }
  .fa-brand { display: inline-flex; align-items: center; gap: 8px; height: 56px; font-family: var(--fa-serif); font-size: 20px; color: var(--fa-muted); }
  .fa-brand:hover { color: var(--fa-ink); }
  .fa-brand img { max-height: 32px; max-width: 110px; object-fit: contain; filter: grayscale(1); opacity: .75; }
  .fa-brand:hover img { filter: none; opacity: 1; }

  /* ---------- footer ---------- */
  .fa-footer { margin-top: 64px; background: #141414; color: #d6d2cc; }
  @media (min-width: 1024px) { .fa-footer { margin-top: 96px; } }
  .fa-footer-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 32px 20px; padding: 56px 0 40px; }
  @media (min-width: 1024px) { .fa-footer-grid { grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; padding: 72px 0 56px; } }
  .fa-footer-brand { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 16px; }
  @media (min-width: 1024px) { .fa-footer-brand { grid-column: auto; } }
  .fa-footer .fa-logo { justify-self: start; color: #fff; font-size: 26px; max-width: none; }
  .fa-footer .fa-logo img { filter: brightness(0) invert(1); }
  .fa-footer-brand p { margin: 0; font-size: 14px; line-height: 1.7; max-width: 340px; }
  .fa-footer h6 { margin: 0 0 16px; font-size: 12px; letter-spacing: .2em; text-transform: uppercase; color: #fff; font-weight: 500; }
  .fa-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 11px; }
  .fa-footer li, .fa-footer li a { font-size: 14px; color: #d6d2cc; }
  .fa-footer li a:hover { color: #fff; }
  .fa-footer-contact li { display: flex; align-items: flex-start; gap: 9px; }
  .fa-footer-contact svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 3px; }
  .fa-social { display: flex; gap: 8px; }
  .fa-social a { width: 40px; height: 40px; border: 1px solid #3a3a3a; display: grid; place-items: center; color: #fff; }
  .fa-social a:hover { border-color: #fff; }
  .fa-social svg { width: 16px; height: 16px; }
  .fa-footer-bottom { border-top: 1px solid #3a3a3a; padding: 22px 0; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; color: #a8a29a; }
  .fa-pay { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .fa-pay span { display: inline-flex; align-items: center; justify-content: center; height: 26px; min-width: 44px; padding: 0 8px; border: 1px solid #3a3a3a; font-size: 10px; font-weight: 600; letter-spacing: .1em; color: #d6d2cc; }

  .fa-empty { padding: 32px; text-align: center; color: var(--fa-muted); font-size: 14px; }
  [x-cloak] { display: none !important; }
  @media (max-width: 1023.98px) { .fa-footer { padding-bottom: 5rem; } }
</style>
<script>
  // Card quick add: a size button adds that exact variant (size-only
  // products), so shoppers skip the variant picker. Same cart line shape as
  // the picker in store/partials/home-modals-scripts.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.fa-qa[data-variant-id]');
    if (!btn || btn.disabled || !window.CartLS || window.__HIDE_PRICES__) return;
    e.preventDefault();
    var stock = btn.dataset.stock !== undefined && btn.dataset.stock !== '' ? Number(btn.dataset.stock) : null;
    if (!window.__ALLOW_OVERSELLING__ && stock !== null && stock <= 0) return;
    var item = {
      id: btn.dataset.productId + ':' + btn.dataset.variantId,
      product_id: Number(btn.dataset.productId),
      product_variant_id: Number(btn.dataset.variantId),
      name: (btn.dataset.name || '') + ' — ' + (btn.dataset.variantName || ''),
      variant_name: btn.dataset.variantName || '',
      price: Number(btn.dataset.price || 0),
      qty: 1,
      image: btn.dataset.image || '',
      slug: btn.dataset.slug || '',
      currency: btn.dataset.currency || '',
      stock: stock
    };
    try { window.CartLS.add(item, 1); } catch (err) { return; }
    btn.classList.add('is-added');
    var card = btn.closest('.product-card');
    var status = card ? card.querySelector('.js-add-status') : null;
    if (status) status.textContent = btn.dataset.addedLabel || window.__MSG_ADDED__ || '';
    setTimeout(function () { btn.classList.remove('is-added'); if (status) status.textContent = ''; }, 1200);
  });
</script>
