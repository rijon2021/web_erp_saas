/**
 * Settings > General > Dashboard — reorder, toggle and size dashboard widgets.
 * The DOM order of .dl-item rows is the saved order (each row carries a
 * hidden dashboard_layout[order][] input), so moving a row is all it takes.
 */
(function () {
    var list = document.getElementById('dlList');
    if (!list) return;

    var data = window.DashboardLayoutData || {};
    var trans = data.trans || {};
    var panel = document.getElementById('panel-dashboard');
    var countEl = document.getElementById('dlCount');
    var activeFilter = 'all';

    function items() {
        return Array.prototype.slice.call(list.querySelectorAll('.dl-item'));
    }
    function isShown(item) {
        return item.style.display !== 'none';
    }

    // ── Counter + move-button state ──────────────────────────────
    function refresh() {
        var all = items();
        var on = all.filter(function (item) { return !item.classList.contains('is-off'); }).length;
        if (countEl && trans.shownCount) {
            countEl.textContent = trans.shownCount.replace(':on', on).replace(':total', all.length);
        }
        var visible = all.filter(isShown);
        visible.forEach(function (item, i) {
            item.querySelector('[data-dl-move="up"]').disabled = i === 0;
            item.querySelector('[data-dl-move="down"]').disabled = i === visible.length - 1;
        });
    }

    function setEnabled(item, enabled) {
        var box = item.querySelector('.dl-enabled');
        if (!box) return; // locked widget
        box.checked = enabled;
        item.classList.toggle('is-off', !enabled);
    }

    // ── Toggle ──────────────────────────────────────────────────
    list.addEventListener('change', function (e) {
        if (e.target.classList.contains('dl-enabled')) {
            e.target.closest('.dl-item').classList.toggle('is-off', !e.target.checked);
            applyFilter();
        }
    });

    // ── Up / down (moves past the neighbouring row that is currently shown) ──
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dl-move]');
        if (!btn) return;
        var item = btn.closest('.dl-item');
        var dir = btn.getAttribute('data-dl-move');
        var sibling = dir === 'up' ? item.previousElementSibling : item.nextElementSibling;
        while (sibling && !isShown(sibling)) {
            sibling = dir === 'up' ? sibling.previousElementSibling : sibling.nextElementSibling;
        }
        if (!sibling) return;
        list.insertBefore(item, dir === 'up' ? sibling : sibling.nextElementSibling);
        flash(item);
        refresh();
        btn.focus();
    });

    function flash(item) {
        item.classList.remove('is-moved');
        void item.offsetWidth;
        item.classList.add('is-moved');
    }

    // ── Drag and drop ───────────────────────────────────────────
    // Only the grip handle is draggable (the row's selects never sit inside a
    // draggable element); the whole row is used as the drag image.
    var dragging = null;

    list.addEventListener('dragstart', function (e) {
        var handle = e.target.closest && e.target.closest('.dl-handle');
        if (!handle) return;
        var item = handle.closest('.dl-item');
        dragging = item;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', item.getAttribute('data-key')); } catch (err) { /* old browsers */ }
        if (e.dataTransfer.setDragImage) {
            var box = item.getBoundingClientRect();
            e.dataTransfer.setDragImage(item, e.clientX - box.left, e.clientY - box.top);
        }
        // Style after the drag image is captured.
        setTimeout(function () { if (dragging) dragging.classList.add('is-dragging'); }, 0);
    });

    list.addEventListener('dragover', function (e) {
        if (!dragging) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        // Only touch the DOM when the slot changes: re-inserting the drag
        // source in place can cancel the drag in Chromium.
        var after = rowAfter(e.clientY);
        if (after === null) {
            if (list.lastElementChild !== dragging) list.appendChild(dragging);
        } else if (after !== dragging && dragging.nextElementSibling !== after) {
            list.insertBefore(dragging, after);
        }
    });

    list.addEventListener('drop', function (e) {
        if (dragging) e.preventDefault();
    });

    list.addEventListener('dragend', function () {
        if (!dragging) return;
        dragging.classList.remove('is-dragging');
        flash(dragging);
        dragging = null;
        refresh();
    });

    // The first shown row whose middle is below the pointer.
    function rowAfter(y) {
        var candidates = items().filter(function (item) { return item !== dragging && isShown(item); });
        for (var i = 0; i < candidates.length; i++) {
            var box = candidates[i].getBoundingClientRect();
            if (y < box.top + box.height / 2) return candidates[i];
        }
        return null;
    }

    // ── Filters ─────────────────────────────────────────────────
    function applyFilter() {
        items().forEach(function (item) {
            var show = activeFilter === 'all'
                || (activeFilter === 'on' && !item.classList.contains('is-off'))
                || item.getAttribute('data-group') === activeFilter;
            item.style.display = show ? '' : 'none';
        });
        refresh();
    }

    panel.querySelectorAll('[data-dl-filter]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            activeFilter = chip.getAttribute('data-dl-filter');
            panel.querySelectorAll('[data-dl-filter]').forEach(function (c) { c.classList.toggle('is-active', c === chip); });
            applyFilter();
        });
    });

    // ── Show all / hide all (rows in the current filter) ─────────
    panel.querySelectorAll('[data-dl-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var enable = btn.getAttribute('data-dl-bulk') === '1';
            items().filter(isShown).forEach(function (item) { setEnabled(item, enable); });
            applyFilter();
        });
    });

    // ── Reset to defaults (applies on Save, like every other field) ──
    function resetToDefaults() {
        var byKey = {};
        items().forEach(function (item) { byKey[item.getAttribute('data-key')] = item; });
        (data.defaultOrder || []).forEach(function (key) {
            if (byKey[key]) list.appendChild(byKey[key]);
        });
        panel.querySelectorAll('[data-default]').forEach(function (input) {
            var def = input.getAttribute('data-default');
            if (input.type === 'checkbox') {
                input.checked = def === '1';
                var item = input.closest('.dl-item');
                if (item) item.classList.toggle('is-off', !input.checked);
            } else {
                input.value = def;
            }
        });
        applyFilter();
    }

    var resetBtn = document.getElementById('dlReset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
                Swal.fire({
                    title: trans.resetTitle,
                    text: trans.resetText,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: trans.resetConfirm,
                    cancelButtonText: trans.cancel,
                    confirmButtonColor: '#ef4444',
                }).then(function (result) { if (result.isConfirmed) resetToDefaults(); });
            } else if (window.confirm(trans.resetText)) {
                resetToDefaults();
            }
        });
    }

    refresh();
})();
