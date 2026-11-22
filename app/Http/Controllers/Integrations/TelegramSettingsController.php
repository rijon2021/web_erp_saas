<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TelegramSetting;
use App\Services\Notifications\ChannelNotifier;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Telegram integration settings: bot token + chat id, enable switch,
 * subscribed events, and a test send. Gated by the `telegram_settings`
 * permission; routes by `tenant.feature:telegram`. The bot token is
 * write-only: reads expose a recognisable tail only.
 */
class TelegramSettingsController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', TelegramSetting::class);

        $settings = TelegramSetting::current();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'configured' => $settings->isConfigured(),
            'bot_token_hint' => $settings->bot_token ? '…' . substr($settings->bot_token, -4) : null,
            'chat_id' => $settings->chat_id,
            'events' => $settings->events ?: [],
            'available_events' => WebhookService::availableEvents(),
            // No 'cron' key: the server's cron and path belong to the platform
            // operator, not to tenants — the guide shows its cron step only
            // when the API supplies a line.
            'queue' => $this->queueCounts(),
        ]);
    }

    /**
     * This tenant's messages waiting for telegram:send, and those that gave up
     * retrying. The jobs table is central and shared by every tenant, so rows
     * are matched on the tenant id QueueTenancyBootstrapper writes into each
     * payload. Null when nothing is queued at all (sync driver).
     */
    private function queueCounts(): array
    {
        try {
            $default = (string) config('queue.default');
            $tenantId = tenant('id');
            if ($default === 'sync' || ! $tenantId) {
                return ['waiting' => null, 'failed' => null];
            }

            $tenantLike = '%"tenant_id":' . json_encode((string) $tenantId) . '%';
            $failed = config('queue.failed');

            return [
                'waiting' => DB::connection(config("queue.connections.{$default}.connection"))
                    ->table(config("queue.connections.{$default}.table", 'jobs'))
                    ->where('queue', ChannelNotifier::TELEGRAM_QUEUE)
                    ->where('payload', 'like', $tenantLike)
                    ->count(),
                'failed' => DB::connection($failed['database'] ?? null)
                    ->table($failed['table'] ?? 'failed_jobs')
                    ->where('queue', ChannelNotifier::TELEGRAM_QUEUE)
                    ->where('payload', 'like', $tenantLike)
                    ->count(),
            ];
        } catch (Throwable $e) {
            return ['waiting' => null, 'failed' => null];
        }
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', TelegramSetting::class);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'bot_token' => 'nullable|string|max:191',
            'chat_id' => 'nullable|string|max:64',
            'events' => 'nullable|array',
            'events.*' => 'string',
        ]);

        $settings = TelegramSetting::current();
        $settings->enabled = (bool) $data['enabled'];
        // Write-only secret: only replace when the request carries the field.
        if ($request->exists('bot_token')) {
            $settings->bot_token = $data['bot_token'] ?: null;
        }
        if ($request->exists('chat_id')) {
            $settings->chat_id = $data['chat_id'] ?: null;
        }
        if ($request->exists('events')) {
            $settings->events = array_values(array_intersect(
                $data['events'] ?? [],
                WebhookService::availableEvents()
            ));
        }
        $settings->save();

        return response()->json(['success' => true, 'configured' => $settings->isConfigured()]);
    }

    public function test(Request $request, ChannelNotifier $notifier)
    {
        $this->authorizeForUser($request->user('api'), 'update', TelegramSetting::class);

        if (! TelegramSetting::current()->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Add the bot token and chat id first.'], 422);
        }

        $result = $notifier->send(
            'telegram',
            "\u{2705} Test notification from ".$this->tenantAppName().' — your Telegram integration works.'
        );

        return response()->json(['ok' => $result['success'], 'error' => $result['error']], $result['success'] ? 200 : 422);
    }

    /** The tenant's app name (the part before "|"), or a neutral fallback. */
    private function tenantAppName(): string
    {
        try {
            $raw = (string) (Setting::where('deleted_at', null)->value('app_name') ?? '');
        } catch (Throwable) {
            $raw = '';
        }
        $name = trim(explode('|', $raw)[0]);

        return $name !== '' ? $name : 'your POS';
    }
}
