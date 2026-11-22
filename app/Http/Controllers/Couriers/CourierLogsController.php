<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\CourierLog;
use App\Services\Couriers\CourierLogger;
use App\Services\Couriers\CourierRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Couriers → Logs: courier API calls, webhooks received and errors, newest
 * first. Technical data (masked request / response bodies), so it is behind
 * courier_settings like the accounts themselves.
 */
class CourierLogsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $accounts = CourierAccount::query()->withTrashed()->orderBy('name')->get(['id', 'name', 'courier', 'deleted_at']);
        if (! Schema::hasTable('courier_logs')) {
            return response()->json(['logs' => [], 'totalRows' => 0, 'ready' => false, 'accounts' => $this->accountOptions($accounts), 'keep_days' => CourierLogger::KEEP_DAYS]);
        }

        $limit = min(200, max(10, (int) $request->query('limit', 25)));
        $query = CourierLog::query()
            ->when($request->filled('account_id'), fn ($q) => $q->where('courier_account_id', $request->integer('account_id')))
            ->when($request->filled('shipment_id'), fn ($q) => $q->where('shipment_id', $request->integer('shipment_id')))
            ->when(in_array($request->query('action'), CourierLog::ACTIONS, true), fn ($q) => $q->where('action', $request->query('action')))
            ->when(in_array($request->query('level'), ['info', 'error'], true), fn ($q) => $q->where('level', $request->query('level')))
            ->when(in_array($request->query('direction'), ['in', 'out'], true), fn ($q) => $q->where('direction', $request->query('direction')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->where(fn ($w) => $w->where('message', 'like', $s)->orWhere('url', 'like', $s)
                    ->orWhere('request', 'like', $s)->orWhere('response', 'like', $s));
            });

        $total = (clone $query)->count();
        $names = $accounts->keyBy('id');
        $rows = $query->orderByDesc('id')
            ->forPage(max(1, (int) $request->query('page', 1)), $limit)
            ->get(['id', 'courier_account_id', 'shipment_id', 'courier', 'action', 'direction', 'level', 'method', 'url', 'http_status', 'duration_ms', 'message', 'created_at'])
            ->map(fn (CourierLog $l) => $this->present($l, $names));

        return response()->json([
            'logs' => $rows,
            'totalRows' => $total,
            'ready' => true,
            'errors_24h' => CourierLog::query()->where('level', 'error')->where('created_at', '>=', now()->subDay())->count(),
            'accounts' => $this->accountOptions($accounts),
            'keep_days' => CourierLogger::KEEP_DAYS,
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);
        if (! Schema::hasTable('courier_logs')) {
            abort(404);
        }

        $log = CourierLog::query()->findOrFail($id);
        $names = CourierAccount::query()->withTrashed()->where('id', $log->courier_account_id)->get(['id', 'name', 'courier', 'deleted_at'])->keyBy('id');

        return response()->json(['log' => $this->present($log, $names) + [
            'request' => $log->request,
            'response' => $log->response,
            'sale_id' => $log->shipment_id ? \App\Models\Shipment::query()->whereKey($log->shipment_id)->value('sale_id') : null,
        ]]);
    }

    /** Older than 7 days by default; ?all=1 for everything (optionally one account). */
    public function destroy(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);
        if (! Schema::hasTable('courier_logs')) {
            return response()->json(['deleted' => 0]);
        }

        $deleted = CourierLog::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->where('created_at', '<', now()->subDays(7)))
            ->when($request->filled('account_id'), fn ($q) => $q->where('courier_account_id', $request->integer('account_id')))
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    private function present(CourierLog $l, $names): array
    {
        $a = $names[$l->courier_account_id] ?? null;

        return [
            'id' => $l->id,
            'account_id' => $l->courier_account_id,
            'account_name' => $a ? $a->name . ($a->deleted_at ? ' (deleted)' : '') : null,
            'courier_name' => $l->courier ? (CourierRegistry::meta($l->courier)['name'] ?? $l->courier) : null,
            'shipment_id' => $l->shipment_id,
            'action' => $l->action,
            'direction' => $l->direction,
            'level' => $l->level,
            'method' => $l->method,
            'url' => $l->url,
            'http_status' => $l->http_status,
            'duration_ms' => $l->duration_ms,
            'message' => $l->message,
            'created_at' => $l->created_at?->toDateTimeString(),
        ];
    }

    private function accountOptions($accounts): array
    {
        return $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name . ($a->deleted_at ? ' (deleted)' : '')])->values()->all();
    }
}
