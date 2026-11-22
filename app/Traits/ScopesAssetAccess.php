<?php

namespace App\Traits;

use App\Models\Asset;

/**
 * Warehouse visibility for the Asset Management module.
 *
 * An asset sits in a warehouse, or in none (company-wide). A warehouse-
 * restricted user sees the assets of their warehouses plus the company-wide
 * ones, and every log hanging off an asset (custody, maintenance) follows the
 * asset. Built on ScopesWarehouseAccess, which BaseController already uses.
 */
trait ScopesAssetAccess
{
    /** Restrict a query on a table carrying an asset id column to visible assets. */
    protected function scopeToVisibleAssets($query, string $assetIdColumn)
    {
        $ids = $this->warehouseScopeIds();

        return $ids === null ? $query : $query->whereIn($assetIdColumn, Asset::visibleIds($ids));
    }

    /** Hard stop for an asset opened, changed or logged against by id. */
    protected function abortIfAssetDenied($asset): void
    {
        if ($asset && ! $asset->isVisibleIn($this->warehouseScopeIds())) {
            abort(403, 'This asset belongs to a warehouse you are not assigned to.');
        }
    }

    /** Same, for a log row (assignment / maintenance) via its asset_id. */
    protected function abortIfAssetIdDenied($assetId): void
    {
        if ($this->warehouseScopeIds() !== null) {
            $this->abortIfAssetDenied(Asset::find($assetId));
        }
    }

    /** A restricted user may only place an asset in one of their own warehouses. */
    protected function abortIfAssetWarehouseDenied($warehouseId): void
    {
        if ($warehouseId && $this->warehouseScopeIds() !== null) {
            $this->abortIfWarehouseDenied($warehouseId);
        }
    }
}
