<?php

namespace App\Models\Central;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class CentralUser extends Authenticatable
{
    use Notifiable;

    protected $connection = 'central';

    protected $table = 'central_users';

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN       = 'admin';

    /**
     * Permission sections grouped exactly like the super-admin sidebar.
     * Each sidebar link is its own key, so a restricted admin can be
     * granted pages one by one.
     *
     * group key => ['label' => lang key, 'icon' => bootstrap icon,
     *               'items' => [permission key => lang key]]
     */
    public const PERMISSION_GROUPS = [
        'main' => [
            'label' => 'super.sidebar.main',
            'icon'  => 'bi-grid-1x2-fill',
            'items' => [
                'dashboard' => 'super.sidebar.dashboard',
            ],
        ],
        'management' => [
            'label' => 'super.sidebar.management',
            'icon'  => 'bi-briefcase-fill',
            'items' => [
                'tenants'       => 'super.sidebar.tenants',
                'plans'         => 'super.sidebar.plans',
                'addons'        => 'super.sidebar.addons',
                'subscriptions' => 'super.sidebar.subscriptions',
                'reminders'     => 'super.sidebar.reminders',
                'admins'        => 'super.sidebar.admins',
            ],
        ],
        'payments' => [
            'label' => 'super.sidebar.payments',
            'icon'  => 'bi-credit-card-fill',
            'items' => [
                'payments_overview'     => 'super.sidebar.overview',
                'payments_transactions' => 'super.sidebar.transactions',
                'payments_links'        => 'super.sidebar.payment_links',
                'payments_pending'      => 'super.sidebar.pending_payments',
                'payments_invoices'     => 'super.sidebar.invoices',
            ],
        ],
        'support' => [
            'label' => 'super.sidebar.support',
            'icon'  => 'bi-life-preserver',
            'items' => [
                'support' => 'super.sidebar.support_tickets',
            ],
        ],
        'reports' => [
            'label' => 'super.sidebar.reports',
            'icon'  => 'bi-bar-chart-fill',
            'items' => [
                'reports' => 'super.sidebar.reports',
            ],
        ],
        'cms' => [
            'label' => 'super.sidebar.cms',
            'icon'  => 'bi-window-stack',
            'items' => [
                'cms_landing'       => 'super.sidebar.landing_page',
                'cms_landing_pages' => 'super.sidebar.landing_pages',
                'cms_hero'          => 'super.sidebar.hero_section',
                'cms_features'      => 'super.sidebar.features',
                'cms_pricing'       => 'super.sidebar.pricing_section',
                'cms_stats'         => 'super.sidebar.stats',
                'cms_how_it_works'  => 'super.sidebar.how_it_works',
                'cms_testimonials'  => 'super.sidebar.testimonials',
                'cms_faq'           => 'super.sidebar.faq',
                'cms_cta'           => 'super.sidebar.cta_section',
                'cms_footer'        => 'super.sidebar.footer',
                'cms_seo'           => 'super.sidebar.seo_settings',
                'cms_privacy'       => 'super.sidebar.privacy_policy',
                'cms_terms'         => 'super.sidebar.terms',
                'cms_translations'  => 'super.sidebar.translations',
            ],
        ],
        'knowledge_base' => [
            'label' => 'super.sidebar.knowledge_base',
            'icon'  => 'bi-book-fill',
            'items' => [
                'knowledge_base' => 'super.sidebar.knowledge_base',
            ],
        ],
        'blog' => [
            'label' => 'super.sidebar.blog',
            'icon'  => 'bi-journal-text',
            'items' => [
                'blog' => 'super.sidebar.blog',
            ],
        ],
        'help' => [
            'label' => 'super.sidebar.help',
            'icon'  => 'bi-question-circle-fill',
            'items' => [
                'how_it_works' => 'super.sidebar.how_it_works',
            ],
        ],
        'monitoring' => [
            'label' => 'super.sidebar.monitoring',
            'icon'  => 'bi-activity',
            'items' => [
                'logs_health'        => 'super.sidebar.logs_health',
                'impersonation_logs' => 'super.sidebar.impersonation_logs',
            ],
        ],
        'settings' => [
            'label' => 'super.sidebar.settings',
            'icon'  => 'bi-gear-fill',
            'items' => [
                'settings_general'          => 'super.sidebar.general_settings',
                'languages'                 => 'super.languages.title',
                'email_templates'           => 'super.sidebar.email_templates',
                'sms_templates'             => 'super.sidebar.sms_templates',
                'settings_mail'             => 'super.sidebar.mail_settings',
                'settings_sms'              => 'super.sidebar.sms_settings',
                'settings_payment_gateways' => 'super.sidebar.payment_gateways',
                'whatsapp'                  => 'super.sidebar.whatsapp_settings',
                'system_update'             => 'super.sidebar.system_update',
                'license'                   => 'super.sidebar.license',
            ],
        ],
    ];

    /**
     * Umbrella keys. Asking hasPermission('payments') is true when ANY of
     * the listed page keys is granted, so section labels, route groups and
     * dashboard widgets keyed on the old section name keep working.
     */
    public const SECTION_CHILDREN = [
        'payments' => ['payments_overview', 'payments_transactions', 'payments_links', 'payments_pending', 'payments_invoices'],
        'cms'      => ['cms_landing', 'cms_landing_pages', 'cms_hero', 'cms_features', 'cms_pricing', 'cms_stats', 'cms_how_it_works', 'cms_testimonials', 'cms_faq', 'cms_cta', 'cms_footer', 'cms_seo', 'cms_privacy', 'cms_terms', 'cms_translations'],
        'settings' => ['settings_general', 'settings_mail', 'settings_sms', 'settings_payment_gateways', 'license'],
    ];

    /**
     * Backward compatibility for admins saved before pages were split:
     * a stored legacy key still grants every page it used to cover.
     */
    public const LEGACY_IMPLIES = [
        'payments'    => self::SECTION_CHILDREN['payments'],
        'cms'         => self::SECTION_CHILDREN['cms'],
        'settings'    => self::SECTION_CHILDREN['settings'],
        'tenants'     => ['impersonation_logs'],
        'logs_health' => ['impersonation_logs'],
    ];

    /**
     * Flat list of every selectable permission: key => English label.
     * (Forms translate through PERMISSION_GROUPS; this is the validation list.)
     */
    public const PERMISSIONS = [
        'dashboard'                 => 'Dashboard',
        'tenants'                   => 'Tenants',
        'plans'                     => 'Plans',
        'addons'                    => 'Add-ons',
        'subscriptions'             => 'Subscriptions',
        'reminders'                 => 'Subscription Reminders',
        'admins'                    => 'Super Admins',
        'payments_overview'         => 'Payments Overview',
        'payments_transactions'     => 'Payment Transactions',
        'payments_links'            => 'Payment Links',
        'payments_pending'          => 'Pending Payments',
        'payments_invoices'         => 'Invoices',
        'support'                   => 'Support Tickets',
        'reports'                   => 'Reports',
        'cms_landing'               => 'CMS: Landing Page',
        'cms_landing_pages'         => 'CMS: Landing Layouts',
        'cms_hero'                  => 'CMS: Hero Section',
        'cms_features'              => 'CMS: Features',
        'cms_pricing'               => 'CMS: Pricing Section',
        'cms_stats'                 => 'CMS: Stats / Trust Bar',
        'cms_how_it_works'          => 'CMS: How It Works',
        'cms_testimonials'          => 'CMS: Testimonials',
        'cms_faq'                   => 'CMS: FAQ',
        'cms_cta'                   => 'CMS: CTA Section',
        'cms_footer'                => 'CMS: Footer',
        'cms_seo'                   => 'CMS: SEO Settings',
        'cms_privacy'               => 'CMS: Privacy Policy',
        'cms_terms'                 => 'CMS: Terms & Conditions',
        'cms_translations'          => 'CMS: Translations',
        'knowledge_base'            => 'Knowledge Base',
        'blog'                      => 'Blog',
        'how_it_works'              => 'How It Works',
        'logs_health'               => 'Logs & System Health',
        'impersonation_logs'        => 'Impersonation Logs',
        'settings_general'          => 'General Settings',
        'languages'                 => 'Languages & Translations',
        'email_templates'           => 'Email Templates',
        'sms_templates'             => 'SMS Templates',
        'settings_mail'             => 'Mail Settings',
        'settings_sms'              => 'SMS Settings',
        'settings_payment_gateways' => 'Payment Gateways',
        'whatsapp'                  => 'WhatsApp Settings',
        'system_update'             => 'System Update',
        'license'                   => 'Licence (UI Lib activation)',
    ];

    protected $fillable = ['name', 'email', 'password', 'avatar', 'role', 'permissions'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Expand a stored permission list into the granular page keys it
     * grants (legacy section keys are replaced by their pages).
     */
    public static function expandPermissions(?array $stored): array
    {
        $out = [];
        foreach ($stored ?? [] as $key) {
            if (isset(self::LEGACY_IMPLIES[$key])) {
                array_push($out, ...self::LEGACY_IMPLIES[$key]);
            }
            if (isset(self::PERMISSIONS[$key])) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /** Granular page keys this admin can open (empty for super admins = everything). */
    public function effectivePermissions(): array
    {
        return self::expandPermissions($this->permissions);
    }

    public function hasPermission(string $section): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $granted = $this->effectivePermissions();

        if (in_array($section, $granted, true)) {
            return true;
        }

        // Umbrella section (payments / cms / settings): any page inside counts.
        if (isset(self::SECTION_CHILDREN[$section])) {
            return ! empty(array_intersect(self::SECTION_CHILDREN[$section], $granted));
        }

        return false;
    }

    public function hasAnyPermission(array $sections): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($sections as $section) {
            if ($this->hasPermission($section)) {
                return true;
            }
        }

        return false;
    }
}
