<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\VehicleSpec;
use App\Support\CarsTheme;
use App\Support\VehicleCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Car Dealership storefront theme. Active when StoreSetting->theme === 'cars';
 * StoreFrontController hands it the homepage, the shop page (vehicle
 * inventory with spec filters) and the product page (vehicle page). Cart,
 * checkout and account pages stay shared. Prices go through the parent's
 * pricing/stock pipeline so they match the rest of the store.
 */
class CarsStoreController extends ElectronicsStoreController
{
    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = CarsTheme::options($s);
        $sections = $opts['sections'];
        $limits = $opts['limits'];
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);
        $reserveId = $opts['reserve_product_id'];

        $slides = collect($opts['slides'])->map(function ($row) {
            $row['image_url'] = CarsTheme::imageUrl($row['image'] ?? '');
            $row['primary_href'] = CarsTheme::link($row['primary_url'] ?? '/shop');
            $row['secondary_href'] = CarsTheme::link($row['secondary_url'] ?? '/shop');

            return $row;
        })->values();

        $listed = function () use ($reserveId) {
            $q = $this->baseQuery();
            VehicleCatalog::onSale($q, $reserveId);

            return $q;
        };

        // Featured: the owner's featured cars, topped up with the newest.
        $featured = collect();
        if ($sections['featured']) {
            $featured = $this->hydrate($listed()->where('products.is_featured', 1)->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limits['featured']);
            if ($featured->count() < $limits['featured']) {
                $have = $featured->pluck('id')->all();
                $more = $this->hydrate($listed()->when($have, fn ($q) => $q->whereNotIn('products.id', $have))->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limits['featured'] - $featured->count());
                $featured = $featured->concat($more)->values();
            }
        }

        $latest = collect();
        if ($sections['latest']) {
            $skip = $featured->pluck('id')->all();
            $latest = $this->hydrate($listed()->when($skip, fn ($q) => $q->whereNotIn('products.id', $skip))->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limits['latest']);
        }
        $this->attachSpecs($featured->concat($latest));

        $stocked = $this->stockedIds($s);
        $facets = VehicleCatalog::facets($reserveId, '', $stocked);
        $makes = collect($facets['makes'])->sortByDesc('count')->take($limits['makes'])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return view('store.cars.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $sections,
            'slides' => $slides,
            'featured' => $featured,
            'latest' => $latest,
            'facets' => $facets,
            'makes' => $makes,
            'makeModels' => VehicleCatalog::makeModels($reserveId, $stocked),
            'priceSteps' => $this->priceSteps($reserveId),
            'categories' => $this->storeCategories(),
            'showCategoryBar' => false,
        ]);
    }

    /** Shop page: vehicle inventory with spec filters. */
    public function inventory(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = CarsTheme::options($s);
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);
        $f = VehicleCatalog::selected($request);
        [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();

        $query = $this->baseQuery()
            ->leftJoinSub($minVariantSub, 'pvmin', fn ($join) => $join->on('pvmin.product_id', '=', 'products.id'))
            ->addSelect(
                'products.*',
                DB::raw("$baseExpr AS base_price"),
                DB::raw("$afterDiscountExpr AS after_discount"),
                DB::raw("$finalExpr AS final_display_price")
            );
        if (VehicleSpec::tablesReady()) {
            $query->with('vehicleSpec');
        }
        $stocked = $this->stockedIds($s);
        if ($stocked !== null) {
            $query->whereIn('products.id', $stocked ?: [0]);
        }
        VehicleCatalog::onSale($query, $opts['reserve_product_id']);
        VehicleCatalog::apply($query, $f, $finalExpr);

        $products = $query->paginate(12)->withQueryString();
        $this->applyDisplayPrices($products->getCollection(), $defaultTaxRate);
        $this->markCompareAt($products->getCollection(), $defaultTaxRate);
        $this->attachStockToProducts($products, $s->activeWarehouseIds());

        $facets = VehicleCatalog::facets($opts['reserve_product_id'], $f['make'], $stocked);
        $query = array_filter($request->query(), fn ($v) => $v !== null && $v !== '' && $v !== []);

        return view('store.cars.inventory', [
            's' => $s,
            'opts' => $opts,
            'products' => $products,
            'f' => $f,
            'facets' => $facets,
            'chips' => VehicleCatalog::chips($f, route('store.shop'), $query),
            'priceSteps' => $this->priceSteps($opts['reserve_product_id']),
            'categories' => $this->storeCategories(),
            'showCategoryBar' => false,
        ]);
    }

    /** Product page: gallery, specs, finance calculator and the lead forms. */
    public function show(Request $request, $id)
    {
        $s = StoreSetting::firstOrFail();
        $opts = CarsTheme::options($s);
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);

        $product = $this->pricedProduct($s, (int) $id, $defaultTaxRate);
        if (! $product) {
            abort(404);
        }
        $this->attachSpecs(collect([$product]));
        $spec = $product->getRelation('vehicleSpec');

        $similar = $this->similar($s, $product, $spec, $opts, $defaultTaxRate);

        // Reservation: the owner's deposit product, priced like any other product.
        $reserve = null;
        if ($opts['enable_reserve'] && $opts['reserve_product_id'] && (int) $opts['reserve_product_id'] !== (int) $product->id
            && (! $spec || ($spec->reservable && ! $spec->is_sold))) {
            $deposit = $this->pricedProduct($s, (int) $opts['reserve_product_id'], $defaultTaxRate);
            if ($deposit) {
                $reserve = [
                    'id' => (int) $deposit->id,
                    'name' => $deposit->name,
                    'price' => (float) $deposit->display_price,
                    'image' => product_image_url($deposit->primaryProductImageFilename()),
                ];
            }
        }

        $title = $product->name;
        $canonical = route('store.product.show', $product->id);
        $imageUrls = collect($product->productGalleryFilenames())->map(fn ($f) => product_image_url_or_null($f))->filter()->values()->all();
        $metaDesc = trim(\Illuminate\Support\Str::limit(strip_tags((string) $product->note), 160))
            ?: ($spec ? trim($spec->headline().' '.($spec->trim ?? '')) : $title).' — '.($s->store_name ?? '');

        $ld = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $spec ? 'Car' : 'Product',
            'name' => $title,
            'description' => $metaDesc,
            'image' => $imageUrls ?: null,
            'brand' => $spec && $spec->make ? ['@type' => 'Brand', 'name' => $spec->make] : null,
            'model' => $spec->model ?? null,
            'vehicleModelDate' => $spec && $spec->year ? (string) $spec->year : null,
            'bodyType' => $spec && $spec->body_type ? __('messages.CarBody_'.$spec->body_type) : null,
            'fuelType' => $spec && $spec->fuel ? __('messages.CarFuel_'.$spec->fuel) : null,
            'vehicleTransmission' => $spec && $spec->transmission ? __('messages.CarGear_'.$spec->transmission) : null,
            'color' => $spec->exterior_color ?? null,
            'itemCondition' => $spec ? 'https://schema.org/'.($spec->condition === 'new' ? 'NewCondition' : 'UsedCondition') : null,
            'mileageFromOdometer' => $spec && $spec->mileage !== null ? ['@type' => 'QuantitativeValue', 'value' => $spec->mileage, 'unitCode' => $opts['mileage_unit'] === 'mi' ? 'SMI' : 'KMT'] : null,
            'offers' => array_filter([
                '@type' => 'Offer',
                'price' => (string) round((float) $product->display_price, 2),
                'priceCurrency' => strtoupper((string) (store_currency()['code'] ?? '')) ?: null,
                'availability' => 'https://schema.org/'.(($spec && $spec->is_sold) || ! $this->productHasStock($product) ? 'SoldOut' : 'InStock'),
                'url' => $canonical,
            ], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);

        return view('store.cars.show', [
            's' => $s,
            'opts' => $opts,
            'product' => $product,
            'spec' => $spec,
            'similar' => $similar,
            'reserve' => $reserve,
            'categories' => $this->storeCategories(),
            'showCategoryBar' => false,
            'seo' => [
                'title' => $title,
                'description' => $metaDesc,
                'canonical' => $canonical,
                'type' => 'product',
                'image' => $imageUrls[0] ?? null,
                'jsonld' => $ld,
            ],
        ]);
    }

    /**
     * One storefront-visible product through the shared pricing pipeline.
     * Unlike hydrate() it ignores "hide out of stock": a sold-out car keeps
     * its page (shown as sold) and the deposit product is a stock-less service.
     */
    protected function pricedProduct(StoreSetting $s, int $id, float $defaultTaxRate)
    {
        [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();
        $product = $this->baseQuery()
            ->where('products.id', $id)
            ->leftJoinSub($minVariantSub, 'pvmin', fn ($join) => $join->on('pvmin.product_id', '=', 'products.id'))
            ->addSelect(
                'products.*',
                DB::raw("$baseExpr AS base_price"),
                DB::raw("$afterDiscountExpr AS after_discount"),
                DB::raw("$finalExpr AS final_display_price")
            )
            ->first();
        if (! $product) {
            return null;
        }
        $one = collect([$product]);
        $this->applyDisplayPrices($one, $defaultTaxRate);
        $this->markCompareAt($one, $defaultTaxRate);
        $this->attachStockToProducts($one, $s->activeWarehouseIds());

        return $product;
    }

    /** Same body type (else same make), closest in price, then anything newest. */
    protected function similar(StoreSetting $s, $product, ?VehicleSpec $spec, array $opts, float $defaultTaxRate)
    {
        $limit = 4;
        $listed = function () use ($opts, $product) {
            $q = $this->baseQuery()->where('products.id', '!=', $product->id);
            VehicleCatalog::onSale($q, $opts['reserve_product_id']);

            return $q;
        };
        $price = (float) $product->display_price;
        $out = collect();
        if ($spec && VehicleSpec::tablesReady() && ($spec->body_type || $spec->make)) {
            $q = $listed()->whereExists(function ($sub) use ($spec) {
                $sub->select(DB::raw(1))->from('vehicle_specs')->whereColumn('vehicle_specs.product_id', 'products.id')
                    ->where(fn ($w) => $w->when($spec->body_type, fn ($x) => $x->where('body_type', $spec->body_type))
                        ->when($spec->make, fn ($x) => $x->orWhere('make', $spec->make)));
            });
            // Same price basis as $price (display price: after discount and tax).
            [, , , $finalExpr] = $this->priceSqlParts();
            $q->orderByRaw("ABS($finalExpr - ?)", [$price]);
            $out = $this->hydrate($q, $s, $defaultTaxRate, $limit);
        }
        if ($out->count() < $limit) {
            $have = $out->pluck('id')->all();
            $out = $out->concat($this->hydrate($listed()->when($have, fn ($q) => $q->whereNotIn('products.id', $have))->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limit - $out->count()))->values();
        }
        $this->attachSpecs($out);

        return $out;
    }

    /** Eager-load vehicle specs onto already-fetched products (null relation before the migration). */
    protected function attachSpecs($products): void
    {
        if ($products->isEmpty()) {
            return;
        }
        if (! VehicleSpec::tablesReady()) {
            $products->each(fn ($p) => $p->setRelation('vehicleSpec', null));

            return;
        }
        $specs = VehicleSpec::whereIn('product_id', $products->pluck('id')->all())->get()->keyBy('product_id');
        $products->each(fn ($p) => $p->setRelation('vehicleSpec', $specs[$p->id] ?? null));
    }

    /** Strike-through price for discounted products (same rule as hydrate()). */
    protected function markCompareAt($products, float $defaultTaxRate): void
    {
        foreach ($products as $p) {
            if (isset($p->compare_at_price) || (float) ($p->discount ?? 0) <= 0) {
                continue;
            }
            $original = (float) ($p->base_price ?? $p->price);
            $taxRate = is_numeric($p->TaxNet) ? (float) $p->TaxNet : $defaultTaxRate;
            if ((string) $p->tax_method !== '2' && $taxRate > 0) {
                $original *= 1 + $taxRate / 100;
            }
            $original = round($original, 2);
            if ($original > (float) $p->display_price + 0.01) {
                $p->compare_at_price = $original;
            }
        }
    }

    /**
     * Products the store may list when "hide out of stock" is on (null = no
     * restriction), so the facet counts match what the listing shows.
     */
    protected function stockedIds(StoreSetting $s): ?array
    {
        if (! $s->hide_out_of_stock || ! ($warehouseIds = $s->activeWarehouseIds())) {
            return null;
        }

        return $this->getInStockProductIds($warehouseIds);
    }

    /**
     * "Max price" options: round steps up to the dearest car on sale, on the
     * same final price (after discount and tax) the price filter compares.
     * Base currency.
     */
    protected function priceSteps(?int $reserveId): array
    {
        [$minVariantSub, , , $finalExpr] = $this->priceSqlParts();
        $max = (float) Product::query()->whereNull('products.deleted_at')->where('products.is_active', 1)->where('products.hide_from_online_store', 0)->forStorefrontWarehouses()
            ->when($reserveId, fn ($q) => $q->where('products.id', '!=', $reserveId))
            ->leftJoinSub($minVariantSub, 'pvmin', fn ($join) => $join->on('pvmin.product_id', '=', 'products.id'))
            ->selectRaw("MAX($finalExpr) AS m")
            ->value('m');
        if ($max <= 0) {
            return [];
        }
        $steps = [];
        foreach ([1, 2, 2.5, 5] as $m) {
            for ($e = 2; $e <= 8; $e++) {
                $steps[] = $m * (10 ** $e);
            }
        }
        sort($steps);
        $out = [];
        foreach ($steps as $v) {
            if ($v >= $max / 8) {
                $out[] = (int) $v;
            }
            if ($v >= $max) {
                break;
            }
        }

        return array_slice($out, -8);
    }
}
