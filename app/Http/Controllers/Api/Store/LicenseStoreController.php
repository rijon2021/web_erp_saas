<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\LicenseActivation;
use App\Models\LicenseKey;
use App\Services\LicenseKeys\LicenseFulfilment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Storefront → My Account → My Licenses: the signed-in shopper's software
 * license keys (every sale of theirs, online or in-store), with expiry and
 * activated devices / domains for generated licenses. A shopper may free one
 * of their own activation slots (e.g. an old computer).
 */
class LicenseStoreController extends Controller
{
    /** Does this customer hold any license key? (account menu link) */
    public static function hasKeys(?int $clientId): bool
    {
        if (! $clientId || ! LicenseFulfilment::ready()) {
            return false;
        }
        static $memo = [];

        return $memo[$clientId] ??= LicenseKey::where('client_id', $clientId)
            ->whereIn('status', [LicenseKey::STATUS_SOLD, LicenseKey::STATUS_SUSPENDED, LicenseKey::STATUS_REVOKED])
            ->exists();
    }

    // GET /account/licenses
    public function page()
    {
        $user = Auth::guard('store')->user();
        $clientId = $user && $user->client_id ? (int) $user->client_id : null;
        if (! LicenseFulfilment::ready()) {
            abort(404);
        }

        // Keys are secrets: shown only once the account's e-mail is verified
        // (registration does not prove it, and guest orders link by e-mail).
        if (! StoreEmailVerificationController::verified($user)) {
            return view('store.account-licenses', [
                'licenses' => collect(),
                'needsVerification' => true,
                'email' => $user->email ?? '',
                'keyCount' => $clientId ? LicenseKey::where('client_id', $clientId)->whereIn('status', [LicenseKey::STATUS_SOLD, LicenseKey::STATUS_SUSPENDED, LicenseKey::STATUS_REVOKED])->count() : 0,
            ]);
        }

        $keys = $clientId
            ? LicenseKey::with(['product:id,name,image', 'variant:id,name', 'sale:id,Ref'])
                ->where('client_id', $clientId)
                ->whereIn('status', [LicenseKey::STATUS_SOLD, LicenseKey::STATUS_SUSPENDED, LicenseKey::STATUS_REVOKED])
                ->orderByDesc('sold_at')->orderByDesc('id')
                ->limit(500)
                ->get()
            : collect();

        $activations = $keys->isEmpty() ? collect() : LicenseActivation::whereIn('license_key_id', $keys->pluck('id'))
            ->whereNull('deactivated_at')->orderBy('activated_at')->get()->groupBy('license_key_id');

        $orderRefs = $keys->pluck('online_order_id')->filter()->unique()->isEmpty() ? collect()
            : DB::table('online_orders')->whereIn('id', $keys->pluck('online_order_id')->filter()->unique())->pluck('ref', 'id');

        $licenses = $keys->map(function (LicenseKey $k) use ($activations, $orderRefs) {
            $state = $k->isGenerated() ? $k->licenseState() : ($k->status === LicenseKey::STATUS_SOLD ? 'delivered' : $k->licenseState());

            return [
                'id' => $k->id,
                'product' => $k->product->name ?? '',
                'edition' => $k->variant->name ?? null,
                'key' => $state === 'revoked' ? $k->maskedKey() : $k->plainKey(),
                'generated' => $k->isGenerated(),
                'state' => $state,
                'order_ref' => $k->online_order_id ? ($orderRefs[$k->online_order_id] ?? null) : ($k->sale->Ref ?? null),
                'order_id' => $k->online_order_id,
                'purchased_at' => optional($k->sold_at)->toDateString(),
                'expires_at' => $k->isGenerated() ? optional($k->expires_at)->toDateString() : null,
                'max_activations' => $k->max_activations,
                'restriction' => $k->restriction,
                'activations' => ($activations[$k->id] ?? collect())->map(fn ($a) => [
                    'id' => $a->id,
                    'identifier' => $a->identifier,
                    'label' => $a->label,
                    'kind' => $a->kind,
                    'activated_at' => optional($a->activated_at)->toDateString(),
                ])->values()->all(),
            ];
        });

        return view('store.account-licenses', ['licenses' => $licenses]);
    }

    // POST /my/licenses/{id}/activations/{activation}/deactivate
    public function deactivate(Request $request, $id, $activation)
    {
        $user = Auth::guard('store')->user();
        if (! $user || ! $user->client_id || ! LicenseFulfilment::ready() || ! StoreEmailVerificationController::verified($user)) {
            abort(404);
        }
        $key = LicenseKey::where('client_id', (int) $user->client_id)
            ->where('source', LicenseKey::SOURCE_GENERATED)
            ->findOrFail((int) $id);
        $n = LicenseActivation::where('license_key_id', $key->id)->whereKey((int) $activation)
            ->whereNull('deactivated_at')->update(['deactivated_at' => now(), 'updated_at' => now()]);
        if ($n) {
            app(LicenseFulfilment::class)->event($key->id, 'deactivated', 'By customer', null, ['via' => 'store']);
        }

        return back()->with('license_status', __('messages.License_Device_Removed'));
    }
}
