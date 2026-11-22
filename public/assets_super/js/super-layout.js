(function() {
    var sidebar = document.getElementById('sidebar');
    var toggleBtn = document.getElementById('sidebarToggle');
    var overlay = document.getElementById('sidebarOverlay');
    var mainContent = document.querySelector('.main-content');
    var STORAGE_KEY = 'super_sidebar_collapsed';
    var LAYOUT_KEY = 'super_sidebar_layout';
    var LAYOUTS = ['default', 'large', 'accordion', 'dark'];

    var sidebarNav = sidebar.querySelector('.sidebar-nav');

    function isMobile() {
        return window.innerWidth < 992;
    }

    function currentLayout() {
        for (var i = 0; i < LAYOUTS.length; i++) {
            if (document.body.classList.contains('sb-' + LAYOUTS[i])) return LAYOUTS[i];
        }
        return 'default';
    }

    // True when the nav is rendered as a floating flyout beside the rail
    // (Large layout while collapsed) rather than docked.
    function flyoutMode() {
        return !isMobile() && currentLayout() === 'large' && sidebar.classList.contains('collapsed');
    }

    function preventBgScroll(e) {
        if (sidebarNav && sidebarNav.contains(e.target)) {
            return;
        }
        e.preventDefault();
    }

    function openMobileSidebar() {
        document.documentElement.classList.add('sidebar-open');
        document.body.classList.add('sidebar-open');
        sidebar.classList.add('open');
        overlay.classList.add('show');
        mainContent.classList.add('blurred');
        document.addEventListener('touchmove', preventBgScroll, { passive: false });
    }

    function closeMobileSidebar() {
        document.removeEventListener('touchmove', preventBgScroll);
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        mainContent.classList.remove('blurred');
        document.documentElement.classList.remove('sidebar-open');
        document.body.classList.remove('sidebar-open');
    }

    // ── Sidebar layouts (theme customizer) ─────────────────────────────
    // The inline script after the <aside> already wrapped each section into a
    // .nav-group and built .sidebar-rail (one .rail-item per group with links,
    // paired by data-group). This only wires the behaviour.
    var groups = [];
    Array.prototype.forEach.call(sidebar.querySelectorAll('.sidebar-nav .nav-group'), function (el) {
        var idx = el.getAttribute('data-group');
        var label = el.querySelector('.sidebar-section-label');
        el.classList.toggle('is-empty', !el.querySelector('.nav-link'));
        groups.push({
            el: el,
            label: label,
            rail: idx === null ? null : sidebar.querySelector('.rail-item[data-group="' + idx + '"]'),
            active: !!el.querySelector('.nav-link.active'),
            multi: el.querySelectorAll('.nav-link').length > 1
        });
    });

    var hoverTimer = null;
    function cancelHover() { if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; } }

    groups.forEach(function (g) {
        if (!g.rail) return;
        g.rail.addEventListener('mouseenter', function () {
            if (isMobile()) return;
            cancelHover();
            // Hover intent: while a flyout/panel is already showing another
            // group, a pointer merely passing over this item on its way to the
            // flyout must not switch it. Open immediately when nothing is shown.
            var showing = sidebar.classList.contains('flyout-open') || !flyoutMode();
            if (showing && !g.el.classList.contains('is-open')) {
                hoverTimer = setTimeout(function () { hoverTimer = null; openGroup(g, false); }, 140);
            } else {
                openGroup(g, false);
            }
        });
        g.rail.addEventListener('mouseleave', cancelHover);
        g.rail.addEventListener('click', function (e) {
            if (!g.multi) return; // single-link group: the rail item is a plain link
            e.preventDefault();
            cancelHover();
            openGroup(g, true);
        });
    });

    // Accordion layout: section headers toggle their group, one open at a time.
    function toggleAccordion(g) {
        setOpen(g.el.classList.contains('is-open') ? null : g);
    }
    groups.forEach(function (g) {
        if (!g.label) return;
        g.label.addEventListener('click', function () {
            if (currentLayout() === 'accordion') toggleAccordion(g);
        });
        g.label.addEventListener('keydown', function (e) {
            if (currentLayout() !== 'accordion') return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleAccordion(g); }
        });
    });

    var pinned = null;
 // group the user clicked on the rail (docked panel keeps it)

    function homeGroup() {
        if (pinned) return pinned;
        for (var i = 0; i < groups.length; i++) if (groups[i].active) return groups[i];
        for (var j = 0; j < groups.length; j++) if (groups[j].rail) return groups[j];
        return null;
    }

    function setOpen(g) {
        var shown = !flyoutMode() || sidebar.classList.contains('flyout-open');
        var accordion = currentLayout() === 'accordion';
        groups.forEach(function (x) {
            x.el.classList.toggle('is-open', x === g);
            if (x.label && accordion) x.label.setAttribute('aria-expanded', x === g ? 'true' : 'false');
            if (!x.rail) return;
            x.rail.classList.toggle('is-open', x === g);
            if (x.multi) x.rail.setAttribute('aria-expanded', (x === g && shown) ? 'true' : 'false');
        });
    }

    function positionFlyout(g) {
        if (!g.rail) return;
        var r = g.rail.getBoundingClientRect();
        var s = sidebar.getBoundingClientRect();
        var top = Math.max(8, r.top - s.top);
        // Content height of the open group (scrollHeight ignores the max-height
        // cap); shift the flyout up when it would run past the viewport.
        var h = sidebarNav.scrollHeight + 2;
        var maxTop = window.innerHeight - h - 12;
        if (top > maxTop) top = Math.max(8, maxTop);
        sidebar.style.setProperty('--sb-flyout-top', top + 'px');
    }

    function openGroup(g, pin) {
        if (flyoutMode()) {
            sidebar.classList.add('flyout-open');
            setOpen(g);
            positionFlyout(g);
        } else {
            setOpen(g);
            if (pin) pinned = g;
        }
    }

    function closeFlyout() {
        cancelHover();
        sidebar.classList.remove('flyout-open');
        var h = homeGroup();
        if (h) setOpen(h);
    }

    sidebar.addEventListener('focusout', function (e) {
        // Only a real focus move out of the sidebar (Tab); mouse clicks on
        // non-focusable flyout areas report a null relatedTarget and stay open.
        if (isMobile() || !e.relatedTarget || sidebar.contains(e.relatedTarget)) return;
        if (sidebar.classList.contains('flyout-open')) closeFlyout();
    });

    sidebar.addEventListener('mouseleave', function () {
        if (isMobile() || currentLayout() !== 'large') return;
        if (flyoutMode()) {
            closeFlyout();
        } else {
            var h = homeGroup();
            if (h) setOpen(h);
        }
    });

    document.addEventListener('click', function (e) {
        if (isMobile() || sidebar.contains(e.target)) return;
        if (toggleBtn && toggleBtn.contains(e.target)) return;
        if (sidebar.classList.contains('flyout-open')) closeFlyout();
    });

    function applyLayout(layout) {
        LAYOUTS.forEach(function (l) { document.body.classList.remove('sb-' + l); });
        if (layout !== 'default') document.body.classList.add('sb-' + layout);
        sidebar.classList.remove('flyout-open');
        sidebar.style.removeProperty('--sb-flyout-top');
        pinned = null;
        if (!isMobile()) sidebar.classList.toggle('collapsed', localStorage.getItem(STORAGE_KEY) === '1');
        groups.forEach(function (g) {
            if (!g.label) return;
            if (layout === 'accordion') {
                g.label.setAttribute('role', 'button');
                g.label.setAttribute('tabindex', '0');
            } else {
                g.label.removeAttribute('role');
                g.label.removeAttribute('tabindex');
                g.label.removeAttribute('aria-expanded');
            }
        });
        var h = homeGroup();
        if (h) setOpen(h);
    }

    window.SuperSidebar = {
        layouts: LAYOUTS.slice(),
        getLayout: currentLayout,
        setLayout: function (layout) {
            if (LAYOUTS.indexOf(layout) === -1) layout = 'default';
            try {
                if (layout === 'default') localStorage.removeItem(LAYOUT_KEY);
                else localStorage.setItem(LAYOUT_KEY, layout);
            } catch (e) {}
            applyLayout(layout);
        }
    };

    // Body class was pre-applied by the head script; this syncs collapsed/open state to it.
    applyLayout(currentLayout());

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (isMobile()) {
                if (sidebar.classList.contains('open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                sidebar.classList.remove('flyout-open');
                sidebar.classList.toggle('collapsed');
                localStorage.setItem(STORAGE_KEY, sidebar.classList.contains('collapsed') ? '1' : '0');
                var h = homeGroup();
                if (h) setOpen(h);
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function() {
            closeMobileSidebar();
        });
    }

    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (isMobile()) {
                sidebar.classList.remove('collapsed', 'flyout-open');
            } else {
                closeMobileSidebar();
                if (localStorage.getItem(STORAGE_KEY) === '1') {
                    sidebar.classList.add('collapsed');
                }
            }
        }, 150);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        if (isMobile() && sidebar.classList.contains('open')) {
            closeMobileSidebar();
        } else if (!isMobile()) {
            if (sidebar.classList.contains('flyout-open')) closeFlyout();
        }
    });
})();

function swalConfirm(e, opts) {
    e.preventDefault();
    var form = e.target.closest('form') || e.target;
    var config = Object.assign({
        title: __s('are_you_sure'),
        text: '',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: __s('yes'),
        cancelButtonText: __s('cancel'),
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
    }, opts);
    Swal.fire(config).then(function(result) {
        if (result.isConfirmed) {
            form.submit();
        }
    });
    return false;
}

document.getElementById('themeToggle').addEventListener('click', function() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('super_theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
});

window.addEventListener('load', function() {
    var loader = document.getElementById('pageLoader');
    if (loader) loader.classList.add('hide');
});


// ── Global search (topbar) ─────────────────────────────────────────
(function () {
    var wrap = document.getElementById('topbarSearch');
    if (!wrap) return;
    var input = wrap.querySelector('input');
    var results = wrap.querySelector('.topbar-search-results');
    var url = wrap.getAttribute('data-url');
    var minLength = 2;
    var timer = null, controller = null, lastQuery = '', activeIndex = -1;

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function highlight(text, q) {
        var safe = escHtml(text);
        if (!q) return safe;
        var re = new RegExp('(' + escHtml(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
        return safe.replace(re, '<mark>$1</mark>');
    }
    function open() { results.classList.add('is-open'); input.setAttribute('aria-expanded', 'true'); }
    function close() { results.classList.remove('is-open'); input.setAttribute('aria-expanded', 'false'); activeIndex = -1; }
    function items() { return Array.prototype.slice.call(results.querySelectorAll('.topbar-search-item')); }
    function setActive(i) {
        var list = items();
        if (!list.length) return;
        activeIndex = (i + list.length) % list.length;
        list.forEach(function (el, idx) { el.classList.toggle('is-active', idx === activeIndex); });
        list[activeIndex].scrollIntoView({ block: 'nearest' });
    }
    function render(data, q) {
        var html = '';
        (data.groups || []).forEach(function (g) {
            if (!g.items.length && !g.more_url) return;
            html += '<div class="topbar-search-group"><div class="topbar-search-label">' + escHtml(g.label) + '</div>';
            g.items.forEach(function (it) {
                html += '<a class="topbar-search-item" href="' + escHtml(it.url) + '">'
                    + (it.initials
                        ? '<span class="av" style="background:' + escHtml(it.color || '#64748b') + '">' + escHtml(it.initials) + '</span>'
                        : '<span class="ic"><i class="bi ' + escHtml(it.icon || 'bi-dot') + '"></i></span>')
                    + '<span style="min-width:0"><span class="t d-block">' + highlight(it.title, q) + '</span>'
                    + (it.subtitle ? '<span class="s d-block">' + highlight(it.subtitle, q) + '</span>' : '') + '</span>'
                    + (it.badge ? '<span class="b">' + escHtml(it.badge) + '</span>' : '')
                    + '</a>';
            });
            if (g.more_url) {
                html += '<a class="topbar-search-item more" href="' + escHtml(g.more_url) + '"><span class="ic"><i class="bi bi-search"></i></span><span class="t">' + escHtml(g.more_label) + '</span></a>';
            }
            html += '</div>';
        });
        if (!html) html = '<div class="topbar-search-empty">' + escHtml(data.empty || '') + '</div>';
        results.innerHTML = html;
        activeIndex = -1;
        open();
    }
    function search(q) {
        if (controller) controller.abort();
        controller = new AbortController();
        fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, signal: controller.signal, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (q === lastQuery) render(data, q); })
            .catch(function () { /* aborted or offline: keep whatever is shown */ });
    }
    input.addEventListener('input', function () {
        var q = input.value.trim();
        lastQuery = q;
        clearTimeout(timer);
        if (q.length < minLength) { close(); return; }
        timer = setTimeout(function () { search(q); }, 220);
    });
    input.addEventListener('focus', function () { if (input.value.trim().length >= minLength && results.innerHTML) open(); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (!results.classList.contains('is-open')) return; setActive(activeIndex + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(activeIndex - 1); }
        else if (e.key === 'Enter') {
            var list = items();
            var target = activeIndex >= 0 ? list[activeIndex] : (list.length ? list[0] : null);
            if (target) { e.preventDefault(); window.location.href = target.href; }
        }
        else if (e.key === 'Escape') { close(); input.blur(); }
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });
})();
