<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Provider;
use App\Models\Warehouse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me          the key, the user it acts as, its warehouses
 * GET /api/v1/warehouses  ids to use in warehouse_id
 * GET /api/v1/suppliers   ids / names to use in purchases (search=…)
 */
class MetaApiController extends ApiController
{
    public function me(Request $request)
    {
        $key = $request->attributes->get('api_key');
        $user = $request->user('api');

        return response()->json(['data' => [
            'api_version' => 1,
            'key' => ['name' => $key?->name, 'prefix' => $key?->key_prefix, 'ability' => $key?->ability],
            'user' => ['id' => $user->id, 'username' => $user->username],
            'warehouses' => Warehouse::whereIn('id', $this->userWarehouseIds() ?: [0])->get(['id', 'name']),
            'currency' => (new \App\utils\helpers)->Get_Currency(),
        ]]);
    }

    public function warehouses(Request $request)
    {
        return response()->json(['data' => Warehouse::whereNull('deleted_at')->whereIn('id', $this->userWarehouseIds() ?: [0])->orderBy('id')->get(['id', 'name'])]);
    }

    public function suppliers(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);
        $query = Provider::whereNull('deleted_at')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', '%'.$request->query('search').'%'))
            ->orderBy('id');

        return response()->json($this->paginated($query, $request, fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'email' => $p->email, 'phone' => $p->phone,
        ], 100, 500));
    }
}
