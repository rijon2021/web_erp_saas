<?php

namespace App\Http\Controllers;

use App\Imports\ProductImport;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\WarehousePrices;
use App\Traits\ScopesWarehouseAccess;
use Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Different prices per store — spreadsheet round trip
 * (Products > Import > Update products, "Store prices" card).
 *
 * Columns: code, product, variant, warehouse, normal_price, price,
 * wholesale_price, min_price. `product` and `normal_price` are for reading
 * only. On import a price column that is present but blank clears that
 * figure (the normal price applies again); a column missing from the file
 * leaves that figure alone.
 */
class WarehousePricesController extends Controller
{
    use ScopesWarehouseAccess;

    private const HEADERS = ['code', 'product', 'variant', 'warehouse', 'normal_price', 'price', 'wholesale_price', 'min_price'];

    /**
     * GET products/warehouse-prices/meta — what the import page's card needs:
     * whether the feature is usable and the caller's warehouses.
     */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);

        return response()->json([
            'available' => WarehousePrices::tableExists(),
            'enabled' => WarehousePrices::enabled(),
            'warehouses' => Warehouse::whereNull('deleted_at')->whereIn('id', $this->userWarehouseIds())
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * GET products/warehouse-prices/export?warehouse_id=&only_set=1
     * CSV of every product (variants expanded) × warehouse, or only the rows
     * that carry a store price. Limited to the caller's warehouses.
     */
    public function export(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        abort_unless(WarehousePrices::tableExists(), 422, 'Store prices are not available yet: the database update has not been run.');

        $warehouseIds = $this->userWarehouseIds();
        if ($request->filled('warehouse_id')) {
            $this->abortIfWarehouseDenied($request->warehouse_id);
            $warehouseIds = [(int) $request->warehouse_id];
        }
        $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id', $warehouseIds)
            ->orderBy('name')->pluck('name', 'id')->all();
        $onlySet = $request->boolean('only_set');

        $filename = 'store_prices'.(count($warehouses) === 1 ? '_'.\Illuminate\Support\Str::slug(reset($warehouses)) : '').'.csv';

        return response()->streamDownload(function () use ($warehouses, $onlySet) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents / Arabic
            fputcsv($out, self::HEADERS);

            if (empty($warehouses)) {
                fclose($out);

                return;
            }
            $warehouseIds = array_keys($warehouses);

            $query = Product::query()->whereNull('deleted_at')
                ->select(['id', 'code', 'name', 'type', 'price'])
                ->orderBy('id');
            $this->scopeProductsToWarehouses($query);
            if ($onlySet) {
                $query->whereIn('id', DB::table('product_warehouse_prices')
                    ->whereIn('warehouse_id', $warehouseIds)->select('product_id'));
            }

            $query->chunkById(500, function ($products) use ($out, $warehouses, $warehouseIds, $onlySet) {
                $ids = $products->pluck('id')->all();
                $variants = ProductVariant::whereIn('product_id', $ids)->whereNull('deleted_at')
                    ->orderBy('id')->get(['id', 'product_id', 'name', 'code', 'price'])->groupBy('product_id');
                $prices = [];
                foreach (DB::table('product_warehouse_prices')->whereIn('product_id', $ids)
                    ->whereIn('warehouse_id', $warehouseIds)->orderBy('id')->get() as $r) {
                    $prices[WarehousePrices::key($r->product_id, $r->product_variant_id).'|'.$r->warehouse_id] = $r;
                }

                foreach ($products as $product) {
                    $lines = $product->type === 'is_variant'
                        ? ($variants[$product->id] ?? collect())->map(fn ($v) => [$v->code, $v->name, $v->price, $v->id])->all()
                        : [[$product->code, '', $product->price, null]];

                    foreach ($lines as [$code, $variantName, $normal, $variantId]) {
                        foreach ($warehouses as $wid => $wname) {
                            $row = $prices[WarehousePrices::key($product->id, $variantId).'|'.$wid] ?? null;
                            if ($onlySet && ! $row) {
                                continue;
                            }
                            fputcsv($out, [
                                $code,
                                self::safeText($product->name),
                                self::safeText($variantName),
                                self::safeText($wname),
                                self::plain($normal),
                                $row ? self::plain($row->price) : '',
                                $row ? self::plain($row->wholesale_price) : '',
                                $row ? self::plain($row->min_price) : '',
                            ]);
                        }
                    }
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * POST products/import/warehouse-prices (multipart `products`).
     * Valid rows are applied; the others come back as a list to fix.
     */
    public function import(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'product_import', Product::class);
        ini_set('max_execution_time', 2000);

        if (! WarehousePrices::tableExists()) {
            return response()->json(['status' => false, 'errors' => ['Store prices are not available yet: the database update has not been run.']], 422);
        }
        if (! WarehousePrices::enabled()) {
            return response()->json(['status' => false, 'errors' => ['Turn on "Different Prices per Store" in System Settings > Features first.']], 422);
        }

        $request->validate([
            'products' => [
                'required', 'file',
                function ($attribute, $value, $fail) {
                    if (! in_array(strtolower($value->getClientOriginalExtension()), ['csv', 'xls', 'xlsx'], true)) {
                        $fail('The file must be a csv, xls or xlsx file.');
                    }
                },
            ],
        ]);

        $rows = $this->readRows($request->file('products'));
        if (empty($rows)) {
            return response()->json(['status' => false, 'errors' => ['The file has no rows.']], 422);
        }

        $headers = array_keys($rows[0]);
        if (! in_array('code', $headers, true) || ! in_array('warehouse', $headers, true)) {
            return response()->json(['status' => false, 'errors' => ['The file needs a "code" and a "warehouse" column.']], 422);
        }
        $fields = array_values(array_intersect(WarehousePrices::FIELDS, $headers));
        if (empty($fields)) {
            return response()->json(['status' => false, 'errors' => ['The file needs at least one of the columns price, wholesale_price, min_price.']], 422);
        }

        // Warehouses the caller may price, addressed by name or id.
        $allowed = $this->userWarehouseIds();
        $warehouseByName = [];
        foreach (Warehouse::whereNull('deleted_at')->whereIn('id', $allowed)->get(['id', 'name']) as $w) {
            $warehouseByName[mb_strtolower(trim($w->name))] = (int) $w->id;
            $warehouseByName[(string) $w->id] = (int) $w->id;
        }

        $counts = ['created' => 0, 'updated' => 0, 'cleared' => 0, 'unchanged' => 0];
        $errors = [];

        DB::transaction(function () use ($rows, $fields, $warehouseByName, &$counts, &$errors) {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // header is row 1
                $code = self::cellText($row['code'] ?? '');
                $whName = mb_strtolower(self::cellText($row['warehouse'] ?? ''));
                if ($code === '' && $whName === '') {
                    continue;
                }
                if ($code === '') {
                    $errors[] = "Row {$line}: code is empty.";

                    continue;
                }
                $wid = $warehouseByName[$whName] ?? null;
                if (! $wid) {
                    $errors[] = "Row {$line}: warehouse \"".self::cellText($row['warehouse'] ?? '')."\" was not found or is not assigned to you.";

                    continue;
                }

                [$productId, $variantId, $problem] = $this->resolveLine($code, self::cellText($row['variant'] ?? ''));
                if ($problem) {
                    $errors[] = "Row {$line}: {$problem}";

                    continue;
                }

                $figures = [];
                $bad = null;
                foreach ($fields as $field) {
                    $raw = $row[$field] ?? null;
                    $value = WarehousePrices::money($raw);
                    if ($value === null && $raw !== null && trim((string) $raw) !== '') {
                        $bad = "{$field} \"{$raw}\" is not a valid amount.";
                        break;
                    }
                    $figures[$field] = $value;
                }
                if ($bad) {
                    $errors[] = "Row {$line}: {$bad}";

                    continue;
                }

                $counts[WarehousePrices::upsert($productId, $variantId, $wid, $figures)]++;
            }
        });

        return response()->json([
            'status' => true,
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'cleared' => $counts['cleared'],
            'unchanged' => $counts['unchanged'],
            'error_list' => $errors,
        ]);
    }

    /**
     * code (+ optional variant) → [product_id, variant_id|null, error|null].
     * A standard product's code, or a variant's own code; with the `variant`
     * column filled, the parent code plus the variant's name or code.
     */
    private function resolveLine(string $code, string $variant): array
    {
        $visible = function ($q) {
            $q->whereNull('deleted_at');
            $this->scopeProductsToWarehouses($q);
        };

        $product = Product::where('code', $code)->where($visible)->first(['id', 'type']);

        if ($product && $product->type === 'is_variant') {
            if ($variant === '') {
                return [null, null, "\"{$code}\" has variants: fill the variant column or use the variant's own code."];
            }
            $match = ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('code', $variant)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($variant)]))
                ->first(['id']);

            return $match
                ? [(int) $product->id, (int) $match->id, null]
                : [null, null, "variant \"{$variant}\" was not found on \"{$code}\"."];
        }

        if ($product) {
            return [(int) $product->id, null, null];
        }

        $variantRow = ProductVariant::where('code', $code)->whereNull('deleted_at')
            ->whereHas('product', $visible)
            ->first(['id', 'product_id']);

        return $variantRow
            ? [(int) $variantRow->product_id, (int) $variantRow->id, null]
            : [null, null, "no product or variant has the code \"{$code}\"."];
    }

    /** Rows as header => value, headers normalised like the other imports. */
    private function readRows($file): array
    {
        $normalise = fn ($h) => trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h))), '_');

        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            // Excel in many European locales saves CSV with ";" (or a tab):
            // take whichever separator the header line uses most.
            $firstLine = (string) fgets($fh = fopen($file->getRealPath(), 'r'));
            fclose($fh);
            $delimiter = ',';
            foreach ([';', "\t"] as $candidate) {
                if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
                    $delimiter = $candidate;
                }
            }

            $handle = fopen($file->getRealPath(), 'r');
            $rows = [];
            $headers = null;
            while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($headers === null) {
                    $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($line[0] ?? ''));
                    $headers = array_map($normalise, $line);

                    continue;
                }
                if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $line = array_pad(array_slice($line, 0, count($headers)), count($headers), '');
                $rows[] = array_combine($headers, array_map('trim', $line));
            }
            fclose($handle);

            return $rows;
        }

        $rows = Excel::toArray(new ProductImport, $file)[0] ?? [];
        $out = [];
        foreach ($rows as $row) {
            $clean = [];
            foreach ($row as $k => $v) {
                $clean[$normalise($k)] = is_string($v) ? trim($v) : $v;
            }
            if (count(array_filter($clean, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Names are free text: one starting with = + - @ would run as a formula
     * when the sheet is opened in Excel, so it gets a leading apostrophe
     * (shown as text; the import strips it again).
     */
    private static function safeText($value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** A cell as text: whole numbers from Excel without ".0" / exponent; export's leading apostrophe removed. */
    private static function cellText($value): string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = number_format($value, 0, '.', '');
        }
        $value = trim((string) $value);

        return str_starts_with($value, "'") ? substr($value, 1) : $value;
    }

    private static function plain($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }
}
