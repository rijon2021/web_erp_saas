/*
 * Live preview of the tenant login page (Settings > Login Page).
 * Loaded only by /login/preview, which the settings page shows in an iframe.
 * The parent posts { type: 'login-preview:update', state } and this applies
 * it in place. cssVars() mirrors App\Support\LoginPageAppearance::cssVars —
 * keep the two in step.
 */
(function () {
    var body = document.body;
    var form = document.getElementById('login_form');

    // Never submit from the preview.
    if (form) {
        form.noValidate = true;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }, true);
    }

    var INPUT_RADIUS = { pill: '999px', rounded: '12px', square: '4px' };
    var VAR_NAMES = [
        '--login-hero-bg', '--login-hero-text', '--login-page-bg', '--login-card-bg', '--login-text',
        '--login-primary', '--login-btn-text', '--login-card-radius', '--login-input-radius',
    ];

    function color(value) {
        return typeof value === 'string' && /^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(value) ? value : null;
    }

    function heroBackground(o, c1, imageUrl) {
        if (o.hero_bg_type === 'image' && imageUrl) {
            var shade = Math.round(o.hero_overlay) / 100;
            var url = String(imageUrl).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            return 'linear-gradient(rgba(0, 0, 0, ' + shade + '), rgba(0, 0, 0, ' + shade + ')), url("' + url + '") center / cover no-repeat ' + (c1 || '#6237ff');
        }
        if (o.hero_bg_type === 'solid') return c1 || '#6237ff';
        var c2 = color(o.hero_color_2);
        if (!c1 && !c2 && Number(o.hero_gradient_angle) === 140) return null;
        return 'linear-gradient(' + Number(o.hero_gradient_angle) + 'deg, ' + (c1 || '#7a4dff') + ', ' + (c2 || '#4f2bf8') + ')';
    }

    function cssVars(o, heroColor, imageUrl) {
        return {
            '--login-hero-bg': heroBackground(o, color(heroColor), imageUrl),
            '--login-hero-text': color(o.hero_text_color),
            '--login-page-bg': color(o.page_bg_color),
            '--login-card-bg': color(o.card_bg_color),
            '--login-text': color(o.text_color),
            '--login-primary': color(o.primary_color),
            '--login-btn-text': color(o.button_text_color),
            '--login-card-radius': o.card_radius + 'px',
            '--login-input-radius': INPUT_RADIUS[o.input_style] || INPUT_RADIUS.pill,
        };
    }

    function apply(state) {
        var o = state.options || {};
        var vars = cssVars(o, state.login_bg_color, state.image_url);
        VAR_NAMES.forEach(function (name) {
            if (vars[name]) body.style.setProperty(name, vars[name]);
            else body.style.removeProperty(name);
        });

        body.setAttribute('data-layout', o.layout || 'split');
        body.setAttribute('data-hero-align', o.hero_align || 'left');
        body.setAttribute('data-panel-align', o.panel_align || 'left');
        body.setAttribute('data-card-shadow', o.card_shadow === false ? 'off' : 'on');

        // Texts: empty falls back to data-default, or hides the element.
        var texts = state.texts || {};
        document.querySelectorAll('[data-text]').forEach(function (el) {
            var value = (texts[el.dataset.text] || '').trim();
            var fallback = el.dataset.default;
            el.textContent = value || fallback || '';
            el.hidden = !value && fallback === undefined;
        });
        var features = document.querySelector('.hero-features');
        if (features) {
            features.hidden = !features.querySelector('li:not([hidden])');
        }

        document.querySelectorAll('[data-option]').forEach(function (el) {
            el.hidden = !o[el.dataset.option];
        });
        var logo = document.querySelector('.panel-logo');
        if (logo) logo.hidden = o.show_logo === false;
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) return;
        var data = event.data || {};
        if (data.type === 'login-preview:update' && data.state) apply(data.state);
    });

    if (window.parent !== window) {
        window.parent.postMessage({ type: 'login-preview:ready' }, window.location.origin);
    }
})();
