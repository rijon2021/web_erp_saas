<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Services\SalesLines;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /api/v1/sales/lines — one row per item sold from `since` (Y-m-d) to
 * `until` (default today); include_returns=1 adds returns as NEGATIVE rows,
 * so the rows sum to net sales. Same lines as Reports > Product Sales.
 * Filters: warehouse_id. Ordered by date, then line, for stable paging.
 */
class SalesApiController extends ApiController
{
    public function lines(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);
        $data = $request->validate([
            'since' => 'required|date_format:Y-m-d',
            'until' => 'nullable|date_format:Y-m-d|after_or_equal:since',
            'warehouse_id' => 'nullable|integer',
            'include_returns' => 'nullable|boolean',
        ]);

        $warehouseIds = $this->userWarehouseIds();
        if (! empty($data['warehouse_id']) && ! in_array((int) $data['warehouse_id'], $warehouseIds, true)) {
            return $this->error('invalid_warehouse', 'warehouse_id is not one of this key\'s warehouses.', 422);
        }

        $query = SalesLines::query([
            'from' => $data['since'],
            'to' => $data['until'] ?? now()->toDateString(),
            'warehouse_ids' => $this->warehouseScopeIds(), // null = all, incl. deleted warehouses
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'user_id' => Auth::user()->hasRecordView() ? null : Auth::id(),
            'include_returns' => $request->boolean('include_returns'),
        ])->orderBy('date')->orderBy('kind', 'desc')->orderBy('line_id');

        return response()->json($this->paginated($query, $request, fn ($r) => [
            'type' => $r->kind,
            'date' => $r->date,
            'document_id' => (int) $r->document_id,
            'reference' => $r->Ref,
            'customer' => $r->client_name,
            'warehouse' => $r->warehouse_name,
            'code' => $r->code,
            'product' => $r->product_name,
            'category' => $r->category_name,
            'unit' => $r->unit_sale,
            'quantity' => (float) $r->quantity,
            'unit_price' => (float) $r->unit_price,
            'discount' => round((float) $r->discount, 4),
            'total' => (float) $r->total,
        ], 200, 1000));
    }
}
