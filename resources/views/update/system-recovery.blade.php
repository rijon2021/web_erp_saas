@php
    $rc = [
        'status'   => route('super.system-update.status'),
        'step'     => route('super.system-update.step'),
        'resume'   => route('super.system-update.resume'),
        'rollback' => route('super.system-update.rollback'),
        'discard'  => route('super.system-update.discard'),
        'trans'    => __('super.system_update.recovery'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ __('super.system_update.recovery.title') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f0f2f5; color: #1f1f1f; padding: 32px 16px; }
        .wrap { max-width: 760px; margin: 0 auto; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 28px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin-bottom: 6px; }
        .sub { color: #8c8c8c; font-size: 13px; margin-bottom: 18px; }
        .row { display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 14px; font-size: 14px; }
        .row b { display: block; font-size: 12px; color: #8c8c8c; font-weight: 500; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; }
        .badge.running { background: #e6f4ff; color: #1677ff; }
        .badge.stalled, .badge.recovery_required, .badge.failed { background: #fff1f0; color: #cf1322; }
        .badge.rolled_back { background: #fff7e6; color: #d46b08; }
        .badge.completed { background: #f6ffed; color: #389e0d; }
        .bar { height: 10px; border-radius: 5px; background: #f0f0f0; overflow: hidden; margin: 10px 0 8px; }
        .bar i { display: block; height: 100%; background: #1677ff; transition: width .4s; }
        .db { font-size: 13px; color: #595959; margin-bottom: 14px; }
        button { border: 0; border-radius: 6px; padding: 9px 18px; font-size: 14px; cursor: pointer; margin: 0 8px 8px 0; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .primary { background: #1677ff; color: #fff; }
        .danger { background: #ff4d4f; color: #fff; }
        .ghost { background: #f0f0f0; color: #1f1f1f; }
        pre { background: #101418; color: #c9d1d9; font-size: 12px; border-radius: 8px; padding: 14px; max-height: 360px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
        .error-box { background: #fff1f0; border: 1px solid #ffccc7; color: #a8071a; border-radius: 8px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; word-break: break-word; }
        .msg { font-size: 13px; margin-top: 10px; color: #595959; }
        a { color: #1677ff; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ __('super.system_update.recovery.title') }}</h1>
        <div class="sub">{{ __('super.system_update.recovery.subtitle') }}</div>

        <div id="none" style="display:none">{{ __('super.system_update.recovery.no_run') }}
            <div class="msg"><a href="{{ route('super.system-update.index') }}">&larr; {{ __('super.system_update.recovery.back') }}</a></div>
        </div>

        <div id="panel" style="display:none">
            <div class="row">
                <div><b>{{ __('super.system_update.recovery.run') }}</b><span id="r-id">—</span></div>
                <div><b>{{ __('super.system_update.recovery.type') }}</b><span id="r-type">—</span></div>
                <div><b>{{ __('super.system_update.recovery.from') }}</b><span id="r-from">—</span></div>
                <div><b>{{ __('super.system_update.recovery.to') }}</b><span id="r-to">—</span></div>
                <div><b>{{ __('super.system_update.recovery.status') }}</b><span class="badge" id="r-status">—</span></div>
                <div><b>{{ __('super.system_update.recovery.phase') }}</b><span id="r-phase">—</span></div>
            </div>
            <div class="bar"><i id="r-bar" style="width:0%"></i></div>
            <div class="db" id="r-db"></div>
            <div class="error-box" id="r-error" style="display:none"></div>

            <div>
                <button class="primary" id="btn-resume">{{ __('super.system_update.recovery.resume') }}</button>
                <button class="danger" id="btn-rollback">{{ __('super.system_update.recovery.rollback') }}</button>
                <button class="ghost" id="btn-discard">{{ __('super.system_update.recovery.discard') }}</button>
            </div>
            <div class="msg" id="r-msg"></div>
        </div>
    </div>

    <div class="card">
        <b style="font-size:13px">{{ __('super.system_update.recovery.activity') }}</b>
        <pre id="r-log">…</pre>
    </div>
</div>

<script>
(function () {
    'use strict';
    var C = {!! json_encode($rc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};
    var T = C.trans || {};
    var stepping = false;

    function api(method, url) {
        return fetch(url, {
            method: method, credentials: 'same-origin',
            headers: {
                'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
                return d;
            });
        });
    }

    function render(data) {
        var s = data.state;
        document.getElementById('none').style.display = s ? 'none' : 'block';
        document.getElementById('panel').style.display = s ? 'block' : 'none';
        if (!s) { document.getElementById('r-log').textContent = '—'; return; }
        document.getElementById('r-id').textContent = s.id || '—';
        document.getElementById('r-type').textContent = s.type || '—';
        document.getElementById('r-from').textContent = s.from_version || '—';
        document.getElementById('r-to').textContent = s.to_version || '—';
        var st = document.getElementById('r-status');
        st.textContent = s.status + (s.stalled ? ' (' + (T.interrupted || 'interrupted') + ')' : '');
        st.className = 'badge ' + (s.stalled ? 'stalled' : s.status);
        document.getElementById('r-phase').textContent = s.phase || '—';
        document.getElementById('r-bar').style.width = (s.percent || 0) + '%';
        var db = s.db_progress;
        document.getElementById('r-db').textContent = db
            ? (T.databases || 'Databases') + ': ' + db.done + '/' + db.total + (db.current ? ' — ' + db.current : '')
            : (s.databases ? (T.databases || 'Databases') + ': ' + s.databases : '');
        var err = document.getElementById('r-error');
        if (s.error) { err.style.display = 'block'; err.textContent = s.error.phase + ': ' + s.error.message; }
        else err.style.display = 'none';
        document.getElementById('r-log').textContent = (s.log_tail || []).join('\n') || '—';

        var terminal = ['completed', 'failed', 'rolled_back'].indexOf(s.status) !== -1;
        document.getElementById('btn-resume').disabled = terminal || stepping;
        document.getElementById('btn-rollback').disabled = terminal || stepping;
        document.getElementById('btn-discard').disabled = !terminal && !s.stalled;
    }

    function refresh() { return api('GET', C.status).then(render).catch(showErr); }
    function showErr(e) { document.getElementById('r-msg').textContent = e.message; }

    function stepLoop() {
        if (stepping) return;
        stepping = true;
        (function next() {
            api('POST', C.step).then(function (data) {
                render(data);
                var s = data.state;
                if (s && s.status === 'running') setTimeout(next, 700);
                else { stepping = false; render(data); }
            }).catch(function (e) {
                stepping = false; showErr(e);
                setTimeout(refresh, 2000);
            });
        })();
    }

    document.getElementById('btn-resume').addEventListener('click', function () {
        api('POST', C.resume).then(function (d) { render(d); stepLoop(); }).catch(showErr);
    });
    document.getElementById('btn-rollback').addEventListener('click', function () {
        if (!confirm(T.rollback_confirm || 'Roll back?')) return;
        api('POST', C.rollback).then(function (d) { render(d); stepLoop(); }).catch(showErr);
    });
    document.getElementById('btn-discard').addEventListener('click', function () {
        api('POST', C.discard).then(render).catch(showErr);
    });

    refresh();
    setInterval(function () { if (!stepping) refresh(); }, 5000);
})();
</script>
</body>
</html>
