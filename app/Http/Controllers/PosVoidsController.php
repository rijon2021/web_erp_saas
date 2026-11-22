<?php

namespace App\Http\Controllers;

use App\Models\PosVoid;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\PosApprovalPin;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POS manager approval for removing items (POS Settings > "Require manager
 * approval to remove items").
 *
 *   GET  pos/voids/approvers managers the till can ask (have a PIN, work here)
 *   POST pos/voids/approve   cashier: chosen manager + their PIN + the lines being
 *                            removed → {approved, approver}; the lines are logged
 *   POST pos/voids/log       user with pos_void_items removed lines themselves
 *                            (occurred_at when sent late from an offline till)
 *   GET  pos/voids           Reports > POS Voided Items (pos_void_items)
 *   POST pos/approval-pin    set / remove my own PIN (pos_void_items + password)
 *
 * The cart lives in the browser, so the till enforces the rule; the server
 * checks the PIN and keeps the record.
 */
class PosVoidsController extends BaseController
{
    // Wrong PINs per cashier before the till is locked out for DECAY seconds.
    protected const MAX_ATTEMPTS = 5;
    protected const DECAY = 300;
    // ...and at most this many wrong PINs per cashier per day.
    protected const MAX_PER_DAY = 20;
    // Wrong PINs per manager per day, from every till together.
    protected const MAX_PER_APPROVER_DAY = 30;

    // A queued offline removal (log) may be backdated this far.
    protected const MAX_BACKDATE_DAYS = 30;

    // decimal(15,4) columns.
    protected const MAX_AMOUNT = 99999999999;

    protected const SORTABLE = [
        'created_at' => 'v.created_at',
        'product_name' => 'v.product_name',
        'quantity' => 'v.quantity',
        'amount' => 'v.amount',
        'action' => 'v.action',
        'cashier_name' => 'cu.username',
        'approver_name' => 'au.username',
        'warehouse_name' => 'w.name',
    ];

    public function approve(Request $request)
    {
        $cashier = $request->user('api');
        $this->authorizeForUser($cashier, 'Sales_pos', Sale::class);
        $this->ensureReady();

        $data = $this->validateLines($request, true);

        // One check at a time per cashier: parallel requests would all pass the
        // limit check before any failure is counted (and the file cache store
        // does not increment atomically).
        $key = 'pos-void-pin:'.PosApprovalPin::scope().':'.$cashier->id;
        $dayKey = $key.':day';
        $lock = Cache::lock($key.':lock', 10);
        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            return response()->json(['message' => __('Too many attempts. Try again later.'), 'retry_after' => 5], 429);
        }

        try {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) || RateLimiter::tooManyAttempts($dayKey, self::MAX_PER_DAY)) {
                $seconds = max(RateLimiter::availableIn($key), RateLimiter::tooManyAttempts($dayKey, self::MAX_PER_DAY) ? RateLimiter::availableIn($dayKey) : 0);

                return response()->json([
                    'message' => __('Too many wrong PINs. Try again in :minutes min.', ['minutes' => max(1, (int) ceil($seconds / 60))]),
                    'retry_after' => $seconds,
                ], 429);
            }

            // Each manager's PIN also has its own daily budget across every till.
            $approverId = (int) $data['approver_id'];
            $approverKey = 'pos-void-pin-approver:'.PosApprovalPin::scope().':'.$approverId.':day';
            if (RateLimiter::tooManyAttempts($approverKey, self::MAX_PER_APPROVER_DAY)) {
                $seconds = RateLimiter::availableIn($approverKey);

                return response()->json([
                    'message' => __('This manager\'s PIN is locked after too many wrong tries. Try again in :minutes min.', ['minutes' => max(1, (int) ceil($seconds / 60))]),
                    'retry_after' => $seconds,
                    'locked' => 'approver',
                ], 429);
            }

            $approver = PosApprovalPin::verify($approverId, (string) $data['pin']);
            if (! $approver) {
                RateLimiter::hit($key, self::DECAY);
                RateLimiter::hit($dayKey, 86400);
                RateLimiter::hit($approverKey, 86400);
                Log::warning('POS approval: wrong manager PIN', ['cashier_id' => $cashier->id, 'approver_id' => $approverId]);

                return response()->json([
                    'message' => __('Wrong PIN, or this user cannot approve removals.'),
                    'attempts_left' => max(0, min(
                        self::MAX_ATTEMPTS - RateLimiter::attempts($key),
                        self::MAX_PER_DAY - RateLimiter::attempts($dayKey),
                        self::MAX_PER_APPROVER_DAY - RateLimiter::attempts($approverKey)
                    )),
                ], 422);
            }

            // Failures are not cleared by a success: they expire on their own, so
            // knowing one PIN does not reset the count for guessing another.
            $this->record($data, $cashier->id, $approver->id, 'pin');
            if (! empty($data['draft_id'])) {
                PosApprovalPin::grantDraftDelete($cashier, (int) $data['draft_id']);
            }
        } finally {
            $lock->release();
        }

        return response()->json([
            'approved' => true,
            'approver' => ['id' => $approver->id, 'name' => $this->userName($approver)],
        ]);
    }

    /** GET pos/voids/approvers?warehouse_id= → managers the till can ask (with a PIN set). */
    public function approvers(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        if (! PosApprovalPin::ready()) {
            return response()->json(['approvers' => []]);
        }

        $warehouseId = $this->filterWarehouseId($request->input('warehouse_id'));
        $approvers = PosApprovalPin::approvers($warehouseId)
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $this->userName($u)])
            ->values();

        return response()->json(['approvers' => $approvers]);
    }

    public function log(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'pos_void_items', Sale::class);
        if (! PosApprovalPin::ready()) {
            return response()->json(['logged' => false]);
        }

        $data = $this->validateLines($request, false);
        // Removals made offline are queued on the till and sent later with
        // the time they happened.
        $request->validate(['occurred_at' => 'nullable|date']);
        $at = null;
        if ($request->filled('occurred_at')) {
            $at = \Illuminate\Support\Carbon::parse($request->input('occurred_at'));
            if ($at->isFuture() || $at->lt(now()->subDays(self::MAX_BACKDATE_DAYS))) {
                $at = null;
            }
        }
        $this->record($data, $user->id, $user->id, 'self', $at);

        return response()->json(['logged' => true]);
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_void_items', Sale::class);

        if (! PosApprovalPin::ready()) {
            return response()->json([
                'ready' => false, 'rows' => [], 'totalRows' => 0,
                'summary' => ['lines' => 0, 'quantity' => 0, 'amount' => 0],
                'warehouses' => [], 'cashiers' => [], 'approvers' => [],
            ]);
        }

        $query = DB::table('pos_voids as v')
            ->leftJoin('users as cu', 'cu.id', '=', 'v.cashier_id')
            ->leftJoin('users as au', 'au.id', '=', 'v.approved_by')
            ->leftJoin('warehouses as w', 'w.id', '=', 'v.warehouse_id');
        // Rows without a warehouse are shown only to users who see every warehouse.
        $this->scopeToWarehouses($query, 'v.warehouse_id');
        // Option lists: only people who appear in rows this user may see.
        $scoped = clone $query;

        if ($warehouseId = $this->filterWarehouseId($request->input('warehouse_id'))) {
            $query->where('v.warehouse_id', $warehouseId);
        }
        if ($request->filled('cashier_id')) {
            $query->where('v.cashier_id', (int) $request->input('cashier_id'));
        }
        if ($request->filled('approved_by')) {
            $query->where('v.approved_by', (int) $request->input('approved_by'));
        }
        if ($request->filled('action') && in_array($request->input('action'), PosVoid::ACTIONS, true)) {
            $query->where('v.action', $request->input('action'));
        }
        if (is_string($request->input('from')) && $request->filled('from')) {
            $query->whereDate('v.created_at', '>=', $request->input('from'));
        }
        if (is_string($request->input('to')) && $request->filled('to')) {
            $query->whereDate('v.created_at', '<=', $request->input('to'));
        }
        if (is_string($request->input('search')) && $request->filled('search')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($q) use ($like) {
                $q->where('v.product_name', 'like', $like)
                    ->orWhere('v.product_code', 'like', $like)
                    ->orWhere('v.reason', 'like', $like);
            });
        }

        $summary = (clone $query)->selectRaw('COUNT(*) as lines_count, COALESCE(SUM(v.quantity), 0) as qty, COALESCE(SUM(v.amount), 0) as amount')->first();
        $total = (int) ($summary->lines_count ?? 0);

        $sortField = $request->input('SortField');
        $sortColumn = is_string($sortField) ? (self::SORTABLE[$sortField] ?? 'v.created_at') : 'v.created_at';
        $sortDir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min(200, (int) $request->input('limit', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $rows = $query->select([
            'v.id', 'v.action', 'v.product_name', 'v.product_code', 'v.quantity', 'v.unit_price', 'v.amount',
            'v.approval_method', 'v.reason', 'v.created_at', 'v.cashier_id', 'v.approved_by', 'v.warehouse_id',
            'cu.username as cashier_name', 'au.username as approver_name', 'w.name as warehouse_name',
        ])
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('v.id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $warehouseIds = $this->warehouseScopeIds();
        $warehouses = Warehouse::whereNull('deleted_at')
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('id', $warehouseIds))
            ->orderBy('name')->get(['id', 'name']);
        $people = fn (string $column) => DB::table('users')
            ->whereIn('id', (clone $scoped)->whereNotNull('v.'.$column)->distinct()->select('v.'.$column))
            ->orderBy('username')->get(['id', 'username as name']);

        return response()->json([
            'ready' => true,
            'rows' => $rows,
            'totalRows' => $total,
            'summary' => [
                'lines' => $total,
                'quantity' => (float) ($summary->qty ?? 0),
                'amount' => (float) ($summary->amount ?? 0),
            ],
            'warehouses' => $warehouses,
            'cashiers' => $people('cashier_id'),
            'approvers' => $people('approved_by'),
        ]);
    }

    /** GET pos/approval-pin → does the signed-in user have a PIN / may they hold one. */
    public function myPin(Request $request)
    {
        $user = $request->user('api');

        return response()->json([
            'ready' => PosApprovalPin::ready(),
            'can_approve' => PosApprovalPin::canApprove($user),
            'has_pin' => PosApprovalPin::ready() && ! empty($user->pos_pin_hash),
        ]);
    }

    public function setMyPin(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'pos_void_items', Sale::class);
        $this->ensureReady();

        $request->validate([
            'current_password' => 'required|string',
            'pin' => 'nullable|string',
            'pin_confirmation' => 'nullable|string',
        ]);

        // Uniqueness answers "is this PIN taken", so cap how often it can be asked.
        PosApprovalPin::throttleSet($user);

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            return response()->json(['message' => __('Current password is incorrect.'), 'errors' => ['current_password' => [__('Current password is incorrect.')]]], 422);
        }

        $pin = $request->input('pin');
        if ($pin !== null && $pin !== '' && $pin !== $request->input('pin_confirmation')) {
            return response()->json(['message' => __('The PINs do not match.'), 'errors' => ['pin_confirmation' => [__('The PINs do not match.')]]], 422);
        }

        PosApprovalPin::set($user, $pin === '' ? null : $pin);

        return response()->json(['success' => true, 'has_pin' => ! empty($user->fresh()->pos_pin_hash)]);
    }

    private function validateLines(Request $request, bool $withPin): array
    {
        $data = $request->validate([
            'pin' => $withPin ? 'required|string|max:12' : 'nullable',
            'approver_id' => $withPin ? 'required|integer|min:1' : 'nullable',
            'action' => 'required|in:'.implode(',', PosVoid::ACTIONS),
            'warehouse_id' => 'nullable|integer|min:1',
            // Held draft being deleted (clear_cart): the approval also unlocks remove_draft_sale.
            'draft_id' => 'nullable|integer|min:1',
            'reason' => 'nullable|string|max:255',
            'items' => 'required|array|min:1|max:300',
            'items.*.product_id' => 'nullable|integer|min:1',
            'items.*.product_variant_id' => 'nullable|integer|min:1',
            'items.*.name' => 'required|string|max:192',
            'items.*.code' => 'nullable|string|max:192',
            'items.*.quantity' => 'required|numeric|min:0|max:1000000000',
            'items.*.unit_price' => 'nullable|numeric|min:0|max:'.self::MAX_AMOUNT,
        ]);

        // A warehouse the user may not work in is not recorded against it.
        $data['warehouse_id'] = $this->filterWarehouseId($data['warehouse_id'] ?? null);

        return $data;
    }

    private function record(array $data, int $cashierId, int $approverId, string $method, $at = null): void
    {
        $now = $at ?: now();
        $rows = [];
        foreach ($data['items'] as $item) {
            $qty = round((float) $item['quantity'], 4);
            $price = round((float) ($item['unit_price'] ?? 0), 4);
            $rows[] = [
                'action' => $data['action'],
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name' => mb_substr((string) $item['name'], 0, 192),
                'product_code' => isset($item['code']) ? mb_substr((string) $item['code'], 0, 192) : null,
                'quantity' => $qty,
                'unit_price' => $price,
                'amount' => min(round($qty * $price, 4), self::MAX_AMOUNT),
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'cashier_id' => $cashierId,
                'approved_by' => $approverId,
                'approval_method' => $method,
                'reason' => $data['reason'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        PosVoid::insert($rows);
    }

    private function ensureReady(): void
    {
        if (! PosApprovalPin::ready()) {
            abort(response()->json(['message' => __('Run the system update to enable manager approval.')], 503));
        }
    }

    private function userName(User $user): string
    {
        $full = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));

        return $full !== '' ? $full : (string) $user->username;
    }
}
