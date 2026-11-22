document.addEventListener('DOMContentLoaded', function() {
    // Password toggle
    document.querySelectorAll('.btn-pw-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.closest('.position-relative').querySelector('input');
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });
    });

    // Role toggle → show/hide permissions
    var roleSelect = document.getElementById('roleSelect');
    var permBlock = document.getElementById('permissionsBlock');

    function togglePerms() {
        permBlock.classList.toggle('permissions-block-hidden', roleSelect.value !== 'admin');
    }
    roleSelect.addEventListener('change', togglePerms);
    togglePerms();

    // Allowed Sections: per-group toggles + select all / none + counters
    var wrap = document.getElementById('permGroups');
    if (!wrap) return;

    var boxSelector = 'input[name="permissions[]"]';
    var allBoxes = wrap.querySelectorAll(boxSelector);

    function syncCounters() {
        var total = 0;
        wrap.querySelectorAll('[data-perm-group]').forEach(function(group) {
            var boxes = group.querySelectorAll(boxSelector);
            var on = 0;
            boxes.forEach(function(b) { if (b.checked) on++; });
            total += on;

            var toggle = group.querySelector('[data-perm-group-toggle]');
            if (toggle) {
                toggle.checked = boxes.length > 0 && on === boxes.length;
                toggle.indeterminate = on > 0 && on < boxes.length;
            }
            var counter = group.querySelector('[data-perm-group-count]');
            if (counter) counter.textContent = on + ' / ' + boxes.length;
            group.classList.toggle('is-complete', boxes.length > 0 && on === boxes.length);
            group.classList.toggle('is-partial', on > 0 && on < boxes.length);
        });
        var count = wrap.querySelector('[data-perm-count]');
        if (count) count.textContent = total;
    }

    wrap.addEventListener('change', function(e) {
        if (e.target.matches('[data-perm-group-toggle]')) {
            var group = e.target.closest('[data-perm-group]');
            var checked = e.target.checked;
            group.querySelectorAll(boxSelector).forEach(function(b) { b.checked = checked; });
        }
        syncCounters();
    });

    wrap.querySelectorAll('[data-perm-all]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var checked = btn.getAttribute('data-perm-all') === '1';
            allBoxes.forEach(function(b) { b.checked = checked; });
            syncCounters();
        });
    });

    syncCounters();
});
