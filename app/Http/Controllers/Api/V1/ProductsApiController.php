<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SubCategory;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\ProductBarcodes;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * GET  /api/v1/products              list (filters: code, search, updated_since)
 * GET  /api/v1/products/{code}       one product (code, variant code or barcode)
 * PUT  /api/v1/products/{code}       create or update by code (upsert)
 *
 * Upsert: an unknown code creates a standard product (name, category, unit,
 * cost and price required) with a stock row in every warehouse, as the
 * product form does; a known product code updates only the fields sent; a
 * variant code updates that variant's cost / prices.
 */
class ProductsApiController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $request->validate([
            'updated_since' => 'nullable|date',
            'code' => 'nullable|string|max:191',
            'search' => 'nullable|string|max:191',
        ]);

        $query = Product::with('category', 'subCategory', 'brand', 'unit', 'variants')
            ->whereNull('deleted_at')
            ->when($request->filled('code'), fn ($q) => $q->where('code', $request->query('code')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'LIKE', '%'.$request->query('search').'%')
                ->orWhere('code', 'LIKE', '%'.$request->query('search').'%')))
            ->when($request->filled('updated_since'), fn ($q) => $q->where('updated_at', '>=', \Carbon\Carbon::parse($request->query('updated_since'))))
            ->tap(fn ($q) => $this->scopeProductsToWarehouses($q))
            ->orderBy('id');

        $page = $this->paginated($query, $request, fn ($p) => $p, 100, 500);
        $barcodes = ProductBarcodes::map(array_map(fn ($p) => $p->id, $page['data']));
        $page['data'] = array_map(fn ($p) => $this->resource($p, $barcodes), $page['data']);

        return response()->json($page);
    }

    public function show(Request $request, string $code)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        [$product] = $this->resolveCode($code);
        if (! $product || ! $this->canAccessProduct($product)) {
            return $this->error('not_found', "No product with code \"{$code}\".", 404);
        }

        return response()->json(['data' => $this->resource($product->load('category', 'subCategory', 'brand', 'unit', 'variants'))]);
    }

    public function upsert(Request $request, string $code)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:192|regex:/^[^<>]*$/',
            'category' => 'nullable|string|max:192',
            'sub_category' => 'nullable|string|max:192',
            'brand' => 'nullable|string|max:192',
            'unit' => 'nullable|string|max:192',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'min_price' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'tax_method' => ['nullable', Rule::in(['exclusive', 'inclusive'])],
            'is_active' => 'nullable|boolean',
            'stock_alert' => 'nullable|numeric|min:0',
            'barcodes' => 'nullable|array|max:50',
            'barcodes.*' => 'string|max:64',
        ]);
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 192) {
            return $this->error('invalid_code', 'The product code must be 1–192 characters.', 422);
        }

        [$product, $variant] = $this->resolveCode($code);

        // A variant code: only its own prices move. Its product must be live
        // and visible to the key's user, like any other write.
        if ($variant) {
            $this->authorizeForUser($request->user('api'), 'update', Product::class);
            if (! $product || ! $this->canAccessProduct($product)) {
                return $this->error('not_found', "No product with code \"{$code}\".", 404);
            }
            $extra = array_diff(array_keys(array_filter($data, fn ($v) => $v !== null)), ['cost', 'price', 'wholesale_price', 'min_price']);
            if ($extra) {
                return $this->error('variant_fields', 'A variant code can only update cost, price, wholesale_price and min_price. Not: '.implode(', ', $extra).'.', 422);
            }
            $variant->fill(array_filter([
                'cost' => $data['cost'] ?? null,
                'price' => $data['price'] ?? null,
                'wholesale' => $data['wholesale_price'] ?? null,
                'min_price' => $data['min_price'] ?? null,
            ], fn ($v) => $v !== null))->save();

            return response()->json(['data' => $this->resource($product->fresh()->load('category', 'subCategory', 'brand', 'unit', 'variants')), 'updated' => 'variant']);
        }

        // An extra barcode is not a product's code: refuse to write through it.
        if ($product && $product->code !== $code) {
            return $this->error('code_is_barcode', "\"{$code}\" is an additional barcode of product \"{$product->code}\"; use the product code.", 422);
        }

        $creating = ! $product;
        $this->authorizeForUser($request->user('api'), $creating ? 'create' : 'update', Product::class);
        if (! $creating && ! $this->canAccessProduct($product)) {
            return $this->error('not_found', "No product with code \"{$code}\".", 404);
        }

        if ($creating) {
            $missing = array_values(array_filter(['name', 'category', 'unit', 'cost', 'price'], fn ($f) => ! isset($data[$f]) || $data[$f] === ''));
            if ($missing) {
                return $this->error('missing_fields', 'A new product needs: '.implode(', ', $missing).'.', 422, ['fields' => $missing]);
            }
            if (app(TenantLimitsService::class)->hasReachedLimit('max_products')) {
                return $this->error('limit_reached', 'Your plan\'s product limit is reached.', 403);
            }
        }

        $unitId = null;
        if (isset($data['unit']) && $data['unit'] !== '') {
            $unitId = Unit::whereNull('deleted_at')->where(fn ($q) => $q->where('ShortName', $data['unit'])->orWhere('name', $data['unit']))->value('id');
            if (! $unitId) {
                return $this->error('unknown_unit', "Unit \"{$data['unit']}\" does not exist.", 422);
            }
            // Changing the unit of an existing product rewrites its sale and
            // purchase units too, which is only safe when it uses one unit
            // throughout; a product sold or bought by another unit (box of 12 …)
            // keeps its setup and the change is refused.
            if (! $creating && (int) $unitId !== (int) $product->unit_id
                && ((int) $product->unit_sale_id !== (int) $product->unit_id || (int) $product->unit_purchase_id !== (int) $product->unit_id)) {
                return $this->error('unit_change_in_app', 'This product sells or buys in another unit; change its units in the app.', 422);
            }
            if (! $creating && (int) $unitId === (int) $product->unit_id) {
                $unitId = null; // unchanged
            }
        }
        if (array_key_exists('barcodes', $data) && $data['barcodes'] !== null) {
            $list = ProductBarcodes::normalize($data['barcodes']);
            if ($conflicts = ProductBarcodes::conflicts($list, $product?->id, null, $code)) {
                return $this->error('barcode_conflict', implode(' ', $conflicts), 422);
            }
        }

        $product = DB::transaction(function () use ($product, $creating, $data, $code, $unitId) {
            $product = $product ?: new Product([
                'type' => 'is_single',
                'code' => $code,
                'Type_barcode' => 'CODE128',
                'image' => 'no-image.png',
                'discount' => 0,
                'discount_method' => '2',
                'tax_method' => '1',
                'warehouse_id' => $this->productHomeWarehouseFor(null, false),
                'wholesale_price' => 0,
                'min_price' => 0,
                'stock_alert' => 0,
                'is_active' => 1,
            ]);
            if ($creating) {
                $product->TaxNet = 0; // not mass-assignable
            }
            $oldCategoryId = (int) $product->category_id;

            foreach (['name' => 'name', 'cost' => 'cost', 'price' => 'price', 'wholesale_price' => 'wholesale_price',
                'min_price' => 'min_price', 'tax_percent' => 'TaxNet', 'stock_alert' => 'stock_alert'] as $in => $column) {
                if (isset($data[$in])) {
                    $product->{$column} = $data[$in];
                }
            }
            if (isset($data['tax_method'])) {
                $product->tax_method = $data['tax_method'] === 'inclusive' ? '2' : '1';
            }
            if (isset($data['is_active'])) {
                $product->is_active = $data['is_active'] ? 1 : 0;
            }
            if ($unitId) {
                $product->unit_id = $product->unit_sale_id = $product->unit_purchase_id = $unitId;
            }
            if (! empty($data['brand'])) {
                $product->brand_id = Brand::firstOrCreate(['name' => $data['brand']])->id;
            }
            if (! empty($data['category'])) {
                $product->category_id = Category::firstOrCreate(['name' => $data['category']])->id;
                if ((int) $product->category_id !== $oldCategoryId && empty($data['sub_category'])) {
                    $product->sub_category_id = null;
                }
            }
            if (! empty($data['sub_category']) && $product->category_id) {
                $product->sub_category_id = SubCategory::firstOrCreate(
                    ['category_id' => $product->category_id, 'name' => $data['sub_category']],
                    ['status' => true]
                )->id;
            }
            $product->save();

            if ($creating) {
                // A zero-stock row in every warehouse, as the product form and
                // import create: stock movements work on these rows.
                $now = now();
                $rows = Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($wid) => [
                    'product_id' => $product->id, 'warehouse_id' => $wid, 'manage_stock' => 1, 'qte' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ])->all();
                if ($rows) {
                    DB::table('product_warehouse')->insert($rows);
                }
            }
            if ((int) $product->category_id !== $oldCategoryId && Schema::hasTable('category_product')) {
                if ($oldCategoryId) {
                    $product->categories()->detach($oldCategoryId);
                }
                $product->categories()->syncWithoutDetaching([(int) $product->category_id]);
            }
            if (array_key_exists('barcodes', $data) && $data['barcodes'] !== null) {
                ProductBarcodes::sync($product->id, null, ProductBarcodes::normalize($data['barcodes']));
            }

            return $product;
        });

        return response()->json([
            'data' => $this->resource($product->fresh()->load('category', 'subCategory', 'brand', 'unit', 'variants')),
            'created' => $creating,
        ], $creating ? 201 : 200);
    }

    protected function resource(Product $p, ?array $barcodeMap = null): array
    {
        $barcodes = $barcodeMap !== null ? ($barcodeMap[$p->id.'|'] ?? []) : ProductBarcodes::for((int) $p->id);

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'type' => ['is_single' => 'standard', 'is_variant' => 'variable', 'is_service' => 'service', 'is_combo' => 'combo'][$p->type] ?? $p->type,
            'category' => $p->category?->name,
            'sub_category' => $p->subCategory?->name,
            'brand' => $p->brand?->name,
            'unit' => $p->unit?->ShortName,
            'cost' => (float) $p->cost,
            'price' => (float) $p->price,
            'wholesale_price' => (float) $p->wholesale_price,
            'min_price' => (float) $p->min_price,
            'tax_percent' => (float) $p->TaxNet,
            'tax_method' => $p->tax_method == '2' ? 'inclusive' : 'exclusive',
            'is_active' => (bool) $p->is_active,
            'barcodes' => $barcodes,
            'variants' => $p->type === 'is_variant'
                ? $p->variants->whereNull('deleted_at')->map(fn (ProductVariant $v) => [
                    'code' => $v->code, 'name' => $v->name, 'cost' => (float) $v->cost, 'price' => (float) $v->price,
                ])->values()->all()
                : [],
            'updated_at' => optional($p->updated_at)->toIso8601String(),
        ];
    }
}
