<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Product lines of documents — code, product, category, quantity, unit price,
 * discount, tax, total (adjustments: signed quantity). Used by the lists'
 * "Items (Excel)" export and by the sale / return / purchase webhooks, so an
 * integration gets the lines with the event. No access scoping here: callers
 * pass ids they may read.
 */
class DocumentLines
{
    /** type → [model class, documents table, details table, document fk, party, price column] */
    public const TYPES = [
        'sales' => [\App\Models\Sale::class, 'sales', 'sale_details', 'sale_id', 'client', 'price'],
        'sale_returns' => [\App\Models\SaleReturn::class, 'sale_returns', 'sale_return_details', 'sale_return_id', 'client', 'price'],
        'purchases' => [\App\Models\Purchase::class, 'purchases', 'purchase_details', 'purchase_id', 'provider', 'cost'],
        'purchase_returns' => [\App\Models\PurchaseReturn::class, 'purchase_returns', 'purchase_return_details', 'purchase_return_id', 'provider', 'cost'],
        'adjustments' => [\App\Models\Adjustment::class, 'adjustments', 'adjustment_details', 'adjustment_id', null, null],
    ];

    /** The type key for a document model, or null. */
    public static function typeOf(Model $model): ?string
    {
        foreach (self::TYPES as $type => [$class]) {
            if ($model instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /** Lines of one document model (empty for anything that is not a document). */
    public static function forModel(Model $model): array
    {
        $type = self::typeOf($model);

        return $type ? self::lines($type, [(int) $model->getKey()]) : [];
    }

    public static function lines(string $type, array $ids): array
    {
        [, $docs, $details, $fk, $party, $priceCol] = self::TYPES[$type];
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $query = DB::table("$details as d")
                ->join("$docs as doc", 'doc.id', '=', "d.$fk")
                ->leftJoin('warehouses as w', 'w.id', '=', 'doc.warehouse_id')
                ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereIn("d.$fk", $chunk)
                ->orderBy('doc.date')
                ->orderBy('doc.id')
                ->orderBy('d.id')
                ->select(
                    'doc.date', 'doc.Ref', 'w.name as warehouse_name', 'd.product_id', 'd.product_variant_id',
                    'p.code as product_code', 'p.name as product_name', 'pv.code as variant_code', 'pv.name as variant_name',
                    'c.name as category_name', 'd.quantity'
                );

            if ($party === 'client') {
                $query->leftJoin('clients as party', 'party.id', '=', 'doc.client_id')->addSelect('party.name as party_name');
            } elseif ($party === 'provider') {
                $query->leftJoin('providers as party', 'party.id', '=', 'doc.provider_id')->addSelect('party.name as party_name');
            }

            if ($priceCol) {
                $query->addSelect("d.$priceCol as unit_price", 'd.discount', 'd.discount_method', 'd.TaxNet as tax_percent', 'd.tax_method', 'd.total');
            } else {
                $query->addSelect('d.type');
            }

            foreach ($query->get() as $row) {
                $out[] = self::line($row, (bool) $priceCol, $party !== null);
            }
        }

        return $out;
    }

    private static function line(object $row, bool $priced, bool $hasParty): array
    {
        $line = [
            'date' => $row->date,
            'Ref' => $row->Ref,
            'warehouse_name' => $row->warehouse_name,
            'product_id' => (int) $row->product_id,
            'product_variant_id' => $row->product_variant_id !== null ? (int) $row->product_variant_id : null,
            'code' => $row->variant_code ?: $row->product_code,
            'product_name' => $row->variant_name ? $row->product_name.' ['.$row->variant_name.']' : $row->product_name,
            'category_name' => $row->category_name,
        ];
        if ($hasParty) {
            $line['party_name'] = $row->party_name;
        }

        if (! $priced) {
            // Adjustments: one signed quantity, as the stock moved.
            $line['quantity'] = ($row->type === 'sub' ? -1 : 1) * (float) $row->quantity;

            return $line;
        }

        $price = (float) $row->unit_price;
        $discount = $row->discount_method == '2'
            ? (float) $row->discount
            : $price * (float) $row->discount / 100;
        $qty = (float) $row->quantity;
        // The documents' own math: tax is TaxNet % of the discounted price,
        // added on top (exclusive) or contained in it (inclusive).
        $tax = (float) $row->tax_percent * ($price - $discount) / 100;

        return $line + [
            'quantity' => $qty,
            'unit_price' => round($price, 4),
            'discount' => round($discount * $qty, 4),
            'tax' => round($tax * $qty, 4),
            'total' => (float) $row->total,
        ];
    }
}
