<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Support\PremiumElectronicsTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Electronics Premium storefront theme (plan-gated, see StoreThemes::PREMIUM).
 *
 * Active when StoreSetting->theme resolves to 'electronics_premium' — the
 * model accessor already swapped it for the free Electronics theme when the
 * plan lost the premium_themes feature, so nothing here re-checks the plan.
 * Shop, cart, checkout and account pages keep their controllers; the layout
 * swaps in this theme's head/header/footer.
 */
class PremiumElectronicsStoreController extends StoreFrontController
{
    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = PremiumElectronicsTheme::options($s);
        $sections = $opts['sections'];
        $limits = $opts['limits'];
        $tax = (float) ($s->default_tax_rate ?? 0);
        $used = [];

        // ----- Hero: the owner's slides, else the newest featured product -----
        $slides = collect($opts['slides'])->map(function ($row) {
            $row['image_url'] = PremiumElectronicsTheme::imageUrl($row['image'] ?? '');
            $row['primary_href'] = PremiumElectronicsTheme::link($row['primary_url'] ?? '/shop');
            $row['secondary_href'] = ($row['secondary_text'] ?? '') !== '' ? PremiumElectronicsTheme::link($row['secondary_url'] ?? '/shop') : null;
            $row['video_embed'] = PremiumElectronicsTheme::embedUrl($row['video_url'] ?? '');
            $row['video_link'] = ($row['video_url'] ?? '') !== '' && PremiumElectronicsTheme::safeUrl($row['video_url']) ? $row['video_url'] : null;

            return $row;
        })->values();

        $heroProduct = null;
        if ($slides->isEmpty()) {
            $heroProduct = $this->hydrate(
                $this->withImage($this->baseQuery())->orderByDesc('products.is_featured')->orderByDesc('products.created_at'),
                $s, $tax, 1
            )->first();
            if ($heroProduct) {
                $used[] = $heroProduct->id;
            }
        }

        // ----- Side tiles: the owner's two banners, else a top deal + a new arrival -----
        $banners = collect($opts['banners'])->take(2)->map(function ($row) {
            $row['image_url'] = PremiumElectronicsTheme::imageUrl($row['image'] ?? '');
            $row['href'] = PremiumElectronicsTheme::link($row['url'] ?? '/shop');

            return $row;
        })->values();

        $tileProducts = collect();
        if ($banners->isEmpty()) {
            $deal = $this->hydrate(
                $this->withImage($this->baseQuery())->where('products.discount', '>', 0)
                    ->when($used, fn ($q) => $q->whereNotIn('products.id', $used))
                    ->orderByDesc('products.discount'),
                $s, $tax, 1
            )->first();
            if ($deal) {
                $used[] = $deal->id;
                $tileProducts->push(['kind' => 'deal', 'p' => $deal]);
            }
            $fresh = $this->hydrate(
                $this->withImage($this->baseQuery())
                    ->when($used, fn ($q) => $q->whereNotIn('products.id', $used))
                    ->orderByDesc('products.created_at'),
                $s, $tax, 2 - $tileProducts->count()
            );
            foreach ($fresh as $p) {
                $tileProducts->push(['kind' => 'new', 'p' => $p]);
            }
        }

        // ----- Deals: a running flash sale (real countdown), else discounted products -----
        $deals = ['products' => collect(), 'ends_at' => null, 'title' => null];
        if ($sections['deals']) {
            $sale = Schema::hasTable('flash_sales')
                ? FlashSale::running()->orderBy('sort_order')->orderBy('ends_at')->first()
                : null;
            if ($sale) {
                [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();
                $flash = $this->buildFlashProducts($s, $baseExpr, $afterDiscountExpr, $finalExpr, $minVariantSub, $tax, $limits['deals'] * 2);
                if ($s->hide_out_of_stock ?? false) {
                    $flash = $flash->filter(fn ($p) => $this->productHasStock($p))->values();
                }
                $flash = $flash->take($limits['deals'])->values();
                $this->withBrands($flash);
                $this->attachRatings($flash);
                $deals = [
                    'products' => $flash,
                    'ends_at' => $sale->ends_at,
                    'title' => method_exists($sale, 'localizedName') ? $sale->localizedName() : $sale->name,
                ];
            }
            if ($deals['products']->isEmpty()) {
                $deals['products'] = $this->hydrate(
                    $this->baseQuery()->where('products.discount', '>', 0)->orderByDesc('products.discount')->orderByDesc('products.created_at'),
                    $s, $tax, $limits['deals']
                );
                $deals['ends_at'] = null;
            }
        }

        // ----- Categories: the biggest ones, tile image = newest product photo -----
        $categories = $this->storeCategories();
        $categoryTiles = collect();
        if ($sections['categories'] && $categories->isNotEmpty()) {
            $counts = Product::query()
                ->whereNull('deleted_at') // tenant models soft-delete by hand
                ->where('is_active', 1)
                ->where('hide_from_online_store', 0)->forStorefrontWarehouses()
                ->select('category_id', DB::raw('COUNT(*) AS c'))
                ->groupBy('category_id')
                ->pluck('c', 'category_id');

            $categoryTiles = $categories
                ->map(fn ($c) => ['c' => $c, 'count' => (int) ($counts[$c->id] ?? 0)])
                ->filter(fn ($t) => $t['count'] > 0)
                ->sortByDesc('count')
                ->take($limits['categories'])
                ->map(function ($t) {
                    $c = $t['c'];
                    $img = $this->withImage(Product::query()
                        ->whereNull('deleted_at')
                        ->where('category_id', $c->id)
                        ->where('is_active', 1)
                        ->where('hide_from_online_store', 0)->forStorefrontWarehouses())
                        ->orderByDesc('is_featured')
                        ->orderByDesc('created_at')
                        ->value('image');

                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'icon' => $c->icon,
                        'count' => $t['count'],
                        'image_url' => $img ? product_image_url($img) : null,
                        'url' => route('store.shop', ['category' => $c->id]),
                    ];
                })
                ->values();
        }

        // ----- "Picked for you" tabs -----
        $picks = [];
        if ($sections['picks']) {
            $n = $limits['picks'];
            $picks['top_picks'] = $this->hydrate($this->baseQuery()->orderByDesc('products.is_featured')->orderByDesc('products.created_at'), $s, $tax, $n);
            $picks['new_arrivals'] = $this->hydrate($this->baseQuery()->orderByDesc('products.created_at')->orderByDesc('products.id'), $s, $tax, $n);

            [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();
            $best = $this->buildBestSellerProducts($s, $baseExpr, $afterDiscountExpr, $finalExpr, $minVariantSub, $tax, $n);
            $this->withBrands($best);
            $this->attachRatings($best);
            $picks['best_sellers'] = $best;

            $ratingSub = $this->ratingSubquery();
            $picks['top_rated'] = $ratingSub
                ? $this->hydrate(
                    $this->baseQuery()
                        ->joinSub($ratingSub, 'pr', fn ($j) => $j->on('pr.product_id', '=', 'products.id'))
                        ->orderByDesc('pr.rating_avg')
                        ->orderByDesc('pr.rating_count'),
                    $s, $tax, $n
                )
                : collect();
            $picks = array_filter($picks, fn ($list) => $list->isNotEmpty());
        }

        // ----- Brands -----
        $brands = $sections['brands']
            ? Brand::query()->whereNull('deleted_at')->orderBy('name')->take(12)->get()
            : collect();

        return view('store.electronics_premium.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $sections,
            'slides' => $slides,
            'heroProduct' => $heroProduct,
            'banners' => $banners,
            'tileProducts' => $tileProducts,
            'deals' => $deals,
            'categories' => $categories,
            'categoryTiles' => $categoryTiles,
            'picks' => $picks,
            'brands' => $brands,
            'showCategoryBar' => false,
        ]);
    }

    /**
     * /compare?ids=1,2,3 — up to 4 products side by side. The list itself lives
     * in the visitor's browser (window.PxCompare); the page script adds ?ids=
     * when it is missing. Other themes are sent to the shop.
     */
    public function compare(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        if (($s->theme ?? 'default') !== PremiumElectronicsTheme::KEY) {
            return redirect(store_route_or('store.shop'));
        }
        $tax = (float) ($s->default_tax_rate ?? 0);

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($v) => (int) trim($v))->filter(fn ($v) => $v > 0)->unique()->take(4)->values()->all();

        $products = collect();
        if ($ids) {
            $order = array_flip($ids);
            $products = $this->hydrate($this->baseQuery()->whereIn('products.id', $ids), $s, $tax, 4)
                ->sortBy(fn ($p) => $order[$p->id] ?? 99)->values();
        }

        $maps = $products->map(fn ($p) => \App\Support\StoreSpecs::map($p))->all();
        $labels = \App\Support\StoreSpecs::filterLabels(PremiumElectronicsTheme::options($s));
        foreach ($maps as $m) {
            foreach (array_keys($m) as $l) {
                if (! in_array($l, $labels, true)) {
                    $labels[] = $l;
                }
            }
        }
        $rows = [];
        foreach ($labels as $label) {
            $vals = array_map(fn ($m) => $m[$label] ?? null, $maps);
            $present = array_values(array_filter($vals, fn ($v) => $v !== null));
            if (! $present) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'values' => $vals,
                'differs' => count(array_unique(array_map('mb_strtolower', $present))) > 1 || count($present) < count($vals),
            ];
        }

        return view('store.electronics_premium.compare', [
            's' => $s,
            'products' => $products,
            'rows' => $rows,
            'hasIds' => $request->has('ids'),
            'categories' => $this->storeCategories(),
            'showCategoryBar' => false,
            'seo' => ['title' => __('messages.PxCompareProducts'), 'robots' => 'noindex'],
        ]);
    }

    /**
     * Extra product-page data for this theme (StoreFrontController::productShow
     * renders the shared page and adds these blocks):
     *  - specs: grouped spec table, highlight tiles, video
     *  - bundle: "frequently bought together" from real co-purchases in online
     *    orders, else "complete your setup" (cheaper single items from other
     *    categories). Single, available products only, so "add all" never needs
     *    a variant choice.
     *  - similar: up to 3 products of the same category for the compare table.
     */
    public function productExtras(StoreSetting $s, Product $product): array
    {
        $opts = PremiumElectronicsTheme::options($s);
        $tax = (float) ($s->default_tax_rate ?? 0);

        // ----- Bundle -----
        $bundleKind = 'bought';
        $ids = [];
        if (Schema::hasTable('online_order_items')) {
            $orderIds = DB::table('online_order_items')
                ->join('online_orders', 'online_orders.id', '=', 'online_order_items.order_id')
                ->whereNotIn('online_orders.status', ['cancelled', 'canceled', 'refunded'])
                ->where('online_order_items.product_id', $product->id)
                ->orderByDesc('online_order_items.order_id')->limit(500)
                ->pluck('online_order_items.order_id')->all();
            if ($orderIds) {
                $ids = DB::table('online_order_items')
                    ->whereIn('order_id', $orderIds)
                    ->whereNotNull('product_id')
                    ->where('product_id', '!=', $product->id)
                    ->select('product_id', DB::raw('COUNT(*) AS c'))
                    ->groupBy('product_id')
                    ->orderByDesc('c')
                    ->limit(12)
                    ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            }
        }
        $bundle = collect();
        if ($ids) {
            $order = array_flip($ids);
            $bundle = $this->hydrate($this->singleItems($this->baseQuery()->whereIn('products.id', $ids)), $s, $tax, 12)
                ->sortBy(fn ($p) => $order[$p->id] ?? 999)->values();
        }
        $bundle = $bundle->filter(fn ($p) => $this->sellable($s, $p))->take(2)->values();
        if ($bundle->isEmpty()) {
            $bundleKind = 'setup';
            // Compared with products.price, so use this product's base price too (pre-discount, pre-tax).
            $ceiling = (float) ($product->base_price ?? $product->price ?? 0);
            $bundle = $this->hydrate(
                $this->singleItems($this->baseQuery())
                    ->where('products.id', '!=', $product->id)
                    ->when($product->category_id, fn ($q) => $q->where(fn ($w) => $w->where('products.category_id', '!=', $product->category_id)->orWhereNull('products.category_id')))
                    ->when($ceiling > 0, fn ($q) => $q->where('products.price', '<', $ceiling))
                    ->orderByDesc('products.is_featured')
                    ->orderByDesc('products.created_at'),
                $s, $tax, 6
            )->filter(fn ($p) => $this->sellable($s, $p))->take(2)->values();
        }

        // ----- Similar products + compare rows -----
        $similar = collect();
        if ($product->category_id) {
            $similar = $this->hydrate(
                $this->baseQuery()
                    ->where('products.category_id', $product->category_id)
                    ->where('products.id', '!=', $product->id)
                    ->when(\App\Support\StoreSpecs::ready(), fn ($q) => $q->orderByRaw('products.specs IS NULL'))
                    ->orderByDesc('products.is_featured')
                    ->orderByDesc('products.created_at'),
                $s, $tax, 6
            );
        }
        // Only products that share at least one spec with this one earn a column.
        $own = \App\Support\StoreSpecs::map($product);
        $similar = $similar->filter(fn ($p) => array_intersect_key(\App\Support\StoreSpecs::map($p), $own) !== [])->take(3)->values();
        $columns = collect([$product])->concat($similar)->values();
        $maps = $columns->map(fn ($p) => \App\Support\StoreSpecs::map($p))->all();
        $labels = \App\Support\StoreSpecs::filterLabels($opts);
        foreach (array_keys($maps[0] ?? []) as $l) {
            if (count($labels) >= 8) {
                break;
            }
            if (! in_array($l, $labels, true)) {
                $labels[] = $l;
            }
        }
        $compareRows = [];
        foreach ($labels as $label) {
            $vals = array_map(fn ($m) => $m[$label] ?? null, $maps);
            if (count(array_filter($vals, fn ($v) => $v !== null)) >= 1 && $vals[0] !== null) {
                $compareRows[] = ['label' => $label, 'values' => $vals];
            }
        }

        $video = \App\Support\StoreSpecs::videoReady() ? trim((string) ($product->getAttributes()['video_url'] ?? '')) : '';
        $video = $video !== '' && PremiumElectronicsTheme::safeUrl($video) ? $video : '';

        return [
            'groups' => \App\Support\StoreSpecs::grouped($product),
            'highlights' => \App\Support\StoreSpecs::highlights($product, 4),
            'video_url' => $video ?: null,
            'video_embed' => $video ? PremiumElectronicsTheme::embedUrl($video) : null,
            'bundle' => $bundle,
            'bundle_kind' => $bundleKind,
            'similar' => $similar,
            // Only worth a table when this product has specs and something to compare with.
            'compare_columns' => $similar->isNotEmpty() && $compareRows ? $columns : collect(),
            'compare_rows' => $compareRows,
        ];
    }

    /** Plain single products (no variants, not services/combos) — safe for "add all". */
    protected function singleItems($query)
    {
        return $query->where('products.type', 'is_single');
    }

    protected function sellable(StoreSetting $s, $p): bool
    {
        if ((bool) ($s->allow_overselling ?? true) || (bool) ($p->always_in_stock ?? false)) {
            return true;
        }

        return $this->productHasStock($p);
    }

    /** The parent's storefront query plus the brand the cards print. */
    protected function baseQuery()
    {
        return parent::baseQuery()->with('brand:id,name');
    }

    /** Eager-load brands on a list built outside baseQuery() (flash sale, best sellers). */
    protected function withBrands($products): void
    {
        if ($products instanceof \Illuminate\Database\Eloquent\Collection && $products->isNotEmpty()) {
            $products->loadMissing('brand:id,name');
        }
    }

    /** Only products with a real photo (hero, tiles, category images). */
    protected function withImage($query)
    {
        return $query->whereNotNull('products.image')
            ->where('products.image', '!=', '')
            ->where('products.image', '!=', 'no-image.png');
    }
}
