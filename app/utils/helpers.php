<?php

namespace App\utils;

use App\Models\Currency;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

class helpers
{
    /**
     * Helper Multiple Filter — applies one AND condition per column that the
     * request actually carries a value for.
     *
     * $columns and $param are parallel 0-indexed arrays: $columns[i] is the
     * DB column and $param[i] is how to match it. "like" means a free-text box
     * (name, code, Ref, email …) and matches anywhere in the value, so typing
     * "Book" finds "Business Book Set"; anything else is an exact comparison
     * and is what ids, dates and status enums use — a substring match on
     * payment_statut would make "paid" also match "unpaid".
     */
    public function filter($model, $columns, $param, $request)
    {
        foreach ($columns as $key => $column) {
            if (! $request->filled($column)) {
                continue;
            }

            $value = $request[$column];
            $operator = $param[$key] ?? '=';

            if ($operator === 'like') {
                $model->where($column, 'LIKE', '%'.$value.'%');
            } else {
                $model->where($column, $operator, $value);
            }
        }

        // Finally return the model
        return $model;
    }

    //  Check If Hass Permission Show All records
    public function Show_Records($model)
    {
        $user = Auth::user();
        
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $ShowRecord = false;
        
        if (isset($user->record_view)) {
            // Use user-level record_view field
            $ShowRecord = (bool) $user->record_view;
        } else {
            // Fallback to role permission check for backward compatibility
            $Role = $user->roles()->first();
            if ($Role) {
                $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');
            }
        }

        if (! $ShowRecord) {
            return $model->where('user_id', '=', Auth::user()->id);
        }

        return $model;
    }
    
    //  Check If User Has Record View Permission (with backward compatibility)
    public function HasRecordView($user = null)
    {
        if ($user === null) {
            $user = Auth::user();
        }
        
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        if (isset($user->record_view)) {
            // Use user-level record_view field
            return (bool) $user->record_view;
        } else {
            // Fallback to role permission check for backward compatibility
            $Role = $user->roles()->first();
            if ($Role) {
                return Role::findOrFail($Role->id)->inRole('record_view');
            }
        }
        
        return false;
    }

    /**
     * The tenant's default currency, or null when none is set or it was deleted.
     * Read through the memoised settings row, so the relation loads once per
     * request instead of three queries on every call (these run per sale line).
     */
    private static function defaultCurrency(): ?Currency
    {
        $settings = Setting::current();
        if (! $settings || ! $settings->currency_id) {
            return null;
        }

        $currency = $settings->Currency;

        return ($currency && $currency->deleted_at === null) ? $currency : null;
    }

    // Get Currency
    public function Get_Currency()
    {
        return static::defaultCurrency()->symbol ?? '';
    }

    // Get Currency COde
    public function Get_Currency_Code()
    {
        return static::defaultCurrency()->code ?? 'usd';
    }

    /**
     * Whether the Multi-Currency module is enabled for this tenant. Read through
     * the memoised settings row (per request / per job, keyed by tenant — a
     * function-level static here leaked one tenant's value to the next job of
     * a queue worker).
     */
    public static function multi_currency_enabled()
    {
        try {
            return (bool) (Setting::current()->enable_multi_currency ?? false);
        } catch (\Throwable $e) {
            // Settings table/column may not exist yet (tenant not migrated) — fall back.
            return false;
        }
    }

    /**
     * Resolve the currency a document was recorded in.
     *
     * Returns ['id','symbol','code','rate'] where rate = units of the document
     * currency per 1 base-currency unit (base rate is always 1). Falls back to
     * the tenant's default currency with rate 1 when the document has no
     * currency_id, the currency row was deleted, or Multi-Currency is off —
     * so legacy rows and toggle-off tenants behave exactly as before.
     *
     * @param  object|null  $doc  any model/stdClass with currency_id / exchange_rate
     */
    public static function Get_Document_Currency($doc)
    {
        $helpers = new self;
        $default = [
            'id' => null,
            'symbol' => $helpers->Get_Currency(),
            'code' => $helpers->Get_Currency_Code(),
            'rate' => 1.0,
        ];

        if (! $doc || ! self::multi_currency_enabled()) {
            return $default;
        }

        $currencyId = $doc->currency_id ?? null;
        if (! $currencyId) {
            return $default;
        }

        $currency = Currency::where('id', $currencyId)
            ->where('deleted_at', '=', null)
            ->first();
        if (! $currency) {
            return $default;
        }

        $rate = (float) ($doc->exchange_rate ?? 0);
        if ($rate <= 0) {
            $rate = (float) ($currency->exchange_rate ?? 1) ?: 1.0;
        }

        return [
            'id' => $currency->id,
            'symbol' => $currency->symbol,
            'code' => $currency->code,
            'rate' => $rate,
        ];
    }

    /**
     * Convert a base-currency amount to the document currency.
     */
    public static function to_document_amount($baseAmount, $rate)
    {
        return (float) $baseAmount * ((float) $rate ?: 1.0);
    }

    /**
     * Sanitize the currency snapshot sent by a document form.
     *
     * Returns ['currency_id','exchange_rate'] ready to persist. Base-currency
     * documents are stored as NULL/NULL (the backward-compat convention), a
     * missing field keeps the existing stored pair (so non-form API callers
     * don't clear it), and the client-sent rate is never trusted blindly —
     * anything <= 0 falls back to the currency's configured rate.
     */
    public static function resolve_request_currency($request, $existingCurrencyId = null, $existingRate = null)
    {
        if (! self::multi_currency_enabled() || ! $request->has('currency_id')) {
            return ['currency_id' => $existingCurrencyId, 'exchange_rate' => $existingRate];
        }

        // Permission gate (SalePolicy::multi_currency): the pickers are hidden
        // for users without it, so also refuse a crafted request here — the
        // stored pair is left untouched. No auth context (queued jobs) passes.
        try {
            $user = Auth::user();
            if ($user && $user->cannot('multi_currency', \App\Models\Sale::class)) {
                return ['currency_id' => $existingCurrencyId, 'exchange_rate' => $existingRate];
            }
        } catch (\Throwable $e) {
            // Permission lookup unavailable — never block the save itself.
        }

        $currencyId = $request->input('currency_id');
        if (empty($currencyId)) {
            return ['currency_id' => null, 'exchange_rate' => null];
        }

        $default = Setting::current()->currency_id ?? null;
        if ((int) $currencyId === (int) $default) {
            return ['currency_id' => null, 'exchange_rate' => null];
        }

        $currency = Currency::where('id', $currencyId)
            ->where('deleted_at', '=', null)
            ->first();
        if (! $currency) {
            return ['currency_id' => null, 'exchange_rate' => null];
        }

        $rate = (float) $request->input('exchange_rate', 0);
        if ($rate <= 0) {
            $rate = (float) ($currency->exchange_rate ?? 1) ?: 1.0;
        }

        return ['currency_id' => $currency->id, 'exchange_rate' => $rate];
    }

    /**
     * Resolve the configured monetary precision.
     *
     * Driven by the "Enable 3 Decimal Pricing" setting: returns 3 when enabled,
     * 2 otherwise. Read through the memoised settings row (Setting::current())
     * so the many formatting call sites don't each hit the database, without a
     * function-level static that would outlive the tenant in a queue worker.
     *
     * @return int 2 or 3
     */
    public static function price_decimals()
    {
        try {
            return (bool) (Setting::current()->enable_3_decimal_pricing ?? false) ? 3 : 2;
        } catch (\Throwable $e) {
            // Settings table/column may not exist yet (e.g. during migration) — fall back.
            return 2;
        }
    }

    /**
     * Apply the "Resize Product Images" setting to an Intervention image:
     * when the switch is off the image is kept at its original dimensions,
     * otherwise it is fitted into a product_image_max_size box (aspect ratio
     * kept, never upscaled) — the behaviour that used to be hard-coded to
     * 800x800 at every product upload site. Reads the memoised settings row
     * (no function-level static: a queue worker serves many tenants).
     *
     * @param  \Intervention\Image\Image  $image
     * @return \Intervention\Image\Image  the same instance, for chaining ->save()
     */
    public static function apply_product_image_resize($image)
    {
        $max = 800;
        try {
            $setting = Setting::current();
            // Never saved / column missing => keep the historical 800x800.
            $enabled = $setting === null ? true : (bool) ($setting->product_image_resize ?? true);
            $max = $enabled ? max(0, (int) ($setting->product_image_max_size ?? 800)) : 0;
        } catch (\Throwable $e) {
            // Settings table/column may not exist yet (e.g. during migration).
        }

        if ($max > 0) {
            $image->resize($max, $max, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        return $image;
    }

    /**
     * Accepted values of the "Currency Position" setting:
     *   'before'         => "SAR 100" (the default)
     *   'before_nospace' => "SAR100"
     *   'after'          => "100 SAR"
     *   'after_nospace'  => "100SAR"
     * Mirrors CURRENCY_POSITIONS in resources/src/lib/priceFormat.js.
     */
    public const CURRENCY_POSITIONS = ['before', 'before_nospace', 'after', 'after_nospace'];

    /**
     * Where the currency symbol goes relative to the amount ("Currency
     * Position" setting). Unknown/missing values read as 'before'.
     *
     * @return string one of self::CURRENCY_POSITIONS
     */
    public static function currency_position()
    {
        try {
            $value = Setting::current()->currency_position ?? 'before';
        } catch (\Throwable $e) {
            $value = 'before';
        }

        return in_array($value, self::CURRENCY_POSITIONS, true) ? $value : 'before';
    }

    /** True when the symbol goes after the amount ('after' / 'after_nospace'). */
    public static function currency_after(): bool
    {
        return str_starts_with(self::currency_position(), 'after');
    }

    /** True when the symbol touches the amount (the *_nospace positions). */
    public static function currency_nospace(): bool
    {
        return str_ends_with(self::currency_position(), '_nospace');
    }

    /**
     * "Hide Zero Decimals" system setting: whole-number amounts are shown
     * without their fraction (100.00 => 100; 100.50 keeps its decimals).
     * Display only; see format_price().
     *
     * @return bool
     */
    public static function hide_zero_decimals()
    {
        try {
            return (bool) (Setting::current()->hide_zero_decimals ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Attach a currency symbol to an already-formatted amount, honouring the
     * Currency Position setting. An empty symbol returns the amount alone.
     * No Unicode bidi controls (dompdf has no bidi support and would print
     * them as boxes).
     *
     * An Arabic/Hebrew symbol is joined with a no-break space instead of a
     * plain one: the PDF controllers run ArPHP's arIdentify/utf8Glyphs over
     * the HTML, and an Arabic run extends over following spaces and digits,
     * so "ر.س 100.00" was reversed as a whole into "٠٠.١٠٠ س.ر". The no-break
     * space ends the run at the symbol, leaving the amount untouched. Latin
     * symbols keep the ASCII space (the ESC/POS text receipt is single-byte).
     *
     * The *_nospace positions drop the separator for Latin symbols ("$100").
     * An Arabic/Hebrew symbol keeps its no-break space even then: with nothing
     * between them the digits would join the Arabic run and be reversed.
     *
     * @param string|null $symbol
     * @param string $formatted
     * @return string
     */
    public static function with_currency($symbol, $formatted)
    {
        $symbol = trim((string) $symbol);
        if ($symbol === '') {
            return (string) $formatted;
        }

        $rtl = (bool) preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $symbol);
        $sep = $rtl ? "\u{00A0}" : (self::currency_nospace() ? '' : ' ');

        return self::currency_after()
            ? $formatted.$sep.$symbol
            : $symbol.$sep.$formatted;
    }

    /**
     * Format an amount for display: thousands/decimal separators from the
     * Price Format setting, the configured precision (2 or 3) unless
     * $decimals is given, and the Hide Zero Decimals switch (a whole number
     * drops its ".00"; any other fraction is kept). The single PHP-side
     * formatter used by the PDFs, receipts and notification texts; mirrors
     * formatPriceDisplay() in resources/src/lib/priceFormat.js.
     *
     * @param float|string|null $number
     * @param int|null $decimals null = the configured price precision
     * @param string|null $priceFormat 'comma_dot' | 'dot_comma' | 'space_comma' | null (= the setting; '' = default separators)
     * @param bool|null $hideZeroDecimals null = the setting
     * @return string
     */
    public static function format_price($number, $decimals = null, $priceFormat = null, $hideZeroDecimals = null)
    {
        $number = (float) $number;
        $decimals = $decimals === null ? self::price_decimals() : (int) $decimals;
        if ($priceFormat === null) {
            try {
                $priceFormat = Setting::current()->price_format ?? null;
            } catch (\Throwable $e) {
                $priceFormat = null;
            }
        }

        switch ($priceFormat) {
            case 'dot_comma':   // 1.234,56
                $dec = ',';
                $thousands = '.';
                break;
            case 'space_comma': // 1 234,56
                $dec = ',';
                $thousands = ' ';
                break;
            case 'comma_dot':   // 1,234.56
            default:
                $dec = '.';
                $thousands = ',';
        }

        $formatted = number_format($number, $decimals, $dec, $thousands);

        $hide = $hideZeroDecimals === null ? self::hide_zero_decimals() : (bool) $hideZeroDecimals;
        if ($hide && $decimals > 0) {
            $pos = strrpos($formatted, $dec);
            if ($pos !== false && preg_match('/^0+$/', substr($formatted, $pos + 1))) {
                $formatted = substr($formatted, 0, $pos);
            }
        }

        return $formatted;
    }

    /**
     * Legacy name of format_price() (kept for callers outside this repo).
     *
     * @param float $number
     * @param int|null $decimals
     * @param string|null $priceFormat
     * @return string
     */
    public static function formatPriceDisplay($number, $decimals = null, $priceFormat = null)
    {
        return self::format_price($number, $decimals, $priceFormat === null ? '' : $priceFormat);
    }
}
