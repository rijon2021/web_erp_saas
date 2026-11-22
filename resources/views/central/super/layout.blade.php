@php
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
    $logoUrl = $generalSettings->getLogoUrl();
    $faviconUrl = $generalSettings->getFaviconUrl();
    // Static files: ?v= is the file mtime, so browsers cache them until they change.
    $asset = [\App\Support\SuperAsset::class, 'url'];
    // Uploaded logo/favicon: versioned by the settings row, not by the clock.
    $assetVersion = $generalSettings->updated_at?->getTimestamp() ?: 1;
    $appVersion = is_file(base_path('version.txt')) ? trim(@file_get_contents(base_path('version.txt'))) : null;
    $isRtl = \App\Models\Central\CentralLanguage::isRtl(app()->getLocale());
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" @if($isRtl) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('super.topbar.super_admin')) — {{ $appName }}</title>
    <link rel="icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}?v={{ $assetVersion }}" type="image/x-icon">
    <link rel="shortcut icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}?v={{ $assetVersion }}">
    <link href="{{ $asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ $asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $asset('assets_super/css/central-shared.css') }}" rel="stylesheet">
    <link href="{{ $asset('assets_super/css/super-layout.css') }}" rel="stylesheet">
    <link href="{{ $asset('assets_super/css/super-customizer.css') }}" rel="stylesheet">
    @stack('styles')
    {{-- RTL overrides load last so they also win over per-page stylesheets --}}
    @if($isRtl)
        <link href="{{ $asset('assets_super/css/super-rtl.css') }}" rel="stylesheet">
    @endif
</head>
<body>
    <script>
        // Pre-apply saved theme + sidebar layout before paint to avoid a flash.
        (function () {
            try {
                if (localStorage.getItem('super_theme') === 'dark') document.body.classList.add('dark-mode');
                var layout = localStorage.getItem('super_sidebar_layout');
                if (['large', 'accordion', 'dark'].indexOf(layout) !== -1) document.body.classList.add('sb-' + layout);
            } catch (e) {}
        })();
    </script>
    <script>
        // Default primary colour from Settings > General > Appearance (null =
        // the built-in colour in super-layout.css). super-customizer.js resets to it.
        window.SuperDefaultColor = @json($generalSettings->superPrimaryColor());
        // Pre-apply saved primary color before paint to avoid color flash.
        // A colour picked in the customizer wins over the admin default.
        (function () {
            try {
                var saved = localStorage.getItem('super_primary_color');
                if (!saved || !/^#([0-9a-f]{6})$/i.test(saved)) saved = window.SuperDefaultColor;
                if (!saved || !/^#([0-9a-f]{6})$/i.test(saved)) return;
                var hex = saved.replace('#', '');
                var r = parseInt(hex.substring(0, 2), 16);
                var g = parseInt(hex.substring(2, 4), 16);
                var b = parseInt(hex.substring(4, 6), 16);
                function clamp(v){return Math.max(0,Math.min(255,Math.round(v)));}
                function darken(c, amt){return clamp(c*(1-amt));}
                var dr = darken(r, 0.15), dg = darken(g, 0.15), db = darken(b, 0.15);
                var darkHex = '#' + [dr, dg, db].map(function(v){var s=v.toString(16);return s.length===1?'0'+s:s;}).join('');
                var style = document.createElement('style');
                style.id = 'super-customizer-vars';
                style.textContent = ':root{'
                    + '--color-primary:' + saved + ';'
                    + '--color-primary-dark:' + darkHex + ';'
                    + '--color-primary-light:rgba(' + r + ',' + g + ',' + b + ',0.10);'
                    + '--color-primary-lighter:rgba(' + r + ',' + g + ',' + b + ',0.05);'
                    + '--bs-link-color-rgb:' + r + ',' + g + ',' + b + ';'
                    + '}'
                    + 'body.dark-mode{'
                    + '--color-primary:' + saved + ';'
                    + '--color-primary-dark:' + darkHex + ';'
                    + '--color-primary-light:rgba(' + r + ',' + g + ',' + b + ',0.14);'
                    + '--color-primary-lighter:rgba(' + r + ',' + g + ',' + b + ',0.07);'
                    + '--bs-link-color-rgb:' + r + ',' + g + ',' + b + ';'
                    + '}';
                document.documentElement.appendChild(style);
            } catch (e) {}
        })();
    </script>
    <div class="page-loader" id="pageLoader">
        <div class="loader-dots">
            <span></span><span></span><span></span>
        </div>
    </div>

    <div class="app-wrap">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <aside class="sidebar" id="sidebar">
            <a class="sidebar-brand" href="{{ route('super.dashboard') }}">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}?v={{ $assetVersion }}" alt="{{ $appName }}" class="brand-logo">
                @else
                    <span class="brand-icon">{{ strtoupper(substr($appName, 0, 1)) }}</span>
                @endif
                @if($generalSettings->show_site_name ?? true)
                    <span class="brand-text">{{ $appName }}</span>
                @endif
            </a>
            @php $centralUser = Auth::guard('central')->user(); @endphp
            <nav class="sidebar-nav">
                <div class="sidebar-section-label" data-icon="bi-grid-1x2-fill">{{ __('super.sidebar.main') }}</div>
                @if($centralUser->hasPermission('dashboard'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.dashboard') ? 'active' : '' }}" href="{{ route('super.dashboard') }}" data-title="{{ __('super.sidebar.dashboard') }}">
                        <i class="bi bi-grid-1x2-fill"></i>
                        <span>{{ __('super.sidebar.dashboard') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasAnyPermission(['tenants', 'plans', 'subscriptions', 'reminders', 'admins']))
                <div class="sidebar-section-label" data-icon="bi-briefcase-fill">{{ __('super.sidebar.management') }}</div>
                @if($centralUser->hasPermission('tenants'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.tenants.*') ? 'active' : '' }}" href="{{ route('super.tenants.index') }}" data-title="{{ __('super.sidebar.tenants') }}">
                        <i class="bi bi-building"></i>
                        <span>{{ __('super.sidebar.tenants') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('plans'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.plans.*') ? 'active' : '' }}" href="{{ route('super.plans.index') }}" data-title="{{ __('super.sidebar.plans') }}">
                        <i class="bi bi-tag-fill"></i>
                        <span>{{ __('super.sidebar.plans') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('addons'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.addons.*') ? 'active' : '' }}" href="{{ route('super.addons.index') }}" data-title="{{ __('super.sidebar.addons') }}">
                        <i class="bi bi-puzzle-fill"></i>
                        <span>{{ __('super.sidebar.addons') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('subscriptions'))
                <div class="nav-item">
                    <a class="nav-link {{ (request()->routeIs('super.subscriptions.*') && ! request()->routeIs('super.subscriptions.reminders')) ? 'active' : '' }}" href="{{ route('super.subscriptions.index') }}" data-title="{{ __('super.sidebar.subscriptions') }}">
                        <i class="bi bi-credit-card-2-front"></i>
                        <span>{{ __('super.sidebar.subscriptions') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('reminders'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.subscriptions.reminders') ? 'active' : '' }}" href="{{ route('super.subscriptions.reminders') }}" data-title="{{ __('super.sidebar.reminders') }}">
                        <i class="bi bi-alarm"></i>
                        <span>{{ __('super.sidebar.reminders') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('admins'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.admins.*') ? 'active' : '' }}" href="{{ route('super.admins.index') }}" data-title="{{ __('super.sidebar.admins') }}">
                        <i class="bi bi-person-lock"></i>
                        <span>{{ __('super.sidebar.admins') }}</span>
                    </a>
                </div>
                @endif
                @endif

                @if($centralUser->hasPermission('payments'))
                <div class="sidebar-section-label" data-icon="bi-credit-card-fill">{{ __('super.sidebar.payments') }}</div>
                @if($centralUser->hasPermission('payments_overview'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.dashboard') ? 'active' : '' }}" href="{{ route('super.payments.dashboard') }}" data-title="{{ __('super.sidebar.overview') }}">
                        <i class="bi bi-bar-chart-line"></i>
                        <span>{{ __('super.sidebar.overview') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('payments_transactions'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.index') || request()->routeIs('super.payments.show') ? 'active' : '' }}" href="{{ route('super.payments.index') }}" data-title="{{ __('super.sidebar.transactions') }}">
                        <i class="bi bi-receipt"></i>
                        <span>{{ __('super.sidebar.transactions') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('payments_links'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payment-links.*') ? 'active' : '' }}" href="{{ route('super.payment-links.index') }}" data-title="{{ __('super.sidebar.payment_links') }}">
                        <i class="bi bi-link-45deg"></i>
                        <span>{{ __('super.sidebar.payment_links') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('payments_pending'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.pending-registrations*') ? 'active' : '' }}" href="{{ route('super.payments.pending-registrations') }}" data-title="{{ __('super.sidebar.pending_payments') }}">
                        <i class="bi bi-hourglass-split"></i>
                        <span>{{ __('super.sidebar.pending_payments') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('payments_invoices'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.invoices') ? 'active' : '' }}" href="{{ route('super.payments.invoices') }}" data-title="{{ __('super.sidebar.invoices') }}">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>{{ __('super.sidebar.invoices') }}</span>
                    </a>
                </div>
                @endif
                @endif

                @if($centralUser->hasPermission('support'))
                @php $supportUnread = \App\Models\Central\SupportTicket::where('admin_unread', true)->count(); @endphp
                <div class="sidebar-section-label" data-icon="bi-life-preserver">{{ __('super.sidebar.support') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.support.*') ? 'active' : '' }}" href="{{ route('super.support.index') }}" data-title="{{ __('super.sidebar.support_tickets') }}">
                        <i class="bi bi-life-preserver"></i>
                        <span>{{ __('super.sidebar.support_tickets') }}</span>
                        @if($supportUnread > 0)
                            <span class="badge badge-no ms-auto">{{ $supportUnread }}</span>
                        @endif
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('reports'))
                <div class="sidebar-section-label" data-icon="bi-bar-chart-fill">{{ __('super.sidebar.reports') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.reports.*') ? 'active' : '' }}" href="{{ route('super.reports.index') }}" data-title="{{ __('super.sidebar.reports') }}">
                        <i class="bi bi-bar-chart-fill"></i>
                        <span>{{ __('super.sidebar.reports') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('cms'))
                <div class="sidebar-section-label" data-icon="bi-window-stack">{{ __('super.sidebar.cms') }}</div>
                @if($centralUser->hasPermission('cms_landing'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.index') ? 'active' : '' }}" href="{{ route('super.cms.index') }}" data-title="{{ __('super.sidebar.landing_page') }}">
                        <i class="bi bi-window-stack"></i>
                        <span>{{ __('super.sidebar.landing_page') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_landing_pages'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.landing-pages*') ? 'active' : '' }}" href="{{ route('super.cms.landing-pages') }}" data-title="{{ __('super.sidebar.landing_pages') }}">
                        <i class="bi bi-layout-wtf"></i>
                        <span>{{ __('super.sidebar.landing_pages') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_hero'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.hero*') ? 'active' : '' }}" href="{{ route('super.cms.hero') }}" data-title="{{ __('super.sidebar.hero_section') }}">
                        <i class="bi bi-stars"></i>
                        <span>{{ __('super.sidebar.hero_section') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_features'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.features*') ? 'active' : '' }}" href="{{ route('super.cms.features') }}" data-title="{{ __('super.sidebar.features') }}">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <span>{{ __('super.sidebar.features') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_pricing'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.pricing*') ? 'active' : '' }}" href="{{ route('super.cms.pricing') }}" data-title="{{ __('super.sidebar.pricing_section') }}">
                        <i class="bi bi-currency-dollar"></i>
                        <span>{{ __('super.sidebar.pricing_section') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_stats'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.stats*') ? 'active' : '' }}" href="{{ route('super.cms.stats') }}" data-title="{{ __('super.sidebar.stats') }}">
                        <i class="bi bi-bar-chart"></i>
                        <span>{{ __('super.sidebar.stats') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_how_it_works'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.how-it-works*') ? 'active' : '' }}" href="{{ route('super.cms.how-it-works') }}" data-title="{{ __('super.sidebar.how_it_works') }}">
                        <i class="bi bi-diagram-3"></i>
                        <span>{{ __('super.sidebar.how_it_works') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_testimonials'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.testimonials*') ? 'active' : '' }}" href="{{ route('super.cms.testimonials') }}" data-title="{{ __('super.sidebar.testimonials') }}">
                        <i class="bi bi-chat-quote"></i>
                        <span>{{ __('super.sidebar.testimonials') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_faq'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.faq*') ? 'active' : '' }}" href="{{ route('super.cms.faqs') }}" data-title="{{ __('super.sidebar.faq') }}">
                        <i class="bi bi-question-circle"></i>
                        <span>{{ __('super.sidebar.faq') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_cta'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.cta*') ? 'active' : '' }}" href="{{ route('super.cms.cta') }}" data-title="{{ __('super.sidebar.cta_section') }}">
                        <i class="bi bi-megaphone"></i>
                        <span>{{ __('super.sidebar.cta_section') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_footer'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.footer*') ? 'active' : '' }}" href="{{ route('super.cms.footer') }}" data-title="{{ __('super.sidebar.footer') }}">
                        <i class="bi bi-layout-text-window-reverse"></i>
                        <span>{{ __('super.sidebar.footer') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_seo'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.seo*') ? 'active' : '' }}" href="{{ route('super.cms.seo') }}" data-title="{{ __('super.sidebar.seo_settings') }}">
                        <i class="bi bi-search"></i>
                        <span>{{ __('super.sidebar.seo_settings') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_privacy'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.privacy-policy*') ? 'active' : '' }}" href="{{ route('super.cms.privacy-policy') }}" data-title="{{ __('super.sidebar.privacy_policy') }}">
                        <i class="bi bi-shield-check"></i>
                        <span>{{ __('super.sidebar.privacy_policy') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_terms'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.terms-conditions*') ? 'active' : '' }}" href="{{ route('super.cms.terms-conditions') }}" data-title="{{ __('super.sidebar.terms') }}">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>{{ __('super.sidebar.terms') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('cms_translations'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.translations*') ? 'active' : '' }}" href="{{ route('super.cms.translations') }}" data-title="{{ __('super.sidebar.translations') }}">
                        <i class="bi bi-translate"></i>
                        <span>{{ __('super.sidebar.translations') }}</span>
                    </a>
                </div>
                @endif
                @endif

                @if($centralUser->hasPermission('knowledge_base'))
                <div class="sidebar-section-label" data-icon="bi-book-fill">{{ __('super.sidebar.knowledge_base') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.knowledge-base.*') ? 'active' : '' }}" href="{{ route('super.knowledge-base.index') }}" data-title="{{ __('super.sidebar.knowledge_base') }}">
                        <i class="bi bi-book"></i>
                        <span>{{ __('super.sidebar.knowledge_base') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('blog'))
                <div class="sidebar-section-label" data-icon="bi-journal-text">{{ __('super.sidebar.blog') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.blog.*') ? 'active' : '' }}" href="{{ route('super.blog.index') }}" data-title="{{ __('super.sidebar.blog') }}">
                        <i class="bi bi-journal-text"></i>
                        <span>{{ __('super.sidebar.blog') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('how_it_works'))
                <div class="sidebar-section-label" data-icon="bi-question-circle-fill">{{ __('super.sidebar.help') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.how-it-works') ? 'active' : '' }}" href="{{ route('super.how-it-works') }}" data-title="{{ __('super.sidebar.how_it_works') }}">
                        <i class="bi bi-diagram-3"></i>
                        <span>{{ __('super.sidebar.how_it_works') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasAnyPermission(['logs_health', 'impersonation_logs']))
                <div class="sidebar-section-label" data-icon="bi-activity">{{ __('super.sidebar.monitoring') }}</div>
                @if($centralUser->hasPermission('logs_health'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.logs-health.*') ? 'active' : '' }}" href="{{ route('super.logs-health.index') }}" data-title="{{ __('super.sidebar.logs_health') }}">
                        <i class="bi bi-activity"></i>
                        <span>{{ __('super.sidebar.logs_health') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('impersonation_logs'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.impersonation-logs.*') ? 'active' : '' }}" href="{{ route('super.impersonation-logs.index') }}" data-title="{{ __('super.sidebar.impersonation_logs') }}">
                        <i class="bi bi-incognito"></i>
                        <span>{{ __('super.sidebar.impersonation_logs') }}</span>
                    </a>
                </div>
                @endif
                @endif

                @if($centralUser->hasAnyPermission(['settings', 'languages', 'email_templates', 'sms_templates', 'whatsapp', 'system_update', 'license']))
                <div class="sidebar-section-label" data-icon="bi-gear-fill">{{ __('super.sidebar.settings') }}</div>
                @if($centralUser->hasPermission('settings'))
                @if($centralUser->hasPermission('settings_general'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.general*') ? 'active' : '' }}" href="{{ route('super.settings.general') }}" data-title="{{ __('super.sidebar.general_settings') }}">
                        <i class="bi bi-sliders"></i>
                        <span>{{ __('super.sidebar.general_settings') }}</span>
                    </a>
                </div>
                @endif
                @endif
                @if($centralUser->hasPermission('languages'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.languages.*') ? 'active' : '' }}" href="{{ route('super.languages.index') }}" data-title="{{ __('super.languages.title') }}">
                        <i class="bi bi-globe2"></i>
                        <span>{{ __('super.languages.title') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('email_templates'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.email-templates*') ? 'active' : '' }}" href="{{ route('super.email-templates.index') }}" data-title="{{ __('super.sidebar.email_templates') }}">
                        <i class="bi bi-envelope-paper"></i>
                        <span>{{ __('super.sidebar.email_templates') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('sms_templates'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.sms-templates*') ? 'active' : '' }}" href="{{ route('super.sms-templates.index') }}" data-title="{{ __('super.sidebar.sms_templates') }}">
                        <i class="bi bi-chat-left-text"></i>
                        <span>{{ __('super.sidebar.sms_templates') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('settings'))
                @if($centralUser->hasPermission('settings_mail'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.mail*') ? 'active' : '' }}" href="{{ route('super.settings.mail') }}" data-title="{{ __('super.sidebar.mail_settings') }}">
                        <i class="bi bi-mailbox"></i>
                        <span>{{ __('super.sidebar.mail_settings') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('settings_sms'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.sms*') ? 'active' : '' }}" href="{{ route('super.settings.sms') }}" data-title="{{ __('super.sidebar.sms_settings') }}">
                        <i class="bi bi-chat-left-dots"></i>
                        <span>{{ __('super.sidebar.sms_settings') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('settings_payment_gateways'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.payment-gateways*') ? 'active' : '' }}" href="{{ route('super.settings.payment-gateways') }}" data-title="{{ __('super.sidebar.payment_gateways') }}">
                        <i class="bi bi-gear"></i>
                        <span>{{ __('super.sidebar.payment_gateways') }}</span>
                    </a>
                </div>
                @endif
                @endif
                @if($centralUser->hasPermission('whatsapp'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.whatsapp*') ? 'active' : '' }}" href="{{ route('super.settings.whatsapp') }}" data-title="{{ __('super.sidebar.whatsapp_settings') }}">
                        <i class="bi bi-whatsapp"></i>
                        <span>{{ __('super.sidebar.whatsapp_settings') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('system_update'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.system-update.*') ? 'active' : '' }}" href="{{ route('super.system-update.index') }}" data-title="{{ __('super.sidebar.system_update') }}">
                        <i class="bi bi-cloud-arrow-down"></i>
                        <span>{{ __('super.sidebar.system_update') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('license'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.license*') ? 'active' : '' }}" href="{{ route('super.settings.license') }}" data-title="{{ __('super.sidebar.license') }}">
                        <i class="bi bi-key-fill"></i>
                        <span>{{ __('super.sidebar.license') }}</span>
                    </a>
                </div>
                @endif
                @endif
            </nav>
            <div class="sidebar-footer-actions">
                <a href="{{ route('super.profile') }}" class="sidebar-user" title="{{ $centralUser->name }}">
                    <span class="sidebar-user-avatar">
                        @if($centralUser->avatar)
                            <img src="{{ asset($centralUser->avatar) }}" alt="">
                        @else
                            {{ strtoupper(mb_substr($centralUser->name ?? 'A', 0, 1)) }}
                        @endif
                    </span>
                    <span class="sidebar-user-text">
                        <span class="sidebar-user-name d-block">{{ $centralUser->name }}</span>
                        <span class="sidebar-user-email d-block">{{ $centralUser->email }}</span>
                    </span>
                </a>
                <form method="POST" action="{{ route('central.logout') }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn-logout-icon" aria-label="{{ __('super.sidebar.log_out') }}" title="{{ __('super.sidebar.log_out') }}">
                        <i class="bi bi-box-arrow-left"></i>
                    </button>
                </form>
            </div>
        </aside>

        <div class="main-content">
            <script>
                // Builds the rail-layout structure before first paint: wraps each
                // section label + its items in a .nav-group and adds one .rail-item
                // per group that has links. Runs inline (parser-blocking) so the
                // Large/Compact layouts never paint an empty rail while
                // super-layout.js (which wires hover/pin/flyout behaviour) loads.
                (function () {
                    var sidebar = document.getElementById('sidebar');
                    var nav = sidebar && sidebar.querySelector('.sidebar-nav');
                    if (!nav) return;
                    var children = Array.prototype.slice.call(nav.children);
                    var group = null, i;
                    for (i = 0; i < children.length; i++) {
                        if (children[i].classList.contains('sidebar-section-label')) {
                            group = document.createElement('div');
                            group.className = 'nav-group';
                            nav.insertBefore(group, children[i]);
                            group.appendChild(children[i]);
                        } else if (group) {
                            group.appendChild(children[i]);
                        }
                    }
                    var rail = document.createElement('div');
                    rail.className = 'sidebar-rail';
                    var groups = nav.querySelectorAll('.nav-group');
                    for (i = 0; i < groups.length; i++) {
                        var g = groups[i];
                        var label = g.querySelector('.sidebar-section-label');
                        var links = g.querySelectorAll('.nav-link');
                        g.setAttribute('data-group', i);
                        if (!links.length) continue;
                        var title = label.textContent.trim();
                        var item = document.createElement('a');
                        item.className = 'rail-item' + (g.querySelector('.nav-link.active') ? ' is-active' : '');
                        item.setAttribute('data-group', i);
                        item.setAttribute('aria-label', title);
                        if (links.length === 1) {
                            item.href = links[0].getAttribute('href');
                        } else {
                            item.href = '#';
                            item.setAttribute('role', 'button');
                            item.setAttribute('aria-haspopup', 'true');
                            item.setAttribute('aria-expanded', 'false');
                        }
                        var icon = document.createElement('i');
                        icon.className = 'bi ' + (label.getAttribute('data-icon') || 'bi-circle');
                        var text = document.createElement('span');
                        text.textContent = title;
                        item.appendChild(icon);
                        item.appendChild(text);
                        rail.appendChild(item);
                    }
                    sidebar.insertBefore(rail, nav);
                })();
            </script>

            <header class="topbar">
                <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="{{ __('super.topbar.toggle_sidebar') }}">
                    <i class="bi bi-layout-sidebar-inset"></i>
                </button>
                <div class="topbar-search" id="topbarSearch" data-url="{{ route('super.search') }}">
                    <label class="topbar-search-box" for="topbarSearchInput">
                        <i class="bi bi-search"></i>
                        <input type="search" id="topbarSearchInput" autocomplete="off" spellcheck="false" placeholder="{{ __('super.search.placeholder') }}" aria-label="{{ __('super.search.placeholder') }}" role="combobox" aria-expanded="false" aria-controls="topbarSearchResults" aria-autocomplete="list">
                        <kbd>Ctrl K</kbd>
                    </label>
                    <div class="topbar-search-results" id="topbarSearchResults" role="listbox"></div>
                </div>
                <div class="topbar-right ms-auto">
                    {{-- Language switcher --}}
                    @php
                        $currentLocale = app()->getLocale();
                        $allCentralLanguages = \App\Models\Central\CentralLanguage::orderBy('sort_order')->get();
                        $switcherLanguages = $allCentralLanguages->where('is_active', true)->values();
                        $currentLanguage = $switcherLanguages->firstWhere('locale', $currentLocale);
                        // Catalog languages not installed yet — teased at the bottom for admins who can install them.
                        $canManageLanguages = $centralUser?->hasPermission('languages') ?? false;
                        $notInstalledLanguages = $canManageLanguages
                            ? array_diff_key(\App\Support\CentralLanguageCatalog::ALL, $allCentralLanguages->keyBy('locale')->all())
                            : [];
                    @endphp
                    <div class="dropdown lang-switcher">
                        <button type="button" class="lang-switcher-btn" data-bs-toggle="dropdown" data-bs-offset="0,10" aria-expanded="false" aria-label="{{ __('super.topbar.language') }}">
                            <span class="lang-flag-icon">@if($currentLanguage?->flag)<img src="{{ asset('flags/' . $currentLanguage->flag) }}" alt="">@endif</span>
                            <span class="lang-code">{{ strtoupper($currentLocale) }}</span>
                            <i class="bi bi-chevron-down lang-chevron"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end lang-dropdown">
                            <div class="lang-dd-head">
                                <div class="lang-dd-head-text">
                                    <div class="lang-dd-title"><i class="bi bi-translate"></i>{{ __('landing.language') }}</div>
                                    <div class="lang-dd-sub">{{ __('super.languages.switcher_sub') }}</div>
                                </div>
                                <span class="lang-dd-count">{{ $switcherLanguages->count() }}</span>
                            </div>

                            @if($switcherLanguages->count() > 6)
                            <div class="lang-dd-search">
                                <i class="bi bi-search"></i>
                                <input type="text" class="js-lang-dd-search" placeholder="{{ __('super.languages.switcher_search') }}" autocomplete="off" aria-label="{{ __('super.languages.switcher_search') }}">
                            </div>
                            @endif

                            <div class="lang-dd-list">
                                @foreach($switcherLanguages as $lang)
                                @php $code = $lang->locale; $isCurrent = $currentLocale === $code; @endphp
                                <form method="POST" action="{{ route('super.locale', $code) }}" class="mb-0 js-lang-dd-item" data-search="{{ mb_strtolower($lang->display_name . ' ' . $lang->name . ' ' . $code) }}">
                                    @csrf
                                    <button type="submit" class="lang-option {{ $isCurrent ? 'active' : '' }}" @if($isCurrent) aria-current="true" @endif>
                                        <span class="lang-option-flag">@if($lang->flag)<img src="{{ asset('flags/' . $lang->flag) }}" alt="">@endif</span>
                                        <span class="lang-option-text">
                                            <span class="lang-option-label">{{ $lang->display_name }}</span>
                                            @if($lang->display_name !== $lang->name)
                                                <span class="lang-option-sub">{{ $lang->name }}</span>
                                            @endif
                                        </span>
                                        @if($isCurrent)
                                            <span class="lang-option-check"><i class="bi bi-check-lg"></i></span>
                                        @else
                                            <span class="lang-option-code">{{ strtoupper($code) }}</span>
                                        @endif
                                    </button>
                                </form>
                                @endforeach
                                <div class="lang-dd-empty js-lang-dd-empty" hidden>{{ __('super.languages.switcher_empty') }}</div>
                            </div>

                            @if($canManageLanguages)
                                @if(count($notInstalledLanguages))
                                <a href="{{ route('super.languages.index', ['tab' => 'available']) }}" class="lang-dd-footer">
                                    <span class="lang-dd-flags" aria-hidden="true">
                                        @foreach(array_slice($notInstalledLanguages, 0, 3) as $entry)
                                            <img src="{{ asset('flags/' . $entry['flag']) }}" alt="">
                                        @endforeach
                                    </span>
                                    <span class="lang-dd-footer-text">
                                        <strong>{{ trans_choice('super.languages.more_available', count($notInstalledLanguages), ['count' => count($notInstalledLanguages)]) }}</strong>
                                        <small>{{ __('super.languages.more_available_hint') }}</small>
                                    </span>
                                    <i class="bi bi-arrow-right lang-dd-arrow"></i>
                                </a>
                                @else
                                <a href="{{ route('super.languages.index') }}" class="lang-dd-footer lang-dd-footer-plain">
                                    <span class="lang-dd-footer-text"><strong>{{ __('super.languages.manage') }}</strong></span>
                                    <i class="bi bi-arrow-right lang-dd-arrow"></i>
                                </a>
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Dark mode toggle --}}
                    <button type="button" class="topbar-icon-btn" id="themeToggle" aria-label="{{ __('super.topbar.toggle_dark_mode') }}">
                        <i class="bi bi-moon-stars-fill"></i>
                        <i class="bi bi-sun-fill"></i>
                    </button>

                    <div class="dropdown">
                        <button type="button" class="topbar-icon-btn" id="quickActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('super.topbar.quick_actions') }}">
                            <i class="bi bi-lightning-charge-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-header">{{ __('super.topbar.quick_actions') }}</span></li>
                            <li><a class="dropdown-item" href="{{ route('super.tenants.index') }}"><i class="bi bi-building"></i> {{ __('super.topbar.tenants') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.plans.create') }}"><i class="bi bi-plus-lg"></i> {{ __('super.topbar.new_plan') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.subscriptions.index') }}"><i class="bi bi-credit-card"></i> {{ __('super.topbar.subscriptions') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.cms.index') }}"><i class="bi bi-window-stack"></i> {{ __('super.topbar.cms_landing') }}</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{ route('central.welcome') }}" target="_blank"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.topbar.landing_page') }}</a></li>
                        </ul>
                    </div>
                    @php
                        $__navPendingTenants = isset($pendingTenants) ? $pendingTenants : \App\Tenant::with('domains')->where('status', \App\Tenant::STATUS_PENDING)->orderByDesc('created_at')->get();
                        $__navPendingOffline = \App\Models\Central\PendingRegistration::where('gateway', 'offline')
                            ->where('status', \App\Models\Central\PendingRegistration::STATUS_PROCESSING)
                            ->with('plan')
                            ->orderByDesc('created_at')
                            ->get();
                        $__navPendingBilling = \App\Models\Central\TenantBillingPayment::whereIn('gateway', ['offline', 'manual'])
                            ->where('status', \App\Models\Central\TenantBillingPayment::STATUS_PENDING)
                            ->with(['tenant', 'plan'])
                            ->orderByDesc('created_at')
                            ->get();
                        $__navHasNotifications = $__navPendingTenants->count() > 0 || $__navPendingOffline->count() > 0 || $__navPendingBilling->count() > 0;
                    @endphp
                    <div class="dropdown">
                        <button type="button" class="topbar-icon-btn" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('super.topbar.notifications') }}">
                            <i class="bi bi-bell"></i>
                            @if($__navHasNotifications)
                                <span class="badge-dot"></span>
                            @endif
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notifications-dropdown">
                            <li><span class="dropdown-header">{{ __('super.topbar.notifications') }}</span></li>

                            {{-- Pending offline payments --}}
                            @foreach($__navPendingOffline as $reg)
                                <li>
                                    <a class="notification-item d-block text-decoration-none text-dark" href="{{ route('super.payments.pending-registrations') }}">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge badge-bank-transfer">{{ __('super.topbar.bank_transfer') }}</span>
                                            <strong class="text-truncate notification-name-truncate">{{ $reg->company_name }}</strong>
                                        </div>
                                        <div class="small mt-1">{{ __('super.topbar.offline_proof_pending') }}</div>
                                        <div class="small text-muted">{{ $reg->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @endforeach

                            {{-- Pending billing offline payments (tenant upgrades/renewals) --}}
                            @foreach($__navPendingBilling as $billingPayment)
                                <li>
                                    <a class="notification-item d-block text-decoration-none text-dark" href="{{ route('super.payments.index', ['status' => 'pending', 'gateway' => $billingPayment->gateway]) }}">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge badge-bank-transfer">{{ __('super.topbar.bank_transfer') }}</span>
                                            <strong class="text-truncate notification-name-truncate">{{ $billingPayment->tenant->company_name ?? $billingPayment->tenant_id }}</strong>
                                        </div>
                                        <div class="small mt-1">{{ __('super.topbar.billing_proof_pending') }} — {{ $billingPayment->plan->name ?? __('super.common.not_available') }} ({{ Lang::has('super.plans.' . $billingPayment->billing_cycle) ? __('super.plans.' . $billingPayment->billing_cycle) : ucfirst($billingPayment->billing_cycle) }})</div>
                                        <div class="small text-muted">{{ $billingPayment->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @endforeach

                            {{-- Pending tenants --}}
                            @foreach($__navPendingTenants as $tenant)
                                <li>
                                    <a class="notification-item d-block text-decoration-none text-dark" href="{{ route('super.tenants.index', ['status' => 'pending']) }}">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-warning text-dark badge-pending">{{ __('super.topbar.pending') }}</span>
                                            <strong class="text-truncate notification-name-truncate">{{ $tenant->company_name ?? $tenant->id }}</strong>
                                        </div>
                                        <div class="small mt-1">{{ __('super.topbar.pending_tenant_msg') }}</div>
                                        <div class="small text-muted">{{ $tenant->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @endforeach

                            @if(!$__navHasNotifications)
                                <li>
                                    <div class="notification-item">
                                        <span>{{ __('super.topbar.no_notifications') }}</span>
                                        <div class="small mt-1">{{ __('super.topbar.all_caught_up') }}</div>
                                    </div>
                                </li>
                            @endif

                            @if($__navPendingOffline->count() || $__navPendingBilling->count())
                                <li><hr class="dropdown-divider"></li>
                                @if($__navPendingOffline->count())
                                <li>
                                    <a class="dropdown-item text-center small" href="{{ route('super.payments.pending-registrations') }}">
                                        {{ trans_choice('super.topbar.view_pending_registrations', $__navPendingOffline->count(), ['count' => $__navPendingOffline->count()]) }}
                                    </a>
                                </li>
                                @endif
                                @if($__navPendingBilling->count())
                                <li>
                                    <a class="dropdown-item text-center small" href="{{ route('super.payments.index', ['status' => 'pending', 'gateway' => 'offline']) }}">
                                        {{ trans_choice('super.topbar.view_pending_billing', $__navPendingBilling->count(), ['count' => $__navPendingBilling->count()]) }}
                                    </a>
                                </li>
                                @endif
                            @endif
                            @if($__navPendingTenants->count())
                                <li>@if(!$__navPendingOffline->count())<hr class="dropdown-divider">@endif</li>
                                <li>
                                    <a class="dropdown-item text-center small" href="{{ route('super.tenants.index', ['status' => 'pending']) }}">
                                        {{ __('super.topbar.view_all_pending') }}
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </div>
                    @php $user = auth()->guard('central')->user(); @endphp
                    <div class="dropdown">
                        <button type="button" class="topbar-profile-btn" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('super.topbar.profile_menu') }}">
                            @if($user && $user->avatar)
                                <img src="{{ asset($user->avatar) }}" alt="" class="avatar-img-round">
                            @else
                                <span class="avatar">{{ $user ? strtoupper(mb_substr($user->name ?? 'A', 0, 1)) : 'A' }}</span>
                            @endif
                            <span class="name d-none d-md-inline">{{ $user->name ?? __('super.topbar.super_admin') }}</span>
                            <i class="bi bi-chevron-down text-muted small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <div class="px-3 py-2">
                                    <p class="mb-0 fw-600">{{ $user->name ?? __('super.topbar.super_admin') }}</p>
                                    <p class="mb-0 small text-muted">{{ $user->email ?? '' }}</p>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{ route('super.dashboard') }}"><i class="bi bi-grid-1x2"></i> {{ __('super.topbar.dashboard') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.profile') }}"><i class="bi bi-person"></i> {{ __('super.topbar.my_profile') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('central.welcome') }}" target="_blank"><i class="bi bi-house"></i> {{ __('super.topbar.home') }}</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('central.logout') }}" class="mb-0">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger w-100 border-0 bg-transparent"><i class="bi bi-box-arrow-left"></i> {{ __('super.topbar.log_out') }}</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            <main class="main-body">
                {{-- UI Lib licence: persistent notice for admins who can act on it
                     while the platform is not licensed. Informational only. --}}
                @php
                    // Shown to super admins only; tenants never hear about the
                    // licence (they get a neutral page once the platform locks).
                    try {
                        $licenseService = app(\App\Services\Licensing\LicenseService::class);
                        $licenseLock = null;
                        if (! request()->is('super/settings/license*')) {
                            $licenseLock = $licenseService->lockState();
                        }
                    } catch (\Throwable $e) { $licenseLock = null; }
                @endphp
                @if($licenseLock && $licenseLock['state'] !== \App\Services\Licensing\LicenseService::LOCK_OPEN)
                    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span><i class="bi bi-key-fill me-1"></i>
                            @if($licenseLock['state'] === \App\Services\Licensing\LicenseService::LOCK_GRACE && ! empty($licenseLock['until']))
                                @php $licenseGraceKey = ($licenseService->state()['has_license'] ?? false) ? 'super.license.grace_notice' : 'super.license.grace_notice_unactivated'; @endphp
                                {{ __($licenseGraceKey, ['date' => \Carbon\Carbon::parse($licenseLock['until'])->format('Y-m-d H:i')]) }}
                            @else
                                {{ __('super.license.notice') }}
                            @endif
                        </span>
                        @if($centralUser->hasPermission('license'))
                            <a href="{{ route('super.settings.license') }}" class="btn btn-primary btn-sm">{{ __('super.license.notice_action') }}</a>
                        @endif
                    </div>
                @endif
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-error">{{ session('error') }}</div>
                @endif
                @if($errors->any())
                    <div class="alert alert-error">{{ $errors->first() }}</div>
                @endif
                @yield('content')
            </main>
            @if(!empty($generalSettings->dashboard_footer_text) || !empty($appVersion))
                <footer class="dashboard-footer">
                    @if(!empty($generalSettings->dashboard_footer_text))
                        <span>{!! e($generalSettings->dashboard_footer_text) !!}</span>
                    @endif
                    @if(!empty($appVersion))
                        <span class="dashboard-footer-version">
                            {{ __('super.general_settings.version') }} {{ $appVersion }}
                        </span>
                    @endif
                </footer>
            @endif
        </div>
    </div>

    <script>
        // Strings the super-admin JS renders client-side. Keyed the same as
        // resources/lang/*/super.php -> 'js'; __s() mirrors Laravel's :param
        // replacement so those scripts hold no English of their own.
        window.SuperI18n = @json(__('super.js'));
        window.__s = function (key, params) {
            var s = (window.SuperI18n && window.SuperI18n[key] !== undefined) ? window.SuperI18n[key] : key;
            if (params) {
                Object.keys(params).forEach(function (p) {
                    s = s.split(':' + p).join(params[p]);
                });
            }
            return s;
        };
    </script>
    <script src="{{ $asset('assets_super/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ $asset('assets_super/js/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $asset('assets_super/js/super-layout.js') }}"></script>
    <script>
        // Topbar language switcher: type-to-filter, focus the search on open, reset on close.
        (function () {
            var wrap = document.querySelector('.lang-switcher');
            var input = wrap && wrap.querySelector('.js-lang-dd-search');
            if (!input) return;
            var items = wrap.querySelectorAll('.js-lang-dd-item');
            var empty = wrap.querySelector('.js-lang-dd-empty');
            function filter() {
                var q = input.value.trim().toLowerCase(), shown = 0;
                items.forEach(function (el) {
                    var hit = !q || el.dataset.search.indexOf(q) !== -1;
                    el.hidden = !hit;
                    if (hit) shown++;
                });
                empty.hidden = shown > 0;
            }
            input.addEventListener('input', filter);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var first = Array.prototype.find.call(items, function (el) { return !el.hidden; });
                    if (first) first.querySelector('button').click();
                }
            });
            wrap.addEventListener('shown.bs.dropdown', function () { input.focus(); });
            wrap.addEventListener('hidden.bs.dropdown', function () { input.value = ''; filter(); });
        })();
    </script>
    @stack('scripts')

    {{-- ====================================================== --}}
    {{-- Primary Color Customizer (floating button + drawer)    --}}
    {{-- ====================================================== --}}
    @if($generalSettings->show_customizer_button ?? true)
    <button type="button" class="super-customizer-fab" id="superCustomizerFab" aria-label="{{ __('super.customizer.open') }}" title="{{ __('super.customizer.open') }}">
        <i class="bi bi-palette-fill"></i>
    </button>
    <div class="super-customizer-backdrop" id="superCustomizerBackdrop"></div>
    <aside class="super-customizer-drawer" id="superCustomizerDrawer" role="dialog" aria-labelledby="superCustomizerTitle" aria-hidden="true">
        <div class="super-customizer-header">
            <h3 id="superCustomizerTitle"><i class="bi bi-palette-fill"></i> {{ __('super.customizer.title') }}</h3>
            <button type="button" class="super-customizer-close" id="superCustomizerClose" aria-label="{{ __('super.customizer.close') }}">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="super-customizer-body">
            <div class="super-customizer-section">
                <p class="super-customizer-section-title">{{ __('super.customizer.sidebar_layout') }}</p>
                <p class="super-customizer-section-help">{{ __('super.customizer.sidebar_help') }}</p>
                <div class="super-customizer-layouts" id="superCustomizerLayouts">
                    <button type="button" class="super-customizer-layout" data-layout="default" aria-label="{{ __('super.customizer.layout_default') }}">
                        <span class="sb-prev sb-prev-default"><span class="sb-prev-side"><i></i><i></i><i></i><i></i></span><span class="sb-prev-body"><i></i><i></i></span></span>
                        <span class="super-customizer-layout-name">{{ __('super.customizer.layout_default') }}</span>
                    </button>
                    <button type="button" class="super-customizer-layout" data-layout="large" aria-label="{{ __('super.customizer.layout_large') }}">
                        <span class="sb-prev sb-prev-large"><span class="sb-prev-rail"><i></i><i></i><i></i></span><span class="sb-prev-side"><i></i><i></i><i></i></span><span class="sb-prev-body"><i></i><i></i></span></span>
                        <span class="super-customizer-layout-name">{{ __('super.customizer.layout_large') }}</span>
                    </button>
                    <button type="button" class="super-customizer-layout" data-layout="accordion" aria-label="{{ __('super.customizer.layout_accordion') }}">
                        <span class="sb-prev sb-prev-accordion"><span class="sb-prev-side"><i class="h"></i><i class="s"></i><i class="s"></i><i class="h"></i><i class="h"></i></span><span class="sb-prev-body"><i></i><i></i></span></span>
                        <span class="super-customizer-layout-name">{{ __('super.customizer.layout_accordion') }}</span>
                    </button>
                    <button type="button" class="super-customizer-layout" data-layout="dark" aria-label="{{ __('super.customizer.layout_dark') }}">
                        <span class="sb-prev sb-prev-dark"><span class="sb-prev-side"><i></i><i></i><i></i><i></i></span><span class="sb-prev-body"><i></i><i></i></span></span>
                        <span class="super-customizer-layout-name">{{ __('super.customizer.layout_dark') }}</span>
                    </button>
                </div>
            </div>
            <div class="super-customizer-section">
                <p class="super-customizer-section-title">{{ __('super.customizer.primary_color') }}</p>
                <p class="super-customizer-section-help">{{ __('super.customizer.primary_help') }}</p>
                <div class="super-customizer-swatches" id="superCustomizerSwatches">
                    <button type="button" class="super-customizer-swatch" data-color="#018A00" style="background:#018A00" aria-label="{{ __('super.customizer.color_green') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#6366f1" style="background:#6366f1" aria-label="{{ __('super.customizer.color_indigo') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#3b82f6" style="background:#3b82f6" aria-label="{{ __('super.customizer.color_blue') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#06b6d4" style="background:#06b6d4" aria-label="{{ __('super.customizer.color_cyan') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#14b8a6" style="background:#14b8a6" aria-label="{{ __('super.customizer.color_teal') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#8b5cf6" style="background:#8b5cf6" aria-label="{{ __('super.customizer.color_purple') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#ec4899" style="background:#ec4899" aria-label="{{ __('super.customizer.color_pink') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#ef4444" style="background:#ef4444" aria-label="{{ __('super.customizer.color_red') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#f97316" style="background:#f97316" aria-label="{{ __('super.customizer.color_orange') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#f59e0b" style="background:#f59e0b" aria-label="{{ __('super.customizer.color_amber') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#0f172a" style="background:#0f172a" aria-label="{{ __('super.customizer.color_slate') }}"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#64748b" style="background:#64748b" aria-label="{{ __('super.customizer.color_gray') }}"></button>
                </div>
            </div>

            <div class="super-customizer-section">
                <p class="super-customizer-section-title">{{ __('super.customizer.custom_color') }}</p>
                <div class="super-customizer-picker-row">
                    <input type="color" id="superCustomizerColorInput" value="#018A00" aria-label="{{ __('super.customizer.pick_custom') }}">
                    <input type="text" id="superCustomizerHexInput" value="#018A00" maxlength="7" aria-label="{{ __('super.customizer.hex_value') }}">
                </div>
                <div class="super-customizer-actions">
                    <button type="button" class="super-customizer-btn super-customizer-btn-secondary" id="superCustomizerReset">{{ __('super.customizer.reset_default') }}</button>
                </div>
            </div>
        </div>
    </aside>

    <script src="{{ $asset('assets_super/js/super-customizer.js') }}"></script>
    @endif
</body>
</html>
