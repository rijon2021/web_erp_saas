<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'gtin', 'hs_code', 'fbr_uom', 'Type_barcode', 'name', 'cost', 'price', 'unit_id', 'unit_sale_id', 'unit_purchase_id',
        'stock_alert', 'weight', 'length', 'width', 'height', 'category_id', 'sub_category_id', 'is_variant', 'is_imei',
        'tax_method', 'image', 'brand_id', 'is_active', 'note', 'type',
        'warranty_period', 'warranty_unit', 'warranty_terms', 'wholesale_price', 'min_price',
        'has_guarantee', 'guarantee_period', 'guarantee_unit', 'points', 'discount', 'discount_method',
        'is_featured', 'hide_from_online_store', 'is_returnable', 'always_in_stock',
        'is_preorder', 'preorder_available_date', 'preorder_limit', 'preorder_note',
        'is_batch_tracked', 'shelf_life_days', 'generic_name', 'strength', 'dosage_form',
        'pack_size', 'manufacturer', 'prescription_required', 'drug_schedule',
        // Default supplier to reorder this product from (Settings -> Features)
        'provider_id',
        // Home warehouse the product was created for (Settings -> Features:
        // restrict products by warehouse). NULL = not assigned to any branch.
        'warehouse_id',
        // Size chart shown on the storefront product page (Products > Size guides)
        'size_guide_id',
        // Item code a counter weighing scale prints on its labels
        'scale_plu',
        // Software License: none | pool (uploaded keys) | generated (own software)
        'license_mode',
    ];

    protected $casts = [
        'size_guide_id' => 'integer',
        'is_returnable' => 'boolean',
        'wholesale_price' => 'double',
        'min_price' => 'double',
        'category_id' => 'integer',
        'is_featured' => 'integer',
        'sub_category_id' => 'integer',
        'unit_id' => 'integer',
        'unit_sale_id' => 'integer',
        'unit_purchase_id' => 'integer',
        'is_variant' => 'integer',
        'is_imei' => 'integer',
        'brand_id' => 'integer',
        'provider_id' => 'integer',
        'warehouse_id' => 'integer',
        'is_active' => 'integer',
        'cost' => 'double',
        'price' => 'double',
        'stock_alert' => 'double',
        'weight' => 'double',
        'length' => 'double',
        'width' => 'double',
        'height' => 'double',
        'TaxNet' => 'double',
        'points' => 'double',
        'has_guarantee' => 'boolean',
        'hide_from_online_store' => 'boolean',
        'always_in_stock' => 'boolean',
        'is_preorder' => 'boolean',
        'preorder_available_date' => 'date',
        'preorder_limit' => 'integer',
        'discount' => 'double',
        'woocommerce_missing_sku' => 'boolean',
        'is_batch_tracked' => 'boolean',
        'prescription_required' => 'boolean',
        'shelf_life_days' => 'integer',
    ];

    /**
     * Shipping methods this product may be sent by. EMPTY means unrestricted —
     * every method that reaches the destination is offered.
     */
    public function shippingMethods()
    {
        return $this->belongsToMany(ShippingMethod::class, 'product_shipping_method');
    }

    public function variants()
    {
        // table is "product_variants", FK is "product_id"
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    /**
     * Variant option dimensions (Color, Size...). Empty for products that use
     * the legacy flat variant list.
     */
    public function options()
    {
        return $this->hasMany(ProductOption::class, 'product_id')->orderBy('position')->orderBy('id');
    }

    public function batches()
    {
        return $this->hasMany(ProductBatch::class, 'product_id');
    }

    public function packs()
    {
        return $this->hasMany(ProductPack::class, 'product_id');
    }

    public function ProductVariant()
    {
        return $this->belongsTo('App\Models\ProductVariant');
    }

    public function PurchaseDetail()
    {
        return $this->belongsTo('App\Models\PurchaseDetail');
    }

    public function SaleDetail()
    {
        return $this->belongsTo('App\Models\SaleDetail');
    }

    public function QuotationDetail()
    {
        return $this->belongsTo('App\Models\QuotationDetail');
    }

    public function category()
    {
        return $this->belongsTo('App\Models\Category');
    }

    /**
     * Optional sub-category (for more granular product grouping).
     */
    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    /**
     * Additional / multiple parent categories (legacy default remains category_id + category()).
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withTimestamps();
    }

    /**
     * Additional / multiple subcategories (legacy default remains sub_category_id + subCategory()).
     */
    public function subcategories()
    {
        return $this->belongsToMany(SubCategory::class, 'product_subcategory', 'product_id', 'sub_category_id')
            ->withTimestamps();
    }

    public function unit()
    {
        return $this->belongsTo('App\Models\Unit', 'unit_id');
    }

    public function unitPurchase()
    {
        return $this->belongsTo('App\Models\Unit', 'unit_purchase_id');
    }

    public function unitSale()
    {
        return $this->belongsTo('App\Models\Unit', 'unit_sale_id');
    }

    public function brand()
    {
        return $this->belongsTo('App\Models\Brand');
    }

    public function sizeGuide()
    {
        return $this->belongsTo(SizeGuide::class, 'size_guide_id');
    }

    /**
     * The product's active size guide when it has something to show, else
     * null. Safe before the size_guides migration has run.
     */
    public function activeSizeGuide(): ?SizeGuide
    {
        if (! $this->size_guide_id || ! SizeGuide::tablesReady()) {
            return null;
        }
        $guide = $this->relationLoaded('sizeGuide') ? $this->sizeGuide : $this->sizeGuide()->first();

        return $guide && ! $guide->deleted_at && $guide->status && $guide->hasChart() ? $guide : null;
    }

    /** Car Dealership theme: make, model, year, mileage … (vehicle_specs). */
    public function vehicleSpec()
    {
        return $this->hasOne(VehicleSpec::class);
    }

    /** Booking Management: a service product's booking details (bk_services). */
    public function bookingDetail()
    {
        return $this->hasOne(BookingServiceDetail::class, 'product_id');
    }

    /** Home warehouse (see the warehouse_id column note above). */
    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'warehouse_id');
    }

    /**
     * Products a user assigned to the given warehouses may see: the home
     * warehouse is one of theirs, or real stock (qte > 0) sits in one of
     * theirs — a product transferred or purchased into a branch belongs to
     * that branch's day-to-day work even if it was created elsewhere. The
     * zero-quantity rows every product gets in every warehouse do NOT count,
     * which is the whole point of the home warehouse column.
     */
    public function scopeVisibleInWarehouses($query, array $warehouseIds, string $table = 'products')
    {
        return static::applyVisibleInWarehouses($query, $warehouseIds, $table);
    }

    /** Builder-agnostic body of visibleInWarehouses (Eloquent or DB builder). */
    public static function applyVisibleInWarehouses($query, array $warehouseIds, string $table = 'products')
    {
        // A non-correlated IN (…) lets MySQL materialise the stocked ids once
        // (semi-join) instead of probing product_warehouse per product row:
        // ~4x faster than the equivalent EXISTS on a 30k-product catalogue.
        return $query->where(function ($q) use ($warehouseIds, $table) {
            $q->whereIn($table.'.warehouse_id', $warehouseIds)
                ->orWhereIn($table.'.id', function ($e) use ($warehouseIds) {
                    $e->select('product_warehouse.product_id')
                        ->from('product_warehouse')
                        ->whereNull('product_warehouse.deleted_at')
                        ->whereIn('product_warehouse.warehouse_id', $warehouseIds)
                        ->where('product_warehouse.qte', '>', 0);
                });
        });
    }

    /**
     * Storefront counterpart of the back-office "Restrict products by
     * warehouse" rule. While that Features switch is on AND Store Settings
     * sells from a subset of warehouses, the shop lists only products that
     * belong to (or hold stock in) those warehouses, exactly like a user
     * restricted to them would see. No-op when the switch is off or the
     * store sells from every warehouse.
     */
    public function scopeForStorefrontWarehouses($query, string $table = 'products')
    {
        return static::applyStorefrontWarehouses($query, $table);
    }

    /** Same rule for Eloquent or DB builders ($table = alias of products). */
    public static function applyStorefrontWarehouses($query, string $table = 'products')
    {
        $ids = static::storefrontWarehouseIds();
        if ($ids === null) {
            return $query;
        }

        return static::applyVisibleInWarehouses($query, $ids, $table);
    }

    /** Per-request memo, keyed by tenant database. */
    private static array $storefrontWarehouseMemo = [];

    /**
     * Warehouses the storefront is restricted to, or null when every product
     * may be listed (switch off, no store settings row, or the store sells
     * from every warehouse).
     *
     * @return int[]|null
     */
    public static function storefrontWarehouseIds(): ?array
    {
        $key = (string) \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (array_key_exists($key, self::$storefrontWarehouseMemo)) {
            return self::$storefrontWarehouseMemo[$key];
        }

        $ids = null;
        $setting = Setting::current();
        if ($setting && ($setting->restrict_products_by_warehouse ?? false)) {
            $store = StoreSetting::query()->first();
            if ($store) {
                $selected = $store->activeWarehouseIds();
                $all = Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($selected && array_diff($all, $selected)) {
                    $ids = $selected;
                }
            }
        }

        return self::$storefrontWarehouseMemo[$key] = $ids;
    }

    /** Forget the memo (tests, or after Store Settings change in-request). */
    public static function forgetStorefrontWarehouses(): void
    {
        self::$storefrontWarehouseMemo = [];
    }

    /** Default supplier to reorder this product from. */
    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    // Relationship for products that are combined in a combo
    public function combinedProducts()
    {
        return $this->belongsToMany(Product::class, 'combined_products', 'product_id', 'combined_product_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function collections()
    {
        return $this->belongsToMany(Collection::class, 'collection_product')
            ->withPivot(['sort_order', 'pinned'])
            ->withTimestamps();
    }

    public function warehouseLocationHints()
    {
        return $this->hasMany(ProductWarehouseLocation::class, 'product_id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * Filenames under the tenant-aware products upload dir — prefers product_images when loaded, else legacy comma-separated products.image.
     */
    public function productGalleryFilenames(): array
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            return $this->images
                ->sortBy('sort_order')
                ->values()
                ->pluck('image_path')
                ->map(fn ($f) => trim((string) $f))
                ->filter()
                ->values()
                ->all();
        }

        $raw = trim((string) ($this->attributes['image'] ?? ''));

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Primary filename for thumbnails / legacy consumers (main gallery row when loaded, else first path in legacy column).
     */
    public function primaryProductImageFilename(): string
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            $main = $this->images->firstWhere('is_main', true);
            if ($main && trim((string) $main->image_path) !== '') {
                return trim((string) $main->image_path);
            }
            $first = $this->images->sortBy('sort_order')->first();

            return $first ? trim((string) $first->image_path) : '';
        }

        $gallery = $this->productGalleryFilenames();
        if (! empty($gallery)) {
            return (string) $gallery[0];
        }

        return '';
    }

    /**
     * Final price after discount + tax.
     * Encodings (varchar):
     * - discount_method: "1"=percent, "2"=fixed
     * - tax_method:      "1"=exclusive, "2"=inclusive
     *
     * @param  float|null  $taxRate  Percent (e.g. 20 for 20%)
     * @param  float|null  $overrideBase  Use this base instead of $this->price (for variants)
     * @return array{base:float, discount:float, after_discount:float, tax:float, final:float}
     */
    public function computeFinalPrice(?float $taxRate = null, ?float $overrideBase = null): array
    {
        // ---- Base
        $base = (float) ($overrideBase ?? $this->price ?? 0);

        // ---- Discount method normalization
        $dmRaw = $this->discount_method ?? null;
        $dm = null; // 'percent' | 'fixed' | null

        if (is_string($dmRaw)) {
            $dmRaw = trim(strtolower($dmRaw));
            if ($dmRaw === '1') {
                $dm = 'percent';
            } elseif ($dmRaw === '2') {
                $dm = 'fixed';
            } elseif (in_array($dmRaw, ['percent', 'percentage'], true)) {
                $dm = 'percent';
            } elseif ($dmRaw === 'fixed') {
                $dm = 'fixed';
            }
        } elseif (is_numeric($dmRaw)) {
            $dm = ((int) $dmRaw === 1) ? 'percent' : (((int) $dmRaw === 2) ? 'fixed' : null);
        }

        $discVal = (float) ($this->discount ?? 0);
        if ($dm === 'percent') {
            $discountAmount = round($base * ($discVal / 100), 2);
        } elseif ($dm === 'fixed') {
            $discountAmount = round(min($discVal, $base), 2);
        } else {
            $discountAmount = 0.0;
        }

        $afterDiscount = max(0.0, round($base - $discountAmount, 2));

        // ---- Tax rate
        if ($taxRate === null) {
            if (isset($this->tax_rate)) {
                $taxRate = (float) $this->tax_rate;
            } elseif (isset($this->TaxNet)) {
                $taxRate = (float) $this->TaxNet;
            } // some schemas use TaxNet
            else {
                $taxRate = 0.0;
            }
        } else {
            $taxRate = (float) $taxRate;
        }

        // ---- Tax method normalization
        $tmRaw = $this->tax_method ?? '1';
        $taxMode = 'exclusive'; // default
        if (is_string($tmRaw)) {
            $tm = trim(strtolower($tmRaw));
            if ($tm === '2' || $tm === 'inclusive') {
                $taxMode = 'inclusive';
            } else {
                $taxMode = 'exclusive';
            } // '1' or 'exclusive'
        } elseif (is_numeric($tmRaw)) {
            $taxMode = ((int) $tmRaw === 2) ? 'inclusive' : 'exclusive';
        }

        if ($taxRate <= 0) {
            $taxAmount = 0.0;
            $final = $afterDiscount;
        } elseif ($taxMode === 'inclusive') {
            // afterDiscount is gross; extract tax portion
            $taxAmount = round($afterDiscount - ($afterDiscount / (1 + $taxRate / 100)), 2);
            $final = $afterDiscount;
        } else {
            // exclusive; add tax on top
            $taxAmount = round($afterDiscount * ($taxRate / 100), 2);
            $final = round($afterDiscount + $taxAmount, 2);
        }

        return [
            'base' => round($base, 2),
            'discount' => $discountAmount,
            'after_discount' => $afterDiscount,
            'tax' => $taxAmount,
            'final' => $final,
        ];
    }

    /**
     * Minimum final price across variants (if loaded), else product itself.
     */
    public function minDisplayPrice(?float $taxRate = null): float
    {
        if (! empty($this->is_variant) && $this->relationLoaded('variants') && $this->variants->count()) {
            $min = null;
            foreach ($this->variants as $v) {
                $calc = $this->computeFinalPrice($taxRate, (float) ($v->price ?? 0));
                $min = is_null($min) ? $calc['final'] : min($min, $calc['final']);
            }
            if ($min !== null) {
                return round($min, 2);
            }
        }

        return round($this->computeFinalPrice($taxRate)['final'], 2);
    }

    /**
     * API payload: categories from pivot when present, else legacy category_id.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function apiCategoriesList(): array
    {
        if ($this->relationLoaded('categories') && $this->categories->isNotEmpty()) {
            return $this->categories
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
                ->values()
                ->all();
        }

        if ($this->category_id) {
            $c = $this->relationLoaded('category') ? $this->category : $this->category()->first();
            if ($c) {
                return [['id' => (int) $c->id, 'name' => (string) $c->name]];
            }
        }

        return [];
    }

    /**
     * API payload: subcategories from pivot when present, else legacy sub_category_id.
     *
     * @return array<int, array{id:int, name:string, category_id:int}>
     */
    public function apiSubcategoriesList(): array
    {
        if ($this->relationLoaded('subcategories') && $this->subcategories->isNotEmpty()) {
            return $this->subcategories
                ->map(fn ($s) => [
                    'id' => (int) $s->id,
                    'name' => (string) $s->name,
                    'category_id' => (int) $s->category_id,
                ])
                ->values()
                ->all();
        }

        if ($this->sub_category_id) {
            $s = $this->relationLoaded('subCategory') ? $this->subCategory : $this->subCategory()->first();
            if ($s) {
                return [[
                    'id' => (int) $s->id,
                    'name' => (string) $s->name,
                    'category_id' => (int) $s->category_id,
                ]];
            }
        }

        return [];
    }
}
