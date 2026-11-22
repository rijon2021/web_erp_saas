<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\TenantLimitsService;

/**
 * Client Portal options, stored as one JSON blob in settings.portal_settings
 * and edited on Client Portal → Portal Settings.
 *
 * A null column or a missing key means the default below, so tenants that
 * never opened the page keep today's behaviour: portal on, every module shown.
 * Enforcement lives in the `portal.feature` middleware (routes/portal.php);
 * the portal SPA only hides what the API would refuse anyway.
 *
 * SaaS: a module also needs the tenant's plan to include the feature it is
 * built on (PLAN_FEATURES). allows() and forClient() answer for both, so a
 * module the plan lacks is off whatever Portal Settings says.
 */
class PortalSettings
{
    /**
     * Client-facing modules that can be switched off, in portal sidebar order.
     * 'requests' is Protocol & Requests; 'deals' is the CRM (stocky serves the
     * CRM as 'requests' — that path belongs to Protocol here).
     */
    public const MODULES = [
        'invoices', 'payments', 'statement', 'requests', 'deals', 'quotations',
        'appointments', 'bookings', 'contracts', 'rentals', 'help',
    ];

    /** Actions clients can take, each switchable on its own. */
    public const ACTIONS = ['quotation_requests', 'appointment_booking'];

    /** Plan feature the whole portal needs: without it the portal is off, whatever Portal Settings says. */
    public const PORTAL_FEATURE = 'client_portal';

    /** Portal module => plan feature it needs (Plan::AVAILABLE_FEATURES). Unlisted = core. */
    public const PLAN_FEATURES = [
        'requests' => 'protocol',
        'deals' => 'crm',
        'quotations' => 'quotations',
        'appointments' => 'service_maintenance',
        'bookings' => 'bookings',
        'contracts' => 'contracts',
        'rentals' => 'rental',
        'help' => 'knowledge_base',
    ];

    /** Action => module it belongs to (an action is off when its module is). */
    public const ACTION_MODULES = [
        'quotation_requests' => 'quotations',
        'appointment_booking' => 'appointments',
    ];

    /** Keyed by tenant: a queue worker serves many tenants in one process. */
    private static array $cache = [];

    private static array $planCache = [];

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'modules' => array_fill_keys(self::MODULES, true),
            'actions' => array_fill_keys(self::ACTIONS, true),
            'welcome_message' => '',
            'default_locale' => null,
            'invitation_expiry_hours' => 48,
            // Lets portal users turn on two-step sign-in from their profile.
            'two_factor_enabled' => false,
        ];
    }

    /** The saved settings (NOT narrowed by the plan — see allows() / forClient()). */
    public static function all(): array
    {
        $key = self::tenantKey();
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $stored = [];
        try {
            // Runs on every portal request (SetPortalLocale), so no schema
            // check up front — a missing column just throws and lands below.
            $raw = Setting::whereNull('deleted_at')->value('portal_settings');
            $stored = $raw ? (json_decode($raw, true) ?: []) : [];
        } catch (\Throwable $e) {
            // Migration not run yet: fall back to defaults.
        }

        return self::$cache[$key] = self::normalize($stored);
    }

    public static function save(array $input): array
    {
        $clean = self::normalize($input);
        Setting::whereNull('deleted_at')->update(['portal_settings' => json_encode($clean)]);
        self::$cache[self::tenantKey()] = $clean;

        return $clean;
    }

    public static function forget(): void
    {
        self::$cache = [];
        self::$planCache = [];
    }

    /** Switched on in Portal Settings AND the plan includes the portal. */
    public static function enabled(): bool
    {
        return (bool) self::all()['enabled'] && self::planHas(self::PORTAL_FEATURE);
    }

    /** True when every named module / action is switched on and in the plan. */
    public static function allows(string ...$features): bool
    {
        $s = self::all();
        foreach ($features as $f) {
            $on = $s['modules'][$f] ?? $s['actions'][$f] ?? true;
            if (! $on) {
                return false;
            }
            $module = self::ACTION_MODULES[$f] ?? $f;
            if (! self::inPlan($module)) {
                return false;
            }
        }

        return true;
    }

    /** Whether the tenant's plan includes what a module is built on. */
    public static function inPlan(string $module): bool
    {
        $feature = self::PLAN_FEATURES[$module] ?? null;

        return $feature === null || self::planHas($feature);
    }

    private static function planHas(string $feature): bool
    {
        $key = self::tenantKey();
        if (! isset(self::$planCache[$key])) {
            $flags = [];
            try {
                $limits = app(TenantLimitsService::class);
                foreach (array_unique([...array_values(self::PLAN_FEATURES), self::PORTAL_FEATURE]) as $f) {
                    $flags[$f] = (bool) $limits->hasFeature($f);
                }
            } catch (\Throwable $e) {
                // No plan resolvable: plan modules stay off.
            }
            self::$planCache[$key] = $flags;
        }

        return self::$planCache[$key][$feature] ?? false;
    }

    /** module => in the plan, for Portal Settings (shows "not in your plan"). */
    public static function planModules(): array
    {
        $out = [];
        foreach (self::MODULES as $m) {
            $out[$m] = self::inPlan($m);
        }

        return $out;
    }

    /**
     * Whether a portal SPA path (e.g. '/invoices', '/rental-invoices?status=open')
     * belongs to a switched-on module — used to drop dashboard alerts and bell
     * items that would point at a hidden section. Mirrors portal/lib/settings.js.
     */
    public static function allowsPath(?string $url): bool
    {
        $first = explode('/', trim((string) parse_url((string) $url, PHP_URL_PATH), '/'))[0] ?? '';
        $map = ['rental-invoices' => 'rentals', 'rental-requests' => 'rentals'];
        $module = $map[$first] ?? (in_array($first, self::MODULES, true) ? $first : null);

        return $module === null || self::allows($module);
    }

    public static function invitationExpiryHours(): int
    {
        return (int) self::all()['invitation_expiry_hours'];
    }

    public static function defaultLocale(): ?string
    {
        return self::all()['default_locale'];
    }

    /** The subset the portal SPA needs (sent with /api/portal/me), narrowed by the plan. */
    public static function forClient(): array
    {
        $s = self::all();

        $modules = [];
        foreach ($s['modules'] as $m => $on) {
            $modules[$m] = $on && self::inPlan($m);
        }
        $actions = [];
        foreach ($s['actions'] as $a => $on) {
            $actions[$a] = $on && ($modules[self::ACTION_MODULES[$a] ?? ''] ?? true);
        }

        return [
            'modules' => $modules,
            'actions' => $actions,
            'welcome_message' => $s['welcome_message'],
            'two_factor_enabled' => $s['two_factor_enabled'],
        ];
    }

    /** Merge input over defaults, dropping unknown keys and bad values. */
    private static function normalize(array $in): array
    {
        $d = self::defaults();

        $flags = function ($given, array $keys) {
            $given = is_array($given) ? $given : [];
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = array_key_exists($k, $given) ? (bool) $given[$k] : true;
            }

            return $out;
        };

        $locale = $in['default_locale'] ?? null;
        $hours = (int) ($in['invitation_expiry_hours'] ?? $d['invitation_expiry_hours']);

        return [
            'enabled' => array_key_exists('enabled', $in) ? (bool) $in['enabled'] : true,
            'modules' => $flags($in['modules'] ?? null, self::MODULES),
            'actions' => $flags($in['actions'] ?? null, self::ACTIONS),
            'welcome_message' => mb_substr(trim((string) ($in['welcome_message'] ?? '')), 0, 2000),
            // Any INSTALLED portal language (Client Portal → Portal Languages).
            'default_locale' => $locale && isset(\App\Services\PortalLanguageInstaller::installedLocales()[$locale]) ? $locale : null,
            'invitation_expiry_hours' => max(1, min(720, $hours ?: 48)),
            'two_factor_enabled' => (bool) ($in['two_factor_enabled'] ?? false),
        ];
    }

    private static function tenantKey(): string
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        return $tenant ? (string) $tenant->getTenantKey() : 'central';
    }
}
