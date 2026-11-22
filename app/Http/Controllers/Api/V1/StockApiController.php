<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\Product;
use App\Models\product_warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET  /api/v1/stock                 stock levels (codes=A,B,… · warehouse_id · updated_since)
 * POST /api/v1/stock/adjustments     post one adjustment: reason, reference,
 *                                    lines [{code, quantity}] (+ adds, − removes)
 *
 * Lines name products by code, variant code or additional barcode. Serial /
 * batch tracked, combo and service products are refused (their stock moves
 * through serials, batches or components). The adjustment is noted
 * "API: <reason> — <reference>" and can be deleted in the app to undo it.
 */
class StockApiController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        $request->validate([
            'codes' => 'nullable|string|max:20000',
            'warehouse_id' => 'nullable|integer',
            'updated_since' => 'nullable|date',
        ]);

        $warehouseIds = $this->userWarehouseIds();
        if ($request->filled('warehouse_id')) {
            $id = (int) $request->query('warehouse_id');
            if (! in_array($id, $warehouseIds, true)) {
                return $this->error('invalid_warehouse', 'warehouse_id is not one of this key\'s warehouses.', 422);
            }
            $warehouseIds = [$id];
        }

        $codes = $request->filled('codes')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $request->query('codes')))))
            : [];
        if (count($codes) > 500) {
            return $this->error('too_many_codes', 'Ask for at most 500 codes per call.', 422);
        }

        $query = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pw.product_variant_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->whereNull('pw.deleted_at')->whereNull('p.deleted_at')->whereNull('w.deleted_at')
            ->where(fn ($q) => $q->whereNotNull('pw.product_variant_id')->orWhere('p.type', '!=', 'is_variant'))
            ->where('p.type', '!=', 'is_service')
            ->whereIn('pw.warehouse_id', $warehouseIds ?: [0])
            ->when($codes, fn ($q) => $q->where(fn ($w) => $w->whereIn('p.code', $codes)->orWhereIn('pv.code', $codes)))
            ->when($request->filled('updated_since'), fn ($q) => $q->where('pw.updated_at', '>=', \Carbon\Carbon::parse($request->query('updated_since'))))
            ->orderBy('pw.id')
            ->select('pw.id', 'pw.warehouse_id', 'w.name as warehouse', 'pw.qte', 'pw.manage_stock', 'pw.updated_at',
                'p.code as product_code', 'p.name as product_name', 'pv.code as variant_code', 'pv.name as variant_name');

        return response()->json($this->paginated($query, $request, fn ($r) => [
            'code' => $r->variant_code ?: $r->product_code,
            'name' => $r->variant_name ? $r->product_name.' ['.$r->variant_name.']' : $r->product_name,
            'warehouse_id' => (int) $r->warehouse_id,
            'warehouse' => $r->warehouse,
            'quantity' => (float) $r->qte,
            'manage_stock' => (bool) $r->manage_stock,
            'updated_at' => $r->updated_at,
        ], 200, 1000));
    }

    public function adjust(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Adjustment::class);
        $data = $request->validate([
            'warehouse_id' => 'required|integer',
            'reason' => 'required|string|max:100',
            'reference' => 'nullable|string|max:100',
            'date' => 'nullable|date_format:Y-m-d',
            'lines' => 'required|array|min:1|max:5000',
            'lines.*.code' => 'required|string|max:191',
            'lines.*.quantity' => 'required|numeric|not_in:0',
        ]);
        $warehouseId = $this->warehouseOrError($data['warehouse_id']);
        if (! is_int($warehouseId)) {
            return $warehouseId;
        }

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $errors = [];
        $moves = []; // "pid|vid" → [product_id, variant_id, quantity]
        foreach ($data['lines'] as $i => $line) {
            [$product, $variant] = $this->resolveCode($line['code']);
            $at = "lines.{$i} ({$line['code']})";
            if (! $product) {
                $errors[] = "{$at}: unknown code.";
            } elseif (! $variant && $product->type === 'is_variant') {
                $errors[] = "{$at}: this product has variants — use a variant code.";
            } elseif (in_array($product->type, ['is_service', 'is_combo'], true)) {
                $errors[] = "{$at}: services and combos have no stock of their own.";
            } elseif ($product->is_imei || ($hasBatch && $product->is_batch_tracked)) {
                $errors[] = "{$at}: serial / batch tracked — adjust it in the app.";
            } elseif (! $this->canAccessProduct($product)) {
                $errors[] = "{$at}: unknown code.";
            } else {
                $key = $product->id.'|'.($variant?->id ?? '');
                $moves[$key] ??= [(int) $product->id, $variant ? (int) $variant->id : null, 0.0];
                $moves[$key][2] += (float) $line['quantity'];
            }
        }
        if ($errors) {
            return $this->error('invalid_lines', 'Nothing was posted. '.count($errors).' line(s) need fixing.', 422, ['lines' => $errors]);
        }
        $moves = array_filter($moves, fn ($m) => abs($m[2]) > 0.0000001);
        if (! $moves) {
            return $this->error('nothing_to_post', 'The lines cancel each other out.', 422);
        }

        $adjustment = DB::transaction(function () use ($moves, $warehouseId, $data) {
            $note = 'API: '.$data['reason'].(! empty($data['reference']) ? ' — '.$data['reference'] : '');
            $adjustment = Adjustment::create([
                'date' => $data['date'] ?? now()->toDateString(),
                'time' => now()->toTimeString(),
                'Ref' => app('App\Http\Controllers\AdjustmentController')->getNumberOrder(),
                'warehouse_id' => $warehouseId,
                'items' => count($moves),
                'notes' => $note,
                'user_id' => Auth::id(),
            ]);

            foreach ($moves as [$productId, $variantId, $qty]) {
                $pw = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)->where('product_variant_id', $variantId)->lockForUpdate()->first()
                    ?: new product_warehouse(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'product_variant_id' => $variantId, 'manage_stock' => 1, 'qte' => 0]);
                $pw->qte = (float) $pw->qte + $qty;
                $pw->save(); // the stock observer pushes Shopify / WooCommerce

                AdjustmentDetail::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'quantity' => abs($qty),
                    'type' => $qty > 0 ? 'add' : 'sub',
                ]);
            }

            \App\Services\AccountingV2\StockJournal::adjustment($adjustment, created: true);

            return $adjustment;
        });

        return response()->json(['data' => [
            'id' => $adjustment->id,
            'reference' => $adjustment->Ref,
            'warehouse_id' => $warehouseId,
            'notes' => $adjustment->notes,
            'lines' => \App\Services\DocumentLines::forModel($adjustment),
        ]], 201);
    }
}
