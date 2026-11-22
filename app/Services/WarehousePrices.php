<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductWarehousePrice;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Different prices per store (System Settings → Features).
 *
 * A product or variant may carry its own retail / wholesale / minimum price
 * in a warehouse. Every reader asks this class for the row of the document's
 * warehouse and falls back to the product's normal price for any NULL field:
 *
 *   $row = WarehousePrices::find($productId, $variantId, $warehouseId);
 *   $price = WarehousePrices::pick($row, 'price', $product->price);
 *
 * With the switch off (or the migration not run) every lookup returns null,
 * so callers behave exactly as before.
 *
 * Applied by: ProductsController::show_product_data (sales, quotations,
 * POS fallback) and ::Products_by_Warehouse (pickers, barcode labels),
 * PosController::GetProductsByParametre (POS grid + offline snapshot) and
 * ::data_draft_convert_sale. Not applied: online store, channel syncs.
 */
class WarehousePrices
{
    public const FIELDS = ['price', 'wholesale_price', 'min_price'];

    /** @var array<string, bool> table-exists memo keyed by tenant */
    private static array $tableMemo = [];

    public static function tableExists(): bool
    {
        $key = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : '__central';
        if (! array_key_exists($key, self::$tableMemo)) {
            self::$tableMemo[$key] = Schema::hasTable('product_warehouse_prices');
        }

        return self::$tableMemo[$key];
    }

    public static function enabled(): bool
    {
        $setting = Setting::current();

        return (bool) ($setting->warehouse_prices_enabled ?? false) && self::tableExists();
    }

    public static function key($productId, $variantId): string
    {
        return (int) $productId.'|'.($variantId ? (int) $variantId : '');
    }

    /**
     * Every store price of one warehouse, keyed "product|variant".
     * Empty while the feature is off.
     *
     * @return array<string, array{price: ?float, wholesale_price: ?float, min_price: ?float}>
     */
    public static function map($warehouseId, ?array $productIds = null): array
    {
        if (! $warehouseId || ! self::enabled()) {
            return [];
        }

        $query = DB::table('product_warehouse_prices')
            ->where('warehouse_id', (int) $warehouseId)
            ->select(['product_id', 'product_variant_id', 'price', 'wholesale_price', 'min_price']);

        if ($productIds !== null) {
            if (empty($productIds)) {
                return [];
            }
            $query->whereIn('product_id', array_map('intval', array_values(array_unique($productIds))));
        }

        $out = [];
        foreach ($query->get() as $r) {
            $out[self::key($r->product_id, $r->product_variant_id)] = self::normaliseRow($r);
        }

        return $out;
    }

    /** One product/variant's store prices in a warehouse, or null. */
    public static function find($productId, $variantId, $warehouseId): ?array
    {
        if (! $warehouseId || ! $productId || ! self::enabled()) {
            return null;
        }

        $r = DB::table('product_warehouse_prices')
            ->where('warehouse_id', (int) $warehouseId)
            ->where('product_id', (int) $productId)
            ->when($variantId, fn ($q) => $q->where('product_variant_id', (int) $variantId),
                fn ($q) => $q->whereNull('product_variant_id'))
            ->orderByDesc('id')
            ->first(['price', 'wholesale_price', 'min_price']);

        return $r ? self::normaliseRow($r) : null;
    }

    /** The store figure for $field when one is set, else $fallback. */
    public static function pick(?array $row, string $field, $fallback)
    {
        return ($row && isset($row[$field]) && $row[$field] !== null) ? $row[$field] : $fallback;
    }

    /** Blank, non-numeric or negative input means "use the normal price". */
    public static function money($value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([' ', "\u{00A0}", "'"], '', trim($value));
            if ($value === '') {
                return null;
            }
            $comma = strrpos($value, ',');
            $dot = strrpos($value, '.');
            if ($comma !== false && $dot !== false) {
                // Both: the last one is the decimal mark ("1.500,50" / "1,500.50").
                $value = $comma > $dot
                    ? str_replace(['.', ','], ['', '.'], $value)
                    : str_replace(',', '', $value);
            } elseif ($comma !== false) {
                // "12,50" is a decimal comma; "1,500" / "1,500,000" are thousands.
                $value = preg_match('/^-?\d+,\d{1,2}$/', $value)
                    ? str_replace(',', '.', $value)
                    : str_replace(',', '', $value);
            }
        }
        if (! is_numeric($value)) {
            return null;
        }
        $f = round((float) $value, 3);

        return $f < 0 ? null : $f;
    }

    // ------------------------------------------------------------------
    // Product form
    // ------------------------------------------------------------------

    /**
     * Rows for the product form's "Store prices" table: only the warehouses
     * the user may see. Variant rows carry the variant code, which is how the
     * form addresses variants that may not have an id yet.
     */
    public static function formRows(int $productId, array $warehouseIds): array
    {
        if (! self::tableExists() || empty($warehouseIds)) {
            return [];
        }

        $rows = ProductWarehousePrice::where('product_id', $productId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderBy('id')
            ->get();

        $variantCodes = ProductVariant::where('product_id', $productId)
            ->whereNull('deleted_at')
            ->pluck('code', 'id');

        $out = [];
        foreach ($rows as $row) {
            if ($row->product_variant_id && ! isset($variantCodes[$row->product_variant_id])) {
                continue; // variant removed since
            }
            $out[self::key($row->product_id, $row->product_variant_id).'|'.$row->warehouse_id] = [
                'warehouse_id' => (int) $row->warehouse_id,
                'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                'variant_code' => $row->product_variant_id ? (string) $variantCodes[$row->product_variant_id] : null,
                'price' => $row->price,
                'wholesale_price' => $row->wholesale_price,
                'min_price' => $row->min_price,
            ];
        }

        return array_values($out);
    }

    /**
     * Replace the product's store prices in $allowedWarehouseIds with the
     * form's rows. Rows for warehouses the user cannot see are left alone.
     * Each payload row: {warehouse_id, variant_code|null, price,
     * wholesale_price, min_price}; a row with no figure is dropped.
     */
    public static function syncFromForm(Product $product, $payload, array $allowedWarehouseIds): void
    {
        if (! self::tableExists()) {
            return;
        }
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload)) {
            return;
        }

        $allowed = array_map('intval', $allowedWarehouseIds);
        $isVariant = $product->type === 'is_variant';
        $liveVariants = $isVariant
            ? ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')
                ->orderBy('id')->get(['id', 'code'])
            : collect();
        $variantIdsByCode = $liveVariants->pluck('id', 'code')->all();
        $liveVariantIds = array_flip($liveVariants->pluck('id')->map(fn ($v) => (int) $v)->all());

        $rows = [];
        $now = now();
        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $wid = (int) ($entry['warehouse_id'] ?? 0);
            if (! in_array($wid, $allowed, true)) {
                continue;
            }

            $variantId = null;
            if ($isVariant) {
                // A saved variant is addressed by id (codes need not be unique
                // within a product); a variant created by this save by its code.
                $givenId = (int) ($entry['product_variant_id'] ?? 0);
                $code = (string) ($entry['variant_code'] ?? '');
                if ($givenId && isset($liveVariantIds[$givenId])) {
                    $variantId = $givenId;
                } elseif ($code !== '' && isset($variantIdsByCode[$code])) {
                    $variantId = (int) $variantIdsByCode[$code];
                } else {
                    continue;
                }
            } elseif (! empty($entry['variant_code']) || ! empty($entry['product_variant_id'])) {
                continue; // left over from a variant layout
            }

            $figures = [];
            foreach (self::FIELDS as $field) {
                $figures[$field] = self::money($entry[$field] ?? null);
            }
            if ($figures['price'] === null && $figures['wholesale_price'] === null && $figures['min_price'] === null) {
                continue;
            }

            $rows[self::key($product->id, $variantId).'|'.$wid] = [
                'product_id' => (int) $product->id,
                'product_variant_id' => $variantId,
                'warehouse_id' => $wid,
                'price' => $figures['price'],
                'wholesale_price' => $figures['wholesale_price'],
                'min_price' => $figures['min_price'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('product_warehouse_prices')
            ->where('product_id', $product->id)
            ->whereIn('warehouse_id', $allowed)
            ->delete();

        if ($rows) {
            DB::table('product_warehouse_prices')->insert(array_values($rows));
        }
    }

    /**
     * Set (or clear) one product/variant's store prices in a warehouse.
     * Only the fields present in $figures are touched; a row left with no
     * figure is removed. Used by the spreadsheet import.
     */
    public static function upsert(int $productId, ?int $variantId, int $warehouseId, array $figures): string
    {
        $base = DB::table('product_warehouse_prices')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId),
                fn ($q) => $q->whereNull('product_variant_id'));

        $existing = (clone $base)->orderByDesc('id')->first();
        $merged = [];
        foreach (self::FIELDS as $field) {
            $merged[$field] = array_key_exists($field, $figures)
                ? $figures[$field]
                : ($existing ? ($existing->{$field} !== null ? (float) $existing->{$field} : null) : null);
        }

        $empty = $merged['price'] === null && $merged['wholesale_price'] === null && $merged['min_price'] === null;

        if ($existing) {
            (clone $base)->where('id', '!=', $existing->id)->delete(); // stray duplicates
            if ($empty) {
                (clone $base)->delete();

                return 'cleared';
            }
            DB::table('product_warehouse_prices')->where('id', $existing->id)
                ->update($merged + ['updated_at' => now()]);

            return 'updated';
        }

        if ($empty) {
            return 'unchanged';
        }

        DB::table('product_warehouse_prices')->insert($merged + [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'created';
    }

    private static function normaliseRow($r): array
    {
        return [
            'price' => $r->price !== null ? (float) $r->price : null,
            'wholesale_price' => $r->wholesale_price !== null ? (float) $r->wholesale_price : null,
            'min_price' => $r->min_price !== null ? (float) $r->min_price : null,
        ];
    }
}
