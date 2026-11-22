<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\PaymentMethod;
use App\Models\PosTerminal;
use App\Models\PosTerminalTransaction as Txn;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\PosTerminals\TerminalException;
use App\Services\PosTerminals\TerminalGateway;
use App\Services\PosTerminals\TerminalOperation as Op;
use App\Services\PosTerminals\TerminalRegistry;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POS Devices (card terminals / fiscal registers, plan feature `pos_terminals`).
 *
 * Devices (pos_terminals_manage):
 *   GET    pos-terminals                     list + form options
 *   POST   pos-terminals                     add
 *   PUT    pos-terminals/{id}                edit (blank secrets keep the stored ones)
 *   DELETE pos-terminals/{id}                remove (soft)
 *   POST   pos-terminals/{id}/test           ping (cloud) / ping envelope (bridge)
 *   POST   pos-terminals/{id}/test-result    the bridge's answer to that envelope
 * Till (Sales_pos):
 *   GET    pos-terminals/available           active devices for a warehouse (no secrets)
 *   POST   pos-terminals/{id}/charge         start a card sale for one payment line
 *   GET    pos-terminals/transactions/{id}   poll (own transactions, or managers)
 *   POST   pos-terminals/transactions/{id}/bridge-result
 *   POST   pos-terminals/transactions/{id}/dispatch  cloud: make the long provider call
 *   POST   pos-terminals/transactions/{id}/cancel
 *   POST   pos-terminals/transactions/{id}/void   own unlinked charge, or managers
 * Log:
 *   GET    pos-terminals/transactions        (pos_terminals_transactions)
 *   POST   pos-terminals/transactions/{id}/refund (pos_terminals_manage)
 */
class PosTerminalsController extends BaseController
{
    private const SORTABLE = [
        'created_at' => 't.created_at', 'amount' => 't.amount', 'status' => 't.status',
        'type' => 't.type', 'terminal_name' => 'pt.name', 'reference' => 't.reference',
    ];

    /** The settings JSON keys a device keeps. */
    private const SETTING_KEYS = ['currency', 'timeout_seconds', 'max_installments', 'auth_header', 'endpoints', 'departments'];

    /** A cashier may void their own unlinked charge for this long (an abandoned checkout). */
    private const OWN_VOID_HOURS = 12;

    public function __construct(private TerminalGateway $gateway)
    {
    }

    // ================================================================ devices

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_terminals_manage', Sale::class);

        if (! $this->ready()) {
            return response()->json(['ready' => false, 'terminals' => [], 'models' => TerminalRegistry::models(), 'warehouses' => [], 'payment_methods' => []]);
        }

        $query = PosTerminal::query()->with(['warehouse:id,name', 'paymentMethod:id,name'])->orderBy('name');
        $this->scopeToWarehouses($query, 'warehouse_id', true);

        $warehouseIds = $this->warehouseScopeIds();

        return response()->json([
            'ready' => true,
            'terminals' => $query->get()->map(fn (PosTerminal $t) => $this->presentTerminal($t, true))->values(),
            'models' => TerminalRegistry::models(),
            'warehouses' => Warehouse::whereNull('deleted_at')
                ->when($warehouseIds !== null, fn ($q) => $q->whereIn('id', $warehouseIds))
                ->orderBy('name')->get(['id', 'name']),
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'pos_terminals_manage', Sale::class);
        $this->ensureReady();

        $data = $this->validated($request, null);
        $terminal = new PosTerminal();
        $this->fill($terminal, $data, $request);
        $terminal->created_by = $user->id;
        $terminal->save();

        return response()->json(['success' => true, 'terminal' => $this->presentTerminal($terminal->fresh(['warehouse:id,name', 'paymentMethod:id,name']), true)]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_terminals_manage', Sale::class);
        $this->ensureReady();

        $terminal = $this->terminalOrFail($id);
        $data = $this->validated($request, $terminal);
        $this->fill($terminal, $data, $request);
        $terminal->save();

        return response()->json(['success' => true, 'terminal' => $this->presentTerminal($terminal->fresh(['warehouse:id,name', 'paymentMethod:id,name']), true)]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_terminals_manage', Sale::class);
        $this->ensureReady();

        $terminal = $this->terminalOrFail($id);
        $this->gateway->expireStale($terminal);
        if ($terminal->transactions()->where('status', Txn::STATUS_PENDING)->exists()) {
            return response()->json(['message' => __('messages.Pd_Device_Busy')], 422);
        }
        $terminal->delete();

        return response()->json(['success' => true]);
    }

    public function test(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_terminals_manage', Sale::class);
        $this->ensureReady();

        return $this->guard(fn () => $this->gateway->ping($this->terminalOrFail($id)));
    }

    public function testResult(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'pos_terminals_manage', Sale::class);
        $this->ensureReady();
        $data = $this->bridgeInput($request);

        $terminal = $this->terminalOrFail($id);
        if (! $terminal->usesBridge()) {
            return response()->json(['message' => 'This device is not set up for a local bridge.'], 422);
        }

        return $this->guard(fn () => $this->gateway->pingResult($terminal, $data['raw'], $data['http_status'], $data['signature']));
    }

    // =================================================================== till

    public function available(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        if (! $this->ready()) {
            return response()->json(['terminals' => []]);
        }

        $warehouseId = (int) $request->input('warehouse_id');
        if ($warehouseId && ! $this->canAccessWarehouse($warehouseId)) {
            return response()->json(['terminals' => []]);
        }

        $query = PosTerminal::query()
            ->where('is_active', true)
            ->whereNotNull('payment_method_id')
            ->when($warehouseId, fn ($q) => $q->where(fn ($w) => $w->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id')))
            ->orderBy('name');
        // Without a warehouse filter: devices of every warehouse this user works in.
        $this->scopeToWarehouses($query, 'warehouse_id', true);
        $terminals = $query->get();

        return response()->json([
            'terminals' => $terminals->filter(fn (PosTerminal $t) => TerminalRegistry::has($t->driver))
                ->map(fn (PosTerminal $t) => $this->presentTerminal($t, false))->values(),
        ]);
    }

    public function charge(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);
        $this->ensureReady();

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'currency_id' => 'nullable|integer',
            'sale_uuid' => 'required|string|max:64',
            'warehouse_id' => 'nullable|integer',
            'installments' => 'nullable|integer|min:1|max:36',
            'items' => 'nullable|array|max:500',
            'items.*.name' => 'nullable|string|max:191',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.unit_price' => 'nullable|numeric',
            'items.*.total' => 'nullable|numeric',
            'items.*.vat_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.code' => 'nullable|string|max:191',
            'sale_total' => 'nullable|numeric',
        ]);

        $terminal = PosTerminal::where('is_active', true)->findOrFail($id);
        if ($terminal->warehouse_id && ! $this->canAccessWarehouse($terminal->warehouse_id)) {
            abort(403);
        }
        if ($terminal->warehouse_id && ! empty($data['warehouse_id']) && (int) $data['warehouse_id'] !== (int) $terminal->warehouse_id) {
            return response()->json(['message' => __('messages.Pd_Wrong_Warehouse')], 422);
        }
        if (! empty($data['warehouse_id']) && ! $this->canAccessWarehouse($data['warehouse_id'])) {
            abort(403);
        }
        // One card flow per device at a time is enforced in startSale (row lock).

        $maxInstallments = (int) $terminal->setting('max_installments', 1);
        $installments = TerminalRegistry::supports($terminal, 'installments')
            ? max(1, min((int) ($data['installments'] ?? 1), max(1, $maxInstallments)))
            : 1;

        // Rate of the POS-selected currency per 1 base unit, from the currency
        // record (as the POS uses it) — never from the request.
        $docCurrency = ! empty($data['currency_id']) ? Currency::find($data['currency_id']) : null;
        $rate = $docCurrency && (float) $docCurrency->exchange_rate > 0 ? (float) $docCurrency->exchange_rate : 1.0;
        $currency = strtoupper((string) ($docCurrency?->code ?: (new helpers())->Get_Currency_Code() ?: $terminal->setting('currency', 'TRY')));

        return $this->guard(fn () => $this->gateway->startSale($terminal, [
            'amount' => (float) $data['amount'],
            'base_amount' => (float) $data['amount'] / $rate,
            'currency' => $currency,
            'sale_uuid' => $data['sale_uuid'],
            'installments' => $installments,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'receipt' => $terminal->fiscal_mode === PosTerminal::FISCAL_FULL_RECEIPT
                ? $this->receipt($terminal, $data['items'] ?? [], (float) ($data['sale_total'] ?? $data['amount']), (float) $data['amount'])
                : [],
        ], $user));
    }

    public function show(Request $request, $id)
    {
        $txn = $this->ownTxnOrFail($request, $id);

        return $this->guard(fn () => $this->gateway->refresh($txn, $request->boolean('recheck')));
    }

    /** Cloud devices: the long provider call for a sale / refund / void (see TerminalGateway). */
    public function send(Request $request, $id)
    {
        $txn = $this->ownTxnOrFail($request, $id);

        return $this->guard(fn () => $this->gateway->dispatch($txn));
    }

    public function bridgeResult(Request $request, $id)
    {
        $txn = $this->ownTxnOrFail($request, $id);
        $data = $this->bridgeInput($request);
        $request->validate(['op' => ['required', Rule::in(Op::ALL)], 'error' => 'nullable|string|max:191']);
        $op = (string) $request->input('op');

        // http_status 0 = the browser got no HTTP answer from the bridge.
        if ($data['http_status'] === 0) {
            return $this->guard(fn () => $this->gateway->bridgeUnreachable($txn, $op, $request->input('error')));
        }

        return $this->guard(fn () => $this->gateway->bridgeResult($txn, $op, $data['raw'], $data['signature']));
    }

    public function cancel(Request $request, $id)
    {
        $txn = $this->ownTxnOrFail($request, $id);

        return $this->guard(fn () => $this->gateway->cancel($txn));
    }

    public function void(Request $request, $id)
    {
        $user = $request->user('api');
        $this->ensureReady();
        $txn = Txn::with('terminal')->findOrFail($id);
        $this->abortIfTxnWarehouseDenied($txn);

        // The cashier undoing their own charge before the sale was saved
        // (customer changed their mind, checkout abandoned).
        $ownUnlinked = $txn->user_id === $user->id && $txn->sale_id === null
            && $txn->created_at && $txn->created_at->gt(now()->subHours(self::OWN_VOID_HOURS));
        if (! $ownUnlinked) {
            $this->authorizeForUser($user, 'pos_terminals_manage', Sale::class);
        } else {
            $this->authorizeForUser($user, 'Sales_pos', Sale::class);
        }

        return $this->guard(fn () => $this->gateway->reverse($txn, Txn::TYPE_VOID, (float) $txn->amount, $user, $ownUnlinked && ! $user->can('pos_terminals_manage', Sale::class)));
    }

    // ==================================================================== log

    public function transactions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'pos_terminals_transactions', Sale::class);

        if (! $this->ready()) {
            return response()->json(['ready' => false, 'rows' => [], 'totalRows' => 0, 'summary' => [], 'terminals' => [], 'can_manage' => false]);
        }

        $query = DB::table('pos_terminal_transactions as t')
            ->leftJoin('pos_terminals as pt', 'pt.id', '=', 't.pos_terminal_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('sales as s', 's.id', '=', 't.sale_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 't.warehouse_id');
        $this->scopeToWarehouses($query, 't.warehouse_id');

        if ($request->filled('pos_terminal_id')) {
            $query->where('t.pos_terminal_id', (int) $request->input('pos_terminal_id'));
        }
        if (in_array($request->input('status'), Txn::FINAL_STATUSES, true) || $request->input('status') === Txn::STATUS_PENDING) {
            $query->where('t.status', $request->input('status'));
        }
        if (in_array($request->input('type'), [Txn::TYPE_SALE, Txn::TYPE_REFUND, Txn::TYPE_VOID], true)) {
            $query->where('t.type', $request->input('type'));
        }
        if ($request->input('linked') === 'unlinked') {
            // Approved charges no sale was saved for and not fully given back:
            // money taken, nothing recorded.
            $query->where('t.type', Txn::TYPE_SALE)->where('t.status', Txn::STATUS_APPROVED)->whereNull('t.sale_id')
                ->whereRaw('t.amount - (SELECT COALESCE(SUM(r.amount), 0) FROM pos_terminal_transactions r WHERE r.parent_id = t.id AND r.status = ?) > 0.0001', [Txn::STATUS_APPROVED]);
        }
        if (is_string($request->input('from')) && $request->filled('from')) {
            $query->whereDate('t.created_at', '>=', $request->input('from'));
        }
        if (is_string($request->input('to')) && $request->filled('to')) {
            $query->whereDate('t.created_at', '<=', $request->input('to'));
        }
        if (is_string($request->input('search')) && $request->filled('search')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('search'))).'%';
            $query->where(fn ($q) => $q->where('t.reference', 'like', $like)
                ->orWhere('t.provider_reference', 'like', $like)
                ->orWhere('t.auth_code', 'like', $like)
                ->orWhere('t.card_mask', 'like', $like)
                ->orWhere('s.Ref', 'like', $like));
        }

        $summary = (clone $query)->selectRaw("
            COUNT(*) as total_count,
            COALESCE(SUM(CASE WHEN t.type = 'sale' AND t.status = 'approved' THEN t.base_amount ELSE 0 END), 0) as approved_amount,
            COALESCE(SUM(CASE WHEN t.type <> 'sale' AND t.status = 'approved' THEN t.base_amount ELSE 0 END), 0) as reversed_amount,
            COALESCE(SUM(CASE WHEN t.status IN ('declined','failed','timeout','cancelled') THEN 1 ELSE 0 END), 0) as unsuccessful
        ")->first();

        $sortField = $request->input('SortField');
        $sortColumn = is_string($sortField) ? (self::SORTABLE[$sortField] ?? 't.created_at') : 't.created_at';
        $sortDir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min(200, (int) $request->input('limit', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $rows = $query->select([
            't.id', 't.pos_terminal_id', 't.type', 't.status', 't.reference', 't.amount', 't.base_amount', 't.currency',
            't.installments', 't.sale_id', 't.parent_id', 't.provider_reference', 't.auth_code', 't.card_mask',
            't.card_brand', 't.response_code', 't.message', 't.created_at', 't.completed_at',
            'pt.name as terminal_name', 'u.username as user_name', 's.Ref as sale_ref', 'w.name as warehouse_name',
        ])
            ->selectSub(fn ($q) => $q->from('pos_terminal_transactions as c')->whereColumn('c.parent_id', 't.id')
                ->where('c.status', Txn::STATUS_APPROVED)->selectRaw('COALESCE(SUM(c.amount), 0)'), 'reversed')
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('t.id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return response()->json([
            'ready' => true,
            'rows' => $rows,
            'totalRows' => (int) ($summary->total_count ?? 0),
            'summary' => [
                'count' => (int) ($summary->total_count ?? 0),
                'approved_amount' => (float) ($summary->approved_amount ?? 0),
                'reversed_amount' => (float) ($summary->reversed_amount ?? 0),
                'unsuccessful' => (int) ($summary->unsuccessful ?? 0),
            ],
            'terminals' => $this->scopeToWarehouses(PosTerminal::withTrashed(), 'warehouse_id', true)->orderBy('name')->get(['id', 'name']),
            'can_manage' => $request->user('api')->can('pos_terminals_manage', Sale::class),
        ]);
    }

    public function refund(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'pos_terminals_manage', Sale::class);
        $this->ensureReady();

        $data = $request->validate(['amount' => 'required|numeric|min:0.01']);
        $txn = Txn::with('terminal')->findOrFail($id);
        $this->abortIfTxnWarehouseDenied($txn);

        return $this->guard(fn () => $this->gateway->reverse($txn, Txn::TYPE_REFUND, (float) $data['amount'], $user));
    }

    // ================================================================ helpers

    private function ready(): bool
    {
        return Schema::hasTable('pos_terminals') && Schema::hasTable('pos_terminal_transactions');
    }

    private function ensureReady(): void
    {
        if (! $this->ready()) {
            abort(response()->json(['message' => __('messages.Pd_Not_Ready')], 422));
        }
    }

    private function terminalOrFail($id): PosTerminal
    {
        $terminal = PosTerminal::findOrFail($id);
        if ($terminal->warehouse_id && ! $this->canAccessWarehouse($terminal->warehouse_id)) {
            abort(403);
        }

        return $terminal;
    }

    /** A cashier polls / cancels their own operations; device managers anyone's. */
    private function ownTxnOrFail(Request $request, $id): Txn
    {
        $user = $request->user('api');
        $this->ensureReady();
        $txn = Txn::with(['terminal', 'parent'])->findOrFail($id);
        $this->abortIfTxnWarehouseDenied($txn);

        if ($txn->user_id === $user->id) {
            $this->authorizeForUser($user, 'Sales_pos', Sale::class);
        } else {
            $this->authorizeForUser($user, 'pos_terminals_manage', Sale::class);
        }

        return $txn;
    }

    private function abortIfTxnWarehouseDenied(Txn $txn): void
    {
        if ($txn->warehouse_id && ! $this->canAccessWarehouse($txn->warehouse_id)) {
            abort(403);
        }
    }

    /** Runs a gateway call; device refusals become a 422 with the reason. */
    private function guard(callable $call)
    {
        try {
            return response()->json($call());
        } catch (TerminalException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** @return array{raw: string, http_status: int, signature: ?string} */
    private function bridgeInput(Request $request): array
    {
        $data = $request->validate([
            'raw' => 'present|nullable|string|max:200000',
            'http_status' => 'required|integer|min:0|max:999',
            'signature' => 'nullable|string|max:256',
        ]);

        return ['raw' => (string) ($data['raw'] ?? ''), 'http_status' => (int) $data['http_status'], 'signature' => $data['signature'] ?? null];
    }

    private function validated(Request $request, ?PosTerminal $terminal): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'driver' => ['required', Rule::in(array_keys(TerminalRegistry::DRIVERS))],
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'connection_mode' => ['required', Rule::in([PosTerminal::MODE_CLOUD, PosTerminal::MODE_BRIDGE])],
            'api_base_url' => 'nullable|string|max:500',
            'bridge_url' => 'nullable|string|max:500',
            'auth_type' => ['nullable', Rule::in(PosTerminal::AUTH_TYPES)],
            'api_key' => 'nullable|string|max:2000',
            'api_secret' => 'nullable|string|max:2000',
            'remove_api_key' => 'nullable|boolean',
            'remove_api_secret' => 'nullable|boolean',
            'merchant_id' => 'nullable|string|max:64',
            'terminal_id' => 'nullable|string|max:64',
            'serial_number' => 'nullable|string|max:64',
            'ip_address' => 'nullable|string|max:64',
            'port' => 'nullable|integer|min:1|max:65535',
            'fiscal_mode' => ['required', Rule::in([PosTerminal::FISCAL_PAYMENT_ONLY, PosTerminal::FISCAL_FULL_RECEIPT])],
            'is_test' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
            'settings.timeout_seconds' => 'nullable|integer|min:15|max:300',
            'settings.max_installments' => 'nullable|integer|min:1|max:36',
            'settings.auth_header' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'settings.endpoints' => 'nullable|array',
            'settings.endpoints.*' => ['nullable', 'string', 'max:300', 'regex:#^((GET|POST|PUT|PATCH|DELETE)\s+)?/[^\s]*$#i'],
            'settings.departments' => 'nullable|array|max:20',
            'settings.departments.*.vat_rate' => 'required|numeric|min:0|max:100',
            'settings.departments.*.department' => 'required|integer|min:1|max:99',
        ]);

        if (! empty($data['warehouse_id']) && ! $this->canAccessWarehouse($data['warehouse_id'])) {
            abort(403);
        }

        $errors = [];
        $meta = TerminalRegistry::meta($data['driver']);
        if (! in_array($data['connection_mode'], $meta['modes'] ?? [], true)) {
            $errors['connection_mode'] = __('messages.Pd_Mode_Not_Supported');
        }

        // A newly typed secret wins over "remove" (see fill()).
        $secretAfter = ! empty($data['api_secret']) || (! ($data['remove_api_secret'] ?? false) && $terminal && $terminal->api_secret);
        if ($data['connection_mode'] === PosTerminal::MODE_CLOUD) {
            if (TerminalGateway::publicHttpsTarget($data['api_base_url'] ?? null) === null) {
                $errors['api_base_url'] = __('messages.Pd_Api_Url_Invalid');
            }
        } else {
            $url = (string) ($data['bridge_url'] ?? '');
            if ($url !== '' && ! preg_match('#^https?://[^\s/]+(:\d+)?(/\S*)?$#i', $url)) {
                $errors['bridge_url'] = __('messages.Pd_Bridge_Url_Invalid');
            }
            if (! $secretAfter) {
                $errors['api_secret'] = __('messages.Pd_Secret_Required');
            }
        }
        if (($data['auth_type'] ?? 'bearer') === 'hmac' && ! $secretAfter && $data['connection_mode'] === PosTerminal::MODE_CLOUD) {
            $errors['api_secret'] = __('messages.Pd_Secret_Required');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function fill(PosTerminal $terminal, array $data, Request $request): void
    {
        $defaults = TerminalRegistry::meta($data['driver'])['defaults'] ?? [];

        $terminal->fill([
            'name' => trim($data['name']),
            'driver' => $data['driver'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'payment_method_id' => $data['payment_method_id'],
            'connection_mode' => $data['connection_mode'],
            'api_base_url' => $data['api_base_url'] ?? null,
            'bridge_url' => $data['bridge_url'] ?? ($defaults['bridge_url'] ?? null),
            'auth_type' => $data['auth_type'] ?? 'bearer',
            'merchant_id' => $data['merchant_id'] ?? null,
            'terminal_id' => $data['terminal_id'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'port' => $data['port'] ?? null,
            'fiscal_mode' => $data['fiscal_mode'],
            // Not posted = unchanged (new devices: test mode, active).
            'is_test' => (bool) ($data['is_test'] ?? ($terminal->exists ? $terminal->is_test : true)),
            'is_active' => (bool) ($data['is_active'] ?? ($terminal->exists ? $terminal->is_active : true)),
        ]);

        // Settings: only known keys. A posted key replaces what the device had
        // (posted empty = back to the model default / unset); others are kept.
        $posted = (array) ($data['settings'] ?? []);
        $settings = array_replace($defaults, array_intersect_key($terminal->settings ?? [], array_flip(self::SETTING_KEYS)));
        foreach (['timeout_seconds', 'max_installments', 'auth_header'] as $key) {
            if (array_key_exists($key, $posted)) {
                if ($posted[$key] === null || $posted[$key] === '') {
                    unset($settings[$key]);
                    if (array_key_exists($key, $defaults)) {
                        $settings[$key] = $defaults[$key];
                    }
                } else {
                    $settings[$key] = $posted[$key];
                }
            }
        }
        if (array_key_exists('endpoints', $posted)) {
            $endpoints = array_intersect_key((array) $posted['endpoints'], array_flip(Op::ALL));
            $settings['endpoints'] = array_filter($endpoints, fn ($v) => is_string($v) && trim($v) !== '') + ($defaults['endpoints'] ?? []);
        }
        if (array_key_exists('departments', $posted)) {
            $settings['departments'] = array_values(array_map(fn ($d) => [
                'vat_rate' => (float) $d['vat_rate'],
                'department' => (int) $d['department'],
            ], (array) $posted['departments']));
        }
        $terminal->settings = array_intersect_key($settings, array_flip(self::SETTING_KEYS));

        // Secrets are write-only: blank keeps the stored value, a new value
        // replaces it, remove_* (with nothing new typed) clears it.
        foreach (['api_key', 'api_secret'] as $field) {
            if (! empty($data[$field])) {
                $terminal->{$field} = trim($data[$field]);
            } elseif ($request->boolean('remove_'.$field)) {
                $terminal->{$field} = null;
            }
        }
    }

    /** Items for full_receipt mode, each with the device department of its VAT rate. */
    private function receipt(PosTerminal $terminal, array $items, float $saleTotal, float $amount): array
    {
        $departments = collect((array) $terminal->setting('departments', []));
        $department = function ($rate) use ($departments) {
            $match = $departments->first(fn ($d) => abs((float) ($d['vat_rate'] ?? -1) - (float) $rate) < 0.001);

            return $match ? (int) $match['department'] : null;
        };

        $lines = array_values(array_map(fn ($i) => [
                'name' => mb_substr((string) ($i['name'] ?? ''), 0, 191),
                'code' => $i['code'] ?? null,
                'quantity' => round((float) ($i['quantity'] ?? 1), 4),
                'unit_price' => round((float) ($i['unit_price'] ?? 0), 4),
                'total' => round((float) ($i['total'] ?? 0), 2),
                'vat_rate' => round((float) ($i['vat_rate'] ?? 0), 2),
                'department' => $department($i['vat_rate'] ?? 0),
            ], $items));
        $itemsTotal = round(array_sum(array_column($lines, 'total')), 2);

        return [
            'mode' => PosTerminal::FISCAL_FULL_RECEIPT,
            'items' => $lines,
            'items_total' => $itemsTotal,
            // Order-level discount / shipping / tax / points: sale_total - items_total
            // (negative = discount), so the receipt adds up to what is due.
            'adjustment' => round($saleTotal - $itemsTotal, 2),
            'sale_total' => round($saleTotal, 2),
            'paid_on_device' => round($amount, 2),
        ];
    }

    private function presentTerminal(PosTerminal $t, bool $full): array
    {
        $meta = TerminalRegistry::meta($t->driver);
        $base = [
            'id' => $t->id,
            'name' => $t->name,
            'driver' => $t->driver,
            'model_label' => $meta['label'] ?? $t->driver,
            'warehouse_id' => $t->warehouse_id,
            'payment_method_id' => $t->payment_method_id,
            'connection_mode' => $t->connection_mode,
            'fiscal_mode' => $t->fiscal_mode,
            'is_test' => $t->is_test,
            'timeout_seconds' => $t->timeoutSeconds(),
            'max_installments' => TerminalRegistry::supports($t, 'installments') ? max(1, (int) $t->setting('max_installments', 1)) : 1,
            'capabilities' => $meta['capabilities'] ?? [],
        ];

        if (! $full) {
            return $base;
        }

        return $base + [
            'warehouse_name' => $t->warehouse?->name,
            'payment_method_name' => $t->paymentMethod?->name,
            'api_base_url' => $t->api_base_url,
            'bridge_url' => $t->bridge_url,
            'auth_type' => $t->auth_type,
            'has_api_key' => ! empty($t->api_key),
            'api_key_hint' => PosTerminal::hint($t->api_key),
            'has_api_secret' => ! empty($t->api_secret),
            'api_secret_hint' => PosTerminal::hint($t->api_secret),
            'merchant_id' => $t->merchant_id,
            'terminal_id' => $t->terminal_id,
            'serial_number' => $t->serial_number,
            'ip_address' => $t->ip_address,
            'port' => $t->port,
            'settings' => $t->settings ?? [],
            'is_active' => $t->is_active,
            'last_seen_at' => optional($t->last_seen_at)->toDateTimeString(),
            'last_status' => $t->last_status,
        ];
    }
}
