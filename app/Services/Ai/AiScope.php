<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Models\UserWarehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What one user may see — the same rules every report applies: assigned
 * warehouses (unless is_all_warehouses), own records only when record_view
 * is off, and per-module view permissions. Every AI data tool runs inside
 * this scope, so the assistant can never reveal more than the user's own
 * screens would.
 */
class AiScope
{
    public readonly array $warehouseIds;

    public readonly bool $allRecords;

    private array $permissions;

    public function __construct(public readonly User $user)
    {
        $this->allRecords = (bool) $user->hasRecordView();
        $this->permissions = $user->roles()->first()?->permissions->pluck('name')->all() ?? [];

        $this->warehouseIds = $user->is_all_warehouses
            ? DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
    }

    public function can(string ...$anyOf): bool
    {
        return (bool) array_intersect($anyOf, $this->permissions);
    }

    /** Restrict to one warehouse when asked (and allowed), else all visible ones. */
    public function warehouses(?int $warehouseId): array
    {
        if ($warehouseId && in_array($warehouseId, $this->warehouseIds, true)) {
            return [$warehouseId];
        }

        return $this->warehouseIds ?: [0];
    }

    /** Warehouse + own-records filter for a document table (sales, purchases…). */
    public function applyDocument(Builder $q, string $table, ?int $warehouseId, bool $hasUser = true): Builder
    {
        $q->whereNull("$table.deleted_at")->whereIn("$table.warehouse_id", $this->warehouses($warehouseId));
        if ($hasUser && ! $this->allRecords) {
            $q->where("$table.user_id", $this->user->id);
        }

        return $q;
    }

    /** Stable key for caching per-scope results (dashboard insights). */
    public function key(): string
    {
        $wh = $this->warehouseIds;
        sort($wh);

        return md5(implode(',', $wh).'|'.($this->allRecords ? 'all' : 'u'.$this->user->id).'|'.implode(',', array_filter([
            $this->can('Sales_view', 'Reports_sales') ? 's' : null,
            $this->can('Purchases_view', 'Reports_purchase') ? 'p' : null,
            $this->can('expense_view', 'expenses_report') ? 'e' : null,
            $this->can('products_view', 'Reports_quantity_alerts', 'stock_report') ? 'k' : null,
            $this->can('Customers_view', 'Reports_customers') ? 'c' : null,
        ])));
    }
}
