<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Support\FashionTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fashion & Apparel storefront theme — homepage. Active when
 * StoreSetting->theme === 'fashion'. Reuses the shared product pipeline in
 * StoreFrontController (prices, stock, ratings) so cards match the shop page.
 */
class FashionStoreController extends ElectronicsStoreController
{
    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = FashionTheme::options($s);
        $sections = $opts['sections'];
        $limits = $opts['limits'];
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);

        $slides = collect($opts['slides'])->map(function ($row) {
            $row['image_url'] = FashionTheme::imageUrl($row['image'] ?? '');
            $row['primary_href'] = FashionTheme::link($row['primary_url'] ?? '/shop');
            $row['secondary_href'] = FashionTheme::link($row['secondary_url'] ?? '/shop');

            return $row;
        })->values();

        $categories = Category::with('subcategories')->when(Schema::hasColumn('categories', 'show_in_store'), fn ($q) => $q->where('show_in_store', 1))->orderBy('name')->get();

        // Category tiles: the owner's tiles when added; otherwise (unless
        // switched off) the biggest categories with a photo from their products.
        $tiles = collect();
        if ($sections['categories']) {
            if ($opts['tiles'] === [] && $opts['auto_category_tiles']) {
                $counts = Product::query()
                    ->whereNull('deleted_at')
                    ->where('is_active', 1)
                    ->where('hide_from_online_store', 0)->forStorefrontWarehouses()
                    ->select('category_id', DB::raw('COUNT(*) AS c'))
                    ->groupBy('category_id')
                    ->pluck('c', 'category_id');
                $tiles = $categories
                    ->filter(fn ($c) => (int) ($counts[$c->id] ?? 0) > 0)
                    ->sortByDesc(fn ($c) => (int) ($counts[$c->id] ?? 0))
                    ->take($limits['categories'])
                    ->map(function ($c) use ($counts) {
                        $img = Product::query()
                            ->whereNull('deleted_at')->where('category_id', $c->id)->where('is_active', 1)->where('hide_from_online_store', 0)->forStorefrontWarehouses()
                            ->whereNotNull('image')->where('image', '!=', '')->where('image', '!=', 'no-image.png')
                            ->orderByDesc('is_featured')->orderByDesc('created_at')->value('image');

                        return [
                            'title' => $c->name,
                            'subtitle' => trans_choice('messages.products', (int) $counts[$c->id], ['count' => (int) $counts[$c->id]]),
                            'image_url' => $img ? product_image_url($img) : null,
                            'href' => route('store.shop', ['category' => $c->id]),
                        ];
                    })
                    ->values();
            } else {
                $tiles = collect($opts['tiles'])->take($limits['categories'])->map(function ($row) {
                    $row['image_url'] = FashionTheme::imageUrl($row['image'] ?? '');
                    $row['href'] = FashionTheme::link($row['url'] ?? '/shop');

                    return $row;
                })->values();
            }
        }

        // New arrivals, with category tabs filtered in the browser.
        $newArrivals = collect();
        $arrivalTabs = collect();
        if ($sections['new_arrivals']) {
            $newArrivals = $this->hydrate(
                $this->baseQuery()->orderByDesc('products.created_at'),
                $s, $defaultTaxRate, $limits['new_arrivals']
            );
            $catIds = $newArrivals->pluck('category_id')->filter()->countBy()->sortDesc()->keys()->take(4);
            if ($catIds->count() > 1) {
                $names = $categories->keyBy('id');
                $arrivalTabs = $catIds->filter(fn ($id) => $names->has($id))
                    ->map(fn ($id) => ['id' => (int) $id, 'name' => $names[$id]->name])
                    ->values();
            }
        }

        // Sale: discounted + flash-sale products.
        $sale = collect();
        if ($sections['sale']) {
            $flashIds = store_flash_ids();
            $sale = $this->hydrate(
                $this->baseQuery()->where(function ($q) use ($flashIds) {
                    $q->where('products.discount', '>', 0);
                    if ($flashIds) {
                        $q->orWhereIn('products.id', $flashIds);
                    }
                })->orderByDesc('products.discount')->orderByDesc('products.created_at'),
                $s, $defaultTaxRate, $limits['sale']
            );
        }

        // Best sellers: online best sellers, topped up with featured / most reviewed.
        $popular = collect();
        if ($sections['popular']) {
            [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();
            $popular = $this->buildBestSellerProducts($s, $baseExpr, $afterDiscountExpr, $finalExpr, $minVariantSub, $defaultTaxRate, $limits['popular']);
            if ($popular->count() < $limits['popular']) {
                $have = $popular->pluck('id')->merge($newArrivals->pluck('id'))->all();
                $ratingSub = $this->ratingSubquery();
                $q = $this->baseQuery()->when($have, fn ($q) => $q->whereNotIn('products.id', $have));
                if ($ratingSub) {
                    $q->leftJoinSub($ratingSub, 'pr', fn ($j) => $j->on('pr.product_id', '=', 'products.id'))
                        ->orderByDesc('products.is_featured')->orderByDesc('pr.rating_count')->orderByDesc('pr.rating_avg');
                } else {
                    $q->orderByDesc('products.is_featured');
                }
                $more = $this->hydrate($q->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limits['popular'] - $popular->count());
                $popular = $popular->concat($more)->values();
            }
            $this->attachRatings($popular);
        }

        $banners = collect($opts['banners'])->map(function ($row) {
            $row['image_url'] = FashionTheme::imageUrl($row['image'] ?? '');
            $row['href'] = FashionTheme::link($row['url'] ?? '/shop');

            return $row;
        })->values();

        $brands = collect();
        if ($sections['brands']) {
            $brandIds = Product::where('is_active', 1)->where('hide_from_online_store', 0)->forStorefrontWarehouses()->whereNotNull('brand_id')->distinct()->pluck('brand_id');
            $brands = $brandIds->count() >= 3 ? Brand::whereIn('id', $brandIds)->orderBy('name')->take(12)->get() : collect();
        }

        return view('store.fashion.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $sections,
            'slides' => $slides,
            'categories' => $categories,
            'tiles' => $tiles,
            'newArrivals' => $newArrivals,
            'arrivalTabs' => $arrivalTabs,
            'sale' => $sale,
            'popular' => $popular,
            'banners' => $banners,
            'brands' => $brands,
            'showCategoryBar' => false,
        ]);
    }
}
