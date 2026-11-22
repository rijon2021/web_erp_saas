{{--
    Settings > General > Security tab. Expects $security (array from
    SecuritySettingsController::viewData()): options, providers,
    imageCaptcha, migrated.
--}}
@php
    extract($security, EXTR_SKIP);
    $o = $options;
    $val = fn ($key) => old($key, $o[$key]);
    $checked = fn ($key) => old('_token') !== null && old('captcha_provider') !== null ? (bool) old($key) : (bool) $o[$key];
    $durations = [1, 2, 5, 10, 15, 30, 60, 120, 360, 720, 1440];
    if (! in_array((int) $val('lockout_minutes'), $durations, true)) {
        $durations[] = (int) $val('lockout_minutes');
        sort($durations);
    }
    $durationLabel = fn ($m) => $m < 60
        ? trans_choice('super.security_settings.minutes', $m, ['count' => $m])
        : trans_choice('super.security_settings.hours', intdiv($m, 60), ['count' => intdiv($m, 60)]);
@endphp

@if (! $migrated)
    <div class="alert alert-error">{{ __('super.security_settings.not_migrated') }}</div>
@endif

<form method="POST" action="{{ route('super.settings.security.update') }}" id="securityForm">
    @csrf
    @method('PUT')
    <input type="hidden" name="captcha_test_token" id="captchaTestToken" value="">

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- CAPTCHA --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-robot me-2 text-muted"></i>{{ __('super.security_settings.captcha') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="captcha_enabled" value="1" class="form-check-input" id="captchaEnabled" {{ $checked('captcha_enabled') ? 'checked' : '' }}>
                            <span class="fw-600">{{ __('super.security_settings.captcha_enable') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.security_settings.captcha_enable_hint') }}</p>
                    </div>

                    <div id="captchaFields">
                        <div class="form-group mb-4">
                            <label class="form-label">{{ __('super.security_settings.provider') }}</label>
                            <select name="captcha_provider" class="form-control" id="captchaProvider">
                                <option value="builtin" {{ $val('captcha_provider') === 'builtin' ? 'selected' : '' }}>{{ __('super.security_settings.provider_builtin') }}</option>
                                @foreach ($providers as $key => $p)
                                    <option value="{{ $key }}" {{ $val('captcha_provider') === $key ? 'selected' : '' }}>{{ $p['label'] }}</option>
                                @endforeach
                            </select>
                            @if (! $imageCaptcha)
                                <p class="form-hint">{{ __('super.security_settings.builtin_no_gd') }}</p>
                            @endif
                        </div>

                        <div id="externalFields">
                            <p class="form-hint mb-3">{{ __('super.security_settings.keys_hint', ['domain' => request()->getHost()]) }}</p>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('super.security_settings.site_key') }}</label>
                                    <input type="text" name="captcha_site_key" id="captchaSiteKey" class="form-control" value="{{ $val('captcha_site_key') }}" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        {{ __('super.security_settings.secret_key') }}
                                        <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                                    </label>
                                    <input type="password" name="captcha_secret_key" class="form-control" value="" autocomplete="new-password">
                                    @if ($o['captcha_secret_set'])
                                        <p class="form-hint">{{ __('super.security_settings.secret_set_hint') }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="border rounded p-3 mb-4">
                                <div class="fw-600 mb-1">{{ __('super.security_settings.test_title') }}</div>
                                <p class="form-hint mb-2">{{ __('super.security_settings.test_hint') }}</p>
                                <button type="button" class="btn btn-outline btn-sm" id="loadTestWidget"><i class="bi bi-play-circle"></i> {{ __('super.security_settings.test_load') }}</button>
                                <div id="testWidgetHost" class="mt-3"></div>
                                <div id="testWidgetStatus" class="fs-sm2 mt-2"></div>
                                @error('captcha_test')
                                    <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('super.security_settings.mode') }}</label>
                                <select name="captcha_mode" class="form-control" id="captchaMode">
                                    <option value="always" {{ $val('captcha_mode') === 'always' ? 'selected' : '' }}>{{ __('super.security_settings.mode_always') }}</option>
                                    <option value="after_failures" {{ $val('captcha_mode') === 'after_failures' ? 'selected' : '' }}>{{ __('super.security_settings.mode_after') }}</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="afterFailuresWrap">
                                <label class="form-label">{{ __('super.security_settings.after_failures') }}</label>
                                <input type="number" name="captcha_after_failures" class="form-control" min="1" max="20" value="{{ $val('captcha_after_failures') }}">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Lockout --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-shield-lock me-2 text-muted"></i>{{ __('super.security_settings.lockout') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="lockout_enabled" value="1" class="form-check-input" id="lockoutEnabled" {{ $checked('lockout_enabled') ? 'checked' : '' }}>
                            <span class="fw-600">{{ __('super.security_settings.lockout_enable') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.security_settings.lockout_enable_hint') }}</p>
                    </div>

                    <div id="lockoutFields">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('super.security_settings.max_attempts') }}</label>
                                <input type="number" name="lockout_max_attempts" class="form-control" min="2" max="50" value="{{ $val('lockout_max_attempts') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('super.security_settings.lockout_minutes') }}</label>
                                <select name="lockout_minutes" class="form-control">
                                    @foreach ($durations as $m)
                                        <option value="{{ $m }}" {{ (int) $val('lockout_minutes') === $m ? 'selected' : '' }}>{{ $durationLabel($m) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-group mb-4">
                            <label class="form-label">{{ __('super.security_settings.scope') }}</label>
                            <select name="lockout_scope" class="form-control">
                                @foreach (['email_ip', 'email', 'ip'] as $scope)
                                    <option value="{{ $scope }}" {{ $val('lockout_scope') === $scope ? 'selected' : '' }}>{{ __('super.security_settings.scope_'.$scope) }}</option>
                                @endforeach
                            </select>
                            <p class="form-hint">{{ __('super.security_settings.scope_hint') }}</p>
                        </div>

                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="lockout_show_remaining" value="1" class="form-check-input" {{ $checked('lockout_show_remaining') ? 'checked' : '' }}>
                            <span>{{ __('super.security_settings.show_remaining') }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-body-custom">
                    <button type="submit" class="btn btn-primary" @disabled(! $migrated)><i class="bi bi-check-lg"></i> {{ __('super.security_settings.save') }}</button>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-life-preserver me-2 text-muted"></i>{{ __('super.security_settings.recovery_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted fs-sm2">{{ __('super.security_settings.recovery_hint') }}</p>
                    <code class="d-block p-2 border rounded" style="white-space: pre-wrap">php artisan security:login-reset central</code>
                    <p class="text-muted fs-sm2 mt-3 mb-0">{{ __('super.security_settings.tenant_note') }}</p>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
@php
    $securityPageData = json_encode([
        'providers' => collect($providers)->map(fn ($p) => ['script' => $p['script']])->all(),
        'storedProvider' => $o['captcha_provider'],
        'storedSiteKey' => $o['captcha_site_key'],
        'storedEnabled' => $o['captcha_enabled'],
        'trans' => [
            'needSiteKey' => __('super.security_settings.test_need_site_key'),
            'loading' => __('super.security_settings.test_loading'),
            'passed' => __('super.security_settings.test_passed'),
            'loadFailed' => __('super.security_settings.test_load_failed'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<script>
(function () {
    var D = {!! $securityPageData !!};
    var $ = function (id) { return document.getElementById(id); };
    var enabled = $('captchaEnabled'), provider = $('captchaProvider'), mode = $('captchaMode');
    var lockout = $('lockoutEnabled'), token = $('captchaTestToken');
    var host = $('testWidgetHost'), status = $('testWidgetStatus');
    if (!enabled || !provider) return;

    function sync() {
        $('captchaFields').style.display = enabled.checked ? '' : 'none';
        $('externalFields').style.display = provider.value === 'builtin' ? 'none' : '';
        $('afterFailuresWrap').style.display = mode.value === 'after_failures' ? '' : 'none';
        $('lockoutFields').style.display = lockout.checked ? '' : 'none';
    }
    [enabled, provider, mode, lockout].forEach(function (el) { el.addEventListener('change', sync); });
    sync();

    // A key/provider change invalidates a token earned with the old keys.
    [provider, $('captchaSiteKey')].forEach(function (el) {
        el.addEventListener('input', function () { token.value = ''; host.innerHTML = ''; status.textContent = ''; });
        el.addEventListener('change', function () { token.value = ''; host.innerHTML = ''; status.textContent = ''; });
    });

    // reCAPTCHA, hCaptcha and Turnstile share the explicit-render API:
    // <global>.render(element, { sitekey, callback }).
    var globals = { recaptcha: 'grecaptcha', hcaptcha: 'hcaptcha', turnstile: 'turnstile' };
    var loaded = {};

    function withProvider(name, cb) {
        if (window[globals[name]] && window[globals[name]].render) return cb();
        var cbName = '__secCaptchaReady_' + name;
        window[cbName] = function () { cb(); };
        if (loaded[name]) return;
        loaded[name] = true;
        var s = document.createElement('script');
        s.src = D.providers[name].script + '?render=explicit&onload=' + cbName;
        s.async = true;
        s.onerror = function () { status.textContent = D.trans.loadFailed; loaded[name] = false; };
        document.head.appendChild(s);
    }

    $('loadTestWidget').addEventListener('click', function () {
        var name = provider.value, siteKey = $('captchaSiteKey').value.trim();
        token.value = '';
        host.innerHTML = '';
        if (!siteKey) { status.textContent = D.trans.needSiteKey; return; }
        status.textContent = D.trans.loading;
        withProvider(name, function () {
            var el = document.createElement('div');
            host.appendChild(el);
            try {
                window[globals[name]].render(el, {
                    sitekey: siteKey,
                    callback: function (t) { token.value = t; status.textContent = D.trans.passed; }
                });
                status.textContent = '';
            } catch (e) {
                status.textContent = D.trans.loadFailed;
            }
        });
    });
})();
</script>
@endpush
