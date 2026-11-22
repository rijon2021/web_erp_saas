{{--
    Custom domain setup guide — right-side drawer opened from the
    "Setup guide" button of the tenant page's Custom domain card.
    Commands are pre-filled with this install's real values (platform host,
    server IP, public folder) and this tenant's custom domain, so the
    platform owner can copy them as they are.
--}}
@php
    $g = 'super.custom_domains.guide.';
    $gDomain = optional($tenant->customDomains->sortByDesc('is_primary')->first())->domain ?: 'tenantshop.com';
    $gHost = $centralHost ?: 'yoursaas.com';
    // Only look up the real platform host — never the placeholder.
    $gIps = $centralHost ? (@gethostbynamel($centralHost) ?: []) : [];
    $gIp = $gIps ? implode(', ', array_unique($gIps)) : __($g . 'ip_unknown');
    $gPublic = str_replace('\\', '/', public_path());
@endphp

<div class="offcanvas offcanvas-end cd-guide" tabindex="-1" id="cdGuideDrawer" aria-labelledby="cdGuideTitle">
    <div class="offcanvas-header cd-guide-header">
        <div>
            <h5 class="offcanvas-title mb-1" id="cdGuideTitle"><i class="bi bi-signpost-split me-2"></i>{{ __($g . 'title') }}</h5>
            <div class="text-muted fs-xs2">{{ __($g . 'subtitle') }}</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{ __('super.common.close') }}"></button>
    </div>

    <div class="offcanvas-body cd-guide-body">
        {{-- How it works --}}
        <div class="cd-guide-callout cd-guide-callout-info">
            <div class="fw-700 mb-1"><i class="bi bi-info-circle me-1"></i>{{ __($g . 'how_title') }}</div>
            <div>{{ __($g . 'how_text') }}</div>
        </div>
        <div class="cd-guide-callout cd-guide-callout-warn">
            <div class="fw-700 mb-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ __($g . 'warn_title') }}</div>
            <div>{{ __($g . 'warn_text') }}</div>
        </div>

        {{-- Values used in the commands --}}
        <div class="cd-guide-section-title">{{ __($g . 'your_values') }}</div>
        <table class="cd-guide-values">
            <tr><th>{{ __($g . 'val_platform') }}</th><td><code>{{ $gHost }}</code></td></tr>
            <tr><th>{{ __($g . 'val_tenant') }}</th><td><code>{{ $gDomain }}</code><div class="text-muted fs-xs2 mt-1">{{ __($g . 'val_tenant_hint') }}</div></td></tr>
            <tr><th>{{ __($g . 'val_ip') }}</th><td><code>{{ $gIp }}</code></td></tr>
            <tr><th>{{ __($g . 'val_public') }}</th><td><code>{{ $gPublic }}</code></td></tr>
        </table>

        {{-- DNS (both hosting types) --}}
        <div class="cd-guide-section-title">{{ __($g . 'dns_title') }}</div>
        <p>{{ __($g . 'dns_text', ['domain' => $gDomain, 'ip' => $gIp, 'host' => $gHost]) }}</p>

        {{-- Hosting type tabs --}}
        <ul class="nav nav-pills cd-guide-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="cd-tab-vps" data-bs-toggle="pill" data-bs-target="#cd-pane-vps" type="button" role="tab" aria-controls="cd-pane-vps" aria-selected="true">
                    <i class="bi bi-hdd-rack me-1"></i>{{ __($g . 'tab_vps') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cd-tab-shared" data-bs-toggle="pill" data-bs-target="#cd-pane-shared" type="button" role="tab" aria-controls="cd-pane-shared" aria-selected="false">
                    <i class="bi bi-server me-1"></i>{{ __($g . 'tab_shared') }}
                </button>
            </li>
        </ul>

        <div class="tab-content">
            {{-- ───────────── VPS ───────────── --}}
            <div class="tab-pane fade show active" id="cd-pane-vps" role="tabpanel" aria-labelledby="cd-tab-vps" tabindex="0">
                <div class="cd-guide-step">
                    <div class="cd-guide-step-title">{{ __($g . 'vps_s1_title') }}</div>
                    <p>{{ __($g . 'vps_s1_text', ['host' => $gHost]) }}</p>
<div class="cd-guide-code"><button type="button" class="cd-guide-copy" data-cd-copy>{{ __($g . 'copy') }}</button><pre>server_name {{ $gHost }} *.{{ $gHost }} {{ $gDomain }} www.{{ $gDomain }};</pre></div>
                    <p class="cd-guide-note">{{ __($g . 'vps_s1_note') }}</p>
                </div>

                <div class="cd-guide-step">
                    <div class="cd-guide-step-title">{{ __($g . 'vps_s2_title') }}</div>
                    <p>{{ __($g . 'vps_s2_text') }}</p>
<div class="cd-guide-code"><button type="button" class="cd-guide-copy" data-cd-copy>{{ __($g . 'copy') }}</button><pre>sudo apt install -y certbot
sudo certbot certonly --webroot -w {{ $gPublic }} -d {{ $gDomain }} -d www.{{ $gDomain }} --deploy-hook "systemctl reload nginx"</pre></div>
                    <p class="cd-guide-note">{{ __($g . 'vps_s2_note', ['domain' => $gDomain]) }}</p>
                </div>

                <div class="cd-guide-step">
                    <div class="cd-guide-step-title">{{ __($g . 'vps_s3_title') }}</div>
                    <p>{{ __($g . 'vps_s3_text') }}</p>
<div class="cd-guide-code"><button type="button" class="cd-guide-copy" data-cd-copy>{{ __($g . 'copy') }}</button><pre>server_name {{ $gDomain }} www.{{ $gDomain }};
ssl_certificate     /etc/letsencrypt/live/{{ $gDomain }}/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/{{ $gDomain }}/privkey.pem;
if ($host = www.{{ $gDomain }}) { return 301 https://{{ $gDomain }}$request_uri; }</pre></div>
                </div>

                <div class="cd-guide-step">
                    <div class="cd-guide-step-title">{{ __($g . 'vps_s4_title') }}</div>
                    <p>{{ __($g . 'vps_s4_text', ['domain' => $gDomain]) }}</p>
                </div>

                <div class="cd-guide-section-title">{{ __($g . 'vps_other_title') }}</div>
                <p>{{ __($g . 'vps_other_nginx') }}</p>
                <p>{{ __($g . 'vps_other_apache') }}</p>
<div class="cd-guide-code"><button type="button" class="cd-guide-copy" data-cd-copy>{{ __($g . 'copy') }}</button><pre># 1) port 80 VirtualHost of the platform: add
ServerAlias {{ $gDomain }} www.{{ $gDomain }}

# 2) issue the certificate
sudo certbot certonly --webroot -w {{ $gPublic }} -d {{ $gDomain }} -d www.{{ $gDomain }} --deploy-hook "systemctl reload apache2"

# 3) new VirtualHost for the domain
&lt;VirtualHost *:443&gt;
    ServerName {{ $gDomain }}
    ServerAlias www.{{ $gDomain }}
    DocumentRoot {{ $gPublic }}
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/{{ $gDomain }}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/{{ $gDomain }}/privkey.pem
    &lt;Directory {{ $gPublic }}&gt;
        AllowOverride All
        Require all granted
    &lt;/Directory&gt;
&lt;/VirtualHost&gt;</pre></div>
            </div>

            {{-- ───────────── Shared hosting ───────────── --}}
            <div class="tab-pane fade" id="cd-pane-shared" role="tabpanel" aria-labelledby="cd-tab-shared" tabindex="0">
                <p>{{ __($g . 'shared_intro') }}</p>

                <div class="cd-guide-step">
                    <div class="cd-guide-step-title"><i class="bi bi-ui-checks-grid me-1"></i>{{ __($g . 'cpanel_title') }}</div>
                    <ol class="cd-guide-list">
                        <li>{{ __($g . 'cpanel_1', ['domain' => $gDomain, 'host' => $gHost]) }}</li>
                        <li>{{ __($g . 'cpanel_2') }}</li>
                    </ol>
                </div>

                <div class="cd-guide-step">
                    <div class="cd-guide-step-title"><i class="bi bi-ui-checks-grid me-1"></i>{{ __($g . 'hpanel_title') }}</div>
                    <ol class="cd-guide-list">
                        <li>{{ __($g . 'hpanel_1', ['domain' => $gDomain, 'host' => $gHost]) }}</li>
                        <li>{{ __($g . 'hpanel_2', ['domain' => $gDomain]) }}</li>
                    </ol>
                </div>

                <div class="cd-guide-step">
                    <div class="cd-guide-step-title"><i class="bi bi-ui-checks-grid me-1"></i>{{ __($g . 'plesk_title') }}</div>
                    <ol class="cd-guide-list">
                        <li>{{ __($g . 'plesk_1', ['domain' => $gDomain, 'host' => $gHost]) }}</li>
                        <li>{{ __($g . 'plesk_2') }}</li>
                    </ol>
                </div>

                <div class="cd-guide-callout cd-guide-callout-warn">
                    <div><i class="bi bi-exclamation-triangle me-1"></i>{{ __($g . 'shared_warn') }}</div>
                </div>
                <p class="cd-guide-note">{{ __($g . 'shared_limit') }}</p>
            </div>
        </div>

        {{-- Both hosting types --}}
        <div class="cd-guide-section-title">{{ __($g . 'checks_title') }}</div>
        <ul class="cd-guide-list">
            <li>{{ __($g . 'check_session', ['host' => $gHost]) }}</li>
            <li>{{ __($g . 'check_scheduler') }}</li>
        </ul>

        <div class="cd-guide-section-title">{{ __($g . 'trouble_title') }}</div>
        <table class="cd-guide-values cd-guide-trouble">
            @foreach(['403', 'cert', 'login', 'www', 'ssl'] as $t)
                <tr>
                    <th>{{ __($g . 't_' . $t, ['host' => $gHost]) }}</th>
                    <td>{{ __($g . 't_' . $t . '_fix') }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

@push('styles')
<style>
    /* above the floating customizer button (z-index 1080) */
    .cd-guide { --bs-offcanvas-width: min(680px, 100vw); --bs-offcanvas-zindex: 1085; z-index: 1085; }
    .cd-guide-header { border-bottom: 1px solid var(--color-border); align-items: flex-start; }
    .cd-guide-body { font-size: .86rem; line-height: 1.6; }
    .cd-guide-body p { margin-bottom: .6rem; }
    .cd-guide-section-title { font-weight: 700; font-size: .9rem; margin: 1.25rem 0 .5rem; }
    .cd-guide-callout { border-radius: var(--radius-sm); padding: .7rem .9rem; margin-bottom: .75rem; border-left: 3px solid; }
    .cd-guide-callout-info { background: #eff6ff; border-color: #3b82f6; }
    .cd-guide-callout-warn { background: #fffbeb; border-color: #f59e0b; }
    .cd-guide-values { width: 100%; border-collapse: collapse; font-size: .82rem; table-layout: fixed; }
    .cd-guide-values th, .cd-guide-values td { white-space: normal !important; overflow-wrap: anywhere; }
    @media (max-width: 575px) { .cd-guide-values th { width: 38%; font-size: .72rem; } }
    .cd-guide-values th, .cd-guide-values td { border: 1px solid var(--color-border); padding: .45rem .6rem; vertical-align: top; }
    .cd-guide-values th { width: 34%; font-weight: 600; background: var(--color-surface); }
    .cd-guide-values code { word-break: break-all; }
    .cd-guide-tabs { gap: .4rem; margin: 1rem 0 .75rem; }
    .cd-guide-tabs .nav-link { font-size: .82rem; padding: .4rem .8rem; border: 1px solid var(--color-border); color: inherit; }
    .cd-guide-tabs .nav-link.active { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
    .cd-guide-step { border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: .75rem .9rem; margin-bottom: .75rem; }
    .cd-guide-step-title { font-weight: 700; margin-bottom: .35rem; }
    .cd-guide-note { color: var(--color-muted); font-size: .8rem; }
    .cd-guide-list { padding-left: 1.2rem; margin-bottom: .4rem; }
    .cd-guide-list li { margin-bottom: .3rem; }
    .cd-guide-code { position: relative; margin-bottom: .6rem; }
    .cd-guide-code pre { background: #0f172a; color: #e2e8f0; border-radius: var(--radius-sm); padding: .7rem .9rem; padding-right: 4.5rem;
        font-size: .76rem; line-height: 1.55; white-space: pre-wrap; word-break: normal; overflow-wrap: anywhere; margin: 0; }
    .cd-guide-copy { position: absolute; top: .4rem; right: .4rem; font-size: .7rem; padding: .15rem .5rem; border-radius: 6px;
        border: 1px solid rgba(255,255,255,.2); background: rgba(255,255,255,.08); color: #e2e8f0; }
    .cd-guide-copy:hover { background: rgba(255,255,255,.18); }
    [dir="rtl"] .cd-guide-copy { right: auto; left: .4rem; }
    [dir="rtl"] .cd-guide-code pre { padding-right: .9rem; padding-left: 4.5rem; direction: ltr; text-align: left; }
    [dir="rtl"] .cd-guide-callout { border-left: 0; border-right: 3px solid; }
    [dir="rtl"] .cd-guide-list { padding-left: 0; padding-right: 1.2rem; }
    body.dark-mode .cd-guide { background: #1e293b; color: #e2e8f0; }
    body.dark-mode .cd-guide .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    body.dark-mode .cd-guide-header, body.dark-mode .cd-guide-values th, body.dark-mode .cd-guide-values td,
    body.dark-mode .cd-guide-step, body.dark-mode .cd-guide-tabs .nav-link { border-color: rgba(255,255,255,.08); }
    body.dark-mode .cd-guide-values th { background: rgba(255,255,255,.03); }
    body.dark-mode .cd-guide-callout-info { background: rgba(59,130,246,.12); }
    body.dark-mode .cd-guide-callout-warn { background: rgba(245,158,11,.12); }
</style>
@endpush

@push('scripts')
<script>
    // .main-body is its own stacking context; move the drawer to <body> so it
    // sits above the floating customizer button.
    (function () {
        var drawer = document.getElementById('cdGuideDrawer');
        if (drawer && drawer.parentElement !== document.body) document.body.appendChild(drawer);
    })();

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-cd-copy]');
        if (!btn) return;
        var pre = btn.parentElement.querySelector('pre');
        var label = btn.textContent;
        var done = function () {
            btn.textContent = @json(__($g . 'copied'));
            setTimeout(function () { btn.textContent = label; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(pre.innerText).then(done);
        } else {
            var ta = document.createElement('textarea');
            ta.value = pre.innerText; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (err) {}
            document.body.removeChild(ta);
        }
    });
</script>
@endpush
