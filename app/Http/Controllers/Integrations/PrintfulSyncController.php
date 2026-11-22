<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OnlineOrder;
use App\Models\PrintfulLog;
use App\Models\PrintfulMapping;
use App\Models\PrintfulOrder;
use App\Models\PrintfulSetting;
use App\Services\Printful\OrderService;
use App\Services\Printful\PrintfulException;
use App\Services\Printful\SyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin API for the Printful integration: settings, store picking, webhook
 * registration, the batched product import, and the Orders tab (send /
 * confirm / cancel / refresh). Gated by the `printful_settings` permission;
 * routes by `tenant.feature:printful`. The API token is write-only.
 */
class PrintfulSyncController extends Controller
{
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrintfulSetting::class);

        $settings = PrintfulSetting::current();
        $catalog = SyncService::make($settings);

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'has_token' => ! empty($settings->api_token),
            'token_hint' => $settings->api_token ? '…'.substr((string) $settings->api_token, -4) : null,
            'store_id' => $settings->store_id,
            'store_name' => $settings->store_id ? $settings->store_name : null,
            // Token saved but no store picked yet: the page asks for one.
            'needs_store' => $settings->isConfigured() && ! $settings->store_id,
            'currency' => $settings->currency ?: 'USD',
            'store_currency' => $catalog->storeCurrency(),
            'exchange_rate' => $settings->exchange_rate,
            'submit_on' => $settings->submit_on ?: PrintfulSetting::SUBMIT_CONFIRMED,
            'auto_confirm' => (bool) $settings->auto_confirm,
            'sync_costs' => (bool) $settings->sync_costs,
            'update_prices' => (bool) $settings->update_prices,
            'mark_shipped' => (bool) $settings->mark_shipped,
            'category_id' => $settings->category_id,
            'categories' => Category::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'webhook_url' => $settings->isConfigured() ? $settings->webhookUrl() : null,
            'webhook_registered_at' => optional($settings->webhook_registered_at)->toIso8601String(),
            'last_sync_at' => optional($settings->last_sync_at)->toIso8601String(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'api_token' => 'nullable|string|max:500',
            'store_id' => 'nullable|integer|min:1',
            'store_name' => 'nullable|string|max:191',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'submit_on' => ['nullable', Rule::in([PrintfulSetting::SUBMIT_CONFIRMED, PrintfulSetting::SUBMIT_PAID, PrintfulSetting::SUBMIT_MANUAL])],
            'auto_confirm' => 'nullable|boolean',
            'sync_costs' => 'nullable|boolean',
            'update_prices' => 'nullable|boolean',
            'mark_shipped' => 'nullable|boolean',
            'category_id' => 'nullable|integer|exists:categories,id',
        ]);

        $settings = PrintfulSetting::current();
        $wasAutomatic = $settings->sendsAutomatically();
        $previousTrigger = $settings->submit_on;
        $settings->enabled = (bool) $data['enabled'];

        // Write-only secret: only replace when the request carries a new value.
        // A new token may reach other stores, so the store is picked again
        // (the store sent with it is the old one and is ignored).
        $tokenChanged = ! empty($data['api_token']) && trim($data['api_token']) !== $settings->api_token;
        if ($tokenChanged) {
            $settings->api_token = trim($data['api_token']);
            $settings->store_id = null;
            $settings->store_name = null;
            $settings->webhook_registered_at = null;
        }
        if (! $tokenChanged && $request->exists('store_id') && (int) ($data['store_id'] ?? 0) !== (int) $settings->store_id) {
            $settings->store_id = $data['store_id'] ?: null;
            $settings->store_name = $data['store_name'] ?? null;
            $settings->webhook_registered_at = null;
        }
        if ($request->exists('exchange_rate')) {
            $settings->exchange_rate = $data['exchange_rate'] ?: null;
        }
        foreach (['submit_on', 'category_id'] as $key) {
            if ($request->exists($key)) {
                $settings->{$key} = $data[$key] ?: ($key === 'submit_on' ? PrintfulSetting::SUBMIT_CONFIRMED : null);
            }
        }
        foreach (['auto_confirm', 'sync_costs', 'update_prices', 'mark_shipped'] as $flag) {
            if ($request->exists($flag)) {
                $settings->{$flag} = (bool) $data[$flag];
            }
        }
        // Automatic sending (re)starts now: the sweep never back-sends orders
        // that reached their trigger before this moment.
        if ($settings->sendsAutomatically() && (! $wasAutomatic || $settings->submit_on !== $previousTrigger)) {
            $settings->auto_since = now();
        }
        $settings->save();

        return response()->json(['success' => true, 'configured' => $settings->isConfigured()]);
    }

    /** Verifies the token, lists its stores, and connects webhooks once a store is set. */
    public function testConnection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);

        $settings = PrintfulSetting::current();
        if (! $settings->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add your Printful API token first.'], 422);
        }

        $service = SyncService::make($settings);
        $result = $service->testConnection();
        if (! ($result['ok'] ?? false)) {
            if (empty($result['needs_store'])) {
                $service->log('connect.test', 'error', $result['error'] ?? 'Connection test failed');
            }

            return response()->json($result, 422);
        }

        if (! $settings->webhook_registered_at) {
            $result['webhooks'] = $service->registerWebhooks();
        }

        return response()->json($result);
    }

    public function webhookStatus(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrintfulSetting::class);

        return response()->json(SyncService::make($this->configuredSettings())->webhookStatus());
    }

    public function registerWebhooks(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);

        $result = SyncService::make($this->configuredSettings())->registerWebhooks();

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrintfulSetting::class);

        return response()->json(SyncService::make()->stats());
    }

    public function syncProducts(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);
        $settings = $this->configuredSettings();
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');

        $result = SyncService::make($settings)->pullProducts(
            (int) $request->input('page_info', 0),
            (int) ($request->input('batch') ?: 10)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    // -----------------------------------------------------------------
    // Orders tab
    // -----------------------------------------------------------------

    /**
     * view=sent: orders that have a Printful row; view=ready: recent web
     * orders holding Printful products that were never sent.
     */
    public function orders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrintfulSetting::class);
        $perPage = min(100, (int) ($request->input('per_page') ?: 20));

        if ($request->input('view') === 'ready') {
            $page = OnlineOrder::query()
                ->where('created_at', '>=', now()->subDays(60))
                ->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $q->whereNull('delivery_method')->orWhere('delivery_method', '!=', 'pickup'))
                ->whereNotIn('id', PrintfulOrder::query()->select('online_order_id'))
                ->whereHas('items', fn ($q) => $q
                    ->where(fn ($w) => $w->whereNotNull('product_variant_id')
                        ->whereIn('product_variant_id', PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_VARIANT)->select('local_id')))
                    ->orWhere(fn ($w) => $w->whereNull('product_variant_id')
                        ->whereIn('product_id', PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_SINGLE)->select('local_id'))))
                ->orderByDesc('id')
                ->paginate($perPage);

            $page->setCollection($page->getCollection()->map(fn (OnlineOrder $o) => [
                'online_order_id' => $o->id,
                'ref' => $o->ref,
                'date' => $o->date,
                'customer' => $o->customer_name,
                'country' => $o->shipping_country,
                'total' => (float) $o->total,
                'order_status' => $o->status,
                'payment_status' => $o->payment_status,
            ]));

            return response()->json($page);
        }

        $query = PrintfulOrder::query()->with('onlineOrder:id,ref,date,customer_name,total,status,payment_status')->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $page = $query->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn (PrintfulOrder $po) => [
            'id' => $po->id,
            'online_order_id' => $po->online_order_id,
            'ref' => $po->onlineOrder->ref ?? ('#'.$po->online_order_id),
            'date' => $po->onlineOrder->date ?? null,
            'customer' => $po->onlineOrder->customer_name ?? null,
            'total' => isset($po->onlineOrder) ? (float) $po->onlineOrder->total : null,
            'order_status' => $po->onlineOrder->status ?? null,
            'printful_order_id' => $po->printful_order_id,
            'status' => $po->status,
            'total_cost' => $po->total_cost,
            'currency' => $po->currency,
            'items' => count($po->items ?? []),
            'carrier' => $po->carrier,
            'tracking_number' => $po->tracking_number,
            'tracking_url' => $po->tracking_url,
            'shipped_at' => optional($po->shipped_at)->toIso8601String(),
            'error' => $po->error,
            'submitted_at' => optional($po->submitted_at)->toIso8601String(),
            'can_confirm' => $po->isDraft(),
            'can_cancel' => $po->isCancellable(),
            'can_retry' => ! $po->printful_order_id && in_array($po->status, [PrintfulOrder::STATUS_ERROR, PrintfulOrder::STATUS_QUEUED], true),
        ]));

        return response()->json($page);
    }

    /** Send (or re-send after an error) one online order now. */
    public function sendOrder(Request $request, $onlineOrderId)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);
        $settings = $this->configuredSettings();

        $order = OnlineOrder::with('items')->findOrFail($onlineOrderId);
        $service = OrderService::make($settings);
        if ($order->status === 'cancelled' || $order->isPickup()) {
            return response()->json(['ok' => false, 'error' => 'Cancelled and pickup orders are not sent to Printful.'], 422);
        }
        if (! $service->printfulLines($order)) {
            return response()->json(['ok' => false, 'error' => 'This order has no Printful products.'], 422);
        }

        $po = PrintfulOrder::firstOrCreate(['online_order_id' => $order->id], ['status' => PrintfulOrder::STATUS_QUEUED]);
        if ($po->printful_order_id) {
            return response()->json(['ok' => true, 'status' => $po->status, 'printful_order_id' => $po->printful_order_id]);
        }

        try {
            $po = $service->submit($po);
        } catch (PrintfulException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $po->status === PrintfulOrder::STATUS_ERROR
            ? response()->json(['ok' => false, 'error' => $po->error], 422)
            : response()->json(['ok' => true, 'status' => $po->status, 'printful_order_id' => $po->printful_order_id]);
    }

    public function confirmOrder(Request $request, $id)
    {
        return $this->orderAction($request, $id, function (OrderService $service, PrintfulOrder $po) {
            abort_unless($po->isDraft(), 422, 'Only Printful drafts can be confirmed.');

            return $service->confirm($po);
        });
    }

    public function cancelOrder(Request $request, $id)
    {
        return $this->orderAction($request, $id, function (OrderService $service, PrintfulOrder $po) {
            abort_unless($po->isCancellable(), 422, 'Printful no longer allows cancelling this order.');

            return $service->cancel($po);
        });
    }

    public function refreshOrder(Request $request, $id)
    {
        return $this->orderAction($request, $id, function (OrderService $service, PrintfulOrder $po) {
            abort_unless((bool) $po->printful_order_id, 422, 'This order has not reached Printful yet.');

            return $service->refresh($po);
        });
    }

    private function orderAction(Request $request, $id, callable $action)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);
        $service = OrderService::make($this->configuredSettings());
        $po = PrintfulOrder::findOrFail($id);

        try {
            $po = $action($service, $po);
        } catch (PrintfulException $e) {
            $service->log('orders.action', 'error', 'Printful order #'.$po->printful_order_id.': '.$e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'status' => $po->status]);
    }

    // -----------------------------------------------------------------
    // Logs
    // -----------------------------------------------------------------

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PrintfulSetting::class);

        $query = PrintfulLog::query()->orderByDesc('id');
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return response()->json($query->paginate((int) ($request->input('per_page') ?: 20)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', PrintfulSetting::class);

        PrintfulLog::query()->delete();

        return response()->json(['success' => true]);
    }

    private function configuredSettings(): PrintfulSetting
    {
        $settings = PrintfulSetting::current();
        abort_if(! $settings->isConfigured(), 422, 'Printful is not connected — add your API token first.');

        return $settings;
    }
}
