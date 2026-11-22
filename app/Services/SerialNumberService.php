<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\AdjustmentDetailSerial;
use App\Models\Damage;
use App\Models\DamageDetailSerial;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetails;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailSerial;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * SerialNumberService
 *
 * Handles serial / IMEI side-effects for stock movements.
 *
 * Designed to be 100% opt-in:
 * - If the migration hasn't run, every method short-circuits without error.
 * - If a product doesn't have is_imei = true, its rows are ignored.
 * - The existing product_warehouse.qte accounting is left to the callers — this
 *   service only mirrors per-unit serial rows and writes the movement log.
 *
 * Enforcement is strict: when a serialized product line carries serials, the
 * count must equal the line quantity, every serial must be globally unique on
 * purchase, and every serial must be in-stock & available on sale. Any failure
 * throws a ValidationException, which rolls back the surrounding DB::transaction
 * and renders a 422 to the client.
 */
class SerialNumberService
{
    protected ?bool $supported = null;

    protected ?bool $transfersSupported = null;

    public function isSupported(): bool
    {
        if ($this->supported !== null) {
            return $this->supported;
        }

        $this->supported = Schema::hasTable('product_serials')
            && Schema::hasTable('product_serial_movements')
            && Schema::hasColumn('products', 'is_imei');

        return $this->supported;
    }

    /**
     * Whether a single product opted in to serial / IMEI tracking.
     */
    public function productIsTracked($productId): bool
    {
        if (! $this->isSupported() || ! $productId) {
            return false;
        }

        // Standard and variable products only: a combo's serials are its
        // components', and a service holds no stock (an old flag left on
        // either type by a type switch is ignored).
        return (bool) Product::whereKey($productId)->whereIn('type', ['is_single', 'is_variant'])->value('is_imei');
    }

    /**
     * Normalise a raw row value into a clean list of serial strings.
     * Accepts an array, or a newline/comma/semicolon/space separated string
     * (barcode scanners and bulk paste both land here). Trims, drops blanks,
     * and rejects duplicates inside the same submission.
     */
    public function normalizeSerials($raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,;\t]+/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $s) {
            $s = trim((string) $s);
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Duplicate serial number in this entry: {$s}"],
                ]);
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Create serial rows for a freshly-stored purchase detail.
     *
     * @param  array  $serials  Raw serial list from the request row.
     */
    public function receiveOnPurchase(Purchase $purchase, PurchaseDetail $detail, $serials): void
    {
        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $this->assertCountMatches($serials, $this->serialUnits($detail));
        $this->assertGloballyUnique($serials);

        $warehouseId = (int) $purchase->warehouse_id;
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = ProductSerial::create([
                'serial_number' => $serial,
                'product_id' => (int) $detail->product_id,
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'status' => ProductSerial::STATUS_AVAILABLE,
                'purchase_id' => (int) $purchase->id,
                'purchase_detail_id' => (int) $detail->id,
                'provider_id' => $purchase->provider_id ? (int) $purchase->provider_id : null,
                'cost' => $detail->cost !== null ? (float) $detail->cost : null,
            ]);

            $this->logMovement($row, ProductSerialMovement::ACTION_PURCHASED, null, ProductSerial::STATUS_AVAILABLE, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'Purchase',
                'reference_id' => (int) $purchase->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Reverse serial rows for a set of PurchaseDetail rows about to be re-applied
     * by an update. Only `available` serials may be reversed; if any unit has
     * already moved on (sold / returned) we refuse the edit so the ledger can't
     * be corrupted — the user must reverse the downstream document first.
     */
    public function reverseForPurchaseDetails($purchaseDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        foreach ($purchaseDetails as $detail) {
            if (! $detail || ! isset($detail->id)) {
                continue;
            }

            $serials = ProductSerial::where('purchase_detail_id', $detail->id)->get();
            $purchaseWarehouse = (int) Purchase::whereKey((int) $detail->purchase_id)->value('warehouse_id');
            foreach ($serials as $s) {
                // Still in stock AND still where the purchase received it (a
                // unit transferred away since would otherwise be recreated here).
                if ($s->status !== ProductSerial::STATUS_AVAILABLE
                    || ($purchaseWarehouse && (int) $s->warehouse_id !== $purchaseWarehouse)) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => [
                            "Cannot edit this purchase: serial {$s->serial_number} has already moved (status: {$s->status}). Reverse the related sale/return first.",
                        ],
                    ]);
                }
            }

            $ids = $serials->pluck('id')->all();
            if (! empty($ids)) {
                ProductSerialMovement::whereIn('product_serial_id', $ids)->delete();
                ProductSerial::whereIn('id', $ids)->delete();
            }
        }
    }

    /**
     * Re-create serial rows for an updated purchase. Expects the input rows and
     * persisted PurchaseDetail rows aligned by position (caller builds the pairs).
     */
    public function resyncPurchaseSerials(Purchase $purchase, array $alignedInput, $alignedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $alignedDetails = collect($alignedDetails)->values();
        foreach (array_values($alignedInput) as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail) {
                continue;
            }
            $this->receiveOnPurchase($purchase, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Mark selected serials as sold for a freshly-stored sale detail.
     *
     * @param  array  $serials  Raw serial list from the request row.
     */
    public function sellOnSale(Sale $sale, SaleDetail $detail, $serials): void
    {
        if (! $this->isSupported()) {
            return;
        }

        // A combo line sells serials of its tracked components instead.
        $recipe = [];
        if (! $this->productIsTracked($detail->product_id)) {
            $recipe = $this->comboRecipe((int) $detail->product_id);
            if (! $recipe) {
                return;
            }
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $componentOf = [];
        if ($recipe) {
            $componentOf = $this->comboSerialOwners($serials, $recipe);
            $this->assertComboCountsMatch($componentOf, $recipe, $this->serialUnits($detail));
        } else {
            $this->assertCountMatches($serials, $this->serialUnits($detail));
        }

        $warehouseId = (int) $sale->warehouse_id;
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = $recipe
                ? $this->findSellableSerial($serial, $componentOf[$serial], null, $warehouseId, $this->kitUnitsSellable())
                : $this->findSellableSerial($serial, (int) $detail->product_id, $variantId, $warehouseId);

            $from = $row->status;
            $row->status = ProductSerial::STATUS_SOLD;
            $row->sale_id = (int) $sale->id;
            $row->sale_detail_id = (int) $detail->id;
            $row->client_id = $sale->client_id ? (int) $sale->client_id : null;
            $row->save();

            $this->logMovement($row, ProductSerialMovement::ACTION_SOLD, $from, ProductSerial::STATUS_SOLD, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'Sale',
                'reference_id' => (int) $sale->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Release serials that were sold by a set of SaleDetail rows, back to
     * `available`, before an update re-applies the new selection. Serials that
     * have since moved on (e.g. already sale-returned) are left untouched.
     */
    public function reverseForSaleDetails($saleDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $userId = Auth::id();
        foreach ($saleDetails as $detail) {
            if (! $detail || ! isset($detail->id)) {
                continue;
            }

            $serials = ProductSerial::where('sale_detail_id', $detail->id)->get();
            foreach ($serials as $s) {
                if ($s->status !== ProductSerial::STATUS_SOLD) {
                    continue;
                }
                $from = $s->status;
                // A kit's component goes back into its kit, everything else to stock.
                $to = $this->statusBeforeSale($s);
                $s->status = $to;
                $s->sale_id = null;
                $s->sale_detail_id = null;
                $s->client_id = null;
                $s->save();

                $this->logMovement($s, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, $to, [
                    'warehouse_id' => $s->warehouse_id,
                    'user_id' => $userId,
                    'notes' => 'Released by sale edit',
                ]);
            }
        }
    }

    /**
     * Release every unit still `sold` on a sale — it was cancelled, moved
     * out of "completed" or deleted (see SaleSerialObserver). A kit's units go
     * back into their kit. Units already returned or otherwise moved on are
     * left alone.
     */
    public function releaseSale(int $saleId, string $note): void
    {
        if (! $this->isSupported() || $saleId <= 0) {
            return;
        }

        $userId = Auth::id();
        foreach (ProductSerial::where('sale_id', $saleId)->where('status', ProductSerial::STATUS_SOLD)->get() as $s) {
            $to = $this->statusBeforeSale($s);
            $s->status = $to;
            $s->sale_id = null;
            $s->sale_detail_id = null;
            $s->client_id = null;
            $s->save();

            $this->logMovement($s, ProductSerialMovement::ACTION_STATUS_CHANGED, ProductSerial::STATUS_SOLD, $to, [
                'warehouse_id' => $s->warehouse_id,
                'user_id' => $userId,
                'notes' => $note,
            ]);
        }
    }

    /**
     * Re-sell serials for an updated sale. Input rows and persisted SaleDetail
     * rows are aligned by position (caller builds the pairs).
     */
    public function resellSaleSerials(Sale $sale, array $alignedInput, $alignedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $alignedDetails = collect($alignedDetails);
        foreach (array_values($alignedInput) as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail) {
                continue;
            }
            $this->sellOnSale($sale, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Locate a serial that may be sold from the given product/variant/warehouse,
     * or throw a 422 describing exactly why it can't.
     */
    protected function findSellableSerial(string $serial, int $productId, ?int $variantId, int $warehouseId, bool $allowInKit = false): ProductSerial
    {
        // A combo line may also sell units assembled into kits.
        return $this->findSerialWithStatus($serial, $productId, $variantId, $warehouseId, $allowInKit
            ? [ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_IN_KIT]
            : [ProductSerial::STATUS_AVAILABLE]);
    }

    /**
     * Whether a combo line may take units held in kits. Only in kit mode
     * (virtual combo stock off), where selling a combo consumes an assembled
     * kit; with virtual stock a combo sale consumes loose component stock, so
     * it takes available units only.
     */
    public function kitUnitsSellable(): bool
    {
        return ! ComboStockService::enabled();
    }

    /**
     * Where a sold unit goes when its sale is released or returned: back into
     * its kit if it was sold out of one, otherwise to stock.
     */
    protected function statusBeforeSale(ProductSerial $serial): string
    {
        $from = ProductSerialMovement::where('product_serial_id', $serial->id)
            ->where('action', ProductSerialMovement::ACTION_SOLD)
            ->orderBy('id', 'desc')
            ->value('from_status');

        return $from === ProductSerial::STATUS_IN_KIT ? ProductSerial::STATUS_IN_KIT : ProductSerial::STATUS_AVAILABLE;
    }

    /**
     * Available serials for a product (+ variant + warehouse), for the sale picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableSerialsForSale(int $productId, ?int $variantId, int $warehouseId, ?int $includeSaleId = null, string $kitUnits = 'exclude'): array
    {
        if (! $this->isSupported() || $productId <= 0 || $warehouseId <= 0) {
            return [];
        }

        // Units assembled into kits: 'exclude' (a plain sale, kit assembly),
        // 'include' (selling a combo), 'only' (kit disassembly).
        $statuses = [
            'exclude' => [ProductSerial::STATUS_AVAILABLE],
            'include' => [ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_IN_KIT],
            'only' => [ProductSerial::STATUS_IN_KIT],
        ][$kitUnits] ?? [ProductSerial::STATUS_AVAILABLE];

        $query = ProductSerial::query()
            ->forProduct($productId)
            ->forWarehouse($warehouseId)
            ->where(function ($q) use ($includeSaleId, $statuses) {
                // In-stock units, plus (on edit) the ones already sold on this sale
                // so they show up pre-selected in the picker.
                $q->whereIn('status', $statuses);
                if ($includeSaleId) {
                    $q->orWhere(function ($w) use ($includeSaleId) {
                        $w->where('status', ProductSerial::STATUS_SOLD)
                            ->where('sale_id', $includeSaleId);
                    });
                }
            });

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->orderBy('id', 'asc')
            ->get(['id', 'serial_number', 'status'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'serial_number' => (string) $r->serial_number,
                'status' => (string) $r->status,
            ])
            ->all();
    }

    // ============================================================
    // Combos — serials of the tracked components sold inside a combo
    // ============================================================

    /** Per-request cache of combo id => [tracked component id => quantity per combo]. */
    protected array $comboRecipes = [];

    /**
     * Serial-tracked components of the given combos, as
     * combo id => [component id => quantity per combo]; ids that aren't combos,
     * or combos without a tracked component, map to [].
     *
     * A combo has no serials of its own: one combo line sells (quantity per
     * combo × line quantity) serials of every tracked component, all linked to
     * the combo's sale line, and returns bring them back the same way.
     * Components are single products, so their serials carry no variant.
     *
     * @param  int[]  $productIds
     * @return array<int, array<int, float>>
     */
    public function comboRecipes(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (! $this->isSupported() || ! $ids) {
            return [];
        }

        $missing = array_values(array_diff($ids, array_keys($this->comboRecipes)));
        if ($missing) {
            foreach ($missing as $id) {
                $this->comboRecipes[$id] = [];
            }
            DB::table('combined_products as cp')
                ->join('products as combo', 'combo.id', '=', 'cp.product_id')
                ->join('products as component', 'component.id', '=', 'cp.combined_product_id')
                ->whereIn('cp.product_id', $missing)
                ->where('combo.type', 'is_combo')
                ->where('component.is_imei', 1)
                ->get(['cp.product_id', 'cp.combined_product_id', 'cp.quantity'])
                ->each(function ($r) {
                    $per = max(0.0, (float) $r->quantity) ?: 1.0;
                    $this->comboRecipes[(int) $r->product_id][(int) $r->combined_product_id] =
                        ($this->comboRecipes[(int) $r->product_id][(int) $r->combined_product_id] ?? 0) + $per;
                });
        }

        return array_intersect_key($this->comboRecipes, array_flip($ids));
    }

    /**
     * @return array<int, float> tracked component id => quantity per combo
     */
    public function comboRecipe(int $productId): array
    {
        return $this->comboRecipes([$productId])[$productId] ?? [];
    }

    /**
     * Tracked components per combo, shaped for the sale screens' serial
     * pickers: combo id => [{product_id, name, code, per}]. Only combos with a
     * tracked component appear.
     *
     * @param  int[]  $productIds
     */
    public function comboSerialComponents(array $productIds): array
    {
        $recipes = array_filter($this->comboRecipes($productIds));
        if (! $recipes) {
            return [];
        }

        $componentIds = array_unique(array_merge(...array_map('array_keys', array_values($recipes))));
        $products = Product::whereIn('id', $componentIds)->get(['id', 'name', 'code'])->keyBy('id');

        $out = [];
        foreach ($recipes as $comboId => $recipe) {
            foreach ($recipe as $componentId => $per) {
                $product = $products->get($componentId);
                $out[$comboId][] = [
                    'product_id' => (int) $componentId,
                    'name' => (string) optional($product)->name,
                    'code' => (string) optional($product)->code,
                    'per' => (float) $per,
                ];
            }
        }

        return $out;
    }

    /**
     * Serials linked to the given sale lines, grouped per product — for a combo
     * line that is its components: sale detail id => [product id => [serial]].
     *
     * @param  int[]  $saleDetailIds
     */
    public function serialGroupsBySaleDetail(array $saleDetailIds): array
    {
        $saleDetailIds = array_values(array_filter(array_map('intval', $saleDetailIds)));
        if (! $this->isSupported() || ! $saleDetailIds) {
            return [];
        }

        $out = [];
        ProductSerial::whereIn('sale_detail_id', $saleDetailIds)
            ->orderBy('id', 'asc')
            ->get(['sale_detail_id', 'product_id', 'serial_number'])
            ->each(function ($s) use (&$out) {
                $out[(int) $s->sale_detail_id][(int) $s->product_id][] = (string) $s->serial_number;
            });

        return $out;
    }

    /**
     * Group free-text serials (e.g. a quotation line's imei_number) by the
     * product that owns them in the ledger: product id => [serial]. Unknown
     * serials and repeats are dropped — this only pre-fills pickers, the sale
     * itself is validated when saved.
     *
     * @param  string|array|null  $raw
     */
    public function groupSerialsByProduct($raw): array
    {
        $serials = is_string($raw) ? (preg_split('/[\r\n,;\t]+/', $raw) ?: []) : (array) $raw;
        $serials = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $serials), fn ($s) => $s !== '')));
        if (! $this->isSupported() || ! $serials) {
            return [];
        }

        $out = [];
        ProductSerial::whereIn('serial_number', $serials)
            ->orderBy('id', 'asc')
            ->get(['serial_number', 'product_id'])
            ->each(function ($r) use (&$out) {
                $out[(int) $r->product_id][] = (string) $r->serial_number;
            });

        return $out;
    }

    /**
     * Resolve each serial entered on a combo line to its component, rejecting
     * serials that aren't in the ledger or belong to anything else.
     *
     * @param  array<int, float>  $recipe
     * @return array<string, int> serial => component id
     */
    protected function comboSerialOwners(array $serials, array $recipe): array
    {
        $owners = [];
        ProductSerial::whereIn('serial_number', $serials)
            ->get(['serial_number', 'product_id'])
            ->each(function ($r) use (&$owners) {
                $owners[mb_strtolower((string) $r->serial_number)] = (int) $r->product_id;
            });

        $out = [];
        foreach ($serials as $serial) {
            $owner = $owners[mb_strtolower($serial)] ?? null;
            if ($owner === null) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number not found: {$serial}"],
                ]);
            }
            if (! isset($recipe[$owner])) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
                ]);
            }
            $out[$serial] = $owner;
        }

        return $out;
    }

    /**
     * Strict check: every tracked component gets exactly
     * (quantity per combo × line quantity) serials.
     *
     * @param  array<string, int>  $componentOf  serial => component id
     * @param  array<int, float>  $recipe
     */
    protected function assertComboCountsMatch(array $componentOf, array $recipe, $quantity): void
    {
        $counts = array_count_values($componentOf);
        foreach ($recipe as $componentId => $per) {
            $expected = (int) round($per * (float) $quantity);
            $got = $counts[$componentId] ?? 0;
            if ($got !== $expected) {
                $name = Product::whereKey($componentId)->value('name') ?: '#'.$componentId;
                throw ValidationException::withMessages([
                    'serial_numbers' => [
                        "The number of serial numbers for {$name} ({$got}) must match the quantity ({$expected}).",
                    ],
                ]);
            }
        }
    }

    // ============================================================
    // Sale Return — customer returns units back to available stock
    // ============================================================

    /**
     * Apply serial returns for a freshly-stored sale return. Input rows and
     * persisted SaleReturnDetails are aligned by key (caller builds the pairs).
     */
    public function applyForSaleReturn(SaleReturn $return, array $inputDetails, $persistedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $persisted = collect($persistedDetails);
        foreach ($inputDetails as $key => $row) {
            $detail = $persisted->get($key);
            if (! $detail) {
                continue;
            }
            // The return form's rows carry the sale line they mirror as `id`.
            $saleDetailId = isset($row['id']) && $return->sale_id
                ? (int) SaleDetail::where('id', (int) $row['id'])->where('sale_id', (int) $return->sale_id)->value('id')
                : 0;
            $this->returnFromSale($return, $detail, $row['serial_numbers'] ?? null, $saleDetailId ?: null);
        }
    }

    /**
     * Return the selected serials of a sale-return line back to available stock.
     */
    public function returnFromSale(SaleReturn $return, SaleReturnDetails $detail, $serials, ?int $saleDetailId = null): void
    {
        if (! $this->isSupported()) {
            return;
        }

        // A returned combo brings back serials of its tracked components.
        $recipe = [];
        if (! $this->productIsTracked($detail->product_id)) {
            $recipe = $this->comboRecipe((int) $detail->product_id);
            if (! $recipe) {
                return;
            }
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        if ($recipe) {
            $this->assertComboCountsMatch($this->comboSerialOwners($serials, $recipe), $recipe, $this->serialUnits($detail));
        } else {
            $this->assertCountMatches($serials, $this->serialUnits($detail));
        }

        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $warehouseId = (int) $return->warehouse_id;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = ProductSerial::where('serial_number', $serial)->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number not found: {$serial}"],
                ]);
            }
            if ($recipe
                ? ! isset($recipe[(int) $row->product_id])
                : ((int) $row->product_id !== (int) $detail->product_id
                    || ($variantId !== null && (int) $row->product_variant_id !== $variantId))) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
                ]);
            }
            if ($row->status !== ProductSerial::STATUS_SOLD) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number was not sold and cannot be returned (status: {$row->status}): {$serial}"],
                ]);
            }
            // If the return is linked to a specific sale, the serial must have been sold on it.
            if ($return->sale_id && (int) $row->sale_id !== (int) $return->sale_id) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number was not sold on the referenced sale: {$serial}"],
                ]);
            }
            // ...and on the very line being returned (a component unit sold in
            // a kit is not a standalone unit of the same product, and back).
            if ($saleDetailId !== null && (int) $row->sale_detail_id !== $saleDetailId) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number was not sold on this line: {$serial}"],
                ]);
            }

            $from = $row->status;
            // Back to available stock in the return warehouse; keep sale linkage as
            // "last sold on" so a deleted return can be reversed and for history.
            // A returned kit restocks the kit itself (kit mode), so its components
            // go back into it; with virtual combo stock they return to stock.
            $to = $recipe && ! ComboStockService::enabled()
                ? ProductSerial::STATUS_IN_KIT
                : ProductSerial::STATUS_AVAILABLE;
            $row->status = $to;
            $row->warehouse_id = $warehouseId;
            $row->save();

            $this->logMovement($row, ProductSerialMovement::ACTION_SALE_RETURNED, $from, $to, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'SaleReturn',
                'reference_id' => (int) $return->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Reverse a sale return (e.g. on delete): re-mark its returned serials as sold.
     * Best-effort — a serial that has since moved on is left untouched.
     */
    public function reverseForSaleReturn(SaleReturn $return): void
    {
        if (! $this->isSupported()) {
            return;
        }

        // serial id => status the return left it in (available, or in_kit).
        $returnedAs = ProductSerialMovement::where('reference_type', 'SaleReturn')
            ->where('reference_id', (int) $return->id)
            ->where('action', ProductSerialMovement::ACTION_SALE_RETURNED)
            ->orderBy('id', 'asc')
            ->pluck('to_status', 'product_serial_id')
            ->all();

        $userId = Auth::id();
        foreach (ProductSerial::whereIn('id', array_keys($returnedAs))->get() as $row) {
            // Only units still where the return put them; one since re-sold,
            // assembled, damaged or transferred has moved on.
            if ($row->status !== ($returnedAs[$row->id] ?? ProductSerial::STATUS_AVAILABLE)
                || (int) $row->warehouse_id !== (int) $return->warehouse_id) {
                continue;
            }
            $from = $row->status;
            $row->status = ProductSerial::STATUS_SOLD;
            $row->save();
            $this->logMovement($row, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, ProductSerial::STATUS_SOLD, [
                'warehouse_id' => $row->warehouse_id,
                'user_id' => $userId,
                'notes' => 'Sale return reversed',
            ]);
        }
    }

    // ============================================================
    // Purchase Return — units returned to supplier, out of inventory
    // ============================================================

    /**
     * Apply serial returns for a freshly-stored purchase return. Input rows and
     * persisted PurchaseReturnDetails are aligned by key (caller builds the pairs).
     */
    public function applyForPurchaseReturn(PurchaseReturn $return, array $inputDetails, $persistedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $persisted = collect($persistedDetails);
        foreach ($inputDetails as $key => $row) {
            $detail = $persisted->get($key);
            if (! $detail) {
                continue;
            }
            $this->returnToSupplier($return, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Mark the selected serials of a purchase-return line as returned to supplier.
     */
    public function returnToSupplier(PurchaseReturn $return, PurchaseReturnDetails $detail, $serials): void
    {
        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $this->assertCountMatches($serials, $this->serialUnits($detail));

        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = ProductSerial::where('serial_number', $serial)->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number not found: {$serial}"],
                ]);
            }
            if ((int) $row->product_id !== (int) $detail->product_id
                || ($variantId !== null && (int) $row->product_variant_id !== $variantId)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
                ]);
            }
            if ($row->status !== ProductSerial::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Only available serials can be returned to supplier (status: {$row->status}): {$serial}"],
                ]);
            }
            // Serials move with stock transfers, so a unit received here may
            // since have left for another warehouse; it can only go back to the
            // supplier from the warehouse whose stock this return reduces.
            if ($return->warehouse_id && (int) $row->warehouse_id !== (int) $return->warehouse_id) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number is not in the return warehouse: {$serial}"],
                ]);
            }
            // If the return is linked to a specific purchase, the serial must originate from it.
            if ($return->purchase_id && (int) $row->purchase_id !== (int) $return->purchase_id) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to the referenced purchase: {$serial}"],
                ]);
            }

            $from = $row->status;
            $row->status = ProductSerial::STATUS_RETURNED_SUPPLIER;
            $row->save();

            $this->logMovement($row, ProductSerialMovement::ACTION_PURCHASE_RETURNED, $from, ProductSerial::STATUS_RETURNED_SUPPLIER, [
                'warehouse_id' => $row->warehouse_id,
                'reference_type' => 'PurchaseReturn',
                'reference_id' => (int) $return->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Reverse a purchase return (e.g. on delete): bring its serials back to available.
     * Best-effort — a serial that has since moved on is left untouched.
     */
    public function reverseForPurchaseReturn(PurchaseReturn $return): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $serialIds = ProductSerialMovement::where('reference_type', 'PurchaseReturn')
            ->where('reference_id', (int) $return->id)
            ->where('action', ProductSerialMovement::ACTION_PURCHASE_RETURNED)
            ->pluck('product_serial_id')
            ->unique()
            ->all();

        $userId = Auth::id();
        foreach (ProductSerial::whereIn('id', $serialIds)->get() as $row) {
            if ($row->status !== ProductSerial::STATUS_RETURNED_SUPPLIER) {
                continue;
            }
            $from = $row->status;
            $row->status = ProductSerial::STATUS_AVAILABLE;
            $row->save();
            $this->logMovement($row, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, ProductSerial::STATUS_AVAILABLE, [
                'warehouse_id' => $row->warehouse_id,
                'user_id' => $userId,
                'notes' => 'Purchase return reversed',
            ]);
        }
    }

    // ============================================================
    // Transfer — serials travel with the stock between warehouses
    // ============================================================
    //
    // The selection is saved with the transfer (transfer_detail_serials) and
    // applied only while the transfer moves stock, mirroring the qty gating in
    // TransferController: approved + completed moves the unit to the
    // destination warehouse; approved + sent keeps it at the source as
    // reserved (in transit). Pending transfers only hold the selection.

    public function transfersSupported(): bool
    {
        if ($this->transfersSupported === null) {
            $this->transfersSupported = $this->isSupported() && Schema::hasTable('transfer_detail_serials');
        }

        return $this->transfersSupported;
    }

    /**
     * Whether a transfer in its current state has moved its stock.
     */
    public function transferMovesStock(Transfer $transfer): bool
    {
        return $transfer->isApproved() && in_array($transfer->statut, ['completed', 'sent'], true);
    }

    /**
     * Replace a transfer's serial selection. Input rows and persisted
     * TransferDetail rows are aligned by position (caller builds the pairs).
     * Call it after reverseTransferSerials() so serials the transfer had
     * already moved are back at the source and validate as available.
     */
    public function saveTransferSelection(Transfer $transfer, array $alignedInput, $alignedDetails): void
    {
        if (! $this->transfersSupported()) {
            return;
        }

        $alignedInput = array_values($alignedInput);
        $alignedDetails = collect($alignedDetails)->values();

        $carriesSerials = collect($alignedInput)
            ->contains(fn ($row) => is_array($row) && array_key_exists('serial_numbers', $row));

        // Serials are required server-side, not just by the form: a serialized
        // line added now, or one that already carried serials, must name its
        // units. Only lines saved before serials travelled with transfers
        // (existing line, no saved selection) may stay without them.
        $hadSelection = TransferDetailSerial::where('transfer_id', $transfer->id)
            ->pluck('transfer_detail_id')->map(fn ($id) => (int) $id)->unique()->all();
        foreach ($alignedInput as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail || ! $this->productIsTracked($detail->product_id)) {
                continue;
            }
            $needsSerials = $detail->wasRecentlyCreated || in_array((int) $detail->id, $hadSelection, true);
            $given = $carriesSerials ? $this->normalizeSerials($row['serial_numbers'] ?? null) : null;
            $missing = $carriesSerials ? empty($given) : $detail->wasRecentlyCreated;
            if ($needsSerials && $missing) {
                $name = Product::whereKey($detail->product_id)->value('name');
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Select the serial numbers for {$name} (".(int) round((float) $detail->quantity).' needed).'],
                ]);
            }
        }

        // A client that sends no serials at all keeps the saved selection;
        // only rows of lines removed from the document are dropped.
        if (! $carriesSerials) {
            TransferDetailSerial::where('transfer_id', $transfer->id)
                ->whereNotIn('transfer_detail_id', TransferDetail::where('transfer_id', $transfer->id)->pluck('id'))
                ->delete();

            return;
        }

        TransferDetailSerial::where('transfer_id', $transfer->id)->delete();

        $fromWarehouse = (int) $transfer->from_warehouse_id;
        $picked = [];
        foreach ($alignedInput as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail) {
                continue;
            }
            // A kit (combo) line may carry its components' in-kit units —
            // optional, since kits assembled before serials were recorded
            // hold none, but complete when given.
            $tracked = $this->productIsTracked($detail->product_id);
            $recipe = $tracked ? [] : $this->comboRecipe((int) $detail->product_id);
            if (! $tracked && ! $recipe) {
                continue;
            }

            $serials = $this->normalizeSerials($row['serial_numbers'] ?? null);
            if (empty($serials)) {
                continue;
            }

            $componentOf = [];
            if ($tracked) {
                $this->assertCountMatches($serials, $this->serialUnits($detail));
            } else {
                $componentOf = $this->comboSerialOwners($serials, $recipe);
                $this->assertComboCountsMatch($componentOf, $recipe, $this->serialUnits($detail));
            }
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            foreach ($serials as $serial) {
                $key = mb_strtolower($serial);
                if (isset($picked[$key])) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => ["Serial number selected on more than one line: {$serial}"],
                    ]);
                }
                $picked[$key] = true;

                $unit = $tracked
                    ? $this->findTransferableSerial($serial, (int) $detail->product_id, $variantId, $fromWarehouse, (int) $transfer->id)
                    : $this->findTransferableSerial($serial, $componentOf[$serial], null, $fromWarehouse, (int) $transfer->id, ProductSerial::STATUS_IN_KIT);

                TransferDetailSerial::create([
                    'transfer_id' => (int) $transfer->id,
                    'transfer_detail_id' => (int) $detail->id,
                    'product_serial_id' => (int) $unit->id,
                    'serial_number' => (string) $unit->serial_number,
                ]);
            }
        }
    }

    /**
     * Move the selected serials, if the transfer's state moves stock.
     * Each unit must still be available at the source warehouse.
     */
    public function applyTransferSerials(Transfer $transfer): void
    {
        if (! $this->transfersSupported() || ! $this->transferMovesStock($transfer)) {
            return;
        }

        $rows = TransferDetailSerial::with('serial')->where('transfer_id', $transfer->id)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $from = (int) $transfer->from_warehouse_id;
        $to = (int) $transfer->to_warehouse_id;
        $completed = $transfer->statut === 'completed';
        $route = $this->warehouseRoute($from, $to);
        $userId = Auth::id();
        $kitLines = $this->kitTransferDetailIds($transfer);

        foreach ($rows as $r) {
            $unit = $r->serial;
            $home = isset($kitLines[(int) $r->transfer_detail_id]) ? ProductSerial::STATUS_IN_KIT : ProductSerial::STATUS_AVAILABLE;
            if (! $unit || (int) $unit->warehouse_id !== $from || $unit->status !== $home) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number is no longer available in the source warehouse: {$r->serial_number}"],
                ]);
            }

            $prev = $unit->status;
            if ($completed) {
                $unit->warehouse_id = $to;
            } else {
                $unit->status = ProductSerial::STATUS_RESERVED;
            }
            $unit->save();

            $this->logMovement($unit, ProductSerialMovement::ACTION_TRANSFERRED, $prev, $unit->status, [
                'warehouse_id' => $completed ? $to : $from,
                'reference_type' => 'Transfer',
                'reference_id' => (int) $transfer->id,
                'user_id' => $userId,
                'notes' => $completed ? $route : $route.' (in transit)',
            ]);
        }
    }

    /**
     * Undo applyTransferSerials() before a transfer is edited or deleted.
     * Pass the transfer in its CURRENT (pre-edit) state. A completed transfer
     * whose unit was since sold or moved out of the destination can't be
     * reversed, so the edit/delete is refused rather than corrupting the ledger.
     */
    public function reverseTransferSerials(Transfer $transfer): void
    {
        if (! $this->transfersSupported() || ! $this->transferMovesStock($transfer)) {
            return;
        }

        $rows = TransferDetailSerial::with('serial')->where('transfer_id', $transfer->id)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $from = (int) $transfer->from_warehouse_id;
        $to = (int) $transfer->to_warehouse_id;
        $completed = $transfer->statut === 'completed';
        $route = $this->warehouseRoute($to, $from);
        $userId = Auth::id();
        $kitLines = $this->kitTransferDetailIds($transfer);

        foreach ($rows as $r) {
            $unit = $r->serial;
            if (! $unit) {
                continue;
            }
            // A kit line's units travel in their kits and go back into them.
            $home = isset($kitLines[(int) $r->transfer_detail_id]) ? ProductSerial::STATUS_IN_KIT : ProductSerial::STATUS_AVAILABLE;

            if ($completed) {
                // Already back at the source (e.g. corrected by hand): nothing to undo.
                if ((int) $unit->warehouse_id === $from && $unit->status === $home) {
                    continue;
                }
                if ((int) $unit->warehouse_id !== $to || $unit->status !== $home) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => ["Serial number {$r->serial_number} has already been sold or moved from the destination warehouse, so this transfer can no longer be changed."],
                    ]);
                }

                $unit->warehouse_id = $from;
                $unit->save();

                $this->logMovement($unit, ProductSerialMovement::ACTION_TRANSFERRED, $unit->status, $unit->status, [
                    'warehouse_id' => $from,
                    'reference_type' => 'Transfer',
                    'reference_id' => (int) $transfer->id,
                    'user_id' => $userId,
                    'notes' => $route.' (transfer reversed)',
                ]);
            } else {
                // Sent: only release units still held in transit at the source.
                if ((int) $unit->warehouse_id !== $from || $unit->status !== ProductSerial::STATUS_RESERVED) {
                    continue;
                }

                $unit->status = $home;
                $unit->save();

                $this->logMovement($unit, ProductSerialMovement::ACTION_TRANSFERRED, ProductSerial::STATUS_RESERVED, $home, [
                    'warehouse_id' => $from,
                    'reference_type' => 'Transfer',
                    'reference_id' => (int) $transfer->id,
                    'user_id' => $userId,
                    'notes' => 'Transit cancelled (transfer reversed)',
                ]);
            }
        }
    }

    /**
     * Lines of a transfer that move kits (combos): their selected units are
     * component units held in kits. transfer detail id => true.
     */
    protected function kitTransferDetailIds(Transfer $transfer): array
    {
        return TransferDetail::where('transfer_id', (int) $transfer->id)
            ->whereIn('product_id', Product::where('type', 'is_combo')->select('id'))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Drop a transfer's selection (on delete, after reverseTransferSerials()).
     */
    public function deleteTransferSelection(Transfer $transfer): void
    {
        if (! $this->transfersSupported()) {
            return;
        }

        TransferDetailSerial::where('transfer_id', $transfer->id)->delete();
    }

    /**
     * Saved serial numbers per transfer detail id, for the edit/show payloads.
     *
     * @return array<int, array<int, string>>
     */
    public function serialsByTransferDetail($details): array
    {
        if (! $this->transfersSupported()) {
            return [];
        }

        $ids = collect($details)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if (empty($ids)) {
            return [];
        }

        return TransferDetailSerial::whereIn('transfer_detail_id', $ids)
            ->orderBy('id', 'asc')
            ->get(['transfer_detail_id', 'serial_number'])
            ->groupBy('transfer_detail_id')
            ->map(fn ($rows) => $rows->pluck('serial_number')->all())
            ->all();
    }

    /**
     * Serials the transfer picker may offer: available at the source warehouse
     * and not already held by another transfer awaiting its stock movement,
     * plus (on edit) the ones already selected on this transfer. $inKit lists
     * a kit line's component units held in kits instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableSerialsForTransfer(int $productId, ?int $variantId, int $warehouseId, ?int $transferId = null, bool $inKit = false): array
    {
        if (! $this->transfersSupported() || $productId <= 0 || $warehouseId <= 0) {
            return [];
        }

        $held = $this->heldByUnappliedTransfers($transferId)
            ->pluck('transfer_detail_serials.product_serial_id')
            ->all();
        $status = $inKit ? ProductSerial::STATUS_IN_KIT : ProductSerial::STATUS_AVAILABLE;

        $query = ProductSerial::query()
            ->forProduct($productId)
            ->where(function ($q) use ($warehouseId, $held, $transferId, $status) {
                $q->where(function ($w) use ($warehouseId, $held, $status) {
                    $w->where('warehouse_id', $warehouseId)
                        ->where('status', $status)
                        ->when($held, fn ($x) => $x->whereNotIn('id', $held));
                });
                if ($transferId) {
                    $q->orWhereIn('id', TransferDetailSerial::where('transfer_id', $transferId)->select('product_serial_id'));
                }
            });

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->orderBy('id', 'asc')
            ->get(['id', 'serial_number', 'status'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'serial_number' => (string) $r->serial_number,
                'status' => (string) $r->status,
            ])
            ->all();
    }

    /**
     * Locate a serial that may leave the given source warehouse on a transfer,
     * or throw a 422 describing exactly why it can't.
     */
    protected function findTransferableSerial(string $serial, int $productId, ?int $variantId, int $warehouseId, int $transferId, string $status = ProductSerial::STATUS_AVAILABLE): ProductSerial
    {
        $row = ProductSerial::where('serial_number', $serial)->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number not found in stock: {$serial}"],
            ]);
        }

        if ((int) $row->product_id !== $productId
            || ($variantId !== null && (int) $row->product_variant_id !== $variantId)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
            ]);
        }

        if ((int) $row->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is not in the source warehouse: {$serial}"],
            ]);
        }

        // $status: in_kit for a kit line's component units.
        if ($row->status !== $status) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is not available (status: {$row->status}): {$serial}"],
            ]);
        }

        $heldRef = $this->heldByUnappliedTransfers($transferId)
            ->where('transfer_detail_serials.product_serial_id', $row->id)
            ->value('transfers.Ref');
        if ($heldRef !== null) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is already selected on transfer {$heldRef}: {$serial}"],
            ]);
        }

        return $row;
    }

    /**
     * Selection rows of live transfers that haven't moved their stock yet
     * (pending approval, or approved with status pending), optionally
     * excluding one transfer. Rejected transfers hold nothing.
     */
    protected function heldByUnappliedTransfers(?int $exceptTransferId)
    {
        return TransferDetailSerial::query()
            ->join('transfers', 'transfers.id', '=', 'transfer_detail_serials.transfer_id')
            ->whereNull('transfers.deleted_at')
            ->when($exceptTransferId, fn ($q) => $q->where('transfer_detail_serials.transfer_id', '!=', $exceptTransferId))
            ->where(function ($q) {
                $q->where('transfers.approval_status', 'pending')
                    ->orWhere(function ($w) {
                        // NULL approval_status = legacy row, treated as approved.
                        $w->where(fn ($a) => $a->whereNull('transfers.approval_status')->orWhere('transfers.approval_status', 'approved'))
                            ->where('transfers.statut', 'pending');
                    });
            });
    }

    protected function warehouseRoute(int $fromId, int $toId): string
    {
        $names = Warehouse::whereIn('id', [$fromId, $toId])->pluck('name', 'id');

        return ($names[$fromId] ?? "#{$fromId}").' → '.($names[$toId] ?? "#{$toId}");
    }

    // ============================================================
    // Adjustment — stock found / written off, kit assembly / disassembly
    // ============================================================

    protected ?bool $adjustmentsSupported = null;

    /**
     * Off until the adjustment_detail_serials migration has run: adjustments
     * then leave the serial ledger alone, as they always did.
     */
    public function adjustmentsSupported(): bool
    {
        if ($this->adjustmentsSupported === null) {
            $this->adjustmentsSupported = $this->isSupported() && Schema::hasTable('adjustment_detail_serials');
        }

        return $this->adjustmentsSupported;
    }

    /**
     * Apply the serials of saved adjustment lines. Input rows and persisted
     * AdjustmentDetail rows are aligned by position (caller builds the pairs).
     *
     *   tracked product, add     → new units enter stock (like a purchase)
     *   tracked product, sub     → the picked units are written off
     *   combo, add (assembly)    → the picked component units go into kits
     *   combo, sub (disassembly) → the picked kit units come back to stock
     *
     * A line without serials is left alone; a line with serials must carry
     * exactly one per unit (per component unit for a combo).
     */
    public function applyForAdjustment(Adjustment $adjustment, array $alignedInput, $alignedDetails): void
    {
        if (! $this->adjustmentsSupported()) {
            return;
        }

        $alignedDetails = collect($alignedDetails)->values();
        $this->assertNoSerialOnTwoLines($alignedInput);
        foreach (array_values($alignedInput) as $i => $row) {
            $detail = $alignedDetails->get($i);
            if ($detail) {
                $this->applyAdjustmentLine($adjustment, $detail, $row['serial_numbers'] ?? null);
            }
        }
    }

    /**
     * One unit can be moved by only one line of a document (a second move
     * would make the document impossible to reverse).
     */
    protected function assertNoSerialOnTwoLines(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            foreach ($this->normalizeSerials(is_array($row) ? ($row['serial_numbers'] ?? null) : null) as $serial) {
                $key = mb_strtolower($serial);
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => ["Serial number selected on more than one line: {$serial}"],
                    ]);
                }
                $seen[$key] = true;
            }
        }
    }

    protected function applyAdjustmentLine(Adjustment $adjustment, AdjustmentDetail $detail, $serials): void
    {
        $tracked = $this->productIsTracked($detail->product_id);
        $recipe = $tracked ? [] : $this->comboRecipe((int) $detail->product_id);
        if (! $tracked && ! $recipe) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        // Units an edit kept on this line (adjustmentRowsToKeep) are already
        // applied; they count toward the line but aren't moved again.
        $kept = AdjustmentDetailSerial::where('adjustment_detail_id', (int) $detail->id)
            ->pluck('serial_number')
            ->mapWithKeys(fn ($s) => [mb_strtolower((string) $s) => true])
            ->all();

        $add = $detail->type === 'add';
        $componentOf = [];
        if ($tracked) {
            $this->assertCountMatches($serials, $this->serialUnits($detail));
            if ($add) {
                $this->assertGloballyUnique(array_values(array_filter($serials, fn ($s) => ! isset($kept[mb_strtolower($s)]))));
            }
        } else {
            $componentOf = $this->comboSerialOwners($serials, $recipe);
            $this->assertComboCountsMatch($componentOf, $recipe, $this->serialUnits($detail));
        }

        $warehouseId = (int) $adjustment->warehouse_id;
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            if (isset($kept[mb_strtolower($serial)])) {
                continue;
            }
            if ($tracked && $add) {
                $row = ProductSerial::create([
                    'serial_number' => $serial,
                    'product_id' => (int) $detail->product_id,
                    'product_variant_id' => $variantId,
                    'warehouse_id' => $warehouseId,
                    'status' => ProductSerial::STATUS_AVAILABLE,
                ]);
                [$action, $from, $to] = [AdjustmentDetailSerial::ACTION_IN, null, ProductSerial::STATUS_AVAILABLE];
            } else {
                [$action, $expected, $to] = $tracked
                    ? [AdjustmentDetailSerial::ACTION_OUT, ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_WRITTEN_OFF]
                    : ($add
                        ? [AdjustmentDetailSerial::ACTION_KIT, ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_IN_KIT]
                        : [AdjustmentDetailSerial::ACTION_UNKIT, ProductSerial::STATUS_IN_KIT, ProductSerial::STATUS_AVAILABLE]);
                $row = $this->findSerialWithStatus(
                    $serial,
                    $tracked ? (int) $detail->product_id : $componentOf[$serial],
                    $tracked ? $variantId : null,
                    $warehouseId,
                    [$expected]
                );
                $from = $row->status;
                $row->status = $to;
                $row->save();
            }

            $this->logMovement($row, ProductSerialMovement::ACTION_ADJUSTED, $from, $to, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'Adjustment',
                'reference_id' => (int) $adjustment->id,
                'user_id' => $userId,
            ]);

            AdjustmentDetailSerial::create([
                'adjustment_id' => (int) $adjustment->id,
                'adjustment_detail_id' => (int) $detail->id,
                'product_serial_id' => (int) $row->id,
                'serial_number' => (string) $row->serial_number,
                'action' => $action,
            ]);
        }
    }

    /**
     * Undo an adjustment's serial moves before it is edited or deleted. Like a
     * purchase edit, this refuses when a unit has moved on since (sold,
     * transferred, re-adjusted), so the ledger can't be rewritten under it.
     *
     * $keepRowIds: selection rows an edit leaves exactly as they are (see
     * adjustmentRowsToKeep) — a unit the adjustment created keeps its id, so
     * transfers or sales already pointing at it stay valid.
     * $onlyDetailIds: reverse just these lines (lines removed by an edit).
     */
    public function reverseForAdjustment(Adjustment $adjustment, array $keepRowIds = [], ?array $onlyDetailIds = null): void
    {
        if (! $this->adjustmentsSupported()) {
            return;
        }

        $rows = AdjustmentDetailSerial::with('serial')
            ->where('adjustment_id', (int) $adjustment->id)
            ->when($onlyDetailIds !== null, fn ($q) => $q->whereIn('adjustment_detail_id', $onlyDetailIds ?: [0]))
            ->when($keepRowIds, fn ($q) => $q->whereNotIn('id', $keepRowIds))
            ->orderBy('id', 'desc')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        // action => [status the adjustment left the unit in, status to restore]
        $states = [
            AdjustmentDetailSerial::ACTION_IN => [ProductSerial::STATUS_AVAILABLE, null],
            AdjustmentDetailSerial::ACTION_OUT => [ProductSerial::STATUS_WRITTEN_OFF, ProductSerial::STATUS_AVAILABLE],
            AdjustmentDetailSerial::ACTION_KIT => [ProductSerial::STATUS_IN_KIT, ProductSerial::STATUS_AVAILABLE],
            AdjustmentDetailSerial::ACTION_UNKIT => [ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_IN_KIT],
        ];

        foreach ($rows as $r) {
            $s = $r->serial;
            if ($s && ($s->status !== $states[$r->action][0] || (int) $s->warehouse_id !== (int) $adjustment->warehouse_id)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => [
                        "Cannot change this adjustment: serial {$s->serial_number} has moved since (status: {$s->status}). Reverse the later document first.",
                    ],
                ]);
            }
        }

        $userId = Auth::id();
        foreach ($rows as $r) {
            $s = $r->serial;
            if (! $s) {
                continue;
            }
            if ($r->action === AdjustmentDetailSerial::ACTION_IN) {
                // The unit only existed because of this adjustment.
                ProductSerialMovement::where('product_serial_id', $s->id)->delete();
                $s->delete();

                continue;
            }
            $from = $s->status;
            $to = $states[$r->action][1];
            $s->status = $to;
            $s->save();
            $this->logMovement($s, ProductSerialMovement::ACTION_ADJUSTED, $from, $to, [
                'warehouse_id' => $s->warehouse_id,
                'reference_type' => 'Adjustment',
                'reference_id' => (int) $adjustment->id,
                'user_id' => $userId,
                'notes' => 'Adjustment reversed',
            ]);
        }

        AdjustmentDetailSerial::whereIn('id', $rows->pluck('id')->all())->delete();
    }

    /**
     * Selection rows an adjustment edit keeps untouched: same line (same
     * product, variant and add/sub type), same warehouse, and the serial is
     * submitted again on that line. Everything else is reversed and re-applied.
     *
     * @return int[] adjustment_detail_serials ids
     */
    public function adjustmentRowsToKeep(Adjustment $adjustment, array $inputRows, int $newWarehouseId): array
    {
        if (! $this->adjustmentsSupported() || (int) $adjustment->warehouse_id !== $newWarehouseId) {
            return [];
        }

        $old = AdjustmentDetail::where('adjustment_id', (int) $adjustment->id)->get()->keyBy('id');
        $keep = [];
        foreach ($inputRows as $row) {
            $detail = is_array($row) ? $old->get((int) ($row['id'] ?? 0)) : null;
            if (! $detail
                || $detail->type !== ($row['type'] ?? null)
                || (int) $detail->product_id !== (int) ($row['product_id'] ?? 0)
                || (int) $detail->product_variant_id !== (int) ($row['product_variant_id'] ?? 0)) {
                continue;
            }
            $wanted = array_flip(array_map('mb_strtolower', $this->normalizeSerials($row['serial_numbers'] ?? null)));
            foreach (AdjustmentDetailSerial::where('adjustment_detail_id', (int) $detail->id)->get() as $r) {
                if (isset($wanted[mb_strtolower($r->serial_number)])) {
                    $keep[] = (int) $r->id;
                }
            }
        }

        return $keep;
    }

    public function adjustmentHasSerials(Adjustment $adjustment): bool
    {
        return $this->adjustmentsSupported()
            && AdjustmentDetailSerial::where('adjustment_id', (int) $adjustment->id)->exists();
    }

    /**
     * Serials moved by adjustment lines, grouped per product — for a combo line
     * that is its components: adjustment detail id => [product id => [serial]].
     *
     * @param  int[]  $detailIds
     */
    public function serialGroupsByAdjustmentDetail(array $detailIds): array
    {
        $detailIds = array_values(array_filter(array_map('intval', $detailIds)));
        if (! $this->adjustmentsSupported() || ! $detailIds) {
            return [];
        }

        $out = [];
        AdjustmentDetailSerial::with('serial:id,product_id')
            ->whereIn('adjustment_detail_id', $detailIds)
            ->orderBy('id', 'asc')
            ->get()
            ->each(function ($r) use (&$out) {
                $productId = (int) optional($r->serial)->product_id;
                $out[(int) $r->adjustment_detail_id][$productId][] = (string) $r->serial_number;
            });

        return $out;
    }

    // ============================================================
    // Damage — units taken out of stock as damaged
    // ============================================================

    protected ?bool $damagesSupported = null;

    /**
     * Off until the damage_detail_serials migration has run: damages then
     * leave the serial ledger alone, as they always did.
     */
    public function damagesSupported(): bool
    {
        if ($this->damagesSupported === null) {
            $this->damagesSupported = $this->isSupported() && Schema::hasTable('damage_detail_serials');
        }

        return $this->damagesSupported;
    }

    /**
     * Mark the units of saved damage lines `damaged`. Input rows and persisted
     * DamageDetail rows are aligned by position (caller builds the pairs).
     * A tracked product takes its own in-stock units; a combo (kit) takes its
     * tracked components' units, from its kits or from stock. A line without
     * serials is left alone; a line with serials needs one per unit.
     */
    public function applyForDamage(Damage $damage, array $alignedInput, $alignedDetails): void
    {
        if (! $this->damagesSupported()) {
            return;
        }

        $alignedDetails = collect($alignedDetails)->values();
        $warehouseId = (int) $damage->warehouse_id;
        $userId = Auth::id();
        $this->assertNoSerialOnTwoLines($alignedInput);

        foreach (array_values($alignedInput) as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail) {
                continue;
            }

            $tracked = $this->productIsTracked($detail->product_id);
            $recipe = $tracked ? [] : $this->comboRecipe((int) $detail->product_id);
            if (! $tracked && ! $recipe) {
                continue;
            }

            $serials = $this->normalizeSerials($row['serial_numbers'] ?? null);
            if (empty($serials)) {
                continue;
            }

            $componentOf = [];
            if ($tracked) {
                $this->assertCountMatches($serials, $this->serialUnits($detail));
            } else {
                $componentOf = $this->comboSerialOwners($serials, $recipe);
                $this->assertComboCountsMatch($componentOf, $recipe, $this->serialUnits($detail));
            }
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            foreach ($serials as $serial) {
                $unit = $tracked
                    ? $this->findSerialWithStatus($serial, (int) $detail->product_id, $variantId, $warehouseId, [ProductSerial::STATUS_AVAILABLE])
                    : $this->findSerialWithStatus($serial, $componentOf[$serial], null, $warehouseId, [ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_IN_KIT]);

                $from = $unit->status;
                $unit->status = ProductSerial::STATUS_DAMAGED;
                $unit->save();

                $this->logMovement($unit, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, ProductSerial::STATUS_DAMAGED, [
                    'warehouse_id' => $warehouseId,
                    'reference_type' => 'Damage',
                    'reference_id' => (int) $damage->id,
                    'user_id' => $userId,
                ]);

                DamageDetailSerial::create([
                    'damage_id' => (int) $damage->id,
                    'damage_detail_id' => (int) $detail->id,
                    'product_serial_id' => (int) $unit->id,
                    'serial_number' => (string) $unit->serial_number,
                    'from_status' => $from,
                ]);
            }
        }
    }

    /**
     * Restore a damage's units before it is edited or deleted. Refuses when a
     * unit has moved on since (e.g. its status was changed by hand).
     * $onlyDetailIds: restore just these lines (lines removed by an edit).
     */
    public function reverseForDamage(Damage $damage, ?array $onlyDetailIds = null): void
    {
        if (! $this->damagesSupported()) {
            return;
        }

        $rows = DamageDetailSerial::with('serial')
            ->where('damage_id', (int) $damage->id)
            ->when($onlyDetailIds !== null, fn ($q) => $q->whereIn('damage_detail_id', $onlyDetailIds ?: [0]))
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $r) {
            $s = $r->serial;
            if ($s && ($s->status !== ProductSerial::STATUS_DAMAGED || (int) $s->warehouse_id !== (int) $damage->warehouse_id)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => [
                        "Cannot change this damage: serial {$s->serial_number} has moved since (status: {$s->status}).",
                    ],
                ]);
            }
        }

        $userId = Auth::id();
        foreach ($rows as $r) {
            $s = $r->serial;
            if (! $s) {
                continue;
            }
            $s->status = $r->from_status;
            $s->save();
            $this->logMovement($s, ProductSerialMovement::ACTION_STATUS_CHANGED, ProductSerial::STATUS_DAMAGED, $r->from_status, [
                'warehouse_id' => $s->warehouse_id,
                'reference_type' => 'Damage',
                'reference_id' => (int) $damage->id,
                'user_id' => $userId,
                'notes' => 'Damage reversed',
            ]);
        }

        DamageDetailSerial::whereIn('id', $rows->pluck('id')->all())->delete();
    }

    public function damageHasSerials(Damage $damage): bool
    {
        return $this->damagesSupported()
            && DamageDetailSerial::where('damage_id', (int) $damage->id)->exists();
    }

    /**
     * Serials taken by damage lines, grouped per product (a combo line's are
     * its components'): damage detail id => [product id => [serial]].
     *
     * @param  int[]  $detailIds
     */
    public function serialGroupsByDamageDetail(array $detailIds): array
    {
        $detailIds = array_values(array_filter(array_map('intval', $detailIds)));
        if (! $this->damagesSupported() || ! $detailIds) {
            return [];
        }

        $out = [];
        DamageDetailSerial::with('serial:id,product_id')
            ->whereIn('damage_detail_id', $detailIds)
            ->orderBy('id', 'asc')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->damage_detail_id][(int) optional($r->serial)->product_id][] = (string) $r->serial_number;
            });

        return $out;
    }

    /**
     * Locate a serial of the given product/variant in the warehouse whose
     * status is one of $statuses, or throw a 422 saying why not.
     */
    protected function findSerialWithStatus(string $serial, int $productId, ?int $variantId, int $warehouseId, array $statuses): ProductSerial
    {
        // Locked for the surrounding transaction, so two documents saved at
        // the same moment can't both take the same unit.
        $row = ProductSerial::where('serial_number', $serial)->lockForUpdate()->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number not found in stock: {$serial}"],
            ]);
        }
        if ((int) $row->product_id !== $productId
            || ($variantId !== null && (int) $row->product_variant_id !== $variantId)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
            ]);
        }
        if ((int) $row->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is not in the selected warehouse: {$serial}"],
            ]);
        }
        if (! in_array($row->status, $statuses, true)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is not available (status: {$row->status}): {$serial}"],
            ]);
        }

        return $row;
    }

    /**
     * Base units a document line moves — one serial each: quantity × pack
     * multiplier, converted from the line's sale / purchase unit the way the
     * stock movement converts it (ComboStockService::baseQty). Lines without a
     * unit (adjustments, damages) are already in base units.
     */
    public function serialUnits($detail): float
    {
        $qty = (float) $detail->quantity;
        $pack = (float) ($detail->pack_multiplier ?? 1);
        if ($pack > 0) {
            $qty *= $pack;
        }
        $unitId = $detail->sale_unit_id ?? $detail->purchase_unit_id ?? null;

        return ComboStockService::baseQty($qty, $unitId ? Unit::find((int) $unitId) : null);
    }

    /**
     * Strict check: number of serials must equal the line quantity.
     */
    protected function assertCountMatches(array $serials, $quantity): void
    {
        $expected = (int) round((float) $quantity);
        if (count($serials) !== $expected) {
            throw ValidationException::withMessages([
                'serial_numbers' => [
                    'The number of serial numbers ('.count($serials).') must match the quantity ('.$expected.').',
                ],
            ]);
        }
    }

    /**
     * Strict check: none of these serials may already exist anywhere in the ledger.
     */
    protected function assertGloballyUnique(array $serials): void
    {
        $existing = ProductSerial::whereIn('serial_number', $serials)
            ->pluck('serial_number')
            ->all();

        if (! empty($existing)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['Serial number(s) already exist in the system: '.implode(', ', $existing)],
            ]);
        }
    }

    /**
     * Manually change a serial's status among the in-stock states
     * (available / damaged / reserved). Serials driven by documents
     * (sold / returned_supplier) can't be changed here.
     */
    public function changeStatus(ProductSerial $serial, string $newStatus, ?string $note = null): void
    {
        $manual = [
            ProductSerial::STATUS_AVAILABLE,
            ProductSerial::STATUS_DAMAGED,
            ProductSerial::STATUS_RESERVED,
        ];

        if (! in_array($newStatus, $manual, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid target status.'],
            ]);
        }

        if (! in_array($serial->status, $manual, true)) {
            throw ValidationException::withMessages([
                'status' => ["A {$serial->status} serial can't be changed manually; it is controlled by sales/returns."],
            ]);
        }

        if ($serial->status === $newStatus) {
            return;
        }

        $from = $serial->status;
        $serial->status = $newStatus;
        $serial->save();

        $this->logMovement($serial, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, $newStatus, [
            'warehouse_id' => $serial->warehouse_id,
            'user_id' => Auth::id(),
            'notes' => $note,
        ]);
    }

    /**
     * Whether an update payload actually carries serial data on any line. Used to
     * avoid touching the serial ledger on edits from forms that don't yet collect
     * serials (which would otherwise wipe previously-captured serials).
     */
    public function payloadHasSerials($details): bool
    {
        if (! is_array($details)) {
            return false;
        }
        foreach ($details as $row) {
            if (is_array($row) && array_key_exists('serial_numbers', $row) && ! empty($row['serial_numbers'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append an audit row to the movement log.
     */
    public function logMovement(ProductSerial $serial, string $action, ?string $fromStatus, ?string $toStatus, array $meta = []): void
    {
        ProductSerialMovement::create([
            'product_serial_id' => (int) $serial->id,
            'serial_number' => (string) $serial->serial_number,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'warehouse_id' => $meta['warehouse_id'] ?? $serial->warehouse_id,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'user_id' => $meta['user_id'] ?? Auth::id(),
            'notes' => $meta['notes'] ?? null,
            'created_at' => now(),
        ]);
    }
}
