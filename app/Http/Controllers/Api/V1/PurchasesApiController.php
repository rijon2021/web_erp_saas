<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/purchases — supplier (supplier_id or exact supplier name),
 * warehouse_id, reference (the supplier's invoice number), status
 * received|pending|ordered, lines [{code, quantity, cost}] in purchase units;
 * a blank cost takes the product's (or variant's) current cost.
 *
 * A received purchase adds the stock at once, like the app. The same supplier
 * invoice number on a live purchase from that supplier is refused (409) unless
 * allow_duplicate_reference=true. Serial / batch tracked products need their
 * serials or batches and are refused here.
 */
class PurchasesApiController extends ApiController
{
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);
        $data = $request->validate([
            'supplier_id' => 'nullable|integer',
            'supplier' => 'nullable|string|max:191',
            'warehouse_id' => 'required|integer',
            'reference' => 'nullable|string|max:100',
            'date' => 'nullable|date_format:Y-m-d',
            'status' => ['nullable', Rule::in(['received', 'pending', 'ordered'])],
            'notes' => 'nullable|string|max:1000',
            'allow_duplicate_reference' => 'nullable|boolean',
            'lines' => 'required|array|min:1|max:2000',
            'lines.*.code' => 'required|string|max:191',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.cost' => 'nullable|numeric|min:0',
        ]);

        $warehouseId = $this->warehouseOrError($data['warehouse_id']);
        if (! is_int($warehouseId)) {
            return $warehouseId;
        }

        $supplier = ! empty($data['supplier_id'])
            ? Provider::whereNull('deleted_at')->find($data['supplier_id'])
            : (! empty($data['supplier']) ? Provider::whereNull('deleted_at')->where('name', $data['supplier'])->first() : null);
        if (! $supplier) {
            return $this->error('unknown_supplier', 'Give supplier_id or the exact supplier name of an existing supplier.', 422);
        }

        $reference = trim((string) ($data['reference'] ?? ''));
        $refLine = 'Supplier invoice: '.$reference;
        if ($reference !== '' && empty($data['allow_duplicate_reference'])) {
            $existing = Purchase::whereNull('deleted_at')->where('provider_id', $supplier->id)
                ->where(fn ($q) => $q->where('notes', $refLine)->orWhere('notes', 'like', str_replace(['%', '_'], ['\%', '\_'], $refLine)."\n%"))
                ->first(['id', 'Ref']);
            if ($existing) {
                return $this->error('duplicate_reference', "Invoice \"{$reference}\" from this supplier is already purchase {$existing->Ref}.", 409, ['purchase_id' => $existing->id]);
            }
        }

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $errors = [];
        $lines = [];
        foreach ($data['lines'] as $i => $line) {
            [$product, $variant] = $this->resolveCode($line['code']);
            $at = "lines.{$i} ({$line['code']})";
            if (! $product || ! $this->canAccessProduct($product)) {
                $errors[] = "{$at}: unknown code.";
            } elseif (! $variant && $product->type === 'is_variant') {
                $errors[] = "{$at}: this product has variants — use a variant code.";
            } elseif (in_array($product->type, ['is_service', 'is_combo'], true)) {
                $errors[] = "{$at}: services and combos are not purchased into stock.";
            } elseif ($product->is_imei || ($hasBatch && $product->is_batch_tracked)) {
                $errors[] = "{$at}: serial / batch tracked — record this purchase in the app.";
            } else {
                $cost = isset($line['cost']) ? (float) $line['cost'] : (float) ($variant ? $variant->cost : $product->cost);
                $lines[] = ['product' => $product, 'variant' => $variant, 'quantity' => (float) $line['quantity'], 'cost' => $cost];
            }
        }
        if ($errors) {
            return $this->error('invalid_lines', 'Nothing was posted. '.count($errors).' line(s) need fixing.', 422, ['lines' => $errors]);
        }

        $status = $data['status'] ?? 'received';
        $purchase = DB::transaction(function () use ($lines, $supplier, $warehouseId, $data, $status, $reference, $refLine) {
            $purchase = new Purchase;
            $purchase->date = $data['date'] ?? now()->toDateString();
            $purchase->time = now()->toTimeString();
            $purchase->Ref = app('App\Http\Controllers\PurchasesController')->getNumberOrder();
            $purchase->provider_id = $supplier->id;
            $purchase->warehouse_id = $warehouseId;
            $purchase->tax_rate = 0;
            $purchase->TaxNet = 0;
            $purchase->discount = 0;
            $purchase->shipping = 0;
            $purchase->GrandTotal = 0;
            $purchase->statut = $status;
            $purchase->payment_statut = 'unpaid';
            $purchase->notes = trim(($reference !== '' ? $refLine."\n" : '').($data['notes'] ?? ''));
            $purchase->user_id = Auth::id();
            $purchase->save();

            $total = 0.0;
            foreach ($lines as $l) {
                $product = $l['product'];
                $lineTotal = $l['quantity'] * $l['cost'];
                $total += $lineTotal;
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $l['variant']?->id,
                    'quantity' => $l['quantity'],
                    'cost' => $l['cost'],
                    'purchase_unit_id' => $product->unit_purchase_id,
                    'TaxNet' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_method' => '2',
                    'total' => $lineTotal,
                    'imei_number' => null,
                ]);

                if ($status === 'received') {
                    // Quantities are in purchase units, as in the purchase form.
                    $unit = $product->unit_purchase_id ? Unit::find($product->unit_purchase_id) : null;
                    $base = $unit && (float) $unit->operator_value > 0
                        ? ($unit->operator === '/' ? $l['quantity'] / $unit->operator_value : $l['quantity'] * $unit->operator_value)
                        : $l['quantity'];
                    $pw = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $warehouseId)
                        ->where('product_id', $product->id)->where('product_variant_id', $l['variant']?->id)->lockForUpdate()->first()
                        ?: new product_warehouse(['warehouse_id' => $warehouseId, 'product_id' => $product->id, 'product_variant_id' => $l['variant']?->id, 'manage_stock' => 1, 'qte' => 0]);
                    $pw->qte = (float) $pw->qte + $base;
                    $pw->save();
                }
            }

            $purchase->GrandTotal = $total;
            $purchase->save();

            return $purchase;
        });

        return response()->json(['data' => [
            'id' => $purchase->id,
            'reference' => $purchase->Ref,
            'supplier_invoice' => $reference !== '' ? $reference : null,
            'status' => $purchase->statut,
            'grand_total' => (float) $purchase->GrandTotal,
            'lines' => \App\Services\DocumentLines::forModel($purchase),
        ]], 201);
    }
}
