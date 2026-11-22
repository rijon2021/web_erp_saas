<?php

use App\Models\Product;
use App\Services\ProductOptionService;

if (! function_exists('store_variant_options')) {
    /**
     * Storefront payload describing a variable product's Shopify-style options.
     *
     * Returns null for products without options (legacy flat variant list), so
     * the storefront falls back to the plain variant chips. Otherwise:
     *
     *   [
     *     'options' => [['name' => 'Color', 'values' => [['value' => 'Red', 'swatch' => null], ...]], ...],
     *     'map'     => [variantId => ['Red', 'S'], ...]   // values in option order
     *   ]
     *
     * Uses the eager-loaded `options.values` and `variants.optionValues`
     * relations when present (listing pages) and falls back to two queries
     * otherwise (single product contexts).
     */
    function store_variant_options($product): ?array
    {
        if (! $product instanceof Product || ! ProductOptionService::tablesReady()) {
            return null;
        }

        // ---- options (ordered) ----
        if ($product->relationLoaded('options')) {
            $options = $product->options;
        } else {
            $options = $product->options()->with('values')->get();
        }
        if ($options->isEmpty()) {
            return null;
        }

        $optionIndexByValueId = [];
        $payloadOptions = [];
        foreach ($options->values() as $i => $opt) {
            $values = $opt->relationLoaded('values') ? $opt->values : $opt->values()->get();
            $payloadOptions[] = [
                'name' => (string) $opt->name,
                'values' => $values->map(fn ($v) => [
                    'value' => (string) $v->value,
                    'swatch' => $v->swatch ?: null,
                ])->values()->all(),
            ];
            foreach ($values as $v) {
                $optionIndexByValueId[(int) $v->id] = [$i, (string) $v->value];
            }
        }
        $count = count($payloadOptions);

        // ---- per-variant values in option order ----
        $map = [];
        $variants = $product->relationLoaded('variants') ? $product->variants : collect();
        $allLoaded = $variants->isNotEmpty() && $variants->every(fn ($v) => $v->relationLoaded('optionValues'));

        if ($allLoaded) {
            foreach ($variants as $variant) {
                // Removed (soft-deleted) variants carry no option links.
                if ($variant->optionValues->isEmpty()) {
                    continue;
                }
                $row = array_fill(0, $count, null);
                foreach ($variant->optionValues as $val) {
                    if (isset($optionIndexByValueId[(int) $val->id])) {
                        [$idx, $text] = $optionIndexByValueId[(int) $val->id];
                        $row[$idx] = $text;
                    }
                }
                $map[(int) $variant->id] = $row;
            }
        } else {
            $byName = app(ProductOptionService::class)->variantValuesMap((int) $product->id);
            $nameIndex = [];
            foreach ($payloadOptions as $i => $o) {
                $nameIndex[$o['name']] = $i;
            }
            foreach ($byName as $variantId => $values) {
                $row = array_fill(0, $count, null);
                foreach ($values as $name => $text) {
                    if (isset($nameIndex[$name])) {
                        $row[$nameIndex[$name]] = (string) $text;
                    }
                }
                $map[(int) $variantId] = $row;
            }
        }

        if (empty($map)) {
            return null;
        }

        return ['options' => $payloadOptions, 'map' => $map];
    }
}
