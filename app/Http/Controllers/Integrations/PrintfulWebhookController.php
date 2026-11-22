<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\PrintfulSetting;
use App\Services\Printful\OrderService;
use Illuminate\Http\Request;

/**
 * Public endpoint Printful posts webhooks to: /api/printful/webhook/{token}.
 * Printful's v1 webhooks are not signed, so the secret token in the URL is
 * what authenticates the call — and order events are re-read from the API
 * before anything changes. Envelope: {type, created, retries, store, data}.
 */
class PrintfulWebhookController extends Controller
{
    public function handle(Request $request, string $token)
    {
        if (! OrderService::installed()) {
            return response()->json(['ok' => false], 404);
        }

        $settings = PrintfulSetting::query()->first();
        if (! $settings || empty($settings->webhook_token) || ! hash_equals((string) $settings->webhook_token, $token)) {
            return response()->json(['ok' => false], 404);
        }
        if (! $settings->isReady() || ! OrderService::enabled()) {
            // Acknowledge so Printful stops retrying while the store is off.
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $type = (string) $request->input('type', '');
        $data = (array) $request->input('data', []);
        $storeId = (int) $request->input('store', 0);
        if ($settings->store_id && $storeId && $storeId !== (int) $settings->store_id) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        try {
            OrderService::make($settings)->handleWebhook($type, $data);
        } catch (\Throwable $e) {
            OrderService::make($settings)->log('webhook.'.$type, 'error', $e->getMessage());

            // Printful retries failed deliveries.
            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
