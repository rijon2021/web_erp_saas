<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\OpencartOrderWebhookJob;
use App\Models\OpencartSetting;
use App\Services\Opencart\Client;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Public endpoint the connector posts to: /api/opencart/webhook/{token}.
 * The token finds the right door; the signature proves the sender holds the
 * secret. The body only names an order, which is then read back over the
 * signed API, so a forged payload could not say anything about it anyway.
 */
class OpencartWebhookController extends Controller
{
    /** Same drift the connector allows the other way. */
    private const MAX_SKEW = 300;

    public function handle(Request $request, string $token)
    {
        // The store gives up after a few seconds so its checkout is not held
        // up. Answering a sender that has gone would otherwise abort PHP right
        // there, before the after-response job that does the work.
        ignore_user_abort(true);

        if (! Schema::hasTable('opencart_settings')) {
            return response()->json(['ok' => false], 404);
        }

        $settings = OpencartSetting::query()->first();
        if (! $settings || empty($settings->webhook_token) || ! hash_equals((string) $settings->webhook_token, $token)) {
            return response()->json(['ok' => false], 404);
        }

        $timestamp = (string) $request->header('X-Connector-Timestamp', '');
        $signature = (string) $request->header('X-Connector-Signature', '');
        $body = (string) $request->getContent();

        if ($timestamp === '' || abs(time() - (int) $timestamp) > self::MAX_SKEW
            || ! hash_equals(Client::signature((string) $settings->secret, 'webhook', $timestamp, $body), $signature)) {
            return response()->json(['ok' => false], 401);
        }

        $settings->forceFill(['last_webhook_at' => now()])->save();

        $orderId = (int) $request->input('order_id', 0);

        // Answered in the positive either way, so the store is not left waiting.
        if (! $orderId || ! $settings->enabled || ! $settings->auto_import_orders
            || ! app(TenantLimitsService::class)->hasFeature('opencart')) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        OpencartOrderWebhookJob::dispatchAfterResponse($orderId);

        return response()->json(['ok' => true]);
    }
}
