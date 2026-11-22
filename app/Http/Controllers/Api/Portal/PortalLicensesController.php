<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\LicenseActivation;
use App\Models\LicenseKey;
use App\Services\LicenseKeys\LicenseFulfilment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Client portal → Licenses: the customer's software license keys (every sale
 * of theirs), with expiry and activations for generated licenses. The nav
 * item shows only while summary() says the customer holds a key.
 */
class PortalLicensesController extends Controller
{
    private const VISIBLE = [LicenseKey::STATUS_SOLD, LicenseKey::STATUS_SUSPENDED, LicenseKey::STATUS_REVOKED];

    // GET /api/portal/licenses/summary
    public function summary()
    {
        $clientId = $this->clientId();
        $count = $clientId && LicenseFulfilment::ready()
            ? LicenseKey::where('client_id', $clientId)->whereIn('status', self::VISIBLE)->count()
            : 0;

        return response()->json(['enabled' => $count > 0, 'count' => $count]);
    }

    // GET /api/portal/licenses
    public function index(Request $request)
    {
        $clientId = $this->clientId();
        if (! $clientId || ! LicenseFulfilment::ready()) {
            return response()->json(['licenses' => [], 'totalRows' => 0]);
        }

        $keys = LicenseKey::with(['product:id,name', 'variant:id,name', 'sale:id,Ref'])
            ->where('client_id', $clientId)
            ->whereIn('status', self::VISIBLE)
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim((string) $request->search);
                $q->where(fn ($w) => $w->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('sale', fn ($p) => $p->where('Ref', 'like', "%{$s}%")));
            })
            ->orderByDesc('sold_at')->orderByDesc('id')
            ->limit(500)
            ->get();

        $activations = $keys->isEmpty() ? collect() : LicenseActivation::whereIn('license_key_id', $keys->pluck('id'))
            ->whereNull('deactivated_at')->orderBy('activated_at')->get()->groupBy('license_key_id');

        $rows = $keys->map(function (LicenseKey $k) use ($activations) {
            $state = $k->isGenerated() ? $k->licenseState() : ($k->status === LicenseKey::STATUS_SOLD ? 'delivered' : $k->licenseState());

            return [
                'id' => $k->id,
                'product' => $k->product->name ?? '',
                'edition' => $k->variant->name ?? null,
                'key' => $state === 'revoked' ? $k->maskedKey() : $k->plainKey(),
                'generated' => $k->isGenerated(),
                'state' => $state,
                'sale_ref' => $k->sale->Ref ?? null,
                'purchased_at' => optional($k->sold_at)->toDateString(),
                'expires_at' => $k->isGenerated() ? optional($k->expires_at)->toDateString() : null,
                'max_activations' => $k->max_activations,
                'restriction' => $k->restriction ?: 'none',
                'activations' => ($activations[$k->id] ?? collect())->map(fn ($a) => [
                    'id' => $a->id,
                    'kind' => $a->kind,
                    'identifier' => $a->identifier,
                    'label' => $a->label,
                    'activated_at' => optional($a->activated_at)->toDateString(),
                ])->values()->all(),
            ];
        })->values();

        return response()->json(['licenses' => $rows, 'totalRows' => $rows->count()]);
    }

    // POST /api/portal/licenses/{id}/activations/{activation}/deactivate
    public function deactivate($id, $activation)
    {
        $clientId = $this->clientId();
        if (! $clientId || ! LicenseFulfilment::ready()) {
            abort(404);
        }
        $key = LicenseKey::where('client_id', $clientId)->where('source', LicenseKey::SOURCE_GENERATED)->findOrFail((int) $id);
        $n = LicenseActivation::where('license_key_id', $key->id)->whereKey((int) $activation)
            ->whereNull('deactivated_at')->update(['deactivated_at' => now(), 'updated_at' => now()]);
        if ($n) {
            app(LicenseFulfilment::class)->event($key->id, 'deactivated', 'By customer (portal)', null, ['via' => 'portal']);
        }

        return response()->json(['success' => (bool) $n]);
    }

    private function clientId(): ?int
    {
        $portalClient = Auth::guard('portal')->user();
        if (! $portalClient) {
            abort(401);
        }
        if ((int) $portalClient->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }

        return $portalClient->client_id ? (int) $portalClient->client_id : null;
    }
}
