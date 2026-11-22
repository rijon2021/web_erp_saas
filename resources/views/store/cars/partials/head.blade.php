{{-- Car Dealership theme skin. Embedded CSS (no Tailwind rebuild): charcoal
     header + hero, light showroom ground, one accent; `cd-*` components for
     header, footer, homepage, inventory and the vehicle page; shared tokens
     re-tinted so cart, checkout and account pages match. --}}
@php
  $cdAccent = $s->primary_color ?: '#c2410c';
  // Stock defaults of the other themes read as "unset" here.
  if (in_array(strtolower($cdAccent), ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#0f766e', '#1a7f6b', '#16a34a', '#8a1c2b'], true)) { $cdAccent = '#c2410c'; }
  $cdRgb = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [194, 65, 12];
      return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  };
  [$cr, $cg, $cb] = $cdRgb($cdAccent);
  // Darker shade of the accent for text links on white (keeps contrast with light accents).
  $cdInk = sprintf('#%02x%02x%02x', (int) ($cr * .78), (int) ($cg * .78), (int) ($cb * .78));
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@100..125,500..800&family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root {
    --color-bg-base: 244 245 247;
    --color-bg-surface: 255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted: 238 240 243;
    --color-border-subtle: 226 229 233;
    --color-border-strong: 205 210 217;
    --color-fg-primary: 17 20 24;
    --color-fg-secondary: 42 47 54;
    --color-fg-muted: 91 99 110;
    --color-accent-400: {{ $cr }} {{ $cg }} {{ $cb }};
    --color-accent-500: {{ $cr }} {{ $cg }} {{ $cb }};
    --color-accent-600: {{ (int) ($cr * .85) }} {{ (int) ($cg * .85) }} {{ (int) ($cb * .85) }};
    --color-accent-glow: rgba({{ $cr }}, {{ $cg }}, {{ $cb }}, .25);
    --cd-ink: #111418;
    --cd-ink-2: #2a2f36;
    --cd-muted: #5b636e;
    --cd-line: #e2e5e9;
    --cd-line-2: #d5dae1;
    --cd-ground: #f4f5f7;
    --cd-card: #ffffff;
    --cd-photo: #e6e8eb;
    --cd-dark: #111418;
    --cd-dark-2: #1f242b;
    --cd-dark-line: #3a414b;
    --cd-dark-muted: #a7afba;
    --cd-accent: {{ $cdAccent }};
    --cd-accent-ink: {{ $cdInk }};
    --cd-accent-soft: rgba({{ $cr }}, {{ $cg }}, {{ $cb }}, .12);
    --cd-display: 'Archivo', 'Inter', system-ui, sans-serif;
  }
  .dark {
    --color-bg-base: 13 15 18; --color-bg-surface: 22 25 30; --color-bg-elevated: 22 25 30; --color-bg-muted: 31 36 43;
    --color-border-subtle: 44 50 58; --color-border-strong: 58 65 75;
    --color-fg-primary: 238 240 243; --color-fg-secondary: 205 210 217; --color-fg-muted: 160 168 179;
    --cd-ink: #eef0f3; --cd-ink-2: #cdd2d9; --cd-muted: #a0a8b3; --cd-line: #2c323a; --cd-line-2: #3a414b;
    --cd-ground: #0d0f12; --cd-card: #16191e; --cd-photo: #1f242b; --cd-accent-ink: #f4a97c;
  }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--cd-ground); }
  .section-title, .hero-title, .cd-display { font-family: var(--cd-display); font-stretch: 112%; letter-spacing: -0.01em; }
  .num, .cd-num { font-variant-numeric: tabular-nums; }
  .btn { border-radius: 10px; }
  .card { border-radius: 16px; }
  .input, .select, .textarea { border-radius: 10px; }
  .page-link { border-radius: 10px; }
  .page-link.active, .page-link.is-active { background: var(--cd-ink); border-color: var(--cd-ink); color: #fff; }
  .dark .page-link.active, .dark .page-link.is-active { color: #111418; }

  /* ---------- layout ---------- */
  .cd-container { width: 100%; max-width: 1272px; margin: 0 auto; padding: 0 16px; }
  .cd-section { padding-top: 48px; }
  @media (min-width: 1024px) { .cd-section { padding-top: 72px; } }
  .cd-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
  .cd-h1 { font-family: var(--cd-display); font-stretch: 112%; font-weight: 800; font-size: 30px; line-height: 1.08; margin: 0; color: var(--cd-ink); letter-spacing: -0.01em; }
  @media (min-width: 1024px) { .cd-h1 { font-size: 38px; } }
  .cd-h2 { font-family: var(--cd-display); font-stretch: 112%; font-weight: 800; font-size: 24px; line-height: 1.15; margin: 0; color: var(--cd-ink); letter-spacing: -0.01em; }
  @media (min-width: 1024px) { .cd-h2 { font-size: 30px; } }
  .cd-sub { margin: 6px 0 0; color: var(--cd-muted); }
  .cd-kicker { display: inline-block; font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--cd-accent-ink); }
  .cd-link { font-weight: 600; color: var(--cd-accent-ink); white-space: nowrap; }
  .cd-link:hover { text-decoration: underline; }
  .cd-muted { color: var(--cd-muted); }
  .cd-note { margin: 12px 0 0; font-size: 12px; color: var(--cd-muted); }
  [x-cloak] { display: none !important; }

  /* ---------- buttons ---------- */
  .cd-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 0 20px; border-radius: 10px; border: 1px solid var(--cd-accent); background: var(--cd-accent); color: #fff; font: 600 15px/1.2 'Inter', sans-serif; cursor: pointer; white-space: nowrap; text-decoration: none; transition: filter .15s, background .15s, color .15s; }
  .cd-btn:hover { filter: brightness(.92); color: #fff; }
  .cd-btn:disabled { opacity: .6; cursor: not-allowed; }
  .cd-btn svg { width: 18px; height: 18px; }
  .cd-btn-dark { background: var(--cd-ink); border-color: var(--cd-ink); color: var(--cd-card); }
  .cd-btn-dark:hover { color: var(--cd-card); }
  .cd-btn-ghost { background: var(--cd-card); border-color: var(--cd-line-2); color: var(--cd-ink); }
  .cd-btn-ghost:hover { filter: none; border-color: var(--cd-ink); color: var(--cd-ink); }
  .cd-btn-on-dark { background: transparent; border-color: var(--cd-dark-line); color: #f4f5f7; }
  .cd-btn-on-dark:hover { filter: none; border-color: #f4f5f7; color: #f4f5f7; }
  .cd-btn-block { width: 100%; }
  .cd-btn-sm { min-height: 40px; padding: 0 14px; font-size: 14px; }

  /* ---------- announcement + header ---------- */
  .cd-announce { background: var(--cd-dark); color: #d5dae1; font-size: 13px; }
  .cd-announce .cd-container { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 36px; }
  .cd-announce-mid { flex: 1; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cd-announce-side { display: none; align-items: center; gap: 14px; }
  .cd-announce-side a, .cd-announce-side button { color: #d5dae1; display: inline-flex; align-items: center; gap: 4px; background: none; border: 0; cursor: pointer; font: inherit; }
  .cd-announce-side a:hover, .cd-announce-side button:hover { color: #fff; }
  .cd-announce-side svg { width: 14px; height: 14px; }
  @media (min-width: 900px) { .cd-announce-side { display: flex; } }
  .cd-header { position: sticky; top: 0; z-index: 40; background: var(--cd-card); border-bottom: 1px solid var(--cd-line); }
  .cd-header-main { display: flex; align-items: center; gap: 24px; min-height: 68px; }
  .cd-logo { display: inline-flex; align-items: center; gap: 10px; color: var(--cd-ink); font-family: var(--cd-display); font-stretch: 112%; font-weight: 800; font-size: 20px; white-space: nowrap; text-decoration: none; }
  .cd-logo img { max-height: 40px; max-width: 170px; object-fit: contain; }
  .cd-logo-mark { width: 40px; height: 40px; border-radius: 10px; background: var(--cd-accent); display: inline-flex; align-items: center; justify-content: center; color: #fff; flex: none; }
  .cd-logo-mark svg { width: 24px; height: 24px; }
  .cd-nav { display: none; align-items: center; gap: 22px; list-style: none; margin: 0; padding: 0; }
  @media (min-width: 1100px) { .cd-nav { display: flex; } }
  .cd-nav a { color: var(--cd-ink); font-size: 15px; font-weight: 500; text-decoration: none; padding: 8px 0; border-bottom: 2px solid transparent; }
  .cd-nav a:hover, .cd-nav a.is-active { border-color: var(--cd-accent); }
  .cd-actions { margin-inline-start: auto; display: flex; align-items: center; gap: 8px; }
  .cd-icon-btn { position: relative; width: 44px; height: 44px; border-radius: 10px; border: 1px solid var(--cd-line); background: var(--cd-card); color: var(--cd-ink); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
  .cd-icon-btn:hover { border-color: var(--cd-ink); }
  .cd-icon-btn img { width: 28px; height: 28px; border-radius: 999px; object-fit: cover; }
  .cd-count { position: absolute; top: -6px; inset-inline-end: -6px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--cd-accent); color: #fff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
  .cd-count:empty, .cd-count[data-zero] { display: none; }
  .cd-phone { display: none; align-items: center; gap: 8px; font-weight: 600; color: var(--cd-ink); text-decoration: none; padding: 0 8px; }
  @media (min-width: 1200px) { .cd-phone { display: inline-flex; } }
  .cd-phone svg { width: 18px; height: 18px; }
  .cd-hide-sm { display: none !important; }
  @media (min-width: 768px) { .cd-hide-sm { display: inline-flex !important; } }
  .cd-show-sm { display: inline-flex; }
  @media (min-width: 1100px) { .cd-show-sm { display: none; } }
  .cd-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 8px); min-width: 200px; background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 12px; box-shadow: 0 18px 40px rgba(17,20,24,.14); padding: 6px; z-index: 50; }
  .cd-menu a, .cd-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; border-radius: 8px; color: var(--cd-ink); font-size: 14px; background: none; border: 0; cursor: pointer; text-align: start; text-decoration: none; }
  .cd-menu a:hover, .cd-menu button:hover { background: var(--cd-ground); }
  .cd-menu svg { width: 16px; height: 16px; }
  .cd-menu hr { border: 0; border-top: 1px solid var(--cd-line); margin: 6px 0; }
  .cd-announce .cd-menu a { color: var(--cd-ink); }
  .cd-search-bar { border-top: 1px solid var(--cd-line); background: var(--cd-card); }
  .cd-search-bar form { position: relative; display: flex; align-items: center; gap: 10px; min-height: 60px; }
  .cd-search-bar input { flex: 1; height: 44px; border: 0; background: transparent; font-size: 16px; color: var(--cd-ink); outline: none; }
  .cd-search-results { position: absolute; top: 100%; inset-inline: 0; background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 12px; box-shadow: 0 18px 40px rgba(17,20,24,.14); overflow: hidden; z-index: 50; max-height: 420px; overflow-y: auto; }
  .cd-search-results a { display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: var(--cd-ink); text-decoration: none; }
  .cd-search-results a:hover { background: var(--cd-ground); }
  .cd-search-results img { width: 56px; height: 40px; border-radius: 6px; object-fit: cover; background: var(--cd-photo); }

  /* ---------- hero + search ---------- */
  .cd-hero { background: var(--cd-dark); color: #f4f5f7; }
  .cd-hero-grid { display: flex; flex-wrap: wrap; gap: 40px; align-items: center; padding: 48px 0 112px; }
  @media (min-width: 1024px) { .cd-hero-grid { padding: 64px 0 128px; } }
  .cd-hero-copy { flex: 1 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
  .cd-hero-copy .cd-kicker { color: #f4a97c; }
  .cd-hero-title { font-family: var(--cd-display); font-stretch: 112%; font-weight: 800; font-size: 40px; line-height: 1.04; margin: 0; letter-spacing: -0.015em; }
  @media (min-width: 1024px) { .cd-hero-title { font-size: 58px; } }
  .cd-hero-sub { margin: 0; font-size: 18px; line-height: 1.55; color: var(--cd-dark-muted); max-width: 540px; }
  .cd-hero-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
  .cd-hero-media { flex: 1 1 480px; min-width: 0; aspect-ratio: 16 / 10; border-radius: 18px; overflow: hidden; background: var(--cd-dark-2); }
  .cd-hero-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cd-hero-dots { display: flex; gap: 8px; }
  .cd-hero-dots button { width: 28px; height: 4px; border-radius: 2px; border: 0; background: var(--cd-dark-line); cursor: pointer; padding: 0; }
  .cd-hero-dots button.is-active { background: #f4f5f7; }
  .cd-hero-plain .cd-hero-grid { padding-bottom: 112px; }
  .cd-search-wrap { position: relative; margin-top: -72px; }
  .cd-search { background: var(--cd-card); border-radius: 16px; box-shadow: 0 18px 40px rgba(17,20,24,.14); padding: 20px; display: flex; flex-direction: column; gap: 16px; }
  .cd-search-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items: end; }
  .cd-seg { display: flex; gap: 6px; flex-wrap: wrap; }
  .cd-seg label, .cd-seg a { position: relative; height: 36px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--cd-line); background: var(--cd-card); color: var(--cd-ink); font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; cursor: pointer; text-decoration: none; }
  .cd-seg input { position: absolute; opacity: 0; pointer-events: none; }
  .cd-seg label:has(input:checked), .cd-seg a.is-active { background: var(--cd-ink); border-color: var(--cd-ink); color: var(--cd-card); }
  .cd-seg label:has(input:focus-visible) { outline: 2px solid var(--cd-accent); outline-offset: 2px; }
  .cd-field { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: var(--cd-muted); min-width: 0; }
  .cd-field select, .cd-field input, .cd-field textarea { width: 100%; min-height: 46px; border: 1px solid var(--cd-line-2); border-radius: 10px; padding: 0 12px; background: var(--cd-card); color: var(--cd-ink); font: 400 15px/1.4 'Inter', sans-serif; }
  .cd-field textarea { padding: 10px 12px; min-height: 90px; resize: vertical; }
  .cd-field select:focus, .cd-field input:focus, .cd-field textarea:focus { outline: 2px solid var(--cd-accent-soft); border-color: var(--cd-accent); }
  .cd-field-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

  /* ---------- tiles, makes, grid ---------- */
  .cd-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
  .cd-tile { background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 14px; padding: 18px 14px; display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--cd-ink); font-weight: 600; text-decoration: none; transition: border-color .15s; }
  .cd-tile:hover { border-color: var(--cd-ink); }
  .cd-tile svg { width: 96px; height: 42px; }
  .cd-tile small { font-weight: 500; color: var(--cd-muted); font-size: 13px; }
  .cd-makes { display: flex; flex-wrap: wrap; gap: 10px; }
  .cd-make { min-width: 128px; min-height: 52px; padding: 0 18px; border-radius: 12px; background: var(--cd-card); border: 1px solid var(--cd-line); display: inline-flex; align-items: center; justify-content: space-between; gap: 14px; font-weight: 600; color: var(--cd-ink); text-decoration: none; }
  .cd-make:hover { border-color: var(--cd-ink); }
  .cd-make small { font-weight: 500; color: var(--cd-muted); font-size: 13px; }
  .cd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }

  /* ---------- vehicle card ---------- */
  .cd-card { position: relative; background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; transition: box-shadow .15s, transform .15s; }
  .cd-card:hover { box-shadow: 0 10px 30px rgba(17,20,24,.10); transform: translateY(-2px); }
  .cd-card-media { position: relative; display: block; aspect-ratio: 3 / 2; background: var(--cd-photo); overflow: hidden; }
  .cd-card-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cd-badges { position: absolute; top: 12px; inset-inline-start: 12px; display: flex; gap: 6px; flex-wrap: wrap; z-index: 1; }
  .cd-badge { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; background: #fff; color: #111418; }
  .cd-badge.is-new { background: #111418; color: #fff; }
  .cd-badge.is-cert { background: #1e4fa8; color: #fff; }
  .cd-badge.is-drop { background: #fdebdd; color: #9a3412; }
  .cd-badge.is-sold { background: #b91c1c; color: #fff; }
  .cd-photos { position: absolute; bottom: 10px; inset-inline-end: 12px; font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 6px; background: rgba(17,20,24,.75); color: #fff; display: inline-flex; align-items: center; gap: 5px; }
  .cd-photos svg { width: 13px; height: 13px; }
  .cd-card-body { padding: 16px; display: flex; flex-direction: column; gap: 12px; flex: 1; }
  .cd-card-title { margin: 0; font-family: var(--cd-display); font-stretch: 108%; font-size: 18px; font-weight: 700; line-height: 1.25; }
  .cd-card-title a { color: var(--cd-ink); text-decoration: none; }
  .cd-card-title a::after { content: ''; position: absolute; inset: 0; }
  .cd-trim { font-size: 14px; color: var(--cd-muted); margin-top: 2px; }
  .cd-specs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; font-size: 13px; color: var(--cd-ink-2); }
  .cd-specs span { display: flex; align-items: center; gap: 6px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cd-specs svg { width: 16px; height: 16px; color: var(--cd-muted); flex: none; }
  .cd-card-foot { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--cd-line); display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
  .cd-price { font-family: var(--cd-display); font-size: 23px; font-weight: 800; color: var(--cd-ink); font-variant-numeric: tabular-nums; line-height: 1.1; }
  .cd-was { display: block; font-size: 13px; color: var(--cd-muted); text-decoration: line-through; font-variant-numeric: tabular-nums; }
  .cd-monthly { font-size: 13px; color: var(--cd-muted); text-align: end; font-variant-numeric: tabular-nums; }
  .cd-monthly strong { color: var(--cd-ink); }

  /* ---------- panels: finance, trade-in, trust, visit ---------- */
  .cd-panel { background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 20px; padding: 24px; }
  @media (min-width: 768px) { .cd-panel { padding: 40px; } }
  .cd-split { display: flex; flex-wrap: wrap; gap: 32px; }
  .cd-split > * { flex: 1 1 360px; min-width: 0; }
  .cd-steps { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
  .cd-steps li { display: flex; gap: 14px; align-items: center; }
  .cd-steps b { flex: none; width: 36px; height: 36px; border-radius: 999px; background: var(--cd-ink); color: var(--cd-card); display: inline-flex; align-items: center; justify-content: center; }
  .cd-calc { background: var(--cd-ground); border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
  .cd-calc-row { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; }
  .cd-calc-row strong { font-variant-numeric: tabular-nums; }
  .cd-calc input[type=range] { width: 100%; accent-color: var(--cd-ink); }
  .cd-terms { display: flex; gap: 6px; flex-wrap: wrap; }
  .cd-terms button { flex: 1; min-width: 56px; height: 40px; border-radius: 8px; border: 1px solid var(--cd-line-2); background: var(--cd-card); color: var(--cd-ink); font-weight: 600; cursor: pointer; }
  .cd-terms button.is-active { background: var(--cd-ink); border-color: var(--cd-ink); color: var(--cd-card); }
  .cd-calc-total { border-top: 1px solid var(--cd-line-2); padding-top: 14px; display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; }
  .cd-calc-total .cd-price { font-size: 32px; }
  .cd-dark-panel { background: var(--cd-dark); color: #f4f5f7; border-radius: 20px; padding: 24px; }
  @media (min-width: 768px) { .cd-dark-panel { padding: 40px; } }
  .cd-dark-panel .cd-h2 { color: #f4f5f7; }
  .cd-dark-panel p { color: var(--cd-dark-muted); }
  .cd-dark-panel .cd-field { color: var(--cd-dark-muted); }
  .cd-dark-panel .cd-field input { background: var(--cd-dark-2); border-color: var(--cd-dark-line); color: #f4f5f7; }
  .cd-trust { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
  .cd-trust-item { display: flex; gap: 14px; align-items: flex-start; }
  .cd-trust-item > span { flex: none; width: 48px; height: 48px; border-radius: 12px; background: var(--cd-card); border: 1px solid var(--cd-line); display: inline-flex; align-items: center; justify-content: center; color: var(--cd-ink); }
  .cd-trust-item strong { display: block; color: var(--cd-ink); }
  .cd-trust-item small { display: block; font-size: 14px; color: var(--cd-muted); margin-top: 2px; }
  .cd-hours { white-space: pre-line; line-height: 1.8; font-variant-numeric: tabular-nums; }
  .cd-map { min-height: 300px; border-radius: 20px; overflow: hidden; background: var(--cd-photo); border: 1px solid var(--cd-line); }
  .cd-map iframe { width: 100%; height: 100%; min-height: 300px; border: 0; display: block; }

  /* ---------- inventory ---------- */
  .cd-crumbs { font-size: 14px; color: var(--cd-muted); display: flex; gap: 6px; flex-wrap: wrap; }
  .cd-crumbs a { color: var(--cd-muted); text-decoration: none; }
  .cd-crumbs a:hover { color: var(--cd-ink); }
  .cd-inv { display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start; }
  .cd-filters { flex: 1 1 280px; max-width: 100%; min-width: 0; background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 20px; }
  @media (min-width: 1024px) { .cd-filters { max-width: 300px; position: sticky; top: 88px; max-height: calc(100vh - 104px); overflow-y: auto; } }
  .cd-filters fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
  .cd-filters legend, .cd-filter-title { font-weight: 700; color: var(--cd-ink); margin-bottom: 10px; font-size: 15px; padding: 0; }
  .cd-check { display: flex; align-items: center; gap: 10px; min-height: 34px; font-size: 15px; color: var(--cd-ink); cursor: pointer; }
  .cd-check input { width: 18px; height: 18px; accent-color: var(--cd-ink); }
  .cd-check span { flex: 1; }
  .cd-check small { font-size: 13px; color: var(--cd-muted); font-variant-numeric: tabular-nums; }
  .cd-results { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
  .cd-toolbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .cd-chips { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .cd-chip { height: 36px; padding: 0 10px 0 14px; border-radius: 999px; border: 1px solid var(--cd-ink); background: var(--cd-card); color: var(--cd-ink); font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
  .cd-chip svg { width: 14px; height: 14px; }
  .cd-sort { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--cd-muted); }
  .cd-sort select { height: 44px; border: 1px solid var(--cd-line-2); border-radius: 10px; padding: 0 12px; background: var(--cd-card); color: var(--cd-ink); font-weight: 600; }
  .cd-filter-toggle { display: inline-flex; }
  @media (min-width: 1024px) { .cd-filter-toggle { display: none; } }
  @media (max-width: 1023px) { .cd-filters.is-collapsed { display: none; } }
  .cd-empty { background: var(--cd-card); border: 1px dashed var(--cd-line-2); border-radius: 16px; padding: 48px 24px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 10px; }
  .cd-empty svg { width: 120px; height: 48px; color: var(--cd-line-2); }

  /* ---------- vehicle page ---------- */
  .cd-pdp { display: flex; gap: 28px; flex-wrap: wrap; align-items: flex-start; }
  .cd-pdp-main { flex: 999 1 620px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
  .cd-pdp-aside { flex: 1 1 360px; min-width: 0; display: flex; flex-direction: column; gap: 14px; }
  @media (min-width: 1024px) { .cd-pdp-aside { position: sticky; top: 88px; } }
  /* Phones/tablets: price + lead form right after the gallery, specs below. */
  @media (max-width: 1023px) {
    .cd-pdp { flex-direction: column; flex-wrap: nowrap; gap: 20px; }
    .cd-pdp-main { display: contents; }
    .cd-pdp-gallery { order: 1; width: 100%; }
    .cd-pdp-aside { order: 2; width: 100%; }
    .cd-pdp-main > .cd-box { order: 3; width: 100%; box-sizing: border-box; }
  }
  .cd-gallery-main { position: relative; aspect-ratio: 16 / 10; border-radius: 18px; background: var(--cd-photo); overflow: hidden; }
  .cd-gallery-main img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cd-gallery-nav { position: absolute; top: 50%; margin-top: -22px; width: 44px; height: 44px; border-radius: 999px; border: 0; background: rgba(255,255,255,.92); color: #111418; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
  .cd-gallery-nav.is-prev { inset-inline-start: 14px; }
  .cd-gallery-nav.is-next { inset-inline-end: 14px; }
  .cd-thumbs { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
  .cd-thumbs button { aspect-ratio: 4 / 3; border-radius: 10px; overflow: hidden; padding: 0; border: 2px solid transparent; background: var(--cd-photo); cursor: pointer; position: relative; }
  .cd-thumbs button.is-active { border-color: var(--cd-ink); }
  .cd-thumbs img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .cd-thumbs .cd-more { position: absolute; inset: 0; background: rgba(17,20,24,.6); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; }
  .cd-box { background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 16px; padding: 22px; display: flex; flex-direction: column; gap: 16px; }
  .cd-box h2 { font-family: var(--cd-display); font-stretch: 108%; font-size: 21px; font-weight: 800; margin: 0; color: var(--cd-ink); }
  .cd-keyspecs { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
  .cd-keyspecs div { background: var(--cd-ground); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 4px; }
  .cd-keyspecs span { font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--cd-muted); }
  .cd-keyspecs strong { font-size: 16px; color: var(--cd-ink); font-variant-numeric: tabular-nums; }
  .cd-spec-table { margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); column-gap: 32px; }
  .cd-spec-table div { display: flex; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--cd-line); }
  .cd-spec-table dt { color: var(--cd-muted); }
  .cd-spec-table dd { margin: 0; font-weight: 600; text-align: end; color: var(--cd-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
  .cd-features { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px 16px; }
  .cd-features li { display: flex; gap: 8px; align-items: center; font-size: 15px; color: var(--cd-ink); }
  .cd-features svg { width: 16px; height: 16px; flex: none; }
  .cd-desc { margin: 0; line-height: 1.7; color: var(--cd-ink-2); overflow-wrap: anywhere; }
  .cd-pills { display: flex; gap: 8px; flex-wrap: wrap; }
  .cd-pills span { font-size: 14px; font-weight: 600; padding: 8px 12px; border-radius: 8px; background: var(--cd-ground); color: var(--cd-ink); }
  .cd-buy { background: var(--cd-card); border: 1px solid var(--cd-line); border-radius: 18px; padding: 22px; display: flex; flex-direction: column; gap: 16px; }
  .cd-buy .cd-h1 { font-size: 28px; }
  .cd-price-lg { font-family: var(--cd-display); font-size: 38px; font-weight: 800; color: var(--cd-ink); font-variant-numeric: tabular-nums; line-height: 1; }
  .cd-tabs { display: grid; grid-template-columns: repeat(var(--cd-tabs, 3), minmax(0, 1fr)); gap: 4px; background: var(--cd-ground); border-radius: 12px; padding: 4px; }
  .cd-tabs button { min-height: 40px; border-radius: 9px; border: 0; font-weight: 600; font-size: 14px; cursor: pointer; background: transparent; color: var(--cd-muted); }
  .cd-tabs button.is-active { background: var(--cd-card); color: var(--cd-ink); box-shadow: 0 1px 3px rgba(17,20,24,.12); }
  .cd-form { display: flex; flex-direction: column; gap: 10px; }
  .cd-form .cd-check { font-size: 14px; min-height: 28px; }
  .cd-reserve-box { border: 1px solid var(--cd-accent-soft); background: var(--cd-accent-soft); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
  .cd-reserve-box div { display: flex; justify-content: space-between; font-weight: 700; color: var(--cd-ink); }
  .cd-alert { border-radius: 10px; padding: 12px 14px; font-size: 14px; }
  .cd-alert.is-ok { background: #e6f4ea; color: #1e6b3a; }
  .cd-alert.is-err { background: #fde8e8; color: #9b1c1c; }
  .cd-sold { background: #b91c1c; color: #fff; border-radius: 12px; padding: 12px 14px; font-weight: 600; }
  .cd-hp { position: absolute !important; inset-inline-start: -9999px; width: 1px; height: 1px; overflow: hidden; }
  .cd-2btn { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .cd-2btn > :only-child { grid-column: 1 / -1; }

  /* ---------- footer ---------- */
  .cd-footer { margin-top: 72px; background: var(--cd-dark); color: var(--cd-dark-muted); }
  .cd-footer-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 32px; padding: 48px 0 28px; }
  .cd-footer h6 { margin: 0 0 12px; color: #f4f5f7; font-size: 14px; font-weight: 700; }
  .cd-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; font-size: 14px; }
  .cd-footer a { color: var(--cd-dark-muted); text-decoration: none; }
  .cd-footer a:hover { color: #fff; }
  .cd-footer .cd-logo { color: #f4f5f7; }
  .cd-footer-brand p { font-size: 14px; line-height: 1.6; margin: 12px 0 0; max-width: 340px; }
  .cd-footer-contact li { display: flex; gap: 8px; align-items: flex-start; }
  .cd-footer-contact svg { width: 16px; height: 16px; margin-top: 2px; flex: none; }
  .cd-social { display: flex; gap: 8px; margin-top: 16px; }
  .cd-social a { width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--cd-dark-line); display: inline-flex; align-items: center; justify-content: center; }
  .cd-social svg { width: 18px; height: 18px; }
  .cd-footer-bottom { border-top: 1px solid var(--cd-ink-2); padding: 16px 0 24px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; }
  .dark .cd-footer-bottom { border-color: var(--cd-dark-line); }
  .cd-pay { display: flex; gap: 6px; flex-wrap: wrap; }
  .cd-pay span { font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--cd-dark-line); color: #d5dae1; }
  /* Room for the shared mobile bottom bar. */
  @media (max-width: 767px) { .cd-footer { padding-bottom: 64px; } }
</style>
