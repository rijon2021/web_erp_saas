{{-- Booking theme skin. Embedded CSS (no Tailwind rebuild): white ground with
     soft grey sections, deep slate ink, one accent (the store's primary colour,
     or the business type's: rose for salons, blue for clinics, indigo for
     consulting, green for home services, amber for auto, teal for classes,
     violet for events). Display face Plus Jakarta Sans over Inter. `bk-*`
     components for header, hero booking card, services, steps, team, visit,
     the booking steps page, status page and footer. The shared tokens are
     re-tinted so account pages match. --}}
@php
  $bkAccent = \App\Services\Booking\BookingWebsiteService::accent($s->primary_color ?? null);
  $bkRgb = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) return [14, 124, 123];
      return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
  };
  [$br, $bg, $bb] = $bkRgb($bkAccent);
  $bkInk = sprintf('#%02x%02x%02x', (int) ($br * .8), (int) ($bg * .8), (int) ($bb * .8));
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root {
    --color-bg-base: 255 255 255;
    --color-bg-surface: 255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted: 246 247 249;
    --color-border-subtle: 230 233 237;
    --color-border-strong: 207 213 219;
    --color-fg-primary: 19 32 43;
    --color-fg-secondary: 59 74 87;
    --color-fg-muted: 79 93 106;
    --color-accent-400: {{ $br }} {{ $bg }} {{ $bb }};
    --color-accent-500: {{ $br }} {{ $bg }} {{ $bb }};
    --color-accent-600: {{ (int) ($br * .85) }} {{ (int) ($bg * .85) }} {{ (int) ($bb * .85) }};
    --color-accent-glow: rgba({{ $br }}, {{ $bg }}, {{ $bb }}, .22);
    --bk-ink: #13202b;
    --bk-ink-2: #3b4a57;
    --bk-muted: #4f5d6a;
    --bk-line: #e6e9ed;
    --bk-line-2: #cfd5db;
    --bk-ground: #ffffff;
    --bk-soft: #f6f7f9;
    --bk-card: #ffffff;
    --bk-dark: #13202b;
    --bk-ok: #135c32;
    --bk-ok-soft: #e7f5ec;
    --bk-warn: #7a4a00;
    --bk-warn-soft: #fff4e0;
    --bk-bad: #9b1c1c;
    --bk-bad-soft: #fde8e8;
    --bk-accent: {{ $bkAccent }};
    --bk-accent-ink: {{ $bkInk }};
    --bk-accent-soft: rgba({{ $br }}, {{ $bg }}, {{ $bb }}, .09);
    --bk-accent-line: rgba({{ $br }}, {{ $bg }}, {{ $bb }}, .4);
    --bk-display: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  }
  .dark {
    --color-bg-base: 13 19 26; --color-bg-surface: 20 28 37; --color-bg-elevated: 20 28 37; --color-bg-muted: 27 37 48;
    --color-border-subtle: 38 50 64; --color-border-strong: 56 70 86;
    --color-fg-primary: 238 242 246; --color-fg-secondary: 200 209 218; --color-fg-muted: 156 168 180;
    --bk-ink: #eef2f6; --bk-ink-2: #c8d1da; --bk-muted: #9ca8b4; --bk-line: #263240; --bk-line-2: #384656;
    --bk-ground: #0d131a; --bk-soft: #141c25; --bk-card: #141c25; --bk-accent-ink: #e2e8f0;
    --bk-ok: #86efac; --bk-ok-soft: #14321f; --bk-warn: #fcd34d; --bk-warn-soft: #3a2a07; --bk-bad: #fca5a5; --bk-bad-soft: #3b1212;
  }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bk-ground); color: var(--bk-ink); }
  [x-cloak] { display: none !important; }
  .bk-container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 20px; }
  .bk-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .bk-display { font-family: var(--bk-display); letter-spacing: -.02em; }

  /* Buttons + links */
  .bk-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 20px; border-radius: 10px; background: var(--bk-accent); color: #fff; font-weight: 600; font-size: 15px; border: 0; cursor: pointer; text-decoration: none; white-space: nowrap; transition: filter .15s; }
  .bk-btn:hover { filter: brightness(.93); color: #fff; }
  .bk-btn:disabled, .bk-btn.is-disabled { opacity: .5; cursor: not-allowed; filter: none; }
  .bk-btn svg { width: 18px; height: 18px; }
  .bk-btn-ghost { background: var(--bk-card); color: var(--bk-ink); border: 1px solid var(--bk-line-2); }
  .bk-btn-ghost:hover { color: var(--bk-ink); border-color: var(--bk-accent); filter: none; }
  .bk-btn-outline { background: transparent; color: var(--bk-accent-ink); border: 1px solid var(--bk-accent); }
  .bk-btn-outline:hover { color: var(--bk-accent-ink); background: var(--bk-accent-soft); filter: none; }
  .bk-btn-danger { background: var(--bk-card); color: var(--bk-bad); border: 1px solid var(--bk-bad); }
  .bk-btn-danger:hover { color: var(--bk-bad); background: var(--bk-bad-soft); filter: none; }
  .bk-btn-sm { min-height: 40px; padding: 0 14px; font-size: 14px; }
  .bk-btn-block { width: 100%; }
  .bk-btn:focus-visible, .bk-chip:focus-visible, .bk-option:focus-visible, .bk-seg button:focus-visible, .bk-icon-btn:focus-visible, .bk-day:focus-visible { outline: 3px solid var(--bk-accent-line); outline-offset: 2px; }
  .bk-link { color: var(--bk-accent-ink); font-weight: 600; text-decoration: none; }
  .bk-link:hover { text-decoration: underline; color: var(--bk-accent-ink); }
  .bk-icon-btn { width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; border: 0; background: transparent; color: var(--bk-ink); cursor: pointer; text-decoration: none; }
  .bk-icon-btn:hover { background: var(--bk-soft); color: var(--bk-ink); }
  .bk-icon-btn svg { width: 22px; height: 22px; }
  .bk-icon-btn img { width: 30px; height: 30px; border-radius: 999px; object-fit: cover; }

  /* Announcement + header */
  .bk-announce { background: var(--bk-dark); color: #e2e8f0; font-size: 13px; }
  .bk-announce .bk-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 36px; }
  .bk-announce-mid { flex: 1; text-align: center; font-weight: 600; }
  .bk-announce button { background: none; border: 0; color: inherit; font: inherit; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
  .bk-announce svg { width: 14px; height: 14px; }
  .bk-header { position: sticky; top: 0; z-index: 40; background: color-mix(in srgb, var(--bk-card) 95%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--bk-line); }
  .bk-header-main { display: flex; align-items: center; gap: 18px; min-height: 68px; }
  .bk-logo { display: inline-flex; align-items: center; gap: 10px; font-family: var(--bk-display); font-size: 19px; font-weight: 800; color: var(--bk-ink); text-decoration: none; }
  .bk-logo:hover { color: var(--bk-ink); }
  .bk-logo img { max-height: 42px; max-width: 170px; object-fit: contain; }
  .bk-logo-mark { width: 36px; height: 36px; border-radius: 10px; background: var(--bk-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
  .bk-logo-mark svg { width: 20px; height: 20px; }
  .bk-nav { display: flex; gap: 2px; list-style: none; margin: 0; padding: 0; }
  .bk-nav a { display: block; padding: 8px 12px; border-radius: 10px; color: var(--bk-ink-2); font-weight: 500; text-decoration: none; font-size: 15px; }
  .bk-nav a:hover { background: var(--bk-soft); color: var(--bk-ink); }
  .bk-actions { margin-inline-start: auto; display: flex; align-items: center; gap: 6px; }
  .bk-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); background: var(--bk-card); border: 1px solid var(--bk-line); border-radius: 12px; padding: 6px; min-width: 200px; box-shadow: 0 16px 36px rgba(19,32,43,.14); z-index: 50; }
  .bk-menu a, .bk-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 10px; border-radius: 8px; color: var(--bk-ink); text-decoration: none; background: none; border: 0; font: inherit; cursor: pointer; text-align: start; }
  .bk-menu a:hover, .bk-menu button:hover { background: var(--bk-soft); }
  .bk-menu svg { width: 16px; height: 16px; }
  .bk-menu hr { border: 0; border-top: 1px solid var(--bk-line); margin: 4px 0; }
  .bk-show-sm { display: none; }
  @media (max-width: 960px) { .bk-nav, .bk-hide-sm { display: none !important; } .bk-show-sm { display: inline-flex; } }
  .bk-drawer-nav a { display: flex; align-items: center; gap: 10px; padding: 13px 18px; color: var(--bk-ink); font-weight: 600; text-decoration: none; border-bottom: 1px solid var(--bk-line); }
  .bk-drawer-nav a svg { width: 18px; height: 18px; color: var(--bk-muted); }

  /* Hero */
  .bk-hero { background: var(--bk-accent-soft); position: relative; overflow: hidden; }
  .bk-hero-photo { position: absolute; inset: 0; }
  .bk-hero-photo img { width: 100%; height: 100%; object-fit: cover; }
  .bk-hero-photo::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(19,32,43,.82) 0%, rgba(19,32,43,.55) 55%, rgba(19,32,43,.35) 100%); }
  .bk-hero.has-photo .bk-hero-copy { color: #fff; }
  .bk-hero.has-photo .bk-hero-sub, .bk-hero.has-photo .bk-facts { color: #e2e8f0; }
  .bk-hero-grid { position: relative; display: flex; flex-wrap: wrap; gap: 40px; align-items: flex-start; padding: 56px 0 64px; }
  .bk-hero-copy { flex: 1 1 420px; min-width: 0; padding-top: 18px; }
  .bk-pill { display: inline-flex; align-items: center; gap: 8px; background: var(--bk-card); color: var(--bk-ink-2); border: 1px solid var(--bk-line); border-radius: 999px; padding: 6px 14px; font-size: 13px; font-weight: 600; }
  .bk-pill i { width: 8px; height: 8px; border-radius: 50%; background: var(--bk-accent); }
  .bk-hero-title { font-family: var(--bk-display); font-size: clamp(34px, 5vw, 52px); line-height: 1.08; letter-spacing: -.02em; margin: 20px 0 16px; font-weight: 800; }
  .bk-hero-sub { font-size: 18px; line-height: 1.6; color: var(--bk-ink-2); margin: 0 0 26px; max-width: 520px; }
  .bk-facts { display: flex; flex-direction: column; gap: 12px; font-size: 15px; color: var(--bk-ink-2); margin: 0; padding: 0; list-style: none; }
  .bk-facts li { display: flex; align-items: center; gap: 10px; }
  .bk-facts svg { width: 18px; height: 18px; flex: none; }
  .bk-facts a { color: inherit; }
  .bk-card { background: var(--bk-card); border: 1px solid var(--bk-line); border-radius: 16px; padding: 24px; }
  .bk-hero-card { flex: 1 1 440px; min-width: 0; max-width: 560px; box-shadow: 0 12px 32px rgba(19,32,43,.08); color: var(--bk-ink); }
  .bk-seg { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; background: var(--bk-soft); padding: 5px; border-radius: 12px; margin-bottom: 18px; }
  .bk-seg button { border: 0; border-radius: 9px; min-height: 44px; font-weight: 600; cursor: pointer; background: transparent; color: var(--bk-muted); font: inherit; font-weight: 600; }
  .bk-seg button.is-on { background: var(--bk-card); color: var(--bk-ink); box-shadow: 0 1px 3px rgba(19,32,43,.12); }

  /* Fields */
  .bk-form { display: flex; flex-direction: column; gap: 14px; }
  .bk-field { display: flex; flex-direction: column; gap: 6px; font-size: 14px; font-weight: 600; color: var(--bk-ink); }
  .bk-field .bk-hint { font-weight: 400; color: var(--bk-muted); font-size: 13px; }
  .bk-input, .bk-field input:not([type=checkbox]):not([type=radio]):not([type=file]), .bk-field select, .bk-field textarea { min-height: 44px; border: 1px solid var(--bk-line-2); border-radius: 10px; padding: 0 12px; background: var(--bk-card); color: var(--bk-ink); font: inherit; font-weight: 400; width: 100%; box-sizing: border-box; }
  .bk-field textarea { padding: 10px 12px; resize: vertical; min-height: 84px; }
  .bk-field input[type=file] { border: 1px dashed var(--bk-line-2); border-radius: 10px; padding: 10px 12px; font-weight: 400; }
  .bk-field input:focus, .bk-field select:focus, .bk-field textarea:focus { outline: 2px solid var(--bk-accent-line); border-color: var(--bk-accent); }
  .bk-check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; font-weight: 400; }
  .bk-check input { width: 18px; height: 18px; margin: 2px 0 0; flex: none; accent-color: var(--bk-accent); }
  .bk-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
  .bk-err { color: var(--bk-bad); font-size: 13px; font-weight: 500; }
  .bk-note { margin: 0; display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.5; color: var(--bk-muted); }
  .bk-note svg { width: 16px; height: 16px; flex: none; margin-top: 2px; }

  /* Chips: days, times, windows, categories */
  .bk-label { font-size: 14px; font-weight: 600; margin-bottom: 8px; }
  .bk-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
  .bk-times { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 6px; }
  .bk-windows { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 6px; }
  .bk-chip { border: 1px solid var(--bk-line-2); background: var(--bk-card); color: var(--bk-ink); border-radius: 10px; min-height: 44px; padding: 6px 6px; cursor: pointer; font: inherit; font-variant-numeric: tabular-nums; text-align: center; }
  .bk-chip small { display: block; font-size: 12px; color: var(--bk-muted); }
  .bk-chip strong { display: block; font-size: 16px; }
  .bk-chip.is-on { border-color: var(--bk-accent); background: var(--bk-accent); color: #fff; font-weight: 600; }
  .bk-chip.is-on small { color: rgba(255,255,255,.85); }
  .bk-chip:disabled { background: var(--bk-soft); color: var(--bk-muted); cursor: not-allowed; border-color: var(--bk-line); }
  .bk-chip:disabled strong { text-decoration: line-through; }
  .bk-empty-times { color: var(--bk-muted); font-size: 14px; padding: 10px 0; }
  .bk-cats { display: flex; flex-wrap: wrap; gap: 8px; }
  .bk-cats button { border: 1px solid var(--bk-line-2); background: var(--bk-card); color: var(--bk-ink); border-radius: 999px; padding: 10px 16px; min-height: 44px; cursor: pointer; font: inherit; }
  .bk-cats button.is-on { background: var(--bk-ink); border-color: var(--bk-ink); color: var(--bk-ground); font-weight: 600; }

  /* Sections */
  .bk-section { padding: 64px 0; }
  .bk-section-soft { background: var(--bk-soft); }
  .bk-section-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 24px; }
  .bk-h2 { font-family: var(--bk-display); font-size: 32px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 6px; }
  .bk-sub { margin: 0; color: var(--bk-muted); font-size: 16px; line-height: 1.55; }
  .bk-services { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
  .bk-svc { border: 1px solid var(--bk-line); border-radius: 14px; background: var(--bk-card); display: flex; flex-direction: column; overflow: hidden; }
  .bk-svc img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; background: var(--bk-soft); }
  .bk-svc-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
  .bk-svc-cat { font-size: 12px; font-weight: 600; color: var(--bk-muted); text-transform: uppercase; letter-spacing: .06em; }
  .bk-svc h3 { margin: 0; font-size: 18px; font-weight: 700; }
  .bk-svc p { margin: 0; color: var(--bk-muted); font-size: 14px; line-height: 1.5; }
  .bk-svc-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 14px; color: var(--bk-ink-2); margin-top: auto; padding-top: 4px; }
  .bk-svc-meta span { display: inline-flex; align-items: center; gap: 5px; }
  .bk-svc-meta svg { width: 15px; height: 15px; }
  .bk-svc .bk-btn { align-self: flex-start; margin-top: 6px; }
  .bk-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
  .bk-step { background: var(--bk-soft); border-radius: 14px; padding: 24px; }
  .bk-section-soft .bk-step { background: var(--bk-card); }
  .bk-step b { width: 36px; height: 36px; border-radius: 50%; background: var(--bk-accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; margin-bottom: 14px; }
  .bk-step h3 { margin: 0 0 6px; font-size: 18px; }
  .bk-step p { margin: 0; color: var(--bk-muted); line-height: 1.55; }
  .bk-team { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
  .bk-member { background: var(--bk-card); border: 1px solid var(--bk-line); border-radius: 14px; padding: 18px; display: flex; align-items: center; gap: 14px; }
  .bk-avatar { width: 56px; height: 56px; border-radius: 50%; flex: none; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 20px; color: #fff; }
  .bk-member > div strong { display: block; font-size: 16px; }
  .bk-member > div span { color: var(--bk-muted); font-size: 14px; }
  .bk-about { display: flex; flex-wrap: wrap; gap: 32px; align-items: center; }
  .bk-about > * { flex: 1 1 360px; min-width: 0; }
  .bk-about img { width: 100%; border-radius: 16px; object-fit: cover; max-height: 380px; }
  .bk-about p { white-space: pre-line; line-height: 1.7; color: var(--bk-ink-2); }
  .bk-visit { display: flex; flex-wrap: wrap; gap: 32px; }
  .bk-visit > * { flex: 1 1 340px; min-width: 0; }
  .bk-hours { width: 100%; border-collapse: collapse; font-size: 15px; }
  .bk-hours th, .bk-hours td { padding: 12px 0; border-bottom: 1px solid var(--bk-line); text-align: start; font-weight: 500; }
  .bk-hours td { text-align: end; font-variant-numeric: tabular-nums; }
  .bk-hours .closed { color: var(--bk-muted); }
  .bk-contact { display: flex; flex-direction: column; gap: 12px; }
  .bk-contact a, .bk-contact div { display: flex; gap: 10px; align-items: center; color: var(--bk-ink); text-decoration: none; }
  .bk-contact svg { width: 18px; height: 18px; color: var(--bk-muted); flex: none; }
  .bk-empty { text-align: center; padding: 48px 16px; color: var(--bk-muted); }
  .bk-empty svg { width: 40px; height: 40px; color: var(--bk-accent); margin-bottom: 8px; }
  .bk-empty h3 { color: var(--bk-ink); font-size: 20px; margin: 0 0 6px; }

  /* Booking steps page */
  .bk-page { background: var(--bk-soft); padding: 32px 0 64px; min-height: 60vh; }
  .bk-stepper { list-style: none; margin: 0 0 26px; padding: 0; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
  .bk-stepper li { display: flex; flex-direction: column; gap: 8px; font-size: 13px; font-weight: 500; color: var(--bk-muted); }
  .bk-stepper li::before { content: ''; height: 4px; border-radius: 4px; background: var(--bk-line-2); }
  .bk-stepper li.is-done, .bk-stepper li.is-on { color: var(--bk-ink); }
  .bk-stepper li.is-on { font-weight: 700; }
  .bk-stepper li.is-done::before, .bk-stepper li.is-on::before { background: var(--bk-accent); }
  .bk-book { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
  .bk-book-main { flex: 999 1 560px; min-width: 0; }
  .bk-book-aside { flex: 1 1 300px; min-width: 0; position: sticky; top: 88px; }
  @media (max-width: 960px) { .bk-book-aside { position: static; } }
  .bk-book h1 { font-family: var(--bk-display); font-size: 28px; margin: 0 0 6px; }
  .bk-options { display: flex; flex-direction: column; gap: 10px; }
  .bk-option { display: flex; justify-content: space-between; align-items: center; gap: 12px; width: 100%; padding: 16px 18px; min-height: 64px; border-radius: 12px; cursor: pointer; background: var(--bk-card); color: var(--bk-ink); border: 1px solid var(--bk-line-2); text-align: start; font: inherit; }
  .bk-option.is-on { border: 2px solid var(--bk-accent); padding: 15px 17px; }
  .bk-option strong { display: block; font-size: 16px; }
  .bk-option small { display: block; color: var(--bk-muted); font-size: 14px; margin-top: 2px; }
  .bk-option .price { font-weight: 600; white-space: nowrap; }
  .bk-people { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
  .bk-person { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 18px 12px; border-radius: 12px; cursor: pointer; background: var(--bk-card); color: var(--bk-ink); border: 1px solid var(--bk-line-2); font: inherit; }
  .bk-person.is-on { border: 2px solid var(--bk-accent); padding: 17px 11px; }
  .bk-person .bk-avatar { width: 48px; height: 48px; font-size: 18px; }
  .bk-person small { color: var(--bk-muted); font-size: 13px; }
  .bk-cal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
  .bk-cal-dow, .bk-cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; text-align: center; }
  .bk-cal-dow { font-size: 12px; color: var(--bk-muted); margin-bottom: 4px; }
  .bk-day { min-height: 42px; border-radius: 8px; border: 1px solid var(--bk-line-2); background: var(--bk-card); color: var(--bk-ink); cursor: pointer; font: inherit; font-variant-numeric: tabular-nums; }
  .bk-day.is-on { background: var(--bk-accent); border-color: var(--bk-accent); color: #fff; font-weight: 700; }
  .bk-day:disabled { background: var(--bk-soft); border-color: transparent; color: var(--bk-muted); cursor: not-allowed; }
  .bk-day.is-blank { visibility: hidden; }
  .bk-time-wrap { display: flex; flex-wrap: wrap; gap: 24px; }
  .bk-time-wrap > * { flex: 1 1 260px; min-width: 0; }
  .bk-nav-row { display: flex; justify-content: space-between; gap: 12px; margin-top: 26px; padding-top: 20px; border-top: 1px solid var(--bk-line); }
  .bk-summary { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 10px 16px; font-size: 14px; }
  .bk-summary dt { color: var(--bk-muted); }
  .bk-summary dd { margin: 0; text-align: end; font-weight: 600; }
  .bk-alert { display: flex; gap: 10px; align-items: flex-start; border-radius: 10px; padding: 12px 14px; font-size: 14px; line-height: 1.5; }
  .bk-alert svg { width: 18px; height: 18px; flex: none; margin-top: 1px; }
  .bk-alert-ok { background: var(--bk-ok-soft); color: var(--bk-ok); }
  .bk-alert-warn { background: var(--bk-warn-soft); color: var(--bk-warn); }
  .bk-alert-bad { background: var(--bk-bad-soft); color: var(--bk-bad); }
  .bk-alert-info { background: var(--bk-accent-soft); color: var(--bk-ink); }
  .bk-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
  .bk-stepper-seats { display: inline-flex; align-items: center; gap: 8px; }
  .bk-stepper-seats button { width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--bk-line-2); background: var(--bk-card); color: var(--bk-ink); cursor: pointer; font-size: 18px; }
  .bk-stepper-seats output { min-width: 28px; text-align: center; font-weight: 700; }

  /* Status page + account */
  .bk-status { max-width: 820px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }
  .bk-status-top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: flex-start; }
  .bk-badge { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; font-weight: 600; font-size: 14px; }
  .bk-badge svg { width: 16px; height: 16px; }
  .bk-badge-warn { background: var(--bk-warn-soft); color: var(--bk-warn); }
  .bk-badge-ok { background: var(--bk-ok-soft); color: var(--bk-ok); }
  .bk-badge-bad { background: var(--bk-bad-soft); color: var(--bk-bad); }
  .bk-badge-muted { background: var(--bk-soft); color: var(--bk-ink-2); }
  .bk-progress { list-style: none; padding: 0; margin: 24px 0 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
  .bk-progress li { display: flex; flex-direction: column; gap: 6px; font-size: 14px; font-weight: 600; color: var(--bk-muted); }
  .bk-progress li::before { content: ''; height: 4px; border-radius: 4px; background: var(--bk-line-2); }
  .bk-progress li.is-done { color: var(--bk-ink); }
  .bk-progress li.is-done::before { background: var(--bk-accent); }
  .bk-facts-grid { margin: 22px 0 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
  .bk-facts-grid dt { font-size: 13px; color: var(--bk-muted); }
  .bk-facts-grid dd { margin: 4px 0 0; font-weight: 600; overflow-wrap: anywhere; white-space: pre-line; }
  .bk-row-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--bk-line); }
  .bk-table-wrap { overflow-x: auto; }
  .bk-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 560px; }
  .bk-table th { text-align: start; color: var(--bk-muted); font-weight: 600; padding: 10px 8px; border-bottom: 1px solid var(--bk-line); }
  .bk-table td { padding: 12px 8px; border-bottom: 1px solid var(--bk-line); }

  /* Footer */
  .bk-footer { background: var(--bk-dark); color: #d5dbe1; margin-top: 0; }
  .bk-footer a { color: #d5dbe1; text-decoration: none; }
  .bk-footer a:hover { color: #fff; }
  .bk-footer-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 28px; padding: 44px 0 28px; }
  .bk-footer-brand { font-family: var(--bk-display); font-weight: 800; color: #fff; font-size: 18px; margin-bottom: 8px; }
  .bk-footer h4 { color: #fff; font-size: 15px; margin: 0 0 10px; }
  .bk-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
  .bk-footer p { font-size: 14px; line-height: 1.6; margin: 0; }
  .bk-social { display: flex; gap: 8px; margin-top: 12px; }
  .bk-social a { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,.08); display: inline-flex; align-items: center; justify-content: center; }
  .bk-social svg { width: 18px; height: 18px; }
  .bk-footer-bottom { border-top: 1px solid rgba(255,255,255,.12); padding: 16px 0 24px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px; font-size: 13px; }
  @media (max-width: 1023px) { .bk-footer { padding-bottom: 64px; } }
</style>
