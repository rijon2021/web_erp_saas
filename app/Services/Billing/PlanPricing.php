<?php

namespace App\Services\Billing;

use App\Models\Central\Plan;
use App\Services\CurrencyConversionService;
use App\Support\PlatformFeatures;
use App\Support\SellingCurrencies;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Plan prices per currency (multi-currency pricing feature).
 *
 * plan.price / yearly_price are the MAIN currency. Another currency is either
 * a FIXED price typed by the super admin (plan_prices) or AUTO: the main price
 * converted at the live rate and rounded (SellingCurrencies::roundAuto).
 *
 * Every place that prices a plan goes through price() / displayPlan(), so the
 * landing, register, checkout and tenant billing pages always agree with what
 * is charged.
 */
class PlanPricing
{
    public const COOKIE = 'plan_currency';

    /** @var array<int, array<string, object>> plan_prices per plan id */
    protected static array $rows = [];

    /** Price of a plan for a cycle in a currency (main price when not priced). */
    public static function price(Plan $plan, string $cycle, ?string $currency): float
    {
        $currency = strtoupper((string) ($currency ?: SellingCurrencies::main()));
        $main = SellingCurrencies::main();
        $base = (float) $plan->getPriceForCycle($cycle);

        if ($currency === $main) {
            return $base;
        }

        $row = static::rowsFor($plan)[$currency] ?? null;
        if ($row && $row->mode === 'fixed') {
            $monthly = $row->monthly_price !== null ? (float) $row->monthly_price : null;
            $yearly = $row->yearly_price !== null ? (float) $row->yearly_price : null;
            if ($cycle === 'yearly') {
                if ($yearly !== null && $yearly > 0) {
                    return $yearly;
                }
                // Same rule as Plan::getPriceForCycle: no yearly price = 12 × monthly.
                if ($monthly !== null) {
                    return $base <= 0 ? 0.0 : $monthly * 12;
                }
            } elseif ($monthly !== null) {
                return $monthly;
            }
        }

        // Auto: convert the main price, rounded for the currency.
        if ($base <= 0) {
            return 0.0;
        }

        return SellingCurrencies::roundAuto($base * static::rate($main, $currency), $currency);
    }

    /** Convert an amount from the main currency (add-ons, which are priced in it). */
    public static function fromMain(float $amount, ?string $currency): float
    {
        $currency = strtoupper((string) ($currency ?: SellingCurrencies::main()));
        if ($currency === SellingCurrencies::main() || $amount == 0.0) {
            return round($amount, 2);
        }

        return round($amount * static::rate(SellingCurrencies::main(), $currency), SellingCurrencies::decimals($currency));
    }

    public static function rate(string $from, string $to): float
    {
        if ($from === $to) {
            return 1.0;
        }

        // Both rate APIs down: CurrencyConversionService waits up to 2 × 10 s
        // before throwing. Remember the failure for a minute so public pages
        // fall back to the main price at once instead of hanging every view.
        $failKey = "exchange_rate_failed:{$from}:{$to}";
        if (Cache::has($failKey)) {
            throw new \RuntimeException("Exchange rate {$from} → {$to} unavailable (recent failure).");
        }

        try {
            return CurrencyConversionService::getExchangeRate($from, $to);
        } catch (\Throwable $e) {
            Cache::put($failKey, 1, 60);
            throw $e;
        }
    }

    /**
     * A copy of the plan priced in a currency, for pages that read
     * $plan->price / yearly_price directly (landing designs, register page,
     * tenant billing). Never saved (Plan::booted guard).
     */
    public static function displayPlan(Plan $plan, ?string $currency): Plan
    {
        $currency = strtoupper((string) ($currency ?: SellingCurrencies::main()));
        if ($currency === SellingCurrencies::main()) {
            return $plan;
        }

        try {
            $monthly = static::price($plan, 'monthly', $currency);
            $yearly = static::price($plan, 'yearly', $currency);
        } catch (\Throwable $e) {
            // No rate for this currency right now: show the main price.
            Log::warning("PlanPricing: no price for plan {$plan->id} in {$currency}: {$e->getMessage()}");

            return $plan;
        }

        $copy = clone $plan;
        $copy->price = $monthly;
        $copy->yearly_price = $yearly;
        $copy->displayCurrency = $currency;

        return $copy;
    }

    /** @param  Collection<int, Plan>  $plans */
    public static function displayPlans(Collection $plans, ?string $currency): Collection
    {
        return $plans->map(fn (Plan $p) => static::displayPlan($p, $currency));
    }

    /** plan_prices rows for one plan, keyed by currency. */
    public static function rowsFor(Plan $plan): array
    {
        $id = (int) $plan->id;
        if (isset(static::$rows[$id])) {
            return static::$rows[$id];
        }
        if (! SellingCurrencies::tableReady()) {
            return static::$rows[$id] = [];
        }
        try {
            return static::$rows[$id] = DB::connection('central')->table('plan_prices')
                ->where('plan_id', $id)->get()->keyBy(fn ($r) => strtoupper($r->currency))->all();
        } catch (\Throwable) {
            return static::$rows[$id] = [];
        }
    }

    public static function forgetCache(): void
    {
        static::$rows = [];
        SellingCurrencies::forgetCache();
    }

    // ── Which currency? ──────────────────────────────────────────────

    /**
     * The currency a public visitor sees (landing, register): ?currency= in the
     * link (remembered in a cookie), else the cookie, else — when switched on —
     * the visitor's country, else the main currency.
     */
    public static function visitorCurrency(Request $request): string
    {
        $main = SellingCurrencies::main();
        if (! SellingCurrencies::enabled()) {
            return $main;
        }

        $asked = strtoupper((string) $request->query('currency', ''));
        if ($asked !== '' && SellingCurrencies::isOffered($asked)) {
            cookie()->queue(cookie(self::COOKIE, $asked, 60 * 24 * 90));

            return $asked;
        }

        $saved = strtoupper((string) $request->cookie(self::COOKIE, ''));
        if ($saved !== '' && SellingCurrencies::isOffered($saved)) {
            return $saved;
        }

        if (PlatformFeatures::setting(PlatformFeatures::MULTI_CURRENCY, 'auto_detect', false)) {
            $fromCountry = SellingCurrencies::forCountry(static::visitorCountry($request));
            if ($fromCountry) {
                return $fromCountry;
            }
        }

        return $main;
    }

    /**
     * Visitor's country: a CDN header when present (Cloudflare / CloudFront /
     * Vercel), else a cached lookup of the IP (1 day, 2 s timeout).
     */
    protected static function visitorCountry(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Vercel-IP-Country', 'X-Country-Code'] as $header) {
            $value = strtoupper(trim((string) $request->header($header, '')));
            if (preg_match('/^[A-Z]{2}$/', $value) && $value !== 'XX') {
                return $value;
            }
        }

        $ip = (string) $request->ip();
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            return Cache::remember('visitor_country:' . md5($ip), 86400, function () use ($ip) {
                $response = Http::timeout(2)->get('https://ipapi.co/' . $ip . '/country/');

                $code = strtoupper(trim((string) $response->body()));

                return $response->successful() && preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
            }) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The currency a workspace is billed in: set at signup (tenant
     * billing_currency), else its latest subscription's, else the main one.
     * Kept even after the feature is switched off — existing tenants keep
     * paying in their own currency.
     */
    public static function tenantCurrency(?Tenant $tenant): string
    {
        $main = SellingCurrencies::main();
        if (! $tenant) {
            return $main;
        }

        // Only an explicit choice counts (multi-currency signup, or set on the
        // tenant page). Never the currency stored on old subscription rows:
        // those can be stale (a past bug stored 'USD' on non-USD platforms, and
        // the platform currency may have been changed since) — trusting them
        // would re-bill such tenants in a converted foreign currency.
        $code = strtoupper((string) ($tenant->billing_currency ?? ''));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : $main;
    }
}
