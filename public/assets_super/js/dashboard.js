var verifiedTenants = {};

// Toggle DB configuration form
function toggleDbForm(tenantId) {
    var row = document.getElementById('db-form-' + tenantId);
    row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
}

// Test DB connection via AJAX
function testDbConnection(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    var form = document.getElementById('approve-form-' + tenantId);
    var resultDiv = document.getElementById('db-test-result-' + tenantId);

    var payload = {
        db_host:     form.querySelector('[name=db_host]').value.trim(),
        db_name:     form.querySelector('[name=db_name]').value.trim(),
        db_username: form.querySelector('[name=db_username]').value.trim(),
        db_password: form.querySelector('[name=db_password]').value,
        db_port:     form.querySelector('[name=db_port]').value.trim(),
    };

    resultDiv.style.display = 'block';

    if (!payload.db_host || !payload.db_name || !payload.db_username || !payload.db_port) {
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.fillDbFields + '</div>';
        return;
    }

    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.testingConnection + '</div>';

    fetch(data.tenantsUrl + '/' + tenantId + '/test-connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': data.csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(payload),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (res.success) {
            verifiedTenants[tenantId] = true;
            if (approveBtn) approveBtn.classList.remove('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-success"><i class="bi bi-check-circle-fill me-1"></i>' + res.message + '</div>';
        } else if (res.db_not_found) {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" data-create-db="' + tenantId + '">'
                + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                + '</div>';
        } else {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        verifiedTenants[tenantId] = false;
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (approveBtn) approveBtn.classList.add('approve-locked');
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.requestFailed + ': ' + err.message + '</div>';
    });
}

function confirmApprove(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    if (!verifiedTenants[tenantId]) {
        Swal.fire({
            title: trans.testConnectionFirst,
            text: trans.testConnectionFirstText,
            icon: 'warning',
            confirmButtonColor: '#f59e0b',
        });
        return;
    }
    var form = document.getElementById('approve-form-' + tenantId);
    if (!form) { console.error('Form not found: approve-form-' + tenantId); return; }
    var host = form.querySelector('[name=db_host]').value.trim();
    var name = form.querySelector('[name=db_name]').value.trim();
    if (!host || !name) return;

    // Re-verify the DB connection with the current form values right before provisioning,
    // so the heavy provisioning modal never opens against broken credentials.
    Swal.fire({
        title: trans.verifyingConnection,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: function() { Swal.showLoading(); },
    });

    var verifyPayload = {
        db_host:     host,
        db_name:     name,
        db_username: form.querySelector('[name=db_username]').value.trim(),
        db_password: form.querySelector('[name=db_password]').value,
        db_port:     form.querySelector('[name=db_port]').value.trim(),
    };

    fetch(data.tenantsUrl + '/' + tenantId + '/test-connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': data.csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(verifyPayload),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        var resultDiv = document.getElementById('db-test-result-' + tenantId);

        if (!res.success) {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');

            if (res.db_not_found) {
                // DB missing but server creds are valid — render the same amber
                // panel + Create Database button used by the initial test click.
                if (resultDiv) {
                    resultDiv.style.display = 'block';
                    resultDiv.innerHTML = '<div style="padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:0.82rem; color:#92400e;">'
                        + '<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-database-exclamation" style="font-size:1rem;"></i><span>' + res.message + '</span></div>'
                        + '<button type="button" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; padding:5px 14px;" data-create-db="' + tenantId + '">'
                        + '<i class="bi bi-database-add me-1"></i>' + trans.createDatabase + '</button>'
                        + '</div>';
                }
                Swal.fire({
                    title: trans.connectionCheckFailed,
                    text: res.message || '',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: trans.createDatabase,
                    confirmButtonColor: '#10b981',
                    cancelButtonText: trans.cancelText,
                }).then(function(result) {
                    if (result.isConfirmed) {
                        createTenantDatabase(tenantId);
                    }
                });
                return;
            }

            if (resultDiv) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + (res.message || '') + '</div>';
            }
            Swal.fire({
                title: trans.connectionCheckFailed,
                text: res.message || '',
                icon: 'error',
                confirmButtonColor: '#ef4444',
            });
            return;
        }

        // Connection verified — proceed to the approve confirmation + provisioning flow.
        Swal.fire({
            title: trans.approveTenantTitle,
            html: trans.approveTenantHtml.replace(':name', name).replace(':host', host),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: trans.yesApprove,
            confirmButtonColor: '#10b981',
            cancelButtonText: trans.cancelText,
        }).then(function(result) {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: trans.provisioningTitle,
                html: trans.provisioningHtml,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: function() { Swal.showLoading(); },
            });

            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': data.csrfToken },
                body: formData,
                redirect: 'manual',
            }).then(function() {
                pollApproveStatus(tenantId);
            }).catch(function() {
                pollApproveStatus(tenantId);
            });
        });
    })
    .catch(function(err) {
        Swal.fire({
            title: trans.connectionCheckFailed,
            text: trans.requestFailed + ': ' + (err && err.message ? err.message : ''),
            icon: 'error',
            confirmButtonColor: '#ef4444',
        });
    });
}

function pollApproveStatus(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    var statusUrl = data.tenantsUrl + '/' + tenantId + '/provisioning-status';
    var seconds = 0;

    var timer = setInterval(function() {
        seconds += 3;
        var stepEl = document.getElementById('swal-step');
        if (stepEl) {
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            stepEl.textContent = __s('elapsed') + ' ' + (mins > 0 ? mins + 'm ' : '') + secs + 's';
        }

        if (seconds >= 600) {
            clearInterval(timer);
            Swal.fire({
                title: trans.provisioningFailedTitle,
                text: trans.provisioningTimedOutText,
                icon: 'warning',
                confirmButtonColor: '#f59e0b',
            }).then(function() { location.reload(); });
            return;
        }

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': data.csrfToken }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.status === 'active') {
                clearInterval(timer);
                Swal.fire({
                    title: trans.tenantApprovedTitle,
                    text: trans.tenantApprovedText,
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                }).then(function() { location.reload(); });
            } else if (res.status === 'failed') {
                clearInterval(timer);
                Swal.fire({
                    title: trans.provisioningFailedTitle,
                    text: res.error || '',
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                }).then(function() { location.reload(); });
            }
        })
        .catch(function() { /* keep polling */ });
    }, 3000);
}

function createTenantDatabase(tenantId) {
    var data = window.DashboardData || {};
    var trans = data.trans || {};
    var form = document.getElementById('approve-form-' + tenantId);
    var resultDiv = document.getElementById('db-test-result-' + tenantId);

    var payload = {
        db_host:     form.querySelector('[name=db_host]').value,
        db_name:     form.querySelector('[name=db_name]').value,
        db_username: form.querySelector('[name=db_username]').value,
        db_password: form.querySelector('[name=db_password]').value,
        db_port:     form.querySelector('[name=db_port]').value,
    };

    resultDiv.innerHTML = '<div class="ajax-result-info"><i class="bi bi-hourglass-split me-1"></i>' + trans.creatingDatabase + '</div>';

    fetch(data.tenantsUrl + '/' + tenantId + '/create-database', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': data.csrfToken,
            'Accept': 'application/json',
        },
        body: JSON.stringify(payload),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        if (res.success) {
            // Database created — re-test the connection so the same
            // "Connection successful" UX (green message + button unlock) is shown.
            testDbConnection(tenantId);
        } else {
            verifiedTenants[tenantId] = false;
            if (approveBtn) approveBtn.classList.add('approve-locked');
            resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + res.message + '</div>';
        }
    })
    .catch(function(err) {
        var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
        verifiedTenants[tenantId] = false;
        if (approveBtn) approveBtn.classList.add('approve-locked');
        resultDiv.innerHTML = '<div class="ajax-result-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + trans.requestFailed + ': ' + err.message + '</div>';
    });
}

// Reject modal logic
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    var data = window.DashboardData || {};
    var trans = data.trans || {};

    // Toggle DB form buttons (event delegation)
    document.addEventListener('click', function(e) {
        var toggleBtn = e.target.closest('[data-toggle-db]');
        if (toggleBtn) {
            toggleDbForm(toggleBtn.getAttribute('data-toggle-db'));
        }
        var testBtn = e.target.closest('[data-test-db]');
        if (testBtn) {
            testDbConnection(testBtn.getAttribute('data-test-db'));
        }
        var approveBtn = e.target.closest('[data-confirm-approve]');
        if (approveBtn) {
            confirmApprove(approveBtn.getAttribute('data-confirm-approve'));
        }
        var createDbBtn = e.target.closest('[data-create-db]');
        if (createDbBtn) {
            createTenantDatabase(createDbBtn.getAttribute('data-create-db'));
        }
    });

    // Re-lock approve button when DB fields change
    document.querySelectorAll('form[id^="approve-form-"]').forEach(function(form) {
        var tenantId = form.id.replace('approve-form-', '');
        form.querySelectorAll('input[name^="db_"]').forEach(function(field) {
            field.addEventListener('input', function() {
                verifiedTenants[tenantId] = false;
                var approveBtn = document.querySelector('[data-confirm-approve="' + tenantId + '"]');
                if (approveBtn) approveBtn.classList.add('approve-locked');
                var resultDiv = document.getElementById('db-test-result-' + tenantId);
                if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }
            });
        });
    });

    // Close reject modal button
    var closeRejectBtn = document.getElementById('closeRejectModalBtn');
    if (closeRejectBtn) {
        closeRejectBtn.addEventListener('click', function() {
            closeRejectModal();
        });
    }

    document.querySelectorAll('.btn-reject-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tenantId = this.getAttribute('data-tenant-id');
            var tenantName = this.getAttribute('data-tenant-name');
            document.getElementById('rejectModalTenantName').textContent = trans.rejectRegistrationFor.replace(':name', tenantName);
            document.getElementById('rejectForm').action = data.tenantsUrl + '/' + tenantId + '/reject';
            document.getElementById('rejectModal').style.display = 'flex';
        });
    });

    var modal = document.getElementById('rejectModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeRejectModal();
        });
    }

    // Charts — one spec per enabled chart widget (App\Services\SuperDashboard\WidgetData)
    var chartData = data.charts || {};
    renderDashboardCharts(chartData.specs || [], chartData);
    renderSparklines();

    // Auto-refresh (Settings > General > Dashboard). Skipped while the admin
    // is typing or has a pending-tenant DB form open, so no input is lost.
    var refreshMinutes = parseInt(data.refreshMinutes, 10) || 0;
    if (refreshMinutes > 0) {
        setInterval(function() {
            var active = document.activeElement;
            var typing = active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName);
            var formOpen = Array.prototype.some.call(document.querySelectorAll('.db-form-row'), function(row) {
                return row.style.display === 'table-row';
            });
            var modal = document.getElementById('rejectModal');
            var modalOpen = modal && modal.style.display === 'flex';
            var swalOpen = typeof Swal !== 'undefined' && Swal.isVisible && Swal.isVisible();
            if (!typing && !formOpen && !modalOpen && !swalOpen && document.visibilityState === 'visible') {
                location.reload();
            }
        }, refreshMinutes * 60000);
    }
});

// KPI sparklines: thin line, soft area, emphasised endpoint; colours follow the theme.
function renderSparklines() {
    var svgs = document.querySelectorAll('svg[data-spark]');
    if (!svgs.length) return;
    var accent = dashCssVar('--color-primary', '#018A00');
    var surface = dashCssVar('--dsh-surface', '#ffffff');
    var ns = 'http://www.w3.org/2000/svg';
    svgs.forEach(function(svg) {
        var v = svg.getAttribute('data-spark').split(',').map(Number).filter(function(x) { return !isNaN(x); });
        if (v.length < 2) return;
        Array.prototype.slice.call(svg.childNodes).forEach(function(c) { if (c.nodeName !== 'title') svg.removeChild(c); });
        var w = 104, h = 40, pad = 3;
        var min = Math.min.apply(null, v), max = Math.max.apply(null, v);
        var x = function(i) { return pad + i * (w - pad * 2) / (v.length - 1); };
        var y = function(d) { return h - pad - (d - min) / (max - min || 1) * (h - pad * 2); };
        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
        var pts = v.map(function(d, i) { return x(i) + ',' + y(d); }).join(' ');
        var area = document.createElementNS(ns, 'polygon');
        area.setAttribute('points', x(0) + ',' + (h - pad) + ' ' + pts + ' ' + x(v.length - 1) + ',' + (h - pad));
        area.setAttribute('fill', accent); area.setAttribute('opacity', '0.12');
        var line = document.createElementNS(ns, 'polyline');
        line.setAttribute('points', pts); line.setAttribute('fill', 'none'); line.setAttribute('stroke', accent);
        line.setAttribute('stroke-width', '2'); line.setAttribute('stroke-linejoin', 'round'); line.setAttribute('stroke-linecap', 'round');
        var dot = document.createElementNS(ns, 'circle');
        dot.setAttribute('cx', x(v.length - 1)); dot.setAttribute('cy', y(v[v.length - 1])); dot.setAttribute('r', '3.5');
        dot.setAttribute('fill', accent); dot.setAttribute('stroke', surface); dot.setAttribute('stroke-width', '2');
        svg.appendChild(area); svg.appendChild(line); svg.appendChild(dot);
    });
}

function dashCssVar(name, fallback) {
    var v = getComputedStyle(document.body).getPropertyValue(name).trim();
    return v || fallback;
}

function renderDashboardCharts(specs, chartData) {
    if (!specs.length || typeof ApexCharts === 'undefined') return;

    var symbol = chartData.currencySymbol || '';
    var dark = document.body.classList.contains('dark-mode');
    var ink3 = dashCssVar('--dsh-ink-3', '#8a9099');
    var ink2 = dashCssVar('--dsh-ink-2', '#475569');
    var gridColor = dashCssVar('--dsh-grid', '#e6e8e3');
    var surface = dashCssVar('--dsh-surface', '#ffffff');
    var font = 'Plus Jakarta Sans, system-ui, sans-serif';
    var axisLabel = { colors: ink3, fontSize: '11px', fontWeight: 500, fontFamily: font };
    var base = {
        chart: { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: font, parentHeightOffset: 0, animations: { speed: 400 } },
        grid: { borderColor: gridColor, strokeDashArray: 0, xaxis: { lines: { show: false } }, padding: { left: 4, right: 8 } },
        tooltip: { theme: dark ? 'dark' : 'light', style: { fontFamily: font, fontSize: '12px' } },
        states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } }
    };

    function money(v) {
        var n = Number(v) || 0;
        return symbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function compactMoney(v) {
        var n = Number(v) || 0;
        var abs = Math.abs(n);
        if (abs >= 1e6) return symbol + (n / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return symbol + (n / 1e3).toFixed(1) + 'k';
        return symbol + Math.round(n);
    }
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function chartOptions(extra) {
        var opts = Object.assign({}, base, extra);
        opts.chart = Object.assign({}, base.chart, extra.chart || {});
        opts.grid = Object.assign({}, base.grid, extra.grid || {});
        return opts;
    }
    function empty(el) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="bi bi-bar-chart"></i></div><h3>'
            + escHtml(chartData.noDataLabel) + '</h3><p>' + escHtml(chartData.noDataDesc) + '</p></div>';
    }
    // Month labels arrive as "Jan 2026"; the short month is enough on the axis.
    function shortCategory(c) { return String(c).replace(/\s+\d{4}$/, ''); }

    specs.forEach(function(spec) {
        var el = document.getElementById(spec.el);
        if (!el) return;
        if (spec.empty) { empty(el); return; }

        var valueFormatter = spec.money ? money : function(v) { return v; };
        var options;

        if (spec.kind === 'series') {
            var type = spec.type === 'bar' ? 'bar' : (spec.type === 'line' ? 'line' : 'area');
            var multi = spec.series.length > 1;
            var dash = spec.dash || spec.series.map(function() { return 0; });
            options = chartOptions({
                chart: { type: type, height: 300 },
                dataLabels: type === 'bar' && multi
                    ? { enabled: true, enabledOnSeries: [0], offsetY: -18, style: { fontSize: '11px', fontWeight: 700, colors: [ink2] }, background: { enabled: false }, dropShadow: { enabled: false } }
                    : { enabled: false },
                stroke: type === 'bar' ? { show: true, width: 2, colors: [surface] } : { curve: 'straight', width: multi ? [2.2, 1.5] : 2.2, dashArray: dash, lineCap: 'round' },
                fill: type === 'area'
                    ? { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: multi ? [0.18, 0] : 0.18, opacityTo: 0.02, stops: [0, 90, 100] } }
                    : { opacity: 1 },
                plotOptions: { bar: { borderRadius: 4, borderRadiusApplication: 'end', columnWidth: multi ? '55%' : '38%', dataLabels: { position: 'top' } } },
                colors: spec.colors,
                series: spec.series,
                legend: { show: false },
                xaxis: { categories: spec.categories.map(shortCategory), labels: { style: axisLabel, rotate: 0, hideOverlappingLabels: true, trim: false }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
                yaxis: { labels: { style: axisLabel, formatter: spec.money ? compactMoney : function(v) { return Math.round(v); } }, min: 0, tickAmount: 4 },
                tooltip: Object.assign({}, base.tooltip, { shared: true, intersect: false, y: { formatter: valueFormatter }, x: { formatter: function(v, o) { return spec.categories[o.dataPointIndex] || v; } } }),
                markers: type === 'bar' ? { size: 0 } : { size: 0, strokeWidth: 0, hover: { size: 5, sizeOffset: 0 } }
            });
            if (type !== 'bar') {
                // Emphasised endpoint on the main series.
                var last = spec.series[0].data.length - 1;
                options.markers.discrete = [{ seriesIndex: 0, dataPointIndex: last, fillColor: spec.colors[0], strokeColor: surface, strokeWidth: 2, size: 4.5 }];
            }
        } else if (spec.type === 'donut' || spec.type === 'pie') {
            options = chartOptions({
                chart: { type: spec.type, height: 280 },
                labels: spec.labels,
                series: spec.values,
                colors: spec.colors,
                legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '12px', fontWeight: 500, labels: { colors: ink2 }, markers: { radius: 3 }, itemMargin: { horizontal: 8, vertical: 3 } },
                plotOptions: { pie: { donut: { size: '72%', labels: {
                    show: spec.type === 'donut',
                    name: { fontSize: '13px', fontWeight: 600, color: ink2 },
                    value: { fontSize: '22px', fontWeight: 800, formatter: function(v) { return spec.money ? compactMoney(v) : v; } },
                    total: { show: true, fontSize: '12px', fontWeight: 600, color: ink3, formatter: function(w) {
                        var sum = w.globals.seriesTotals.reduce(function(a, b) { return a + b; }, 0);
                        return spec.money ? compactMoney(sum) : sum;
                    } }
                } } } },
                dataLabels: { enabled: spec.type === 'pie' },
                tooltip: Object.assign({}, base.tooltip, { y: { formatter: valueFormatter } }),
                stroke: { width: 2, colors: [surface] }
            });
        } else {
            // Categorical bar: horizontal, one colour per bar.
            options = chartOptions({
                chart: { type: 'bar', height: Math.max(220, spec.labels.length * 36 + 40) },
                plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '55%', distributed: true } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 6, style: { fontSize: '12px', fontWeight: 700, colors: [ink2] }, formatter: spec.money ? compactMoney : function(v) { return v; } },
                colors: spec.colors,
                series: [{ name: spec.seriesName, data: spec.values }],
                xaxis: { categories: spec.labels, labels: { style: axisLabel, formatter: spec.money ? compactMoney : function(v) { return v; } }, axisBorder: { show: false }, axisTicks: { show: false } },
                yaxis: { labels: { style: { colors: ink2, fontSize: '12px', fontWeight: 600, fontFamily: font } } },
                grid: { xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
                tooltip: Object.assign({}, base.tooltip, { y: { formatter: valueFormatter } }),
                legend: { show: false }
            });
        }

        new ApexCharts(el, options).render();
    });
}
