<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Write calls to /api/v1 may carry an `Idempotency-Key` header (any unique
 * string, e.g. a UUID per business event). The first SUCCESSFUL response for
 * that key is stored; a retry with the same key gets it back (header
 * `Idempotent-Replayed: true`) instead of posting the same stock adjustment or
 * purchase again. Keys are per API key; reusing one for a different call is
 * refused. Failed calls (4xx/5xx) are not stored, so a corrected retry runs;
 * a claim left running over 5 minutes is treated as abandoned.
 */
class ApiIdempotency
{
    public function handle(Request $request, Closure $next)
    {
        $idem = trim((string) $request->header('Idempotency-Key', ''));
        $keyId = $request->attributes->get('api_key_id');
        if ($idem === '' || ! $keyId || $request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }
        if (mb_strlen($idem) > 100) {
            return ApiKeyAuth::error('invalid_idempotency_key', 'Idempotency-Key may be at most 100 characters.', 422);
        }

        $path = mb_substr('/'.ltrim($request->path(), '/'), 0, 191);
        $scope = fn () => DB::table('api_idempotency')->where('api_key_id', $keyId)->where('idempotency_key', $idem);

        // Claim the key first (status 0 = running): a concurrent duplicate
        // then hits the unique index instead of posting a second time.
        $claim = fn () => DB::table('api_idempotency')->insert([
            'api_key_id' => $keyId,
            'idempotency_key' => $idem,
            'method' => $request->method(),
            'path' => $path,
            'status' => 0,
            'response' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            $claim();
        } catch (\Illuminate\Database\QueryException $e) {
            $seen = $scope()->first();
            if (! $seen) {
                throw $e;
            }
            if ($seen->method !== $request->method() || $seen->path !== $path) {
                return ApiKeyAuth::error('idempotency_key_reused', 'This Idempotency-Key was already used for a different call.', 422);
            }
            if ((int) $seen->status === 0) {
                // A claim older than 5 minutes was abandoned (killed worker,
                // fatal error, timeout): take it over instead of 409 forever.
                if (\Carbon\Carbon::parse($seen->updated_at)->gt(now()->subMinutes(5))) {
                    return ApiKeyAuth::error('request_in_progress', 'A request with this Idempotency-Key is still being processed.', 409);
                }
                $scope()->where('status', 0)->delete();
                try {
                    $claim();
                } catch (\Illuminate\Database\QueryException $again) {
                    return ApiKeyAuth::error('request_in_progress', 'A request with this Idempotency-Key is still being processed.', 409);
                }
            } else {
                return response($seen->response, $seen->status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            $scope()->delete(); // nothing was stored: let a retry run
            throw $e;
        }

        // Only a success is remembered: after a 4xx (bad body, unknown
        // supplier …) the caller fixes the request and retries with the same
        // key, which must then run — nothing was posted the first time.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $scope()->delete();
        } else {
            $scope()->update([
                'status' => $response->getStatusCode(),
                'response' => (string) $response->getContent(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }
}
