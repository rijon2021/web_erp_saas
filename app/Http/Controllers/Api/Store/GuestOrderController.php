<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;

/**
 * Guest checkout: a shopper who ordered without a store account reaches their
 * order through the secret link (/order/{token}) shown on the thank-you page.
 * The link stays valid even after the admin switches guest checkout off, so
 * existing orders remain reachable.
 */
class GuestOrderController extends Controller
{
    /** The guest order behind a secret link (404 for anything else). */
    public static function orderForToken($token): OnlineOrder
    {
        $token = (string) $token;
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return OnlineOrder::where('guest_token', $token)
            ->where('is_guest', true)
            ->firstOrFail();
    }

    /** GET /order/{token} — the order page (same view as the account one). */
    public function show($token)
    {
        $order = self::orderForToken($token);

        return view('store.order-show', [
            'id' => $order->id,
            'guestToken' => $order->guest_token,
        ]);
    }

    /** GET /order/{token}/data — the order-page payload. */
    public function data($token)
    {
        $order = self::orderForToken($token);

        return response()->json(app(MyOrdersApiController::class)->present($order));
    }
}
