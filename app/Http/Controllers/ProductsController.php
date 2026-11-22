<?php

namespace App\Http\Controllers;

use App\Exports\StockExport;
use App\Imports\OpeningStockRowsImport;
use App\Services\ImportFingerprint;
use App\Services\ProductBarcodes;
use App\Imports\ProductImport;
use App\Models\Brand;
use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\CombinedProduct;
use App\Models\CountStock;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPack;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\ProductWarehouseLocation;
use App\Services\ProductGalleryService;
use App\Services\ProductOptionService;
use App\Services\ComboStockService;
use App\Services\SerialNumberService;
use App\Services\WarehousePrices;
use App\Services\WooCommerce\AutoSync as WooAutoSync;
use App\Services\Opencart\AutoSync as OpencartAutoSync;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Excel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManagerStatic as Image;

class ProductsController extends BaseController
{
    /** Stock-count exports live on the private `local` disk, never under public/. */
    private const COUNT_STOCK_DIR = 'count_stock';

    // ------------ Get ALL Products --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $perPage = $request->integer('limit', 10);
        $pageStart = (int) ($request->get('page', 1));
        $offSet = ($pageStart * max($perPage, 1)) - max($perPage, 1);
        $order = $request->get('SortField', 'id');
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $helpers = new helpers;
        $warehouseId = $request->integer('warehouse_id');

          // ✅ assigned warehouses (user scope)
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->get(['id', 'name']);
            $allowedWarehouseIds = $warehouses->pluck('id')->toArray();
        } else {
            $allowedWarehouseIds = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id', $allowedWarehouseIds)->get(['id', 'name']);
        }

        // ✅ if warehouse_id provided but not allowed => ignore it (don’t break anything)
        if ($warehouseId && ! in_array($warehouseId, $allowedWarehouseIds, true)) {
            $warehouseId = null;
        }

        // provider_id makes "everything I buy from this supplier" a one-click
        // filter, which is what turns a stored supplier into a reorder list.
        $columns = [0 => 'name', 1 => 'category_id', 2 => 'brand_id', 3 => 'code', 4 => 'sub_category_id', 5 => 'provider_id'];
        $param = [0 => 'like', 1 => '=', 2 => '=', 3 => 'like', 4 => '=', 5 => '='];

        $productsQuery = Product::with('unit', 'category', 'subCategory', 'brand', 'categories', 'provider:id,name', 'warehouse:id,name')
            ->whereNull('deleted_at');

        // Settings -> Features: optionally show only products stocked in one of
        // this user's warehouses. Previously the whole catalogue was returned
        // and only the QUANTITY column was scoped, so a warehouse A user still
        // browsed warehouse B's products. A product stocked nowhere yet stays
        // visible, so a newly created one is never hidden from its author.
        $this->scopeMasterDataToWarehouses($productsQuery, fn ($ids) => DB::table('product_warehouse')
            ->whereNull('product_warehouse.deleted_at')
            ->whereColumn('product_warehouse.product_id', 'products.id')
            ->when($ids !== null, fn ($q) => $q->whereIn('product_warehouse.warehouse_id', $ids)));

        // Settings -> Features: products belong to a warehouse. A restricted
        // user sees only products whose home warehouse is theirs or that
        // hold stock in one of theirs (see Product::scopeVisibleInWarehouses).
        $this->scopeProductsToWarehouses($productsQuery);
         


        // Multiple Filter
        $filtered = $helpers->filter($productsQuery, $columns, $param, $request)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    $s = $request->search;

                    return $query->where('products.name', 'LIKE', "%{$s}%")
                        ->orWhere('products.code', 'LIKE', "%{$s}%")
                        ->orWhere(function ($q) use ($s) {
                            $q->whereHas('category', function ($cq) use ($s) {
                                $cq->where('name', 'LIKE', "%{$s}%");
                            });
                        })
                        ->orWhere(function ($q) use ($s) {
                            $q->whereHas('categories', function ($cq) use ($s) {
                                $cq->where('name', 'LIKE', "%{$s}%");
                            });
                        })
                        ->orWhere(function ($q) use ($s) {
                            $q->whereHas('brand', function ($bq) use ($s) {
                                $bq->where('name', 'LIKE', "%{$s}%");
                            });
                        })
                        // An additional barcode (manufacturer EAN …) finds its product too.
                        ->when(ProductBarcodes::enabled(), fn ($q) => $q->orWhereIn('products.id', ProductBarcodes::productIdsLike($s)));
                });
            });

        // Optional status filter: status=1 (active), status=0 (inactive)
        if ($request->filled('status') && $request->status !== '') {
            $status = $request->status;
            if ($status === '1' || $status === 1 || $status === 'active') {
                $filtered->where('is_active', 1);
            } elseif ($status === '0' || $status === 0 || $status === 'inactive') {
                $filtered->where('is_active', 0);
            }
        }

        // Optional warehouse filter. Every product has a stock row in every
        // warehouse (they are created together), so "has a row there" matched
        // the whole catalogue. A product is in a warehouse when it is its home
        // or when it actually holds stock there.
        if ($warehouseId) {
            $filtered->visibleInWarehouses([$warehouseId]);
        }

        // Optional Software License filter: license=1 (any key source), pool, generated.
        if ($request->filled('license') && \App\Services\LicenseKeys\LicenseFulfilment::ready()) {
            $lic = (string) $request->license;
            in_array($lic, ['pool', 'generated'], true)
                ? $filtered->where('products.license_mode', $lic)
                : $filtered->whereIn('products.license_mode', ['pool', 'generated']);
        }

        $totalRows = (clone $filtered)->count();
        if ($perPage === -1) {
            $perPage = $totalRows;
        }

        $products = $filtered->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $hasVariantWholesale = Schema::hasColumn('product_variants', 'wholesale');
        $hasVariantMinPrice = Schema::hasColumn('product_variants', 'min_price');

        // Stock and variants for the whole page in two queries, instead of a
        // SUM per product and a variant lookup per variable product.
        $qtyByProduct = product_warehouse::whereIn('product_id', $products->pluck('id'))
            ->whereNull('deleted_at')
            ->when($warehouseId,
                fn ($q) => $q->where('warehouse_id', $warehouseId),
                // ✅ if no warehouse filter selected, keep qty within allowed warehouses only
                fn ($q) => $q->when(! $user_auth->is_all_warehouses, fn ($q) => $q->whereIn('warehouse_id', $allowedWarehouseIds)))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qte) as qty')
            ->pluck('qty', 'product_id');

        $variantProductIds = $products->where('type', 'is_variant')->pluck('id');
        $variantsByProduct = $variantProductIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('product_id', $variantProductIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id');

        $data = [];
        foreach ($products as $product) {
            $item = [];

            $item['id'] = $product->id;
            $item['code'] = $product->code;
            $item['category'] = optional($product->category)->name;
            $item['sub_category'] = optional($product->subCategory)->name;
            $item['categories_display'] = $product->categories->isNotEmpty()
                ? $product->categories->pluck('name')->filter()->unique()->values()->implode("\n")
                : ($item['category'] ?? '');
            $item['brand'] = $product->brand ? $product->brand->name : 'N/D';
            // Default supplier — shown as a column and used to reorder.
            $item['provider_id'] = $product->provider_id;
            $item['provider_name'] = $product->provider ? $product->provider->name : null;
            // Home warehouse — shown as a column while the ownership switch is on.
            $item['warehouse_id'] = $product->warehouse_id;
            $item['warehouse_name'] = $product->warehouse ? $product->warehouse->name : null;

            $isActive = (int) ($product->is_active ?? 1) === 1;
            $item['status'] = $isActive ? __('Active') : __('Inactif');
            $item['is_active'] = $isActive;

            $firstimage = explode(',', (string) $product->image);
            $item['image'] = $firstimage[0] ?? '';

            if ($product->type === 'is_single') {

                $item['type'] = 'Single';
                $item['name'] = $product->name; // PLAIN TEXT
                // IMPORTANT: keep backend numeric formatting *machine‑friendly*:
                // - 2 decimals
                // - NO thousands separator (third param '.', fourth param '')
                // So the frontend priceFormat helper can safely re‑format.
                $item['cost'] = number_format((float) $product->cost, helpers::price_decimals(), '.', '');
                $item['price'] = number_format((float) $product->price, helpers::price_decimals(), '.', '');
                $item['wholesale_price'] = number_format((float) $product->wholesale_price, helpers::price_decimals(), '.', '');
                $item['min_price'] = number_format((float) $product->min_price, helpers::price_decimals(), '.', '');
                $item['unit'] = optional($product->unit)->ShortName;

                $qty = $qtyByProduct[$product->id] ?? 0;

                $item['quantity'] = number_format((float) $qty, 2, '.', '').' '.$item['unit'];

            } elseif ($product->type === 'is_combo') {

                $item['type'] = 'Combo';
                $item['name'] = $product->name; // PLAIN TEXT
                $item['cost'] = number_format((float) $product->cost, helpers::price_decimals(), '.', '');
                $item['price'] = number_format((float) $product->price, helpers::price_decimals(), '.', '');
                $item['wholesale_price'] = number_format((float) $product->wholesale_price, helpers::price_decimals(), '.', '');
                $item['min_price'] = number_format((float) $product->min_price, helpers::price_decimals(), '.', '');
                $item['unit'] = optional($product->unit)->ShortName;

                $qty = $qtyByProduct[$product->id] ?? 0;

                $item['quantity'] = number_format((float) $qty, 2, '.', '').' '.$item['unit'];

            } elseif ($product->type === 'is_variant') {

                $item['type'] = 'Variable';

                $variants = $variantsByProduct->get($product->id, collect());

                // For variant products, display the parent product name
                $item['name'] = $product->name;
                $item['cost'] = $variants
                    ->map(fn ($v) => number_format((float) $v->cost, helpers::price_decimals(), '.', ''))
                    ->implode("\n");
                $item['price'] = $variants
                    ->map(fn ($v) => number_format((float) $v->price, helpers::price_decimals(), '.', ''))
                    ->implode("\n");
                $item['wholesale_price'] = $hasVariantWholesale
                    ? $variants
                        ->map(fn ($v) => number_format((float) $v->wholesale, helpers::price_decimals(), '.', ''))
                        ->implode("\n")
                    : null;
                $item['min_price'] = $hasVariantMinPrice
                    ? $variants
                        ->map(fn ($v) => number_format((float) $v->min_price, helpers::price_decimals(), '.', ''))
                        ->implode("\n")
                    : null;
                $item['unit'] = optional($product->unit)->ShortName;

                $qty = $qtyByProduct[$product->id] ?? 0;

                $item['quantity'] = number_format((float) $qty, 2, '.', '').' '.$item['unit'];

            } else {

                $item['type'] = 'Service';
                $item['name'] = $product->name; // PLAIN TEXT
                $item['cost'] = '----';
                $item['quantity'] = '----';
                $item['unit'] = '----';
                $item['price'] = number_format((float) $product->price, helpers::price_decimals(), '.', '');
            }

            // Software License badge (none | pool | generated).
            $item['license_mode'] = $product->license_mode ?? 'none';

            $data[] = $item;
        }

        // $warehouses (the user's) was resolved at the top of the method.
        $categories = Category::whereNull('deleted_at')->get(['id', 'name']);
        $subcategories = SubCategory::orderBy('name')->get(['id', 'name', 'category_id']);
        $brands = Brand::whereNull('deleted_at')->get(['id', 'name']);

        // Supplier column + filter (Settings -> Features). Skipped entirely when
        // the feature is off, so the list costs nothing extra.
        $setting = Setting::whereNull('deleted_at')->first();
        $product_supplier_enabled = (bool) ($setting->product_supplier_enabled ?? false);
        $restrict_products_by_warehouse = (bool) ($setting->restrict_products_by_warehouse ?? false);
        $providers = $product_supplier_enabled
            ? \App\Models\Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
            : collect();

        return response()->json([
            'warehouses' => $warehouses,
            'categories' => $categories,
            'subcategories' => $subcategories,
            'brands' => $brands,
            'providers' => $providers,
            'product_supplier_enabled' => $product_supplier_enabled,
            'restrict_products_by_warehouse' => $restrict_products_by_warehouse,
            'products' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    // ------------------------------- WAREHOUSE LOCATIONS (quick-add from product forms) --------------------------\\
    // This endpoint exists to allow creating locations from Create/Edit Product without relying on
    // WarehouseLocationController authorization.
    public function storeWarehouseLocation(Request $request)
    {
        // Allow users who can create products to add location hints
        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $allowedWarehouseIds = Warehouse::whereNull('deleted_at')->pluck('id')->toArray();
        } else {
            $allowedWarehouseIds = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
        }

        $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::in($allowedWarehouseIds)],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('warehouse_locations')->where(function ($q) use ($request) {
                    return $q->whereNull('deleted_at')
                        ->where('warehouse_id', $request->warehouse_id);
                }),
            ],
            'name' => ['nullable', 'string', 'max:192'],
        ]);

        $loc = WarehouseLocation::create([
            'warehouse_id' => (int) $request->warehouse_id,
            'code' => trim((string) $request->code),
            'name' => $request->name ? trim((string) $request->name) : null,
            'is_active' => 1,
        ]);

        return response()->json([
            'success' => true,
            'location' => $loc,
        ]);
    }

    /**
     * Size guide from the product form ('' / 'null' clears it). Skipped until
     * the size_guides migration has run, and only an active guide is accepted.
     */
    private function applySizeGuide(Product $Product, Request $request): void
    {
        if (! \App\Models\SizeGuide::tablesReady() || ! $request->has('size_guide_id')) {
            return;
        }
        $id = (int) $request->input('size_guide_id');
        $Product->size_guide_id = $id > 0 && \App\Models\SizeGuide::whereNull('deleted_at')->whereKey($id)->exists() ? $id : null;
    }

    /** True when the tenant decodes weighing-scale labels (and the 2026_10_27_000004 migration ran). */
    private static function scaleBarcodeEnabled(): bool
    {
        if (! Schema::hasColumn('products', 'scale_plu')) {
            return false;
        }

        return (bool) optional(\App\Models\PosSetting::whereNull('deleted_at')->first())->scale_barcode_enabled;
    }

    /**
     * Weighing scale: the item code a counter scale prints for this product
     * (System Settings > Weighing scale). Stored without leading zeros so a
     * label's "00042" finds "42". Standard products only — the scale can't
     * tell variants apart.
     */
    private function applyScalePlu(Product $Product, Request $request): void
    {
        if (! Schema::hasColumn('products', 'scale_plu')) {
            return;
        }
        // Changed into a combo/service/variant product: the code no longer applies,
        // even when the form didn't send the field (scale switched off).
        if ($Product->type !== 'is_single') {
            $Product->scale_plu = null;

            return;
        }
        if (! $request->has('scale_plu')) {
            return;
        }
        $plu = trim((string) $request->input('scale_plu'));
        if ($plu === '' || $plu === 'null') {
            $Product->scale_plu = null;

            return;
        }
        if (! preg_match('/^\d{1,8}$/', $plu)) {
            throw ValidationException::withMessages(['scale_plu' => __('The scale PLU must be 1 to 8 digits.')]);
        }
        $plu = ltrim($plu, '0') === '' ? '0' : ltrim($plu, '0');

        $takenBy = Product::whereNull('deleted_at')
            ->where('scale_plu', $plu)
            ->when($Product->exists, fn ($q) => $q->whereKeyNot($Product->id))
            ->value('name');
        if ($takenBy !== null) {
            throw ValidationException::withMessages(['scale_plu' => __('Scale PLU :plu is already used by :name.', ['plu' => $plu, 'name' => $takenBy])]);
        }
        $Product->scale_plu = $plu;
    }

    /**
     * Car Dealership theme: the form's "Vehicle details" card arrives as a
     * `vehicle_spec` JSON object. Saved only while that theme is the store's
     * theme and its migration has run; an empty card removes the row.
     */
    private function applyVehicleSpec(Product $Product, Request $request): void
    {
        if (! $request->has('vehicle_spec') || ! self::vehicleSpecsEnabled()) {
            return;
        }
        $raw = $request->input('vehicle_spec');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($raw)) {
            return;
        }
        $clean = \App\Models\VehicleSpec::clean($raw);
        $hasData = collect($clean)->except(['condition', 'reservable', 'is_sold', 'features'])->filter(fn ($v) => $v !== null)->isNotEmpty()
            || $clean['features'] || $clean['is_sold'];
        if (! $hasData) {
            \App\Models\VehicleSpec::where('product_id', $Product->id)->delete();

            return;
        }
        \App\Models\VehicleSpec::updateOrCreate(['product_id' => $Product->id], $clean);
    }

    /**
     * Restaurant module: the form's "Dish details" card arrives as a
     * `dish_details` JSON object ({station_id, prep_minutes, calories,
     * spice_level, dietary[], meal_periods[], addon_group_ids[]}). Saved only
     * while the module is on and its menu tables exist.
     */
    /**
     * Software License: the form's "Software License" type arrives as a
     * `license` JSON object {mode: none|pool|generated, policy: {...},
     * variant_policies: [{variant_name, ...}]}. The product keeps its real type
     * (is_single / is_variant); generated-key products never run out (always in
     * stock, stock not managed). Saved only on plans with `license_keys`.
     */
    private function applyLicense(Product $Product, Request $request): void
    {
        if (! $request->has('license') || ! \App\Services\LicenseKeys\LicenseFulfilment::ready()) {
            return;
        }
        $raw = $request->input('license');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($raw)) {
            return;
        }

        $old = $Product->license_mode ?: 'none';
        $mode = in_array($raw['mode'] ?? 'none', ['none', 'pool', 'generated'], true) ? $raw['mode'] : 'none';
        if (! in_array($Product->type, ['is_single', 'is_variant'], true)) {
            $mode = 'none';
        }
        if ($mode !== 'none' && ! app(\App\Services\TenantLimitsService::class)->hasFeature('license_keys')) {
            $mode = $old;
        }

        // Uploaded keys still in stock would be stranded by a mode change.
        if ($old === 'pool' && $mode !== 'pool') {
            $inStock = \App\Models\LicenseKey::where('product_id', $Product->id)
                ->whereIn('status', [\App\Models\LicenseKey::STATUS_AVAILABLE, \App\Models\LicenseKey::STATUS_RETURNED])
                ->count();
            if ($inStock > 0) {
                throw ValidationException::withMessages(['license' => [
                    __(':n uploaded license keys are still in stock — sell or write them off before changing the key source.', ['n' => $inStock]),
                ]]);
            }
        }

        // Leaving "generated": its stock figures were never real (sales ran it
        // negative with stock unmanaged) — start again from 0, managed.
        if ($old === 'generated' && $mode !== 'generated') {
            product_warehouse::where('product_id', $Product->id)->update(['qte' => 0, 'manage_stock' => 1]);
            $Product->always_in_stock = 0;
        }

        // Uploaded keys ARE the stock (qte == keys on hand), so the product must
        // start from 0: stock it holds now would sell keys that do not exist.
        if ($mode === 'pool' && $old !== 'pool') {
            $qty = (float) product_warehouse::where('product_id', $Product->id)->whereNull('deleted_at')->sum('qte');
            if (abs($qty) > 0.0001) {
                throw ValidationException::withMessages(['license' => [
                    __('This product has :n in stock. Adjust its stock to 0 first — with uploaded license keys, the keys you add become its stock.', ['n' => round($qty, 3)]),
                ]]);
            }
        }

        $Product->license_mode = $mode;
        if ($mode === 'generated') {
            $Product->always_in_stock = 1;
        } elseif ($mode === 'pool') {
            // Never oversold: a sale needs a real key.
            $Product->always_in_stock = 0;
        }
        $Product->save();

        if ($mode === 'generated') {
            product_warehouse::where('product_id', $Product->id)->update(['manage_stock' => 0]);
        }

        if ($mode !== 'generated') {
            return;
        }

        $clean = function ($p): array {
            $p = is_array($p) ? $p : [];
            $duration = in_array($p['duration_type'] ?? null, \App\Models\LicensePolicy::DURATIONS, true) ? $p['duration_type'] : 'lifetime';
            $restriction = in_array($p['restriction'] ?? null, \App\Models\LicensePolicy::RESTRICTIONS, true) ? $p['restriction'] : 'none';
            $max = isset($p['max_activations']) && $p['max_activations'] !== '' && $p['max_activations'] !== null
                ? max(1, min(100000, (int) $p['max_activations'])) : null;
            $format = isset($p['key_format']) ? trim((string) $p['key_format']) : '';
            if (! \App\Services\LicenseKeys\LicenseKeyGenerator::validFormat($format)) {
                throw ValidationException::withMessages(['license' => [
                    __('The key format is too easy to guess: use at least 13 X (random letter or digit) — a 9 counts less — and at most 64 characters.'),
                ]]);
            }

            return [
                'key_format' => $format !== '' ? $format : null,
                'duration_type' => $duration,
                'duration_value' => max(1, min(1000, (int) ($p['duration_value'] ?? 1))),
                'max_activations' => $max,
                'restriction' => $restriction,
            ];
        };

        \App\Models\LicensePolicy::updateOrCreate(
            ['product_id' => $Product->id, 'product_variant_id' => null],
            $clean($raw['policy'] ?? [])
        );

        // Per-edition rules, matched to the saved variants by name.
        $byName = [];
        foreach ((array) ($raw['variant_policies'] ?? []) as $vp) {
            if (is_array($vp) && ! empty($vp['variant_name']) && ! empty($vp['override'])) {
                $byName[mb_strtolower(trim((string) $vp['variant_name']))] = $vp;
            }
        }
        $variants = $Product->type === 'is_variant'
            ? ProductVariant::where('product_id', $Product->id)->whereNull('deleted_at')->get(['id', 'name'])
            : collect();
        foreach ($variants as $v) {
            $vp = $byName[mb_strtolower(trim((string) $v->name))] ?? null;
            if ($vp) {
                \App\Models\LicensePolicy::updateOrCreate(
                    ['product_id' => $Product->id, 'product_variant_id' => $v->id],
                    $clean($vp)
                );
            } else {
                \App\Models\LicensePolicy::where('product_id', $Product->id)->where('product_variant_id', $v->id)->delete();
            }
        }
        \App\Models\LicensePolicy::where('product_id', $Product->id)
            ->whereNotNull('product_variant_id')
            ->whereNotIn('product_variant_id', $variants->pluck('id'))
            ->delete();
    }

    /** Edit-form payload for the Software License card. */
    private function licensePayload(Product $Product): array
    {
        if (! \App\Services\LicenseKeys\LicenseFulfilment::ready()) {
            return ['mode' => 'none', 'policy' => null, 'variant_policies' => [], 'keys_in_stock' => 0];
        }
        $policies = \App\Models\LicensePolicy::where('product_id', $Product->id)->get();
        $base = $policies->firstWhere('product_variant_id', null);
        $names = ProductVariant::whereIn('id', $policies->pluck('product_variant_id')->filter())->pluck('name', 'id');
        $shape = fn ($p) => [
            'key_format' => $p->key_format ?? '',
            'duration_type' => $p->duration_type,
            'duration_value' => (int) $p->duration_value,
            'max_activations' => $p->max_activations,
            'restriction' => $p->restriction,
        ];

        return [
            'mode' => $Product->license_mode ?: 'none',
            'policy' => $base ? $shape($base) : null,
            'variant_policies' => $policies->whereNotNull('product_variant_id')->map(fn ($p) => ['variant_name' => $names[$p->product_variant_id] ?? '', 'override' => true] + $shape($p))->values()->all(),
            'keys_in_stock' => \App\Models\LicenseKey::where('product_id', $Product->id)->where('status', \App\Models\LicenseKey::STATUS_AVAILABLE)->count(),
        ];
    }

    private function applyDishDetails(Product $Product, Request $request): void
    {
        if (! $request->has('dish_details') || ! \App\Support\RestaurantModule::menuReady() || ! self::canEditDishes()) {
            return;
        }
        $raw = $request->input('dish_details');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($raw)) {
            return;
        }
        // Left out = keep the dish's add-on groups; an empty list removes them.
        $groups = array_key_exists('addon_group_ids', $raw) ? (is_array($raw['addon_group_ids']) ? $raw['addon_group_ids'] : []) : null;
        app(\App\Services\Restaurant\MenuService::class)->saveDish($Product->id, $raw, $groups);
    }

    /** The "Dish details" card is for staff allowed to edit the restaurant menu (rst_menu). */
    private static function canEditDishes(): bool
    {
        $user = request()->user('api');

        return $user && $user->can('rst_menu', \App\Models\RestaurantSetting::class);
    }

    /** Product form payload for the "Dish details" card (null when the restaurant menu is off). */
    private static function dishFormData(?int $productId): ?array
    {
        if (! \App\Support\RestaurantModule::menuReady() || ! self::canEditDishes()) {
            return null;
        }
        $d = $productId ? \App\Models\RestaurantDishDetail::where('product_id', $productId)->first() : null;

        return [
            'stations' => \App\Models\RestaurantStation::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'addon_groups' => \App\Models\RestaurantAddonGroup::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'min_select', 'max_select']),
            'details' => [
                'station_id' => $d->station_id ?? null,
                'prep_minutes' => $d->prep_minutes ?? null,
                'calories' => $d->calories ?? null,
                'spice_level' => $d->spice_level ?? null,
                'dietary' => array_values($d->dietary ?? []),
                'meal_periods' => array_values($d->meal_periods ?? []),
                'addon_group_ids' => $productId ? \Illuminate\Support\Facades\DB::table('rst_dish_addon_group')->where('product_id', $productId)->orderBy('sort_order')->pluck('group_id')->map(fn ($v) => (int) $v)->all() : [],
            ],
        ];
    }

    /**
     * The product form shows "Specifications" (products.specs + video_url) when
     * the column exists and the plan includes premium store themes — so owners
     * can fill specs in before switching to Electronics Premium.
     */
    private static function storeSpecsEnabled(): bool
    {
        return \App\Support\StoreSpecs::ready() && \App\Support\StoreThemes::allowedPremium() !== [];
    }

    /**
     * "Specifications" card: `store_specs` JSON rows + `product_video_url`.
     * Left out of the request = unchanged; an empty list clears the specs.
     */
    private function applyStoreSpecs(Product $Product, Request $request): void
    {
        if (! self::storeSpecsEnabled()) {
            return;
        }
        if ($request->has('store_specs')) {
            $rows = \App\Support\StoreSpecs::clean($request->input('store_specs'));
            $Product->specs = $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE) : null;
        }
        if ($request->has('product_video_url') && \App\Support\StoreSpecs::videoReady()) {
            $url = trim((string) $request->input('product_video_url'));
            if ($url !== '' && $url !== 'null' && ! \App\Support\PremiumElectronicsTheme::safeUrl($url)) {
                throw ValidationException::withMessages(['product_video_url' => __('The video link must start with http:// or https://.')]);
            }
            $Product->video_url = ($url === '' || $url === 'null') ? null : mb_substr($url, 0, 500);
        }
        if ($Product->isDirty(['specs', 'video_url'])) {
            $Product->save();
            \App\Support\StoreSpecs::forgetLabels();
        }
    }

    /** Spec groups + labels already used in the catalogue, for the form's autocomplete. */
    private static function storeSpecLabelSuggestions(): array
    {
        $groups = [];
        $labels = [];
        \Illuminate\Support\Facades\DB::table('products')->whereNull('deleted_at')->whereNotNull('specs')
            ->orderByDesc('id')->limit(300)->pluck('specs')
            ->each(function ($json) use (&$groups, &$labels) {
                foreach (\App\Support\StoreSpecs::clean(json_decode((string) $json, true)) as $r) {
                    if ($r['group'] !== '') {
                        $groups[$r['group']] = true;
                    }
                    $labels[$r['label']] = true;
                }
            });

        return ['groups' => array_slice(array_keys($groups), 0, 40), 'labels' => array_slice(array_keys($labels), 0, 80)];
    }

    /** The product form shows "Vehicle details" when the store runs the Car Dealership theme. */
    private static function vehicleSpecsEnabled(): bool
    {
        return \App\Models\VehicleSpec::tablesReady()
            && \App\Models\StoreSetting::query()->value('theme') === \App\Support\CarsTheme::KEY;
    }

    /**
     * Store an uploaded variant image under the tenant-aware products upload dir (same convention as product images).
     * Resizes to 800x800 (keeping aspect ratio). Returns the stored filename, or 'no-image.png' when no/invalid file.
     */
    private function storeVariantImage($file): string
    {
        if (! $file) {
            return 'no-image.png';
        }

        try {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $ext = 'jpg';
            }
            $filename = rand(11111111, 99999999).'.'.$ext;

            $path = upload_public_path('products');
            if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
                \Illuminate\Support\Facades\File::makeDirectory($path, 0755, true);
            }

            $image_resize = Image::make($file->getRealPath());
            helpers::apply_product_image_resize($image_resize)->save($path.'/'.$filename);

            return $filename;
        } catch (\Throwable $e) {
            return 'no-image.png';
        }
    }

    // -------------- Store new  Product  ---------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        try {

            // define validation rules for product
            $productRules = [
                'code' => [
                    'required',
                    Rule::unique('products')->where(function ($query) {
                        return $query->where('deleted_at', '=', null);
                    }),

                    Rule::unique('product_variants')->where(function ($query) {
                        return $query->where('deleted_at', '=', null);
                    }),
                ],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^[^<>]*$/', // prevents <script>, <details>, etc.
                ],
                'Type_barcode' => 'required',
                'gtin' => 'nullable|string|max:64',
                'hs_code' => 'nullable|string|max:20',
                'fbr_uom' => 'nullable|string|max:100',
                'category_id' => 'required',
                'type' => 'required',
                'tax_method' => 'required',
                'discount_method' => 'required',
                'sub_category_id' => 'nullable|integer',
                'sub_category_id' => 'nullable|integer',
                'unit_id' => Rule::requiredIf($request->type != 'is_service'),
                'cost' => Rule::requiredIf($request->type == 'is_single' || $request->type == 'is_combo'),
                'price' => Rule::requiredIf($request->type != 'is_variant'),
            ];

            // Combo products must carry at least one component product
            if ($request->type == 'is_combo') {
                $productRules['materiels'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;
                        if (! is_array($decoded) || empty($decoded)) {
                            $fail('Add at least one product to the combo.');
                        }
                    },
                ];
            }

            // if type is not is_variant, add validation for variants array
            if ($request->type == 'is_variant') {
                $productRules['variants'] = [
                    'required',
                    function ($attribute, $value, $fail) use ($request) {
                        // check if array is not empty
                        if (empty($value)) {
                            $fail('The variants array is required.');

                            return;
                        }

                        // check for duplicate codes in variants array
                        $variants = json_decode($request->variants, true);

                        if ($variants) {
                            foreach ($variants as $variant) {
                                if (! array_key_exists('text', $variant) || empty($variant['text'])) {
                                    $fail('Variant Name cannot be empty.');

                                    return;
                                } elseif (! array_key_exists('code', $variant) || empty($variant['code'])) {
                                    $fail('Variant code cannot be empty.');

                                    return;
                                } elseif (! array_key_exists('cost', $variant) || empty($variant['cost'])) {
                                    $fail('Variant cost cannot be empty.');

                                    return;
                                } elseif (! array_key_exists('price', $variant) || empty($variant['price'])) {
                                    $fail('Variant price cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('The variants data is invalid.');

                            return;
                        }

                        // check if variant name empty
                        $names = array_column($variants, 'text');
                        if ($names) {
                            foreach ($names as $name) {
                                if (empty($name)) {
                                    $fail('Variant Name cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant Name cannot be empty.');

                            return;
                        }

                        // check if variant cost empty
                        $all_cost = array_column($variants, 'cost');
                        if ($all_cost) {
                            foreach ($all_cost as $cost) {
                                if (empty($cost)) {
                                    $fail('Variant Cost cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant Cost cannot be empty.');

                            return;
                        }

                        // check if variant price empty
                        $all_price = array_column($variants, 'price');
                        if ($all_price) {
                            foreach ($all_price as $price) {
                                if (empty($price)) {
                                    $fail('Variant Price cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant Price cannot be empty.');

                            return;
                        }

                        // check if code empty
                        $codes = array_column($variants, 'code');
                        if ($codes) {
                            foreach ($codes as $code) {
                                if (empty($code)) {
                                    $fail('Variant code cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant code cannot be empty.');

                            return;
                        }

                        // check if code Duplicate
                        if (count(array_unique($codes)) !== count($codes)) {
                            $fail('Duplicate codes found in variants array.');

                            return;
                        }

                        // check for duplicate codes in product_variants table
                        $duplicateCodes = DB::table('product_variants')
                            ->whereIn('code', $codes)
                            ->whereNull('deleted_at')
                            ->pluck('code')
                            ->toArray();
                        if (! empty($duplicateCodes)) {
                            $fail('This code : '.implode(', ', $duplicateCodes).' already used');
                        }

                        // check for duplicate codes in products table
                        $duplicateCodes_products = DB::table('products')
                            ->whereIn('code', $codes)
                            ->whereNull('deleted_at')
                            ->pluck('code')
                            ->toArray();
                        if (! empty($duplicateCodes_products)) {
                            $fail('This code : '.implode(', ', $duplicateCodes_products).' already used');
                        }
                    },
                ];
            }

            // Additional barcodes share one namespace with product / variant codes.
            $this->addBarcodeRules($productRules, $request, null);

            // validate the request data
            $validatedData = $request->validate($productRules, [
                'code.unique' => 'Product code already used.',
                'code.required' => 'This field is required',
            ]);

            // Shopify-style options (Color / Size). Empty = legacy flat variant list.
            $optionService = app(ProductOptionService::class);
            $productOptions = $request['type'] == 'is_variant'
                ? $optionService->normalizeOptions($request->input('options'))
                : [];
            $requestVariantRows = $request['type'] == 'is_variant'
                ? array_values((array) json_decode((string) $request->variants, true))
                : [];
            $variantOptionValues = [];
            if ($productOptions) {
                foreach ($requestVariantRows as $variantRow) {
                    $variantOptionValues[] = $optionService->normalizeVariantValues($variantRow['option_values'] ?? null, $productOptions);
                }
                $optionService->validateVariants($productOptions, $variantOptionValues);
            }

            $createdProductId = null;

            \DB::transaction(function () use ($request, $optionService, $productOptions, $variantOptionValues, &$createdProductId) {

                // -- Create New Product
                $Product = new Product;

                // -- Field Required
                $Product->type = $request['type'];
                $Product->name = $request['name'];
                $Product->code = $request['code'];
                $Product->gtin = $request->filled('gtin') ? trim((string) $request->input('gtin')) : null;
                $Product->hs_code = $request->filled('hs_code') ? trim((string) $request->input('hs_code')) : null;
                $Product->fbr_uom = $request->filled('fbr_uom') ? trim((string) $request->input('fbr_uom')) : null;
                $Product->Type_barcode = $request['Type_barcode'];
                $Product->category_id = $request['category_id'];
                $Product->sub_category_id = $request['sub_category_id'] ?? null;
                $Product->brand_id = $request['brand_id'];
                $this->applySizeGuide($Product, $request);
                $this->applyScalePlu($Product, $request);
                // Default supplier (Settings -> Features). 'null' arrives as a
                // string from the multipart form when the select is cleared.
                $Product->provider_id = ($request['provider_id'] ?? null) && $request['provider_id'] !== 'null'
                    ? (int) $request['provider_id']
                    : null;
                // Home warehouse (Settings -> Features -> Restrict products by
                // warehouse): the branch this product was created for.
                $Product->warehouse_id = $this->productHomeWarehouseFor(
                    $request->input('warehouse_id') === 'null' ? null : $request->input('warehouse_id')
                );
                $Product->note = $request['note'];
                $Product->TaxNet = $request['TaxNet'] ? $request['TaxNet'] : 0;
                $Product->tax_method = $request['tax_method'];
                $Product->discount = $request['discount'] ? $request['discount'] : 0;
                $Product->discount_method = $request['discount_method'];

                $Product->points = $request['points'] ? $request['points'] : 0;

                // —————— Warranty & Guarantee ——————
                $Product->warranty_period = $request['warranty_period'] ?? null;
                $Product->warranty_unit = $request['warranty_unit'] ?? null;
                $Product->warranty_terms = $request['warranty_terms'] ?? null;

                // casted boolean
                $Product->has_guarantee = filter_var($request['has_guarantee'], FILTER_VALIDATE_BOOLEAN);
                $Product->guarantee_period = $request['guarantee_period'] ?? null;
                $Product->guarantee_unit = $request['guarantee_unit'] ?? null;

                // -- check if type is_single
                if ($request['type'] == 'is_single' || $request['type'] == 'is_combo') {
                    $Product->price = $request['price'];
                    // ✅ Handle new prices safely (default to 0)
                    $Product->wholesale_price = ! empty($request['wholesale_price']) ? $request['wholesale_price'] : 0;
                    $Product->min_price = ! empty($request['min_price']) ? $request['min_price'] : 0;

                    $Product->cost = $request['cost'];

                    $Product->unit_id = $request['unit_id'];
                    $Product->unit_sale_id = $request['unit_sale_id'] ? $request['unit_sale_id'] : $request['unit_id'];
                    $Product->unit_purchase_id = $request['unit_purchase_id'] ? $request['unit_purchase_id'] : $request['unit_id'];

                    $Product->stock_alert = $request['stock_alert'] ? $request['stock_alert'] : 0;
                    $Product->weight = $request['weight'] ? $request['weight'] : null;
                    $Product->length = $request->filled('length') ? $request['length'] : null;
                    $Product->width = $request->filled('width') ? $request['width'] : null;
                    $Product->height = $request->filled('height') ? $request['height'] : null;

                    $manage_stock = 1;

                    // -- check if type is_variant
                } elseif ($request['type'] == 'is_variant') {

                    $Product->price = 0;
                    $Product->cost = 0;
                    $Product->wholesale_price = 0;
                    $Product->min_price = 0;

                    $Product->unit_id = $request['unit_id'];
                    $Product->unit_sale_id = $request['unit_sale_id'] ? $request['unit_sale_id'] : $request['unit_id'];
                    $Product->unit_purchase_id = $request['unit_purchase_id'] ? $request['unit_purchase_id'] : $request['unit_id'];

                    $Product->stock_alert = $request['stock_alert'] ? $request['stock_alert'] : 0;
                    $Product->weight = $request['weight'] ? $request['weight'] : null;
                    $Product->length = $request->filled('length') ? $request['length'] : null;
                    $Product->width = $request->filled('width') ? $request['width'] : null;
                    $Product->height = $request->filled('height') ? $request['height'] : null;

                    $manage_stock = 1;

                    // -- check if type is_service
                } else {
                    $Product->price = $request['price'];
                    $Product->wholesale_price = ! empty($request['wholesale_price']) ? $request['wholesale_price'] : 0;
                    $Product->min_price = ! empty($request['min_price']) ? $request['min_price'] : 0;
                    $Product->cost = 0;

                    $Product->unit_id = null;
                    $Product->unit_sale_id = null;
                    $Product->unit_purchase_id = null;

                    $Product->stock_alert = 0;
                    $Product->weight = null;

                    $manage_stock = 0;

                }

                // Derived from the product type so any client gets per-variant stock rows.
                $Product->is_variant = ($request['type'] === 'is_variant' || $request['is_variant'] == 'true') ? 1 : 0;
                $Product->is_imei = ($request['is_imei'] == 'true' && in_array($request['type'], ['is_single', 'is_variant'], true)) ? 1 : 0;
                $Product->not_selling = $request['not_selling'] == 'true' ? 1 : 0;
                $Product->is_active = filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $Product->is_featured = $request['is_featured'] == 'true' ? 1 : 0;
                $Product->hide_from_online_store = $request['hide_from_online_store'] == 'true' ? 1 : 0;
                // Product-level overselling: sellable at zero stock even when the global switch is off.
                $Product->always_in_stock = $request['always_in_stock'] == 'true' ? 1 : 0;

                $Product->is_preorder = filter_var($request->input('is_preorder', false), FILTER_VALIDATE_BOOLEAN);
                $Product->preorder_available_date = $Product->is_preorder && $request->filled('preorder_available_date') && $request['preorder_available_date'] !== 'null'
                    ? $request['preorder_available_date'] : null;
                $Product->preorder_limit = $Product->is_preorder && $request->filled('preorder_limit') && $request['preorder_limit'] !== '' && $request['preorder_limit'] !== 'null'
                    ? (int) $request['preorder_limit'] : null;
                $Product->preorder_note = $Product->is_preorder && $request->filled('preorder_note') && $request['preorder_note'] !== 'null'
                    ? $request['preorder_note'] : null;

                // Pharmacy / Batch & Expiry tracking (per-product opt-in; defaults preserve legacy behavior)
                $Product->is_batch_tracked = filter_var($request->input('is_batch_tracked', false), FILTER_VALIDATE_BOOLEAN);
                $Product->shelf_life_days = $request->filled('shelf_life_days') && $request['shelf_life_days'] !== 'null' && $request['shelf_life_days'] !== ''
                    ? (int) $request['shelf_life_days'] : null;
                $Product->generic_name = $request->filled('generic_name') && $request['generic_name'] !== 'null' ? $request['generic_name'] : null;
                $Product->strength = $request->filled('strength') && $request['strength'] !== 'null' ? $request['strength'] : null;
                $Product->dosage_form = $request->filled('dosage_form') && $request['dosage_form'] !== 'null' ? $request['dosage_form'] : null;
                $Product->pack_size = $request->filled('pack_size') && $request['pack_size'] !== 'null' ? $request['pack_size'] : null;
                $Product->manufacturer = $request->filled('manufacturer') && $request['manufacturer'] !== 'null' ? $request['manufacturer'] : null;
                $Product->prescription_required = filter_var($request->input('prescription_required', false), FILTER_VALIDATE_BOOLEAN);
                $Product->drug_schedule = $request->filled('drug_schedule') && $request['drug_schedule'] !== 'null' ? $request['drug_schedule'] : null;

                if ($request->hasFile('image')) {
                    $image = $request->file('image');
                    $ext = strtolower($image->getClientOriginalExtension());
                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $ext = 'jpg';
                    }
                    $filename = rand(11111111, 99999999).'.'.$ext;

                    $path = upload_public_path('products');
                    if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
                        \Illuminate\Support\Facades\File::makeDirectory($path, 0755, true);
                    }

                    $image_resize = Image::make($image->getRealPath());

                    // Resize per System Settings → Features (Resize Product Images)
                    helpers::apply_product_image_resize($image_resize)->save($path.'/'.$filename);

                } else {
                    $filename = 'no-image.png';
                }

                $Product->image = $filename;
                $Product->save();
                $createdProductId = $Product->id;
                if ($request->has('barcodes') && $Product->type !== 'is_variant') {
                    ProductBarcodes::sync($Product->id, null, ProductBarcodes::normalize($request->input('barcodes')));
                }
                $this->applyVehicleSpec($Product, $request);
                $this->applyStoreSpecs($Product, $request);
                $this->applyDishDetails($Product, $request);

                $this->syncProductMultiCategories($request, $Product);

                app(ProductGalleryService::class)->syncAfterCreate($request, $Product, $filename);

                if ($request['type'] == 'is_combo') {
                    $materiels = json_decode($request['materiels'] ?? '[]', true);
                    if (! is_array($materiels)) {
                        $materiels = [];
                    }

                    $syncData = [];
                    foreach ($materiels as $materiel) {
                        if (empty($materiel['product_id'])) {
                            continue;
                        }
                        $syncData[$materiel['product_id']] = ['quantity' => max(1, (float) ($materiel['quantity'] ?? 1))];
                    }

                    // Sync the combined products
                    $Product->combinedProducts()->sync($syncData);
                }

                // Multi-Pack Selling: persist per-product selling packs
                $this->syncProductPacks($request, $Product);

                // Store Variants Product
                if ($request['type'] == 'is_variant') {
                    $variants = json_decode($request->variants);

                    $hasWholesaleColumn = Schema::hasColumn('product_variants', 'wholesale');
                    $hasMinPriceColumn = Schema::hasColumn('product_variants', 'min_price');
                    $hasGtinColumn = Schema::hasColumn('product_variants', 'gtin');
                    $variantImages = $request->file('variant_images', []);
                    $Product_variants_data = [];
                    foreach ($variants as $index => $variant) {
                        $newVariantImage = is_array($variantImages) ? ($variantImages[$index] ?? null) : null;
                        if ($newVariantImage) {
                            $variantImageName = $this->storeVariantImage($newVariantImage);
                        } elseif (isset($variant->image) && $variant->image !== '' && $variant->image !== 'null') {
                            // Preserve an existing filename (e.g. when duplicating a product).
                            $variantImageName = $variant->image;
                        } else {
                            $variantImageName = 'no-image.png';
                        }

                        $row = [
                            'product_id' => $Product->id,
                            'name' => $productOptions
                                ? $optionService->title($productOptions, $variantOptionValues[$index] ?? [])
                                : $variant->text,
                            'cost' => $variant->cost,
                            'price' => $variant->price,
                            'code' => $variant->code,
                            'image' => $variantImageName,
                        ];
                        if ($hasGtinColumn) {
                            $row['gtin'] = isset($variant->gtin) && $variant->gtin !== ''
                                ? $variant->gtin
                                : null;
                        }
                        if ($hasWholesaleColumn) {
                            $row['wholesale'] = isset($variant->wholesale) && $variant->wholesale !== ''
                                ? $variant->wholesale
                                : 0;
                        }
                        if ($hasMinPriceColumn) {
                            $row['min_price'] = isset($variant->min_price) && $variant->min_price !== ''
                                ? $variant->min_price
                                : 0;
                        }
                        $Product_variants_data[] = $row;
                    }
                    if (! empty($Product_variants_data)) {
                        ProductVariant::insert($Product_variants_data);
                    }

                    // Options: create the dimensions and point every variant at
                    // its combination. Bulk insert preserves order, so the
                    // id-ordered variants line up with the request rows.
                    if ($productOptions) {
                        $optionMap = $optionService->sync($Product, $productOptions);
                        $insertedVariants = ProductVariant::where('product_id', $Product->id)
                            ->whereNull('deleted_at')
                            ->orderBy('id')
                            ->get()
                            ->values();
                        foreach ($insertedVariants as $vIndex => $insertedVariant) {
                            $optionService->attach($insertedVariant->id, $variantOptionValues[$vIndex] ?? [], $optionMap);
                        }
                    }
                }

                // Different prices per store (variants exist by now).
                $this->saveStorePrices($request, $Product);

                // 1) gather all warehouse IDs
                $warehouseIds = Warehouse::whereNull('deleted_at')
                    ->pluck('id')
                    ->toArray();

                if ($warehouseIds) {
                    $isSingle = $request->input('type') === 'is_single';
                    $isVariant = $request->input('type') === 'is_variant' || $request->input('is_variant') === 'true';

                    // fetch variants if needed — ordered by id so their position
                    // matches the `variants` payload (bulk-inserted in that order),
                    // which is how `variant_opening` addresses them.
                    $variants = $isVariant
                        ? ProductVariant::where('product_id', $Product->id)
                            ->whereNull('deleted_at')
                            ->orderBy('id')
                            ->get()
                        : collect();

                    // decode the JSON blob you appended from the frontend
                    $payloadWs = json_decode($request->input('warehouses', '[]'), true);
                    if (! is_array($payloadWs)) {
                        $payloadWs = [];
                    }

                    // Variant opening stock: { warehouse_id: [qty by variant position] }
                    $payloadVo = json_decode($request->input('variant_opening', '[]'), true);
                    if (! is_array($payloadVo)) {
                        $payloadVo = [];
                    }

                    // Opening quantities actually recorded, for the virtual
                    // adjustments below: [warehouse_id => [variant_id|0 => qty]]
                    $openingByWarehouse = [];

                    $insertRows = [];

                    foreach ($warehouseIds as $wid) {
                        // grab or default
                        $whData = $payloadWs[$wid] ?? [];

                        $qty = $isSingle
                        ? (float) ($whData['qte'] ?? 0)
                        : 0;

                        if ($isVariant) {
                            $voRow = $payloadVo[$wid] ?? [];
                            foreach ($variants->values() as $index => $variant) {
                                $variantQty = is_array($voRow) && isset($voRow[$index]) && is_numeric($voRow[$index])
                                    ? max(0.0, (float) $voRow[$index])
                                    : 0.0;

                                $insertRows[] = [
                                    'product_id' => $Product->id,
                                    'warehouse_id' => $wid,
                                    'product_variant_id' => $variant->id,
                                    'manage_stock' => $manage_stock,
                                    'qte' => $variantQty,
                                ];

                                if ($variantQty > 0) {
                                    $openingByWarehouse[$wid][$variant->id] = $variantQty;
                                }
                            }
                        } else {
                            if ($isSingle && $qty > 0) {
                                $openingByWarehouse[$wid][0] = $qty;
                            }
                            $insertRows[] = [
                                'product_id' => $Product->id,
                                'warehouse_id' => $wid,
                                'manage_stock' => $manage_stock,
                                'qte' => $qty,
                            ];
                        }
                    }

                    // bulk insert
                    product_warehouse::insert($insertRows);

                    // Store default warehouse location (Rack/Location) per warehouse (no stock-by-location)
                    $now = now();
                    $pwlRows = [];
                    foreach ($warehouseIds as $wid) {
                        $whData = $payloadWs[$wid] ?? [];
                        $locationId = $whData['warehouse_location_id'] ?? null;

                        $pwlRows[] = [
                            'product_id' => $Product->id,
                            'warehouse_id' => $wid,
                            'warehouse_location_id' => $locationId ?: null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if (! empty($pwlRows)) {
                        ProductWarehouseLocation::insert($pwlRows);
                    }

                    /**
                     * Step 5: record opening stock as "virtual" adjustments for COGS/average-cost only.
                     *
                     * We:
                     *  - do NOT touch product_warehouse here (it was already populated above),
                     *  - create minimal Adjustment + AdjustmentDetail rows so ReportController::averageCostBulk
                     *    can see an initial quantity and value when there are no purchases yet.
                     *
                     * This keeps existing stock logic intact while giving the Profit & Loss report
                     * a proper cost basis for products created with opening stock.
                     */
                    if ($manage_stock && ! empty($openingByWarehouse)) {
                        foreach ($openingByWarehouse as $wid => $lines) {
                            // Create a lightweight adjustment header per warehouse
                            $adjRef = app('App\Http\Controllers\AdjustmentController')->getNumberOrder();

                            $adjustment = Adjustment::create([
                                'date' => now()->toDateString(),
                                'time' => now()->toTimeString(),
                                'Ref' => $adjRef,
                                'warehouse_id' => $wid,
                                'items' => count($lines),
                                'notes' => 'Opening stock (auto)',
                                'user_id' => Auth::id(),
                            ]);

                            // One detail row per product/variant using type "add" so
                            // averageCostBulk values it as positive qty
                            foreach ($lines as $variantId => $openingQty) {
                                AdjustmentDetail::create([
                                    'adjustment_id' => $adjustment->id,
                                    'product_id' => $Product->id,
                                    'product_variant_id' => $variantId ?: null,
                                    'quantity' => $openingQty,
                                    'type' => 'add',
                                ]);
                            }

                            // Accounting: opening stock enters Inventory against equity.
                            \App\Services\AccountingV2\StockJournal::adjustment($adjustment, created: true, opening: true);
                        }
                    }
                }

                // Software License: key source + generated-key rules (after variants / stock rows exist).
                $this->applyLicense($Product, $request);

            }, 10);

            WooAutoSync::productSaved($createdProductId);
            OpencartAutoSync::productSaved($createdProductId);

            // The id lets the form finish what had to wait for it — pairing
            // the new product with a Shopify listing picked before saving.
            return response()->json(['success' => true, 'id' => $createdProductId]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    // -------------- Update Product  ---------------\\
    // -----------------------------------------------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        try {

            // define validation rules for product
            $productRules = [
                'code' => [
                    'required',

                    Rule::unique('products')->ignore($id)->where(function ($query) {
                        return $query->where('deleted_at', '=', null);
                    }),

                    Rule::unique('product_variants')->ignore($id, 'product_id')->where(function ($query) {
                        return $query->where('deleted_at', '=', null);
                    }),
                ],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^[^<>]*$/', // prevents <script>, <details>, etc.
                ],
                'gtin' => 'nullable|string|max:64',
                'hs_code' => 'nullable|string|max:20',
                'fbr_uom' => 'nullable|string|max:100',
                'category_id' => 'required',
                'tax_method' => 'required',
                'discount_method' => 'required',
                'type' => 'required',
                'unit_id' => Rule::requiredIf($request->type != 'is_service'),
                'cost' => Rule::requiredIf($request->type == 'is_single' || $request->type == 'is_combo'),
                'price' => Rule::requiredIf($request->type != 'is_variant'),
            ];

            // Combo products must carry at least one component product
            if ($request->type == 'is_combo') {
                $productRules['materiels'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;
                        if (! is_array($decoded) || empty($decoded)) {
                            $fail('Add at least one product to the combo.');
                        }
                    },
                ];
            }

            // if type is not is_variant, add validation for variants array
            if ($request->type == 'is_variant') {
                $productRules['variants'] = [
                    'required',
                    function ($attribute, $value, $fail) use ($request, $id) {
                        // check if array is not empty
                        if (empty($value)) {
                            $fail('The variants array is required.');

                            return;
                        }
                        // check for duplicate codes in variants array
                        $variants = $request->variants;

                        if ($variants) {
                            foreach ($variants as $variant) {
                                if (! array_key_exists('text', $variant) || empty($variant['text'])) {
                                    $fail('Variant Name cannot be empty.');

                                    return;
                                } elseif (! array_key_exists('code', $variant) || empty($variant['code'])) {
                                    $fail('Variant code cannot be empty.');

                                    return;
                                } elseif (! array_key_exists('cost', $variant) || empty($variant['cost'])) {
                                    $fail('Variant cost cannot be empty.');

                                    return;
                                } elseif (! array_key_exists('price', $variant) || empty($variant['price'])) {
                                    $fail('Variant price cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('The variants data is invalid.');

                            return;
                        }

                        // check if variant name empty
                        $names = array_column($variants, 'text');
                        if ($names) {
                            foreach ($names as $name) {
                                if (empty($name)) {
                                    $fail('Variant Name cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant Name cannot be empty.');

                            return;
                        }

                        // check if variant cost empty
                        $all_cost = array_column($variants, 'cost');
                        if ($all_cost) {
                            foreach ($all_cost as $cost) {
                                if (empty($cost)) {
                                    $fail('Variant Cost cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant Cost cannot be empty.');

                            return;
                        }

                        // check if variant price empty
                        $all_price = array_column($variants, 'price');
                        if ($all_price) {
                            foreach ($all_price as $price) {
                                if (empty($price)) {
                                    $fail('Variant Price cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant Price cannot be empty.');

                            return;
                        }

                        // check if code empty
                        $codes = array_column($variants, 'code');
                        if ($codes) {
                            foreach ($codes as $code) {
                                if (empty($code)) {
                                    $fail('Variant code cannot be empty.');

                                    return;
                                }
                            }
                        } else {
                            $fail('Variant code cannot be empty.');

                            return;
                        }

                        // check if code Duplicate
                        if (count(array_unique($codes)) !== count($codes)) {
                            $fail('Duplicate codes found in variants array.');

                            return;
                        }

                        // check for duplicate codes in product_variants table
                        $duplicateCodes = DB::table('product_variants')
                            ->where(function ($query) use ($id) {
                                $query->where('product_id', '<>', $id);
                            })
                            ->whereIn('code', $codes)
                            ->whereNull('deleted_at')
                            ->pluck('code')
                            ->toArray();
                        if (! empty($duplicateCodes)) {
                            $fail('This code : '.implode(', ', $duplicateCodes).' already used');
                        }

                        // check for duplicate codes in products table
                        $duplicateCodes_products = DB::table('products')
                            ->where('id', '!=', $id)
                            ->whereIn('code', $codes)
                            ->whereNull('deleted_at')
                            ->pluck('code')
                            ->toArray();
                        if (! empty($duplicateCodes_products)) {
                            $fail('This code : '.implode(', ', $duplicateCodes_products).' already used');
                        }
                    },
                ];
            }

            // Additional barcodes share one namespace with product / variant codes.
            $this->addBarcodeRules($productRules, $request, (int) $id);

            // validate the request data
            $validatedData = $request->validate($productRules, [
                'code.unique' => 'Product code already used.',
                'code.required' => 'This field is required',
            ]);

            // Shopify-style options (Color / Size). Empty = legacy flat variant list,
            // and also what a product sends when it is switched back to the list.
            $optionService = app(ProductOptionService::class);
            $productOptions = $request['type'] == 'is_variant'
                ? $optionService->normalizeOptions($request->input('options'))
                : [];
            $variantOptionValues = [];
            if ($productOptions) {
                foreach (array_values((array) $request['variants']) as $variantRow) {
                    $variantOptionValues[] = $optionService->normalizeVariantValues(
                        is_array($variantRow) ? ($variantRow['option_values'] ?? null) : null,
                        $productOptions
                    );
                }
                $optionService->validateVariants($productOptions, $variantOptionValues);
            }

            \DB::transaction(function () use ($request, $id, $optionService, $productOptions, $variantOptionValues) {

                $Product = Product::where('id', $id)
                    ->where('deleted_at', '=', null)
                    ->first();

                // A restricted user may only edit products they can see.
                $this->abortIfProductDenied($Product);

                // -- Update Product
                $Product->type = $request['type'];
                $Product->name = $request['name'];
                $Product->code = $request['code'];
                $Product->gtin = $request->filled('gtin') ? trim((string) $request->input('gtin')) : null;
                $Product->hs_code = $request->filled('hs_code') ? trim((string) $request->input('hs_code')) : null;
                $Product->fbr_uom = $request->filled('fbr_uom') ? trim((string) $request->input('fbr_uom')) : null;
                $Product->Type_barcode = $request['Type_barcode'];
                $Product->category_id = $request['category_id'];
                // normalize optional sub-category (can be null/empty)
                $Product->sub_category_id = isset($request['sub_category_id']) && $request['sub_category_id'] !== '' && $request['sub_category_id'] !== 'null'
                    ? $request['sub_category_id']
                    : null;
                $Product->brand_id = $request['brand_id'] == 'null' ? null : $request['brand_id'];
                $this->applySizeGuide($Product, $request);
                $this->applyScalePlu($Product, $request);
                $Product->provider_id = ($request['provider_id'] ?? null) && $request['provider_id'] !== 'null'
                    ? (int) $request['provider_id']
                    : null;
                // Home warehouse: re-assignable within the caller's warehouses.
                // Only a user who may see every warehouse can clear it (the
                // select sends '' / 'null'); a restricted user keeps the value.
                if ($request->has('warehouse_id')) {
                    $requestedHome = $request->input('warehouse_id');
                    if ($requestedHome !== null && $requestedHome !== '' && $requestedHome !== 'null') {
                        $Product->warehouse_id = $this->productHomeWarehouseFor($requestedHome);
                    } elseif ($this->warehouseScopeIds() === null) {
                        $Product->warehouse_id = null;
                    }
                }
                $Product->TaxNet = $request['TaxNet'];
                $Product->tax_method = $request['tax_method'];
                $Product->discount = $request['discount'];
                $Product->discount_method = $request['discount_method'];
                $Product->note = $request['note'];
                $Product->points = $request['points'];

                // ——— Warranty & Guarantee Tracking ———

                // Warranty
                $Product->warranty_period = $request['warranty_period'] !== null
                ? (int) $request['warranty_period']
                : null;
                $Product->warranty_unit = $request['warranty_unit'] ?? null;
                $Product->warranty_terms = $request['warranty_terms'] ?? null;

                // Guarantee
                // If your form posts 'has_guarantee' only when checked, you might need:
                $Product->has_guarantee = filter_var($request['has_guarantee'], FILTER_VALIDATE_BOOLEAN);

                $Product->guarantee_period = $request['guarantee_period'] !== null
                ? (int) $request['guarantee_period']
                : null;
                $Product->guarantee_unit = $request['guarantee_unit'] ?? null;

                // -- check if type is_single
                if ($request['type'] == 'is_single' || $request['type'] == 'is_combo') {
                    $Product->price = $request['price'];
                    $Product->cost = $request['cost'];
                    $Product->wholesale_price = ! empty($request['wholesale_price']) ? $request['wholesale_price'] : 0;
                    $Product->min_price = ! empty($request['min_price']) ? $request['min_price'] : 0;

                    $Product->unit_id = $request['unit_id'];
                    $Product->unit_sale_id = $request['unit_sale_id'] ? $request['unit_sale_id'] : $request['unit_id'];
                    $Product->unit_purchase_id = $request['unit_purchase_id'] ? $request['unit_purchase_id'] : $request['unit_id'];

                    $Product->stock_alert = $request['stock_alert'] ? $request['stock_alert'] : 0;
                    $Product->weight = $request['weight'] ? $request['weight'] : null;
                    $Product->length = $request->filled('length') ? $request['length'] : null;
                    $Product->width = $request->filled('width') ? $request['width'] : null;
                    $Product->height = $request->filled('height') ? $request['height'] : null;
                    $Product->is_variant = 0;

                    $manage_stock = 1;

                    // -- check if type is_variant
                } elseif ($request['type'] == 'is_variant') {

                    $Product->price = 0;
                    $Product->cost = 0;
                    $Product->wholesale_price = 0;
                    $Product->min_price = 0;

                    $Product->unit_id = $request['unit_id'];
                    $Product->unit_sale_id = $request['unit_sale_id'] ? $request['unit_sale_id'] : $request['unit_id'];
                    $Product->unit_purchase_id = $request['unit_purchase_id'] ? $request['unit_purchase_id'] : $request['unit_id'];

                    $Product->stock_alert = $request['stock_alert'] ? $request['stock_alert'] : 0;
                    $Product->weight = $request['weight'] ? $request['weight'] : null;
                    $Product->length = $request->filled('length') ? $request['length'] : null;
                    $Product->width = $request->filled('width') ? $request['width'] : null;
                    $Product->height = $request->filled('height') ? $request['height'] : null;
                    $Product->is_variant = 1;
                    $manage_stock = 1;

                    // -- check if type is_service
                } else {
                    $Product->price = $request['price'];
                    $Product->cost = 0;
                    $Product->wholesale_price = ! empty($request['wholesale_price']) ? $request['wholesale_price'] : 0;
                    $Product->min_price = ! empty($request['min_price']) ? $request['min_price'] : 0;

                    $Product->unit_id = null;
                    $Product->unit_sale_id = null;
                    $Product->unit_purchase_id = null;

                    $Product->stock_alert = 0;
                    $Product->weight = null;
                    $Product->length = null;
                    $Product->width = null;
                    $Product->height = null;
                    $Product->is_variant = 0;
                    $manage_stock = 0;

                }

                if ($request['type'] == 'is_combo') {
                    $materiels = json_decode($request['materiels'] ?? '[]', true);
                    if (! is_array($materiels)) {
                        $materiels = [];
                    }

                    $syncData = [];
                    foreach ($materiels as $materiel) {
                        if (empty($materiel['product_id'])) {
                            continue;
                        }
                        $syncData[$materiel['product_id']] = ['quantity' => max(1, (float) ($materiel['quantity'] ?? 1))];
                    }

                    // Sync the combined products
                    $Product->combinedProducts()->sync($syncData);
                }

                $Product->is_imei = ($request['is_imei'] == 'true' && in_array($request['type'], ['is_single', 'is_variant'], true)) ? 1 : 0;
                $Product->not_selling = $request['not_selling'] == 'true' ? 1 : 0;
                $Product->is_active = filter_var($request->input('is_active', $Product->is_active ?? 1), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $Product->is_featured = $request['is_featured'] == 'true' ? 1 : 0;
                $Product->hide_from_online_store = $request['hide_from_online_store'] == 'true' ? 1 : 0;
                // Product-level overselling: sellable at zero stock even when the global switch is off.
                $Product->always_in_stock = $request['always_in_stock'] == 'true' ? 1 : 0;

                $Product->is_preorder = filter_var($request->input('is_preorder', false), FILTER_VALIDATE_BOOLEAN);
                $Product->preorder_available_date = $Product->is_preorder && $request->filled('preorder_available_date') && $request['preorder_available_date'] !== 'null'
                    ? $request['preorder_available_date'] : null;
                $Product->preorder_limit = $Product->is_preorder && $request->filled('preorder_limit') && $request['preorder_limit'] !== '' && $request['preorder_limit'] !== 'null'
                    ? (int) $request['preorder_limit'] : null;
                $Product->preorder_note = $Product->is_preorder && $request->filled('preorder_note') && $request['preorder_note'] !== 'null'
                    ? $request['preorder_note'] : null;

                // Pharmacy / Batch & Expiry tracking (per-product opt-in; defaults preserve legacy behavior)
                $Product->is_batch_tracked = filter_var($request->input('is_batch_tracked', false), FILTER_VALIDATE_BOOLEAN);
                $Product->shelf_life_days = $request->filled('shelf_life_days') && $request['shelf_life_days'] !== 'null' && $request['shelf_life_days'] !== ''
                    ? (int) $request['shelf_life_days'] : null;
                $Product->generic_name = $request->filled('generic_name') && $request['generic_name'] !== 'null' ? $request['generic_name'] : null;
                $Product->strength = $request->filled('strength') && $request['strength'] !== 'null' ? $request['strength'] : null;
                $Product->dosage_form = $request->filled('dosage_form') && $request['dosage_form'] !== 'null' ? $request['dosage_form'] : null;
                $Product->pack_size = $request->filled('pack_size') && $request['pack_size'] !== 'null' ? $request['pack_size'] : null;
                $Product->manufacturer = $request->filled('manufacturer') && $request['manufacturer'] !== 'null' ? $request['manufacturer'] : null;
                $Product->prescription_required = filter_var($request->input('prescription_required', false), FILTER_VALIDATE_BOOLEAN);
                $Product->drug_schedule = $request->filled('drug_schedule') && $request['drug_schedule'] !== 'null' ? $request['drug_schedule'] : null;

                // Store Variants Product
                $oldVariants = ProductVariant::where('product_id', $id)
                    ->where('deleted_at', null)
                    ->get();

                $warehouses = Warehouse::where('deleted_at', null)
                    ->pluck('id')
                    ->toArray();

                if ($request['type'] == 'is_variant') {

                    // Uploaded variant images (indexed by variant position) — shared by both update branches below.
                    $variantImages = $request->file('variant_images', []);

                    // Options: upsert dimensions/values (empty = flat list, clears them).
                    $optionMap = $optionService->sync($Product, $productOptions);
                    $variantNameFor = function ($key, $variant) use ($optionService, $productOptions, $variantOptionValues) {
                        return $productOptions
                            ? $optionService->title($productOptions, $variantOptionValues[$key] ?? [])
                            : $variant['text'];
                    };
                    // Refuse to drop a variant that still holds stock: its quantity
                    // would silently disappear from every warehouse.
                    $stockedVariants = $optionService->variantsWithStock($oldVariants->pluck('id')->all());

                    if ($oldVariants->isNotEmpty()) {
                        $new_variants_id = [];
                        $var = 'id';

                        foreach ($request['variants'] as $new_id) {
                            if (array_key_exists($var, $new_id)) {
                                $new_variants_id[] = $new_id['id'];
                            } else {
                                $new_variants_id[] = 0;
                            }
                        }

                        foreach ($oldVariants as $key => $value) {
                            $old_variants_id[] = $value->id;

                            // Delete Variant
                            if (! in_array($old_variants_id[$key], $new_variants_id)) {
                                if (isset($stockedVariants[$value->id])) {
                                    throw ValidationException::withMessages([
                                        'variants' => ['Variant "'.$value->name.'" still has '.rtrim(rtrim(number_format($stockedVariants[$value->id], 2, '.', ''), '0'), '.').' in stock. Adjust or transfer it before removing the variant.'],
                                    ]);
                                }
                                $ProductVariant = ProductVariant::findOrFail($value->id);
                                $ProductVariant->deleted_at = Carbon::now();
                                $ProductVariant->save();
                                $optionService->detach((int) $value->id);

                                $ProductWarehouse = product_warehouse::where('product_variant_id', $value->id)
                                    ->update(['deleted_at' => Carbon::now()]);
                            }
                        }

                        $hasGtinColumn = Schema::hasColumn('product_variants', 'gtin');

                        foreach ($request['variants'] as $key => $variant) {
                            $variantGtin = isset($variant['gtin']) && $variant['gtin'] !== ''
                                ? $variant['gtin']
                                : null;

                            // New uploaded image for this variant (by index) overrides the existing filename.
                            $newVariantImage = is_array($variantImages) ? ($variantImages[$key] ?? null) : null;
                            $variantImageName = $newVariantImage
                                ? $this->storeVariantImage($newVariantImage)
                                : ((isset($variant['image']) && $variant['image'] !== '' && $variant['image'] !== 'null')
                                    ? $variant['image']
                                    : 'no-image.png');

                            if (array_key_exists($var, $variant)) {

                                $ProductVariantDT = new ProductVariant;
                                // -- Field Required
                                $ProductVariantDT->product_id = $variant['product_id'];
                                $ProductVariantDT->name = $variantNameFor($key, $variant);
                                $ProductVariantDT->price = $variant['price'];
                                $ProductVariantDT->cost = $variant['cost'];
                                $ProductVariantDT->code = $variant['code'];
                                if (Schema::hasColumn('product_variants', 'wholesale')) {
                                    $ProductVariantDT->wholesale = isset($variant['wholesale']) && $variant['wholesale'] !== ''
                                        ? $variant['wholesale']
                                        : 0;
                                }
                                if (Schema::hasColumn('product_variants', 'min_price')) {
                                    $ProductVariantDT->min_price = isset($variant['min_price']) && $variant['min_price'] !== ''
                                        ? $variant['min_price']
                                        : 0;
                                }

                                $ProductVariantUP['product_id'] = $variant['product_id'];
                                $ProductVariantUP['code'] = $variant['code'];
                                $ProductVariantUP['name'] = $variantNameFor($key, $variant);
                                $ProductVariantUP['price'] = $variant['price'];
                                $ProductVariantUP['cost'] = $variant['cost'];
                                if (Schema::hasColumn('product_variants', 'wholesale')) {
                                    $ProductVariantUP['wholesale'] = isset($variant['wholesale']) && $variant['wholesale'] !== ''
                                        ? $variant['wholesale']
                                        : 0;
                                }
                                if (Schema::hasColumn('product_variants', 'min_price')) {
                                    $ProductVariantUP['min_price'] = isset($variant['min_price']) && $variant['min_price'] !== ''
                                        ? $variant['min_price']
                                        : 0;
                                }

                            } else {
                                $ProductVariantDT = new ProductVariant;

                                // -- Field Required
                                $ProductVariantDT->product_id = $id;
                                $ProductVariantDT->code = $variant['code'];
                                $ProductVariantDT->name = $variantNameFor($key, $variant);
                                $ProductVariantDT->price = $variant['price'];
                                $ProductVariantDT->cost = $variant['cost'];
                                if (Schema::hasColumn('product_variants', 'wholesale')) {
                                    $ProductVariantDT->wholesale = isset($variant['wholesale']) && $variant['wholesale'] !== ''
                                        ? $variant['wholesale']
                                        : 0;
                                }
                                if (Schema::hasColumn('product_variants', 'min_price')) {
                                    $ProductVariantDT->min_price = isset($variant['min_price']) && $variant['min_price'] !== ''
                                        ? $variant['min_price']
                                        : 0;
                                }

                                $ProductVariantUP['product_id'] = $id;
                                $ProductVariantUP['code'] = $variant['code'];
                                $ProductVariantUP['name'] = $variantNameFor($key, $variant);
                                $ProductVariantUP['price'] = $variant['price'];
                                $ProductVariantUP['cost'] = $variant['cost'];
                                $ProductVariantUP['qty'] = 0.00;
                                if (Schema::hasColumn('product_variants', 'wholesale')) {
                                    $ProductVariantUP['wholesale'] = isset($variant['wholesale']) && $variant['wholesale'] !== ''
                                        ? $variant['wholesale']
                                        : 0;
                                }
                                if (Schema::hasColumn('product_variants', 'min_price')) {
                                    $ProductVariantUP['min_price'] = isset($variant['min_price']) && $variant['min_price'] !== ''
                                        ? $variant['min_price']
                                        : 0;
                                }
                            }

                            if ($hasGtinColumn) {
                                $ProductVariantDT->gtin = $variantGtin;
                                $ProductVariantUP['gtin'] = $variantGtin;
                            }

                            $ProductVariantDT->image = $variantImageName;
                            $ProductVariantUP['image'] = $variantImageName;

                            if (! in_array($new_variants_id[$key], $old_variants_id)) {
                                $ProductVariantDT->save();
                                $optionService->attach($ProductVariantDT->id, $variantOptionValues[$key] ?? [], $optionMap);

                                // --Store Product warehouse
                                if ($warehouses) {
                                    $product_warehouse = [];
                                    foreach ($warehouses as $warehouse) {

                                        $product_warehouse[] = [
                                            'product_id' => $id,
                                            'warehouse_id' => $warehouse,
                                            'product_variant_id' => $ProductVariantDT->id,
                                            'manage_stock' => $manage_stock,
                                        ];

                                    }
                                    product_warehouse::insert($product_warehouse);
                                }
                            } else {
                                ProductVariant::where('id', $variant['id'])->update($ProductVariantUP);
                                $optionService->attach((int) $variant['id'], $variantOptionValues[$key] ?? [], $optionMap);
                            }
                        }

                    } else {
                        $ProducttWarehouse = product_warehouse::where('product_id', $id)
                            ->update([
                                'deleted_at' => Carbon::now(),
                            ]);

                        foreach ($request['variants'] as $vIdx => $variant) {
                            $product_warehouse_DT = [];
                            $ProductVarDT = new ProductVariant;

                            // -- Field Required
                            $ProductVarDT->product_id = $id;
                            $ProductVarDT->code = $variant['code'];
                            if (Schema::hasColumn('product_variants', 'gtin')) {
                                $ProductVarDT->gtin = isset($variant['gtin']) && $variant['gtin'] !== ''
                                    ? $variant['gtin']
                                    : null;
                            }
                            $newVarImg = is_array($variantImages) ? ($variantImages[$vIdx] ?? null) : null;
                            $ProductVarDT->image = $newVarImg
                                ? $this->storeVariantImage($newVarImg)
                                : ((isset($variant['image']) && $variant['image'] !== '' && $variant['image'] !== 'null')
                                    ? $variant['image']
                                    : 'no-image.png');
                            $ProductVarDT->name = $variantNameFor($vIdx, $variant);
                            $ProductVarDT->cost = $variant['cost'];
                            $ProductVarDT->price = $variant['price'];
                            if (Schema::hasColumn('product_variants', 'wholesale')) {
                                $ProductVarDT->wholesale = isset($variant['wholesale']) && $variant['wholesale'] !== ''
                                    ? $variant['wholesale']
                                    : 0;
                            }
                            if (Schema::hasColumn('product_variants', 'min_price')) {
                                $ProductVarDT->min_price = isset($variant['min_price']) && $variant['min_price'] !== ''
                                    ? $variant['min_price']
                                    : 0;
                            }
                            $ProductVarDT->save();
                            $optionService->attach($ProductVarDT->id, $variantOptionValues[$vIdx] ?? [], $optionMap);

                            // -- Store Product warehouse
                            if ($warehouses) {
                                foreach ($warehouses as $warehouse) {

                                    $product_warehouse_DT[] = [
                                        'product_id' => $id,
                                        'warehouse_id' => $warehouse,
                                        'product_variant_id' => $ProductVarDT->id,
                                        'manage_stock' => $manage_stock,
                                    ];
                                }

                                product_warehouse::insert($product_warehouse_DT);
                            }
                        }

                    }
                } else {
                    $optionService->clear($Product);
                    if ($oldVariants->isNotEmpty()) {
                        $stockedVariants = $optionService->variantsWithStock($oldVariants->pluck('id')->all());
                        foreach ($oldVariants as $old_var) {
                            if (isset($stockedVariants[$old_var->id])) {
                                throw ValidationException::withMessages([
                                    'type' => ['Variant "'.$old_var->name.'" still has stock. Adjust or transfer it before changing the product type.'],
                                ]);
                            }
                            $var_old = ProductVariant::where('id', $old_var->id)->first();
                            $var_old->deleted_at = Carbon::now();
                            $var_old->save();

                            $ProducttWarehouse = product_warehouse::where('product_variant_id', $old_var['id'])
                                ->update([
                                    'deleted_at' => Carbon::now(),
                                ]);
                        }

                        if ($warehouses) {
                            foreach ($warehouses as $warehouse) {

                                $product_warehouse[] = [
                                    'product_id' => $id,
                                    'warehouse_id' => $warehouse,
                                    'product_variant_id' => null,
                                    'manage_stock' => $manage_stock,
                                ];

                            }
                            product_warehouse::insert($product_warehouse);
                        }
                    }
                }

                $currentImage = $Product->image;

                if ($currentImage && $request->hasFile('image')) {

                    $image = $request->file('image');
                    $path = upload_public_path('products');
                    if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
                        \Illuminate\Support\Facades\File::makeDirectory($path, 0755, true);
                    }
                    $ext = strtolower($image->getClientOriginalExtension());
                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $ext = 'jpg';
                    }
                    $filename = rand(11111111, 99999999).'.'.$ext;

                    // Resize per System Settings → Features (Resize Product Images)
                    $image_resize = Image::make($image->getRealPath());
                    helpers::apply_product_image_resize($image_resize)->save($path.'/'.$filename);

                    // Delete old image if it exists and is not the placeholder
                    $oldImage = $path.'/'.$currentImage;
                    if ($currentImage && $currentImage != 'no-image.png' && file_exists($oldImage)) {
                        @unlink($oldImage);
                    }

                } elseif (! $currentImage && $request->hasFile('image')) {

                    $image = $request->file('image');
                    $path = upload_public_path('products');
                    if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
                        \Illuminate\Support\Facades\File::makeDirectory($path, 0755, true);
                    }
                    $ext = strtolower($image->getClientOriginalExtension());
                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $ext = 'jpg';
                    }
                    $filename = rand(11111111, 99999999).'.'.$ext;

                    $image_resize = Image::make($image->getRealPath());
                    helpers::apply_product_image_resize($image_resize)->save($path.'/'.$filename);

                } else {
                    // Keep existing image or fallback to default
                    $filename = $currentImage ? $currentImage : 'no-image.png';
                }

                $Product->image = $filename;
                $Product->save();
                // Sent only by forms that edit them, so an older client never wipes the list.
                if ($request->has('barcodes') && $Product->type !== 'is_variant') {
                    ProductBarcodes::sync($Product->id, null, ProductBarcodes::normalize($request->input('barcodes')));
                } elseif ($Product->type === 'is_variant') {
                    // Switched to variants: the parent row is never sold, so its
                    // own barcodes would scan to nothing — free them.
                    ProductBarcodes::sync($Product->id, null, []);
                }
                // Variants removed above take their barcodes with them.
                ProductBarcodes::purgeDead();
                $this->applyVehicleSpec($Product, $request);
                $this->applyStoreSpecs($Product, $request);
                $this->applyDishDetails($Product, $request);

                $this->syncProductMultiCategories($request, $Product);

                // Multi-Pack Selling: reconcile per-product selling packs
                $this->syncProductPacks($request, $Product);

                // Different prices per store (after the variants above are saved).
                $this->saveStorePrices($request, $Product);

                app(ProductGalleryService::class)->syncAfterUpdate($request, $Product);

                // Update default warehouse location (Rack/Location) per warehouse (no stock-by-location)
                $payloadLoc = json_decode($request->input('warehouse_locations', '{}'), true);
                if (is_array($payloadLoc)) {
                    $user_auth = auth()->user();
                    if ($user_auth->is_all_warehouses) {
                        $warehouseIds = Warehouse::whereNull('deleted_at')->pluck('id')->toArray();
                    } else {
                        $warehouseIds = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
                    }

                    foreach ($warehouseIds as $wid) {
                        $entry = $payloadLoc[$wid] ?? null;
                        $locId = is_array($entry) ? ($entry['warehouse_location_id'] ?? null) : $entry;
                        ProductWarehouseLocation::updateOrCreate(
                            ['product_id' => $Product->id, 'warehouse_id' => $wid],
                            ['warehouse_location_id' => $locId ?: null]
                        );
                    }
                }

                // Software License: key source + generated-key rules (after variants / stock rows exist).
                $this->applyLicense($Product, $request);

            }, 10);

            WooAutoSync::productSaved($id);
            OpencartAutoSync::productSaved($id);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    // -------------- Remove Product  ---------------\\
    // -----------------------------------------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Product::class);

        \DB::transaction(function () use ($id) {

            $Product = Product::findOrFail($id);
            $this->abortIfProductDenied($Product);

            if (Schema::hasTable('category_product')) {
                $Product->categories()->detach();
            }
            if (Schema::hasTable('product_subcategory')) {
                $Product->subcategories()->detach();
            }

            ProductGalleryService::deleteAllForProduct($Product);

            $pathIMG = upload_public_path('products').'/'.$Product->image;
            if (file_exists($pathIMG)) {
                if ($Product->image != 'no-image.png') {
                    @unlink($pathIMG);
                }
            }

            $Product->deleted_at = Carbon::now();
            $Product->save();

            product_warehouse::where('product_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            ProductVariant::where('product_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            // Its extra barcodes are free again, like its code.
            ProductBarcodes::forget((int) $id);

        }, 10);

        return response()->json(['success' => true]);

    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Product::class);

        \DB::transaction(function () use ($request) {
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $product_id) {

                $Product = Product::findOrFail($product_id);
                $this->abortIfProductDenied($Product);
                $Product->deleted_at = Carbon::now();
                ProductBarcodes::forget((int) $Product->id);

                if (Schema::hasTable('category_product')) {
                    $Product->categories()->detach();
                }
                if (Schema::hasTable('product_subcategory')) {
                    $Product->subcategories()->detach();
                }

                ProductGalleryService::deleteAllForProduct($Product);

                $pathIMG = upload_public_path('products').'/'.$Product->image;
                if (file_exists($pathIMG)) {
                    if ($Product->image != 'no-image.png') {
                        @unlink($pathIMG);
                    }
                }

                $Product->save();

                product_warehouse::where('product_id', $product_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);

                ProductVariant::where('product_id', $product_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);

    }

    // --------------  Show Product Details ---------------\\

    public function Get_Products_Details(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        $helpers = new helpers;

        $Product = Product::with(['category', 'categories', 'subCategory', 'subcategories', 'images'])
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $this->abortIfProductDenied($Product);
        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $item['id'] = $Product->id;
        $item['type'] = $Product->type;
        $item['code'] = $Product->code;
        $item['barcodes'] = ProductBarcodes::for((int) $Product->id);
        $item['gtin'] = $Product->gtin ?? '';
        $item['hs_code'] = $Product->hs_code ?? '';
        $item['fbr_uom'] = $Product->fbr_uom ?? '';
        $item['points'] = $Product->points;
        $item['Type_barcode'] = $Product->Type_barcode;
        $item['name'] = $Product->name;
        $item['note'] = $Product->note;
        $item['category'] = optional($Product->category)->name;
        $item['categories'] = $Product->apiCategoriesList();
        $item['sub_category'] = optional($Product->subCategory)->name;
        $item['subcategories'] = $Product->apiSubcategoriesList();
        $item['brand'] = $Product['brand'] ? $Product['brand']->name : 'N/D';
        $sizeGuide = $Product->activeSizeGuide();
        $item['size_guide'] = $sizeGuide ? ['id' => $sizeGuide->id, 'name' => $sizeGuide->name] : null;
        $item['price'] = $Product->price;
        $item['wholesale_price'] = $Product->wholesale_price;
        $item['min_price'] = $Product->min_price;
        $item['cost'] = $Product->cost;
        $item['stock_alert'] = $Product->stock_alert;
        $item['weight'] = $Product->weight;
        $item['taxe'] = $Product->TaxNet;
        // Both columns are varchar ("1"/"2"), so compare as strings.
        $item['tax_method'] = (string) $Product->tax_method === '2' ? 'Inclusive' : 'Exclusive';
        $symbol = $helpers->Get_Currency_Code();
        $item['discount'] = (string) $Product->discount_method === '2' ? helpers::with_currency($symbol, $Product->discount) : $Product->discount.' '.'%';

        // ——— Warranty & Guarantee ———
        $item['warranty_period'] = $Product->warranty_period;
        $item['warranty_unit'] = $Product->warranty_unit;
        $item['warranty_terms'] = $Product->warranty_terms;

        $item['has_guarantee'] = (bool) $Product->has_guarantee;
        $item['guarantee_period'] = $Product->guarantee_period;
        $item['guarantee_unit'] = $Product->guarantee_unit;

        // Pharmacy: surface batch tracking flag so the detail UI can render the Batches section.
        $item['is_batch_tracked'] = (bool) ($Product->is_batch_tracked ?? false);

        if ($Product->type == 'is_single') {
            $item['type_name'] = 'Single';
            $item['unit'] = $Product['unit']->ShortName;

        } elseif ($Product->type == 'is_variant') {
            $item['type_name'] = 'Variable';
            $item['unit'] = $Product['unit']->ShortName;

        } else {
            $item['type_name'] = 'Service';
            $item['unit'] = '----';

        }

        if ($Product->type == 'is_combo') {
            $combined_products = CombinedProduct::where('product_id', $id)->with('product.unit')->get();

            $materiels = $combined_products->map(function ($combinedProduct) {
                return [
                    'name' => $combinedProduct->product->name,
                    'code' => $combinedProduct->product->code,
                    'quantity' => $combinedProduct->quantity,
                ];
            });

            $item['products_combo_data'] = $materiels;

        }

        $optionService = app(ProductOptionService::class);
        $item['options'] = $Product->is_variant ? $optionService->optionsPayload((int) $id) : [];
        $variantOptionValues = $item['options'] ? $optionService->variantValuesMap((int) $id) : [];

        if ($Product->is_variant) {
            $item['is_variant'] = 'yes';
            $productsVariants = ProductVariant::where('product_id', $id)
                ->where('deleted_at', null)
                ->get();
            foreach ($productsVariants as $variant) {
                $ProductVariant['option_values'] = (object) ($variantOptionValues[$variant->id] ?? []);
                $ProductVariant['code'] = $variant->code;
                $ProductVariant['gtin'] = $variant->gtin ?? null;
                $ProductVariant['image'] = $variant->image ?: 'no-image.png';
                $ProductVariant['name'] = $variant->name;
                $ProductVariant['cost'] = number_format($variant->cost, helpers::price_decimals(), '.', ',');
                $ProductVariant['price'] = number_format($variant->price, helpers::price_decimals(), '.', ',');
                $ProductVariant['wholesale'] = isset($variant->wholesale)
                    ? number_format((float) $variant->wholesale, helpers::price_decimals(), '.', ',')
                    : number_format(0, helpers::price_decimals(), '.', ',');
                $ProductVariant['min_price'] = isset($variant->min_price)
                    ? number_format((float) $variant->min_price, helpers::price_decimals(), '.', ',')
                    : number_format(0, helpers::price_decimals(), '.', ',');

                $item['products_variants_data'][] = $ProductVariant;

                foreach ($warehouses as $warehouse) {
                    $product_warehouse = DB::table('product_warehouse')
                        ->where('product_id', $id)
                        ->where('deleted_at', '=', null)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('product_variant_id', $variant->id)
                        ->select(DB::raw('SUM(product_warehouse.qte) AS sum'))
                        ->first();

                    $war_var['mag'] = $warehouse->name;
                    $war_var['variant'] = $variant->name;
                    $war_var['qte'] = $product_warehouse->sum;
                    $item['CountQTY_variants'][] = $war_var;
                }

            }
        } else {
            $item['is_variant'] = 'no';
            $item['CountQTY_variants'] = [];
        }

        // Virtual combo stock: show what the components can still fill.
        $virtualCombo = $Product->type == 'is_combo' && ComboStockService::enabled();

        foreach ($warehouses as $warehouse) {
            $product_warehouse_data = DB::table('product_warehouse')
                ->where('deleted_at', '=', null)
                ->where('product_id', $id)
                ->where('warehouse_id', $warehouse->id)
                ->select(DB::raw('SUM(product_warehouse.qte) AS sum'))
                ->first();

            $war['mag'] = $warehouse->name;
            $war['qte'] = $virtualCombo
                ? (app(ComboStockService::class)->availability([(int) $id], $warehouse->id)[(int) $id] ?? '---')
                : $product_warehouse_data->sum;
            $item['CountQTY'][] = $war;
        }

        // Different prices per store: the figures set per warehouse (blank = normal price).
        $item['store_prices'] = [];
        if (WarehousePrices::enabled()) {
            $spWarehouses = Warehouse::whereNull('deleted_at')
                ->whereIn('id', $this->storePriceWarehouseIds())->pluck('name', 'id');
            $spVariants = ProductVariant::where('product_id', $id)->whereNull('deleted_at')->pluck('name', 'id');
            $spRows = \App\Models\ProductWarehousePrice::where('product_id', $id)
                ->whereIn('warehouse_id', $spWarehouses->keys())
                ->orderBy('warehouse_id')->orderBy('product_variant_id')->get();
            foreach ($spRows as $sp) {
                if ($sp->product_variant_id && ! isset($spVariants[$sp->product_variant_id])) {
                    continue;
                }
                $item['store_prices'][] = [
                    'warehouse' => $spWarehouses[$sp->warehouse_id],
                    'variant' => $sp->product_variant_id ? $spVariants[$sp->product_variant_id] : null,
                    'price' => $sp->price,
                    'wholesale_price' => $sp->wholesale_price,
                    'min_price' => $sp->min_price,
                ];
            }
        }

        $allimages = $Product->productGalleryFilenames();
        $mainImage = $Product->primaryProductImageFilename();
        if ($mainImage !== '' && ($key = array_search($mainImage, $allimages, true)) !== false && $key !== 0) {
            array_splice($allimages, $key, 1);
            array_unshift($allimages, $mainImage);
        }
        $item['image'] = $mainImage !== '' ? $mainImage : ($allimages[0] ?? '');
        $item['images'] = $allimages;

        $data[] = $item;

        return response()->json($data[0]);

    }

    // ------------ Get products By Warehouse -----------------\\

    public function Products_by_Warehouse(request $request, $id)
    {
        // The warehouse is addressed directly by the URL, so it must be one of
        // the caller's assigned warehouses — otherwise any restricted user can
        // read another warehouse's full stock list by changing the id.
        $this->abortIfWarehouseDenied($id);

        $data = [];

        // Allow Overselling (global Features switch): when ON, zero / negative
        // stock products must still be listed on the sale and quotation forms —
        // otherwise they can never be picked even though selling them is
        // permitted. Only the stock condition is dropped; the service /
        // manage_stock semantics stay untouched.
        $global_setting = Setting::whereNull('deleted_at')->first();
        $allow_overselling = (bool) ($global_setting->allow_overselling ?? false);
        // Virtual combo stock: combos skip the SQL stock filter and are
        // filtered on their component availability after the query.
        $virtualCombos = (bool) ($global_setting->virtual_combo_stock ?? false);
        $stockFiltered = $request->stock == '1' && $request->product_service == '1' && ! $allow_overselling;

        // product.unitSale / unitPurchase are read for every row below: loading
        // them here saves two queries per product (a 21,000-product catalogue
        // took over a minute to open a sale or purchase form).
        $product_warehouse_data = product_warehouse::with('warehouse', 'product.unitSale', 'product.unitPurchase', 'productVariant')
            // Products belong to a warehouse (Settings -> Features): every
            // product has a row in every warehouse, so the picker must also
            // apply the ownership rule or a zero-stock product from another
            // branch would still be offered here.
            ->whereHas('product', fn ($p) => $this->scopeProductsToWarehouses($p))
            ->withoutVariantParentRows()
            ->where(function ($query) use ($request, $id, $allow_overselling, $virtualCombos) {
                return $query->where('warehouse_id', $id)
                    ->where('deleted_at', '=', null)
                    ->where(function ($query) use ($request) {
                        return $query->whereHas('product', function ($q) use ($request) {
                            // Only allow active products in all flows (sales, purchases, etc.)
                            $q->where('is_active', 1);
                            if ($request->is_sale == '1') {
                                $q->where('not_selling', '=', 0);
                            }
                        });
                    })

                    ->where(function ($query) use ($request) {
                        return $query->whereHas('product', function ($q) use ($request) {
                            if (isset($request->product_combo) && $request->product_combo == '1') {
                                $q->whereIn('type', ['is_combo', 'is_single', 'is_variant', 'is_service']);
                            } elseif (! isset($request->product_combo) || $request->product_combo == '0') {
                                $q->whereNotIn('type', ['is_combo']);
                            }
                        });
                    })
                    // Virtual combo stock: the stock-movement forms (adjustment,
                    // damage, transfer ask for product_service=0) can't move a
                    // combo — it has no stock of its own, only its components.
                    ->when($virtualCombos && $request->product_service == '0', fn ($query) => $query->whereHas('product', fn ($q) => $q->where('type', '!=', 'is_combo')))

                    ->where(function ($query) use ($request, $allow_overselling, $virtualCombos) {
                        if ($request->stock == '1' && $request->product_service == '1') {
                            // Overselling ON → services included, no qte filter.
                            return $allow_overselling
                                ? $query
                                : $query->where('qte', '>', 0)->orWhere('manage_stock', false)
                                    // "Always in stock" products stay listed at zero stock.
                                    ->orWhereHas('product', fn ($p) => $p->where('always_in_stock', 1))
                                    ->when($virtualCombos, fn ($q) => $q->orWhereHas('product', fn ($p) => $p->where('type', 'is_combo')));

                        } elseif ($request->stock == '1' && $request->product_service == '0') {
                            // Overselling ON → stock-managed products only, no qte filter.
                            return $allow_overselling
                                ? $query->where('manage_stock', true)
                                : $query->where('qte', '>', 0)->orWhere('manage_stock', true)
                                    ->orWhereHas('product', fn ($p) => $p->where('always_in_stock', 1));

                        } else {
                            return $query->where('manage_stock', true);
                        }
                    });
            })->get();

        if ($virtualCombos) {
            app(ComboStockService::class)->overlayRows($product_warehouse_data);
            if ($stockFiltered) {
                $product_warehouse_data = $product_warehouse_data->reject(fn ($pw) => optional($pw->product)->type === 'is_combo'
                    && $pw->qte <= 0
                    && ! $pw->combo_unlimited
                    && ! $pw->product->always_in_stock
                )->values();
            }
        }

        // Additional barcodes, scanned by the forms' resolveScan(), in one query.
        $barcodeMap = ProductBarcodes::map($product_warehouse_data->pluck('product_id')->all());

        // Different prices per store: this warehouse's own prices, one query.
        $storePriceMap = WarehousePrices::map($id);

        $num = fn ($v) => $v === null ? null : (float) $v;

        foreach ($product_warehouse_data as $product_warehouse) {
            // Plain reads: on this stack every Eloquent attribute read costs ~10 µs
            // (casts are re-resolved each time) and this loop made ~50 per product,
            // so a 21,000-product list took ~20 s to open a sale or purchase form.
            // Raw values, cast exactly as the models cast them (double → float,
            // null kept, booleans for always_in_stock).
            $a = $product_warehouse['product']->getAttributes();
            $prodRow = (object) [
                'code' => $a['code'] ?? null,
                'name' => $a['name'] ?? null,
                'type' => $a['type'] ?? null,
                'image' => $a['image'] ?? null,
                'Type_barcode' => $a['Type_barcode'] ?? null,
                'scale_plu' => $a['scale_plu'] ?? null,
                'always_in_stock' => isset($a['always_in_stock']) ? (bool) $a['always_in_stock'] : null,
                'price' => $num($a['price'] ?? null),
                'wholesale_price' => $num($a['wholesale_price'] ?? null),
                'min_price' => $num($a['min_price'] ?? null),
                'discount' => $num($a['discount'] ?? null),
                'discount_method' => $a['discount_method'] ?? null,
                'TaxNet' => $num($a['TaxNet'] ?? null),
                'tax_method' => $a['tax_method'] ?? null,
            ];
            $unitSale = $product_warehouse['product']->unitSale;
            $unitPurchase = $product_warehouse['product']->unitPurchase;
            $pwRaw = $product_warehouse->getAttributes();
            $pwQte = $num($pwRaw['qte'] ?? null);
            $pwProductId = isset($pwRaw['product_id']) ? (int) $pwRaw['product_id'] : null;
            $pwVariantId = $pwRaw['product_variant_id'] ?? null;
            $pwManageStock = isset($pwRaw['manage_stock']) ? (int) $pwRaw['manage_stock'] : null;
            $variantRaw = $pwVariantId && $product_warehouse['productVariant'] ? $product_warehouse['productVariant']->getAttributes() : [];

            if ($pwVariantId) {
                $item['product_variant_id'] = $pwVariantId;

                $item['code'] = ($variantRaw['code'] ?? null);
                $item['Variant'] = '['.($variantRaw['name'] ?? null).']'.$prodRow->name;
                $item['name'] = '['.($variantRaw['name'] ?? null).']'.$prodRow->name;
                $item['barcode'] = ($variantRaw['code'] ?? null);

                $product_price = $num($variantRaw['price'] ?? null);

            } else {
                $item['product_variant_id'] = null;
                $item['Variant'] = null;
                $item['code'] = $prodRow->code;
                $item['name'] = $prodRow->name;
                $item['barcode'] = $prodRow->code;

                $product_price = $prodRow->price;
            }
            $storeRow = $storePriceMap[WarehousePrices::key($pwProductId, $pwVariantId)] ?? null;
            $product_price = WarehousePrices::pick($storeRow, 'price', $product_price);
            $item['barcodes'] = $barcodeMap[$pwProductId.'|'.($pwVariantId ?? '')] ?? [];

            $item['id'] = $pwProductId;
            $item['product_type'] = $prodRow->type;
            // Weighing-scale item code (standard products only).
            $item['scale_plu'] = ! $pwVariantId && $prodRow->type === 'is_single'
                ? ($prodRow->scale_plu ?? null) : null;
            $item['always_in_stock'] = (bool) ($prodRow->always_in_stock ?? false)
                || (bool) ($pwRaw['combo_unlimited'] ?? null);
            $item['Type_barcode'] = $prodRow->Type_barcode;
            $firstimage = explode(',', $prodRow->image);
            $item['image'] = $firstimage[0];

            if ($unitSale) {

                if ($unitSale->operator == '/') {
                    $item['qte_sale'] = $pwQte * $unitSale->operator_value;
                    $price = $product_price / $unitSale->operator_value;
                } else {
                    $item['qte_sale'] = $pwQte / $unitSale->operator_value;
                    $price = $product_price * $unitSale->operator_value;
                }

            } else {
                $item['qte_sale'] = $prodRow->type != 'is_service' ? $pwQte : '---';
                $price = $product_price;
            }

            // Compute wholesale price per sale unit (min_price is returned raw from DB)
            $wholesale_product_price = isset($prodRow->wholesale_price) ? $prodRow->wholesale_price : $product_price;
            $wholesale_product_price = WarehousePrices::pick($storeRow, 'wholesale_price', $wholesale_product_price);

            if ($unitSale) {
                if ($unitSale->operator == '/') {
                    $wholesale_unit_price = $wholesale_product_price / $unitSale->operator_value;
                } else {
                    $wholesale_unit_price = $wholesale_product_price * $unitSale->operator_value;
                }
            } else {
                $wholesale_unit_price = $wholesale_product_price;
            }

            if ($unitPurchase) {

                if ($unitPurchase->operator == '/') {
                    $item['qte_purchase'] = round($pwQte * $unitPurchase->operator_value, 5);
                } else {
                    $item['qte_purchase'] = round($pwQte / $unitPurchase->operator_value, 5);
                }

            } else {
                $item['qte_purchase'] = $pwQte;
            }

            $item['manage_stock'] = $pwManageStock;
            $item['qte'] = $prodRow->type != 'is_service' ? $pwQte : '---';
            $item['unitSale'] = $unitSale ? $unitSale->ShortName : '';
            $item['unitPurchase'] = $unitPurchase ? $unitPurchase->ShortName : '';

            // Discount

            if ($prodRow->discount !== 0.0) {
                if ($prodRow->discount_method == '1') {
                    $discount = $price * $prodRow->discount / 100;
                    $item['discount'] = $prodRow->discount;
                    $item['DiscountNet'] = $discount;
                    $price_discounted = $price - $discount;
                    $item['discount_method'] = '1';
                } else {
                    $discount = $prodRow->discount;
                    $item['discount'] = $prodRow->discount;
                    $item['DiscountNet'] = $prodRow->discount;
                    $price_discounted = $price - $prodRow->discount;
                    $item['discount_method'] = '2';
                }

            } else {
                $item['discount'] = 0;
                $item['DiscountNet'] = 0;
                $item['discount_method'] = '2';
                // Per sale unit, like the discounted branches above and the
                // wholesale price below (was the base-unit price).
                $price_discounted = $price;

            }

            // Tax
            if ($prodRow->TaxNet !== 0.0) {
                // Exclusive
                if ($prodRow->tax_method == '1') {
                    $tax_price = $price_discounted * $prodRow->TaxNet / 100;
                    $item['Net_price'] = $price_discounted + $tax_price;
                    // Inxclusive
                } else {
                    $item['Net_price'] = $price_discounted;
                }
            } else {
                $item['Net_price'] = $price_discounted;
            }

            // Apply discount/tax to wholesale and min prices as well
            // Wholesale
            if ($prodRow->discount !== 0.0) {
                if ($prodRow->discount_method == '1') {
                    $wholesale_discount = $wholesale_unit_price * $prodRow->discount / 100;
                    $wholesale_price_discounted = $wholesale_unit_price - $wholesale_discount;
                } else {
                    $wholesale_discount = $prodRow->discount;
                    $wholesale_price_discounted = $wholesale_unit_price - $wholesale_discount;
                }
            } else {
                $wholesale_discount = 0;
                $wholesale_price_discounted = $wholesale_unit_price;
            }

            if ($prodRow->TaxNet !== 0.0) {
                if ($prodRow->tax_method == '1') {
                    $wholesale_tax_price = $wholesale_price_discounted * $prodRow->TaxNet / 100;
                    $wholesale_net_price = $wholesale_price_discounted + $wholesale_tax_price;
                } else {
                    $wholesale_tax_price = $wholesale_price_discounted * $prodRow->TaxNet / 100;
                    $wholesale_net_price = $wholesale_price_discounted;
                }
            } else {
                $wholesale_tax_price = 0;
                $wholesale_net_price = $wholesale_price_discounted;
            }

            $item['Unit_price_wholesale'] = $wholesale_unit_price;
            $item['wholesale_Net_price'] = $wholesale_net_price;
            // return raw min price from DB without discount/tax/unit conversion
            $item['min_price'] = WarehousePrices::pick($storeRow, 'min_price', $prodRow->min_price ?? 0);

            $data[] = $item;
        }

        return response()->json($data);
    }

    public function show($id)
    {
        //
    }

    // ------------ Get product By ID -----------------\\
    public function show_product_data($id, $variant_id, $warehouse_id = null)
    {
        // Same as Products_by_Warehouse: the stock figures below are per
        // warehouse, so the requested warehouse must be assigned to the caller.
        if ($warehouse_id) {
            $this->abortIfWarehouseDenied($warehouse_id);
        }

        $Product_data = Product::with('unit')
            ->where('id', $id)
            ->where('deleted_at', '=', null)
            ->where('is_active', 1)
            ->firstOrFail();
        $this->abortIfProductDenied($Product_data);

        $data = [];
        $item['id'] = $Product_data['id'];
        $item['image'] = $Product_data['image'];
        $item['product_type'] = $Product_data['type'];
        $item['Type_barcode'] = $Product_data['Type_barcode'];

        $item['unit_id'] = $Product_data['unit'] ? $Product_data['unit']->id : '';
        $item['unit'] = $Product_data['unit'] ? $Product_data['unit']->ShortName : '';

        $item['purchase_unit_id'] = $Product_data['unitPurchase'] ? $Product_data['unitPurchase']->id : '';
        $item['unitPurchase'] = $Product_data['unitPurchase'] ? $Product_data['unitPurchase']->ShortName : '';

        $item['sale_unit_id'] = $Product_data['unitSale'] ? $Product_data['unitSale']->id : '';
        $item['unitSale'] = $Product_data['unitSale'] ? $Product_data['unitSale']->ShortName : '';

        $item['tax_method'] = $Product_data['tax_method'];
        $item['tax_percent'] = $Product_data['TaxNet'];

        $item['discount_method'] = $Product_data['discount_method'];
        $item['discount'] = $Product_data['discount'];

        $item['is_imei'] = $Product_data['is_imei'];
        // Combo lines pick serials of their tracked components.
        $item['serial_components'] = app(SerialNumberService::class)
            ->comboSerialComponents([(int) $Product_data['id']])[(int) $Product_data['id']] ?? [];
        $item['not_selling'] = $Product_data['not_selling'];
        $item['hide_from_online_store'] = $Product_data['hide_from_online_store'] ?? 0;
        $item['always_in_stock'] = (bool) ($Product_data['always_in_stock'] ?? false);
        $item['is_batch_tracked'] = (bool) ($Product_data['is_batch_tracked'] ?? false);

        // product single
        if ($Product_data['type'] == 'is_single') {
            $product_price = $Product_data['price'];
            $product_cost = $Product_data['cost'];

            $item['code'] = $Product_data['code'];
            $item['name'] = $Product_data['name'];

            // product is_variant
        } elseif ($Product_data['type'] == 'is_variant') {

            // No such variant (e.g. the parent itself was requested) is a 404,
            // not a crash on the null lookups below.
            $product_variant_data = ProductVariant::where('product_id', $id)
                ->where('id', $variant_id)->firstOrFail();

            $product_price = $product_variant_data['price'];
            $product_cost = $product_variant_data['cost'];
            $item['code'] = $product_variant_data['code'];
            $item['name'] = '['.$product_variant_data['name'].']'.$Product_data['name'];

            // product is_service
        } else {

            $product_price = $Product_data['price'];
            $product_cost = 0;

            $item['code'] = $Product_data['code'];
            $item['name'] = $Product_data['name'];
        }

        // Different prices per store: the warehouse's own figures replace the
        // normal ones (NULL fields keep them). base_price stays the product's
        // own price for forms that edit it (purchase "current price" hint).
        $item['base_price'] = $product_price;
        $storePrices = $warehouse_id
            ? WarehousePrices::find($id, $Product_data['type'] == 'is_variant' ? $variant_id : null, $warehouse_id)
            : null;
        $product_price = WarehousePrices::pick($storePrices, 'price', $product_price);
        $item['store_price_applied'] = $storePrices !== null;

        // check if product has Unit sale
        if ($Product_data['unitSale']) {

            if ($Product_data['unitSale']->operator == '/') {
                $price = $product_price / $Product_data['unitSale']->operator_value;

            } else {
                $price = $product_price * $Product_data['unitSale']->operator_value;
            }

        } else {
            $price = $product_price;
        }

        // check if product has Unit Purchase

        if ($Product_data['unitPurchase']) {

            if ($Product_data['unitPurchase']->operator == '/') {
                $cost = $product_cost / $Product_data['unitPurchase']->operator_value;
            } else {
                $cost = $product_cost * $Product_data['unitPurchase']->operator_value;
            }

        } else {
            $cost = 0;
        }

        $item['Unit_cost'] = $cost;
        $item['fix_cost'] = $product_cost;
        $item['Unit_price'] = $price;
        $item['fix_price'] = $product_price;

        // Calculate wholesale price with same unit conversion (min_price returned raw)
        if ($Product_data['type'] == 'is_variant' && isset($product_variant_data)) {
            $wholesale_product_price = isset($product_variant_data['wholesale']) && $product_variant_data['wholesale'] !== null
                ? $product_variant_data['wholesale']
                : $product_price;
        } else {
            $wholesale_product_price = isset($Product_data->wholesale_price) && $Product_data->wholesale_price !== null
                ? $Product_data->wholesale_price
                : $product_price;
        }
        $wholesale_product_price = WarehousePrices::pick($storePrices, 'wholesale_price', $wholesale_product_price);

        if ($Product_data['unitSale']) {
            if ($Product_data['unitSale']->operator == '/') {
                $wholesale_unit_price = $wholesale_product_price / $Product_data['unitSale']->operator_value;
            } else {
                $wholesale_unit_price = $wholesale_product_price * $Product_data['unitSale']->operator_value;
            }
        } else {
            $wholesale_unit_price = $wholesale_product_price;
        }

        if ($Product_data->discount !== 0.0) {
            if ($Product_data['discount_method'] == '1') {
                $discount = $product_price * $Product_data['discount'] / 100;
                $item['discount'] = $Product_data->discount;
                $item['DiscountNet'] = $discount;
                $price_discount = $product_price - $discount;
                $item['discount_method'] = '1';
            } else {
                $discount = $Product_data->discount;
                $item['discount'] = $Product_data->discount;
                $item['DiscountNet'] = $Product_data->discount;
                $price_discount = $product_price - $Product_data->discount;
                $item['discount_method'] = '2';
            }

        } else {
            $item['discount'] = 0;
            $item['DiscountNet'] = 0;
            $item['discount_method'] = '2';
            $price_discount = $product_price;

        }

        if ($Product_data->TaxNet !== 0.0) {
            // Exclusive
            if ($Product_data['tax_method'] == '1') {
                $tax_price = $price_discount * $Product_data['TaxNet'] / 100;
                $tax_cost = $cost * $Product_data['TaxNet'] / 100;

                $item['Total_cost'] = $cost + $tax_cost;
                $item['Total_price'] = $price_discount + $tax_price;
                $item['Net_cost'] = $cost;
                $item['Net_price'] = $price_discount;
                $item['tax_price'] = $tax_price;
                $item['tax_cost'] = $tax_cost;

                // Inxclusive
            } else {
                $tax_price = $price_discount * $Product_data['TaxNet'] / 100;
                $tax_cost = $cost * $Product_data['TaxNet'] / 100;

                $item['Total_cost'] = $cost;
                $item['Total_price'] = $price_discount;
                $item['Net_cost'] = $cost - $tax_cost;
                $item['Net_price'] = $price_discount - $tax_price;
                $item['tax_price'] = $tax_price;
                $item['tax_cost'] = $tax_cost;
            }
        } else {
            $item['Total_cost'] = $cost;
            $item['Total_price'] = $price_discount;
            $item['Net_cost'] = $cost;
            $item['Net_price'] = $price_discount;
            $item['tax_price'] = 0;
            $item['tax_cost'] = 0;
        }

        // Compute wholesale and min final prices with same discount/tax logic
        // Wholesale
        if ($Product_data->discount !== 0.0) {
            if ($Product_data['discount_method'] == '1') {
                $wholesale_discount = $wholesale_unit_price * $Product_data['discount'] / 100;
                $wholesale_price_discount = $wholesale_unit_price - $wholesale_discount;
            } else {
                $wholesale_discount = $Product_data->discount;
                $wholesale_price_discount = $wholesale_unit_price - $wholesale_discount;
            }
        } else {
            $wholesale_discount = 0;
            $wholesale_price_discount = $wholesale_unit_price;
        }

        if ($Product_data->TaxNet !== 0.0) {
            if ($Product_data['tax_method'] == '1') {
                $wholesale_tax_price = $wholesale_price_discount * $Product_data['TaxNet'] / 100;
                $wholesale_total_price = $wholesale_price_discount + $wholesale_tax_price;
            } else {
                $wholesale_tax_price = $wholesale_price_discount * $Product_data['TaxNet'] / 100;
                $wholesale_total_price = $wholesale_price_discount;
            }
        } else {
            $wholesale_tax_price = 0;
            $wholesale_total_price = $wholesale_price_discount;
        }

        $item['Unit_price_wholesale'] = $wholesale_unit_price;
        $item['wholesale_Net_price'] = $wholesale_total_price;

        // Compute min price per sale unit (no discount/tax), to compare with Net_price
        $min_price_raw = ($Product_data['type'] == 'is_variant' && isset($product_variant_data))
            ? ($product_variant_data['min_price'] ?? 0)
            : ($Product_data->min_price ?? 0);
        $min_price_raw = WarehousePrices::pick($storePrices, 'min_price', $min_price_raw);

        if ($Product_data['unitSale']) {
            if ($Product_data['unitSale']->operator == '/') {
                $min_unit_price = $min_price_raw / $Product_data['unitSale']->operator_value;
            } else {
                $min_unit_price = $min_price_raw * $Product_data['unitSale']->operator_value;
            }
        } else {
            $min_unit_price = $min_price_raw;
        }
        $item['min_price'] = $min_unit_price ?: 0;

        // Add warehouse stock quantity data
        $item['qte'] = 0;
        $item['qte_sale'] = 0;

        if ($warehouse_id) {
            $query = product_warehouse::where('warehouse_id', $warehouse_id)
                ->where('product_id', $id)
                ->where('deleted_at', '=', null);

            // Only filter by variant if variant_id is provided and not null
            if ($variant_id && $variant_id != 'null') {
                $query->where('product_variant_id', $variant_id);
            } else {
                $query->whereNull('product_variant_id');
            }

            $product_warehouse = $query->first();

            if ($product_warehouse && $Product_data['type'] === 'is_combo' && ComboStockService::enabled()) {
                $product_warehouse->setRelation('product', $Product_data);
                app(ComboStockService::class)->overlayRows([$product_warehouse]);
                // Components that never run out: the line must not be capped at 0.
                if ($product_warehouse->combo_unlimited) {
                    $item['always_in_stock'] = true;
                }
            }

            if ($product_warehouse) {
                $item['qte'] = $product_warehouse->qte;

                // Calculate qte_sale based on sale unit if exists
                if ($Product_data['unitSale']) {
                    if ($Product_data['unitSale']->operator == '/') {
                        $item['qte_sale'] = $product_warehouse->qte / $Product_data['unitSale']->operator_value;
                    } else {
                        $item['qte_sale'] = $product_warehouse->qte / $Product_data['unitSale']->operator_value;
                    }
                } else {
                    $item['qte_sale'] = $product_warehouse->qte;
                }
            }
        }

        // Suggested Rack/Location (display-only hint)
        $item['warehouse_location'] = null;
        if ($warehouse_id) {
            $pwl = ProductWarehouseLocation::with('location')
                ->where('product_id', $id)
                ->where('warehouse_id', $warehouse_id)
                ->first();

            if ($pwl && $pwl->location) {
                $item['warehouse_location'] = [
                    'id' => $pwl->location->id,
                    'code' => $pwl->location->code,
                    'name' => $pwl->location->name,
                    'is_active' => (bool) $pwl->location->is_active,
                ];
            }
        }

        // Multi-Pack Selling: expose active packs for single products when the
        // feature is enabled. Empty otherwise, so non-pack flows are unchanged.
        $item['packs'] = [];
        $setting = Setting::whereNull('deleted_at')->first();
        if ((bool) ($setting->enable_multi_pack_selling ?? false) && $Product_data['type'] == 'is_single') {
            $packs = ProductPack::where('product_id', $id)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->orderBy('is_default', 'desc')
                ->orderBy('id')
                ->get();
            foreach ($packs as $pack) {
                $item['packs'][] = [
                    'id' => $pack->id,
                    'name' => $pack->name,
                    'multiplier' => (float) $pack->multiplier,
                    'price' => (float) $pack->price,
                    'is_active' => (bool) $pack->is_active,
                    'is_default' => (bool) $pack->is_default,
                ];
            }
        }

        $data[] = $item;

        return response()->json($data[0]);
    }

    // --------------  Product Quantity Alerts ---------------\\

    public function Products_Alert(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Stock_Alerts', Product::class);

        // Alerts are per warehouse: restrict to the caller's warehouses and
        // ignore a `warehouse` filter pointing outside them.
        $allowedWarehouseIds = $this->userWarehouseIds();
        $warehouseFilter = $this->filterWarehouseId($request->warehouse);

        $product_warehouse_data = product_warehouse::with('warehouse', 'product', 'productVariant')
            ->join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->tap(fn ($q) => $this->scopeProductsToWarehouses($q, 'products'))
            // Qualified: some tenants' products table also has a manage_stock column.
            ->where('product_warehouse.manage_stock', true)
            ->whereRaw('qte <= stock_alert')
            ->tap(fn ($q) => ComboStockService::excludeVirtualCombos($q))
            ->whereIn('product_warehouse.warehouse_id', $allowedWarehouseIds)
            ->when($warehouseFilter, function ($query) use ($warehouseFilter) {
                return $query->where('product_warehouse.warehouse_id', $warehouseFilter);
            })->where('product_warehouse.deleted_at', null)
            // An empty row of another warehouse's product is not a low-stock alert here.
            ->tap(fn ($q) => $this->scopeStockRowsToOwnership($q))
            // products.id / products.warehouse_id would otherwise overwrite the
            // product_warehouse columns and break the eager-loaded relations
            ->select('product_warehouse.*')
            ->get();

        $data = [];

        if ($product_warehouse_data->isNotEmpty()) {

            foreach ($product_warehouse_data as $product_warehouse) {
                $product = $product_warehouse['product'];
                if ($product === null) {
                    continue;
                }
                if ($product_warehouse->qte <= $product->stock_alert) {
                    if ($product_warehouse->product_variant_id !== null) {
                        $item['code'] = optional($product_warehouse['productVariant'])->code;
                        $item['name'] = '['.optional($product_warehouse['productVariant'])->name.']'.$product->name;
                    } else {
                        $item['code'] = $product->code;
                        $item['name'] = $product->name;
                    }
                    $item['quantity'] = $product_warehouse->qte;
                    $item['warehouse'] = optional($product_warehouse['warehouse'])->name;
                    $item['stock_alert'] = $product->stock_alert;
                    $data[] = $item;
                }
            }
        }

        // Whole-set KPIs for the Quantity Alerts report (computed before
        // pagination). Severity matches the dashboard: out of stock <= 0,
        // critical <= 34% of the alert threshold.
        $stats = [
            'total' => count($data),
            'out_of_stock' => count(array_filter($data, fn ($r) => (float) $r['quantity'] <= 0)),
            'critical' => count(array_filter($data, function ($r) {
                $qty = (float) $r['quantity'];
                $threshold = (float) $r['stock_alert'];

                return $qty > 0 && $threshold > 0 && ($qty / $threshold) * 100 <= 34;
            })),
            'warehouses' => count(array_unique(array_column($data, 'warehouse'))),
        ];

        $perPage = $request->limit; // How many items do you want to display.
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $collection = collect($data);
        // Get only the items you need using array_slice
        $data_collection = $collection->slice($offSet, $perPage)->values();

        $products = new LengthAwarePaginator($data_collection, count($data), $perPage, Paginator::resolveCurrentPage(), ['path' => Paginator::resolveCurrentPath()]);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'products' => $products,
            'warehouses' => $warehouses,
            'stats' => $stats,
        ]);
    }

    // ---------------- Show Form Create Product ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        $categories = Category::whereNull('deleted_at')->get(['id', 'name']);
        $subcategories = SubCategory::orderBy('name')->get(['id', 'name', 'category_id']);
        $brands = Brand::where('deleted_at', null)->get(['id', 'name']);
        $units = Unit::where('deleted_at', null)->where('base_unit', null)->get();

        // get warehouses and pad with opening‑stock defaults
        $user_auth = auth()->user();

        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->get(['id', 'name']);
            $allowedWarehouseIds = $warehouses->pluck('id')->toArray();
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')
                ->whereIn('id', $warehouses_id)
                ->get(['id', 'name']);
            $allowedWarehouseIds = $warehouses->pluck('id')->toArray();
        }

        // Add `qte` = 0 to each warehouse
        $warehouses = $warehouses->map(function ($w) {
            return [
                'id' => $w->id,
                'name' => $w->name,
                'qte' => 0, // opening stock
            ];
        });

        $warehouse_locations = WarehouseLocation::whereNull('deleted_at')
            ->where('is_active', 1)
            ->whereIn('warehouse_id', $allowedWarehouseIds)
            ->orderBy('code')
            ->get(['id', 'warehouse_id', 'code', 'name']);

        // Whether the Barcode (GTIN / UPC / EAN / ISBN) field should be shown on the
        // create form. Controlled from System Settings; defaults to false.
        $setting = Setting::whereNull('deleted_at')->first();
        $show_product_gtin = (bool) ($setting->show_product_gtin ?? false);
        $show_serial_tracking = (bool) ($setting->show_serial_tracking ?? false);
        $enable_multi_pack_selling = (bool) ($setting->enable_multi_pack_selling ?? false);
        // Default-supplier field on the product form (Settings -> Features).
        $product_supplier_enabled = (bool) ($setting->product_supplier_enabled ?? false);
        $providers = $product_supplier_enabled
            ? \App\Models\Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
            : collect();

        // FBR Digital Invoicing (Pakistan): show the HS Code / FBR UoM fields
        // on the product form only when the tenant enabled the integration.
        $fbr_enabled = (bool) optional(\App\Models\FbrSetting::current())->enabled;

        return response()->json([
            'categories' => $categories,
            'subcategories' => $subcategories,
            'brands' => $brands,
            'size_guides' => SizeGuideController::activeOptions(),
            'vehicle_specs_enabled' => self::vehicleSpecsEnabled(),
            'store_specs_enabled' => self::storeSpecsEnabled(),
            'store_spec_labels' => self::storeSpecsEnabled() ? self::storeSpecLabelSuggestions() : [],
            'restaurant_dish' => self::dishFormData(null),
            'providers' => $providers,
            'product_supplier_enabled' => $product_supplier_enabled,
            'units' => $units,
            'warehouses' => $warehouses,
            'warehouse_locations' => $warehouse_locations,
            'show_product_gtin' => $show_product_gtin,
            'show_serial_tracking' => $show_serial_tracking,
            'enable_multi_pack_selling' => $enable_multi_pack_selling,
            'fbr_enabled' => $fbr_enabled,
            // Scale PLU field (System Settings > Weighing scale).
            'scale_barcode_enabled' => self::scaleBarcodeEnabled(),
            // Home warehouse field (Settings -> Features -> Restrict products by
            // warehouse). Required for a warehouse-restricted user; a user who
            // may see every warehouse can leave the product unassigned.
            'restrict_products_by_warehouse' => (bool) ($setting->restrict_products_by_warehouse ?? false),
            'home_warehouse_required' => ! $user_auth->is_all_warehouses,
            'default_warehouse_id' => count($allowedWarehouseIds) === 1 ? (int) $allowedWarehouseIds[0] : null,
            // Store prices table (Settings -> Features -> Different prices per store).
            'warehouse_prices_enabled' => WarehousePrices::enabled(),
        ]);

    }

    /**
     * Warehouses whose store prices the current user may set: the same set
     * the product form lists (all, or the user's assigned warehouses).
     */
    private function storePriceWarehouseIds(): array
    {
        $user_auth = auth()->user();
        if (! $user_auth) {
            return [];
        }
        $ids = Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($user_auth->is_all_warehouses) {
            return $ids;
        }
        $assigned = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_intersect($ids, $assigned));
    }

    /** Save the product form's "Store prices" rows (sent only while the switch is on). */
    private function saveStorePrices(Request $request, Product $product): void
    {
        if (! $request->has('warehouse_prices') || ! WarehousePrices::enabled()) {
            return;
        }
        WarehousePrices::syncFromForm($product, $request->input('warehouse_prices'), $this->storePriceWarehouseIds());
    }

    // ---------------- Show Elements Barcode ---------------\\

    public function Get_element_barcode(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'barcode', Product::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json(['warehouses' => $warehouses]);

    }

    // ---------------- Show Form Edit Product ---------------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Product::class);

        $Product = Product::where('deleted_at', '=', null)->findOrFail($id);
        $this->abortIfProductDenied($Product);

        $item['id'] = $Product->id;
        $item['warehouse_id'] = $Product->warehouse_id;
        $item['type'] = $Product->type;
        $item['code'] = $Product->code;
        $item['barcodes'] = ProductBarcodes::for((int) $Product->id);
        $item['gtin'] = $Product->gtin ?? '';
        $item['hs_code'] = $Product->hs_code ?? '';
        $item['fbr_uom'] = $Product->fbr_uom ?? '';
        $item['points'] = $Product->points;
        $item['Type_barcode'] = $Product->Type_barcode;
        $item['name'] = $Product->name;

        // ——— Warranty & Guarantee ———
        $item['warranty_period'] = $Product->warranty_period;
        $item['warranty_unit'] = $Product->warranty_unit;
        $item['warranty_terms'] = $Product->warranty_terms;

        $item['has_guarantee'] = (bool) $Product->has_guarantee;
        $item['guarantee_period'] = $Product->guarantee_period;
        $item['guarantee_unit'] = $Product->guarantee_unit;

        if ($Product->category_id) {
            if (Category::where('id', $Product->category_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['category_id'] = $Product->category_id;
            } else {
                $item['category_id'] = '';
            }
        } else {
            $item['category_id'] = '';
        }

        if ($Product->sub_category_id) {
            // validate against SubCategory model (no soft deletes on this table)
            if (SubCategory::where('id', $Product->sub_category_id)->first()) {
                $item['sub_category_id'] = $Product->sub_category_id;
            } else {
                $item['sub_category_id'] = '';
            }
        } else {
            $item['sub_category_id'] = '';
        }

        if ($Product->brand_id) {
            if (Brand::where('id', $Product->brand_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['brand_id'] = $Product->brand_id;
            } else {
                $item['brand_id'] = '';
            }
        } else {
            $item['brand_id'] = '';
        }
        $item['size_guide_id'] = $Product->size_guide_id ?: null;

        // Same guard as brand: a supplier deleted since the product was saved
        // must not be pre-selected in a form whose dropdown no longer lists it.
        $item['provider_id'] = ($Product->provider_id && \App\Models\Provider::where('id', $Product->provider_id)
            ->whereNull('deleted_at')->exists())
            ? $Product->provider_id
            : '';

        if ($Product->unit_id) {
            if (Unit::where('id', $Product->unit_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['unit_id'] = $Product->unit_id;
            } else {
                $item['unit_id'] = '';
            }

            if (Unit::where('id', $Product->unit_sale_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['unit_sale_id'] = $Product->unit_sale_id;
            } else {
                $item['unit_sale_id'] = '';
            }

            if (Unit::where('id', $Product->unit_purchase_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['unit_purchase_id'] = $Product->unit_purchase_id;
            } else {
                $item['unit_purchase_id'] = '';
            }

        } else {
            $item['unit_id'] = '';
        }

        $materiels = [];
        if ($Product->type == 'is_combo') {
            $combined_products = CombinedProduct::where('product_id', $id)->with('product.unit')->get();

            $materiels = $combined_products->map(function ($combinedProduct) {
                return [
                    'product_id' => $combinedProduct->combined_product_id,
                    'name' => $combinedProduct->product->name,
                    'code' => $combinedProduct->product->code,
                    'unit_name' => $combinedProduct->product['unit']->ShortName,
                    'cost' => $combinedProduct->product->cost,
                    'quantity' => $combinedProduct->quantity,
                ];
            });

        }

        $item['tax_method'] = $Product->tax_method;
        $item['discount_method'] = $Product->discount_method;
        $item['discount'] = $Product->discount;
        $item['price'] = $Product->price;
        $item['wholesale_price'] = $Product->wholesale_price;
        $item['min_price'] = $Product->min_price;
        $item['cost'] = $Product->cost;
        $item['stock_alert'] = $Product->stock_alert;
        $item['weight'] = $Product->weight;
        $item['length'] = $Product->length;
        $item['width'] = $Product->width;
        $item['height'] = $Product->height;
        $item['TaxNet'] = $Product->TaxNet;
        $item['note'] = $Product->note ? $Product->note : '';

        $firstimage = explode(',', $Product->image);
        $item['image'] = $firstimage[0];

        $optionService = app(ProductOptionService::class);
        $item['options'] = $Product->type == 'is_variant' ? $optionService->optionsPayload((int) $id) : [];
        $variantOptionValues = $item['options'] ? $optionService->variantValuesMap((int) $id) : [];

        if ($Product->type == 'is_variant') {
            $item['is_variant'] = true;
            $productsVariants = ProductVariant::where('product_id', $id)
                ->where('deleted_at', null)
                ->get();

            $var_id = 0;
            foreach ($productsVariants as $variant) {
                $variant_item['var_id'] = $var_id += 1;
                $variant_item['option_values'] = (object) ($variantOptionValues[$variant->id] ?? []);
                $variant_item['id'] = $variant->id;
                $variant_item['text'] = $variant->name;
                $variant_item['code'] = $variant->code;
                $variant_item['gtin'] = $variant->gtin ?? '';
                $variant_item['image'] = $variant->image ?: 'no-image.png';
                $variant_item['price'] = $variant->price;
                $variant_item['cost'] = $variant->cost;
                $variant_item['wholesale'] = $variant->wholesale ?? 0;
                $variant_item['min_price'] = $variant->min_price ?? 0;
                $variant_item['product_id'] = $variant->product_id;
                $item['ProductVariant'][] = $variant_item;
            }
        } else {
            $item['is_variant'] = false;
            $item['ProductVariant'] = [];
        }

        // Multi-Pack Selling: per-product selling packs (empty for products that don't use them)
        $item['packs'] = [];
        foreach (ProductPack::where('product_id', $id)->whereNull('deleted_at')->orderBy('is_default', 'desc')->orderBy('id')->get() as $pack) {
            $item['packs'][] = [
                'id' => $pack->id,
                'name' => $pack->name,
                'multiplier' => (float) $pack->multiplier,
                'price' => (float) $pack->price,
                'is_active' => (bool) $pack->is_active,
                'is_default' => (bool) $pack->is_default,
            ];
        }

        $item['is_imei'] = $Product->is_imei ? true : false;
        $item['not_selling'] = $Product->not_selling ? true : false;
        $item['is_featured'] = $Product->is_featured ? true : false;
        $item['hide_from_online_store'] = $Product->hide_from_online_store ? true : false;
        $item['always_in_stock'] = $Product->always_in_stock ? true : false;
        $item['license'] = $this->licensePayload($Product);
        $item['scale_plu'] = $Product->scale_plu ?? '';
        $item['is_active'] = $Product->is_active ? true : false;

        $item['is_preorder'] = (bool) $Product->is_preorder;
        $item['preorder_available_date'] = $Product->preorder_available_date
            ? $Product->preorder_available_date->format('Y-m-d') : '';
        $item['preorder_limit'] = $Product->preorder_limit !== null ? $Product->preorder_limit : '';
        $item['preorder_note'] = $Product->preorder_note ?? '';

        // Pharmacy / Batch & Expiry payload (always present, defaults are safe for non-pharmacy products)
        $item['is_batch_tracked'] = (bool) $Product->is_batch_tracked;
        $item['shelf_life_days'] = $Product->shelf_life_days !== null ? (int) $Product->shelf_life_days : '';
        $item['generic_name'] = $Product->generic_name ?? '';
        $item['strength'] = $Product->strength ?? '';
        $item['dosage_form'] = $Product->dosage_form ?? '';
        $item['pack_size'] = $Product->pack_size ?? '';
        $item['manufacturer'] = $Product->manufacturer ?? '';
        $item['prescription_required'] = (bool) $Product->prescription_required;
        $item['drug_schedule'] = $Product->drug_schedule ?? '';

        $item['product_images'] = [];
        if (Schema::hasTable('product_images')) {
            $item['product_images'] = ProductImage::where('product_id', $id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function ($img) {
                    return [
                        'id' => $img->id,
                        'image_path' => $img->image_path,
                        'url' => product_image_url($img->image_path),
                        'is_main' => (bool) $img->is_main,
                        'sort_order' => (int) $img->sort_order,
                    ];
                })
                ->values()
                ->all();
        }

        $Product->loadMissing(['categories', 'subcategories']);

        $pivotCatIds = $Product->categories->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
        $primaryCat = (int) ($Product->category_id ?? 0);
        if ($pivotCatIds === [] && $primaryCat > 0) {
            $item['assigned_category_ids'] = [$primaryCat];
        } elseif ($primaryCat > 0) {
            $item['assigned_category_ids'] = array_values(array_unique(array_merge(
                [$primaryCat],
                array_values(array_diff($pivotCatIds, [$primaryCat]))
            )));
        } else {
            $item['assigned_category_ids'] = $pivotCatIds;
        }

        $pivotSubIds = $Product->subcategories->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
        $primarySub = (int) ($Product->sub_category_id ?? 0);
        if ($pivotSubIds === [] && $primarySub > 0) {
            $item['assigned_subcategory_ids'] = [$primarySub];
        } elseif ($primarySub > 0) {
            $item['assigned_subcategory_ids'] = array_values(array_unique(array_merge(
                [$primarySub],
                array_values(array_diff($pivotSubIds, [$primarySub]))
            )));
        } else {
            $item['assigned_subcategory_ids'] = $pivotSubIds;
        }

        $data = $item;
        $categories = Category::where('deleted_at', null)->get(['id', 'name']);
        $all_subcategories = SubCategory::orderBy('name')->get(['id', 'name', 'category_id']);
        $brands = Brand::where('deleted_at', null)->get(['id', 'name']);

        $product_units = Unit::where('id', $Product->unit_id)
            ->orWhere('base_unit', $Product->unit_id)
            ->where('deleted_at', null)
            ->get();

        $units = Unit::where('deleted_at', null)
            ->where('base_unit', null)
            ->get();

        // Warehouses assigned to user (for default location selection UI)
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->get(['id', 'name']);
            $allowedWarehouseIds = $warehouses->pluck('id')->toArray();
        } else {
            $allowedWarehouseIds = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id', $allowedWarehouseIds)->get(['id', 'name']);
        }

        $warehouse_locations = WarehouseLocation::whereNull('deleted_at')
            ->whereIn('warehouse_id', $allowedWarehouseIds)
            ->orderBy('code')
            ->get(['id', 'warehouse_id', 'code', 'name', 'is_active']);

        $product_warehouse_locations = ProductWarehouseLocation::where('product_id', $id)
            ->whereIn('warehouse_id', $allowedWarehouseIds)
            ->get(['warehouse_id', 'warehouse_location_id']);

        $setting = Setting::whereNull('deleted_at')->first();
        $show_product_gtin = (bool) ($setting->show_product_gtin ?? false);
        $show_serial_tracking = (bool) ($setting->show_serial_tracking ?? false);
        $enable_multi_pack_selling = (bool) ($setting->enable_multi_pack_selling ?? false);
        // Default-supplier field on the product form (Settings -> Features).
        $product_supplier_enabled = (bool) ($setting->product_supplier_enabled ?? false);
        $providers = $product_supplier_enabled
            ? \App\Models\Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
            : collect();

        return response()->json([
            'product' => $data,
            'categories' => $categories,
            'all_subcategories' => $all_subcategories,
            'brands' => $brands,
            'size_guides' => SizeGuideController::activeOptions(),
            'vehicle_specs_enabled' => self::vehicleSpecsEnabled(),
            'vehicle_spec' => self::vehicleSpecsEnabled() ? optional(\App\Models\VehicleSpec::where('product_id', $Product->id)->first())->toFormArray() : null,
            'store_specs_enabled' => self::storeSpecsEnabled(),
            'store_specs' => self::storeSpecsEnabled() ? \App\Support\StoreSpecs::rows($Product) : [],
            'product_video_url' => self::storeSpecsEnabled() && \App\Support\StoreSpecs::videoReady() ? ($Product->getAttributes()['video_url'] ?? null) : null,
            'store_spec_labels' => self::storeSpecsEnabled() ? self::storeSpecLabelSuggestions() : [],
            'restaurant_dish' => self::dishFormData($Product->id),
            'providers' => $providers,
            'product_supplier_enabled' => $product_supplier_enabled,
            'units' => $units,
            'units_sub' => $product_units,
            'materiels' => $materiels,
            'warehouses' => $warehouses,
            'warehouse_locations' => $warehouse_locations,
            'product_warehouse_locations' => $product_warehouse_locations,
            'show_product_gtin' => $show_product_gtin,
            'show_serial_tracking' => $show_serial_tracking,
            'enable_multi_pack_selling' => $enable_multi_pack_selling,
            'fbr_enabled' => (bool) optional(\App\Models\FbrSetting::current())->enabled,
            'scale_barcode_enabled' => self::scaleBarcodeEnabled(),
            // Home warehouse field — see create().
            'restrict_products_by_warehouse' => (bool) ($setting->restrict_products_by_warehouse ?? false),
            'home_warehouse_required' => ! auth()->user()->is_all_warehouses,
            'default_warehouse_id' => count($allowedWarehouseIds) === 1 ? (int) $allowedWarehouseIds[0] : null,
            // Store prices table (Settings -> Features -> Different prices per store).
            'warehouse_prices_enabled' => WarehousePrices::enabled(),
            'warehouse_prices' => WarehousePrices::enabled()
                ? WarehousePrices::formRows((int) $Product->id, array_map('intval', $allowedWarehouseIds))
                : [],
        ]);

    }

    /**
     * IMPORT: Single products (no variants)
     * Expected headers per row:
     * name, code, price, cost, category, unit, brand (opt), stock_alert (opt), note (opt)
     */
    public function import_single_products(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        ini_set('max_execution_time', 2000);

        $request->validate([
            'products' => 'required|mimes:xls,xlsx',
        ]);

        // Read first sheet with headings → associative rows
        $rows = Excel::toArray(new ProductImport, $request->file('products'))[0] ?? [];

        // Drop rows that are entirely empty (all values null/blank)
        $rows = array_values(array_filter($rows, function ($row) {
            if (! is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    return true;
                }
            }

            return false;
        }));

        if (empty($rows)) {
            return response()->json(['status' => false, 'message' => 'The imported file is empty.']);
        }

        $warehouses = Warehouse::whereNull('deleted_at')->pluck('id')->toArray();

        // Preload existing codes (products & variants)
        $existingProductCodes = Product::whereNull('deleted_at')->pluck('code')->all();
        $existingVariantCodes = ProductVariant::whereNull('deleted_at')->pluck('code')->all();
        // Additional barcodes share the namespace: a new code may not be one.
        $existingCodesSet = array_fill_keys(array_merge($existingProductCodes, $existingVariantCodes, ProductBarcodes::all()), true);

        $seenCodes = []; // duplicates inside the file
        $errors = [];
        $valid = [];
        $unitCache = [];

        // Pass 1 — check every row and report ALL problems at once (with row
        // numbers), instead of stopping at the first; nothing is created yet.
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // heading row = 1
            $name = trim((string) ($row['name'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));
            // Support 'retail_price' as the canonical header; fallback to 'price'
            $price = $row['retail_price'] ?? ($row['price'] ?? null);
            $cost = $row['cost'] ?? null;
            $categoryName = trim((string) ($row['category'] ?? ''));
            $subCategoryName = trim((string) ($row['sub_category'] ?? ($row['subcategory'] ?? '')));
            $unitName = trim((string) ($row['unit'] ?? ''));
            $brandName = trim((string) ($row['brand'] ?? ''));
            $stockAlert = $row['stock_alert'] ?? 0;
            $note = $row['note'] ?? null;
            $wholesale = $row['wholesale_price'] ?? null;
            $minPrice = $row['min_price'] ?? null;
            $label = $code !== '' ? "Row {$rowNum} ({$code})" : "Row {$rowNum}";
            $rowErrors = [];

            if ($name === '') {
                $rowErrors[] = "{$label}: product name is missing.";
            } elseif (mb_strlen($name) > 192) {
                $rowErrors[] = "{$label}: product name is ".mb_strlen($name).' characters; the maximum is 192.';
            }
            if ($code === '') {
                $rowErrors[] = "{$label}: product code is missing.";
            } elseif (mb_strlen($code) > 192) {
                $rowErrors[] = "{$label}: product code is longer than 192 characters.";
            } elseif (isset($seenCodes[$code])) {
                $rowErrors[] = "{$label}: duplicate product code (also on row {$seenCodes[$code]}).";
            } elseif (isset($existingCodesSet[$code])) {
                $rowErrors[] = "{$label}: code already exists (product or variant).";
            }
            if (! is_numeric($price)) {
                $rowErrors[] = "{$label}: retail price \"".(is_scalar($price) ? $price : '').'" is not a number.';
            }
            if (! is_numeric($cost)) {
                $rowErrors[] = "{$label}: cost \"".(is_scalar($cost) ? $cost : '').'" is not a number.';
            }
            if ($wholesale !== null && $wholesale !== '' && ! is_numeric($wholesale)) {
                $rowErrors[] = "{$label}: wholesale price is not a number.";
            }
            if ($minPrice !== null && $minPrice !== '' && ! is_numeric($minPrice)) {
                $rowErrors[] = "{$label}: minimum price is not a number.";
            }
            if ($categoryName === '') {
                $rowErrors[] = "{$label}: category is missing.";
            }

            if (! array_key_exists($unitName, $unitCache)) {
                $unitCache[$unitName] = $unitName === '' ? null
                    : Unit::where('ShortName', $unitName)->orWhere('name', $unitName)->first();
            }
            $unit = $unitCache[$unitName];
            if (! $unit) {
                $rowErrors[] = "{$label}: unit \"{$unitName}\" does not exist.";
            }

            if ($code !== '' && ! isset($seenCodes[$code])) {
                $seenCodes[$code] = $rowNum;
            }

            if ($rowErrors) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $valid[] = compact('name', 'code', 'price', 'cost', 'categoryName', 'subCategoryName', 'unit', 'brandName', 'stockAlert', 'note', 'wholesale', 'minPrice');
        }

        if ($errors) {
            return response()->json([
                'status' => false,
                'message' => count($errors).' problem(s) found — nothing was imported.',
                'errors' => $errors,
            ]);
        }

        // Pass 2 — the file is valid: resolve categories, sub-categories and
        // brands (created when new, once per name).
        $categoryIds = [];
        $subCategoryIds = [];
        $brandIds = [];
        $toCreate = [];
        foreach ($valid as $v) {
            $categoryIds[$v['categoryName']] ??= Category::firstOrCreate(['name' => $v['categoryName']])->id;
            $categoryId = $categoryIds[$v['categoryName']];

            $subCategoryId = null;
            if ($v['subCategoryName'] !== '') {
                $key = $categoryId.'|'.$v['subCategoryName'];
                $subCategoryIds[$key] ??= SubCategory::firstOrCreate(
                    ['category_id' => $categoryId, 'name' => $v['subCategoryName']],
                    ['status' => true]
                )->id;
                $subCategoryId = $subCategoryIds[$key];
            }

            $brandId = null;
            if ($v['brandName'] !== '') {
                $brandIds[$v['brandName']] ??= Brand::firstOrCreate(['name' => $v['brandName']])->id;
                $brandId = $brandIds[$v['brandName']];
            }

            $toCreate[] = [
                'name' => $v['name'],
                'code' => $v['code'],
                'price' => (float) $v['price'],
                'cost' => (float) $v['cost'],
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'unit_id' => $v['unit']->id,
                'unit_sale_id' => $v['unit']->id,
                'unit_purchase_id' => $v['unit']->id,
                'brand_id' => $brandId,
                'stock_alert' => is_numeric($v['stockAlert']) ? $v['stockAlert'] : 0,
                'note' => $v['note'] ?: null,
                'wholesale_price' => is_numeric($v['wholesale']) ? (float) $v['wholesale'] : 0,
                'min_price' => is_numeric($v['minPrice']) ? (float) $v['minPrice'] : 0,
            ];
        }

        try {
            DB::transaction(function () use ($toCreate, $warehouses) {
                DB::disableQueryLog(); // reduce memory during huge imports

                // MySQL prepared statement cap ≈ 65k placeholders.
                // We insert 4 columns per row → theoretical max ≈ 16k rows.
                // Use a safe margin to avoid "too many placeholders".
                $PW_BATCH_ROWS = 12000;

                $pwRows = [];

                foreach ($toCreate as $data) {
                    $product = Product::create([
                        // Home warehouse: the importer's own (first) warehouse.
                        'warehouse_id' => $this->productHomeWarehouseFor(null, false),
                        // base
                        'type' => 'is_single',
                        'is_variant' => 0,
                        'is_imei' => 0,
                        'not_selling' => 0,
                        'is_active' => 1,
                        'Type_barcode' => 'CODE128',
                        'image' => 'no-image.png',
                        'TaxNet' => 0,
                        'tax_method' => 1,
                        'discount' => 0,
                        'discount_method' => 1,
                        // payload
                        ...$data,
                    ]);

                    if (Schema::hasTable('category_product') && ! empty($data['category_id'])) {
                        $product->categories()->sync([(int) $data['category_id']]);
                    }
                    if (Schema::hasTable('product_subcategory') && ! empty($data['sub_category_id'])) {
                        $product->subcategories()->sync([(int) $data['sub_category_id']]);
                    }

                    // Prepare product_warehouse rows for each warehouse
                    foreach ($warehouses as $wid) {
                        $pwRows[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $wid,
                            'manage_stock' => 1,
                            'qte' => 0,
                        ];
                    }

                    // Flush in batches to avoid exceeding placeholder limits
                    if (count($pwRows) >= $PW_BATCH_ROWS) {
                        DB::table('product_warehouse')->insert($pwRows);
                        $pwRows = [];
                    }
                }

                // Insert any remaining rows
                if (! empty($pwRows)) {
                    DB::table('product_warehouse')->insert($pwRows);
                }
            });

            return response()->json(['status' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while importing: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * IMPORT: Service products (single row per service, type = is_service). Cost is always 0.
     * Expected headers: name, code, retail_price/price, category, unit, wholesale_price (opt), min_price (opt), brand (opt), note (opt)
     */
    public function import_service_products(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        ini_set('max_execution_time', 2000);

        $request->validate([
            'products' => 'required|mimes:xls,xlsx',
        ]);

        $rows = Excel::toArray(new ProductImport, $request->file('products'))[0] ?? [];

        $rows = array_values(array_filter($rows, function ($row) {
            if (! is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    return true;
                }
            }

            return false;
        }));

        if (empty($rows)) {
            return response()->json(['status' => false, 'message' => 'The imported file is empty.']);
        }

        $warehouses = Warehouse::whereNull('deleted_at')->pluck('id')->toArray();

        $existingProductCodes = Product::whereNull('deleted_at')->pluck('code')->all();
        $existingVariantCodes = ProductVariant::whereNull('deleted_at')->pluck('code')->all();
        // Additional barcodes share the namespace: a new code may not be one.
        $existingCodesSet = array_fill_keys(array_merge($existingProductCodes, $existingVariantCodes, ProductBarcodes::all()), true);

        $seenCodes = [];
        $toCreate = [];

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            $code = trim($row['code'] ?? '');
            $price = $row['retail_price'] ?? ($row['price'] ?? null);
            $categoryName = $row['category'] ?? '';
            $subCategoryName = $row['sub_category'] ?? ($row['subcategory'] ?? '');
            $unitName = $row['unit'] ?? '';
            $brandName = $row['brand'] ?? '';
            $note = $row['note'] ?? null;
            $wholesale = $row['wholesale_price'] ?? null;
            $minPrice = $row['min_price'] ?? null;

            if (! $name) {
                return response()->json(['status' => false, 'message' => 'Service name is missing.']);
            }
            if (! $code) {
                return response()->json(['status' => false, 'message' => "Service \"$name\" has no code."]);
            }

            if (isset($seenCodes[$code])) {
                return response()->json(['status' => false, 'message' => "Duplicate service code \"$code\" found in file."]);
            }
            if (isset($existingCodesSet[$code])) {
                return response()->json(['status' => false, 'message' => "Code \"$code\" already exists (product or variant)."]);
            }
            if (! is_numeric($price)) {
                return response()->json(['status' => false, 'message' => "Retail price for \"$name\" is invalid."]);
            }
            if ($wholesale !== null && $wholesale !== '' && ! is_numeric($wholesale)) {
                return response()->json(['status' => false, 'message' => "Wholesale price for \"$name\" is invalid."]);
            }
            if ($minPrice !== null && $minPrice !== '' && ! is_numeric($minPrice)) {
                return response()->json(['status' => false, 'message' => "Minimum price for \"$name\" is invalid."]);
            }

            $categoryName = trim($categoryName);
            if ($categoryName === '') {
                return response()->json(['status' => false, 'message' => "Missing category name for service \"$name\"."]);
            }

            $category = Category::firstOrCreate(['name' => $categoryName]);

            $subCategoryId = null;
            $subCategoryName = trim($subCategoryName);
            if ($subCategoryName !== '') {
                $subCategory = SubCategory::firstOrCreate(
                    ['category_id' => $category->id, 'name' => $subCategoryName],
                    ['status' => true]
                );
                $subCategoryId = $subCategory->id;
            }

            $unit = Unit::where('ShortName', $unitName)->orWhere('name', $unitName)->first();
            if (! $unit) {
                return response()->json(['status' => false, 'message' => "Unit \"$unitName\" for \"$name\" does not exist."]);
            }

            $brandId = null;
            if (trim($brandName) !== '') {
                $brand = Brand::firstOrCreate(['name' => trim($brandName)]);
                $brandId = $brand->id;
            }

            $seenCodes[$code] = true;

            $toCreate[] = [
                'name' => $name,
                'code' => $code,
                'price' => (float) $price,
                'cost' => 0,
                'category_id' => $category->id,
                'sub_category_id' => $subCategoryId,
                'unit_id' => $unit->id,
                'unit_sale_id' => $unit->id,
                'unit_purchase_id' => $unit->id,
                'brand_id' => $brandId,
                'stock_alert' => 0,
                'note' => $note ?: null,
                'wholesale_price' => is_numeric($wholesale) ? (float) $wholesale : 0,
                'min_price' => is_numeric($minPrice) ? (float) $minPrice : 0,
            ];
        }

        try {
            DB::transaction(function () use ($toCreate, $warehouses) {
                DB::disableQueryLog();

                $PW_BATCH_ROWS = 12000;
                $pwRows = [];

                foreach ($toCreate as $data) {
                    $product = Product::create([
                        // Home warehouse: the importer's own (first) warehouse.
                        'warehouse_id' => $this->productHomeWarehouseFor(null, false),
                        'type' => 'is_service',
                        'is_variant' => 0,
                        'is_imei' => 0,
                        'not_selling' => 0,
                        'is_active' => 1,
                        'Type_barcode' => 'CODE128',
                        'image' => 'no-image.png',
                        'TaxNet' => 0,
                        'tax_method' => 1,
                        'discount' => 0,
                        'discount_method' => 1,
                        ...$data,
                    ]);

                    if (Schema::hasTable('category_product') && ! empty($data['category_id'])) {
                        $product->categories()->sync([(int) $data['category_id']]);
                    }
                    if (Schema::hasTable('product_subcategory') && ! empty($data['sub_category_id'])) {
                        $product->subcategories()->sync([(int) $data['sub_category_id']]);
                    }

                    foreach ($warehouses as $wid) {
                        $pwRows[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $wid,
                            'manage_stock' => 0,
                            'qte' => 0,
                        ];
                    }

                    if (count($pwRows) >= $PW_BATCH_ROWS) {
                        DB::table('product_warehouse')->insert($pwRows);
                        $pwRows = [];
                    }
                }

                if (! empty($pwRows)) {
                    DB::table('product_warehouse')->insert($pwRows);
                }
            });

            return response()->json(['status' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while importing: '.$e->getMessage(),
            ]);
        }
    }

    public function import_variant_products(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        ini_set('max_execution_time', 2000);

        $request->validate([
            'products' => 'required|mimes:xls,xlsx',
        ]);

        // First sheet, heading row -> associative rows
        $rows = Excel::toArray(new ProductImport, $request->file('products'))[0] ?? [];

        // Drop rows that are entirely empty (all values null/blank)
        $rows = array_values(array_filter($rows, function ($row) {
            if (! is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    return true;
                }
            }

            return false;
        }));
        if (empty($rows)) {
            return response()->json(['status' => false, 'message' => 'The imported file is empty.']);
        }

        // ---- helpers ----
        $normalizeKeys = function (array $row) {
            $out = [];
            foreach ($row as $k => $v) {
                $key = is_string($k) ? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $k))) : $k;
                $out[$key] = $v;
            }

            return $out;
        };
        $firstVal = function (array $row, array $keys, $default = null) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $row)) {
                    $vv = is_string($row[$k]) ? trim($row[$k]) : $row[$k];
                    if ($vv !== '' && $vv !== null) {
                        return $vv;
                    }
                }
            }

            return $default;
        };

        // sanitize plain text (strip HTML/JS, trim, neutralize common patterns)
        $clean = function ($v, $allowEmpty = false) {
            if (is_null($v)) {
                $v = '';
            }
            $v = (string) $v;
            $v = trim(strip_tags($v));
            $v = str_ireplace(['javascript:', '&#x', '&lt;', '&gt;'], '', $v);
            if (! $allowEmpty && $v === '') {
                return '';
            }
            if (preg_match('/[<>]/', $v)) {
                $v = str_replace(['<', '>'], '', $v);
            }

            return $v;
        };

        $warehouses = Warehouse::whereNull('deleted_at')->pluck('id')->toArray();

        // Global uniqueness set (DB): products.code + product_variants.code
        $existingProductCodes = Product::whereNull('deleted_at')->pluck('code')->all();
        $existingVariantCodes = ProductVariant::whereNull('deleted_at')->pluck('code')->all();
        // Additional barcodes share the namespace: a new code may not be one.
        $existingCodes = array_fill_keys(array_merge($existingProductCodes, $existingVariantCodes, ProductBarcodes::all()), true);

        // In-file sets
        $fileParentCodes = []; // product_code's seen in file
        $fileVariantCodes = []; // variant_code's seen in file

        $groups = []; // product_code => ['parent'=>..., 'variants'=>[]]

        // ---- parse & validate rows ----
        foreach ($rows as $raw) {
            $r = $normalizeKeys($raw);

            // Optional "type" must be is_variant if present
            $type = $firstVal($r, ['type'], null);
            if ($type !== null && strtolower($type) !== 'is_variant') {
                return response()->json(['status' => false, 'message' => 'File contains non-variant rows. Use the single-products importer for those.']);
            }

            // Your headers (then sanitize)
            $parentName = $clean((string) $firstVal($r, ['product_name', 'name'], ''));
            $parentCode = $clean((string) $firstVal($r, ['product_code', 'code', 'parent_code'], ''));
            $category = $clean((string) $firstVal($r, ['category', 'category_name'], ''));
            $subCategoryName = $clean((string) $firstVal($r, ['sub_category', 'subcategory', 'sub_category_name'], ''), true);
            $unitName = $clean((string) $firstVal($r, ['unit', 'unit_name', 'unit_short'], ''));
            $brandName = $clean((string) $firstVal($r, ['brand', 'brand_name'], ''), true);

            $vName = $clean((string) $firstVal($r, ['variant_name', 'name_variant'], ''));
            $vCode = $clean((string) $firstVal($r, ['variant_code', 'sku', 'variant_sku'], ''));
            $vCost = $firstVal($r, ['variant_cost', 'v_cost'], null);
            $vPrice = $firstVal($r, ['variant_price', 'v_price'], null);
            $vWholesale = $firstVal($r, ['variant_wholesale', 'v_wholesale'], null);
            $vMinPrice = $firstVal($r, ['variant_min_price', 'v_min_price'], null);
            // Optional Shopify-style options: "Color=Red|Size=S" (also accepts ':' and ';').
            $vOptionsRaw = trim(strip_tags((string) $firstVal($r, ['variant_options', 'options', 'option_values'], '')));
            $vOptionValues = [];
            if ($vOptionsRaw !== '') {
                foreach (preg_split('/[|;]/', $vOptionsRaw) as $pair) {
                    $parts = preg_split('/[=:]/', $pair, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    [$optName, $optValue] = array_map('trim', $parts);
                    if ($optName !== '' && $optValue !== '') {
                        $vOptionValues[$optName] = $optValue;
                    }
                }
            }

            // Required checks (same logic, now on sanitized values)
            if ($parentName === '') {
                return response()->json(['status' => false, 'message' => 'Parent product name is missing.']);
            }
            if ($parentCode === '') {
                return response()->json(['status' => false, 'message' => "Parent product \"$parentName\" has no parent code."]);
            }
            if ($category === '') {
                return response()->json(['status' => false, 'message' => "Missing category name for product \"$parentName\"."]);
            }
            if ($unitName === '') {
                return response()->json(['status' => false, 'message' => "Unit is missing for product \"$parentName\"."]);
            }
            if ($vName === '') {
                return response()->json(['status' => false, 'message' => "Variant name is missing for parent \"$parentName\"."]);
            }
            if ($vCode === '') {
                return response()->json(['status' => false, 'message' => "Variant code is missing for parent \"$parentName\"."]);
            }
            if (! is_numeric($vCost)) {
                return response()->json(['status' => false, 'message' => "Variant cost for \"$vName\" is invalid."]);
            }
            if (! is_numeric($vPrice)) {
                return response()->json(['status' => false, 'message' => "Variant price for \"$vName\" is invalid."]);
            }
            if ($vWholesale !== null && $vWholesale !== '' && ! is_numeric($vWholesale)) {
                return response()->json(['status' => false, 'message' => "Variant wholesale for \"$vName\" is invalid."]);
            }
            if ($vMinPrice !== null && $vMinPrice !== '' && ! is_numeric($vMinPrice)) {
                return response()->json(['status' => false, 'message' => "Variant minimum price for \"$vName\" is invalid."]);
            }

            // ---- in-file cross checks ----
            if (isset($fileVariantCodes[$parentCode])) {
                return response()->json([
                    'status' => false,
                    'message' => "Parent code \"$parentCode\" conflicts with a variant_code in the same file.",
                ]);
            }
            if (isset($fileParentCodes[$vCode])) {
                return response()->json([
                    'status' => false,
                    'message' => "Variant code \"$vCode\" conflicts with a product_code in the same file.",
                ]);
            }
            $fileParentCodes[$parentCode] = true;

            if (isset($fileVariantCodes[$vCode])) {
                return response()->json(['status' => false, 'message' => "Duplicate variant code \"$vCode\" found in file."]);
            }
            $fileVariantCodes[$vCode] = true;

            // ---- global (DB) uniqueness ----
            if (isset($existingCodes[$parentCode])) {
                return response()->json(['status' => false, 'message' => "Parent code \"$parentCode\" already exists (product or variant)."]);
            }
            if (isset($existingCodes[$vCode])) {
                return response()->json(['status' => false, 'message' => "Variant code \"$vCode\" already exists (product or variant)."]);
            }

            // Group and keep parent consistency
            if (! isset($groups[$parentCode])) {
                $groups[$parentCode] = [
                    'parent' => [
                        'name' => $parentName,
                        'code' => $parentCode,
                        'category' => $category,
                        'sub_category' => $subCategoryName,
                        'unit' => $unitName,
                        'brand' => $brandName,
                        'stock_alert' => 0,
                        'note' => null,
                    ],
                    'variants' => [],
                ];
            } else {
                $p = $groups[$parentCode]['parent'];
                if ($p['name'] !== $parentName || $p['category'] !== $category || $p['unit'] !== $unitName || (string) $p['brand'] !== (string) $brandName) {
                    return response()->json([
                        'status' => false,
                        'message' => "Inconsistent parent data for code \"$parentCode\". Ensure product_name/category/unit/brand are identical for all its rows.",
                    ]);
                }
            }

            $groups[$parentCode]['variants'][] = [
                'variant_name' => $vName,
                'variant_code' => $vCode,
                'variant_cost' => (float) $vCost,
                'variant_price' => (float) $vPrice,
                'variant_wholesale' => is_numeric($vWholesale) ? (float) $vWholesale : 0,
                'variant_min_price' => is_numeric($vMinPrice) ? (float) $vMinPrice : 0,
                'variant_qty' => 0,
                'option_values' => $vOptionValues,
            ];
        }

        // ---- persist ----
        try {
            DB::transaction(function () use ($groups, $warehouses) {
                $pwRows = [];

                foreach ($groups as $parentCode => $bundle) {
                    $p = $bundle['parent'];

                    // Category (create if missing)
                    $category = Category::firstOrCreate(['name' => trim($p['category'])]);

                    // Sub-category (optional, create if missing)
                    $subCategoryId = null;
                    $subCatName = trim((string) ($p['sub_category'] ?? ''));
                    if ($subCatName !== '') {
                        $subCat = SubCategory::firstOrCreate(
                            ['category_id' => $category->id, 'name' => $subCatName],
                            ['status' => true]
                        );
                        $subCategoryId = $subCat->id;
                    }

                    // Unit (must exist)
                    $unit = Unit::where('ShortName', $p['unit'])->orWhere('name', $p['unit'])->first();
                    if (! $unit) {
                        throw new \RuntimeException("Unit \"{$p['unit']}\" for \"{$p['name']}\" does not exist.");
                    }

                    // Brand (optional)
                    $brandId = null;
                    if (trim((string) $p['brand']) !== '') {
                        $brand = Brand::firstOrCreate(['name' => trim((string) $p['brand'])]);
                        $brandId = $brand->id;
                    }

                    // Parent product (variant type)
                    $product = Product::create([
                        // Home warehouse: the importer's own (first) warehouse.
                        'warehouse_id' => $this->productHomeWarehouseFor(null, false),
                        'type' => 'is_variant',
                        'is_variant' => 1,
                        'is_imei' => 0,
                        'not_selling' => 0,
                        'is_active' => 1,
                        'Type_barcode' => 'CODE128',
                        'image' => 'no-image.png',
                        'TaxNet' => 0,
                        'tax_method' => 1,
                        'price' => 0,
                        'cost' => 0,
                        'name' => $p['name'],
                        'code' => $p['code'],
                        'category_id' => $category->id,
                        'sub_category_id' => $subCategoryId,
                        'unit_id' => $unit->id,
                        'unit_sale_id' => $unit->id,
                        'unit_purchase_id' => $unit->id,
                        'brand_id' => $brandId,
                        'stock_alert' => 0,
                        'note' => null,
                    ]);

                    // Variants — forced image = 'no-image.png'
                    $variantRows = [];
                    $codesForFetch = [];
                    foreach ($bundle['variants'] as $v) {
                        $variantRows[] = [
                            'product_id' => $product->id,
                            'name' => $v['variant_name'], // sanitized
                            'code' => $v['variant_code'], // sanitized
                            'cost' => $v['variant_cost'],
                            'price' => $v['variant_price'],
                            // optional columns if present in schema
                            'wholesale' => Schema::hasColumn('product_variants', 'wholesale') ? ($v['variant_wholesale'] ?? 0) : null,
                            'min_price' => Schema::hasColumn('product_variants', 'min_price') ? ($v['variant_min_price'] ?? 0) : null,
                            'image' => 'no-image.png',
                        ];
                        $codesForFetch[] = $v['variant_code'];
                    }
                    ProductVariant::insert($variantRows);

                    // Map variant IDs
                    $created = ProductVariant::where('product_id', $product->id)
                        ->whereIn('code', $codesForFetch)
                        ->whereNull('deleted_at')
                        ->get(['id', 'code']);

                    $idByCode = [];
                    foreach ($created as $cv) {
                        $idByCode[$cv->code] = $cv->id;
                    }

                    // Shopify-style options from the optional variant_options column.
                    $namedByVariant = [];
                    foreach ($bundle['variants'] as $v) {
                        $vid = $idByCode[$v['variant_code']] ?? null;
                        if ($vid && ! empty($v['option_values'])) {
                            $namedByVariant[$vid] = $v['option_values'];
                        }
                    }
                    if ($namedByVariant) {
                        app(ProductOptionService::class)->importNamedValues($product, $namedByVariant);
                    }

                    // product_warehouse (forced qte=0)
                    foreach ($bundle['variants'] as $v) {
                        $vid = $idByCode[$v['variant_code']] ?? null;
                        if (! $vid) {
                            continue;
                        }
                        foreach ($warehouses as $wid) {
                            $pwRows[] = [
                                'product_id' => $product->id,
                                'warehouse_id' => $wid,
                                'product_variant_id' => $vid,
                                'manage_stock' => 1,
                                'qte' => 0,
                            ];
                        }
                    }
                }

                if (! empty($pwRows)) {
                    product_warehouse::insert($pwRows);
                }
            });

            return response()->json(['status' => true]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while importing variants: '.$e->getMessage(),
            ]);
        }
    }

        /**
     * IMPORT: Update Only - Updates existing products by code
     * Expected CSV format: code,cost,retail_price
     * Only updates cost and retail_price (price field) for products matching the code
     */
    public function import_update_only(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        ini_set('max_execution_time', 2000);

        // Validate file - check extension instead of MIME type for better CSV support
        $request->validate([
            'products' => [
                'required',
                'file',
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    $allowedExtensions = ['csv', 'xls', 'xlsx'];
                    if (!in_array($extension, $allowedExtensions)) {
                        $fail('The ' . $attribute . ' must be a file of type: csv, xls, xlsx.');
                    }
                },
            ],
        ]);

        // Read file - support both CSV and Excel
        $fileExtension = strtolower($request->file('products')->getClientOriginalExtension());
        
        if ($fileExtension === 'csv') {
            // Handle CSV file
            $file = fopen($request->file('products')->getRealPath(), 'r');
            $rows = [];
            $headers = null;
            
            // Normalize header function
            $normalizeHeader = function($header) {
                return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $header)));
            };
            
            while (($row = fgetcsv($file)) !== false) {
                if ($headers === null) {
                    // Normalize headers: lowercase, trim, replace spaces/special chars with underscore
                    $headers = array_map($normalizeHeader, $row);
                    continue;
                }
                
                if (count($row) !== count($headers)) {
                    continue; // Skip malformed rows
                }
                
                $rows[] = array_combine($headers, array_map('trim', $row));
            }
            fclose($file);
        } else {
            // Handle Excel file
            $rows = Excel::toArray(new ProductImport, $request->file('products'))[0] ?? [];
        }

        // Drop rows that are entirely empty
        $rows = array_values(array_filter($rows, function ($row) {
            if (! is_array($row)) {
                return false;
            }
            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    return true;
                }
            }
            return false;
        }));

        if (empty($rows)) {
            return response()->json(['status' => false, 'message' => 'The imported file is empty.']);
        }

        $updated = 0;
        $notFound = [];
        $errors = [];

        // Columns this import cannot change are reported, not silently dropped.
        $knownColumns = ['code', 'cost', 'retail_price', 'price', 'name', 'category', 'sub_category', 'subcategory', 'brand'];
        $ignoredColumns = [];
        foreach (array_keys($rows[0]) as $header) {
            $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $header), '_'));
            if ($normalized !== '' && ! is_numeric($normalized) && ! in_array($normalized, $knownColumns, true)) {
                $ignoredColumns[] = (string) $header;
            }
        }

        // A whole-catalogue file is tens of thousands of rows: products load in
        // chunks, category / sub-category / brand names resolve once each, and
        // rows commit in batches of 500 (each row still its own savepoint, so
        // one failing row is reported without undoing the others).
        $productsByCode = [];
        $allCodes = array_values(array_filter(array_map(function ($row) {
            foreach ($row as $key => $value) {
                if (strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $key))) === 'code') {
                    return trim((string) $value);
                }
            }

            return '';
        }, $rows), fn ($c) => $c !== ''));
        foreach (array_chunk(array_unique($allCodes), 1000) as $chunk) {
            foreach (Product::whereNull('deleted_at')->whereIn('code', $chunk)->get() as $p) {
                $productsByCode[$p->code] ??= $p;
            }
        }
        $hasCategoryPivot = Schema::hasTable('category_product');
        $hasSubCategoryPivot = Schema::hasTable('product_subcategory');
        $categoryIds = [];
        $subCategoryIds = [];
        $brandIds = [];
        $batchOpen = false;

        foreach ($rows as $index => $row) {
            if ($index % 500 === 0) {
                if ($batchOpen) {
                    DB::commit();
                }
                DB::beginTransaction();
                $batchOpen = true;
            }

            // Normalize keys (handle case-insensitive and variations)
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                $normalizedKey = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $key)));
                $normalizedRow[$normalizedKey] = $value;
            }

            // Extract values - support multiple possible column names
            $code = trim((string) ($normalizedRow['code'] ?? ''));
            $cost = $normalizedRow['cost'] ?? null;
            $retailPrice = $normalizedRow['retail_price'] ?? ($normalizedRow['price'] ?? null);
            // Text fields: blank = unchanged, like the prices.
            $text = fn ($key) => trim((string) ($normalizedRow[$key] ?? ''));
            $name = $text('name');
            $categoryName = $text('category');
            $subCategoryName = $text('sub_category') !== '' ? $text('sub_category') : $text('subcategory');
            $brandName = $text('brand');

            // Validation
            if (empty($code)) {
                $errors[] = "Row " . ($index + 2) . ": Missing product code.";
                continue;
            }

            // A blank cell leaves that value as it is; only a non-number is an error.
            $cost = is_string($cost) ? trim($cost) : $cost;
            $retailPrice = is_string($retailPrice) ? trim($retailPrice) : $retailPrice;
            $hasCost = $cost !== null && $cost !== '';
            $hasPrice = $retailPrice !== null && $retailPrice !== '';

            if ($hasCost && ! is_numeric($cost)) {
                $errors[] = "Row " . ($index + 2) . " (code: $code): Invalid cost.";
                continue;
            }

            if ($hasPrice && ! is_numeric($retailPrice)) {
                $errors[] = "Row " . ($index + 2) . " (code: $code): Invalid retail_price.";
                continue;
            }

            if (mb_strlen($name) > 192) {
                $errors[] = "Row " . ($index + 2) . " (code: $code): name is longer than 192 characters.";
                continue;
            }

            if (! $hasCost && ! $hasPrice && $name === '' && $categoryName === '' && $subCategoryName === '' && $brandName === '') {
                $errors[] = "Row " . ($index + 2) . " (code: $code): Nothing to update.";
                continue;
            }

            // Find product by code (only non-deleted products, preloaded above)
            $product = $productsByCode[$code] ?? null;

            if (!$product) {
                $notFound[] = $code;
                continue;
            }

            // Only the cells that hold a value change (retail_price maps to price).
            try {
                // Names resolve first, outside the row's savepoint: a category
                // created here must not vanish with a later failure of this row
                // while its id stays cached for the rows after it.
                $brandId = $brandName !== ''
                    ? ($brandIds[$brandName] ??= Brand::firstOrCreate(['name' => $brandName])->id)
                    : null;
                // Category / sub-category by name, created when new, as the
                // product import does. A sub-category alone is looked up
                // under the product's current category.
                $categoryId = $categoryName !== ''
                    ? ($categoryIds[$categoryName] ??= Category::firstOrCreate(['name' => $categoryName])->id)
                    : null;
                $parentCategoryId = $categoryId ?? (int) $product->category_id;
                $subCategoryId = ($subCategoryName !== '' && $parentCategoryId)
                    ? ($subCategoryIds[$parentCategoryId.'|'.$subCategoryName] ??= SubCategory::firstOrCreate(
                        ['category_id' => $parentCategoryId, 'name' => $subCategoryName],
                        ['status' => true]
                    )->id)
                    : null;

                $apply = function () use ($product, $hasCost, $cost, $hasPrice, $retailPrice, $name, $brandId, $categoryId, $subCategoryId, $hasCategoryPivot, $hasSubCategoryPivot) {
                    if ($hasCost) {
                        $product->cost = (float) $cost;
                    }
                    if ($hasPrice) {
                        $product->price = (float) $retailPrice;
                    }
                    if ($name !== '') {
                        $product->name = $name;
                    }
                    if ($brandId) {
                        $product->brand_id = $brandId;
                    }

                    $oldCategoryId = (int) $product->category_id;
                    $oldSubCategoryId = (int) $product->sub_category_id;
                    if ($categoryId) {
                        $product->category_id = $categoryId;
                        if ((int) $categoryId !== $oldCategoryId && ! $subCategoryId) {
                            $product->sub_category_id = null; // it belonged to the old category
                        }
                    }
                    if ($subCategoryId) {
                        $product->sub_category_id = $subCategoryId;
                    }
                    $product->save();

                    // Keep the multi-category pivots in step with the primary ids.
                    if ((int) $product->category_id !== $oldCategoryId && $hasCategoryPivot) {
                        if ($oldCategoryId) {
                            $product->categories()->detach($oldCategoryId);
                        }
                        $product->categories()->syncWithoutDetaching([(int) $product->category_id]);
                    }
                    if ((int) $product->sub_category_id !== $oldSubCategoryId && $hasSubCategoryPivot) {
                        if ($oldSubCategoryId) {
                            $product->subcategories()->detach($oldSubCategoryId);
                        }
                        if ($product->sub_category_id) {
                            $product->subcategories()->syncWithoutDetaching([(int) $product->sub_category_id]);
                        }
                    }
                };

                // A category move is several writes and gets its own savepoint;
                // a price / name / brand change is one save and needs none.
                ($categoryId || $subCategoryId) ? DB::transaction($apply) : $apply();
                $updated++;
            } catch (\Throwable $e) {
                // Any failure stays this row's (an \Error would otherwise
                // escape the loop and leave the batch transaction open). A
                // database error is reported without its SQL.
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \Error) {
                    report($e); // unexpected: keep the detail in the log
                }
                $reason = $e instanceof \Illuminate\Database\QueryException ? 'database error' : $e->getMessage();
                $errors[] = "Row " . ($index + 2) . " (code: $code): Failed to update - " . $reason;
            }
        }
        if ($batchOpen) {
            DB::commit();
        }

        // Build response message
        $messages = [];
        if ($updated > 0) {
            $messages[] = "Successfully updated $updated product(s).";
        }
        if (count($notFound) > 0) {
            $messages[] = count($notFound) . " product code(s) not found: " . implode(', ', array_slice($notFound, 0, 10)) . (count($notFound) > 10 ? '...' : '');
        }
        if (count($errors) > 0) {
            $messages[] = count($errors) . " error(s) occurred. " . implode(' ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? '...' : '');
        }
        if (count($ignoredColumns) > 0) {
            $messages[] = 'Ignored column(s), not changed by Update only: ' . implode(', ', $ignoredColumns) . '.';
        }

        if ($updated === 0 && count($notFound) === 0 && count($errors) === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No products were updated. Please check your file format.',
                'ignored_columns' => $ignoredColumns,
            ]);
        }

        return response()->json([
            'status' => $updated > 0,
            'message' => implode(' ', $messages),
            'updated' => $updated,
            'not_found' => count($notFound),
            'errors' => count($errors),
            'ignored_columns' => $ignoredColumns,
            // Every rejected row, for the on-screen list and its download.
            'error_list' => array_merge($errors, array_map(fn ($c) => "Code not found: $c", $notFound)),
        ]);
    }

    // ----------------- price_check

    /**
     * GET price-check?code=… → {found, name, price, unit, currency}: the shelf
     * price check for a customer-facing tablet. Name and selling price ONLY —
     * never cost or stock — so it needs no permission: give the tablet a user
     * whose role has none. Matches a product code, a variant code or an
     * additional barcode; the price is per sale unit, tax included.
     */
    public function price_check(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '' || mb_strlen($code) > 191) {
            return response()->json(['found' => false]);
        }

        $product = Product::whereNull('deleted_at')->where('is_active', 1)->where('code', $code)->first();
        $variant = null;
        if (! $product) {
            $variant = ProductVariant::whereNull('deleted_at')->where('code', $code)->first();
            if (! $variant && ($owner = ProductBarcodes::owner($code))) {
                $variant = $owner[1] ? ProductVariant::whereNull('deleted_at')->find($owner[1]) : null;
                $product = $owner[1] ? null : Product::whereNull('deleted_at')->where('is_active', 1)->find($owner[0]);
            }
            if ($variant) {
                $product = Product::whereNull('deleted_at')->where('is_active', 1)->find($variant->product_id);
            }
        }
        if (! $product || ($product->type === 'is_variant' && ! $variant)) {
            return response()->json(['found' => false]);
        }

        // The register's math (Products_by_Warehouse / POS): per sale unit,
        // then the product's own discount, then exclusive tax on top.
        $price = (float) ($variant ? $variant->price : $product->price);

        // Different prices per store: the kiosk's store price — the page's
        // ?warehouse=<id> when this user may see it, else the user's only
        // assigned warehouse. No store known = the normal price.
        if (WarehousePrices::enabled()) {
            $kioskWarehouse = null;
            if ($request->filled('warehouse_id') && $this->canAccessWarehouse($request->warehouse_id)) {
                $kioskWarehouse = (int) $request->warehouse_id;
            } else {
                $assigned = $this->userWarehouseIds();
                if (! optional(auth()->user())->is_all_warehouses && count($assigned) === 1) {
                    $kioskWarehouse = (int) $assigned[0];
                }
            }
            $price = (float) WarehousePrices::pick(
                WarehousePrices::find($product->id, $variant ? $variant->id : null, $kioskWarehouse), 'price', $price
            );
        }

        $unit = $product->unit_sale_id ? Unit::find($product->unit_sale_id) : null;
        if ($unit && (float) $unit->operator_value > 0) {
            $price = $unit->operator === '/' ? $price / $unit->operator_value : $price * $unit->operator_value;
        }
        if ((float) $product->discount > 0) {
            $price -= $product->discount_method == '1' ? $price * (float) $product->discount / 100 : (float) $product->discount;
        }
        if ($product->tax_method == '1' && (float) $product->TaxNet > 0) {
            $price *= 1 + (float) $product->TaxNet / 100;
        }

        $image = explode(',', (string) $product->image)[0] ?: null;

        return response()->json([
            'found' => true,
            'name' => $variant ? $product->name.' — '.$variant->name : $product->name,
            'price' => round($price, 2),
            'unit' => $unit?->ShortName,
            'image' => $image !== 'no-image.png' ? $image : null,
        ]);
    }

    // ----------------- generate_code

    /**
     * GET products/generate-code?symbology=… → {code}: the next prefix +
     * number code, or null when numbering is off or the symbology needs digits
     * (the form then makes its usual random code).
     */
    public function generate_code(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        return response()->json(['code' => \App\Services\ProductCodeGenerator::next($request->input('symbology', 'CODE128'))]);
    }

    // ----------------- import_barcodes

    /**
     * Import additional barcodes: rows of Code + Barcode (one barcode per row,
     * a product may appear on many rows; a variant code targets that variant).
     * mode=append adds to what each product has, mode=replace makes the file's
     * list the product's whole list. Every row is checked before anything is
     * written; any problem rejects the file with all reasons listed.
     */
    public function import_barcodes(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        ini_set('max_execution_time', 2000);

        $request->validate([
            'products' => 'required|file|max:20480',
            'mode' => 'nullable|in:append,replace',
        ]);
        if (! ProductBarcodes::enabled()) {
            return response()->json(['status' => false, 'errors' => ['Additional barcodes are not set up yet: run the database update first.']], 422);
        }
        $extension = strtolower($request->file('products')->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            return response()->json(['status' => false, 'errors' => ['The file must be an .xlsx, .xls or .csv file.']], 422);
        }

        $normalize = fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h)), '_'));
        $rows = [];
        if ($extension === 'csv') {
            $handle = fopen($request->file('products')->getRealPath(), 'r');
            $headers = null;
            while (($line = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                    $headers = array_map($normalize, $line);

                    continue;
                }
                $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), null));
            }
            fclose($handle);
        } else {
            foreach (Excel::toArray(new OpeningStockRowsImport, $request->file('products'))[0] ?? [] as $row) {
                $rows[] = array_combine(array_map($normalize, array_keys($row)), array_values($row));
            }
        }

        $cells = [];
        foreach ($rows as $i => $row) {
            $cells[$i] = [
                trim((string) ($row['code'] ?? $row['product_code'] ?? '')),
                trim((string) ($row['barcode'] ?? $row['ean'] ?? '')),
            ];
        }

        // Everything a whole-catalogue file needs, loaded in chunks up front:
        // products and variants by the file's codes AND by its barcodes (a
        // barcode may not be anyone's code), and current barcode owners.
        // Barcode checks compare case-insensitively, like the database does
        // (so the *ByLower maps); deleted variants' barcodes are freed first.
        ProductBarcodes::purgeDead();
        $lookups = array_values(array_unique(array_filter(array_merge(array_column($cells, 0), array_column($cells, 1)), fn ($v) => $v !== '')));
        $productsByCode = [];
        $variantsByCode = [];
        $productsByLower = [];
        $variantsByLower = [];
        $ownerByBarcode = [];
        foreach (array_chunk($lookups, 1000) as $chunk) {
            foreach (Product::whereNull('deleted_at')->whereIn('code', $chunk)->get(['id', 'code', 'name', 'type', 'is_variant']) as $p) {
                $productsByCode[$p->code] = $p;
                $productsByLower[mb_strtolower($p->code)] = $p;
            }
            foreach (ProductVariant::whereNull('deleted_at')->whereIn('code', $chunk)->get(['id', 'product_id', 'code']) as $v) {
                $variantsByCode[$v->code] = $v;
                $variantsByLower[mb_strtolower($v->code)] = $v;
            }
            foreach (DB::table('product_barcodes')->whereIn('barcode', $chunk)->get(['barcode', 'product_id', 'product_variant_id']) as $b) {
                $ownerByBarcode[mb_strtolower($b->barcode)] = [(int) $b->product_id, $b->product_variant_id !== null ? (int) $b->product_variant_id : null];
            }
        }
        $variantParents = Product::whereNull('deleted_at')
            ->whereIn('id', collect($variantsByCode)->pluck('product_id')->unique()->all() ?: [0])
            ->get(['id', 'code', 'name', 'type', 'is_variant'])->keyBy('id');

        // Pass 1 — parse and resolve codes.
        $errors = [];
        $wanted = []; // "pid|vid" → ['product_id', 'variant_id', 'code', 'barcodes' => [barcode => row]]
        $seen = [];   // barcode → "pid|vid" within the file
        foreach ($cells as $i => [$code, $barcode]) {
            $rowNum = $i + 2;
            if ($code === '' && $barcode === '') {
                continue;
            }
            if ($code === '' || $barcode === '') {
                $errors[] = "Row {$rowNum}: both code and barcode are required.";

                continue;
            }

            $product = $productsByCode[$code] ?? null;
            $variantId = null;
            if (! $product && isset($variantsByCode[$code])) {
                $product = $variantParents->get($variantsByCode[$code]->product_id);
                $variantId = $product ? (int) $variantsByCode[$code]->id : null;
            }
            if (! $product) {
                $errors[] = "Row {$rowNum}: code '{$code}' does not exist.";

                continue;
            }
            if ($variantId === null && ($product->type === 'is_variant' || $product->is_variant)) {
                $errors[] = "Row {$rowNum} ({$code}): this product has variants — use a variant code.";

                continue;
            }

            $key = $product->id.'|'.($variantId ?? '');
            $lower = mb_strtolower($barcode);
            if (isset($seen[$lower]) && $seen[$lower] !== $key) {
                $errors[] = "Row {$rowNum}: barcode '{$barcode}' is given to two different products in this file.";

                continue;
            }
            $seen[$lower] = $key;
            $wanted[$key] ??= ['product_id' => (int) $product->id, 'variant_id' => $variantId, 'code' => $code, 'barcodes' => []];
            $wanted[$key]['barcodes'][$lower] ??= [$barcode, $rowNum];
        }

        // Pass 2 — each barcode must be free (or already this product's): the
        // same rules as ProductBarcodes::conflicts(), against the preloads.
        foreach ($wanted as $w) {
            foreach ($w['barcodes'] as $lower => [$barcode, $rowNum]) {
                $lower = (string) $lower;
                $owner = $ownerByBarcode[$lower] ?? null;
                $variant = $variantsByLower[$lower] ?? null;
                if (mb_strlen($barcode) > 64) {
                    $errors[] = "Row {$rowNum}: barcode \"{$barcode}\" is longer than 64 characters.";
                } elseif (isset($productsByLower[$lower])) {
                    $errors[] = "Row {$rowNum}: barcode \"{$barcode}\" is already the code of \"{$productsByLower[$lower]->name}\".";
                } elseif ($variant) {
                    $own = (int) $variant->product_id === $w['product_id'] && (int) $variant->id === (int) $w['variant_id'];
                    $errors[] = "Row {$rowNum}: barcode \"{$barcode}\" is ".($own ? "the variant's own code." : 'already a variant code.');
                } elseif ($owner && ! ($owner[0] === $w['product_id'] && $owner[1] === $w['variant_id'])) {
                    $errors[] = "Row {$rowNum}: barcode \"{$barcode}\" already belongs to another product.";
                }
            }
        }

        if ($errors) {
            return response()->json(['status' => false, 'message' => count($errors).' problem(s) found — nothing was imported.', 'errors' => $errors]);
        }
        if (! $wanted) {
            return response()->json(['status' => false, 'errors' => ['No rows found. The file needs a Code and a Barcode column.']], 422);
        }

        // Written in bulk. Replace first clears the listed products' / variants'
        // lists; append skips barcodes the product already has.
        $replace = $request->input('mode') === 'replace';
        $added = 0;
        DB::transaction(function () use ($wanted, $replace, $ownerByBarcode, &$added) {
            if ($replace) {
                $productIds = array_values(array_map(fn ($w) => $w['product_id'], array_filter($wanted, fn ($w) => $w['variant_id'] === null)));
                $variantIds = array_values(array_map(fn ($w) => $w['variant_id'], array_filter($wanted, fn ($w) => $w['variant_id'] !== null)));
                foreach (array_chunk($productIds, 1000) as $chunk) {
                    DB::table('product_barcodes')->whereIn('product_id', $chunk)->whereNull('product_variant_id')->delete();
                }
                foreach (array_chunk($variantIds, 1000) as $chunk) {
                    DB::table('product_barcodes')->whereIn('product_variant_id', $chunk)->delete();
                }
            }

            $now = now();
            $insert = [];
            foreach ($wanted as $w) {
                foreach ($w['barcodes'] as $lower => [$barcode]) {
                    if (! $replace && isset($ownerByBarcode[(string) $lower])) {
                        continue; // already this product's (others were rejected above)
                    }
                    $insert[] = ['product_id' => $w['product_id'], 'product_variant_id' => $w['variant_id'], 'barcode' => $barcode, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($insert, 1000) as $chunk) {
                DB::table('product_barcodes')->insert($chunk);
            }
            $added = count($insert);
        });

        return response()->json([
            'status' => true,
            'products' => count($wanted),
            'added' => $added,
            'message' => count($wanted).' product(s) updated, '.$added.' barcode(s) added.',
        ]);
    }

    // ----------------- count_stock_list

    public function count_stock_list(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'count_stock', Product::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;

        $count_stock = CountStock::whereNull('deleted_at')
            ->with(['warehouse', 'user', 'category'])
            ->whereIn('warehouse_id', $this->userWarehouseIds())
            ->where(function ($query) use ($request) {
                $query->when($request->filled('search'), function ($query) use ($request) {
                    $search = $request->search;

                    $query->whereHas('warehouse', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })->orWhereHas('category', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
                });
            });
        $totalRows = $count_stock->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $stocks = $count_stock->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];

        foreach ($stocks as $stock) {

            $item['id'] = $stock->id;
            $item['date'] = $stock->date;
            $item['warehouse_name'] = $stock['warehouse']->name;
            $item['category_name'] = $stock['category'] ? $stock['category']->name : '---';
            $item['file_stock'] = $stock->file_stock;

            $data[] = $item;
        }

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $categories = Category::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'stocks' => $data,
            'warehouses' => $warehouses,
            'categories' => $categories,
        ]);
    }

    // ----------------- store_count_stock

    public function store_count_stock(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'count_stock', Product::class);

        $request->validate([
            'date' => 'required',
            'warehouse_id' => 'required',
        ]);
        $this->abortIfWarehouseDenied($request->warehouse_id);

        $products = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_warehouse.product_variant_id')
            ->where('product_warehouse.deleted_at', '=', null)
            ->where('products.deleted_at', '=', null)
            ->where('product_warehouse.warehouse_id', '=', $request->warehouse_id)
            // Only what this warehouse actually holds: its own products plus
            // stock transferred in, never the empty rows of other branches.
            ->tap(fn ($q) => $this->scopeProductsToWarehouses($q, 'products'))
            ->tap(fn ($q) => $this->scopeStockRowsToOwnership($q))
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('products.category_id', $request->category_id);
            })
            ->select(
                'product_warehouse.product_id as productID',
                'products.name',
                'products.code',
                'categories.name as category_name',
                'product_warehouse.product_variant_id as productVariantID',
                'product_variants.name as variant_name',
                'product_variants.code as variant_code',
                'product_warehouse.qte'
            )
            ->orderBy('products.code')
            ->get();

        $stock = [];

        foreach ($products as $product) {
            // A variant row counts under the variant's own code.
            $item['code'] = $product->productVariantID ? ($product->variant_code ?: $product->code) : $product->code;
            $item['product_name'] = $product->productVariantID
                ? $product->variant_name.'-'.$product->name
                : $product->name;
            $item['category'] = $product->category_name;
            $item['quantity'] = $product->qte === 0.0 ? '0' : $product->qte;

            $stock[] = $item;
        }

        // Create an instance of StockExport with the warehouse name
        $stockExport = new StockExport($stock);

        // A stock count is a full inventory listing, so it is kept on the private
        // `local` disk (tenant-suffixed by config/tenancy.php) and handed out by
        // download_count_stock() behind auth, rather than written under public/
        // where the timestamped name was guessable by anyone.
        $excelFileName = 'stock_export_'.now()->format('YmdHis').'_'.Str::random(24).'.xlsx';

        Storage::disk('local')->put(
            self::COUNT_STOCK_DIR.'/'.$excelFileName,
            Excel::raw($stockExport, \Maatwebsite\Excel\Excel::XLSX)
        );

        // Save the file name in the count_stock table
        CountStock::create([
            'date' => $request->date,
            'warehouse_id' => $request->warehouse_id,
            'category_id' => $request->category_id,
            'user_id' => Auth::user()->id,
            'file_stock' => $excelFileName,
        ]);

        return response()->json(['success' => true]);

    }

    // -------------- download_count_stock ------------------\\

    /**
     * Stream a stored stock count back to the user.
     *
     * The row is warehouse-gated the same way every other document is, so a
     * user assigned to one branch cannot pull another branch's inventory.
     * Counts generated before these files moved off the public path are still
     * served from their old location so existing rows keep working.
     */
    public function download_count_stock(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'count_stock', Product::class);

        $count_stock = CountStock::findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($count_stock);

        // basename() keeps a tampered row from walking out of the directory.
        $fileName = basename((string) $count_stock->file_stock);
        if ($fileName === '') {
            abort(404);
        }

        $stored = self::COUNT_STOCK_DIR.'/'.$fileName;
        if (Storage::disk('local')->exists($stored)) {
            return Storage::disk('local')->download($stored, $fileName);
        }

        $legacy = upload_public_path('count_stock').'/'.$fileName;
        if (is_file($legacy)) {
            return response()->download($legacy, $fileName);
        }

        abort(404);
    }

    // -------------- upload_counted_stock ------------------\\

    /**
     * Upload a physical count: a file of Code + Counted for one warehouse.
     * Each difference is worked out against stock AT UPLOAD TIME (rows are
     * locked while applying), so sales between the count sheet and the upload
     * never skew it, and posted as ONE adjustment noted "Stock count".
     *
     * preview=1 returns the differences without changing anything. Rows with
     * a blank Counted cell are not counted and are left alone. Any problem row
     * rejects the whole file, as Opening Stock does.
     *
     * Serial / batch tracked, combo and service products are refused: their
     * stock moves through serials, batches or components, which a bare count
     * cannot name — they are counted on a manual adjustment.
     */
    public function upload_counted_stock(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'count_stock', Product::class);
        $this->authorizeForUser($request->user('api'), 'create', Adjustment::class);
        ini_set('max_execution_time', 2000); // whole-catalogue counts, like the other imports

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'file' => 'required|file|max:20480',
            'reference' => 'nullable|string|max:100',
        ]);
        $this->abortIfWarehouseDenied($request->warehouse_id);
        $warehouseId = (int) $request->warehouse_id;

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            return response()->json(['status' => false, 'errors' => ['The file must be an .xlsx, .xls or .csv file.']], 422);
        }

        // CSV is read natively: the spreadsheet reader takes ~10 s on a
        // 21,000-row catalogue count, fgetcsv a fraction of that. Headings are
        // normalized the way the Excel reader slugs them ("Counted Qty" → counted_qty).
        if ($extension === 'csv') {
            $rows = [];
            $handle = fopen($request->file('file')->getRealPath(), 'r');
            $headers = null;
            while (($line = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]); // BOM
                    $headers = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h)), '_')), $line);

                    continue;
                }
                $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), null));
            }
            fclose($handle);
        } else {
            $rows = Excel::toArray(new OpeningStockRowsImport, $request->file('file'))[0] ?? [];
        }

        $errors = [];
        $counts = [];
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $code = trim((string) ($row['code'] ?? $row['product_code'] ?? ''));
            $counted = $row['counted'] ?? $row['counted_qty'] ?? $row['counted_quantity'] ?? null;
            $counted = is_string($counted) ? trim($counted) : $counted;

            if ($code === '' && ($counted === null || $counted === '')) {
                continue; // blank line
            }
            if ($counted === null || $counted === '') {
                continue; // listed but not counted
            }
            if ($code === '') {
                $errors[] = "Row {$rowNum}: code is required.";

                continue;
            }
            if (! is_numeric($counted) || (float) $counted < 0) {
                $errors[] = "Row {$rowNum} ({$code}): counted must be a number of 0 or more.";

                continue;
            }
            if (isset($counts[$code])) {
                $errors[] = "Row {$rowNum}: code '{$code}' appears more than once.";

                continue;
            }
            $counts[$code] = ['row' => $rowNum, 'counted' => (float) $counted];
        }

        if (! $counts && ! $errors) {
            return response()->json(['status' => false, 'errors' => ['No counted quantities found. The file needs a Code and a Counted column.']], 422);
        }

        // Resolve codes: a product code, or a variant code for variant stock.
        $codes = array_keys($counts);
        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $productCols = ['id', 'code', 'name', 'type', 'is_variant', 'is_imei'];
        if ($hasBatch) {
            $productCols[] = 'is_batch_tracked';
        }
        $targets = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            foreach (Product::whereNull('deleted_at')->whereIn('code', $chunk)->get($productCols) as $p) {
                $targets[$p->code] = ['product' => $p, 'variant_id' => null, 'name' => $p->name];
            }
            $variants = ProductVariant::whereNull('deleted_at')->whereIn('code', $chunk)->get(['id', 'product_id', 'code', 'name']);
            $parents = Product::whereNull('deleted_at')->whereIn('id', $variants->pluck('product_id'))->get($productCols)->keyBy('id');
            foreach ($variants as $v) {
                if (! isset($targets[$v->code]) && $parents->has($v->product_id)) {
                    $parent = $parents[$v->product_id];
                    $targets[$v->code] = ['product' => $parent, 'variant_id' => (int) $v->id, 'name' => $parent->name.' ['.$v->name.']'];
                }
            }
        }

        foreach ($counts as $code => $c) {
            $target = $targets[$code] ?? null;
            $p = $target['product'] ?? null;
            if (! $p) {
                $errors[] = "Row {$c['row']}: code '{$code}' does not exist.";
            } elseif ($target['variant_id'] === null && ($p->type === 'is_variant' || $p->is_variant)) {
                $errors[] = "Row {$c['row']} ({$code}): this product has variants — count each variant under its own code.";
            } elseif ($p->type === 'is_service' || $p->type === 'is_combo') {
                $errors[] = "Row {$c['row']} ({$code}): ".($p->type === 'is_service' ? 'services have no stock.' : 'combos are counted through their components.');
            } elseif ($p->is_imei || ($hasBatch && $p->is_batch_tracked)) {
                $errors[] = "Row {$c['row']} ({$code}): serial / batch tracked — count it on a manual adjustment.";
            }
        }

        if ($errors) {
            return response()->json(['status' => false, 'errors' => $errors], 422);
        }

        // Current stock → differences. Applying re-reads under lock, so the
        // difference is the one at the moment of posting, not of the preview.
        // Stock rows load in chunks (one query per 1,000 products, not one per
        // row): a whole-catalogue count is tens of thousands of rows.
        $compute = function (bool $lock) use ($counts, $targets, $warehouseId) {
            $productIds = array_values(array_unique(array_map(fn ($t) => (int) $t['product']->id, array_intersect_key($targets, $counts))));
            $rows = [];
            foreach (array_chunk($productIds, 1000) as $chunk) {
                $found = product_warehouse::whereNull('deleted_at')
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('product_id', $chunk)
                    ->orderBy('id')
                    ->when($lock, fn ($q) => $q->lockForUpdate())
                    ->get();
                foreach ($found as $row) {
                    $rows[$row->product_id.'|'.($row->product_variant_id ?? '')] ??= $row;
                }
            }

            $lines = [];
            foreach ($counts as $code => $c) {
                $t = $targets[$code];
                $pw = $rows[$t['product']->id.'|'.($t['variant_id'] ?? '')] ?? null;
                $current = $pw ? (float) $pw->qte : 0.0;
                $lines[] = [
                    'code' => $code,
                    'name' => $t['name'],
                    'product_id' => (int) $t['product']->id,
                    'product_variant_id' => $t['variant_id'],
                    'current' => $current,
                    'counted' => $c['counted'],
                    'difference' => round($c['counted'] - $current, 4),
                    'pw' => $pw,
                ];
            }

            return $lines;
        };

        $summary = function (array $lines) {
            $changed = array_values(array_filter($lines, fn ($l) => $l['difference'] != 0));

            return [
                'counted' => count($lines),
                'changed' => count($changed),
                'units_added' => array_sum(array_map(fn ($l) => max($l['difference'], 0), $changed)),
                'units_removed' => array_sum(array_map(fn ($l) => max(-$l['difference'], 0), $changed)),
                'lines' => array_map(fn ($l) => array_diff_key($l, ['pw' => 1, 'product_id' => 1, 'product_variant_id' => 1]), $changed),
            ];
        };

        if ($request->boolean('preview')) {
            return response()->json(['status' => true, 'preview' => true] + $summary($compute(false)));
        }

        $result = DB::transaction(function () use ($compute, $summary, $warehouseId, $request) {
            $lines = $compute(true);
            $changed = array_filter($lines, fn ($l) => $l['difference'] != 0);
            $adjustment = null;

            if ($changed) {
                $reference = trim((string) $request->input('reference', ''));
                $adjustment = Adjustment::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'Ref' => app('App\Http\Controllers\AdjustmentController')->getNumberOrder(),
                    'warehouse_id' => $warehouseId,
                    'items' => count($changed),
                    'notes' => 'Stock count'.($reference !== '' ? ' — '.$reference : ''),
                    'user_id' => Auth::id(),
                ]);

                $detailRows = [];
                foreach ($changed as $l) {
                    // Saved one by one on purpose: the stock observer pushes
                    // changed rows to Shopify / WooCommerce.
                    $pw = $l['pw'] ?: new product_warehouse([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $l['product_id'],
                        'product_variant_id' => $l['product_variant_id'],
                        'manage_stock' => 1,
                        'qte' => 0,
                    ]);
                    $pw->qte = (float) $pw->qte + $l['difference'];
                    $pw->save();

                    $detailRows[] = [
                        'adjustment_id' => $adjustment->id,
                        'product_id' => $l['product_id'],
                        'product_variant_id' => $l['product_variant_id'],
                        'quantity' => abs($l['difference']),
                        'type' => $l['difference'] > 0 ? 'add' : 'sub',
                        // insert() skips Eloquent: set what create() would.
                        'created_at' => $adjustment->created_at,
                        'updated_at' => $adjustment->created_at,
                    ];
                }
                foreach (array_chunk($detailRows, 1000) as $chunk) {
                    AdjustmentDetail::insert($chunk);
                }

                \App\Services\AccountingV2\StockJournal::adjustment($adjustment, created: true);
            }

            return $summary($lines) + [
                'adjustment_id' => $adjustment?->id,
                'Ref' => $adjustment?->Ref,
            ];
        });

        return response()->json(['status' => true, 'preview' => false] + $result);
    }

    // -------------- search_products_basic ------------------\\

    /** Typeahead for product pickers: id, name and code of matching active products. */
    public function search_products_basic(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $q = trim((string) $request->input('q', ''));
        $exclude = (int) $request->input('exclude', 0);

        $products = Product::whereNull('deleted_at')
            ->where('is_active', 1)
            ->when($exclude > 0, fn ($qq) => $qq->where('id', '!=', $exclude))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'code']);

        return response()->json(['products' => $products]);
    }

    // -------------- get_products_materiels ------------------\\

    public function get_products_materiels(request $request)
    {

        $products = Product::where('products.deleted_at', '=', null)
            ->where('products.type', 'is_single')
            ->where('products.is_active', 1)
            ->join('units', 'products.unit_sale_id', '=', 'units.id')
            ->select('products.id as product_id', 'products.name', 'products.cost', 'products.code', 'units.ShortName as unit_name')
            ->get();

        return response()->json($products);
    }

    // GET /opening-stock/import/meta
    public function opening_stock_meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'opening_stock_import', Product::class);

        // warehouses for user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json(['warehouses' => $warehouses]);
    }

    // POST /opening-stock/import/single
    /**
     * Why a product cannot take opening stock from an import, or null.
     * AdjustmentController::destroy reverses plain rows only — a combo line
     * is undone as a kit assembly (components credited back) and variant
     * parents / services not at all — so those would make "delete the log"
     * move the wrong stock. Serial / batch stock needs its serials or batches.
     */
    protected function openingStockTypeError(Product $p, bool $variantSheet): ?string
    {
        if (! $variantSheet && ($p->type === 'is_variant' || $p->is_variant)) {
            return 'this product has variants — use the variants sheet with a variant_code.';
        }
        if ($variantSheet && $p->type !== 'is_variant') {
            return 'not a product with variants — use the standard sheet.';
        }
        if ($p->type === 'is_service') {
            return 'services have no stock.';
        }
        if ($p->type === 'is_combo') {
            return 'combos are stocked through their components.';
        }
        if ($p->is_imei || (Schema::hasColumn('products', 'is_batch_tracked') && $p->is_batch_tracked)) {
            return 'serial / batch tracked — enter its opening stock with serials or batches.';
        }

        return null;
    }

    public function opening_stock_import_single(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'opening_stock_import', Product::class);

        $v = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'products' => 'required|mimes:xls,xlsx',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $v->errors()->all(),
            ], 422);
        }

        $rows = Excel::toArray(new OpeningStockRowsImport, $request->file('products'))[0] ?? [];
        if (empty($rows)) {
            return response()->json(['status' => false, 'message' => 'The imported file is empty.', 'errors' => ['The imported file is empty.']]);
        }

        // Normalize keys expected: product_code, qty
        $errors = [];
        $clean = [];
        $seenCodes = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // heading row = 1
            $code = trim((string) ($row['product_code'] ?? ''));
            $qty = $row['qty'] ?? null;

            if ($code === '') {
                $errors[] = "Row {$rowNum}: product_code is required.";
            }
            if ($qty === null || ! is_numeric($qty)) {
                $errors[] = "Row {$rowNum}: qty must be a number.";
            }

            if ($code !== '') {
                if (isset($seenCodes[$code])) {
                    $errors[] = "Row {$rowNum}: duplicate product_code '{$code}' in file.";
                } else {
                    $seenCodes[$code] = true;
                }
            }

            $clean[] = ['row' => $rowNum, 'code' => $code, 'qty' => (float) $qty];
        }

        $this->abortIfWarehouseDenied($request->warehouse_id);

        // Validate existence of products
        $codes = array_values(array_unique(array_column($clean, 'code')));
        $existing = Product::whereNull('deleted_at')->whereIn('code', $codes)->pluck('id', 'code')->toArray();
        foreach ($codes as $code) {
            if ($code !== '' && ! isset($existing[$code])) {
                // Provide detailed, row-by-row errors:
                foreach ($clean as $c) {
                    if ($c['code'] === $code) {
                        $errors[] = "Row {$c['row']}: product_code '{$code}' does not exist.";
                    }
                }
            }
        }

        // Only products whose stock is a plain quantity on their own row: the
        // log's delete reverses exactly those (see openingStockTypeError).
        $typed = Product::whereNull('deleted_at')->whereIn('code', array_keys($existing))->get()->keyBy('code');
        foreach ($clean as $c) {
            if (isset($typed[$c['code']]) && ($why = $this->openingStockTypeError($typed[$c['code']], false))) {
                $errors[] = "Row {$c['row']} ({$c['code']}): {$why}";
            }
        }

        if (! empty($errors)) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $errors]);
        }

        // The same rows already posted (and still standing) → ask before doubling.
        $fingerprint = ImportFingerprint::hash('opening_stock', (int) $request->warehouse_id, array_map(fn ($c) => [$c['code'], $c['qty']], $clean));
        if (! $request->boolean('confirm_duplicate') && ($previous = ImportFingerprint::previous('opening_stock', $fingerprint))) {
            return ImportFingerprint::duplicateResponse($previous);
        }

        // Apply stock + record virtual adjustments (similar to product create "opening stock")
        DB::transaction(function () use ($clean, $existing, $request, $fingerprint) {
            $warehouseId = (int) $request->warehouse_id;

            // 1) Apply stock to product_warehouse
            foreach ($clean as $c) {
                $productId = $existing[$c['code']];
                $pw = product_warehouse::lockForUpdate()->firstOrNew([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_variant_id' => null,
                ]);
                if (! $pw->exists) {
                    $pw->manage_stock = 1;
                    $pw->qte = 0;
                }
                $pw->qte = (float) $pw->qte + (float) $c['qty'];
                $pw->save();
            }

            // 2) Create a lightweight Adjustment with one detail per product
            //    so average‑cost/COGS logic can treat this as opening stock.
            //    Negative rows are logged too (as 'sub' lines): every quantity
            //    this upload moved must be on its log, or deleting the log
            //    cannot undo exactly this upload.
            $detailsByProduct = [];
            foreach ($clean as $c) {
                $qty = (float) $c['qty'];
                if ($qty == 0) {
                    continue;
                }

                $productId = $existing[$c['code']];
                if (! isset($detailsByProduct[$productId])) {
                    $detailsByProduct[$productId] = 0.0;
                }
                $detailsByProduct[$productId] += $qty;
            }
            $detailsByProduct = array_filter($detailsByProduct, fn ($qty) => $qty != 0);

            if (! empty($detailsByProduct)) {
                $adjRef = app('App\Http\Controllers\AdjustmentController')->getNumberOrder();

                $adjustment = Adjustment::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'Ref' => $adjRef,
                    'warehouse_id' => $warehouseId,
                    'items' => count($detailsByProduct),
                    'notes' => 'Opening stock import (auto)',
                    'user_id' => Auth::id(),
                ]);

                foreach ($detailsByProduct as $productId => $qty) {
                    AdjustmentDetail::create([
                        'adjustment_id' => $adjustment->id,
                        'product_id' => $productId,
                        'product_variant_id' => null,
                        'quantity' => abs($qty),
                        'type' => $qty > 0 ? 'add' : 'sub',
                    ]);
                }

                ImportFingerprint::record('opening_stock', $fingerprint, $warehouseId, 'adjustment', $adjustment->id, $request->file('products')->getClientOriginalName());
                \App\Services\AccountingV2\StockJournal::adjustment($adjustment, created: true, opening: true);
            }
        });

        return response()->json(['status' => true]);
    }

    // POST /opening-stock/import/variants
    public function opening_stock_import_variants(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'opening_stock_import', Product::class);

        $v = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'products' => 'required|mimes:xls,xlsx',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $v->errors()->all(),
            ], 422);
        }

        $rows = Excel::toArray(new OpeningStockRowsImport, $request->file('products'))[0] ?? [];
        if (empty($rows)) {
            return response()->json(['status' => false, 'message' => 'The imported file is empty.', 'errors' => ['The imported file is empty.']]);
        }

        // Expected keys: product_code, variant_code, qty
        $errors = [];
        $clean = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $pcode = trim((string) ($row['product_code'] ?? ''));
            $vcode = trim((string) ($row['variant_code'] ?? ''));
            $qty = $row['qty'] ?? null;

            if ($pcode === '') {
                $errors[] = "Row {$rowNum}: product_code is required.";
            }
            if ($vcode === '') {
                $errors[] = "Row {$rowNum}: variant_code is required.";
            }
            if ($qty === null || ! is_numeric($qty)) {
                $errors[] = "Row {$rowNum}: qty must be a number.";
            }

            $clean[] = ['row' => $rowNum, 'pcode' => $pcode, 'vcode' => $vcode, 'qty' => (float) $qty];
        }

        // Validate existence + relationships
        $productCodes = array_values(array_unique(array_column($clean, 'pcode')));
        $products = Product::whereNull('deleted_at')->whereIn('code', $productCodes)->pluck('id', 'code')->toArray();

        // variant lookup
        $variantCodes = array_values(array_unique(array_column($clean, 'vcode')));
        $variants = ProductVariant::whereNull('deleted_at')
            ->whereIn('code', $variantCodes)
            ->get(['id', 'product_id', 'code'])
            ->keyBy('code');

        foreach ($clean as $c) {
            if ($c['pcode'] !== '' && ! isset($products[$c['pcode']])) {
                $errors[] = "Row {$c['row']}: product_code '{$c['pcode']}' does not exist.";

                continue;
            }
            if ($c['vcode'] !== '' && ! $variants->has($c['vcode'])) {
                $errors[] = "Row {$c['row']}: variant_code '{$c['vcode']}' does not exist.";

                continue;
            }
            // match product-variant
            if ($c['pcode'] !== '' && $c['vcode'] !== '' && $variants->has($c['vcode'])) {
                $pid = $products[$c['pcode']];
                $vProd = $variants->get($c['vcode'])->product_id;
                if ((int) $pid !== (int) $vProd) {
                    $errors[] = "Row {$c['row']}: variant_code '{$c['vcode']}' does not belong to product_code '{$c['pcode']}'.";
                }
            }
        }

        $this->abortIfWarehouseDenied($request->warehouse_id);
        // Serial / batch tracked variants move through serials or batches.
        $typed = Product::whereNull('deleted_at')->whereIn('code', array_keys($products))->get()->keyBy('code');
        foreach ($clean as $c) {
            if (isset($typed[$c['pcode']]) && ($why = $this->openingStockTypeError($typed[$c['pcode']], true))) {
                $errors[] = "Row {$c['row']} ({$c['pcode']}): {$why}";
            }
        }

        if (! empty($errors)) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $errors]);
        }

        // The same rows already posted (and still standing) → ask before doubling.
        $fingerprint = ImportFingerprint::hash('opening_stock_variants', (int) $request->warehouse_id, array_map(fn ($c) => [$c['pcode'], $c['vcode'], $c['qty']], $clean));
        if (! $request->boolean('confirm_duplicate') && ($previous = ImportFingerprint::previous('opening_stock_variants', $fingerprint))) {
            return ImportFingerprint::duplicateResponse($previous);
        }

        // Apply stock + record virtual adjustments (similar to product create "opening stock")
        DB::transaction(function () use ($clean, $products, $variants, $request, $fingerprint) {
            $warehouseId = (int) $request->warehouse_id;
            $detailRows = [];

            // 1) Apply stock to product_warehouse
            foreach ($clean as $c) {
                $productId = $products[$c['pcode']];
                $variantId = $variants->get($c['vcode'])->id;

                $pw = product_warehouse::lockForUpdate()->firstOrNew([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                ]);
                if (! $pw->exists) {
                    $pw->manage_stock = 1;
                    $pw->qte = 0;
                }
                $pw->qte = (float) $pw->qte + (float) $c['qty'];
                $pw->save();

                // Negative rows are logged as 'sub' lines so the log can undo
                // exactly what this upload moved.
                $qty = (float) $c['qty'];
                if ($qty != 0) {
                    $detailRows[] = [
                        'product_id' => $productId,
                        'product_variant_id' => $variantId,
                        'quantity' => abs($qty),
                        'type' => $qty > 0 ? 'add' : 'sub',
                    ];
                }
            }

            // 2) Single Adjustment header per import+warehouse, with one detail per variant row
            if (! empty($detailRows)) {
                $adjRef = app('App\Http\Controllers\AdjustmentController')->getNumberOrder();

                $adjustment = Adjustment::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'Ref' => $adjRef,
                    'warehouse_id' => $warehouseId,
                    'items' => count($detailRows),
                    'notes' => 'Opening stock import (variants, auto)',
                    'user_id' => Auth::id(),
                ]);

                foreach ($detailRows as $row) {
                    AdjustmentDetail::create([
                        'adjustment_id' => $adjustment->id,
                        'product_id' => $row['product_id'],
                        'product_variant_id' => $row['product_variant_id'],
                        'quantity' => $row['quantity'],
                        'type' => $row['type'],
                    ]);
                }

                ImportFingerprint::record('opening_stock_variants', $fingerprint, $warehouseId, 'adjustment', $adjustment->id, $request->file('products')->getClientOriginalName());
                \App\Services\AccountingV2\StockJournal::adjustment($adjustment, created: true, opening: true);
            }
        });

        return response()->json(['status' => true]);
    }

    public function cleanNames()
    {
        $count = 0;

        \App\Models\Product::where('name', 'REGEXP', '<|>')
            ->chunkById(200, function ($products) use (&$count) {
                foreach ($products as $p) {
                    $old = $p->name;
                    $p->name = str_replace(['<', '>'], ['‹', '›'], $old);
                    $p->save();
                    $count++;
                }
            });

        return response()->json([
            'success' => true,
            'message' => "Cleaned {$count} products containing < or >.",
        ]);
    }

    // -------------- Duplicate Product  ---------------\\
    // -----------------------------------------------\\
    public function duplicate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        $newProductId = null;

        DB::transaction(function () use ($id, &$newProductId) {
            $original = Product::whereNull('deleted_at')
                ->with(['variants' => function ($q) {
                    $q->whereNull('deleted_at');
                }, 'images'])
                ->findOrFail($id);
            $this->abortIfProductDenied($original);

            $newCode = $this->generateUniqueCode((string) $original->Type_barcode);

            $newImage = 'no-image.png';
            $oldImage = $original->image;
            if (! empty($oldImage) && $oldImage !== 'no-image.png') {
                $srcPath = upload_public_path('products').'/'.$oldImage;
                if (file_exists($srcPath)) {
                    $ext = strtolower(pathinfo($oldImage, PATHINFO_EXTENSION));
                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $ext = 'jpg';
                    }
                    $newImage = rand(11111111, 99999999).'.'.$ext;
                    $dstPath = upload_public_path('products').'/'.$newImage;
                    @copy($srcPath, $dstPath);
                }
            }

            $copy = $original->replicate();
            $copy->code = $newCode;
            $copy->image = $newImage;
            // The copy is a new product, not the original's WooCommerce listing.
            $copy->woocommerce_id = null;
            // A scale PLU names one product; the copy gets its own later.
            if (array_key_exists('scale_plu', $copy->getAttributes())) {
                $copy->scale_plu = null;
            }
            // The copy stays in the original's home warehouse when the caller
            // may access it; otherwise it goes to the caller's own warehouse.
            $copy->warehouse_id = $this->canAccessWarehouse($original->warehouse_id)
                ? $original->warehouse_id
                : $this->productHomeWarehouseFor(null, false);
            $copy->created_at = now();
            $copy->updated_at = now();
            $copy->save();

            $newProductId = $copy->id;

            $original->loadMissing(['categories', 'subcategories']);
            if (Schema::hasTable('category_product')) {
                if ($original->categories->isNotEmpty()) {
                    $copy->categories()->sync($original->categories->pluck('id')->all());
                } elseif ($copy->category_id) {
                    $copy->categories()->sync([(int) $copy->category_id]);
                }
            }
            if (Schema::hasTable('product_subcategory')) {
                if ($original->subcategories->isNotEmpty()) {
                    $copy->subcategories()->sync($original->subcategories->pluck('id')->all());
                } elseif ($copy->sub_category_id) {
                    $copy->subcategories()->sync([(int) $copy->sub_category_id]);
                }
            }

            ProductGalleryService::duplicateImages($original, $copy);
            if (Schema::hasTable('product_images') && $copy->images()->count() === 0 && ! empty($newImage) && $newImage !== 'no-image.png') {
                ProductImage::create([
                    'product_id' => $copy->id,
                    'image_path' => $newImage,
                    'is_main' => true,
                    'sort_order' => 0,
                ]);
            }

            $variantIdMap = [];
            if ($original->is_variant) {
                foreach ($original->variants as $variant) {
                    $newVariant = new ProductVariant;
                    $newVariant->product_id = $copy->id;
                    $newVariant->name = $variant->name;
                    $newVariant->cost = $variant->cost;
                    $newVariant->price = $variant->price;
                    $baseName = trim((string) $variant->name);
                    if ($baseName === '') {
                        $baseName = 'VAR';
                    }
                    $candidate = substr($baseName, 0, 50).'-'.$copy->code;
                    $newVariant->code = $this->ensureUniqueCode($candidate);
                    $newVariant->qty = 0;
                    $newVariant->image = $variant->image ?? 'no-image.png';
                    $newVariant->save();

                    $variantIdMap[$variant->id] = $newVariant->id;
                }

                app(ProductOptionService::class)->cloneTo($original, $copy, $variantIdMap);
            }

            if ($original->type === 'is_combo') {
                $components = CombinedProduct::where('product_id', $original->id)->get(['combined_product_id', 'quantity']);
                if ($components->isNotEmpty()) {
                    $syncData = [];
                    foreach ($components as $c) {
                        $syncData[$c->combined_product_id] = ['quantity' => $c->quantity];
                    }
                    $copy->combinedProducts()->sync($syncData);
                }
            }

            $pws = product_warehouse::where('product_id', $original->id)
                ->whereNull('deleted_at')
                ->get(['warehouse_id', 'product_variant_id', 'manage_stock']);

            if ($pws->isNotEmpty()) {
                $rows = [];
                foreach ($pws as $pw) {
                    $rows[] = [
                        'product_id' => $copy->id,
                        'warehouse_id' => $pw->warehouse_id,
                        'product_variant_id' => $pw->product_variant_id ? ($variantIdMap[$pw->product_variant_id] ?? null) : null,
                        'manage_stock' => (int) ($pw->manage_stock ?? 1),
                        'qte' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (! empty($rows)) {
                    product_warehouse::insert($rows);
                }
            }
        }, 10);

        WooAutoSync::productSaved($newProductId);
        OpencartAutoSync::productSaved($newProductId);

        return response()->json(['success' => true, 'id' => $newProductId]);
    }

    protected function ensureUniqueCode(string $candidate): string
    {
        $code = substr($candidate, 0, 192);
        if (! $this->codeExistsAcrossTables($code)) {
            return $code;
        }

        for ($i = 2; $i <= 10; $i++) {
            $try = substr($candidate, 0, max(1, 192 - (strlen((string) $i) + 1))).'-'.$i;
            if (! $this->codeExistsAcrossTables($try)) {
                return $try;
            }
        }

        do {
            $try = (string) rand(10000000, 99999999);
        } while ($this->codeExistsAcrossTables($try));

        return $try;
    }

    protected function generateUniqueCode(string $symbology = 'CODE128'): string
    {
        // Prefix + number when System Settings > Prefixes sets a numbering and
        // the symbology takes free text; EAN / UPC keep the digit code below.
        $numbered = \App\Services\ProductCodeGenerator::next($symbology ?: 'CODE128');
        if ($numbered !== null) {
            return $numbered;
        }

        do {
            $code = (string) rand(10000000, 99999999);
        } while ($this->codeExistsAcrossTables($code));

        return $code;
    }

    protected function codeExistsAcrossTables(string $code): bool
    {
        $inProducts = Product::whereNull('deleted_at')->where('code', $code)->exists();
        if ($inProducts) {
            return true;
        }
        $inVariants = ProductVariant::whereNull('deleted_at')->where('code', $code)->exists();

        return $inVariants || ProductBarcodes::owner($code) !== null;
    }

    /**
     * Product form rules for additional barcodes: the product code may not be
     * another product's extra barcode, and each submitted barcode must be free
     * (see ProductBarcodes::conflicts). $productId is null on create.
     */
    protected function addBarcodeRules(array &$rules, Request $request, ?int $productId): void
    {
        $rules['code'][] = function ($attribute, $value, $fail) use ($productId) {
            $owner = ProductBarcodes::owner(trim((string) $value));
            if ($owner && $owner[0] !== $productId) {
                $fail('This code is already an additional barcode of another product.');
            }
        };

        // Variant codes too (create posts them as JSON, edit as an array).
        if (isset($rules['variants'])) {
            $rules['variants'] = (array) $rules['variants'];
            $rules['variants'][] = function ($attribute, $value, $fail) use ($productId) {
                $variants = is_string($value) ? (array) json_decode($value, true) : (array) $value;
                foreach ($variants as $variant) {
                    $code = trim((string) (is_array($variant) ? ($variant['code'] ?? '') : ''));
                    $owner = $code !== '' ? ProductBarcodes::owner($code) : null;
                    if ($owner && $owner[0] !== $productId) {
                        $fail("Variant code \"{$code}\" is already an additional barcode of another product.");

                        return;
                    }
                }
            };
        }

        if ($request->has('barcodes')) {
            $rules['barcodes'] = [function ($attribute, $value, $fail) use ($productId, $request) {
                $barcodes = ProductBarcodes::normalize($value);
                if (count($barcodes) > 50) {
                    $fail('A product can have at most 50 additional barcodes.');

                    return;
                }
                foreach (ProductBarcodes::conflicts($barcodes, $productId, null, trim((string) $request->code)) as $message) {
                    $fail($message);
                }
            }];
        }
    }

    /**
     * @return array<int, int>
     */
    protected function normalizeMultiCategoryIds($raw): array
    {
        return $this->normalizeOrderedMultiIds($raw);
    }

    /**
     * Decode request input to a flat int list, preserving order and first occurrence of each id.
     *
     * @return array<int, int>
     */
    protected function normalizeOrderedMultiIds($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }
        if (! is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $v) {
            if ($v === null || $v === '' || $v === 'null') {
                continue;
            }
            $i = (int) $v;
            if ($i <= 0 || isset($seen[$i])) {
                continue;
            }
            $seen[$i] = true;
            $out[] = $i;
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @return array<int, int>
     */
    protected function filterCategoryIdsPreservingOrder(array $orderedIds): array
    {
        if ($orderedIds === []) {
            return [];
        }
        $allowed = Category::whereNull('deleted_at')
            ->whereIn('id', $orderedIds)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->flip()
            ->all();
        $out = [];
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($allowed[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @return array<int, int>
     */
    protected function filterSubcategoryIdsPreservingOrder(array $orderedIds): array
    {
        if ($orderedIds === []) {
            return [];
        }
        $allowed = SubCategory::whereIn('id', $orderedIds)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->flip()
            ->all();
        $out = [];
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($allowed[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Dual-write: pivot tables plus primary category/subcategory columns (first selected wins).
     */
    protected function syncProductMultiCategories(Request $request, Product $product): void
    {
        $categoryDirty = false;
        $subDirty = false;

        if (Schema::hasTable('category_product') && $request->has('multi_category_ids')) {
            $ids = $this->normalizeOrderedMultiIds($request->input('multi_category_ids'));
            if ($ids !== []) {
                $valid = $this->filterCategoryIdsPreservingOrder($ids);
                if ($valid !== []) {
                    $product->category_id = $valid[0];
                    $categoryDirty = true;
                    $product->categories()->sync($valid);
                }
            }
        }

        if (Schema::hasTable('product_subcategory') && $request->has('multi_subcategory_ids')) {
            $subIds = $this->normalizeOrderedMultiIds($request->input('multi_subcategory_ids'));
            if ($subIds !== []) {
                $validSub = $this->filterSubcategoryIdsPreservingOrder($subIds);
                $product->sub_category_id = $validSub !== [] ? $validSub[0] : null;
                $subDirty = true;
                $product->subcategories()->sync($validSub);
            } else {
                $product->sub_category_id = null;
                $subDirty = true;
                $product->subcategories()->detach();
            }
        }

        if ($categoryDirty || $subDirty) {
            $product->saveQuietly();
        }
    }

    /**
     * Multi-Pack Selling: reconcile the product's selling packs from the request.
     *
     * Expects a `packs` field (JSON array or array) of pack rows shaped like
     * { id?, name, multiplier, price, is_active, is_default }. Exactly one
     * default pack (multiplier 1, always active) is guaranteed. Packs are
     * matched by id: existing rows are updated, new rows inserted, removed rows
     * soft-deleted. Packs only apply to single products.
     */
    protected function syncProductPacks(Request $request, Product $product): void
    {
        $setting = Setting::whereNull('deleted_at')->first();
        if (! (bool) ($setting->enable_multi_pack_selling ?? false)) {
            return;
        }

        if ($product->type !== 'is_single' || ! $request->has('packs')) {
            return;
        }

        $raw = $request->input('packs');
        $packs = is_array($raw) ? $raw : json_decode($raw, true);
        if (! is_array($packs)) {
            $packs = [];
        }

        // Sanitize incoming rows; drop anything invalid.
        $clean = [];
        $hasDefault = false;
        foreach ($packs as $p) {
            $name = isset($p['name']) ? trim((string) $p['name']) : '';
            $multiplier = isset($p['multiplier']) ? (float) $p['multiplier'] : 0;
            $price = isset($p['price']) ? (float) $p['price'] : 0;
            $isDefault = ! empty($p['is_default']);
            $isActive = $isDefault ? true : ! empty($p['is_active']);

            if ($isDefault) {
                if ($hasDefault) {
                    continue; // only the first default wins
                }
                // Force the canonical default: multiplier 1, active, named.
                $multiplier = 1.0;
                $name = $name !== '' ? $name : 'Default';
                // Default pack sells in the base unit; fall back to the product
                // price when no explicit price was set so it never sells at 0.
                if ($price <= 0) {
                    $price = (float) $product->price;
                }
                $hasDefault = true;
            } elseif ($name === '' || $multiplier <= 0 || $price < 0) {
                continue;
            }

            $clean[] = [
                'id' => ! empty($p['id']) ? (int) $p['id'] : null,
                'name' => $name,
                'multiplier' => $multiplier,
                'price' => max(0, $price),
                'is_active' => $isActive,
                'is_default' => $isDefault,
            ];
        }

        // Guarantee a default pack exists.
        if (! $hasDefault) {
            array_unshift($clean, [
                'id' => null,
                'name' => 'Default',
                'multiplier' => 1.0,
                'price' => max(0, (float) $product->price),
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        // Reconcile against existing rows by id.
        $existing = ProductPack::where('product_id', $product->id)->whereNull('deleted_at')->get()->keyBy('id');
        $keptIds = [];

        foreach ($clean as $row) {
            if ($row['id'] && $existing->has($row['id'])) {
                $pack = $existing->get($row['id']);
            } else {
                $pack = new ProductPack;
                $pack->product_id = $product->id;
            }
            $pack->name = $row['name'];
            $pack->multiplier = $row['multiplier'];
            $pack->price = $row['price'];
            $pack->is_active = $row['is_active'];
            $pack->is_default = $row['is_default'];
            $pack->save();
            $keptIds[] = $pack->id;
        }

        // Soft-delete rows that were removed in the UI.
        ProductPack::where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $keptIds ?: [0])
            ->delete();
    }
}
