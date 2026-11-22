<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > API: issue and revoke keys for the public REST API (/api/v1).
 * Gated by the `api_keys` permission and the plan feature `api`.
 *
 * A key always acts as the user who creates it — never someone with more
 * rights — and the full key is returned only once, in the create response.
 */
class ApiKeysController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'api_keys', Sale::class);
        if (! Schema::hasTable('api_keys')) {
            return response()->json(['ready' => false, 'keys' => []]);
        }

        $keys = ApiKey::with('user:id,username')->orderByDesc('id')->get()->map(fn (ApiKey $k) => [
            'id' => $k->id,
            'name' => $k->name,
            'prefix' => $k->key_prefix,
            'ability' => $k->ability,
            'user' => $k->user?->username,
            'last_used_at' => $k->last_used_at,
            'last_used_ip' => $k->last_used_ip,
            'revoked_at' => $k->revoked_at,
            'created_at' => $k->created_at,
        ]);

        return response()->json(['ready' => true, 'keys' => $keys]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'api_keys', Sale::class);
        abort_unless(Schema::hasTable('api_keys'), 503, 'Run the database update first.');

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'ability' => 'required|in:read,write',
        ]);

        [$key, $plain] = ApiKey::issue($data['name'], $data['ability'], (int) Auth::id(), (int) Auth::id());

        return response()->json([
            'id' => $key->id,
            'key' => $plain, // shown once; only its hash is stored
            'prefix' => $key->key_prefix,
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'api_keys', Sale::class);
        $key = ApiKey::findOrFail($id);
        if (! $key->revoked_at) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['success' => true]);
    }
}
