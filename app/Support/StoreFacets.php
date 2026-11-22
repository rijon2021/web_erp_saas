<?php

namespace App\Support;

use App\Models\StoreSetting;
use App\Services\ProductOptionService;
use Illuminate\Support\Facades\DB;

/**
 * Shop page size / colour filters.
 *
 * Sizes come from a product option named like "Size" (see
 * FashionTheme::SIZE_WORDS) or, for products without options, from variant
 * names that read as sizes (S, M, XL, 38…). Colours come from an option named
 * like "Color". A product matches when ONE of its variants has a selected size
 * AND a selected colour, and — when overselling is off — stock in one of the
 * store's warehouses.
 */
class StoreFacets
{
    /** Option names treated as "colour" (lower-case, exact). */
    public const COLOR_NAMES = ['color', 'colour', 'colors', 'colours', 'couleur', 'farbe', 'cor', 'colore', 'kleur', 'renk', 'kolor', 'لون', 'اللون', 'رنگ'];

    /** Size order for the filter chips; anything else sorts after, numbers ascending. */
    private const SIZE_ORDER = ['XXXS', 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', '5XL', '6XL', 'ONE SIZE', 'ONESIZE', 'OS'];

    /** @var array<string, array> per tenant DB, per request */
    private static array $memo = [];

    /** Selected values from the query string (?size[]=M&color[]=Red). */
    public static function selected($value): array
    {
        return collect(is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]))
            ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
            ->map(fn ($v) => mb_substr(trim((string) $v), 0, 100))
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }

    /** "(LOWER(col) LIKE ? OR …)" for size option names. */
    private static function sizeNameWhere($q, string $column): void
    {
        $q->where(function ($w) use ($column) {
            foreach (FashionTheme::SIZE_WORDS as $word) {
                $w->orWhereRaw("LOWER($column) LIKE ?", ['%'.mb_strtolower($word).'%']);
            }
        });
    }

    private static function colorNameWhere($q, string $column): void
    {
        $q->where(function ($w) use ($column) {
            $w->whereIn(DB::raw("LOWER(TRIM($column))"), self::COLOR_NAMES)
                ->orWhereRaw("LOWER($column) LIKE ?", ['%colo%r%'])
                ->orWhereRaw("LOWER($column) LIKE ?", ['%couleur%']);
        });
    }

    private static function visibleProducts($q, string $alias = 'p'): void
    {
        $q->whereNull("$alias.deleted_at")->where("$alias.is_active", 1)->where("$alias.hide_from_online_store", 0);
        \App\Models\Product::applyStorefrontWarehouses($q, $alias);
    }

    /**
     * Sizes and colours on sale in the store, for the filter chips:
     * ['sizes' => ['XS', 'S', …], 'colors' => [['value' => 'Red', 'swatch' => '#c00'], …]]
     */
    public static function facets(): array
    {
        $key = (string) DB::connection()->getDatabaseName();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $optionsReady = ProductOptionService::tablesReady();

        $sizes = collect();
        if ($optionsReady) {
            $q = DB::table('product_option_values as pov')
                ->join('product_options as po', 'po.id', '=', 'pov.product_option_id')
                ->join('products as p', 'p.id', '=', 'po.product_id');
            self::visibleProducts($q);
            self::sizeNameWhere($q, 'po.name');
            $sizes = $q->distinct()->limit(200)->pluck('pov.value');
        }

        // Legacy flat variant lists: names that read as sizes.
        $legacy = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereNull('pv.deleted_at');
        self::visibleProducts($legacy);
        if ($optionsReady) {
            $legacy->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('product_options as lpo')->whereColumn('lpo.product_id', 'p.id'));
        }
        $legacySizes = $legacy->distinct()->limit(1000)->pluck('pv.name')
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => preg_match(FashionTheme::SIZE_NAME, $n));

        $seen = [];
        $allSizes = [];
        foreach ($sizes->concat($legacySizes) as $v) {
            $k = mb_strtolower(trim((string) $v));
            if ($k !== '' && ! isset($seen[$k])) {
                $seen[$k] = true;
                $allSizes[] = trim((string) $v);
            }
        }

        $colors = [];
        if ($optionsReady) {
            $q = DB::table('product_option_values as pov')
                ->join('product_options as po', 'po.id', '=', 'pov.product_option_id')
                ->join('products as p', 'p.id', '=', 'po.product_id');
            self::visibleProducts($q);
            self::colorNameWhere($q, 'po.name');
            $colors = $q->groupBy('pov.value')
                ->orderByRaw('COUNT(DISTINCT po.product_id) DESC')
                ->limit(24)
                ->get(['pov.value', DB::raw('MAX(pov.swatch) AS swatch')])
                ->map(fn ($r) => ['value' => (string) $r->value, 'swatch' => $r->swatch ?: null])
                ->all();
        }

        return self::$memo[$key] = [
            'sizes' => array_slice(self::sortSizes($allSizes), 0, 40),
            'colors' => $colors,
        ];
    }

    public static function sortSizes(array $values): array
    {
        usort($values, function ($a, $b) {
            $rank = function ($v) {
                $u = mb_strtoupper(trim($v));
                $i = array_search($u, self::SIZE_ORDER, true);
                if ($i !== false) {
                    return [0, $i, ''];
                }
                if (is_numeric(str_replace(',', '.', $u))) {
                    return [1, (float) str_replace(',', '.', $u), ''];
                }

                return [2, 0, $u];
            };

            return $rank($a) <=> $rank($b);
        });

        return $values;
    }

    /** Restrict a products query to products with a buyable variant in the chosen sizes / colours. */
    public static function apply($query, array $sizes, array $colors, StoreSetting $s): void
    {
        if (! $sizes && ! $colors) {
            return;
        }
        $optionsReady = ProductOptionService::tablesReady();
        $stockWarehouses = ($s->allow_overselling ?? true) ? null : $s->activeWarehouseIds();

        $inStock = function ($sub) use ($stockWarehouses) {
            if ($stockWarehouses === null) {
                return;
            }
            $sub->where(function ($w) use ($stockWarehouses) {
                $w->where('products.always_in_stock', 1)
                    ->orWhereExists(fn ($pw) => $pw->select(DB::raw(1))->from('product_warehouse as fpw')
                        ->whereColumn('fpw.product_variant_id', 'fv.id')
                        ->whereNull('fpw.deleted_at')
                        ->where('fpw.qte', '>', 0)
                        ->whereIn('fpw.warehouse_id', $stockWarehouses ?: [0]));
            });
        };
        $hasValue = function ($sub, array $values, string $kind) {
            $sub->whereExists(function ($e) use ($values, $kind) {
                $e->select(DB::raw(1))
                    ->from('product_variant_option_values as '.$kind.'_pvov')
                    ->join('product_option_values as '.$kind.'_pov', $kind.'_pov.id', '=', $kind.'_pvov.product_option_value_id')
                    ->join('product_options as '.$kind.'_po', $kind.'_po.id', '=', $kind.'_pov.product_option_id')
                    ->whereColumn($kind.'_pvov.product_variant_id', 'fv.id')
                    ->whereIn($kind.'_pov.value', $values);
                $kind === 'size' ? self::sizeNameWhere($e, $kind.'_po.name') : self::colorNameWhere($e, $kind.'_po.name');
            });
        };

        $query->where(function ($w) use ($optionsReady, $sizes, $colors, $inStock, $hasValue) {
            if ($optionsReady) {
                $w->orWhereExists(function ($sub) use ($sizes, $colors, $inStock, $hasValue) {
                    $sub->select(DB::raw(1))->from('product_variants as fv')
                        ->whereColumn('fv.product_id', 'products.id')
                        ->whereNull('fv.deleted_at');
                    if ($sizes) {
                        $hasValue($sub, $sizes, 'size');
                    }
                    if ($colors) {
                        $hasValue($sub, $colors, 'color');
                    }
                    $inStock($sub);
                });
            }
            // Legacy flat lists carry sizes in the variant name and no colour.
            if ($sizes && ! $colors) {
                $w->orWhereExists(function ($sub) use ($sizes, $inStock, $optionsReady) {
                    $sub->select(DB::raw(1))->from('product_variants as fv')
                        ->whereColumn('fv.product_id', 'products.id')
                        ->whereNull('fv.deleted_at')
                        ->whereIn('fv.name', $sizes);
                    if ($optionsReady) {
                        $sub->whereNotExists(fn ($n) => $n->select(DB::raw(1))->from('product_options as npo')->whereColumn('npo.product_id', 'products.id'));
                    }
                    $inStock($sub);
                });
            }
            if (! $optionsReady && $colors) {
                $w->whereRaw('1 = 0');
            }
        });
    }
}
