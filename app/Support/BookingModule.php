<?php

namespace App\Support;

use App\Models\BookingSetting;
use Illuminate\Support\Facades\DB;

/**
 * Whether Booking Management is usable on the current tenant: its plan has
 * the `bookings` feature, the admin has not switched the module off
 * (Settings › Modules) and migration 2026_10_25_000003 has run. Used by code
 * outside the module's own routes (store theme, pending-work badges…), which
 * the tenant.feature:bookings middleware does not cover.
 */
class BookingModule
{
    /** @var array<string, bool> tenant DB name → enabled (per request) */
    private static array $memo = [];

    public static function enabled(): bool
    {
        $key = (string) DB::connection()->getDatabaseName();

        return self::$memo[$key] ??= self::compute();
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    private static function compute(): bool
    {
        if (! BookingSetting::tablesReady()) {
            return false;
        }
        $raw = DB::table('settings')->whereNull('deleted_at')->value('module_flags');
        $flags = $raw ? json_decode($raw, true) : null;
        if (is_array($flags) && array_key_exists('bookings', $flags) && $flags['bookings'] === false) {
            return false;
        }

        return \App\Services\Crm\CrmCaptureService::planAllows('bookings');
    }

    /**
     * Console runs (reminders, deposit expiry): url() would point at APP_URL,
     * so links in emails use the tenant's own host instead (as CourierNotifier).
     */
    public static function useTenantUrl(): void
    {
        if (! app()->runningInConsole() || ! function_exists('tenant') || ! tenant()) {
            return;
        }
        store_path(true); // per tenant: the command loops over tenants
        $domain = strtolower((string) tenant()->domains()->value('domain'));
        if ($domain === '') {
            \Illuminate\Support\Facades\URL::forceRootUrl(null);

            return;
        }
        if (! str_contains($domain, '.')) {
            $central = array_values(array_filter((array) config('tenancy.central_domains', [])))[0] ?? null;
            $domain = $central ? $domain.'.'.$central : $domain;
        }
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        \Illuminate\Support\Facades\URL::forceRootUrl($scheme.'://'.$domain);
    }
}
