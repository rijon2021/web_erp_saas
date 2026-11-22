<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticates the public REST API (/api/v1) with a key from Settings > API
 * (`Authorization: Bearer stk_…` or `X-Api-Key: stk_…`).
 *
 * The key acts as the user it was issued for: that user is bound to the `api`
 * guard, so the same policies, warehouse scopes and record visibility as in
 * the app apply, and the Audit Log names that user (channel "api"). A read key
 * may only GET. Errors are always JSON: {"error": {"code", "message"}}.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Integrations rarely send Accept; exceptions must still render as JSON.
        $request->headers->set('Accept', 'application/json');

        // A JSON body sent without "Content-Type: application/json" (plain
        // `curl -d '{…}'`) would otherwise be read as an empty form.
        if (! $request->isJson() && ! $request->isMethod('GET')) {
            $raw = ltrim((string) $request->getContent());
            if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[') && is_array(json_decode($raw, true))) {
                $request->headers->set('Content-Type', 'application/json');
            }
        }

        if (! Schema::hasTable('api_keys')) {
            return self::error('api_not_ready', 'The API is not set up yet: run the database update.', 503);
        }

        $plain = $request->bearerToken() ?: $request->header('X-Api-Key');
        if (! $plain) {
            return self::error('missing_key', 'Send your API key as "Authorization: Bearer <key>".', 401);
        }

        $key = ApiKey::findActive($plain);
        if (! $key) {
            return self::error('invalid_key', 'This API key is not valid or has been revoked.', 401);
        }

        // Stocky deletes users by setting deleted_at (no SoftDeletes trait).
        $user = User::whereNull('deleted_at')->find($key->user_id);
        if (! $user || (int) $user->statut !== 1) {
            return self::error('user_inactive', 'The user this key acts as is missing or deactivated.', 403);
        }

        if (! $key->canWrite() && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return self::error('read_only_key', 'This key is read-only. Create a read & write key for this call.', 403);
        }

        Auth::guard('api')->setUser($user);
        Auth::shouldUse('api');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_key_id', $key->id);

        // Touch at most once a minute — integrations call in bursts.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        return $next($request);
    }

    public static function error(string $code, string $message, int $status, array $extra = [])
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }
}
