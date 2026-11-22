<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Shopify-style variant options (Color / Size / ...).
 *
 * A product "uses options" only when it has product_options rows. Legacy
 * variant products have none and never reach this service. Variant rows stay
 * the sellable SKUs; the option layer only describes WHICH combination each
 * variant is and generates its display name ("Red / S") into
 * product_variants.name, so every existing reader keeps working.
 */
class ProductOptionService
{
    public const MAX_OPTIONS = 3;

    public const MAX_VALUES_PER_OPTION = 50;

    public const MAX_VARIANTS = 100;

    public const SEPARATOR = ' / ';

    /** @var array<string, bool> tenant database name => tables migrated */
    private static array $tablesReady = [];

    /**
     * True once the tenant's options migration has run. Until then every
     * reader answers "no options" and products behave as legacy flat lists,
     * so the storefront and the product form keep working on a tenant whose
     * database is not migrated yet. Memoised per database (queue workers
     * switch tenants).
     */
    public static function tablesReady(): bool
    {
        $db = (string) DB::connection()->getDatabaseName();

        return self::$tablesReady[$db] ??= Schema::hasTable('product_variant_option_values');
    }

    /** Storefront eager loads for store_variant_options(); none before the migration. */
    public static function storefrontRelations(): array
    {
        return self::tablesReady() ? ['variants.optionValues', 'options.values'] : [];
    }

    /**
     * Normalize the raw `options` request input (JSON string or array) into
     * [['name' => 'Color', 'values' => [['value' => 'Red', 'swatch' => null], ...]], ...].
     * Returns [] when no options were sent (legacy flat list).
     *
     * @throws ValidationException
     */
    public function normalizeOptions($raw): array
    {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || empty($raw)) {
            return [];
        }
        if (! self::tablesReady()) {
            $this->fail('options', 'Variant options need the latest database update. Run the tenant migrations, or use the simple list.');
        }

        $options = [];
        $seenNames = [];
        foreach (array_values($raw) as $opt) {
            $name = trim((string) (is_array($opt) ? ($opt['name'] ?? '') : ''));
            if ($name === '') {
                $this->fail('options', 'Option name cannot be empty.');
            }
            if (mb_strlen($name) > 100) {
                $this->fail('options', 'Option name "'.$name.'" is too long (max 100).');
            }
            $key = mb_strtolower($name);
            if (isset($seenNames[$key])) {
                $this->fail('options', 'Option "'.$name.'" is listed twice.');
            }
            $seenNames[$key] = true;

            $values = [];
            $seenValues = [];
            foreach (array_values((array) ($opt['values'] ?? [])) as $v) {
                if (is_array($v)) {
                    $value = trim((string) ($v['value'] ?? ''));
                    $swatch = isset($v['swatch']) && trim((string) $v['swatch']) !== '' ? trim((string) $v['swatch']) : null;
                    // The storefront prints it into a style attribute: hex colours only.
                    if ($swatch !== null && ! preg_match('/^#[0-9a-f]{3,8}$/i', $swatch)) {
                        $swatch = null;
                    }
                } else {
                    $value = trim((string) $v);
                    $swatch = null;
                }
                if ($value === '') {
                    continue;
                }
                if (mb_strlen($value) > 100) {
                    $this->fail('options', 'Option value "'.$value.'" is too long (max 100).');
                }
                $vk = mb_strtolower($value);
                if (isset($seenValues[$vk])) {
                    continue; // silently drop exact duplicates
                }
                $seenValues[$vk] = true;
                $values[] = ['value' => $value, 'swatch' => $swatch ? mb_substr($swatch, 0, 20) : null];
            }
            if (empty($values)) {
                $this->fail('options', 'Option "'.$name.'" needs at least one value.');
            }
            if (count($values) > self::MAX_VALUES_PER_OPTION) {
                $this->fail('options', 'Option "'.$name.'" has too many values (max '.self::MAX_VALUES_PER_OPTION.').');
            }

            $options[] = ['name' => $name, 'values' => $values];
        }

        if (count($options) > self::MAX_OPTIONS) {
            $this->fail('options', 'A product can have at most '.self::MAX_OPTIONS.' options.');
        }

        return $options;
    }

    /**
     * Normalize one variant's `option_values` (JSON string or array) into
     * [optionName => value]. Keys are matched case-insensitively to $options
     * and re-keyed to the canonical option name.
     */
    public function normalizeVariantValues($raw, array $options): array
    {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $canonical = [];
        foreach ($options as $opt) {
            $canonical[mb_strtolower($opt['name'])] = $opt['name'];
        }

        $out = [];
        foreach ($raw as $name => $value) {
            $k = mb_strtolower(trim((string) $name));
            if (! isset($canonical[$k])) {
                continue;
            }
            $value = trim((string) (is_array($value) ? ($value['value'] ?? '') : $value));
            if ($value === '') {
                continue;
            }
            $out[$canonical[$k]] = $value;
        }

        return $out;
    }

    /**
     * Every variant must carry exactly one known value per option and no two
     * variants may be the same combination.
     *
     * @param  array<int, array>  $variantValues  list of [optionName => value], in request order
     *
     * @throws ValidationException
     */
    public function validateVariants(array $options, array $variantValues): void
    {
        if (empty($options)) {
            return;
        }
        if (count($variantValues) > self::MAX_VARIANTS) {
            $this->fail('variants', 'Too many variants (max '.self::MAX_VARIANTS.'). Reduce the option values.');
        }

        $allowed = [];
        foreach ($options as $opt) {
            $allowed[$opt['name']] = [];
            foreach ($opt['values'] as $v) {
                $allowed[$opt['name']][mb_strtolower($v['value'])] = true;
            }
        }

        $seen = [];
        foreach (array_values($variantValues) as $i => $values) {
            $row = $i + 1;
            foreach ($options as $opt) {
                $name = $opt['name'];
                if (! isset($values[$name])) {
                    $this->fail('variants', 'Variant #'.$row.' has no value for option "'.$name.'".');
                }
                if (! isset($allowed[$name][mb_strtolower($values[$name])])) {
                    $this->fail('variants', 'Variant #'.$row.': "'.$values[$name].'" is not a value of option "'.$name.'".');
                }
            }
            $sig = mb_strtolower($this->title($options, $values));
            if (isset($seen[$sig])) {
                $this->fail('variants', 'Variant "'.$this->title($options, $values).'" is listed twice.');
            }
            $seen[$sig] = true;
        }
    }

    /** Display name of a combination, in option order: "Red / S". */
    public function title(array $options, array $values): string
    {
        $parts = [];
        foreach ($options as $opt) {
            if (isset($values[$opt['name']]) && $values[$opt['name']] !== '') {
                $parts[] = $values[$opt['name']];
            }
        }

        return implode(self::SEPARATOR, $parts);
    }

    /**
     * Upsert the product's options and values, removing what was dropped
     * (pivot rows cascade). Existing option / value rows are matched by name
     * so ids survive edits.
     *
     * @return array<string, array<string, int>> [optionName => [lower(value) => valueId]]
     */
    public function sync(Product $product, array $options): array
    {
        if (! self::tablesReady()) {
            return [];
        }
        if (empty($options)) {
            $this->clear($product);

            return [];
        }

        $existing = ProductOption::where('product_id', $product->id)->with('values')->get();
        $keepOptionIds = [];
        $map = [];

        foreach ($options as $pos => $opt) {
            $row = $existing->first(fn ($e) => mb_strtolower($e->name) === mb_strtolower($opt['name']));
            if ($row) {
                $row->name = $opt['name'];
                $row->position = $pos;
                $row->save();
            } else {
                $row = ProductOption::create([
                    'product_id' => $product->id,
                    'name' => $opt['name'],
                    'position' => $pos,
                ]);
                $row->setRelation('values', collect());
            }
            $keepOptionIds[] = $row->id;

            $keepValueIds = [];
            $map[$opt['name']] = [];
            foreach ($opt['values'] as $vpos => $v) {
                $vrow = $row->values->first(fn ($e) => mb_strtolower($e->value) === mb_strtolower($v['value']));
                if ($vrow) {
                    $vrow->value = $v['value'];
                    $vrow->position = $vpos;
                    $vrow->swatch = $v['swatch'];
                    $vrow->save();
                } else {
                    $vrow = ProductOptionValue::create([
                        'product_option_id' => $row->id,
                        'value' => $v['value'],
                        'position' => $vpos,
                        'swatch' => $v['swatch'],
                    ]);
                }
                $keepValueIds[] = $vrow->id;
                $map[$opt['name']][mb_strtolower($v['value'])] = $vrow->id;
            }

            ProductOptionValue::where('product_option_id', $row->id)
                ->whereNotIn('id', $keepValueIds)
                ->delete();
        }

        ProductOption::where('product_id', $product->id)
            ->whereNotIn('id', $keepOptionIds)
            ->delete();

        return $map;
    }

    /**
     * Point a variant at its combination (replaces any previous assignment).
     *
     * @param  array<string, string>  $values  [optionName => value]
     * @param  array  $map  result of sync()
     */
    public function attach(int $variantId, array $values, array $map): void
    {
        if (! self::tablesReady()) {
            return;
        }
        $ids = [];
        foreach ($values as $name => $value) {
            $id = $map[$name][mb_strtolower($value)] ?? null;
            if ($id) {
                $ids[] = $id;
            }
        }

        DB::table('product_variant_option_values')->where('product_variant_id', $variantId)->delete();
        if ($ids) {
            DB::table('product_variant_option_values')->insert(array_map(fn ($id) => [
                'product_variant_id' => $variantId,
                'product_option_value_id' => $id,
            ], $ids));
        }
    }

    /**
     * Unlink a variant from its combination. product_variants rows are
     * soft-deleted, so the FK cascade never fires: call this when a variant is
     * removed, or the storefront still sees it as a combination.
     */
    public function detach(int $variantId): void
    {
        if (! self::tablesReady()) {
            return;
        }
        DB::table('product_variant_option_values')->where('product_variant_id', $variantId)->delete();
    }

    /** Drop every option of the product (values + pivots cascade). */
    public function clear(Product $product): void
    {
        if (! self::tablesReady()) {
            return;
        }
        ProductOption::where('product_id', $product->id)->delete();
    }

    /** True when the product has at least one option (i.e. is not a legacy flat list). */
    public function hasOptions(int $productId): bool
    {
        if (! self::tablesReady()) {
            return false;
        }
        return ProductOption::where('product_id', $productId)->exists();
    }

    /**
     * API shape: [{id, name, position, values: [{id, value, swatch}]}].
     */
    public function optionsPayload(int $productId): array
    {
        if (! self::tablesReady()) {
            return [];
        }

        return ProductOption::where('product_id', $productId)
            ->with('values')
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'position' => (int) $o->position,
                'values' => $o->values->map(fn ($v) => [
                    'id' => $v->id,
                    'value' => $v->value,
                    'swatch' => $v->swatch,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * [variantId => [optionName => value]] for every variant of the product.
     * Legacy products return [].
     */
    public function variantValuesMap(int $productId): array
    {
        if (! self::tablesReady()) {
            return [];
        }

        $rows = DB::table('product_variant_option_values as pv')
            ->join('product_option_values as v', 'v.id', '=', 'pv.product_option_value_id')
            ->join('product_options as o', 'o.id', '=', 'v.product_option_id')
            ->join('product_variants as var', 'var.id', '=', 'pv.product_variant_id')
            ->where('o.product_id', $productId)
            ->whereNull('var.deleted_at')
            ->orderBy('o.position')->orderBy('o.id')
            ->get(['pv.product_variant_id', 'o.name', 'v.value']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->product_variant_id][$r->name] = $r->value;
        }

        return $map;
    }

    /**
     * Copy options from one product to a duplicate, re-pointing pivots through
     * the old to new variant id map.
     */
    public function cloneTo(Product $from, Product $to, array $variantIdMap): void
    {
        if (! self::tablesReady()) {
            return;
        }
        $options = ProductOption::where('product_id', $from->id)->with('values')->orderBy('position')->get();
        if ($options->isEmpty()) {
            return;
        }

        $valueIdMap = [];
        foreach ($options as $o) {
            $no = ProductOption::create(['product_id' => $to->id, 'name' => $o->name, 'position' => $o->position]);
            foreach ($o->values as $v) {
                $nv = ProductOptionValue::create([
                    'product_option_id' => $no->id,
                    'value' => $v->value,
                    'position' => $v->position,
                    'swatch' => $v->swatch,
                ]);
                $valueIdMap[$v->id] = $nv->id;
            }
        }

        $pivots = DB::table('product_variant_option_values')
            ->whereIn('product_variant_id', array_keys($variantIdMap))
            ->get();
        $rows = [];
        foreach ($pivots as $p) {
            if (isset($variantIdMap[$p->product_variant_id], $valueIdMap[$p->product_option_value_id])) {
                $rows[] = [
                    'product_variant_id' => $variantIdMap[$p->product_variant_id],
                    'product_option_value_id' => $valueIdMap[$p->product_option_value_id],
                ];
            }
        }
        if ($rows) {
            DB::table('product_variant_option_values')->insert($rows);
        }
    }

    /**
     * Variants that still hold stock somewhere, keyed by id => total qty.
     * Used to refuse deleting a variant whose stock would silently vanish.
     *
     * @return array<int, float>
     */
    public function variantsWithStock(array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }

        $rows = DB::table('product_warehouse')
            ->select('product_variant_id', DB::raw('SUM(qte) as total'))
            ->whereIn('product_variant_id', $variantIds)
            ->whereNull('deleted_at')
            ->groupBy('product_variant_id')
            ->havingRaw('SUM(qte) <> 0')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->product_variant_id] = (float) $r->total;
        }

        return $out;
    }

    /**
     * Options as [['name' => 'Color', 'values' => ['Red', 'Blue']], ...] (plain strings).
     */
    public function optionNamesAndValues(int $productId): array
    {
        return array_map(
            fn ($o) => ['name' => $o['name'], 'values' => array_column($o['values'], 'value')],
            $this->optionsPayload($productId)
        );
    }

    /**
     * WooCommerce parent "attributes" for a variable product: one attribute per
     * option when the product defines options, else the legacy single
     * "Variant" attribute listing every variant name.
     */
    public function wooParentAttributes(int $productId, array $variantNames): array
    {
        $options = $this->optionNamesAndValues($productId);
        if ($options) {
            return array_map(fn ($o) => [
                'name' => $o['name'],
                'visible' => true,
                'variation' => true,
                'options' => array_values($o['values']),
            ], $options);
        }

        return [[
            'name' => 'Variant',
            'visible' => true,
            'variation' => true,
            'options' => array_values($variantNames),
        ]];
    }

    /**
     * WooCommerce variation "attributes" for one variant: [{name, option}] per
     * option, or the legacy single "Variant" attribute.
     *
     * @param  array|null  $valuesMap  variantValuesMap() result, pass it to avoid one query per variant
     */
    public function wooVariationAttributes(int $productId, int $variantId, string $fallbackOption, ?array $valuesMap = null): array
    {
        $valuesMap = $valuesMap ?? $this->variantValuesMap($productId);
        $values = $valuesMap[$variantId] ?? [];
        if ($values) {
            $out = [];
            foreach ($values as $name => $value) {
                $out[] = ['name' => $name, 'option' => $value];
            }

            return $out;
        }

        return [['name' => 'Variant', 'option' => $fallbackOption]];
    }

    /**
     * Shopify "options" plus per-variant option1..option3 for a variable
     * product. Falls back to a single "Variant" option carrying the names.
     *
     * @param  iterable  $variants  ProductVariant rows
     * @return array{options: array, perVariant: array<int, array>}
     */
    public function shopifyOptions(int $productId, $variants): array
    {
        $options = array_slice($this->optionNamesAndValues($productId), 0, 3);
        $per = [];
        if ($options) {
            $valuesMap = $this->variantValuesMap($productId);
            foreach ($variants as $v) {
                $row = [];
                foreach ($options as $i => $o) {
                    $val = $valuesMap[$v->id][$o['name']] ?? null;
                    if ($val !== null) {
                        $row['option'.($i + 1)] = $val;
                    }
                }
                $per[$v->id] = $row ?: ['option1' => $v->name];
            }

            return [
                'options' => array_map(fn ($o) => ['name' => $o['name'], 'values' => array_values($o['values'])], $options),
                'perVariant' => $per,
            ];
        }

        $names = [];
        foreach ($variants as $v) {
            $per[$v->id] = ['option1' => $v->name];
            $names[] = $v->name;
        }

        return ['options' => [['name' => 'Variant', 'values' => array_values($names)]], 'perVariant' => $per];
    }

    /**
     * Define a product's options from per-variant named values and attach
     * them (CSV import, WooCommerce pull). Option and value order follow first
     * appearance. Variant names are left as they are unless $rewriteNames.
     *
     * @param  array<int, array<string, string>>  $namedValuesByVariantId  [variantId => [optionName => value]]
     * @return bool false when nothing usable was given
     *
     * @throws ValidationException on option limits
     */
    public function importNamedValues(Product $product, array $namedValuesByVariantId, bool $rewriteNames = false): bool
    {
        if (! self::tablesReady()) {
            return false;
        }
        $byKey = [];
        $order = [];
        foreach ($namedValuesByVariantId as $values) {
            foreach ((array) $values as $name => $value) {
                $name = trim((string) $name);
                $value = trim((string) $value);
                if ($name === '' || $value === '') {
                    continue;
                }
                $k = mb_strtolower($name);
                if (! isset($byKey[$k])) {
                    $byKey[$k] = ['name' => $name, 'values' => []];
                    $order[] = $k;
                }
                $lower = array_map('mb_strtolower', $byKey[$k]['values']);
                if (! in_array(mb_strtolower($value), $lower, true)) {
                    $byKey[$k]['values'][] = $value;
                }
            }
        }
        if (! $order) {
            return false;
        }

        $list = array_slice(array_map(fn ($k) => $byKey[$k], $order), 0, self::MAX_OPTIONS);
        $normalized = $this->normalizeOptions($list);
        $map = $this->sync($product, $normalized);

        foreach ($namedValuesByVariantId as $variantId => $values) {
            $vals = $this->normalizeVariantValues((array) $values, $normalized);
            $this->attach((int) $variantId, $vals, $map);
            if ($rewriteNames && count($vals) === count($normalized)) {
                ProductVariant::where('id', (int) $variantId)->update(['name' => $this->title($normalized, $vals)]);
            }
        }

        return true;
    }

    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
