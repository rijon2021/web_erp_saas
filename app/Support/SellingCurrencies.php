<?php

namespace App\Support;

use App\Models\Central\GeneralSetting;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies plans are sold in (multi-currency pricing feature).
 *
 * The MAIN currency is the platform currency (Settings → General); it always
 * exists and is what plan.price / yearly_price are in. Extra currencies live
 * in `platform_currencies`. With the feature off, only the main currency is
 * offered — everything behaves as a single-currency platform.
 */
class SellingCurrencies
{
    /** Built-in display symbols; a platform_currencies.symbol overrides. */
    public const SYMBOLS = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NGN' => '₦', 'GHS' => 'GH₵', 'KES' => 'KSh',
        'ZAR' => 'R', 'XOF' => 'F CFA', 'XAF' => 'FCFA', 'UGX' => 'USh', 'TZS' => 'TSh', 'RWF' => 'FRw',
        'EGP' => 'E£', 'MAD' => 'DH', 'ZMW' => 'ZK', 'MWK' => 'MK', 'ETB' => 'Br', 'CDF' => 'FC',
        'GNF' => 'FG', 'SLE' => 'Le', 'LRD' => 'L$', 'GMD' => 'D', 'BWP' => 'P', 'NAD' => 'N$',
        'MZN' => 'MT', 'AOA' => 'Kz', 'TND' => 'DT', 'DZD' => 'DA', 'INR' => '₹', 'PKR' => 'Rs',
        'BDT' => '৳', 'SAR' => 'SR', 'AED' => 'AED', 'QAR' => 'QR', 'KWD' => 'KD', 'CAD' => 'CA$',
        'AUD' => 'A$', 'JPY' => '¥', 'CNY' => '¥', 'CHF' => 'CHF', 'BRL' => 'R$', 'TRY' => '₺',
    ];

    /** Currencies written without decimals. */
    public const ZERO_DECIMAL = ['XOF', 'XAF', 'UGX', 'RWF', 'GNF', 'JPY', 'KRW', 'VND', 'CLP', 'PYG', 'KMF', 'DJF', 'BIF', 'MGA', 'TZS'];

    /** Currencies whose symbol is written after the amount. */
    public const SUFFIX_SYMBOL = ['XOF', 'XAF', 'CDF', 'GNF', 'RWF', 'KMF', 'DJF', 'BIF'];

    /** Rounding options for automatic (converted) prices. */
    public const ROUNDINGS = ['none', 'whole', '10', '50', '100', '500', '1000'];

    /** Countries → currency, for the optional "preselect from the visitor's country". */
    public const COUNTRY_CURRENCY = [
        'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES', 'ZA' => 'ZAR', 'UG' => 'UGX', 'TZ' => 'TZS',
        'RW' => 'RWF', 'EG' => 'EGP', 'MA' => 'MAD', 'ZM' => 'ZMW', 'MW' => 'MWK', 'ET' => 'ETB',
        'CD' => 'CDF', 'GN' => 'GNF', 'SL' => 'SLE', 'LR' => 'LRD', 'GM' => 'GMD', 'BW' => 'BWP',
        'NA' => 'NAD', 'MZ' => 'MZN', 'AO' => 'AOA', 'TN' => 'TND', 'DZ' => 'DZD',
        // CFA franc — West (XOF) and Central (XAF)
        'SN' => 'XOF', 'CI' => 'XOF', 'BJ' => 'XOF', 'BF' => 'XOF', 'ML' => 'XOF', 'NE' => 'XOF', 'TG' => 'XOF', 'GW' => 'XOF',
        'CM' => 'XAF', 'GA' => 'XAF', 'CG' => 'XAF', 'TD' => 'XAF', 'CF' => 'XAF', 'GQ' => 'XAF',
        'US' => 'USD', 'GB' => 'GBP', 'IN' => 'INR', 'PK' => 'PKR', 'BD' => 'BDT', 'SA' => 'SAR', 'AE' => 'AED',
        'CA' => 'CAD', 'AU' => 'AUD',
        // Euro area
        'FR' => 'EUR', 'DE' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'PT' => 'EUR', 'IE' => 'EUR',
        'AT' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR', 'LU' => 'EUR',
    ];

    /** @var array<int, object>|null */
    protected static ?array $rows = null;

    public static function main(): string
    {
        return strtoupper(GeneralSetting::currencyCode() ?: 'USD');
    }

    public static function enabled(): bool
    {
        return PlatformFeatures::enabled(PlatformFeatures::MULTI_CURRENCY) && static::tableReady();
    }

    /** @var bool|null per-request memo of tableReady() */
    protected static ?bool $ready = null;

    /**
     * Whether the multi-currency migration has run. Asked on public pages,
     * so cached in the cache store (ready: 10 min, not yet: 1 min); the
     * migration clears it via PlatformFeatures::forgetCache().
     */
    public static function tableReady(): bool
    {
        if (static::$ready !== null) {
            return static::$ready;
        }
        try {
            $hit = \Illuminate\Support\Facades\Cache::get('multi_currency:schema');
            if ($hit !== null) {
                return static::$ready = (bool) $hit;
            }
        } catch (\Throwable) {
            // probe below
        }
        try {
            $ready = Schema::connection('central')->hasTable('platform_currencies')
                && Schema::connection('central')->hasTable('plan_prices');
        } catch (\Throwable) {
            $ready = false;
        }
        try {
            \Illuminate\Support\Facades\Cache::put('multi_currency:schema', $ready ? 1 : 0, $ready ? 600 : 60);
        } catch (\Throwable) {
            // not cached
        }

        return static::$ready = $ready;
    }

    /** platform_currencies rows (all, including inactive), memoised per request. */
    public static function rows(): array
    {
        if (static::$rows !== null) {
            return static::$rows;
        }
        if (! static::tableReady()) {
            return static::$rows = [];
        }
        try {
            return static::$rows = DB::connection('central')->table('platform_currencies')
                ->orderBy('sort_order')->orderBy('code')->get()->keyBy('code')->all();
        } catch (\Throwable) {
            return static::$rows = [];
        }
    }

    public static function forgetCache(): void
    {
        static::$rows = null;
        static::$ready = null;
        try {
            \Illuminate\Support\Facades\Cache::forget('multi_currency:schema');
        } catch (\Throwable) {
            // nothing cached
        }
    }

    /**
     * Currencies offered right now: the main one first, then the active extra
     * ones. Only the main currency while the feature is off.
     *
     * @return string[]
     */
    public static function active(): array
    {
        $main = static::main();
        if (! static::enabled()) {
            return [$main];
        }
        $codes = [$main];
        foreach (static::rows() as $code => $row) {
            if ($row->is_active && strtoupper($code) !== $main) {
                $codes[] = strtoupper($code);
            }
        }

        return $codes;
    }

    /** Is this a currency a customer may pick? */
    public static function isOffered(?string $code): bool
    {
        return $code !== null && in_array(strtoupper($code), static::active(), true);
    }

    public static function symbol(string $code): string
    {
        $code = strtoupper($code);
        if ($code === static::main()) {
            return GeneralSetting::currencySymbol() ?: (self::SYMBOLS[$code] ?? $code);
        }
        $row = static::rows()[$code] ?? null;

        return ($row && $row->symbol) ? $row->symbol : (self::SYMBOLS[$code] ?? $code);
    }

    public static function decimals(string $code): int
    {
        return in_array(strtoupper($code), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** "₦15,000" / "GH₵150.50" / "6 000 F CFA"-style display. */
    public static function format(float $amount, string $code): string
    {
        $code = strtoupper($code);
        $decimals = static::decimals($code);
        // Whole amounts drop ".00" for a cleaner price tag.
        if ($decimals > 0 && abs($amount - round($amount)) < 0.005) {
            $decimals = 0;
        }
        $number = number_format($amount, $decimals);
        $symbol = static::symbol($code);

        // Franc currencies are written after the amount (6,000 F CFA, 5,000 FRw).
        return in_array($code, self::SUFFIX_SYMBOL, true)
            ? $number . ' ' . $symbol
            : $symbol . $number;
    }

    /** Round an automatic (converted) price the way the currency asks. */
    public static function roundAuto(float $amount, string $code): float
    {
        $row = static::rows()[strtoupper($code)] ?? null;
        $rule = $row->rounding ?? 'whole';
        if (static::decimals($code) === 0 && $rule === 'none') {
            $rule = 'whole';
        }

        return match ($rule) {
            'none'  => round($amount, 2),
            'whole' => (float) ceil($amount),
            default => (float) (ceil($amount / (int) $rule) * (int) $rule),
        };
    }

    /**
     * Active gateways that charge this currency directly (no conversion).
     * Bank transfer takes any currency.
     *
     * @return array<string, string> gateway key => label
     */
    public static function gatewaysFor(string $code): array
    {
        $code = strtoupper($code);
        $out = [];
        foreach (PaymentGatewayFactory::getAvailableGateways() as $key => $gateway) {
            if ($key === 'offline') {
                $out[$key] = $gateway['label'] ?? 'Bank transfer';
                continue;
            }
            $config = PaymentGatewayFactory::getGatewayCurrencyConfig($key);
            if (in_array($code, $config['supported_currencies'] ?? [], true)) {
                $out[$key] = $gateway['label'] ?? ucfirst($key);
            }
        }

        return $out;
    }

    /** Currency for a visitor's country code (CF-IPCountry style), or null. */
    public static function forCountry(?string $country): ?string
    {
        $code = self::COUNTRY_CURRENCY[strtoupper((string) $country)] ?? null;

        return $code && static::isOffered($code) ? $code : null;
    }
}
