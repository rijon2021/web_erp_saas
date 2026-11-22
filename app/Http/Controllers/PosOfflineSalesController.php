<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Models\PosOfflineSale;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\OfflineSaleRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sales > Offline Sales — every POS sale a till rang up offline and later
 * replayed (recorded by App\Services\Pos\OfflineSaleRecorder from
 * PosController::CreatePOS), for all tills.
 *
 *   GET  pos/offline_sales                 list + status counts + filter options
 *   GET  pos/offline_sales/{id}            one sale: lines (with stock now), payments
 *   POST pos/offline_sales/{id}/retry      replay it as the original cashier
 *   POST pos/offline_sales/{id}/discard    give up on it (the till drops its copy)
 *   POST pos/offline_sales/statuses        till-side: which of my queued uuids were
 *                                          synced or discarded from here
 */
class PosOfflineSalesController extends BaseController
{
    protected const SORTABLE = [
        'id' => 'o.id',
        'offline_created_at' => 'o.offline_created_at',
        'last_attempt_at' => 'o.last_attempt_at',
        'grand_total' => 'o.grand_total',
        'status' => 'o.status',
        'attempts' => 'o.attempts',
        'cashier_name' => 'u.username',
        'warehouse_name' => 'w.name',
        'client_name' => 'c.name',
    ];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_offline_sales', Sale::class);

        if (! OfflineSaleRecorder::ready()) {
            return response()->json([
                'ready' => false, 'rows' => [], 'totalRows' => 0, 'status_counts' => (object) [],
                'warehouses' => [], 'cashiers' => [],
            ]);
        }

        $query = DB::table('pos_offline_sales as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->leftJoin('clients as c', 'c.id', '=', 'o.client_id')
            ->leftJoin('sales as s', 's.id', '=', 'o.sale_id');
        $this->scopeToWarehouses($query, 'o.warehouse_id');

        if ($warehouseId = $this->filterWarehouseId($request->input('warehouse_id'))) {
            $query->where('o.warehouse_id', $warehouseId);
        }
        if ($request->filled('user_id')) {
            $query->where('o.user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('from')) {
            $query->whereRaw('DATE(COALESCE(o.offline_created_at, o.created_at)) >= ?', [$request->input('from')]);
        }
        if ($request->filled('to')) {
            $query->whereRaw('DATE(COALESCE(o.offline_created_at, o.created_at)) <= ?', [$request->input('to')]);
        }
        if ($request->filled('search')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($q) use ($like) {
                $q->where('o.sale_uuid', 'like', $like)
                    ->orWhere('c.name', 'like', $like)
                    ->orWhere('s.Ref', 'like', $like)
                    ->orWhere('u.username', 'like', $like)
                    ->orWhere('o.error_message', 'like', $like);
            });
        }

        // Counts per status across the current filters (not just this page).
        $statusCounts = (clone $query)->select('o.status', DB::raw('COUNT(*) as n'))
            ->groupBy('o.status')->pluck('n', 'o.status');

        if ($request->filled('status')) {
            $query->where('o.status', (string) $request->input('status'));
        }

        $total = (clone $query)->count('o.id');

        $sortColumn = self::SORTABLE[$request->input('SortField')] ?? 'o.id';
        $sortDir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min(200, (int) $request->input('limit', 10)));
        $page = max(1, (int) $request->input('page', 1));

        $rows = $query->select([
            'o.id', 'o.sale_uuid', 'o.status', 'o.error_message', 'o.status_code', 'o.attempts', 'o.grand_total',
            'o.offline_created_at', 'o.last_attempt_at', 'o.created_at', 'o.sale_id', 'o.resolution', 'o.resolved_at',
            'o.user_id', 'o.warehouse_id', 'o.client_id',
            'u.username as cashier_name', 'w.name as warehouse_name', 'c.name as client_name', 's.Ref as sale_ref',
        ])
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('o.id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $warehouseIds = $this->warehouseScopeIds();
        $warehouses = Warehouse::whereNull('deleted_at')
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('id', $warehouseIds))
            ->orderBy('name')->get(['id', 'name']);
        $cashiers = DB::table('users')
            ->whereIn('id', DB::table('pos_offline_sales')->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('username')->get(['id', 'username as name']);

        return response()->json([
            'ready' => true,
            'rows' => $rows,
            'totalRows' => $total,
            'status_counts' => (object) $statusCounts->map(fn ($n) => (int) $n)->all(),
            'warehouses' => $warehouses,
            'cashiers' => $cashiers,
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_offline_sales', Sale::class);
        $row = $this->findRow($id);
        $payload = (array) $row->payload;

        // Sale lines, with what the warehouse holds right now — for a stock
        // rejection that tells the manager whether a retry can succeed yet.
        $lines = [];
        foreach ((array) ($payload['details'] ?? []) as $d) {
            if (! is_array($d)) {
                continue;
            }
            $productId = $d['product_id'] ?? null;
            $variantId = ! empty($d['product_variant_id']) ? (int) $d['product_variant_id'] : null;
            $product = $productId ? DB::table('products')->where('id', $productId)->first(['name', 'code', 'type']) : null;
            $stockNow = null;
            if ($productId && $row->warehouse_id && $product && $product->type !== 'is_service') {
                $stockNow = (float) DB::table('product_warehouse')
                    ->where('product_id', $productId)->where('warehouse_id', $row->warehouse_id)->whereNull('deleted_at')
                    ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId), fn ($q) => $q->whereNull('product_variant_id'))
                    ->sum('qte');
            }
            $lines[] = [
                'product_id' => $productId,
                'name' => $d['name'] ?? ($product->name ?? ''),
                'code' => $d['code'] ?? ($product->code ?? ''),
                'quantity' => (float) ($d['quantity'] ?? 0),
                'unit_price' => (float) ($d['Unit_price'] ?? 0),
                'subtotal' => (float) ($d['subtotal'] ?? 0),
                'stock_now' => $stockNow,
            ];
        }

        $methods = PaymentMethod::pluck('name', 'id');
        $payments = [];
        foreach ((array) ($payload['payments'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $payments[] = [
                'method' => $methods[$p['payment_method_id'] ?? null] ?? ('#'.($p['payment_method_id'] ?? '?')),
                'amount' => (float) ($p['amount'] ?? 0),
            ];
        }

        $names = fn ($table, $column, $value) => $value ? DB::table($table)->where('id', $value)->value($column) : null;

        return response()->json([
            'sale' => [
                'id' => $row->id,
                'sale_uuid' => $row->sale_uuid,
                'status' => $row->status,
                'error_message' => $row->error_message,
                'status_code' => $row->status_code,
                'attempts' => $row->attempts,
                'grand_total' => $row->grand_total,
                'offline_created_at' => optional($row->offline_created_at)->toDateTimeString(),
                'last_attempt_at' => optional($row->last_attempt_at)->toDateTimeString(),
                'created_at' => optional($row->created_at)->toDateTimeString(),
                'sale_id' => $row->sale_id,
                'sale_ref' => $names('sales', 'Ref', $row->sale_id),
                'cashier_name' => $names('users', 'username', $row->user_id),
                'warehouse_name' => $names('warehouses', 'name', $row->warehouse_id),
                'client_name' => $names('clients', 'name', $row->client_id),
                'resolution' => $row->resolution,
                'resolved_at' => optional($row->resolved_at)->toDateTimeString(),
                'resolved_by_name' => $names('users', 'username', $row->resolved_by),
                'note' => $row->note,
                'device' => $row->device,
                'notes' => $payload['notes'] ?? null,
                'discount' => (float) ($payload['discount'] ?? 0),
                'shipping' => (float) ($payload['shipping'] ?? 0),
                'tax' => (float) ($payload['TaxNet'] ?? 0),
                'lines' => $lines,
                'payments' => $payments,
            ],
        ]);
    }

    /**
     * Replay a rejected sale through PosController::CreatePOS exactly as the
     * till would, but recorded under the cashier who rang it up (so cashier
     * and register totals stay right). Falls back to the manager when that
     * cashier account no longer exists or is disabled, and says so.
     */
    public function retry(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_offline_sales', Sale::class);
        $row = $this->findRow($id);

        if ($row->status === PosOfflineSale::STATUS_SYNCED) {
            return response()->json(['success' => true, 'status' => $row->status, 'sale_id' => $row->sale_id, 'message' => 'Already synced.']);
        }
        if ($row->status === PosOfflineSale::STATUS_DISCARDED) {
            return response()->json(['success' => false, 'status' => $row->status, 'message' => 'This sale was discarded.'], 422);
        }

        // One retry at a time per sale (double clicks, two managers).
        $lock = Cache::lock('pos-offline-retry:'.(tenant('id') ?? 'central').':'.$row->id, 120);
        if (! $lock->get()) {
            return response()->json(['success' => false, 'message' => 'A retry of this sale is already running.'], 409);
        }

        $manager = $request->user('api');
        $cashier = $row->user_id
            ? User::where('id', $row->user_id)->whereNull('deleted_at')->where('statut', 1)->first()
            : null;
        $actor = $cashier ?: $manager;

        $payload = (array) $row->payload;
        $payload['sale_uuid'] = $row->sale_uuid;
        $payload['offline_id'] = $payload['offline_id'] ?? ('retry-'.$row->id);
        if ($row->offline_created_at && empty($payload['offline_created_at'])) {
            $payload['offline_created_at'] = $row->offline_created_at->toIso8601String();
        }

        $replay = Request::create('/api/pos/create_pos', 'POST', $payload);
        $replay->headers->set('Accept', 'application/json');
        $replay->setUserResolver(fn () => $actor);

        $guard = Auth::guard('api');
        $guard->setUser($actor);
        $message = null;
        try {
            $response = app(PosController::class)->CreatePOS($replay);
            if ($response instanceof JsonResponse) {
                $data = (array) $response->getData(true);
                $message = $data['message'] ?? null;
            }
        } catch (ValidationException $e) {
            $message = OfflineSaleRecorder::validationMessage($e);
        } catch (AuthorizationException $e) {
            $message = $cashier
                ? 'The cashier who rang up this sale no longer has POS access or access to this warehouse.'
                : ($e->getMessage() ?: 'This action is unauthorized.');
        } catch (HttpExceptionInterface $e) {
            $message = $e->getMessage() ?: 'HTTP '.$e->getStatusCode();
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            $guard->setUser($manager);
            $lock->release();
        }

        $row->refresh();
        $synced = $row->status === PosOfflineSale::STATUS_SYNCED;
        $note = null;
        if ($synced) {
            $note = $cashier ? null : 'Recorded under '.($manager->username ?? 'the manager').' because the original cashier account is no longer active.';
            $row->forceFill([
                'resolved_by' => $manager->id,
                'resolved_at' => now(),
                'resolution' => 'retried',
                'note' => $note,
            ])->save();
        } elseif ($message && $row->error_message !== $message && $row->status === PosOfflineSale::STATUS_FAILED) {
            // A failure before CreatePOS could record it (e.g. authorization).
            $row->forceFill(['error_message' => $message, 'last_attempt_at' => now()])->save();
        }

        return response()->json([
            'success' => $synced,
            'status' => $row->status,
            'sale_id' => $row->sale_id,
            'message' => $synced ? ($note ?: 'Synced.') : ($row->error_message ?: $message ?: 'Failed'),
        ], $synced ? 200 : 422);
    }

    public function discard(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_offline_sales', Sale::class);
        $request->validate(['note' => 'nullable|string|max:500']);
        $row = $this->findRow($id);

        if ($row->status === PosOfflineSale::STATUS_SYNCED) {
            return response()->json(['success' => false, 'message' => 'This sale is already on the server and cannot be discarded.'], 422);
        }

        $row->forceFill([
            'status' => PosOfflineSale::STATUS_DISCARDED,
            'resolved_by' => $request->user('api')->id,
            'resolved_at' => now(),
            'resolution' => 'discarded',
            'note' => $request->input('note') ?: null,
        ])->save();

        return response()->json(['success' => true, 'status' => $row->status]);
    }

    /**
     * Till-side reconciliation: for the sale_uuids a till still holds as
     * failed, report the ones resolved here (synced by a manager's retry, or
     * discarded) so the till can drop its local copy.
     */
    public function statuses(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        $request->validate(['uuids' => 'required|array|max:500', 'uuids.*' => 'string|max:64']);

        if (! OfflineSaleRecorder::ready()) {
            return response()->json(['statuses' => (object) []]);
        }

        $uuids = array_values(array_unique(array_map('strval', $request->input('uuids', []))));
        $resolved = PosOfflineSale::whereIn('sale_uuid', $uuids)
            ->whereIn('status', [PosOfflineSale::STATUS_SYNCED, PosOfflineSale::STATUS_DISCARDED])
            ->get(['sale_uuid', 'status', 'sale_id']);

        // A sale created on the server by any path (e.g. it raced the record).
        $onServer = Sale::whereIn('sale_uuid', $uuids)->pluck('id', 'sale_uuid');

        $out = [];
        foreach ($resolved as $r) {
            $out[$r->sale_uuid] = ['status' => $r->status, 'sale_id' => $r->sale_id];
        }
        foreach ($onServer as $uuid => $saleId) {
            $out[$uuid] = ['status' => PosOfflineSale::STATUS_SYNCED, 'sale_id' => $saleId];
        }

        return response()->json(['statuses' => (object) $out]);
    }

    protected function findRow($id): PosOfflineSale
    {
        abort_unless(OfflineSaleRecorder::ready(), 404);
        $row = PosOfflineSale::findOrFail($id);
        if ($row->warehouse_id) {
            $this->abortIfWarehouseDenied($row->warehouse_id);
        }

        return $row;
    }
}
