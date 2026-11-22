{{-- Restaurant theme skin. Embedded CSS (no Tailwind rebuild): warm cream
     ground, espresso ink, one accent (the store's primary colour, terracotta
     by default) and a serif display face; `rs-*` components for header,
     hero, menu, dish dialog, booking, visit and footer; the shared tokens are
     re-tinted so cart, checkout and account pages match. --}}
@php
  $rsAccent = $s->primary_color ?: '#b4441f';
  // Stock defaults of the other themes read as "unset" here.
  if (in_array(strtolower($rsAccent), ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#0f766e', '#1a7f6b', '#16a34a', '#8a1c2b', '#c2410c'], true)) { $rsAccent = '#b4441f'; }
  $rsRgb = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [180, 68, 31];
      return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  };
  [$rr, $rg, $rb] = $rsRgb($rsAccent);
  $rsInk = sprintf('#%02x%02x%02x', (int) ($rr * .8), (int) ($rg * .8), (int) ($rb * .8));
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500..800&family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root {
    --color-bg-base: 251 246 239;
    --color-bg-surface: 255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted: 244 236 226;
    --color-border-subtle: 234 223 210;
    --color-border-strong: 216 201 184;
    --color-fg-primary: 43 29 22;
    --color-fg-secondary: 74 58 49;
    --color-fg-muted: 117 101 90;
    --color-accent-400: {{ $rr }} {{ $rg }} {{ $rb }};
    --color-accent-500: {{ $rr }} {{ $rg }} {{ $rb }};
    --color-accent-600: {{ (int) ($rr * .85) }} {{ (int) ($rg * .85) }} {{ (int) ($rb * .85) }};
    --color-accent-glow: rgba({{ $rr }}, {{ $rg }}, {{ $rb }}, .22);
    --rs-ink: #2b1d16;
    --rs-ink-2: #4a3a31;
    --rs-muted: #75655a;
    --rs-line: #eadfd2;
    --rs-ground: #fbf6ef;
    --rs-cream: #f4ece2;
    --rs-card: #ffffff;
    --rs-photo: #efe5d8;
    --rs-dark: #2b1d16;
    --rs-dark-2: #3a2a21;
    --rs-dark-muted: #cbb9a8;
    --rs-herb: #3f6b3a;
    --rs-herb-soft: #e3eedd;
    --rs-chili: #b42318;
    --rs-chili-soft: #fde7e4;
    --rs-accent: {{ $rsAccent }};
    --rs-accent-ink: {{ $rsInk }};
    --rs-accent-soft: rgba({{ $rr }}, {{ $rg }}, {{ $rb }}, .12);
    --rs-display: 'Fraunces', Georgia, 'Times New Roman', serif;
  }
  .dark {
    --color-bg-base: 23 18 15; --color-bg-surface: 33 27 23; --color-bg-elevated: 33 27 23; --color-bg-muted: 44 36 31;
    --color-border-subtle: 58 48 41; --color-border-strong: 74 62 53;
    --color-fg-primary: 244 237 229; --color-fg-secondary: 220 208 196; --color-fg-muted: 179 164 151;
    --rs-ink: #f4ede5; --rs-ink-2: #dcd0c4; --rs-muted: #b3a497; --rs-line: #3a3029; --rs-ground: #17120f; --rs-cream: #211b17;
    --rs-card: #211b17; --rs-photo: #2c241f; --rs-accent-ink: #f0a582; --rs-herb-soft: #24331f; --rs-chili-soft: #3a1c18;
  }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--rs-ground); color: var(--rs-ink); }
  .rs-container { width: 100%; max-width: 1180px; margin: 0 auto; padding: 0 20px; }
  .rs-display { font-family: var(--rs-display); letter-spacing: -.01em; }
  [x-cloak] { display: none !important; }

  /* Buttons */
  .rs-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 22px; border-radius: 999px; background: var(--rs-accent); color: #fff; font-weight: 700; border: 0; cursor: pointer; text-decoration: none; transition: filter .15s, transform .15s; }
  .rs-btn:hover { filter: brightness(.94); }
  .rs-btn:active { transform: translateY(1px); }
  .rs-btn:disabled { opacity: .5; cursor: not-allowed; }
  .rs-btn-ghost { background: transparent; color: var(--rs-ink); border: 1.5px solid var(--rs-line); }
  .rs-btn-ghost:hover { border-color: var(--rs-accent); color: var(--rs-accent-ink); filter: none; }
  .rs-btn-sm { min-height: 36px; padding: 0 14px; font-size: 14px; }
  .rs-btn:focus-visible, .rs-chip:focus-visible, .rs-dish:focus-visible, .rs-icon-btn:focus-visible, .rs-slot:focus-visible { outline: 3px solid var(--rs-accent-soft); outline-offset: 2px; box-shadow: 0 0 0 2px var(--rs-accent); }
  .rs-icon-btn { position: relative; width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; border: 0; background: transparent; color: var(--rs-ink); cursor: pointer; }
  .rs-icon-btn:hover { background: var(--rs-cream); }
  .rs-icon-btn svg { width: 22px; height: 22px; }
  .rs-icon-btn img { width: 30px; height: 30px; border-radius: 999px; object-fit: cover; }
  .rs-count { position: absolute; top: 2px; inset-inline-end: 0; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--rs-accent); color: #fff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }

  /* Announcement + header */
  .rs-announce { background: var(--rs-dark); color: #f4ede5; font-size: 13px; }
  .rs-announce .rs-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 36px; }
  .rs-announce-mid { flex: 1; text-align: center; font-weight: 600; }
  .rs-announce button { background: none; border: 0; color: inherit; font: inherit; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
  .rs-announce svg { width: 14px; height: 14px; }
  .rs-header { position: sticky; top: 0; z-index: 40; background: color-mix(in srgb, var(--rs-ground) 92%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--rs-line); }
  .rs-header-main { display: flex; align-items: center; gap: 16px; min-height: 70px; }
  .rs-logo { display: inline-flex; align-items: center; gap: 10px; font-family: var(--rs-display); font-size: 22px; font-weight: 700; color: var(--rs-ink); text-decoration: none; }
  .rs-logo img { max-height: 44px; max-width: 170px; object-fit: contain; }
  .rs-logo-mark { width: 38px; height: 38px; border-radius: 999px; background: var(--rs-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
  .rs-logo-mark svg { width: 20px; height: 20px; }
  .rs-nav { display: flex; gap: 4px; list-style: none; margin: 0 0 0 12px; padding: 0; }
  .rs-nav a { display: block; padding: 8px 12px; border-radius: 999px; color: var(--rs-ink-2); font-weight: 600; text-decoration: none; }
  .rs-nav a:hover, .rs-nav a.is-active { background: var(--rs-cream); color: var(--rs-ink); }
  .rs-actions { margin-inline-start: auto; display: flex; align-items: center; gap: 4px; }
  .rs-phone { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: var(--rs-ink-2); text-decoration: none; margin-inline-end: 6px; }
  .rs-phone svg { width: 18px; height: 18px; }
  .rs-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); background: var(--rs-card); border: 1px solid var(--rs-line); border-radius: 14px; padding: 6px; min-width: 190px; box-shadow: 0 12px 30px rgba(43,29,22,.12); z-index: 50; }
  .rs-menu a, .rs-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 10px; border-radius: 9px; color: var(--rs-ink); text-decoration: none; background: none; border: 0; font: inherit; cursor: pointer; text-align: start; }
  .rs-menu a:hover, .rs-menu button:hover { background: var(--rs-cream); }
  .rs-menu svg { width: 16px; height: 16px; }
  .rs-menu hr { border: 0; border-top: 1px solid var(--rs-line); margin: 4px 0; }
  .rs-show-sm { display: none; }
  @media (max-width: 900px) { .rs-nav, .rs-hide-sm, .rs-phone { display: none !important; } .rs-show-sm { display: inline-flex; } }

  /* Hero */
  .rs-hero { padding: 56px 0 40px; }
  .rs-hero-grid { display: grid; grid-template-columns: 1.05fr .95fr; gap: 44px; align-items: center; }
  .rs-hero-plain .rs-hero-grid { grid-template-columns: 1fr; text-align: center; max-width: 820px; margin: 0 auto; }
  .rs-kicker { display: inline-block; color: var(--rs-accent-ink); font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 13px; margin-bottom: 10px; }
  .rs-hero-title { font-family: var(--rs-display); font-size: clamp(38px, 6vw, 68px); line-height: 1.02; margin: 0 0 16px; font-weight: 700; }
  .rs-hero-sub { font-size: 18px; color: var(--rs-ink-2); margin: 0 0 26px; max-width: 560px; }
  .rs-hero-plain .rs-hero-sub { margin-inline: auto; }
  .rs-hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }
  .rs-hero-plain .rs-hero-actions, .rs-hero-plain .rs-facts { justify-content: center; }
  .rs-hero-media { position: relative; border-radius: 28px; overflow: hidden; aspect-ratio: 4 / 3.4; background: var(--rs-photo); }
  .rs-hero-media img { width: 100%; height: 100%; object-fit: cover; }
  .rs-facts { display: flex; gap: 18px; flex-wrap: wrap; margin-top: 26px; color: var(--rs-ink-2); font-size: 14px; }
  .rs-facts span { display: inline-flex; align-items: center; gap: 6px; }
  .rs-facts svg { width: 18px; height: 18px; color: var(--rs-accent-ink); }
  @media (max-width: 860px) { .rs-hero-grid { grid-template-columns: 1fr; gap: 26px; } .rs-hero { padding-top: 32px; } }

  /* Sections */
  .rs-section { padding: 44px 0; }
  .rs-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
  .rs-h2 { font-family: var(--rs-display); font-size: clamp(28px, 3.6vw, 40px); margin: 0; font-weight: 700; }
  .rs-lead { color: var(--rs-muted); margin: 6px 0 0; max-width: 640px; }

  /* Menu */
  .rs-tabs { position: sticky; top: 70px; z-index: 30; background: var(--rs-ground); padding: 10px 0; border-bottom: 1px solid var(--rs-line); margin-bottom: 18px; }
  .rs-tabs-row { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; }
  .rs-tabs-row::-webkit-scrollbar { display: none; }
  .rs-chip { flex: none; min-height: 38px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--rs-line); background: var(--rs-card); color: var(--rs-ink-2); font: inherit; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
  .rs-chip:hover { border-color: var(--rs-accent); }
  .rs-chip.is-on { background: var(--rs-ink); border-color: var(--rs-ink); color: var(--rs-ground); }
  .rs-tools { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
  .rs-search { flex: 1; min-width: 220px; position: relative; }
  .rs-search input { width: 100%; min-height: 44px; padding: 0 14px 0 42px; border-radius: 999px; border: 1.5px solid var(--rs-line); background: var(--rs-card); color: var(--rs-ink); font: inherit; }
  [dir="rtl"] .rs-search input { padding: 0 42px 0 14px; }
  .rs-search svg { position: absolute; inset-inline-start: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--rs-muted); }
  .rs-cat { margin-bottom: 34px; scroll-margin-top: 150px; }
  .rs-cat-title { font-family: var(--rs-display); font-size: 26px; margin: 0 0 14px; font-weight: 700; display: flex; align-items: baseline; gap: 10px; }
  .rs-cat-title small { font-family: Inter, sans-serif; font-size: 13px; color: var(--rs-muted); font-weight: 600; }
  .rs-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
  @media (max-width: 760px) { .rs-grid { grid-template-columns: 1fr; } }
  .rs-dish { display: flex; gap: 14px; background: var(--rs-card); border: 1px solid var(--rs-line); border-radius: 18px; padding: 14px; text-align: start; cursor: pointer; font: inherit; color: inherit; width: 100%; transition: border-color .15s, box-shadow .15s; }
  .rs-dish:hover { border-color: color-mix(in srgb, var(--rs-accent) 45%, var(--rs-line)); box-shadow: 0 8px 22px rgba(43,29,22,.07); }
  .rs-dish.is-off { cursor: default; opacity: .72; }
  .rs-dish-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  .rs-dish-name { font-weight: 700; font-size: 16px; }
  .rs-dish-desc { color: var(--rs-muted); font-size: 14px; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .rs-dish-foot { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .rs-price { font-weight: 700; font-variant-numeric: tabular-nums; }
  .rs-dish-photo { position: relative; width: 116px; height: 116px; flex: none; border-radius: 14px; overflow: hidden; background: var(--rs-photo); }
  .rs-dish-photo img { width: 100%; height: 100%; object-fit: cover; }
  .rs-add { width: 36px; height: 36px; border-radius: 999px; background: var(--rs-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; flex: none; }
  .rs-add svg { width: 18px; height: 18px; }
  .rs-off { font-size: 12px; font-weight: 700; color: var(--rs-chili); background: var(--rs-chili-soft); padding: 3px 9px; border-radius: 999px; }
  .rs-tags { display: flex; gap: 5px; flex-wrap: wrap; }
  .rs-tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--rs-herb-soft); color: var(--rs-herb); }
  .rs-tag.spicy { background: var(--rs-chili-soft); color: var(--rs-chili); }
  .rs-empty { text-align: center; color: var(--rs-muted); padding: 40px 0; }

  /* Dish dialog */
  .rs-modal-bg { position: fixed; inset: 0; background: rgba(23,18,15,.55); z-index: 1200; display: flex; align-items: flex-end; justify-content: center; }
  @media (min-width: 640px) { .rs-modal-bg { align-items: center; padding: 20px; } }
  .rs-modal { background: var(--rs-card); width: 100%; max-width: 560px; max-height: 92vh; overflow-y: auto; border-radius: 22px 22px 0 0; }
  @media (min-width: 640px) { .rs-modal { border-radius: 22px; } }
  .rs-modal-photo { aspect-ratio: 16 / 9; background: var(--rs-photo); position: relative; }
  .rs-modal-photo img { width: 100%; height: 100%; object-fit: cover; }
  .rs-modal-close { position: absolute; top: 12px; inset-inline-end: 12px; background: var(--rs-card) !important; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
  .rs-modal-body { padding: 20px; }
  .rs-modal h3 { font-family: var(--rs-display); font-size: 26px; margin: 0 0 6px; }
  .rs-meta { display: flex; gap: 12px; flex-wrap: wrap; color: var(--rs-muted); font-size: 13px; margin: 8px 0; }
  .rs-grp { margin-top: 18px; }
  .rs-grp-head { display: flex; justify-content: space-between; align-items: center; font-weight: 700; margin-bottom: 6px; }
  .rs-rule { font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: var(--rs-cream); color: var(--rs-ink-2); }
  .rs-rule.req { background: var(--rs-accent-soft); color: var(--rs-accent-ink); }
  .rs-opt { display: flex; align-items: center; gap: 12px; min-height: 48px; border-bottom: 1px solid var(--rs-line); cursor: pointer; }
  .rs-opt input { width: 20px; height: 20px; accent-color: var(--rs-accent); flex: none; }
  .rs-opt .p { margin-inline-start: auto; color: var(--rs-muted); font-variant-numeric: tabular-nums; }
  .rs-opt.is-off { opacity: .5; cursor: not-allowed; }
  .rs-modal-foot { position: sticky; bottom: 0; background: var(--rs-card); padding: 14px 20px; border-top: 1px solid var(--rs-line); display: flex; gap: 12px; align-items: center; }
  .rs-qty { display: inline-flex; align-items: center; border: 1.5px solid var(--rs-line); border-radius: 999px; }
  .rs-qty button { width: 40px; height: 42px; border: 0; background: none; font-size: 20px; color: var(--rs-ink); cursor: pointer; }
  .rs-qty span { min-width: 26px; text-align: center; font-weight: 700; }
  .rs-hint { color: var(--rs-muted); font-size: 13px; margin-top: 10px; }

  /* Booking */
  .rs-book { display: grid; grid-template-columns: .9fr 1.1fr; gap: 28px; background: var(--rs-dark); color: #f4ede5; border-radius: 28px; padding: 36px; }
  .rs-book .rs-h2 { color: #fff; }
  .rs-book .rs-lead { color: var(--rs-dark-muted); }
  .rs-book-card { background: var(--rs-card); color: var(--rs-ink); border-radius: 20px; padding: 22px; }
  .rs-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; font-weight: 600; font-size: 14px; }
  .rs-field input, .rs-field select, .rs-field textarea { min-height: 44px; padding: 0 12px; border-radius: 12px; border: 1.5px solid var(--rs-line); background: var(--rs-ground); color: var(--rs-ink); font: inherit; font-weight: 400; }
  .rs-field textarea { padding: 10px 12px; min-height: 70px; }
  .rs-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .rs-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr)); gap: 8px; margin: 6px 0 14px; }
  .rs-slot { min-height: 40px; border-radius: 10px; border: 1.5px solid var(--rs-line); background: var(--rs-card); font: inherit; font-weight: 700; color: var(--rs-ink); cursor: pointer; font-variant-numeric: tabular-nums; }
  .rs-slot.is-on { background: var(--rs-accent); border-color: var(--rs-accent); color: #fff; }
  .rs-slot:disabled { opacity: .35; cursor: not-allowed; text-decoration: line-through; }
  .rs-ok { background: var(--rs-herb-soft); color: var(--rs-herb); border-radius: 14px; padding: 16px; font-weight: 600; }
  .rs-err { color: var(--rs-chili); font-weight: 600; font-size: 14px; margin: 6px 0; }
  @media (max-width: 860px) { .rs-book { grid-template-columns: 1fr; padding: 22px; } .rs-row2 { grid-template-columns: 1fr; } }

  /* About + visit */
  .rs-about { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; align-items: center; }
  .rs-about-img { border-radius: 24px; overflow: hidden; aspect-ratio: 4 / 3; background: var(--rs-photo); }
  .rs-about-img img { width: 100%; height: 100%; object-fit: cover; }
  .rs-prose { color: var(--rs-ink-2); font-size: 17px; line-height: 1.65; white-space: pre-line; }
  .rs-visit { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
  .rs-visit-card { background: var(--rs-card); border: 1px solid var(--rs-line); border-radius: 18px; padding: 20px; }
  .rs-visit-card h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-size: 16px; }
  .rs-visit-card h3 svg { width: 18px; height: 18px; color: var(--rs-accent-ink); }
  .rs-visit-card p { margin: 0 0 4px; color: var(--rs-ink-2); }
  .rs-visit-card a { color: var(--rs-accent-ink); font-weight: 600; }
  @media (max-width: 860px) { .rs-about, .rs-visit { grid-template-columns: 1fr; } }

  /* Footer */
  .rs-footer { background: var(--rs-dark); color: var(--rs-dark-muted); margin-top: 40px; }
  .rs-footer a { color: #f4ede5; text-decoration: none; }
  .rs-footer a:hover { text-decoration: underline; }
  .rs-footer-grid { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr; gap: 28px; padding: 44px 0 28px; }
  .rs-footer h4 { color: #fff; font-size: 14px; letter-spacing: .06em; text-transform: uppercase; margin: 0 0 12px; }
  .rs-footer ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
  .rs-footer-brand { font-family: var(--rs-display); font-size: 24px; color: #fff; margin-bottom: 10px; }
  .rs-footer-bottom { border-top: 1px solid var(--rs-dark-2); padding: 16px 0; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; }
  .rs-social { display: flex; gap: 8px; margin-top: 12px; }
  .rs-social a { width: 36px; height: 36px; border-radius: 999px; background: var(--rs-dark-2); display: inline-flex; align-items: center; justify-content: center; }
  .rs-social svg { width: 18px; height: 18px; }
  @media (max-width: 860px) { .rs-footer-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 520px) { .rs-footer-grid { grid-template-columns: 1fr; } }
</style>
