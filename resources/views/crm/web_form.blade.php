@php
    $accent = ($form && $form->accent_color) ? $form->accent_color : '#6d28d9';
    $title = $form ? ($form->title ?: $form->name) : 'Form unavailable';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        :root { --accent: {{ $accent }}; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f1f5f9; --card: #ffffff; --danger: #dc2626; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: var(--text); background: var(--bg); line-height: 1.5; -webkit-font-smoothing: antialiased; }
        body.embed { background: transparent; }
        .wrap { max-width: 620px; margin: 0 auto; padding: 40px 16px; }
        body.embed .wrap { padding: 4px; max-width: none; }
        .card { background: var(--card); border-radius: 18px; box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 12px 32px -12px rgba(15, 23, 42, .18); overflow: hidden; }
        body.embed .card { box-shadow: none; border: 1px solid var(--line); }
        .bar { height: 5px; background: var(--accent); }
        .inner { padding: 28px 28px 30px; }
        h1 { font-size: 22px; line-height: 1.25; margin: 0 0 6px; letter-spacing: -0.01em; }
        .intro { color: var(--muted); margin: 0 0 22px; white-space: pre-line; font-size: 15px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 14px; }
        .field { display: flex; flex-direction: column; gap: 6px; grid-column: span 2; }
        .field.half { grid-column: span 1; }
        label { font-size: 13.5px; font-weight: 600; }
        label .req { color: var(--danger); margin-inline-start: 2px; }
        input, textarea { width: 100%; font: inherit; font-size: 15px; color: var(--text); background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 11px 12px; outline: none; transition: border-color .15s, box-shadow .15s; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
        .field.invalid input, .field.invalid textarea { border-color: var(--danger); }
        .consent { display: flex; gap: 10px; align-items: flex-start; grid-column: span 2; font-size: 13.5px; color: var(--muted); }
        .consent input { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--accent); flex: none; }
        .hp { position: absolute !important; left: -10000px !important; width: 1px; height: 1px; overflow: hidden; }
        .actions { margin-top: 22px; display: flex; align-items: center; gap: 12px; }
        button { font: inherit; font-weight: 600; font-size: 15px; color: #fff; background: var(--accent); border: 0; border-radius: 10px; padding: 12px 22px; cursor: pointer; transition: filter .15s, transform .05s; display: inline-flex; align-items: center; gap: 8px; }
        button:hover { filter: brightness(1.08); }
        button:active { transform: translateY(1px); }
        button[disabled] { opacity: .65; cursor: progress; }
        .spin { width: 16px; height: 16px; border: 2px solid rgba(255, 255, 255, .45); border-top-color: #fff; border-radius: 50%; animation: s .7s linear infinite; display: none; }
        button[disabled] .spin { display: inline-block; }
        @keyframes s { to { transform: rotate(360deg); } }
        .error { display: none; margin-top: 16px; padding: 10px 12px; border-radius: 10px; background: #fef2f2; color: var(--danger); font-size: 14px; }
        .error.show { display: block; }
        .done { text-align: center; padding: 18px 6px 8px; }
        .tick { width: 58px; height: 58px; border-radius: 50%; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--accent) 14%, #fff); color: var(--accent); }
        .done p { color: var(--muted); margin: 6px 0 0; white-space: pre-line; }
        .foot { text-align: center; font-size: 12px; color: var(--muted); margin-top: 14px; }
        body.embed .foot { display: none; }
        @media (max-width: 560px) {
            .grid { grid-template-columns: 1fr; }
            .field, .field.half, .consent { grid-column: span 1; }
            .inner { padding: 22px 18px 24px; }
            .wrap { padding: 16px; }
        }
        @media (prefers-color-scheme: dark) {
            body:not(.embed) { --bg: #0b1120; }
        }
    </style>
</head>
<body class="{{ $embed ? 'embed' : '' }}">
<div class="wrap">
    <div class="card">
        <div class="bar"></div>
        <div class="inner">
            @if (! $form)
                <h1>This form is no longer available</h1>
                <p class="intro">The link may have changed. Please contact us another way.</p>
            @else
                <div id="done" class="done" @if (! $done) hidden @endif>
                    <div class="tick"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
                    <h1>{{ $form->title ?: 'Thank you!' }}</h1>
                    <p id="done-text">{{ $form->success_message ?: 'Thank you! We received your message and will get back to you shortly.' }}</p>
                </div>

                <form id="crm-form" method="POST" action="{{ url('lead-form/' . $form->token) }}" novalidate @if ($done) hidden @endif>
                    @if ($form->title)<h1>{{ $form->title }}</h1>@endif
                    @if ($form->intro)<p class="intro">{{ $form->intro }}</p>@endif

                    <div class="grid">
                        @php
                            $halves = ['first_name', 'last_name', 'email', 'phone', 'company_name', 'job_title', 'city', 'country', 'website', 'estimated_value'];
                        @endphp
                        @foreach ($fields as $f)
                            <div class="field {{ in_array($f['key'], $halves, true) ? 'half' : '' }}" data-field="{{ $f['key'] }}">
                                <label for="f-{{ $f['key'] }}">{{ $f['label'] }}@if ($f['required'])<span class="req">*</span>@endif</label>
                                @if ($f['type'] === 'textarea')
                                    <textarea id="f-{{ $f['key'] }}" name="{{ $f['key'] }}" maxlength="5000" @if ($f['required']) required @endif>{{ old($f['key']) }}</textarea>
                                @else
                                    <input id="f-{{ $f['key'] }}" name="{{ $f['key'] }}" type="{{ $f['type'] }}" value="{{ old($f['key']) }}"
                                        @if ($f['type'] === 'number') min="0" step="any" inputmode="decimal" @endif
                                        @if ($f['type'] === 'email') autocomplete="email" @elseif ($f['type'] === 'tel') autocomplete="tel" @elseif ($f['key'] === 'name') autocomplete="name" @elseif ($f['key'] === 'first_name') autocomplete="given-name" @elseif ($f['key'] === 'last_name') autocomplete="family-name" @elseif ($f['key'] === 'company_name') autocomplete="organization" @endif
                                        maxlength="191" @if ($f['required']) required @endif>
                                @endif
                            </div>
                        @endforeach
                        @if ($form->consent_text)
                            <label class="consent"><input type="checkbox" name="consent" value="1" required> <span>{{ $form->consent_text }}</span></label>
                        @endif
                    </div>

                    <div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website_hp" tabindex="-1" autocomplete="off"></label></div>
                    <input type="hidden" name="_ts" value="{{ $stamp }}">
                    <input type="hidden" name="page_url" id="page_url">
                    <input type="hidden" name="utm_source" value="{{ request('utm_source') }}">
                    <input type="hidden" name="utm_medium" value="{{ request('utm_medium') }}">
                    <input type="hidden" name="utm_campaign" value="{{ request('utm_campaign') }}">

                    <div id="error" class="error @if ($errors->any()) show @endif" role="alert">{{ $errors->first() }}</div>
                    <div class="actions">
                        <button type="submit" id="submit"><span class="spin"></span><span>{{ $form->submit_label ?: 'Send' }}</span></button>
                    </div>
                </form>
            @endif
        </div>
    </div>
    @if ($form && $company)<div class="foot">{{ $company }}</div>@endif
</div>

@if ($form)
<script>
(function () {
    var token = @json($form->token);
    var form = document.getElementById('crm-form');
    var err = document.getElementById('error');
    var btn = document.getElementById('submit');
    var pageUrl = document.getElementById('page_url');
    try { pageUrl.value = document.referrer || ''; } catch (e) {}

    // Tell the embedding page how tall we are (see the embed snippet).
    function resize() {
        try { parent.postMessage({ crmFormHeight: document.documentElement.scrollHeight, token: token }, '*'); } catch (e) {}
    }
    window.addEventListener('load', resize);
    window.addEventListener('resize', resize);

    function showError(msg, key) {
        err.textContent = msg || 'Something went wrong. Please try again.';
        err.classList.add('show');
        document.querySelectorAll('.field.invalid').forEach(function (el) { el.classList.remove('invalid'); });
        if (key) {
            var f = document.querySelector('.field[data-field="' + key + '"]');
            if (f) { f.classList.add('invalid'); var i = f.querySelector('input,textarea'); if (i) i.focus(); }
        }
        resize();
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.checkValidity()) {
            var bad = form.querySelector(':invalid');
            var field = bad && bad.closest('.field');
            showError(bad && bad.name === 'consent' ? 'Please tick the box to continue.' : 'Please check the highlighted field.', field ? field.getAttribute('data-field') : null);
            return;
        }
        btn.disabled = true;
        err.classList.remove('show');
        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'omit' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                btn.disabled = false;
                if (!res.ok) {
                    var key = res.body && res.body.errors ? Object.keys(res.body.errors)[0] : null;
                    showError(res.body && res.body.message, key);
                    return;
                }
                if (res.body.redirect) {
                    try { window.top.location.href = res.body.redirect; } catch (x) { window.location.href = res.body.redirect; }
                    return;
                }
                document.getElementById('done-text').textContent = res.body.message;
                form.hidden = true;
                document.getElementById('done').hidden = false;
                resize();
            })
            .catch(function () { btn.disabled = false; showError(); });
    });
    resize();
})();
</script>
@endif
</body>
</html>
