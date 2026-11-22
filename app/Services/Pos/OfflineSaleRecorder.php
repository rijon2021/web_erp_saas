<?php

namespace App\Services\Pos;

use App\Models\PosOfflineSale;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Records every POS sale that a till's offline queue replays to
 * pos/create_pos — synced, or rejected with the server's reason — so
 * Sales > Offline Sales can list them for all tills and a manager can retry
 * or discard the rejected ones.
 *
 * Only the offline sync sends `offline_id` (alongside the sale_uuid every
 * POS sale carries), so normal online sales are never recorded. Recording is
 * best-effort: it must never change the outcome of the sale itself, and it is
 * a no-op until the tenant migration has run.
 */
class OfflineSaleRecorder
{
    /** @var array<string, bool> table-exists memo per tenant */
    protected static array $ready = [];

    public static function ready(): bool
    {
        $key = (string) (function_exists('tenant') && tenant() ? tenant('id') : 'central');
        if (! array_key_exists($key, static::$ready)) {
            try {
                static::$ready[$key] = Schema::hasTable('pos_offline_sales');
            } catch (\Throwable $e) {
                static::$ready[$key] = false;
            }
        }

        return static::$ready[$key];
    }

    /** Is this create_pos request a replay from a till's offline queue? */
    public static function applies(Request $request): bool
    {
        return $request->filled('offline_id') && $request->filled('sale_uuid') && static::ready();
    }

    /** The recorded row for this request's sale_uuid, if any. */
    public static function find(Request $request): ?PosOfflineSale
    {
        try {
            return PosOfflineSale::where('sale_uuid', (string) $request->input('sale_uuid'))->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function synced(Request $request, $saleId): ?PosOfflineSale
    {
        return static::record($request, PosOfflineSale::STATUS_SYNCED, null, null, $saleId);
    }

    public static function failed(Request $request, ?string $message, ?int $statusCode): ?PosOfflineSale
    {
        return static::record($request, PosOfflineSale::STATUS_FAILED, $message, $statusCode, null);
    }

    /** Record the outcome carried by a create_pos JSON response. */
    public static function fromResponse(Request $request, $response): ?PosOfflineSale
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }
        $data = (array) $response->getData(true);
        $status = $response->getStatusCode();
        if ($status < 400 && ($data['success'] ?? false) === true) {
            return static::synced($request, $data['id'] ?? null);
        }

        return static::failed($request, (string) ($data['message'] ?? $data['error'] ?? 'Invalid response from server'), $status);
    }

    /** Readable reason from a validation exception (the stock guard puts it in errors.stock). */
    public static function validationMessage(\Illuminate\Validation\ValidationException $e): string
    {
        $parts = collect($e->errors())->flatten()->filter()->map(fn ($m) => (string) $m)->all();

        return $parts ? implode(' ', $parts) : $e->getMessage();
    }

    protected static function record(Request $request, string $status, ?string $message, ?int $statusCode, $saleId): ?PosOfflineSale
    {
        try {
            $uuid = (string) $request->input('sale_uuid');
            $row = PosOfflineSale::firstOrNew(['sale_uuid' => $uuid]);

            // A sale already on the server never goes back to failed (a late
            // replay from another till hits the sale_uuid dedupe instead).
            if ($row->exists && $row->status === PosOfflineSale::STATUS_SYNCED && $status !== PosOfflineSale::STATUS_SYNCED) {
                return $row;
            }

            if (! $row->exists) {
                $row->user_id = Auth::id();
                $row->device = substr((string) $request->userAgent(), 0, 255) ?: null;
            }
            $payload = $request->all();
            $row->offline_id = substr((string) $request->input('offline_id'), 0, 64);
            $row->warehouse_id = $request->input('warehouse_id') ?: null;
            $row->client_id = is_numeric($request->input('client_id')) ? (int) $request->input('client_id') : null;
            $row->grand_total = (float) $request->input('GrandTotal', 0);
            $row->payload = $payload;
            $row->offline_created_at = static::offlineCreatedAt($request) ?? $row->offline_created_at;
            $row->status = $status;
            $row->error_message = $status === PosOfflineSale::STATUS_FAILED ? ($message ?: 'Unknown error') : null;
            $row->status_code = $status === PosOfflineSale::STATUS_FAILED ? $statusCode : null;
            if ($saleId) {
                $row->sale_id = (int) $saleId;
            }
            $row->attempts = (int) $row->attempts + 1;
            $row->last_attempt_at = now();
            $row->save();

            return $row;
        } catch (\Illuminate\Database\QueryException $e) {
            // Two tills (or a till and a manager) racing on the same sale_uuid
            // insert: the other request's row wins; update it instead.
            try {
                $existing = PosOfflineSale::where('sale_uuid', (string) $request->input('sale_uuid'))->first();
                if ($existing && ! ($existing->status === PosOfflineSale::STATUS_SYNCED && $status !== PosOfflineSale::STATUS_SYNCED)) {
                    $existing->forceFill([
                        'status' => $status,
                        'error_message' => $status === PosOfflineSale::STATUS_FAILED ? ($message ?: 'Unknown error') : null,
                        'status_code' => $status === PosOfflineSale::STATUS_FAILED ? $statusCode : null,
                        'sale_id' => $saleId ? (int) $saleId : $existing->sale_id,
                        'attempts' => (int) $existing->attempts + 1,
                        'last_attempt_at' => now(),
                    ])->save();
                }

                return $existing;
            } catch (\Throwable $inner) {
                Log::warning('[POS offline] could not record offline sale: '.$inner->getMessage());

                return null;
            }
        } catch (\Throwable $e) {
            Log::warning('[POS offline] could not record offline sale: '.$e->getMessage());

            return null;
        }
    }

    protected static function offlineCreatedAt(Request $request): ?Carbon
    {
        $raw = $request->input('offline_created_at');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            // Same zone PosController books the sale in.
            return Carbon::parse($raw)->setTimezone(now()->getTimezone());
        } catch (\Throwable $e) {
            return null;
        }
    }
}
