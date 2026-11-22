<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SizeGuide;
use App\Models\StoreSetting;
use App\Models\SubCategory;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use App\Services\ProductOptionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo catalog for the Fashion & Apparel theme: 4 categories with
 * subcategories, brands, 16 clothing / shoe / bag products with Unsplash photo
 * LINKS, a size variant per size (some sold out) stocked in every warehouse,
 * and the theme activated with matching hero, tiles and lookbook. When the
 * variant-options tables exist the sizes are also saved as a "Size" option
 * (plus a "Color" option on two products), and when the size_guides table
 * exists each apparel category gets a size chart.
 * Run:  php artisan db:seed --class=FashionDemoSeeder
 * Idempotent: products carry FA- codes and are skipped when present.
 */
class FashionDemoSeeder extends Seeder
{
    private const IMG = [
        'poncho' => '1434389677669-e08b4cac3105', 'shopping' => '1483985988355-763728e1935b', 'man_circle' => '1488161628813-04466f872be2',
        'rack' => '1490481651871-ab68de25d43d', 'floral_dress' => '1496747611176-843222e1e57c', 'black_tee' => '1503342217505-b0a15ec3261c',
        'striped_pants' => '1509631179647-0177331693ae', 'tracksuit' => '1515886657613-9f3515b0c78f', 'white_tee' => '1521572163474-6864f9cf17ab',
        'red_tee' => '1529139574466-a303027c1d8b', 'blue_coat' => '1539109136881-3be0616acf4b', 'jeans' => '1542272604-787c3835535d',
        'sneaker' => '1549298916-b41d501d3772', 'leather_jacket' => '1551028719-00167b16eac5', 'graphic_tee' => '1576566588028-4147f3842f27',
        'handbag' => '1584917865442-de89df76afd3', 'bomber' => '1591047139829-d91aecb6caea', 'cargo' => '1594633312681-425c7b97ccd1',
        'red_gown' => '1595777457583-95e059d581b8', 'suit' => '1617137968427-85924c800a22',
    ];

    /** Products sold in colours too (Color × Size), by code: [name, swatch]. */
    private const COLOURS = [
        'FA-0001' => [['Floral ivory', '#efe8da'], ['Poppy red', '#b3262e']],
        'FA-0012' => [['White', '#ffffff'], ['Black', '#141414'], ['Stone', '#c9c2b4']],
    ];

    private const WOMEN = ['XS', 'S', 'M', 'L', 'XL'];

    private const MEN = ['S', 'M', 'L', 'XL', 'XXL'];

    private static function img(string $key, int $w = 900): string
    {
        return 'https://images.unsplash.com/photo-'.self::IMG[$key].'?w='.$w.'&q=80&auto=format&fit=crop';
    }

    public function run(): void
    {
        if (Product::where('code', 'like', 'FA-%')->exists()) {
            $this->command?->warn('Fashion demo products already present — activating the theme only. (Delete FA-* products to reseed.)');
            $this->activateTheme();

            return;
        }

        DB::transaction(function () {
            $unit = Unit::firstOrCreate(['ShortName' => 'pc'], ['name' => 'Piece', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1]);
            $warehouses = Warehouse::all();
            if ($warehouses->isEmpty()) {
                $warehouses = collect([Warehouse::create(['name' => 'Main Warehouse', 'city' => 'Main', 'country' => '', 'mobile' => '', 'email' => '', 'zip' => ''])]);
            }

            $catDefs = [
                'Women' => ['icon' => 'shirt', 'subs' => ['Dresses', 'Coats', 'Trousers', 'Tops', 'Knitwear']],
                'Men' => ['icon' => 'shirt', 'subs' => ['Jackets', 'T-shirts', 'Suits', 'Jeans']],
                'Shoes' => ['icon' => 'tag', 'subs' => ['Sneakers', 'Boots']],
                'Accessories' => ['icon' => 'bag', 'subs' => ['Bags', 'Jewellery']],
            ];
            $cats = [];
            $subs = [];
            $n = 1;
            foreach ($catDefs as $name => $def) {
                $c = Category::firstOrCreate(['name' => $name], array_merge(['code' => 'FA-C'.$n, 'icon' => $def['icon']], Schema::hasColumn('categories', 'show_in_store') ? ['show_in_store' => 1] : []));
                $cats[$name] = $c;
                foreach ($def['subs'] as $sn) {
                    $subs[$name][$sn] = SubCategory::firstOrCreate(['category_id' => $c->id, 'name' => $sn], ['description' => '', 'status' => 1]);
                }
                $n++;
            }

            $brands = [];
            foreach (['Atelier Studio', 'Nord Denim', 'Maison Ivy', 'Stride'] as $b) {
                $brands[$b] = Brand::firstOrCreate(['name' => $b], ['description' => $b.' collection']);
            }

            // [name, brand, category, sub, cost, price, discount%, images, featured, note, sizes ([] = one size), sold-out sizes, days old]
            $rows = [
                ['Linen-blend wrap dress', 'Maison Ivy', 'Women', 'Dresses', 38, 89, 0, ['floral_dress', 'red_gown'], 1, 'A softly draped wrap dress in a breathable linen blend, with a tie waist and flutter sleeves.', self::WOMEN, ['XL'], 3],
                ['Flowing maxi gown', 'Maison Ivy', 'Women', 'Dresses', 90, 229, 35, ['red_gown', 'floral_dress'], 1, 'Floor-length satin gown with a full, swinging skirt.', self::WOMEN, ['XS', 'S'], 40],
                ['Oversized wool coat', 'Atelier Studio', 'Women', 'Coats', 95, 219, 0, ['blue_coat', 'shopping'], 1, 'Relaxed double-breasted coat in a soft wool blend.', self::WOMEN, ['XS'], 6],
                ['Pleated wide-leg trousers', 'Atelier Studio', 'Women', 'Trousers', 30, 75, 0, ['striped_pants', 'cargo'], 0, 'High-waisted trousers with front pleats and a fluid wide leg.', self::WOMEN, [], 9],
                ['Utility cargo joggers', 'Atelier Studio', 'Women', 'Trousers', 22, 59, 20, ['cargo', 'striped_pants'], 0, 'Soft twill joggers with cargo pockets and cuffed ankles.', self::WOMEN, ['M', 'L'], 55],
                ['Relaxed fleece tracksuit', 'Stride', 'Women', 'Tops', 40, 95, 0, ['tracksuit'], 0, 'Brushed-back fleece hoodie and joggers set.', self::WOMEN, ['XS', 'L'], 12],
                ['Skeleton print tee', 'Stride', 'Women', 'Tops', 11, 32, 0, ['black_tee', 'red_tee'], 0, 'Boxy cotton tee with a hand-drawn print.', self::WOMEN, [], 20],
                ['Cropped logo tee', 'Stride', 'Women', 'Tops', 10, 29, 0, ['red_tee', 'black_tee'], 0, 'Cropped fit in heavyweight cotton.', self::WOMEN, ['XL'], 2],
                ['Fringed knit poncho', 'Maison Ivy', 'Women', 'Knitwear', 35, 90, 50, ['poncho', 'rack'], 0, 'Open-knit cotton poncho with a fringed hem. One size.', [], [], 70],
                ['Leather biker jacket', 'Atelier Studio', 'Men', 'Jackets', 110, 240, 0, ['leather_jacket', 'red_tee'], 1, 'Classic biker jacket in supple lamb leather.', self::MEN, ['S'], 8],
                ['Satin bomber jacket', 'Atelier Studio', 'Men', 'Jackets', 52, 129, 0, ['bomber', 'man_circle'], 0, 'Lightweight bomber with ribbed trims.', self::MEN, [], 15],
                ['Organic cotton crew tee', 'Stride', 'Men', 'T-shirts', 9, 29, 0, ['white_tee', 'black_tee'], 1, 'Everyday crew neck in organic cotton jersey.', self::MEN, [], 25],
                ['Graphic print tee', 'Stride', 'Men', 'T-shirts', 12, 35, 35, ['graphic_tee', 'white_tee'], 0, 'Relaxed tee with a bold front print.', self::MEN, ['M'], 60],
                ['Slim navy suit', 'Atelier Studio', 'Men', 'Suits', 130, 289, 0, ['suit', 'man_circle'], 1, 'Two-piece suit in stretch wool, slim through the leg.', self::MEN, ['XXL'], 18],
                ['Straight selvedge jeans', 'Nord Denim', 'Men', 'Jeans', 45, 110, 0, ['jeans', 'man_circle'], 0, 'Raw selvedge denim in a straight, mid-rise fit.', ['28', '30', '32', '34', '36'], ['28'], 30],
                ['Leather court sneaker', 'Stride', 'Shoes', 'Sneakers', 55, 135, 0, ['sneaker'], 1, 'Minimal leather sneaker on a cushioned sole.', ['38', '39', '40', '41', '42', '43', '44'], ['44'], 4],
                ['Structured top-handle bag', 'Maison Ivy', 'Accessories', 'Bags', 70, 170, 30, ['handbag', 'shopping'], 0, 'Polished leather bag with a turn-lock closure. One size.', [], [], 45],
            ];

            $optionsReady = class_exists(ProductOptionService::class) && ProductOptionService::tablesReady();
            $optionService = $optionsReady ? app(ProductOptionService::class) : null;

            foreach ($rows as $i => $r) {
                [$name, $brand, $cat, $sub, $cost, $price, $disc, $imgs, $featured, $note, $sizes, $soldOut, $daysOld] = $r;
                $code = 'FA-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                $hasSizes = $sizes !== [];
                $data = [
                    'code' => $code,
                    'Type_barcode' => 'CODE128',
                    'name' => $name,
                    'cost' => $cost,
                    'price' => $price,
                    'unit_id' => $unit->id,
                    'unit_sale_id' => $unit->id,
                    'unit_purchase_id' => $unit->id,
                    'stock_alert' => 3,
                    'category_id' => $cats[$cat]->id,
                    'brand_id' => $brands[$brand]->id ?? null,
                    'is_variant' => $hasSizes ? 1 : 0,
                    'tax_method' => '1',
                    'TaxNet' => 0,
                    'type' => $hasSizes ? 'is_variant' : 'is_single',
                    'is_active' => 1,
                    'image' => self::img($imgs[0]),
                    'note' => $note,
                    'discount' => $disc > 0 ? $disc : null,
                    'discount_method' => '1',
                    'created_at' => Carbon::now()->subDays($daysOld)->subMinutes($i),
                    'updated_at' => Carbon::now(),
                ];
                foreach (['is_featured' => $featured, 'hide_from_online_store' => 0, 'not_selling' => 0] as $col => $val) {
                    if (Schema::hasColumn('products', $col)) {
                        $data[$col] = $val;
                    }
                }
                if (Schema::hasColumn('products', 'sub_category_id') && isset($subs[$cat][$sub])) {
                    $data['sub_category_id'] = $subs[$cat][$sub]->id;
                }
                $product = new Product;
                $product->forceFill($data);
                $product->save();

                if (Schema::hasTable('product_images')) {
                    foreach (array_values(array_unique($imgs)) as $k => $key) {
                        ProductImage::create(['product_id' => $product->id, 'image_path' => self::img($key), 'is_main' => $k === 0, 'sort_order' => $k]);
                    }
                }

                if (! $hasSizes) {
                    foreach ($warehouses as $wh) {
                        product_warehouse::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'qte' => random_int(8, 40), 'manage_stock' => 1]);
                    }

                    continue;
                }

                // Colour × size only where the options tables exist: a legacy
                // flat list named "Black / M" would no longer read as sizes.
                $colours = $optionService ? (self::COLOURS[$code] ?? []) : [];
                $options = [];
                if ($colours) {
                    $options[] = ['name' => 'Color', 'values' => array_map(fn ($c) => ['value' => $c[0], 'swatch' => $c[1]], $colours)];
                }
                $options[] = ['name' => 'Size', 'values' => array_map(fn ($sz) => ['value' => $sz, 'swatch' => null], $sizes)];
                $map = $optionService ? $optionService->sync($product, $options) : [];

                foreach ($colours ?: [null] as $colour) {
                    foreach ($sizes as $sz) {
                        $variant = ProductVariant::create([
                            'product_id' => $product->id,
                            'name' => $colour ? $colour[0].' / '.$sz : $sz,
                            'code' => $code.'-'.($colour ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $colour[0]), 0, 3)).'-' : '').$sz,
                            'cost' => $cost,
                            'price' => $price,
                            'qty' => 0,
                        ]);
                        $qty = in_array($sz, $soldOut, true) ? 0 : random_int(3, 25);
                        foreach ($warehouses as $wh) {
                            product_warehouse::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'product_variant_id' => $variant->id, 'qte' => $qty, 'manage_stock' => 1]);
                        }
                        if ($optionService) {
                            $optionService->attach($variant->id, $colour ? ['Color' => $colour[0], 'Size' => $sz] : ['Size' => $sz], $map);
                        }
                    }
                }
            }

            $this->seedSizeGuides($cats);
        });

        $this->activateTheme();
        $this->command?->info('Fashion demo catalog seeded and the Fashion theme activated.');
    }

    /** One size chart per apparel category, linked to that category's FA- products. */
    protected function seedSizeGuides(array $cats): void
    {
        if (! SizeGuide::tablesReady()) {
            return;
        }
        $guides = [
            'Women' => ['Women\'s clothing', ['Size', 'UK', 'EU', 'US', 'Bust (cm)', 'Waist (cm)', 'Hips (cm)'], [
                ['XS', '6', '34', '2', '80', '62', '88'], ['S', '8', '36', '4', '84', '66', '92'], ['M', '10', '38', '6', '88', '70', '96'],
                ['L', '12', '40', '8', '94', '76', '102'], ['XL', '14', '42', '10', '100', '82', '108'],
            ]],
            'Men' => ['Men\'s clothing', ['Size', 'Chest (cm)', 'Waist (cm)', 'Jeans waist (in)'], [
                ['S', '88-96', '74-82', '28-30'], ['M', '96-104', '82-90', '30-32'], ['L', '104-112', '90-98', '32-34'],
                ['XL', '112-120', '98-106', '34-36'], ['XXL', '120-128', '106-114', '36-38'],
            ]],
            'Shoes' => ['Shoes', ['EU', 'UK', 'US women', 'US men', 'Foot length (cm)'], [
                ['38', '5', '7.5', '6', '24.1'], ['39', '6', '8.5', '7', '24.8'], ['40', '6.5', '9', '7.5', '25.4'], ['41', '7.5', '10', '8.5', '26.0'],
                ['42', '8', '10.5', '9', '26.7'], ['43', '9', '11.5', '10', '27.3'], ['44', '10', '12.5', '11', '27.9'],
            ]],
        ];
        foreach ($guides as $catName => [$name, $columns, $rows]) {
            $guide = SizeGuide::firstOrCreate(['name' => $name], ['columns' => $columns, 'rows' => $rows, 'status' => true]);
            if (isset($cats[$catName])) {
                Product::where('code', 'like', 'FA-%')->where('category_id', $cats[$catName]->id)->where('is_variant', 1)
                    ->update(['size_guide_id' => $guide->id]);
            }
        }
    }

    protected function activateTheme(): void
    {
        $s = StoreSetting::first();
        if (! $s) {
            return;
        }
        $catId = fn ($name) => Category::where('name', $name)->value('id');
        $link = fn ($name) => ($id = $catId($name)) ? '/shop?category='.$id : '/shop';

        $opts = is_array($s->theme_options) ? $s->theme_options : [];
        $opts['fashion'] = [
            'announcement_text' => 'Free shipping over $100 · Free 30-day returns',
            'slides' => [
                ['kicker' => 'Autumn / Winter 2026', 'title' => 'The season of soft tailoring', 'subtitle' => 'Relaxed coats, fluid trousers and knitwear made to layer. New pieces arrive every week.', 'image' => self::img('blue_coat', 1400), 'primary_text' => 'Shop women', 'primary_url' => $link('Women'), 'secondary_text' => 'Shop men', 'secondary_url' => $link('Men')],
                ['kicker' => 'Menswear', 'title' => 'Sharp suits, easy weekends', 'subtitle' => 'Tailoring in stretch wool and the jackets to wear with it.', 'image' => self::img('suit', 1400), 'primary_text' => 'Shop men', 'primary_url' => $link('Men'), 'secondary_text' => 'New in', 'secondary_url' => '/shop?sort=latest'],
            ],
            'tiles' => [
                ['title' => 'Women', 'subtitle' => 'Dresses, coats & more', 'url' => $link('Women'), 'image' => self::img('floral_dress', 800)],
                ['title' => 'Men', 'subtitle' => 'Jackets, tees & suits', 'url' => $link('Men'), 'image' => self::img('man_circle', 800)],
                ['title' => 'Shoes', 'subtitle' => 'Sneakers & boots', 'url' => $link('Shoes'), 'image' => self::img('sneaker', 800)],
                ['title' => 'Accessories', 'subtitle' => 'Bags & jewellery', 'url' => $link('Accessories'), 'image' => self::img('handbag', 800)],
            ],
            'banners' => [
                ['kicker' => 'The lookbook', 'title' => 'City layers, in burgundy', 'subtitle' => 'Wool coats, soft knits and leather accents styled for the commute and the weekend.', 'image' => self::img('shopping', 1400), 'button_text' => 'Shop the look', 'url' => $link('Women')],
            ],
            'sale_title' => 'Up to 50% off',
        ];

        $s->forceFill([
            'theme' => 'fashion',
            'theme_options' => $opts,
            'store_name' => in_array($s->store_name, [null, '', 'StoreX', 'TechNova', 'LittleJoy', 'HavenHomes', 'FreshMart', 'Atelier'], true) ? 'Atelier' : $s->store_name,
            'primary_color' => '#8a1c2b',
            'secondary_color' => '#141414',
            'topbar_text_left' => 'Free shipping over $100',
            'topbar_text_right' => 'Free 30-day returns',
            'footer_text' => 'Considered clothing for every day — made to last, easy to return.',
        ])->save();

        // Only one demo catalog visible at a time.
        if (Schema::hasColumn('products', 'hide_from_online_store')) {
            Product::where('code', 'like', 'FA-%')->update(['hide_from_online_store' => 0]);
            Product::where('code', 'like', 'EL-%')->orWhere('code', 'like', 'TY-%')->orWhere('code', 'like', 'GR-%')->update(['hide_from_online_store' => 1]);
        }
        if (function_exists('store_url_config_clear')) {
            store_url_config_clear();
        }
    }
}
