<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductBarcodes;
use Illuminate\Http\Request;

/**
 * Shared plumbing of the public REST API (/api/v1). Every call is made as the
 * API key's user (ApiKeyAuth), so the app's own policies and warehouse scopes
 * (BaseController / ScopesWarehouseAccess) apply unchanged.
 *
 * Conventions: JSON in and out; lists are {data: [...], meta: {page,
 * per_page, total}}; errors are {error: {code, message}} with a 4xx status
 * (validation errors keep Laravel's {message, errors} 422 shape).
 */
abstract class ApiController extends BaseController
{
    protected function error(string $code, string $message, int $status = 422, array $extra = [])
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }

    protected function perPage(Request $request, int $default = 50, int $max = 200): int
    {
        return max(1, min((int) $request->query('per_page', $default) ?: $default, $max));
    }

    /** {data, meta} for a query builder, with the rows mapped by $map. */
    protected function paginated($query, Request $request, callable $map, int $default = 50, int $max = 200): array
    {
        $perPage = $this->perPage($request, $default, $max);
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return [
            'data' => $rows->map($map)->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => $page * $perPage < $total],
        ];
    }

    /**
     * What a code points at: [product, variant|null] by product code, variant
     * code or additional barcode — or [null, null].
     */
    protected function resolveCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [null, null];
        }
        $product = Product::whereNull('deleted_at')->where('code', $code)->first();
        if ($product) {
            return [$product, null];
        }
        $variant = ProductVariant::whereNull('deleted_at')->where('code', $code)->first();
        if ($variant) {
            return [Product::whereNull('deleted_at')->find($variant->product_id), $variant];
        }
        if ($owner = ProductBarcodes::owner($code)) {
            $product = Product::whereNull('deleted_at')->find($owner[0]);
            $variant = $owner[1] ? ProductVariant::whereNull('deleted_at')->find($owner[1]) : null;

            return [$product, $variant];
        }

        return [null, null];
    }

    /** The warehouse a write call targets: given and allowed, or an error response. */
    protected function warehouseOrError($warehouseId)
    {
        $id = (int) $warehouseId;
        if (! $id || ! in_array($id, $this->userWarehouseIds(), true)) {
            return $this->error('invalid_warehouse', 'warehouse_id is missing, unknown or not one of this key\'s warehouses.', 422);
        }

        return $id;
    }
}
