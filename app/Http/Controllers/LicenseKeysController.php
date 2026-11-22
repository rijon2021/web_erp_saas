<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LicenseActivation;
use App\Models\LicenseKey;
use App\Models\LicenseKeyEvent;
use App\Models\LicenseSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\LicenseKeys\LicenseDelivery;
use App\Services\LicenseKeys\LicenseFulfilment;
use App\Services\LicenseKeys\LicenseKeyInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Products → License Keys.
 *
 * Uploaded keys (third-party software) are stock: add them by hand or from a
 * CSV / Excel file, they are claimed automatically when a sale completes.
 * Generated keys (the tenant's own software) are issued at sale or by hand,
 * and carry expiry, activation limit and domain / device restriction.
 *
 * Permissions: license_keys_view, license_keys_manage, license_keys_control,
 * license_keys_reveal (see LicenseKeyPolicy).
 */
class LicenseKeysController extends BaseController
{
    public function __construct(private LicenseKeyInventory $inventory)
    {
    }

    private function can(Request $request, string $ability): void
    {
        if (! LicenseFulfilment::ready()) {
            abort(response()->json(['message' => __('Run the database update (tenant migrations) to enable License Keys.')], 409));
        }
        $this->authorizeForUser($request->user('api'), $ability, LicenseKey::class);
    }

    // ------------------------------------------------------------------ overview

    /** License products with key counts per status (+ edition rows). */
    public function dashboard(Request $request)
    {
        $this->can($request, 'license_keys_view');

        $products = Product::whereNull('deleted_at')
            ->whereIn('license_mode', ['pool', 'generated'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'license_mode', 'stock_alert', 'type']);

        $counts = DB::table('license_keys')
            ->whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, product_variant_id, status, COUNT(*) as n')
            ->groupBy('product_id', 'product_variant_id', 'status')
            ->get();

        $expiring = DB::table('license_keys')
            ->whereIn('product_id', $products->pluck('id'))
            ->where('source', 'generated')
            ->where('status', 'sold')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->selectRaw('product_id, COUNT(*) as n')
            ->groupBy('product_id')
            ->pluck('n', 'product_id');

        $variantNames = ProductVariant::whereIn('product_id', $products->where('type', 'is_variant')->pluck('id'))
            ->whereNull('deleted_at')->get(['id', 'product_id', 'name'])->groupBy('product_id');

        $backordered = \Illuminate\Support\Facades\Schema::hasTable('license_backorders')
            ? DB::table('license_backorders')->whereIn('product_id', $products->pluck('id'))
                ->selectRaw('product_id, SUM(missing) as n')->groupBy('product_id')->pluck('n', 'product_id')
            : collect();

        $rows = $products->map(function ($p) use ($counts, $expiring, $variantNames, $backordered) {
            $mine = $counts->where('product_id', $p->id);
            $by = fn ($rows) => collect(LicenseKey::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) $rows->where('status', $s)->sum('n')])->all();
            $editions = ($variantNames[$p->id] ?? collect())->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'counts' => $by($mine->where('product_variant_id', $v->id)),
            ])->values();

            $c = $by($mine);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'mode' => $p->license_mode,
                'stock_alert' => (float) $p->stock_alert,
                'counts' => $c,
                'low_stock' => $p->license_mode === 'pool' && $c['available'] <= (float) $p->stock_alert,
                'expiring_30' => (int) ($expiring[$p->id] ?? 0),
                'backordered' => (int) ($backordered[$p->id] ?? 0),
                'editions' => $editions,
            ];
        })->values();

        return response()->json([
            'products' => $rows,
            'totals' => [
                'available' => $rows->where('mode', 'pool')->sum(fn ($r) => $r['counts']['available']),
                'sold' => $rows->sum(fn ($r) => $r['counts']['sold']),
                'returned' => $rows->sum(fn ($r) => $r['counts']['returned']),
                'low_stock' => $rows->where('low_stock', true)->count(),
                'expiring_30' => $rows->sum('expiring_30'),
                'backordered' => $rows->sum('backordered'),
            ],
        ]);
    }

    /** Keys completed sales are still owed (shop imports, pre-orders, subscriptions). */
    public function backorders(Request $request)
    {
        $this->can($request, 'license_keys_view');
        if (! \Illuminate\Support\Facades\Schema::hasTable('license_backorders')) {
            return response()->json(['rows' => [], 'total' => 0]);
        }

        $scope = $this->warehouseScopeIds();
        $rows = DB::table('license_backorders as b')
            ->join('sales as s', 's.id', '=', 'b.sale_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->whereNull('s.deleted_at')
            ->when($scope !== null, fn ($q) => $q->whereIn('s.warehouse_id', $scope))
            ->when($request->filled('product_id'), fn ($q) => $q->where('b.product_id', (int) $request->product_id))
            ->when($request->filled('sale_id'), fn ($q) => $q->where('b.sale_id', (int) $request->sale_id))
            ->orderBy('b.sale_id')
            ->limit(500)
            ->get(['b.id', 'b.sale_id', 's.Ref as sale_ref', 's.date as sale_date', 'c.name as client_name',
                'b.product_id', 'p.name as product_name', 'b.product_variant_id as variant_id', 'v.name as variant_name', 'b.missing', 'b.created_at']);

        return response()->json(['rows' => $rows, 'total' => $rows->count()]);
    }

    /** "Assign now": give a sale the keys it is owed (as many as there are), and email them. */
    public function syncSale(Request $request, $id)
    {
        $this->can($request, 'license_keys_manage');
        $sale = \App\Models\Sale::whereNull('deleted_at')->findOrFail($id);
        $scope = $this->warehouseScopeIds();
        if ($scope !== null && ! in_array((int) $sale->warehouse_id, array_map('intval', $scope), true)) {
            abort(404);
        }

        $fulfilment = app(LicenseFulfilment::class);
        $newIds = DB::transaction(fn () => $fulfilment->syncSale($sale, null, Auth::id(), null, true));
        $emailed = $newIds ? app(LicenseDelivery::class)->deliver($newIds) : 0;
        $missing = \Illuminate\Support\Facades\Schema::hasTable('license_backorders')
            ? (int) DB::table('license_backorders')->where('sale_id', $sale->id)->sum('missing') : 0;

        return response()->json(['success' => true, 'assigned' => count($newIds), 'missing' => $missing, 'emailed' => $emailed > 0]);
    }

    /** License products + editions for pickers. */
    public function products(Request $request)
    {
        $this->can($request, 'license_keys_view');

        $products = Product::whereNull('deleted_at')
            ->whereIn('license_mode', ['pool', 'generated'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'license_mode', 'type']);
        $variants = ProductVariant::whereIn('product_id', $products->pluck('id'))->whereNull('deleted_at')
            ->get(['id', 'product_id', 'name'])->groupBy('product_id');

        return response()->json([
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'mode' => $p->license_mode,
                'variants' => ($variants[$p->id] ?? collect())->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
            ])->values(),
            'warehouses' => \App\Models\Warehouse::whereNull('deleted_at')
                ->when($this->warehouseScopeIds() !== null, fn ($q) => $q->whereIn('id', $this->warehouseScopeIds()))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ------------------------------------------------------------------ keys

    public function index(Request $request)
    {
        $this->can($request, 'license_keys_view');

        $perPage = max(1, min(200, (int) $request->input('limit', 25)));
        $q = $this->filtered($request)
            ->with(['product:id,name', 'variant:id,name', 'client:id,name,email', 'sale:id,Ref'])
            ->withCount(['activations as active_activations' => fn ($a) => $a->whereNull('deactivated_at')]);

        $sortable = ['id', 'created_at', 'sold_at', 'expires_at', 'status'];
        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : 'id';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        $page = $q->orderBy($sort, $dir)->paginate($perPage);

        return response()->json([
            'rows' => collect($page->items())->map(fn ($k) => $this->row($k))->values(),
            'total' => $page->total(),
        ]);
    }

    /**
     * The key list query with every list filter applied, limited to the keys
     * of the user's warehouses (keys with no warehouse — manual issues — stay
     * visible). Shared by the list and the export, so an export never contains
     * more than the list shows.
     */
    private function filtered(Request $request)
    {
        $q = LicenseKey::query();

        $scope = $this->warehouseScopeIds();
        if ($scope !== null) {
            $q->where(fn ($w) => $w->whereIn('warehouse_id', $scope)->orWhereNull('warehouse_id'));
        }

        if ($request->filled('product_id')) {
            $q->where('product_id', (int) $request->product_id);
        }
        if ($request->filled('variant_id')) {
            $q->where('product_variant_id', (int) $request->variant_id);
        }
        if ($request->filled('source')) {
            $q->where('source', $request->source === 'generated' ? 'generated' : 'pool');
        }
        if ($request->filled('status')) {
            $status = (string) $request->status;
            if ($status === 'expired') {
                $q->where('status', 'sold')->whereNotNull('expires_at')->where('expires_at', '<', now());
            } elseif ($status === 'active') {
                $q->where('status', 'sold')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
            } elseif ($status === 'expiring') {
                $q->where('status', 'sold')->whereBetween('expires_at', [now(), now()->addDays(30)]);
            } elseif (in_array($status, LicenseKey::STATUSES, true)) {
                $q->where('status', $status);
            }
        }
        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->client_id);
        }
        if ($request->filled('sale_id')) {
            $q->where('sale_id', (int) $request->sale_id);
        }
        if ($request->filled('batch')) {
            $q->where('batch', (string) $request->batch);
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $hashes = [LicenseKey::hashKey(LicenseKey::normalize($s)), LicenseKey::hashKey(LicenseKey::normalize($s, true))];
            $q->where(function ($w) use ($s, $hashes) {
                $w->whereIn('key_hash', $hashes)
                    ->orWhere('key_last4', $s)
                    ->orWhere('note', 'like', "%{$s}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
                    ->orWhereHas('sale', fn ($c) => $c->where('Ref', 'like', "%{$s}%"));
            });
        }

        return $q;
    }

    /** A key the user may act on (warehouse scope); 404 otherwise. */
    private function findKey($id): LicenseKey
    {
        $key = LicenseKey::findOrFail($id);
        $scope = $this->warehouseScopeIds();
        if ($scope !== null && $key->warehouse_id && ! in_array((int) $key->warehouse_id, array_map('intval', $scope), true)) {
            abort(404);
        }

        return $key;
    }

    public function show(Request $request, $id)
    {
        $this->can($request, 'license_keys_view');
        $key = $this->findKey($id)->load(['product:id,name,license_mode', 'variant:id,name', 'client:id,name,email,phone', 'sale:id,Ref,date']);

        $events = LicenseKeyEvent::with('user:id,username,firstname,lastname')
            ->where('license_key_id', $key->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'event' => $e->event,
                'note' => $e->note,
                'meta' => $e->meta,
                'user' => $e->user ? trim(($e->user->firstname ?? '').' '.($e->user->lastname ?? '')) ?: $e->user->username : null,
                'created_at' => optional($e->created_at)->toDateTimeString(),
            ]);

        $activations = LicenseActivation::where('license_key_id', $key->id)->orderByDesc('id')->limit(500)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'identifier' => $a->identifier,
                'label' => $a->label,
                'ip' => $a->ip,
                'activated_at' => optional($a->activated_at)->toDateTimeString(),
                'last_seen_at' => optional($a->last_seen_at)->toDateTimeString(),
                'deactivated_at' => optional($a->deactivated_at)->toDateTimeString(),
            ]);

        return response()->json([
            'key' => $this->row($key) + [
                'client_phone' => $key->client->phone ?? null,
                'sale_date' => $key->sale->date ?? null,
                'adjustment_id' => $key->adjustment_id,
                'batch' => $key->batch,
            ],
            'events' => $events,
            'activations' => $activations,
        ]);
    }

    /** The full key (logged). */
    public function reveal(Request $request, $id)
    {
        $this->can($request, 'license_keys_reveal');
        $key = $this->findKey($id);
        app(LicenseFulfilment::class)->event($key->id, 'revealed', null, Auth::id());

        return response()->json(['key' => $key->plainKey()]);
    }

    /** CSV of full keys for the list's current filters (each key logged as exported). */
    public function export(Request $request)
    {
        $this->can($request, 'license_keys_reveal');

        $rows = $this->filtered($request)
            ->with(['product:id,name', 'variant:id,name', 'client:id,name,email', 'sale:id,Ref'])
            ->orderBy('id')
            ->limit(20000)
            ->get();

        $userId = Auth::id();
        // Chunked: one INSERT of 20,000 rows would pass MySQL's 65,535-placeholder limit.
        foreach ($rows->chunk(1000) as $chunk) {
            DB::table('license_key_events')->insert($chunk->map(fn ($k) => [
                'license_key_id' => $k->id, 'event' => 'exported', 'note' => null, 'user_id' => $userId, 'meta' => null, 'created_at' => now(),
            ])->values()->all());
        }

        // Spreadsheet-safe: a cell starting with = + - @ (or tab / CR) is not run as a formula.
        $cell = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['license_key', 'product', 'edition', 'status', 'customer', 'email', 'sale', 'sold_at', 'expires_at', 'note']);
        foreach ($rows as $k) {
            fputcsv($out, array_map($cell, [
                $k->plainKey(), $k->product->name ?? '', $k->variant->name ?? '', $k->status,
                $k->client->name ?? '', $k->client->email ?? '', $k->sale->Ref ?? '',
                optional($k->sold_at)->toDateTimeString(), optional($k->expires_at)->toDateString(), (string) $k->note,
            ]));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="license-keys-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    // ------------------------------------------------------------------ uploaded keys

    /** Sort pasted keys or a CSV / Excel file into new / duplicate / existing. */
    public function preview(Request $request)
    {
        $this->can($request, 'license_keys_manage');

        $preview = $this->inventory->preview($this->rowsFromRequest($request));

        return response()->json([
            'new' => count($preview['new']),
            'new_sample' => array_map(fn ($r) => ['row' => $r['row'], 'key' => '••••-'.mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $r['key']) ?: $r['key'], -4), 'cost' => $r['cost'], 'note' => $r['note']], array_slice($preview['new'], 0, 50)),
            'duplicates_in_file' => $preview['duplicates_in_file'],
            'existing' => $preview['existing'],
            'invalid' => $preview['invalid'],
        ]);
    }

    public function import(Request $request)
    {
        $this->can($request, 'license_keys_manage');
        $data = $request->validate([
            'product_id' => 'required|integer',
            'product_variant_id' => 'nullable|integer',
            'warehouse_id' => 'required|integer',
            'cost' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:255',
        ]);
        $this->abortIfWarehouseDenied($data['warehouse_id']);
        if (! \App\Models\Warehouse::whereNull('deleted_at')->whereKey($data['warehouse_id'])->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => [__('Warehouse not found.')]]);
        }
        $product = Product::whereNull('deleted_at')->findOrFail($data['product_id']);

        $rows = $this->rowsFromRequest($request);
        if (! $rows) {
            throw ValidationException::withMessages(['keys' => [__('Paste at least one key or choose a file.')]]);
        }
        if (count($rows) > 20000) {
            throw ValidationException::withMessages(['keys' => [__('Up to 20,000 keys per import.')]]);
        }

        $result = $this->inventory->addKeys(
            $product,
            ! empty($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            (int) $data['warehouse_id'],
            $rows,
            isset($data['cost']) ? (float) $data['cost'] : null,
            $data['note'] ?? null,
            Auth::id()
        );

        return response()->json(['success' => true] + $result);
    }

    public function restock(Request $request, $id)
    {
        $this->can($request, 'license_keys_manage');
        $key = $this->inventory->restock($this->findKey($id), Auth::id());

        return response()->json(['success' => true, 'key' => $this->row($key->fresh(['product', 'variant', 'client', 'sale']))]);
    }

    public function void(Request $request, $id)
    {
        $this->can($request, 'license_keys_manage');
        $reason = $request->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null;
        $key = $this->findKey($id);
        if ($key->warehouse_id) {
            $this->abortIfWarehouseDenied($key->warehouse_id);
        }
        $key = $this->inventory->void($key, $reason, Auth::id());

        return response()->json(['success' => true, 'key' => $this->row($key->fresh(['product', 'variant', 'client', 'sale']))]);
    }

    public function destroy(Request $request, $id)
    {
        $this->can($request, 'license_keys_manage');
        $key = $this->findKey($id);
        if ($key->warehouse_id) {
            $this->abortIfWarehouseDenied($key->warehouse_id);
        }
        $this->inventory->delete($key, Auth::id());

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ generated licenses

    public function issue(Request $request)
    {
        $this->can($request, 'license_keys_manage');
        $data = $request->validate([
            'product_id' => 'required|integer',
            'product_variant_id' => 'nullable|integer',
            'count' => 'required|integer|min:1|max:500',
            'client_id' => 'nullable|integer',
            'note' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
            'send_email' => 'nullable|boolean',
        ]);
        $product = Product::whereNull('deleted_at')->findOrFail($data['product_id']);
        $clientId = ! empty($data['client_id']) ? (int) Client::whereNull('deleted_at')->findOrFail($data['client_id'])->id : null;

        $keys = $this->inventory->issue($product, ! empty($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            (int) $data['count'], $clientId, $data['note'] ?? null, $data['expires_at'] ?? null, Auth::id());

        $sent = 0;
        if (! empty($data['send_email']) && $clientId) {
            $sent = app(LicenseDelivery::class)->deliver($keys->pluck('id')->all(), null, true);
        }

        return response()->json([
            'success' => true,
            'count' => $keys->count(),
            'batch' => $keys->first()->batch ?? null,
            'emailed' => $sent > 0,
        ]);
    }

    public function extend(Request $request, $id)
    {
        $this->can($request, 'license_keys_control');
        $data = $request->validate([
            'mode' => ['required', Rule::in(['date', 'lifetime', 'add'])],
            'expires_at' => 'nullable|required_if:mode,date|date',
            'add_value' => 'nullable|required_if:mode,add|integer|min:1|max:1000',
            'add_unit' => ['nullable', 'required_if:mode,add', Rule::in(['days', 'months', 'years'])],
        ]);
        $key = $this->findKey($id);

        $expires = null;
        if ($data['mode'] === 'date') {
            $expires = $data['expires_at'];
        } elseif ($data['mode'] === 'add') {
            $from = $key->expires_at && $key->expires_at->isFuture() ? $key->expires_at->copy() : now();
            $n = (int) $data['add_value'];
            $expires = match ($data['add_unit']) {
                'days' => $from->addDays($n),
                'months' => $from->addMonthsNoOverflow($n),
                default => $from->addYearsNoOverflow($n),
            };
            $expires = $expires->toDateTimeString();
        }

        $key = $this->inventory->extend($key, $expires, Auth::id());

        return response()->json(['success' => true, 'key' => $this->row($key->fresh(['product', 'variant', 'client', 'sale']))]);
    }

    public function state(Request $request, $id)
    {
        $this->can($request, 'license_keys_control');
        $data = $request->validate([
            'action' => ['required', Rule::in(['suspend', 'revoke', 'reactivate'])],
            'reason' => 'nullable|string|max:255',
        ]);
        $key = $this->inventory->setState($this->findKey($id), $data['action'], $data['reason'] ?? null, Auth::id());

        return response()->json(['success' => true, 'key' => $this->row($key->fresh(['product', 'variant', 'client', 'sale']))]);
    }

    public function resetActivations(Request $request, $id)
    {
        $this->can($request, 'license_keys_control');
        $activationId = $request->validate(['activation_id' => 'nullable|integer'])['activation_id'] ?? null;
        $n = $this->inventory->resetActivations($this->findKey($id), $activationId ? (int) $activationId : null, Auth::id());

        return response()->json(['success' => true, 'deactivated' => $n]);
    }

    /** Email the key again (to the customer, or another address). */
    public function resend(Request $request, $id)
    {
        $this->can($request, 'license_keys_manage');
        $to = $request->validate(['email' => 'nullable|email|max:191'])['email'] ?? null;
        // Sending a key to any other address hands it out: same right as revealing it.
        if ($to) {
            $this->authorizeForUser($request->user('api'), 'license_keys_reveal', LicenseKey::class);
        }
        $key = $this->findKey($id);
        if ($key->status !== LicenseKey::STATUS_SOLD) {
            throw ValidationException::withMessages(['status' => [__('Only sold keys can be emailed.')]]);
        }
        $sent = app(LicenseDelivery::class)->deliver([$key->id], $to, true);
        if (! $sent) {
            throw ValidationException::withMessages(['email' => [__('The email could not be sent — check the customer email and the mail settings.')]]);
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ settings

    public function settings(Request $request)
    {
        $this->can($request, 'license_keys_view');
        $s = LicenseSetting::current();
        [$publicKey] = $s->ensureKeyPair();

        return response()->json([
            'settings' => [
                'email_enabled' => (bool) $s->email_enabled,
                'show_on_receipt' => (bool) $s->show_on_receipt,
                'api_enabled' => (bool) $s->api_enabled,
                'offline_grace_days' => (int) $s->offline_grace_days,
                'reminder_days' => (string) $s->reminder_days,
            ],
            'public_key' => $publicKey,
            'api_base' => url('/api/licensing/v1'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->can($request, 'license_keys_manage');
        $data = $request->validate([
            'email_enabled' => 'required|boolean',
            'show_on_receipt' => 'required|boolean',
            'api_enabled' => 'required|boolean',
            'offline_grace_days' => 'required|integer|min:0|max:365',
            'reminder_days' => ['nullable', 'string', 'max:32', 'regex:/^\s*(\d{1,3}\s*(,\s*\d{1,3}\s*)*)?$/'],
        ]);
        $data['reminder_days'] = preg_replace('/\s+/', '', (string) ($data['reminder_days'] ?? ''));
        LicenseSetting::current()->fill($data)->save();

        return $this->settings($request);
    }

    /** New signing key pair — software holding the old public key will reject responses. */
    public function rotateSigningKey(Request $request)
    {
        $this->can($request, 'license_keys_manage');
        LicenseSetting::current()->rotateKeyPair();

        return $this->settings($request);
    }

    // ------------------------------------------------------------------ helpers

    private function row(LicenseKey $k): array
    {
        return [
            'id' => $k->id,
            'masked_key' => $k->maskedKey(),
            'source' => $k->source,
            'status' => $k->status,
            'license_state' => $k->isGenerated() ? $k->licenseState() : null,
            'status_reason' => $k->status_reason,
            'product_id' => $k->product_id,
            'product_name' => $k->product->name ?? null,
            'variant_id' => $k->product_variant_id,
            'variant_name' => $k->variant->name ?? null,
            'warehouse_id' => $k->warehouse_id,
            'client_id' => $k->client_id,
            'client_name' => $k->client->name ?? null,
            'client_email' => $k->client->email ?? null,
            'sale_id' => $k->sale_id,
            'sale_ref' => $k->sale->Ref ?? null,
            'online_order_id' => $k->online_order_id,
            // Taken back from its sale (return / edit / delete), awaiting review.
            'returned' => $k->released_at !== null,
            'cost' => $k->cost,
            'note' => $k->note,
            'sold_at' => optional($k->sold_at)->toDateTimeString(),
            'delivered_at' => optional($k->delivered_at)->toDateTimeString(),
            'starts_at' => optional($k->starts_at)->toDateTimeString(),
            'expires_at' => optional($k->expires_at)->toDateTimeString(),
            'max_activations' => $k->max_activations,
            'restriction' => $k->restriction,
            'active_activations' => (int) ($k->active_activations ?? $k->activations()->whereNull('deactivated_at')->count()),
            'created_at' => optional($k->created_at)->toDateTimeString(),
        ];
    }

    /**
     * Key rows from `keys` (one per line) and/or an uploaded CSV / TXT / XLSX
     * file. A header row (license_key / key / serial, cost, note) is detected;
     * without one, column 1 = key, 2 = cost, 3 = note.
     *
     * @return array<int, array{key: string, cost: mixed, note: mixed}>
     */
    private function rowsFromRequest(Request $request): array
    {
        $rows = [];
        $text = (string) $request->input('keys', '');
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) !== '') {
                $rows[] = ['key' => trim($line), 'cost' => null, 'note' => null];
            }
        }

        if ($request->hasFile('file')) {
            $request->validate(['file' => 'file|max:10240|mimes:csv,txt,xlsx,xls']);
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                // Explicit reader, values only, first 3 columns / 20,001 rows: no
                // formula evaluation, no format sniffing, bounded memory.
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                    public function readCell($columnAddress, $row, $worksheetName = ''): bool
                    {
                        return $row <= 20001 && in_array($columnAddress, ['A', 'B', 'C'], true);
                    }
                });
                $sheet = $reader->load($file->getRealPath())->getActiveSheet();
                $table = $sheet->rangeToArray('A1:C'.min(20001, max(1, $sheet->getHighestDataRow())), null, false, false, false);
            } else {
                $table = [];
                $h = fopen($file->getRealPath(), 'r');
                $first = fgets($h) ?: '';
                $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (str_contains($first, "\t") ? "\t" : ',');
                rewind($h);
                while (($r = fgetcsv($h, 0, $delim)) !== false) {
                    $table[] = $r;
                }
                fclose($h);
            }

            $map = ['key' => 0, 'cost' => 1, 'note' => 2];
            if ($table) {
                $head = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $table[0]);
                $keyCol = null;
                foreach (['license_key', 'license key', 'key', 'serial', 'serial_key', 'code'] as $name) {
                    if (($i = array_search($name, $head, true)) !== false) {
                        $keyCol = $i;
                        break;
                    }
                }
                if ($keyCol !== null) {
                    $map = [
                        'key' => $keyCol,
                        'cost' => ($i = array_search('cost', $head, true)) !== false ? $i : null,
                        'note' => ($i = array_search('note', $head, true)) !== false ? $i : (($i = array_search('notes', $head, true)) !== false ? $i : null),
                    ];
                    array_shift($table);
                }
            }
            foreach ($table as $r) {
                $key = trim((string) ($r[$map['key']] ?? ''));
                if ($key === '') {
                    continue;
                }
                $rows[] = [
                    'key' => $key,
                    'cost' => $map['cost'] !== null ? ($r[$map['cost']] ?? null) : null,
                    'note' => $map['note'] !== null ? ($r[$map['note']] ?? null) : null,
                ];
            }
        }

        return $rows;
    }
}
