<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per item sold — and, with returns, one NEGATIVE row per item
 * returned — so a list sums to net sales. Shared by Reports > Product Sales
 * and GET /api/v1/sales/lines. Deleted documents are left out.
 *
 * $o: from, to (Y-m-d, inclusive), warehouse_ids (allowed scope; null =
 * unrestricted, deleted warehouses included), and
 * optional warehouse_id, client_id, user_id (own documents only), search,
 * include_returns, since_id (rows after this document-line id, for paging an
 * integration by cursor — returns use their own id space, so pass it per kind).
 */
class SalesLines
{
    public static function query(array $o): Builder
    {
        $union = self::lines('sale', $o);
        if (! empty($o['include_returns'])) {
            $union->unionAll(self::lines('return', $o));
        }

        return DB::query()->fromSub($union, 'l');
    }

    private static function lines(string $kind, array $o)
    {
        $isSale = $kind === 'sale';
        $doc = $isSale ? 'sales' : 'sale_returns';
        $det = $isSale ? 'sale_details' : 'sale_return_details';
        $fk = $isSale ? 'sale_id' : 'sale_return_id';
        $sign = $isSale ? '' : '-';
        $dateCol = $isSale ? 'd.date' : 'doc.date';

        return DB::table("$det as d")
            ->join("$doc as doc", 'doc.id', '=', "d.$fk")
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'doc.client_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'doc.warehouse_id')
            ->leftJoin('units as u', 'u.id', '=', DB::raw('COALESCE(d.sale_unit_id, p.unit_sale_id)'))
            ->whereNull('doc.deleted_at')
            ->whereBetween($dateCol, [$o['from'], $o['to']])
            // null = every warehouse (an all-warehouses user): history stays
            // complete even for a warehouse that was deleted since.
            ->when(($o['warehouse_ids'] ?? null) !== null, fn ($q) => $q->whereIn('doc.warehouse_id', $o['warehouse_ids'] ?: [0]))
            ->when(! empty($o['warehouse_id']), fn ($q) => $q->where('doc.warehouse_id', (int) $o['warehouse_id']))
            ->when(! empty($o['client_id']), fn ($q) => $q->where('doc.client_id', (int) $o['client_id']))
            ->when(! empty($o['user_id']), fn ($q) => $q->where('doc.user_id', (int) $o['user_id']))
            ->when(! empty($o['since_id']), fn ($q) => $q->where('d.id', '>', (int) $o['since_id']))
            ->when(($o['search'] ?? '') !== '', function ($q) use ($o) {
                $s = '%'.$o['search'].'%';
                $q->where(fn ($w) => $w->where('doc.Ref', 'LIKE', $s)->orWhere('cl.name', 'LIKE', $s)->orWhere('w.name', 'LIKE', $s)
                    ->orWhere('p.name', 'LIKE', $s)->orWhere('p.code', 'LIKE', $s)->orWhere('pv.code', 'LIKE', $s));
            })
            ->selectRaw(
                "CONCAT(?, d.id) as id, d.id as line_id, ? as kind, $dateCol as date, doc.Ref as Ref, doc.id as document_id,"
                .' COALESCE(cl.name, ?) as client_name, COALESCE(w.name, ?) as warehouse_name,'
                .' COALESCE(pv.code, p.code) as code,'
                ." CASE WHEN pv.id IS NULL THEN p.name ELSE CONCAT('[', pv.name, ']', p.name) END as product_name,"
                .' c.name as category_name, u.ShortName as unit_sale,'
                ." {$sign}d.quantity as quantity, d.price as unit_price,"
                ." {$sign}(CASE WHEN d.discount_method = '2' THEN d.discount ELSE d.price * d.discount / 100 END) * d.quantity as discount,"
                ." {$sign}d.total as total",
                [$isSale ? 's' : 'r', $kind, '---', '---']
            );
    }
}
