<?php

namespace App\Traits;

use App\Models\UserWarehouse;
use App\Models\Warehouse;

/**
 * Warehouse-level row scoping for tenant users.
 *
 * A user with `is_all_warehouses = 0` may only see and touch data belonging to
 * the warehouses assigned to them in `user_warehouse`. Controllers used to
 * re-derive that list inline (and several only applied it to the warehouse
 * dropdown, leaving the data query wide open), so the rules live here instead.
 *
 * Two shapes are offered because both are already in use across the codebase:
 *  - userWarehouseIds() always returns a concrete id list;
 *  - warehouseScopeIds() returns null for unrestricted users, so a query can
 *    skip the whereIn entirely.
 */
trait ScopesWarehouseAccess
{
    /** Per-request memo — the assignment cannot change mid request. */
    private ?array $warehouseAccessMemo = null;

    /**
     * Warehouse ids the authenticated user may access. Deleted warehouses are
     * never included. An unauthenticated request gets an empty list.
     */
    protected function userWarehouseIds(): array
    {
        if ($this->warehouseAccessMemo !== null) {
            return $this->warehouseAccessMemo;
        }

        $user = auth()->user();

        if (! $user) {
            return $this->warehouseAccessMemo = [];
        }

        if ($user->is_all_warehouses) {
            return $this->warehouseAccessMemo = Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->warehouseAccessMemo = UserWarehouse::where('user_id', $user->id)
            ->whereIn('warehouse_id', Warehouse::whereNull('deleted_at')->select('id'))
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Same list, but null when the user is unrestricted — lets a query do
     * ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids)).
     */
    protected function warehouseScopeIds(): ?array
    {
        $user = auth()->user();

        if ($user && $user->is_all_warehouses) {
            return null;
        }

        return $this->userWarehouseIds();
    }

    /**
     * Apply the warehouse scope to a query column.
     *
     * $includeGlobal keeps rows whose warehouse column is NULL — used by
     * records that may be defined once for the whole company (a BOM or work
     * center with no warehouse belongs to everyone, not to nobody).
     */
    protected function scopeToWarehouses($query, string $column, bool $includeGlobal = false)
    {
        $ids = $this->warehouseScopeIds();

        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $column, $includeGlobal) {
            $q->whereIn($column, $ids);
            if ($includeGlobal) {
                $q->orWhereNull($column);
            }
        });
    }

    /**
     * Scope a query on a record that spans two warehouses (a transfer): the
     * caller sees it when either end is theirs. A NULL end never matches on
     * its own, but does not hide a row whose other end is in scope.
     */
    protected function scopeToWarehousesEitherEnd($query, string $fromColumn, string $toColumn)
    {
        $ids = $this->warehouseScopeIds();

        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $fromColumn, $toColumn) {
            $q->whereIn($fromColumn, $ids)->orWhereIn($toColumn, $ids);
        });
    }

    /**
     * Whether master data (products, customers, suppliers, brands, categories)
     * should also be filtered by the user's warehouses.
     *
     * Off by default. Unlike documents — which carry a warehouse_id and are
     * ALWAYS restricted — master data has no warehouse of its own, so whether a
     * customer "belongs" to a branch is a business policy rather than a fact.
     * Some companies run one shared customer book across every branch; others
     * treat each branch separately. Settings -> Features decides.
     */
    protected function restrictsMasterDataByWarehouse(): bool
    {
        if ($this->masterDataScopeMemo !== null) {
            return $this->masterDataScopeMemo;
        }

        // A user who may see every warehouse is never restricted, so the
        // setting does not even need reading.
        if ($this->warehouseScopeIds() === null) {
            return $this->masterDataScopeMemo = false;
        }

        $setting = \App\Models\Setting::current();

        return $this->masterDataScopeMemo = (bool) ($setting->restrict_master_data_by_warehouse ?? false);
    }

    /** Per-request memo for the setting lookup above. */
    private ?bool $masterDataScopeMemo = null;

    /**
     * Restrict a master-data list to rows connected to the user's warehouses.
     *
     * $subquery receives the warehouse id list (or null for "any warehouse")
     * and returns a correlated query — sales for a customer, product_warehouse
     * rows for a product, and so on.
     *
     * Rows with NO connection anywhere stay visible to everyone. That is
     * deliberate: a customer created a minute ago has no sales yet, and hiding
     * them from the person who just created them would be indefensible. A row
     * becomes scoped as soon as it is used somewhere.
     *
     * @param  callable(?list<int>): \Illuminate\Database\Query\Builder  $subquery
     */
    protected function scopeMasterDataToWarehouses($query, callable $subquery)
    {
        if (! $this->restrictsMasterDataByWarehouse()) {
            return $query;
        }

        $ids = $this->userWarehouseIds();

        return $query->where(function ($q) use ($ids, $subquery) {
            $q->whereExists($subquery($ids))
                ->orWhereNotExists($subquery(null));
        });
    }

    /** Whether the user may access the given warehouse. */
    protected function canAccessWarehouse($warehouseId): bool
    {
        if (! $warehouseId) {
            return false;
        }

        return in_array((int) $warehouseId, $this->userWarehouseIds(), true);
    }

    /**
     * Normalise a client-supplied warehouse filter: returns the id when the
     * user may see it, null otherwise. A rejected filter falls back to the
     * user's full allowed scope rather than leaking another warehouse.
     */
    protected function filterWarehouseId($warehouseId): ?int
    {
        $warehouseId = (int) $warehouseId;

        return $warehouseId && $this->canAccessWarehouse($warehouseId) ? $warehouseId : null;
    }

    /**
     * Warehouse gate for a document opened by id (sale, purchase, quotation,
     * return, transfer, adjustment, damage, expense...).
     *
     * This is the other half of the `check_record` ownership test: record_view
     * decides WHOSE documents a user may open, this decides WHICH warehouses
     * they may come from, and both apply. Documents that carry no warehouse
     * column are left alone.
     */
    protected function abortIfDocumentWarehouseDenied($document): void
    {
        if (! $document) {
            return;
        }

        if (isset($document->from_warehouse_id) || isset($document->to_warehouse_id)) {
            $this->abortIfTransferDenied($document->from_warehouse_id ?? null, $document->to_warehouse_id ?? null);

            return;
        }

        if (isset($document->warehouse_id)) {
            $this->abortIfWarehouseDenied($document->warehouse_id);

            return;
        }

        // Payments carry no warehouse of their own — they inherit the one on
        // the document they settle.
        foreach (self::PARENT_DOCUMENT_KEYS as $foreignKey => $class) {
            if (empty($document->{$foreignKey}) || ! class_exists($class)) {
                continue;
            }

            $parent = $class::find($document->{$foreignKey});
            if ($parent && isset($parent->warehouse_id)) {
                $this->abortIfWarehouseDenied($parent->warehouse_id);
            }

            return;
        }
    }

    /** Documents a payment can belong to, by foreign key. */
    private const PARENT_DOCUMENT_KEYS = [
        'sale_id' => \App\Models\Sale::class,
        'purchase_id' => \App\Models\Purchase::class,
        'sale_return_id' => \App\Models\SaleReturn::class,
        'purchase_return_id' => \App\Models\PurchaseReturn::class,
        'quotation_id' => \App\Models\Quotation::class,
    ];

    /**
     * A transfer straddles two warehouses; being assigned to either end is
     * enough to see it, which matches how the transfer lists are scoped.
     */
    protected function abortIfTransferDenied($fromWarehouseId, $toWarehouseId): void
    {
        if (! $this->canAccessWarehouse($fromWarehouseId) && ! $this->canAccessWarehouse($toWarehouseId)) {
            abort(403, 'You are not assigned to either warehouse of this transfer.');
        }
    }

    /**
     * Hard stop for endpoints addressed by warehouse (a route segment or a
     * required parameter) where silently widening the scope would be wrong.
     */
    protected function abortIfWarehouseDenied($warehouseId): void
    {
        if (! $this->canAccessWarehouse($warehouseId)) {
            abort(403, 'You are not assigned to this warehouse.');
        }
    }

    // ------------------------------------------------------------------
    // Warehouse-owned products (Settings → Features → Restrict products by
    // warehouse). Every product gets a stock row in EVERY warehouse when it
    // is created, so the master-data rule above ("has a row in my
    // warehouse") cannot tell whose product it is. products.warehouse_id
    // records the home warehouse instead; see Product::scopeVisibleInWarehouses
    // for the visibility rule.
    // ------------------------------------------------------------------

    /** Per-request memo for the setting lookup below. */
    private ?bool $productScopeMemo = null;

    /** Whether the toggle is on AND the current user is warehouse-restricted. */
    protected function restrictsProductsByWarehouse(): bool
    {
        if ($this->productScopeMemo !== null) {
            return $this->productScopeMemo;
        }

        if ($this->warehouseScopeIds() === null) {
            return $this->productScopeMemo = false;
        }

        $setting = \App\Models\Setting::current();

        return $this->productScopeMemo = (bool) ($setting->restrict_products_by_warehouse ?? false);
    }

    /** Per-request memo for the setting-only lookup below. */
    private ?bool $productOwnershipMemo = null;

    /** Whether the Features toggle is on, regardless of who is asking. */
    protected function productOwnershipEnabled(): bool
    {
        if ($this->productOwnershipMemo !== null) {
            return $this->productOwnershipMemo;
        }

        $setting = \App\Models\Setting::current();

        return $this->productOwnershipMemo = (bool) ($setting->restrict_products_by_warehouse ?? false);
    }

    /**
     * Drop the stock rows that exist only to satisfy the "one row per
     * warehouse" invariant. While products belong to a warehouse, a product's
     * EMPTY row in a foreign warehouse is not stock: it must not raise a
     * low-stock alert there, appear in that warehouse's stock count, or make
     * the product show up when the list is filtered by that warehouse. A row
     * that actually holds stock (a transfer) still counts, and a product with
     * no home warehouse keeps the old behaviour everywhere.
     *
     * The query must already join products onto product_warehouse; $stock and
     * $products are the aliases those tables are reachable under.
     */
    protected function scopeStockRowsToOwnership($query, string $stock = 'product_warehouse', string $products = 'products')
    {
        if (! $this->productOwnershipEnabled()) {
            return $query;
        }

        return $query->where(function ($q) use ($stock, $products) {
            $q->whereNull($products.'.warehouse_id')
                ->orWhereColumn($products.'.warehouse_id', $stock.'.warehouse_id')
                ->orWhere($stock.'.qte', '>', 0);
        });
    }

    /**
     * Restrict a query over products (Eloquent or DB builder; $table is the
     * alias the products columns are reachable under) to those the current
     * user may see. No-op when the toggle is off or the user is unrestricted.
     */
    protected function scopeProductsToWarehouses($query, string $table = 'products')
    {
        if (! $this->restrictsProductsByWarehouse()) {
            return $query;
        }

        $ids = $this->userWarehouseIds();

        return $query->where(function ($q) use ($ids, $table) {
            $q->whereIn($table.'.warehouse_id', $ids)
                ->orWhereExists(function ($e) use ($ids, $table) {
                    $e->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('product_warehouse')
                        ->whereColumn('product_warehouse.product_id', $table.'.id')
                        ->whereNull('product_warehouse.deleted_at')
                        ->whereIn('product_warehouse.warehouse_id', $ids)
                        ->where('product_warehouse.qte', '>', 0);
                });
        });
    }

    /**
     * Brands / categories: keep rows that have at least one product the user
     * may see, or no product at all (a brand created a minute ago is never
     * hidden from its author). Active under EITHER Features switch: the
     * product-ownership rule when that one is on, otherwise the older
     * "stocked in my warehouse" rule from scopeMasterDataToWarehouses().
     *
     * @param  callable(\Illuminate\Database\Query\Builder): void  $link  adds the
     *         whereColumn joining products to the outer row
     */
    protected function scopeMasterDataToProducts($query, callable $link)
    {
        if (! $this->restrictsProductsByWarehouse()) {
            return $this->scopeMasterDataToWarehouses($query, function ($ids) use ($link) {
                $sub = \Illuminate\Support\Facades\DB::table('products')
                    ->join('product_warehouse', 'product_warehouse.product_id', '=', 'products.id')
                    ->whereNull('products.deleted_at')
                    ->whereNull('product_warehouse.deleted_at')
                    ->when($ids !== null, fn ($q) => $q->whereIn('product_warehouse.warehouse_id', $ids));
                $link($sub);

                return $sub;
            });
        }

        $any = \Illuminate\Support\Facades\DB::table('products')->whereNull('products.deleted_at');
        $link($any);

        $visible = $this->scopeProductsToWarehouses(
            \Illuminate\Support\Facades\DB::table('products')->whereNull('products.deleted_at')
        );
        $link($visible);

        return $query->where(function ($q) use ($any, $visible) {
            $q->whereExists($visible)->orWhereNotExists($any);
        });
    }

    /** Whether the current user may see the given product under the toggle. */
    protected function canAccessProduct($product): bool
    {
        if (! $product || ! $this->restrictsProductsByWarehouse()) {
            return true;
        }

        return \App\Models\Product::where('products.id', $product->id)
            ->visibleInWarehouses($this->userWarehouseIds())
            ->exists();
    }

    /** Hard stop for a product opened by id (view, edit, delete, duplicate). */
    protected function abortIfProductDenied($product): void
    {
        if (! $this->canAccessProduct($product)) {
            abort(403, 'This product belongs to another warehouse.');
        }
    }

    /**
     * Home warehouse for a product being created or re-assigned.
     *
     * A requested warehouse must be one the user may access. With none
     * requested: an unrestricted user leaves the product unassigned (visible
     * to unrestricted users only while the toggle is on); a restricted user
     * with a single warehouse gets it automatically; one with several must
     * choose when the toggle is on ($strict), or gets the first one when the
     * caller cannot ask (imports).
     */
    protected function productHomeWarehouseFor($requested, bool $strict = true): ?int
    {
        $requested = (int) $requested;

        if ($requested) {
            if (! $this->canAccessWarehouse($requested)) {
                abort(403, 'You are not assigned to this warehouse.');
            }

            return $requested;
        }

        $ids = $this->userWarehouseIds();

        if ($this->warehouseScopeIds() === null) {
            return null;
        }

        if (count($ids) === 1 || (! $strict && $ids)) {
            return $ids[0];
        }

        if ($strict && $this->restrictsProductsByWarehouse()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'warehouse_id' => ['Choose the warehouse this product belongs to.'],
            ]);
        }

        return null;
    }
}
