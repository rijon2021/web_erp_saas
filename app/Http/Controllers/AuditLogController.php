<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Audit\AuditRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > Audit Log (plan feature `audit_log`, permission `audit_log`).
 *
 *   GET audit_logs   list (event, subject_type, subject_id, user_id, batch,
 *                    from, to, search) + per-event counts + filter options +
 *                    `refs` (names for the foreign keys on this page)
 *
 * Rows are written by App\Services\Audit\AuditRecorder only; there is no
 * edit or delete endpoint on purpose.
 */
class AuditLogController extends BaseController
{
    protected const SORTABLE = [
        'created_at' => 'id',
        'user_name' => 'user_name',
        'event' => 'event',
        'subject_type' => 'subject_type',
    ];

    public const EVENTS = ['created', 'updated', 'deleted', 'restored', 'login', 'logout', 'login_failed'];

    /** Foreign keys shown as names: column => [table, name column]. */
    protected const REFS = [
        'client_id' => ['clients', 'name'],
        'provider_id' => ['providers', 'name'],
        'warehouse_id' => ['warehouses', 'name'],
        'from_warehouse_id' => ['warehouses', 'name'],
        'to_warehouse_id' => ['warehouses', 'name'],
        'user_id' => ['users', 'username'],
        'seller_id' => ['users', 'username'],
        'category_id' => ['categories', 'name'],
        'brand_id' => ['brands', 'name'],
        'unit_id' => ['units', 'name'],
        'unit_sale_id' => ['units', 'name'],
        'unit_purchase_id' => ['units', 'name'],
        'account_id' => ['accounts', 'account_name'],
        'from_account_id' => ['accounts', 'account_name'],
        'to_account_id' => ['accounts', 'account_name'],
        'default_account_id' => ['accounts', 'account_name'],
        'payment_method_id' => ['payment_methods', 'name'],
        'default_payment_method_id' => ['payment_methods', 'name'],
        'role_id' => ['roles', 'name'],
        'currency_id' => ['currencies', 'name'],
        'expense_category_id' => ['expense_categories', 'name'],
        'deposit_category_id' => ['deposit_categories', 'title'],
        'sale_id' => ['sales', 'Ref'],
        'purchase_id' => ['purchases', 'Ref'],
        'sale_return_id' => ['sale_returns', 'Ref'],
        'purchase_return_id' => ['purchase_returns', 'Ref'],
    ];

    /** Child records shown in a parent's history: type => [[child type, table, foreign key]]. */
    protected const RELATED = [
        'sale' => [['sale_payment', 'payment_sales', 'sale_id']],
        'purchase' => [['purchase_payment', 'payment_purchases', 'purchase_id']],
        'sale_return' => [['sale_return_payment', 'payment_sale_returns', 'sale_return_id']],
        'purchase_return' => [['purchase_return_payment', 'payment_purchase_returns', 'purchase_return_id']],
    ];

    /**
     * Child entries: through the live table (payments are soft-deleted, so
     * their ids survive) or the foreign key recorded in the entry itself.
     */
    private function whereChildOf($q, string $childType, string $table, string $fk, int $parentId)
    {
        $q->where('subject_type', $childType)->where(function ($qq) use ($table, $fk, $parentId) {
            if (Schema::hasTable($table)) {
                $qq->whereIn('subject_id', fn ($sub) => $sub->select('id')->from($table)->where($fk, $parentId));
            }
            $qq->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.{$fk}')) = ?", [(string) $parentId])
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(old_values, '$.{$fk}')) = ?", [(string) $parentId]);
        });
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'audit_log', Setting::class);

        if (! Schema::hasTable('audit_logs')) {
            return response()->json([
                'ready' => false, 'rows' => [], 'totalRows' => 0, 'event_counts' => (object) [],
                'users' => [], 'types' => array_keys(AuditRegistry::MODELS), 'refs' => (object) [],
            ]);
        }

        $query = DB::table('audit_logs');

        // Record history with its child records (?related=1: a sale's
        // payments…) — used by the History button on record pages.
        $type = (string) $request->input('subject_type', '');
        $id = (int) $request->input('subject_id', 0);
        $withChildren = $request->boolean('related') && $type !== '' && $id > 0 && isset(self::RELATED[$type]);
        if ($withChildren) {
            $query->where(function ($q) use ($type, $id) {
                $q->where(fn ($own) => $own->where('subject_type', $type)->where('subject_id', $id));
                foreach (self::RELATED[$type] as [$childType, $table, $fk]) {
                    $q->orWhere(fn ($child) => $this->whereChildOf($child, $childType, $table, $fk, $id));
                }
            });
        }

        foreach (['subject_type', 'batch'] as $column) {
            if ($request->filled($column) && ! ($withChildren && $column === 'subject_type')) {
                $query->where($column, (string) $request->input($column));
            }
        }
        foreach (['subject_id', 'user_id'] as $column) {
            if ($request->filled($column) && ! ($withChildren && $column === 'subject_id')) {
                $query->where($column, (int) $request->input($column));
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from').' 00:00:00');
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to').' 23:59:59');
        }
        if ($request->filled('search')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($q) use ($like) {
                $q->where('subject_label', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('impersonator', 'like', $like)
                    ->orWhere('ip_address', 'like', $like);
            });
        }

        // Counts per event across the current filters (not just this page).
        $eventCounts = (clone $query)->select('event', DB::raw('COUNT(*) as n'))
            ->groupBy('event')->pluck('n', 'event');

        if ($request->filled('event')) {
            $query->where('event', (string) $request->input('event'));
        }

        $total = (clone $query)->count();

        $sortColumn = self::SORTABLE[$request->input('SortField')] ?? 'id';
        $sortDir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        // Excel export pulls the filtered list in one go.
        $limit = max(1, min($request->boolean('export') ? 5000 : 200, (int) $request->input('limit', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('id', $sortDir)
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->old_values = $row->old_values ? json_decode($row->old_values, true) : null;
                $row->new_values = $row->new_values ? json_decode($row->new_values, true) : null;

                return $row;
            });

        $users = DB::table('audit_logs')->whereNotNull('user_id')
            ->select('user_id as id', DB::raw('MAX(user_name) as name'))
            ->groupBy('user_id')->orderBy('name')->limit(500)->get();

        return response()->json([
            'ready' => true,
            'rows' => $rows,
            'totalRows' => $total,
            'event_counts' => (object) $eventCounts->map(fn ($n) => (int) $n)->all(),
            'users' => $users,
            'types' => array_keys(AuditRegistry::MODELS),
            'refs' => (object) $this->refs($rows),
        ]);
    }

    /**
     * Names for every foreign key that appears in this page's values, so the
     * page shows "Main warehouse" instead of 3. Rows that no longer exist
     * (or are soft-deleted) still resolve — history must stay readable.
     *
     * @return array<string, array<int|string, string>> column => [id => name]
     */
    private function refs($rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ([$row->old_values, $row->new_values] as $values) {
                foreach ((array) $values as $column => $value) {
                    if (isset(self::REFS[$column]) && is_numeric($value)) {
                        $ids[$column][(int) $value] = true;
                    }
                }
            }
        }

        // One query per table, however many columns point at it.
        $byTable = [];
        foreach ($ids as $column => $set) {
            [$table, $name] = self::REFS[$column];
            $byTable["$table.$name"] = array_merge($byTable["$table.$name"] ?? [], array_keys($set));
        }

        $names = [];
        foreach ($byTable as $key => $tableIds) {
            [$table, $name] = explode('.', $key, 2);
            try {
                $names[$key] = DB::table($table)->whereIn('id', array_unique($tableIds))->pluck($name, 'id')->all();
            } catch (\Throwable) {
                $names[$key] = [];
            }
        }

        $refs = [];
        foreach ($ids as $column => $set) {
            [$table, $name] = self::REFS[$column];
            foreach (array_keys($set) as $id) {
                if (isset($names["$table.$name"][$id])) {
                    $refs[$column][$id] = (string) $names["$table.$name"][$id];
                }
            }
        }

        return $refs;
    }
}
