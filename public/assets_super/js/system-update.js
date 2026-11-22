/**
 * Super admin > System Update — upload-based, resumable platform updater.
 *
 * The server runs a state machine (super/system-update/*): upload chunks →
 * validate → start → then POST step repeatedly; every call performs one
 * bounded phase (backup of central + every tenant database, stage, swap,
 * migrate central, migrate tenants one by one, verify, …) and returns the
 * full status. Steps keep working during maintenance mode (whitelisted
 * routes). If the browser closes mid-run the state survives server-side:
 * on reload the banner offers Resume / Rollback, and the recovery console
 * works without this page.
 *
 * Data bridge: window.SystemUpdateData = { endpoints, trans, status }.
 */
(function () {
    'use strict';

    var D = window.SystemUpdateData || {};
    var E = D.endpoints || {};
    var T = D.trans || {};
    var CHUNK_SIZE = 2 * 1024 * 1024;

    var st = {
        status: null,
        report: null,
        uploading: false,
        validating: false,
        stepping: false,
        stepErrors: 0
    };

    function t(key, params) {
        var s = T[key] !== undefined ? T[key] : key;
        if (params) {
            Object.keys(params).forEach(function (k) {
                s = s.split(':' + k).join(params[k]);
            });
        }
        return s;
    }
    function $(id) { return document.getElementById(id); }
    function show(el, on) { if (el) el.hidden = !on; }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    // ------------------------------------------------------------------ http

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function api(method, url, body) {
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() }
        };
        if (body instanceof FormData) {
            opts.body = body;
        } else if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                if (!r.ok) {
                    var err = new Error(d.message || ('HTTP ' + r.status));
                    err.status = r.status;
                    throw err;
                }
                return d;
            });
        });
    }

    function toast(icon, title) {
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 3200 });
        } else {
            window.alert(title);
        }
    }
    function confirmDanger(title, text, okText) {
        if (!window.Swal) return Promise.resolve(window.confirm(title + '\n\n' + text));
        return Swal.fire({
            icon: 'warning', title: title, text: text, showCancelButton: true,
            confirmButtonText: okText, cancelButtonText: t('cancel'), confirmButtonColor: '#dc2626'
        }).then(function (r) { return r.isConfirmed; });
    }

    // --------------------------------------------------------------- helpers

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        var k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    function formatDuration(seconds) {
        if (seconds < 60) return seconds + 's';
        return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
    }

    var PHASES = {
        init: 'phase_starting', rst_init: 'phase_starting',
        backup_db: 'phase_backup_db', rst_backup_db: 'phase_safety_db',
        backup_files: 'phase_backup_files', backup_verify: 'phase_backup_verify',
        stage_extract: 'phase_extract', stage_validate: 'phase_validate', rst_extract: 'phase_extract_backup',
        maintenance_on: 'phase_maintenance_on', maintenance_off: 'phase_maintenance_off',
        apply: 'phase_apply', migrate_central: 'phase_migrate_central', migrate_tenants: 'phase_migrate_tenants',
        cache: 'phase_cache', rst_cache: 'phase_cache', verify: 'phase_verify',
        cleanup: 'phase_cleanup', rst_cleanup: 'phase_cleanup',
        rb_files: 'phase_restore_files', rst_swap: 'phase_restore_files',
        rb_db: 'phase_restore_db', rst_db: 'phase_restore_db', rb_finalize: 'phase_rollback_finish'
    };
    function phaseLabel(p) { return PHASES[p] ? t(PHASES[p]) : (p || '—'); }

    var STEPS = [
        { key: 'step_backup', phases: ['init', 'backup_files'] },
        { key: 'step_prepare', phases: ['stage_extract', 'stage_validate'] },
        { key: 'step_backup_db', phases: ['maintenance_on', 'backup_db', 'backup_verify'] },
        { key: 'step_files', phases: ['apply'] },
        { key: 'step_databases', phases: ['migrate_central', 'migrate_tenants'] },
        { key: 'step_cache', phases: ['cache'] },
        { key: 'step_verify', phases: ['verify'] },
        { key: 'step_done', phases: ['maintenance_off', 'cleanup'] }
    ];

    var STATUS_CLASS = {
        running: 'su-badge-running', completed: 'su-badge-ok', failed: 'su-badge-fail',
        rolled_back: 'su-badge-warn', recovery_required: 'su-badge-fail'
    };
    function statusLabel(status, stalled) {
        if (status === 'running' && stalled) return t('status_interrupted');
        return t('status_' + status) !== 'status_' + status ? t('status_' + status) : status;
    }

    function state() { return st.status && st.status.state; }
    // Running, interrupted or awaiting a rollback: its package and backup are
    // the way back, so the server refuses uploads / backup deletion.
    function isUnfinished() { var s = state(); return !!s && (s.status === 'running' || s.status === 'recovery_required'); }
    function isTerminal() { var s = state(); return !!s && ['completed', 'failed', 'rolled_back'].indexOf(s.status) !== -1; }
    function isRollingBack() { var s = state(); return !!s && String(s.phase || '').indexOf('rb_') === 0; }

    // ---------------------------------------------------------------- render

    function render() {
        var data = st.status || {};
        $('suCurrentVersion').textContent = data.current_version ? 'v' + data.current_version : '—';

        var pkg = data.package || null;
        st.report = pkg && pkg.report ? pkg.report : st.report && pkg ? st.report : null;

        renderUploadCard(pkg);
        renderReport();
        renderAttention();
        renderRun();
        renderHistory(data.history || []);
        renderBackups(data.backups || []);
        if (typeof data.retention === 'number' && document.activeElement !== $('suRetention')) {
            $('suRetention').value = data.retention;
        }
    }

    function renderUploadCard(pkg) {
        var running = isUnfinished() || st.stepping;
        show($('suUploadArea'), !running);
        show($('suRunningNote'), running);
        show($('suDropzone'), !pkg && !st.uploading);
        show($('suUploadProgress'), st.uploading);
        show($('suPackage'), !!pkg && !st.uploading);
        if (pkg) {
            $('suPkgFile').textContent = pkg.filename || '—';
            $('suPkgSize').textContent = formatBytes(pkg.size || 0);
            $('suPkgVersion').textContent = pkg.version ? 'v' + pkg.version : '—';
        }
        $('suBtnValidate').disabled = st.validating;
        $('suBtnValidate').querySelector('.su-btn-label').textContent = st.validating ? t('validating') : t('validate');
        show($('suBtnStart'), !!(st.report && st.report.ok));
    }

    function renderReport() {
        var box = $('suChecks');
        box.innerHTML = '';
        var r = st.report;
        show($('suChecksEmpty'), !r);
        show($('suChecksVerdict'), !!r);
        if (!r) return;
        (r.checks || []).forEach(function (c) {
            var row = el('div', 'su-check');
            var icon = el('i', 'bi ' + (c.ok ? 'bi-check-circle-fill su-ok' : (c.level === 'warning' ? 'bi-exclamation-circle-fill su-warn' : 'bi-x-circle-fill su-fail')));
            var body = el('div', 'su-check-body');
            body.appendChild(el('div', 'su-check-label', c.label));
            if (!c.ok && c.detail) body.appendChild(el('div', 'su-check-detail', c.detail));
            row.appendChild(icon);
            row.appendChild(body);
            box.appendChild(row);
        });
        var v = $('suChecksVerdict');
        v.className = 'su-badge ' + (r.ok ? 'su-badge-ok' : 'su-badge-fail');
        v.textContent = r.ok ? t('package_ready') : t('package_blocked');
    }

    function renderAttention() {
        var s = state();
        var needs = !!s && (s.status === 'recovery_required' || (s.status === 'running' && s.stalled && !st.stepping));
        show($('suAttention'), needs);
        if (!needs) return;
        $('suAttentionText').textContent = s.status === 'recovery_required' ? t('recovery_required_text') : t('interrupted_text');
        var err = $('suAttentionError');
        show(err, !!s.error);
        if (s.error) err.textContent = t('failed_step') + ': ' + phaseLabel(s.error.phase) + ' — ' + s.error.message;
    }

    function renderRun() {
        var s = state();
        show($('suRunCard'), !!s);
        if (!s) return;

        $('suRunTitle').textContent = s.type === 'restore' ? t('restoring_backup') : (isRollingBack() ? t('rolling_back') : t('update_progress'));
        var badge = $('suRunBadge');
        badge.className = 'su-badge ' + (s.status === 'running' && s.stalled ? 'su-badge-fail' : (STATUS_CLASS[s.status] || ''));
        badge.textContent = statusLabel(s.status, s.stalled);
        $('suRunVersions').textContent = s.from_version ? 'v' + s.from_version + ' → v' + (s.to_version || '?') : '';

        var pct = s.percent || 0;
        var bar = $('suRunBar');
        bar.style.width = pct + '%';
        bar.className = 'su-bar-fill' + (['failed', 'recovery_required'].indexOf(s.status) !== -1 ? ' su-bar-fail' : (s.status === 'completed' ? ' su-bar-ok' : (s.status === 'running' ? ' su-bar-active' : '')));
        $('suRunPct').textContent = pct + '%';

        // Step strip (update runs) or current phase line (restore / rollback)
        var steps = $('suSteps');
        steps.innerHTML = '';
        var useSteps = s.type === 'update' && !isRollingBack();
        show(steps, useSteps);
        show($('suPhaseLine'), !useSteps);
        if (useSteps) {
            var current = 0;
            if (s.status === 'completed') current = STEPS.length - 1;
            else STEPS.forEach(function (st2, i) { if (st2.phases.indexOf(s.phase) !== -1) current = i; });
            var failed = s.status === 'failed' || s.status === 'recovery_required';
            STEPS.forEach(function (step, i) {
                var cls = 'su-step';
                if (i < current || s.status === 'completed') cls += ' is-done';
                else if (i === current) cls += failed ? ' is-error' : ' is-current';
                var li = el('li', cls);
                li.appendChild(el('span', 'su-step-dot', String(i + 1)));
                li.appendChild(el('span', 'su-step-label', t(step.key)));
                steps.appendChild(li);
            });
        } else {
            $('suPhaseText').textContent = phaseLabel(s.phase);
        }

        // Per-database loop progress
        var dbp = s.db_progress;
        show($('suDbProgress'), !!dbp && s.status === 'running');
        if (dbp) {
            var line = t('db_progress', { done: dbp.done, total: dbp.total });
            if (dbp.current) line += ' — ' + dbp.current;
            $('suDbProgress').textContent = line;
        }
        $('suRunDatabases').textContent = s.databases ? t('databases_in_run', { count: s.databases }) : '';

        // Maintenance bypass link
        show($('suBypass'), !!s.maintenance_secret);
        if (s.maintenance_secret) {
            var url = window.location.origin + '/' + s.maintenance_secret;
            var a = $('suBypassLink');
            a.href = url;
            a.textContent = url;
        }

        show($('suDone'), s.status === 'completed');
        $('suDoneTitle').textContent = s.type === 'restore' ? t('restore_successful') : t('update_successful');
        show($('suRolledBack'), s.status === 'rolled_back');
        $('suRolledBackDetail').textContent = s.error ? phaseLabel(s.error.phase) + ' — ' + s.error.message : '';

        show($('suBtnReload'), s.status === 'completed');
        show($('suBtnDismiss'), isTerminal());

        var log = (s.log_tail || []).join('\n');
        show($('suLogWrap'), !!log);
        $('suLog').textContent = log;
    }

    function renderHistory(rows) {
        var body = $('suHistoryBody');
        body.innerHTML = '';
        show($('suHistoryEmpty'), rows.length === 0);
        rows.slice(0, 15).forEach(function (r) {
            var tr = el('tr');
            tr.appendChild(el('td', 'text-nowrap', r.started_at || '—'));
            var v = (r.type === 'restore' ? t('restore') + ' ' : '') + 'v' + (r.from_version || '?') + ' → v' + (r.to_version || '?');
            tr.appendChild(el('td', '', v));
            tr.appendChild(el('td', '', r.databases ? String(r.databases) : '—'));
            tr.appendChild(el('td', '', r.user ? r.user.name : '—'));
            tr.appendChild(el('td', 'text-nowrap', r.duration != null ? formatDuration(r.duration) : '—'));
            var td = el('td');
            var b = el('span', 'su-badge ' + (STATUS_CLASS[r.status] || ''), statusLabel(r.status, false));
            if (r.error && r.error.message) b.title = r.error.message;
            td.appendChild(b);
            tr.appendChild(td);
            body.appendChild(tr);
        });
    }

    function renderBackups(rows) {
        var list = $('suBackups');
        list.innerHTML = '';
        show($('suBackupsEmpty'), rows.length === 0);
        var running = isUnfinished() || st.stepping;
        rows.forEach(function (b) {
            var item = el('div', 'su-backup');
            var info = el('div', 'su-backup-info');
            var head = el('div', 'su-backup-title', 'v' + (b.version || '?'));
            if (!b.complete) head.appendChild(el('span', 'su-badge su-badge-warn ms-2', t('incomplete')));
            info.appendChild(head);
            var meta = (b.created_at || '') + ' • ' + formatBytes((b.files_size || 0) + (b.db_size || 0))
                + ' • ' + t('backup_tenants', { count: b.tenants || 0 })
                + (b.tenants_skipped ? ' (' + t('backup_tenants_skipped', { count: b.tenants_skipped }) + ')' : '');
            info.appendChild(el('div', 'su-backup-meta', meta));
            item.appendChild(info);

            var actions = el('div', 'su-backup-actions');
            var restore = el('button', 'btn btn-outline btn-sm');
            restore.type = 'button';
            restore.title = t('restore_this_backup');
            restore.innerHTML = '<i class="bi bi-clock-history"></i>';
            restore.disabled = !b.complete || running;
            restore.addEventListener('click', function () { confirmRestore(b); });
            var del = el('button', 'btn btn-outline-danger btn-sm');
            del.type = 'button';
            del.title = t('delete');
            del.innerHTML = '<i class="bi bi-trash"></i>';
            del.disabled = running;
            del.addEventListener('click', function () { confirmDeleteBackup(b); });
            actions.appendChild(restore);
            actions.appendChild(del);
            item.appendChild(actions);
            list.appendChild(item);
        });
    }

    // ----------------------------------------------------------------- status

    function applyStatus(data) {
        if (!data || typeof data !== 'object' || !('current_version' in data)) return;
        st.status = data;
        render();
    }
    function refreshStatus() {
        return api('GET', E.status).then(applyStatus).catch(function () { /* keep the page usable */ });
    }

    // ----------------------------------------------------------------- upload

    function sha256OfFile(file) {
        if (!window.crypto || !window.crypto.subtle || file.size > 64 * 1024 * 1024) return Promise.resolve(null);
        return file.arrayBuffer()
            .then(function (buf) { return window.crypto.subtle.digest('SHA-256', buf); })
            .then(function (d) {
                return Array.prototype.map.call(new Uint8Array(d), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            })
            .catch(function () { return null; });
    }

    function upload(file) {
        if (!file) return;
        if (!/\.zip$/i.test(file.name)) {
            toast('error', t('only_zip'));
            return;
        }
        st.uploading = true;
        st.report = null;
        setUploadPercent(0);
        render();

        var total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        var uploadId = 'up-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);

        sha256OfFile(file).then(function (hash) {
            var index = 0;
            function next() {
                var blob = file.slice(index * CHUNK_SIZE, Math.min(file.size, (index + 1) * CHUNK_SIZE));
                var form = new FormData();
                form.append('chunk', blob, 'chunk.bin');
                form.append('index', String(index));
                form.append('total', String(total));
                form.append('upload_id', uploadId);
                form.append('filename', file.name);
                if (hash && index === total - 1) form.append('sha256', hash);
                return api('POST', E.upload, form).then(function () {
                    index++;
                    setUploadPercent(Math.round((index / total) * 100));
                    if (index < total) return next();
                });
            }
            return next();
        }).then(function () {
            st.uploading = false;
            toast('success', t('package_uploaded'));
            return refreshStatus().then(validatePackage);
        }).catch(function (e) {
            st.uploading = false;
            toast('error', e.message || t('upload_failed'));
            refreshStatus();
        });
    }
    function setUploadPercent(p) {
        $('suUploadBar').style.width = p + '%';
        $('suUploadPct').textContent = p + '%';
    }

    function validatePackage() {
        st.validating = true;
        render();
        return api('POST', E.validate).then(function (report) {
            st.report = report;
            if (!report.ok) toast('warning', t('package_blocked'));
        }).catch(function (e) {
            toast('error', e.message);
        }).then(function () {
            st.validating = false;
            return refreshStatus();
        });
    }

    function removePackage() {
        api('DELETE', E.deletePackage).then(function (d) {
            st.report = null;
            applyStatus(d);
            toast('success', t('removed'));
        }).catch(function (e) { toast('error', e.message); });
    }

    // -------------------------------------------------------------------- run

    function confirmStart() {
        var p = st.report && st.report.package ? st.report.package : {};
        var dbs = p.databases_backed_up != null ? p.databases_backed_up : p.databases;
        var minutes = p.estimate_seconds ? Math.max(1, Math.ceil(p.estimate_seconds / 60)) : '?';
        confirmDanger(t('confirm_title'), t('start_confirm', { count: dbs || '?', minutes: minutes }), t('yes_update')).then(function (ok) {
            if (!ok) return;
            api('POST', E.start).then(function (d) {
                applyStatus(d);
                runStepLoop();
            }).catch(function (e) { toast('error', e.message); });
        });
    }

    function runStepLoop() {
        if (st.stepping) return;
        st.stepping = true;
        st.stepErrors = 0;
        render();
        function next() {
            api('POST', E.step).then(function (d) {
                applyStatus(d);
                st.stepErrors = 0;
                var s = state();
                if (s && s.status === 'running') {
                    setTimeout(next, 500);
                } else {
                    st.stepping = false;
                    render();
                    if (s && s.status === 'completed') {
                        toast('success', s.type === 'restore' ? t('restore_successful') : t('update_successful'));
                    }
                }
            }).catch(function () {
                // Transient failures are expected around the file swap (new
                // code is being loaded) — retry with backoff before giving up.
                st.stepErrors++;
                if (st.stepErrors > 10) {
                    st.stepping = false;
                    toast('error', t('connection_lost'));
                    refreshStatus();
                    return;
                }
                setTimeout(next, 2500);
            });
        }
        next();
    }

    function resumeRun() {
        api('POST', E.resume).then(function (d) { applyStatus(d); runStepLoop(); })
            .catch(function (e) { toast('error', e.message); });
    }

    function confirmRollback() {
        confirmDanger(t('confirm_title'), t('rollback_confirm'), t('rollback_now')).then(function (ok) {
            if (!ok) return;
            api('POST', E.rollback).then(function (d) { applyStatus(d); runStepLoop(); })
                .catch(function (e) { toast('error', e.message); });
        });
    }

    function discardRun() {
        return api('POST', E.discard).then(applyStatus).catch(function (e) { toast('error', e.message); });
    }

    // The finished run's state lives server-side, so a plain reload would show
    // the "update successful" panel again. Acknowledge (clear) it first;
    // history and backups are kept separately.
    function reloadApp() {
        api('POST', E.discard).catch(function () {}).then(function () { window.location.reload(); });
    }

    // ---------------------------------------------------------------- backups

    function confirmRestore(b) {
        confirmDanger(t('confirm_title'), t('restore_confirm', { version: b.version || '?', date: b.created_at || '' }), t('restore')).then(function (ok) {
            if (!ok) return;
            api('POST', E.restoreBackup, { id: b.id }).then(function (d) { applyStatus(d); runStepLoop(); })
                .catch(function (e) { toast('error', e.message); });
        });
    }

    function confirmDeleteBackup(b) {
        confirmDanger(t('confirm_title'), t('delete_backup_confirm'), t('delete')).then(function (ok) {
            if (!ok) return;
            api('DELETE', E.deleteBackup.replace('__ID__', encodeURIComponent(b.id))).then(function (d) {
                applyStatus(d);
                toast('success', t('deleted'));
            }).catch(function (e) { toast('error', e.message); });
        });
    }

    function saveRetention() {
        var v = parseInt($('suRetention').value, 10);
        api('POST', E.retention, { retention: v }).then(function (d) {
            applyStatus(d);
            toast('success', t('saved'));
        }).catch(function (e) { toast('error', e.message); });
    }

    // ------------------------------------------------------------------- init

    function bind() {
        var input = $('suFile');
        var zone = $('suDropzone');
        zone.addEventListener('click', function () { input.click(); });
        zone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) upload(input.files[0]);
            input.value = '';
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-drag'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-drag'); });
        });
        zone.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) upload(f);
        });

        $('suBtnValidate').addEventListener('click', validatePackage);
        $('suBtnStart').addEventListener('click', confirmStart);
        $('suBtnRemove').addEventListener('click', removePackage);
        $('suBtnResume').addEventListener('click', resumeRun);
        $('suBtnRollback').addEventListener('click', confirmRollback);
        $('suBtnReload').addEventListener('click', reloadApp);
        $('suBtnDismiss').addEventListener('click', discardRun);
        $('suBtnRetention').addEventListener('click', saveRetention);
    }

    document.addEventListener('DOMContentLoaded', function () {
        bind();
        applyStatus(D.status);
        // A run left "running" by a closed browser can simply be continued.
        var s = state();
        if (s && s.status === 'running' && !s.stalled) runStepLoop();
        setInterval(function () {
            if (!st.stepping && !st.uploading) refreshStatus();
        }, 10000);
    });
})();
