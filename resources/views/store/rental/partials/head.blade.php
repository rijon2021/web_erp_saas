{{-- Rental theme skin. Embedded CSS (no Tailwind rebuild): cool light
     ground, slate ink, one accent (the store's primary colour, or a default
     that follows the business type: blue for vehicles, amber-brown for
     equipment, teal for properties, indigo for a mix) and a geometric display
     face; `rn-*` components for header, hero + search, category tiles, item
     cards, catalogue filters, item page, availability calendar, booking form,
     status page and footer. The shared tokens are re-tinted so account pages
     match. --}}
@php
  $rnFlavour = \App\Support\RentalTheme::flavour();
  $rnDefaultAccent = ['vehicle' => '#1d4ed8', 'equipment' => '#b45309', 'property' => '#0f766e', 'mixed' => '#4338ca'][$rnFlavour];
  $rnAccent = $s->primary_color ?: $rnDefaultAccent;
  // Stock defaults of the other themes read as "unset" here.
  if (in_array(strtolower($rnAccent), ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#1a7f6b', '#16a34a', '#8a1c2b', '#c2410c', '#b4441f'], true)) { $rnAccent = $rnDefaultAccent; }
  $rnRgb = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [29, 78, 216];
      return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  };
  [$nr, $ng, $nb] = $rnRgb($rnAccent);
  $rnInk = sprintf('#%02x%02x%02x', (int) ($nr * .78), (int) ($ng * .78), (int) ($nb * .78));
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root {
    --color-bg-base: 245 247 250;
    --color-bg-surface: 255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted: 238 242 247;
    --color-border-subtle: 226 232 240;
    --color-border-strong: 203 213 225;
    --color-fg-primary: 15 23 42;
    --color-fg-secondary: 51 65 85;
    --color-fg-muted: 100 116 139;
    --color-accent-400: {{ $nr }} {{ $ng }} {{ $nb }};
    --color-accent-500: {{ $nr }} {{ $ng }} {{ $nb }};
    --color-accent-600: {{ (int) ($nr * .85) }} {{ (int) ($ng * .85) }} {{ (int) ($nb * .85) }};
    --color-accent-glow: rgba({{ $nr }}, {{ $ng }}, {{ $nb }}, .22);
    --rn-ink: #0f172a;
    --rn-ink-2: #334155;
    --rn-muted: #64748b;
    --rn-line: #e2e8f0;
    --rn-line-2: #cbd5e1;
    --rn-ground: #f5f7fa;
    --rn-soft: #eef2f7;
    --rn-card: #ffffff;
    --rn-photo: #e8edf3;
    --rn-dark: #0f172a;
    --rn-dark-2: #1e293b;
    --rn-dark-muted: #94a3b8;
    --rn-ok: #15803d;
    --rn-ok-soft: #dcfce7;
    --rn-warn: #b45309;
    --rn-warn-soft: #fef3c7;
    --rn-bad: #b91c1c;
    --rn-bad-soft: #fee2e2;
    --rn-accent: {{ $rnAccent }};
    --rn-accent-ink: {{ $rnInk }};
    --rn-accent-soft: rgba({{ $nr }}, {{ $ng }}, {{ $nb }}, .1);
    --rn-accent-line: rgba({{ $nr }}, {{ $ng }}, {{ $nb }}, .35);
    --rn-display: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
    --rn-radius: 16px;
  }
  .dark {
    --color-bg-base: 11 17 29; --color-bg-surface: 17 25 40; --color-bg-elevated: 17 25 40; --color-bg-muted: 28 38 56;
    --color-border-subtle: 36 48 68; --color-border-strong: 51 65 85;
    --color-fg-primary: 241 245 249; --color-fg-secondary: 203 213 225; --color-fg-muted: 148 163 184;
    --rn-ink: #f1f5f9; --rn-ink-2: #cbd5e1; --rn-muted: #94a3b8; --rn-line: #243044; --rn-line-2: #334155; --rn-ground: #0b111d; --rn-soft: #1c2638;
    --rn-card: #111928; --rn-photo: #1c2638; --rn-accent-ink: #a5b4fc; --rn-ok-soft: #14532d; --rn-warn-soft: #422006; --rn-bad-soft: #450a0a; --rn-ok: #86efac; --rn-warn: #fcd34d; --rn-bad: #fca5a5;
  }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--rn-ground); color: var(--rn-ink); }
  .rn-container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 20px; }
  .rn-display { font-family: var(--rn-display); letter-spacing: -.02em; }
  [x-cloak] { display: none !important; }
  .rn-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  /* Buttons */
  .rn-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 22px; border-radius: 12px; background: var(--rn-accent); color: #fff; font-weight: 700; border: 0; cursor: pointer; text-decoration: none; transition: filter .15s, transform .15s; font-size: 15px; white-space: nowrap; }
  .rn-btn:hover { filter: brightness(.93); color: #fff; }
  .rn-btn:active { transform: translateY(1px); }
  .rn-btn:disabled { opacity: .5; cursor: not-allowed; filter: none; }
  .rn-btn svg { width: 18px; height: 18px; }
  .rn-btn-ghost { background: transparent; color: var(--rn-ink); border: 1.5px solid var(--rn-line-2); }
  .rn-btn-ghost:hover { border-color: var(--rn-accent); color: var(--rn-accent-ink); filter: none; }
  .rn-btn-light { background: #fff; color: #0f172a; }
  .rn-btn-light:hover { color: #0f172a; }
  .rn-btn-sm { min-height: 36px; padding: 0 14px; font-size: 14px; border-radius: 10px; }
  .rn-btn-block { width: 100%; }
  .rn-btn:focus-visible, .rn-chip:focus-visible, .rn-tab:focus-visible, .rn-icon-btn:focus-visible, .rn-day:focus-visible, .rn-input:focus-visible { outline: 3px solid var(--rn-accent-soft); outline-offset: 2px; box-shadow: 0 0 0 2px var(--rn-accent); }
  .rn-icon-btn { position: relative; width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; border: 0; background: transparent; color: var(--rn-ink); cursor: pointer; text-decoration: none; }
  .rn-icon-btn:hover { background: var(--rn-soft); color: var(--rn-ink); }
  .rn-icon-btn svg { width: 22px; height: 22px; }
  .rn-icon-btn img { width: 30px; height: 30px; border-radius: 999px; object-fit: cover; }
  .rn-link { color: var(--rn-accent-ink); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
  .rn-link:hover { text-decoration: underline; color: var(--rn-accent-ink); }
  .rn-link svg { width: 16px; height: 16px; }

  /* Announcement + header */
  .rn-announce { background: var(--rn-dark); color: #e2e8f0; font-size: 13px; }
  .rn-announce .rn-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 36px; }
  .rn-announce-mid { flex: 1; text-align: center; font-weight: 600; }
  .rn-announce button { background: none; border: 0; color: inherit; font: inherit; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
  .rn-announce svg { width: 14px; height: 14px; }
  .rn-header { position: sticky; top: 0; z-index: 40; background: color-mix(in srgb, var(--rn-card) 94%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--rn-line); }
  .rn-header-main { display: flex; align-items: center; gap: 16px; min-height: 68px; }
  .rn-logo { display: inline-flex; align-items: center; gap: 10px; font-family: var(--rn-display); font-size: 20px; font-weight: 800; color: var(--rn-ink); text-decoration: none; letter-spacing: -.02em; }
  .rn-logo:hover { color: var(--rn-ink); }
  .rn-logo img { max-height: 42px; max-width: 170px; object-fit: contain; }
  .rn-logo-mark { width: 38px; height: 38px; border-radius: 11px; background: var(--rn-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
  .rn-logo-mark svg { width: 20px; height: 20px; }
  .rn-nav { display: flex; gap: 2px; list-style: none; margin: 0 0 0 12px; padding: 0; }
  .rn-nav a { display: block; padding: 8px 12px; border-radius: 10px; color: var(--rn-ink-2); font-weight: 600; text-decoration: none; font-size: 15px; }
  .rn-nav a:hover, .rn-nav a.is-active { background: var(--rn-soft); color: var(--rn-ink); }
  .rn-actions { margin-inline-start: auto; display: flex; align-items: center; gap: 4px; }
  .rn-phone { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: var(--rn-ink-2); text-decoration: none; margin-inline-end: 6px; }
  .rn-phone svg { width: 18px; height: 18px; }
  .rn-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: 14px; padding: 6px; min-width: 200px; box-shadow: 0 16px 36px rgba(15,23,42,.14); z-index: 50; }
  .rn-menu a, .rn-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 10px; border-radius: 9px; color: var(--rn-ink); text-decoration: none; background: none; border: 0; font: inherit; cursor: pointer; text-align: start; }
  .rn-menu a:hover, .rn-menu button:hover { background: var(--rn-soft); }
  .rn-menu svg { width: 16px; height: 16px; }
  .rn-menu hr { border: 0; border-top: 1px solid var(--rn-line); margin: 4px 0; }
  .rn-show-sm { display: none; }
  @media (max-width: 960px) { .rn-nav, .rn-hide-sm, .rn-phone { display: none !important; } .rn-show-sm { display: inline-flex; } }
  .rn-drawer-nav a { display: flex; align-items: center; gap: 10px; padding: 13px 18px; color: var(--rn-ink); font-weight: 600; text-decoration: none; border-bottom: 1px solid var(--rn-line); }
  .rn-drawer-nav a svg { width: 18px; height: 18px; color: var(--rn-muted); }

  /* Hero + search */
  .rn-hero { position: relative; padding: 64px 0 72px; color: #fff; background: radial-gradient(1200px 500px at 80% -10%, rgba({{ $nr }}, {{ $ng }}, {{ $nb }}, .55), transparent 60%), linear-gradient(135deg, #0f172a 0%, #1e293b 100%); overflow: hidden; }
  .rn-hero-photo { position: absolute; inset: 0; }
  .rn-hero-photo img { width: 100%; height: 100%; object-fit: cover; }
  .rn-hero-photo::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(15,23,42,.86) 0%, rgba(15,23,42,.55) 55%, rgba(15,23,42,.25) 100%); }
  .rn-hero .rn-container { position: relative; }
  .rn-kicker { display: inline-flex; align-items: center; gap: 6px; color: #fff; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); border-radius: 999px; padding: 5px 12px; font-weight: 700; font-size: 13px; margin-bottom: 16px; }
  .rn-hero-title { font-family: var(--rn-display); font-size: clamp(34px, 5.4vw, 60px); line-height: 1.04; margin: 0 0 14px; font-weight: 800; letter-spacing: -.03em; max-width: 760px; }
  .rn-hero-sub { font-size: 18px; color: #cbd5e1; margin: 0 0 28px; max-width: 620px; line-height: 1.55; }
  .rn-search { background: var(--rn-card); color: var(--rn-ink); border-radius: 20px; padding: 14px; box-shadow: 0 24px 60px rgba(2,6,23,.35); max-width: 1040px; }
  .rn-tabs { display: flex; gap: 6px; flex-wrap: wrap; padding: 2px 2px 12px; }
  .rn-tab { display: inline-flex; align-items: center; gap: 7px; border: 1.5px solid var(--rn-line); background: var(--rn-card); color: var(--rn-ink-2); border-radius: 999px; padding: 7px 14px; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; }
  .rn-tab svg { width: 16px; height: 16px; }
  .rn-tab.is-active { border-color: var(--rn-accent); background: var(--rn-accent-soft); color: var(--rn-accent-ink); }
  .rn-search-grid { display: grid; grid-template-columns: 1.2fr 1fr 1fr auto; gap: 10px; align-items: end; }
  .rn-search-grid.no-cat { grid-template-columns: 1fr 1fr auto; }
  @media (max-width: 860px) { .rn-search-grid, .rn-search-grid.no-cat { grid-template-columns: 1fr 1fr; } .rn-search-grid .rn-search-go { grid-column: 1 / -1; } .rn-search-grid .rn-search-cat { grid-column: 1 / -1; } }
  @media (max-width: 480px) { .rn-search-grid, .rn-search-grid.no-cat { grid-template-columns: 1fr; } }
  .rn-when { display: grid; grid-template-columns: 1fr 104px; gap: 6px; }

  /* Form controls */
  .rn-field { display: block; min-width: 0; }
  .rn-label { display: block; font-size: 12.5px; font-weight: 700; color: var(--rn-ink-2); margin: 0 0 6px; letter-spacing: .01em; }
  .rn-label .rn-opt { font-weight: 500; color: var(--rn-muted); }
  .rn-input { display: block; width: 100%; min-height: 46px; padding: 10px 12px; border: 1.5px solid var(--rn-line); border-radius: 12px; background: var(--rn-card); color: var(--rn-ink); font: inherit; font-size: 15px; outline: none; transition: border-color .15s; }
  .rn-input:hover { border-color: var(--rn-line-2); }
  .rn-input:focus { border-color: var(--rn-accent); }
  textarea.rn-input { min-height: 92px; resize: vertical; }
  select.rn-input { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-inline-end: 34px; }
  [dir="rtl"] select.rn-input { background-position: left 12px center; }
  .rn-input.is-invalid { border-color: var(--rn-bad); }
  .rn-error { color: var(--rn-bad); font-size: 13px; margin-top: 5px; }
  .rn-help { color: var(--rn-muted); font-size: 13px; margin-top: 5px; }
  .rn-check { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 14.5px; color: var(--rn-ink-2); }
  .rn-check input { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--rn-accent); flex: none; }
  .rn-seg { display: inline-flex; gap: 6px; flex-wrap: wrap; }
  .rn-seg label { display: inline-flex; align-items: center; gap: 8px; border: 1.5px solid var(--rn-line); border-radius: 12px; padding: 10px 14px; cursor: pointer; font-weight: 600; }
  .rn-seg input { accent-color: var(--rn-accent); }
  .rn-seg label:has(input:checked) { border-color: var(--rn-accent); background: var(--rn-accent-soft); color: var(--rn-accent-ink); }

  /* Sections */
  .rn-section { padding: 64px 0; }
  .rn-section-sm { padding: 40px 0; }
  .rn-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
  .rn-h2 { font-family: var(--rn-display); font-size: clamp(26px, 3.2vw, 36px); line-height: 1.12; font-weight: 800; letter-spacing: -.025em; margin: 0; }
  .rn-sub { color: var(--rn-muted); margin: 8px 0 0; font-size: 16px; }
  .rn-band { background: var(--rn-card); border-top: 1px solid var(--rn-line); border-bottom: 1px solid var(--rn-line); }

  /* Category tiles */
  .rn-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; }
  .rn-cat { position: relative; display: flex; flex-direction: column; justify-content: flex-end; min-height: 150px; border-radius: var(--rn-radius); overflow: hidden; background: var(--rn-card); border: 1px solid var(--rn-line); text-decoration: none; color: var(--rn-ink); padding: 16px; transition: transform .18s, box-shadow .18s; }
  .rn-cat:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(15,23,42,.1); color: var(--rn-ink); }
  .rn-cat img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .rn-cat.has-photo { color: #fff; border: 0; }
  .rn-cat.has-photo::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, transparent 30%, rgba(15,23,42,.82) 100%); }
  .rn-cat-ic { position: absolute; top: 16px; inset-inline-start: 16px; width: 44px; height: 44px; border-radius: 12px; background: var(--rn-accent-soft); color: var(--rn-accent-ink); display: inline-flex; align-items: center; justify-content: center; }
  .rn-cat-ic svg { width: 22px; height: 22px; }
  .rn-cat-name { position: relative; z-index: 1; font-weight: 800; font-size: 17px; font-family: var(--rn-display); }
  .rn-cat-count { position: relative; z-index: 1; font-size: 13px; opacity: .8; margin-top: 2px; }

  /* Item cards */
  .rn-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
  .rn-card { display: flex; flex-direction: column; background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: var(--rn-radius); overflow: hidden; text-decoration: none; color: var(--rn-ink); transition: transform .18s, box-shadow .18s, border-color .18s; }
  .rn-card:hover { transform: translateY(-2px); box-shadow: 0 16px 34px rgba(15,23,42,.1); border-color: var(--rn-line-2); color: var(--rn-ink); }
  .rn-card-photo { position: relative; aspect-ratio: 4 / 3; background: var(--rn-photo); overflow: hidden; }
  .rn-card-photo img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s; }
  .rn-card:hover .rn-card-photo img { transform: scale(1.04); }
  .rn-card-ph { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--rn-muted); }
  .rn-card-ph svg { width: 48px; height: 48px; opacity: .5; }
  .rn-badges { position: absolute; top: 12px; inset-inline-start: 12px; display: flex; gap: 6px; flex-wrap: wrap; }
  .rn-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; background: rgba(255,255,255,.94); color: #0f172a; }
  .rn-badge svg { width: 13px; height: 13px; }
  .rn-badge-accent { background: var(--rn-accent); color: #fff; }
  .rn-badge-ok { background: var(--rn-ok-soft); color: var(--rn-ok); }
  .rn-badge-warn { background: var(--rn-warn-soft); color: var(--rn-warn); }
  .rn-badge-bad { background: var(--rn-bad-soft); color: var(--rn-bad); }
  .rn-badge-muted { background: var(--rn-soft); color: var(--rn-ink-2); }
  .rn-card-body { padding: 16px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
  .rn-card-cat { font-size: 12px; font-weight: 700; color: var(--rn-accent-ink); text-transform: uppercase; letter-spacing: .06em; }
  .rn-card-title { font-family: var(--rn-display); font-weight: 800; font-size: 18px; line-height: 1.25; margin: 0; letter-spacing: -.01em; }
  .rn-card-meta { font-size: 13.5px; color: var(--rn-muted); margin-top: -4px; }
  .rn-chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .rn-chip { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--rn-ink-2); background: var(--rn-soft); border-radius: 8px; padding: 5px 8px; }
  .rn-chip svg { width: 14px; height: 14px; color: var(--rn-muted); }
  .rn-card-foot { margin-top: auto; display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; padding-top: 12px; border-top: 1px solid var(--rn-line); }
  .rn-price { font-family: var(--rn-display); font-weight: 800; font-size: 21px; letter-spacing: -.02em; }
  .rn-price small { font-family: 'Inter', sans-serif; font-weight: 600; font-size: 13px; color: var(--rn-muted); letter-spacing: 0; }
  .rn-from { display: block; font-size: 12px; color: var(--rn-muted); font-weight: 600; }
  .rn-deposit { font-size: 12.5px; color: var(--rn-muted); text-align: end; }

  /* Steps + trust + about + visit */
  .rn-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; counter-reset: rnstep; }
  @media (max-width: 760px) { .rn-steps { grid-template-columns: 1fr; } }
  .rn-step { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: var(--rn-radius); padding: 22px; }
  .rn-step-n { width: 38px; height: 38px; border-radius: 11px; background: var(--rn-accent); color: #fff; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; font-family: var(--rn-display); }
  .rn-step h3 { margin: 0 0 6px; font-size: 18px; font-family: var(--rn-display); font-weight: 800; }
  .rn-step p { margin: 0; color: var(--rn-muted); line-height: 1.55; }
  .rn-trust { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
  @media (max-width: 900px) { .rn-trust { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 480px) { .rn-trust { grid-template-columns: 1fr; } }
  .rn-trust-item { display: flex; gap: 12px; align-items: flex-start; }
  .rn-trust-ic { width: 44px; height: 44px; flex: none; border-radius: 12px; background: var(--rn-accent-soft); color: var(--rn-accent-ink); display: inline-flex; align-items: center; justify-content: center; }
  .rn-trust-ic svg { width: 22px; height: 22px; }
  .rn-trust-item b { display: block; font-size: 15.5px; margin-bottom: 2px; }
  .rn-trust-item span { color: var(--rn-muted); font-size: 14px; line-height: 1.45; }
  .rn-about { display: grid; grid-template-columns: 1fr 1fr; gap: 44px; align-items: center; }
  .rn-about.no-photo { grid-template-columns: 1fr; max-width: 760px; }
  @media (max-width: 860px) { .rn-about { grid-template-columns: 1fr; gap: 24px; } }
  .rn-about-photo { border-radius: 22px; overflow: hidden; aspect-ratio: 4 / 3; background: var(--rn-photo); }
  .rn-about-photo img { width: 100%; height: 100%; object-fit: cover; }
  .rn-about p { color: var(--rn-ink-2); line-height: 1.7; font-size: 16px; white-space: pre-line; }
  .rn-visit { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
  @media (max-width: 860px) { .rn-visit { grid-template-columns: 1fr; } }
  .rn-visit-card { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: var(--rn-radius); padding: 22px; }
  .rn-visit-card h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 16px; font-family: var(--rn-display); font-weight: 800; }
  .rn-visit-card h3 svg { width: 20px; height: 20px; color: var(--rn-accent-ink); }
  .rn-visit-card ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; color: var(--rn-ink-2); }
  .rn-visit-card a { color: var(--rn-ink-2); }

  /* Catalogue */
  .rn-page-head { padding: 34px 0 18px; }
  .rn-crumbs { display: flex; flex-wrap: wrap; gap: 6px; font-size: 13.5px; color: var(--rn-muted); margin-bottom: 10px; }
  .rn-crumbs a { color: var(--rn-muted); text-decoration: none; }
  .rn-crumbs a:hover { color: var(--rn-accent-ink); }
  .rn-datebar { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: var(--rn-radius); padding: 14px; display: grid; grid-template-columns: 1fr 1fr auto auto; gap: 10px; align-items: end; margin-bottom: 18px; }
  @media (max-width: 760px) { .rn-datebar { grid-template-columns: 1fr 1fr; } .rn-datebar .rn-btn { grid-column: span 1; } }
  @media (max-width: 420px) { .rn-datebar { grid-template-columns: 1fr; } }
  .rn-layout { display: grid; grid-template-columns: 270px 1fr; gap: 24px; align-items: start; padding-bottom: 64px; }
  @media (max-width: 960px) { .rn-layout { grid-template-columns: 1fr; } }
  .rn-filters { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: var(--rn-radius); padding: 18px; }
  /* The aside sticks (the panel is the whole grid cell); static on phones. */
  .rn-layout > aside { position: sticky; top: 88px; align-self: start; }
  @media (max-width: 960px) { .rn-layout > aside { position: static; } .rn-filters.rn-hide-sm.is-open { display: block !important; } }
  .rn-filter { padding: 14px 0; border-top: 1px solid var(--rn-line); }
  .rn-filter:first-of-type { border-top: 0; padding-top: 0; }
  .rn-filter h4 { margin: 0 0 10px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--rn-muted); }
  .rn-filter-list { display: grid; gap: 4px; }
  .rn-filter-list a, .rn-filter-list label { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 7px 9px; border-radius: 9px; color: var(--rn-ink-2); text-decoration: none; font-size: 14.5px; cursor: pointer; }
  .rn-filter-list a:hover, .rn-filter-list label:hover { background: var(--rn-soft); }
  .rn-filter-list a.is-active { background: var(--rn-accent-soft); color: var(--rn-accent-ink); font-weight: 700; }
  .rn-filter-list label { justify-content: flex-start; }
  .rn-filter-list input { accent-color: var(--rn-accent); width: 16px; height: 16px; }
  .rn-count { font-size: 12px; color: var(--rn-muted); font-weight: 600; }
  .rn-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
  .rn-toolbar .rn-input { min-height: 40px; width: auto; padding-block: 6px; }
  .rn-results { color: var(--rn-muted); font-size: 14.5px; }
  .rn-empty { text-align: center; padding: 56px 20px; background: var(--rn-card); border: 1px dashed var(--rn-line-2); border-radius: var(--rn-radius); color: var(--rn-muted); }
  .rn-empty svg { width: 42px; height: 42px; opacity: .55; margin-bottom: 8px; }
  .rn-empty h3 { color: var(--rn-ink); margin: 0 0 6px; font-family: var(--rn-display); }
  .rn-pager { display: flex; justify-content: center; gap: 6px; margin-top: 26px; flex-wrap: wrap; }
  .rn-pager a, .rn-pager span { min-width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; border: 1px solid var(--rn-line); background: var(--rn-card); color: var(--rn-ink-2); text-decoration: none; font-weight: 600; padding: 0 10px; }
  .rn-pager .is-active { background: var(--rn-accent); color: #fff; border-color: var(--rn-accent); }
  .rn-alert { border-radius: 12px; padding: 12px 14px; font-size: 14.5px; display: flex; gap: 10px; align-items: flex-start; }
  .rn-alert svg { width: 18px; height: 18px; flex: none; margin-top: 1px; }
  .rn-alert-warn { background: var(--rn-warn-soft); color: var(--rn-warn); }
  .rn-alert-ok { background: var(--rn-ok-soft); color: var(--rn-ok); }
  .rn-alert-bad { background: var(--rn-bad-soft); color: var(--rn-bad); }
  .rn-alert-info { background: var(--rn-accent-soft); color: var(--rn-accent-ink); }

  /* Item page */
  .rn-item { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: 28px; align-items: start; padding-bottom: 64px; }
  @media (max-width: 1020px) { .rn-item { grid-template-columns: 1fr; } }
  .rn-gallery-main { position: relative; border-radius: 20px; overflow: hidden; aspect-ratio: 16 / 10; background: var(--rn-photo); }
  .rn-gallery-main img { width: 100%; height: 100%; object-fit: cover; }
  .rn-thumbs { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-top: 8px; }
  .rn-thumbs button { aspect-ratio: 1; border-radius: 10px; overflow: hidden; border: 2px solid transparent; padding: 0; cursor: pointer; background: var(--rn-photo); }
  .rn-thumbs button.is-active { border-color: var(--rn-accent); }
  .rn-thumbs img { width: 100%; height: 100%; object-fit: cover; }
  .rn-gallery-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border-radius: 999px; border: 0; background: rgba(255,255,255,.92); color: #0f172a; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
  .rn-gallery-nav.prev { inset-inline-start: 12px; }
  .rn-gallery-nav.next { inset-inline-end: 12px; }
  .rn-gallery-nav svg { width: 20px; height: 20px; }
  .rn-item-title { font-family: var(--rn-display); font-size: clamp(28px, 3.6vw, 40px); font-weight: 800; letter-spacing: -.03em; line-height: 1.1; margin: 18px 0 6px; }
  .rn-item-sub { color: var(--rn-muted); font-size: 16px; display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }
  .rn-item-sub span { display: inline-flex; align-items: center; gap: 5px; }
  .rn-item-sub svg { width: 16px; height: 16px; }
  .rn-keyspecs { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin: 20px 0; }
  .rn-keyspec { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: 14px; padding: 12px 14px; display: flex; gap: 10px; align-items: center; }
  .rn-keyspec svg { width: 22px; height: 22px; color: var(--rn-accent-ink); flex: none; }
  .rn-keyspec small { display: block; color: var(--rn-muted); font-size: 12px; }
  .rn-keyspec b { font-size: 15px; }
  .rn-block { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: var(--rn-radius); padding: 22px; margin-top: 18px; }
  .rn-block h2 { font-family: var(--rn-display); font-size: 20px; font-weight: 800; margin: 0 0 14px; letter-spacing: -.01em; }
  .rn-desc { color: var(--rn-ink-2); line-height: 1.7; white-space: pre-line; }
  .rn-specs { width: 100%; border-collapse: collapse; }
  .rn-specs th, .rn-specs td { text-align: start; padding: 10px 0; border-bottom: 1px solid var(--rn-line); font-size: 14.5px; vertical-align: top; }
  .rn-specs th { color: var(--rn-muted); font-weight: 600; width: 45%; padding-inline-end: 12px; }
  .rn-specs tr:last-child th, .rn-specs tr:last-child td { border-bottom: 0; }
  .rn-rates { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
  .rn-rate { border: 1px solid var(--rn-line); border-radius: 12px; padding: 12px; }
  .rn-rate small { display: block; color: var(--rn-muted); font-size: 12.5px; font-weight: 600; }
  .rn-rate b { font-family: var(--rn-display); font-size: 18px; }
  .rn-rate.is-main { border-color: var(--rn-accent); background: var(--rn-accent-soft); }
  .rn-tags { display: flex; flex-wrap: wrap; gap: 8px; }
  .rn-tag { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--rn-line); border-radius: 999px; padding: 6px 12px; font-size: 14px; color: var(--rn-ink-2); }
  .rn-tag svg { width: 15px; height: 15px; color: var(--rn-ok); }

  /* Booking panel */
  .rn-book { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: 20px; padding: 20px; box-shadow: 0 18px 44px rgba(15,23,42,.08); }
  .rn-book-aside { position: sticky; top: 88px; align-self: start; }
  .rn-book-aside.is-static { position: static; }
  @media (max-width: 1020px) { .rn-book-aside { position: static; } }
  .rn-book-price { display: flex; align-items: baseline; gap: 6px; margin-bottom: 4px; }
  .rn-book-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 14px 0; }
  .rn-book-grid .rn-when { grid-template-columns: 1fr; }
  .rn-quote { background: var(--rn-soft); border-radius: 14px; padding: 14px; margin: 12px 0; display: grid; gap: 8px; font-size: 14.5px; }
  .rn-quote-row { display: flex; justify-content: space-between; gap: 10px; color: var(--rn-ink-2); }
  .rn-quote-total { border-top: 1px solid var(--rn-line-2); padding-top: 8px; font-weight: 800; color: var(--rn-ink); font-size: 16px; }
  .rn-note { color: var(--rn-muted); font-size: 12.5px; line-height: 1.5; margin-top: 10px; }
  .rn-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .rn-form-grid .rn-full { grid-column: 1 / -1; }
  @media (max-width: 520px) { .rn-form-grid { grid-template-columns: 1fr; } }
  .rn-form-section { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--rn-line); }
  .rn-form-section h3 { font-size: 15px; font-weight: 800; margin: 0 0 12px; font-family: var(--rn-display); }
  .rn-hp { position: absolute !important; left: -10000px !important; width: 1px; height: 1px; overflow: hidden; }

  /* Availability calendar */
  .rn-cal-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
  .rn-cal-head b { font-family: var(--rn-display); font-size: 16px; }
  .rn-cal-months { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
  @media (max-width: 640px) { .rn-cal-months { grid-template-columns: 1fr; } }
  .rn-cal-month h4 { margin: 0 0 8px; font-size: 14px; font-weight: 800; text-align: center; }
  .rn-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
  .rn-cal-dow { font-size: 11.5px; color: var(--rn-muted); text-align: center; font-weight: 700; padding: 4px 0; }
  .rn-day { aspect-ratio: 1; border-radius: 9px; border: 0; font: inherit; font-size: 13.5px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; background: var(--rn-ok-soft); color: var(--rn-ok); cursor: pointer; position: relative; }
  .rn-day.is-partial { background: var(--rn-warn-soft); color: var(--rn-warn); }
  .rn-day.is-busy { background: var(--rn-bad-soft); color: var(--rn-bad); text-decoration: line-through; cursor: not-allowed; }
  .rn-day.is-end { background: var(--rn-soft); color: var(--rn-ink-2); }
  .rn-day.is-closed { background: transparent; color: var(--rn-line-2); cursor: default; }
  .rn-day.is-sel { background: var(--rn-accent); color: #fff; }
  .rn-day.is-range { box-shadow: inset 0 0 0 2px var(--rn-accent); }
  .rn-day.is-empty { visibility: hidden; }
  .rn-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12.5px; color: var(--rn-muted); margin-top: 12px; }
  .rn-legend i { display: inline-block; width: 12px; height: 12px; border-radius: 4px; margin-inline-end: 6px; vertical-align: -1px; }

  /* Status page */
  .rn-status { max-width: 820px; margin: 0 auto; padding: 40px 0 72px; }
  .rn-status-card { background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: 22px; padding: 26px; }
  .rn-status-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
  .rn-status-ic { width: 54px; height: 54px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
  .rn-status-ic svg { width: 28px; height: 28px; }
  .rn-timeline { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 18px 0; }
  .rn-timeline div { border-top: 4px solid var(--rn-line); padding-top: 8px; font-size: 13px; color: var(--rn-muted); font-weight: 600; }
  .rn-timeline div.is-done { border-color: var(--rn-accent); color: var(--rn-ink); }
  .rn-summary { display: grid; grid-template-columns: 96px 1fr; gap: 14px; align-items: center; padding: 14px; border: 1px solid var(--rn-line); border-radius: 14px; }
  .rn-summary img, .rn-summary .rn-card-ph { width: 96px; height: 72px; border-radius: 10px; object-fit: cover; background: var(--rn-photo); }

  /* Account */
  .rn-list { display: grid; gap: 12px; }
  .rn-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; background: var(--rn-card); border: 1px solid var(--rn-line); border-radius: 14px; padding: 14px 16px; text-decoration: none; color: var(--rn-ink); }
  .rn-row:hover { border-color: var(--rn-line-2); color: var(--rn-ink); }
  .rn-row small { color: var(--rn-muted); display: block; margin-top: 2px; }

  /* Footer */
  .rn-footer { background: var(--rn-dark); color: #cbd5e1; padding: 52px 0 26px; margin-top: 0; }
  .rn-footer-grid { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1.1fr; gap: 28px; }
  @media (max-width: 860px) { .rn-footer-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 520px) { .rn-footer-grid { grid-template-columns: 1fr; } }
  .rn-footer-brand { font-family: var(--rn-display); font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 10px; }
  .rn-footer h4 { color: #fff; font-size: 14px; font-weight: 800; margin: 0 0 12px; letter-spacing: .02em; }
  .rn-footer ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; font-size: 14.5px; }
  .rn-footer a { color: #cbd5e1; text-decoration: none; }
  .rn-footer a:hover { color: #fff; }
  .rn-footer p { margin: 0; line-height: 1.6; font-size: 14.5px; color: #94a3b8; }
  .rn-social { display: flex; gap: 8px; margin-top: 16px; }
  .rn-social a { width: 38px; height: 38px; border-radius: 10px; background: #1e293b; display: inline-flex; align-items: center; justify-content: center; }
  .rn-social svg { width: 18px; height: 18px; }
  .rn-footer-bottom { border-top: 1px solid #1e293b; margin-top: 34px; padding-top: 18px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; color: #94a3b8; }
  .rn-spin { width: 18px; height: 18px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 999px; animation: rnspin .7s linear infinite; display: inline-block; }
  @keyframes rnspin { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) { .rn-card, .rn-cat, .rn-card-photo img { transition: none; } .rn-spin { animation-duration: 2s; } }
</style>
