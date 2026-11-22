<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Virtual combo stock (Settings -> Features -> virtual_combo_stock).
 *
 * Off (default): a combo is an assembled kit with its own product_warehouse
 * row — stocked by an Adjustment that consumes the components — and every
 * screen reads that row like any other product.
 *
 * On: the combo's own row is ignored. Its availability is the number of whole
 * combos its components can still fill (min over components of
 * floor(component stock / quantity per combo)), and stock checks expand a
 * combo line into the component units it consumes.
 *
 * Components are single products (see get_products_materiels), so their stock
 * lives on the product_warehouse row with a NULL variant. A component that is
 * not stock-managed or is flagged "Always in stock" never limits a combo; a
 * combo whose components are all unlimited is unlimited (availability null).
 */
class ComboStockService
{
    /** Per-request cache of combo id => [component id => quantity per combo]. */
    private array $recipes = [];

    public static function enabled(): bool
    {
        return (bool) (Setting::current()->virtual_combo_stock ?? false);
    }

    /**
     * @param  int[]  $comboIds
     * @return array<int, array<int, float>> combo id => [component id => quantity per combo]
     */
    public function recipes(array $comboIds): array
    {
        $comboIds = array_values(array_unique(array_map('intval', $comboIds)));
        $missing = array_values(array_diff($comboIds, array_keys($this->recipes)));

        if ($missing) {
            foreach ($missing as $id) {
                $this->recipes[$id] = [];
            }
            DB::table('combined_products')
                ->whereIn('product_id', $missing)
                ->get(['product_id', 'combined_product_id', 'quantity'])
                ->each(function ($r) {
                    $per = max(0.0, (float) $r->quantity) ?: 1.0;
                    $this->recipes[(int) $r->product_id][(int) $r->combined_product_id] =
                        ($this->recipes[(int) $r->product_id][(int) $r->combined_product_id] ?? 0) + $per;
                });
        }

        return array_intersect_key($this->recipes, array_flip($comboIds));
    }

    /**
     * Component units consumed by $qty base units of a combo.
     *
     * @return array<int, float> component id => base units
     */
    public function componentNeeds(int $comboId, float $qty): array
    {
        $needs = [];
        foreach ($this->recipes([$comboId])[$comboId] ?? [] as $componentId => $per) {
            $needs[$componentId] = $per * $qty;
        }

        return $needs;
    }

    /**
     * How many combos the components can fill, stock summed over the given
     * warehouses. null = unlimited; a combo without components gets 0.
     *
     * @param  int[]  $comboIds
     * @param  int|int[]  $warehouseIds
     * @return array<int, float|null>
     */
    public function availability(array $comboIds, int|array $warehouseIds): array
    {
        $recipes = $this->recipes($comboIds);
        $componentIds = [];
        foreach ($recipes as $recipe) {
            $componentIds = array_merge($componentIds, array_keys($recipe));
        }
        $stock = $this->componentStock(array_values(array_unique($componentIds)), (array) $warehouseIds);

        $result = [];
        foreach ($recipes as $comboId => $recipe) {
            if (! $recipe) {
                $result[$comboId] = 0.0;

                continue;
            }

            $min = null;
            foreach ($recipe as $componentId => $per) {
                if (! array_key_exists($componentId, $stock)) {
                    $min = 0.0;

                    break;
                }
                if ($stock[$componentId] === null) {
                    continue;
                }
                $possible = floor(max(0.0, $stock[$componentId]) / $per + 1e-9);
                $min = $min === null ? $possible : min($min, $possible);
            }
            $result[$comboId] = $min;
        }

        return $result;
    }

    /**
     * Overwrite qte on the combo rows of a product_warehouse result, in memory
     * only (never saved), so the existing qte / qte_sale formatting keeps
     * working. Rows of unlimited combos get qte 0 and combo_unlimited = true.
     * Does nothing while the setting is off. Rows lazy-load `product` when it
     * isn't eager-loaded.
     *
     * @param  iterable<\App\Models\product_warehouse>  $rows
     */
    public function overlayRows(iterable $rows): void
    {
        if (! self::enabled()) {
            return;
        }

        $byWarehouse = [];
        foreach ($rows as $row) {
            if ($row->product_variant_id === null && optional($row->product)->type === 'is_combo') {
                $byWarehouse[(int) $row->warehouse_id][] = $row;
            }
        }

        foreach ($byWarehouse as $warehouseId => $comboRows) {
            $available = $this->availability(
                array_map(fn ($r) => (int) $r->product_id, $comboRows),
                $warehouseId
            );
            foreach ($comboRows as $row) {
                // null (unlimited) must survive, so no `??` here.
                $qty = array_key_exists((int) $row->product_id, $available) ? $available[(int) $row->product_id] : 0.0;
                $row->qte = $qty ?? 0.0;
                $row->combo_unlimited = $qty === null;
            }
        }
    }

    /**
     * What switching the setting to $target would put at risk, or null.
     * Shown in the confirmation the settings pages ask for — never a block.
     *
     * Documents don't record which mode moved their stock: an edit, delete or
     * return always reverses a line the CURRENT way. So switching (either
     * direction) is risky while a completed sale or received sales return
     * carries a combo, and turning it on while combos still hold assembled
     * kit stock leaves that stock ignored (disassemble it first with an
     * Adjustment "subtract").
     */
    public function switchRiskReason(bool $target): ?string
    {
        if ($target === self::enabled()) {
            return null;
        }

        $saleRef = DB::table('sale_details')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->where('products.type', 'is_combo')
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->value('sales.Ref');
        if ($saleRef) {
            return "Combo products already appear in completed sales (e.g. {$saleRef}). Their stock was moved the current way, and editing, deleting or returning them after this switch would reverse it the wrong way.";
        }

        $returnRef = DB::table('sale_return_details')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_details.sale_return_id')
            ->join('products', 'products.id', '=', 'sale_return_details.product_id')
            ->where('products.type', 'is_combo')
            ->whereNull('sale_returns.deleted_at')
            ->where('sale_returns.statut', 'received')
            ->value('sale_returns.Ref');
        if ($returnRef) {
            return "Combo products already appear in received sales returns (e.g. {$returnRef}). Editing or deleting them after this switch would reverse their stock the wrong way.";
        }

        if ($target) {
            $assembled = DB::table('product_warehouse')
                ->join('products', 'products.id', '=', 'product_warehouse.product_id')
                ->where('products.type', 'is_combo')
                ->whereNull('product_warehouse.deleted_at')
                ->whereNull('products.deleted_at')
                ->where('product_warehouse.qte', '!=', 0)
                ->distinct()
                ->pluck('products.name');
            if ($assembled->isNotEmpty()) {
                return 'These combos still hold assembled stock: '.$assembled->take(5)->implode(', ').($assembled->count() > 5 ? '…' : '').'. Virtual combo stock will ignore it — remove it first with an Adjustment "subtract" (that puts the components back).';
            }

            // Serial / IMEI units recorded inside kits would be stranded:
            // virtual combos can't be disassembled, and nothing sells them.
            $kitUnits = \Illuminate\Support\Facades\Schema::hasTable('product_serials')
                ? DB::table('product_serials')->where('status', 'in_kit')->count()
                : 0;
            if ($kitUnits > 0) {
                return "{$kitUnits} serial-numbered unit(s) are still recorded inside assembled kits. Disassemble those kits first with an Adjustment \"subtract\" (pick their serials) so the units return to stock.";
            }
        }

        return null;
    }

    /**
     * Drop combos from a stock-alert / stock-health query while stock is
     * virtual: their own row stays at 0 forever, so they would sit in every
     * low-stock list — their real stock is on the components, which keep
     * their own alerts. $productsTable is the products table name or alias
     * used by the query.
     */
    public static function excludeVirtualCombos($query, string $productsTable = 'products')
    {
        if (self::enabled()) {
            $query->where($productsTable.'.type', '!=', 'is_combo');
        }

        return $query;
    }

    /**
     * Move the components of a virtual combo instead of the combo's own row.
     * $comboDelta is in combo base units: negative takes stock (a sale),
     * positive puts it back (an edit or a delete). Returns false — nothing
     * moved — when the setting is off or the product is not a combo with
     * components, so the caller moves the product's own row as before.
     *
     * Components move exactly like a sold single product: every existing
     * stock row moves, "Always in stock" or not.
     */
    public function moveComponents(int $productId, int $warehouseId, float $comboDelta): bool
    {
        if (! self::enabled() || ! $this->isCombo($productId)) {
            return false;
        }

        $recipe = $this->recipes([$productId])[$productId] ?? [];
        if (! $recipe) {
            return false;
        }

        $rows = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', array_keys($recipe))
            ->whereNull('product_variant_id')
            ->lockForUpdate()
            ->get();
        foreach ($rows as $row) {
            $row->qte = (float) $row->qte + $recipe[(int) $row->product_id] * $comboDelta;
            $row->save();
        }

        return true;
    }

    /**
     * Unit-converted base quantity of a sale line, the way the sale
     * controllers convert it ('/' divides, anything else multiplies).
     */
    public static function baseQty(float $qty, $unit): float
    {
        if (! $unit) {
            return $qty;
        }
        $value = (float) ($unit->operator_value ?: 1);

        return $unit->operator == '/' ? $qty / $value : $qty * $value;
    }

    /**
     * Stock-movement documents (adjustment, damage, transfer) can't carry a
     * combo while stock is virtual: it has no stock of its own to move, and an
     * Adjustment "add" would consume the components into a row nobody reads.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assertNoVirtualCombos(array $details): void
    {
        if (! self::enabled()) {
            return;
        }

        $ids = array_values(array_filter(array_map(fn ($d) => (int) ($d['product_id'] ?? 0), $details)));
        $names = $ids ? DB::table('products')->whereIn('id', $ids)->where('type', 'is_combo')->pluck('name')->all() : [];
        if ($names) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'details' => ['Combo products have no stock of their own while virtual combo stock is on — move their component products instead: '.implode(', ', $names).'.'],
            ]);
        }
    }

    private function isCombo(int $productId): bool
    {
        return $this->types[$productId] ??= (string) DB::table('products')->where('id', $productId)->value('type') === 'is_combo';
    }

    /** Per-request cache of product id => is combo. */
    private array $types = [];

    /**
     * Combos that contain any of the given products as a component.
     *
     * @param  int[]  $componentIds
     * @return int[]
     */
    public function combosUsing(array $componentIds): array
    {
        if (! $componentIds) {
            return [];
        }

        return DB::table('combined_products')
            ->whereIn('combined_product_id', $componentIds)
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Stock per component over the warehouses; null = never limits a combo.
     * Components without a stock row in those warehouses are left out.
     *
     * @return array<int, float|null>
     */
    private function componentStock(array $componentIds, array $warehouseIds): array
    {
        if (! $componentIds || ! $warehouseIds) {
            return [];
        }

        $unlimited = array_flip(DB::table('products')
            ->whereIn('id', $componentIds)
            ->where(fn ($q) => $q->where('always_in_stock', 1)->orWhere('type', 'is_service'))
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all());

        $stock = [];
        DB::table('product_warehouse')
            ->whereIn('product_id', $componentIds)
            ->whereIn('warehouse_id', array_map('intval', $warehouseIds))
            ->whereNull('product_variant_id')
            ->whereNull('deleted_at')
            ->get(['product_id', 'qte', 'manage_stock'])
            ->each(function ($r) use (&$stock, $unlimited) {
                $id = (int) $r->product_id;
                if (isset($unlimited[$id]) || ! $r->manage_stock) {
                    $stock[$id] = null;
                } elseif (! array_key_exists($id, $stock) || $stock[$id] !== null) {
                    $stock[$id] = ($stock[$id] ?? 0.0) + (float) $r->qte;
                }
            });

        foreach (array_keys($unlimited) as $id) {
            $stock[$id] = null;
        }

        return $stock;
    }
}
