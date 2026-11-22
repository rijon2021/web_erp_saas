<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product codes Stocky generates: prefix + running number (System Settings >
 * Prefixes), so they never collide with codes assigned by another system —
 * e.g. prefix "STK-" while the shop's own catalogue uses plain numbers.
 *
 * Only for symbologies that accept free text (CODE128, CODE39); EAN / UPC
 * need digits with a check digit, so those keep the random generator. Taken
 * numbers (a product, variant or extra barcode) are skipped, and the counter
 * moves past each code handed out.
 */
class ProductCodeGenerator
{
    public const FREE_TEXT_SYMBOLOGIES = ['CODE128', 'CODE39'];

    /** The next code, or null when numbering is off / not applicable. */
    public static function next(?string $symbology = 'CODE128'): ?string
    {
        if (! Schema::hasColumn('settings', 'product_code_prefix')) {
            return null;
        }
        if ($symbology !== null && ! in_array(strtoupper($symbology), self::FREE_TEXT_SYMBOLOGIES, true)) {
            return null;
        }

        return DB::transaction(function () use ($symbology) {
            $settings = DB::table('settings')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->first(['id', 'product_code_prefix', 'product_code_next']);
            if (! $settings || (($settings->product_code_prefix ?? '') === '' && ! $settings->product_code_next)) {
                return null;
            }

            $prefix = (string) ($settings->product_code_prefix ?? '');
            if (strtoupper((string) $symbology) === 'CODE39') {
                $prefix = strtoupper($prefix); // CODE39 has no lower case
            }
            $number = max((int) $settings->product_code_next, 1);
            for ($guard = 0; $guard < 100000; $guard++, $number++) {
                $code = $prefix.$number;
                if (! self::taken($code)) {
                    DB::table('settings')->where('id', $settings->id)->update(['product_code_next' => $number + 1]);
                    \App\Models\Setting::forgetCurrent();

                    return $code;
                }
            }

            return null;
        });
    }

    public static function taken(string $code): bool
    {
        return DB::table('products')->whereNull('deleted_at')->where('code', $code)->exists()
            || DB::table('product_variants')->whereNull('deleted_at')->where('code', $code)->exists()
            || ProductBarcodes::owner($code) !== null;
    }
}
