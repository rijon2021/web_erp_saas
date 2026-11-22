// Super admin > Payments > Payment Links (index, create, show).
document.addEventListener('DOMContentLoaded', function () {
    // Copy a link to the clipboard (falls back to a hidden textarea on
    // non-secure origins, where navigator.clipboard is unavailable).
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (e) {
                reject(e);
            } finally {
                document.body.removeChild(ta);
            }
        });
    }

    function toast(icon, title) {
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2000 });
        }
    }

    document.querySelectorAll('.js-copy-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copyText(btn.getAttribute('data-url'))
                .then(function () { toast('success', __s('link_copied')); })
                .catch(function () { toast('error', __s('copy_failed')); });
        });
    });

    // Confirm before destructive submits (cancel link).
    document.querySelectorAll('button[data-swal-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            var cfg = {};
            try { cfg = JSON.parse(btn.getAttribute('data-swal-confirm')) || {}; } catch (err) { /* plain confirm */ }
            swalConfirm(e, cfg);
        });
    });

    // Create form: prefill the recipient from the chosen tenant and show the
    // email field only while "send email" is on.
    var tenantSelect = document.getElementById('plTenant');
    var sendEmail    = document.getElementById('plSendEmail');
    var emailWrap    = document.getElementById('plEmailWrap');
    var emailTo      = document.getElementById('plEmailTo');

    if (tenantSelect && emailTo) {
        var lastPrefill = '';
        var prefill = function () {
            var opt = tenantSelect.options[tenantSelect.selectedIndex];
            var email = opt ? (opt.getAttribute('data-email') || '') : '';
            // Only overwrite what we filled in ourselves, never a typed address.
            if (emailTo.value === '' || emailTo.value === lastPrefill) {
                emailTo.value = email;
                lastPrefill = email;
            }
        };
        tenantSelect.addEventListener('change', prefill);
        prefill();
    }

    if (sendEmail && emailWrap) {
        var toggle = function () {
            emailWrap.style.display = sendEmail.checked ? '' : 'none';
            if (emailTo) { emailTo.required = sendEmail.checked; }
        };
        sendEmail.addEventListener('change', toggle);
        toggle();
    }
});
