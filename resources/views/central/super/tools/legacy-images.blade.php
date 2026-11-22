@extends('central.super.layout')

@section('title', 'Import old product images')

@push('styles')
<style>
    .lgi { max-width: 900px; }
    .lgi-card { background: var(--card-bg, #fff); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    .lgi label { display: block; font-weight: 600; margin: 12px 0 4px; }
    .lgi input[type=text], .lgi select { width: 100%; padding: 8px 10px; border: 1px solid rgba(0,0,0,.15); border-radius: 6px; font-family: monospace; }
    .lgi .hint { font-size: 12px; opacity: .7; margin-top: 2px; }
    .lgi .row-opts { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 12px; }
    .lgi .row-opts label { font-weight: normal; margin: 0; display: flex; gap: 6px; align-items: center; }
    .lgi .btns { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }
    .lgi button { padding: 9px 18px; border: 0; border-radius: 6px; cursor: pointer; font-weight: 600; }
    .lgi .lgi-b-check { background: #2563eb; color: #fff; }
    .lgi .lgi-b-sync { background: #16a34a; color: #fff; }
    .lgi button:disabled { opacity: .5; cursor: not-allowed; }
    .lgi .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin: 12px 0; }
    .lgi .stat { border: 1px solid rgba(0,0,0,.08); border-radius: 8px; padding: 10px; }
    .lgi .stat b { display: block; font-size: 22px; }
    .lgi .stat.good b { color: #16a34a; }
    .lgi .stat.warn b { color: #d97706; }
    .lgi .msg { padding: 10px 12px; border-radius: 6px; margin-top: 12px; }
    .lgi .msg.err { background: #fee2e2; color: #991b1b; }
    .lgi .msg.ok { background: #dcfce7; color: #166534; }
    .lgi .bar { height: 14px; background: rgba(0,0,0,.08); border-radius: 7px; overflow: hidden; margin: 10px 0; }
    .lgi .bar > div { height: 100%; width: 0; background: #16a34a; transition: width .3s; }
    .lgi table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .lgi td, .lgi th { border-bottom: 1px solid rgba(0,0,0,.08); padding: 4px 6px; text-align: left; vertical-align: top; }
    .lgi details { margin-top: 10px; }
    .lgi summary { cursor: pointer; font-weight: 600; }
    .lgi .hidden { display: none; }
</style>
@endpush

@section('content')
<div class="lgi">
    <h3>Import old product images</h3>
    <p>Matches rows from the old POS CSV (<code>old_id,code,name,images</code>) to a tenant's products by code, then name,
        and copies the image files in. <b>Check</b> writes nothing. <b>Sync</b> imports in small batches.
        Back up the tenant's <code>products</code> and <code>product_images</code> tables before syncing.</p>

    <div class="lgi-card">
        <label for="lgi-tenant">Tenant ID</label>
        <input type="text" id="lgi-tenant" placeholder="0a9edf80-4879-44fd-a2eb-0d39eadf8063">

        <label for="lgi-csv">CSV file path on the server</label>
        <input type="text" id="lgi-csv" placeholder="/home/user/legacy_import/old_products_images.csv">

        <label for="lgi-images">Images folder path on the server</label>
        <input type="text" id="lgi-images" placeholder="/home/user/legacy_import/old_mages">

        <label for="lgi-match">Match by</label>
        <select id="lgi-match">
            <option value="code_name">Code, then name (recommended)</option>
            <option value="code">Code only</option>
            <option value="name">Name only</option>
        </select>

        <div class="row-opts">
            <label><input type="checkbox" id="lgi-overwrite"> Replace images on products that already have one</label>
            <label><input type="checkbox" id="lgi-resize" checked> Resize (uses the tenant's Resize Product Images setting)</label>
        </div>

        <div class="btns">
            <button type="button" class="lgi-b-check" id="lgi-btn-check">1. Check matching</button>
            <button type="button" class="lgi-b-sync" id="lgi-btn-sync" disabled>2. Sync images</button>
        </div>
        <div id="lgi-msg"></div>
    </div>

    <div class="lgi-card hidden" id="lgi-result"></div>
    <div class="lgi-card hidden" id="lgi-progress">
        <h5>Syncing…</h5>
        <div class="bar"><div id="lgi-bar"></div></div>
        <div id="lgi-progress-text"></div>
        <div id="lgi-errors"></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var urls = {
        check: @json(route('super.tools.legacy-images.check')),
        sync: @json(route('super.tools.legacy-images.sync'))
    };
    var csrf = @json(csrf_token());
    var token = null;
    var resumeOffset = 0; // where Sync continues after a stop
    var totals = null;
    var jobTotal = 0;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    var labels = {
        would_import: 'Will import', skipped_has_image: 'Already have an image', missing_file: 'Image file missing',
        bad_file: 'Image file empty/corrupt',
        no_match: 'No matching product', ambiguous_new: 'Several products match', ambiguous_old: 'Old data conflicts',
        duplicate_old: 'Product matched twice', error: 'Error'
    };

    // Remember the inputs in this browser only.
    ['lgi-tenant', 'lgi-csv', 'lgi-images'].forEach(function (id) {
        try { var v = localStorage.getItem(id); if (v) $(id).value = v; } catch (e) {}
        $(id).addEventListener('change', function () { try { localStorage.setItem(id, $(id).value); } catch (e) {} });
    });

    function msg(text, kind) { $('lgi-msg').innerHTML = text ? '<div class="msg ' + kind + '">' + text + '</div>' : ''; }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok) {
                    var m = j.message || ('HTTP ' + r.status);
                    if (j.errors) m += ': ' + Object.values(j.errors).flat().join(' ');
                    throw new Error(m);
                }
                return j;
            });
        });
    }

    $('lgi-btn-check').addEventListener('click', function () {
        token = null;
        resumeOffset = 0;
        totals = null;
        $('lgi-btn-sync').disabled = true;
        $('lgi-progress').classList.add('hidden');
        $('lgi-result').classList.add('hidden');
        msg('Checking… this can take a minute.', 'ok');
        this.disabled = true;
        var btn = this;
        post(urls.check, {
            tenant_id: $('lgi-tenant').value, csv_path: $('lgi-csv').value, images_path: $('lgi-images').value,
            match: $('lgi-match').value, overwrite: $('lgi-overwrite').checked, resize: $('lgi-resize').checked
        }).then(function (j) {
            msg('', '');
            token = j.token;
            jobTotal = j.to_import;
            renderCheck(j);
            $('lgi-btn-sync').disabled = j.to_import === 0;
        }).catch(function (e) { msg(esc(e.message), 'err'); })
          .finally(function () { btn.disabled = false; });
    });

    function renderCheck(j) {
        var s = j.stats || {};
        var h = '<h5>Check result: tenant ' + esc(j.tenant) + '</h5>';
        h += '<div class="stats">';
        h += stat('Old rows in CSV', j.old_rows) + stat('Image files in folder', j.image_files);
        h += stat('Products in tenant', j.products_total) + stat('Products without image now', j.products_without_image);
        h += stat('Will import', j.to_import, 'good');
        h += stat('  matched by code', j.by_code) + stat('  matched by name', j.by_name);
        Object.keys(s).forEach(function (k) { if (k !== 'would_import') h += stat(labels[k] || k, s[k], 'warn'); });
        h += '</div>';
        h += '<a href="' + esc(j.report_url) + '">Download the full report (CSV)</a>';
        Object.keys(j.samples || {}).forEach(function (k) {
            var rows = j.samples[k];
            h += '<details><summary>' + esc(labels[k] || k) + ': first ' + rows.length + '</summary><table><tr><th>old id</th><th>old code</th><th>old name</th><th>images</th><th>product</th><th>detail</th></tr>';
            rows.forEach(function (r) {
                h += '<tr><td>' + esc(r.old_id) + '</td><td>' + esc(r.old_code) + '</td><td>' + esc(r.old_name) + '</td><td>' + esc(r.images) +
                     '</td><td>' + esc(r.product_id ? ('#' + r.product_id + ' ' + r.product_name) : '') + '</td><td>' + esc(r.detail) + '</td></tr>';
            });
            h += '</table></details>';
        });
        $('lgi-result').innerHTML = h;
        $('lgi-result').classList.remove('hidden');
    }

    function stat(label, val, cls) { return '<div class="stat ' + (cls || '') + '"><b>' + esc(val) + '</b>' + esc(label) + '</div>'; }

    $('lgi-btn-sync').addEventListener('click', function () {
        if (!token) return;
        if (resumeOffset === 0 && !confirm('Import the images now? Make sure you backed up products and product_images.')) return;
        $('lgi-btn-sync').disabled = true;
        $('lgi-btn-check').disabled = true;
        $('lgi-progress').classList.remove('hidden');
        msg('', '');
        if (!totals) {
            $('lgi-errors').innerHTML = '';
            totals = { imported: 0, skipped_has_image: 0, already_done: 0, error: 0 };
        }
        var retries = 0;

        (function step(offset) {
            post(urls.sync, { token: token, offset: offset }).then(function (j) {
                retries = 0;
                resumeOffset = j.next_offset;
                Object.keys(j.counts).forEach(function (k) { totals[k] = (totals[k] || 0) + j.counts[k]; });
                (j.errors || []).forEach(function (e) {
                    $('lgi-errors').insertAdjacentHTML('beforeend', '<div class="msg err">old #' + esc(e.old_id) + ' ' + esc(e.product) + ': ' + esc(e.error) + '</div>');
                });
                var pct = j.total ? Math.round(j.next_offset * 100 / j.total) : 100;
                $('lgi-bar').style.width = pct + '%';
                $('lgi-progress-text').innerHTML = j.next_offset + ' / ' + j.total + ' · imported ' + totals.imported +
                    ' · skipped ' + (totals.skipped_has_image + totals.already_done) + ' · errors ' + totals.error +
                    ' · <a href="' + esc(j.report_url) + '">sync report (CSV)</a>';
                if (j.done) {
                    token = null; // this check is used up; run Check again for a new pass
                    $('lgi-btn-check').disabled = false;
                    msg('Done. Imported ' + totals.imported + ' products. Products still without an image: ' + j.products_without_image + '.', 'ok');
                } else {
                    step(j.next_offset);
                }
            }).catch(function (e) {
                // A dropped request is safe to repeat: imported products are skipped next time.
                if (retries++ < 3) { setTimeout(function () { step(offset); }, 2000); return; }
                $('lgi-btn-check').disabled = false;
                $('lgi-btn-sync').disabled = false;
                msg('Stopped at ' + offset + ' / ' + jobTotal + ': ' + esc(e.message) + '. Click Sync again to continue from there; nothing is imported twice.', 'err');
            });
        })(resumeOffset);
    });
})();
</script>
@endpush
