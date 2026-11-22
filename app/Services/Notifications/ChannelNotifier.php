<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendChannelNotificationJob;
use App\Listeners\Webhooks\DispatchWebhooks;
use App\Models\SlackSetting;
use App\Models\TelegramSetting;
use App\Services\Webhooks\Payload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fans canonical webhook events out to chat notification channels
 * (Slack incoming webhooks, Telegram bots). Mirrors the webhook module's
 * guarantee: never breaks business flow.
 *
 * Slack rides the queued webhook listener (WebhookDispatcher::dispatch).
 * Telegram has a listener of its own that runs inside the request
 * (Listeners\Notifications\NotifyTelegram → notifyTelegram()), so it can be
 * sent the moment the request is answered, with no queue worker or cron.
 */
class ChannelNotifier
{
    public const CHANNELS = ['slack', 'telegram'];

    /** Channels sent from the queued webhook fan-out. Telegram is not one. */
    public const QUEUED_CHANNELS = ['slack'];

    /**
     * Telegram's own queue, drained by `telegram:send`, so it can run from a
     * cron entry of its own. Slack stays on the shared 'webhooks' queue.
     */
    public const TELEGRAM_QUEUE = 'telegram';

    /** Seconds an after-response send may take — kept short, see sendNowOrQueue(). */
    protected const INSTANT_TIMEOUT = 5;

    /**
     * Set once an instant send has failed in this process, so the rest of the
     * request's messages are queued instead of each waiting out the timeout.
     */
    protected static bool $instantFailed = false;

    public static function queueFor(string $channel): string
    {
        return $channel === 'telegram' ? self::TELEGRAM_QUEUE : 'webhooks';
    }

    /**
     * Fan-out from the queued webhook listener. Slack always; Telegram only
     * for events its own listener does not see (e.g. product.low_stock, which
     * is emitted straight into WebhookDispatcher) — the model events in
     * DispatchWebhooks::EVENT_MAP already went out through notifyTelegram().
     */
    public function dispatch(string $event, array $payload): void
    {
        try {
            $text = null; // formatted lazily, only when a channel wants the event

            $channels = self::QUEUED_CHANNELS;
            if (! in_array($event, DispatchWebhooks::EVENT_MAP, true)) {
                $channels[] = 'telegram';
            }

            foreach ($channels as $channel) {
                if (! $this->wants($channel, $event)) {
                    continue;
                }

                $text = $text ?? ChannelMessage::format($event, $payload);
                $this->queue($channel, $text);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Telegram, called inside the request that raised the event.
     *
     * Nothing happens until the surrounding transaction commits — a sale that
     * rolls back sends nothing — and the message is built from the row as
     * committed, not the half-built one the `created` event fired on (a POS
     * sale fires it before its payments and status are written).
     *
     * In a web request it is then sent after the response has gone out: no
     * queue worker, no cron, and the cashier never waits for Telegram. On the
     * CLI or inside a queued job there is no response to come after, so it is
     * queued for `telegram:send`.
     */
    public function notifyTelegram(string $event, ?Model $model, array $payload = []): void
    {
        try {
            if (! $this->wants('telegram', $event)) {
                return;
            }

            DB::afterCommit(function () use ($event, $model, $payload) {
                if (app()->runningInConsole()) {
                    $this->queue('telegram', $this->format($event, $model, $payload));

                    return;
                }

                app()->terminating(function () use ($event, $model, $payload) {
                    $this->sendNowOrQueue('telegram', $this->format($event, $model, $payload));
                });
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Enabled, configured and subscribed to this event. */
    public function wants(string $channel, string $event): bool
    {
        $settings = $this->settings($channel);

        return $settings
            && $settings->enabled
            && $settings->isConfigured()
            && $settings->subscribesTo($event);
    }

    /**
     * Read-only settings lookup — deliberately NOT ::current(), so the hot
     * dispatch path never inserts rows into tenants that never opened the page.
     */
    public function settings(string $channel): SlackSetting|TelegramSetting|null
    {
        return $channel === 'slack'
            ? SlackSetting::query()->first()
            : TelegramSetting::query()->first();
    }

    /**
     * Synchronous send, shared by the queued job, the after-response path and
     * the settings pages' Test button. Returns ['success' => bool, 'error' =>
     * ?string]; never throws.
     */
    public function send(string $channel, string $text, int $timeout = 15): array
    {
        try {
            $settings = $this->settings($channel);
            if (! $settings || ! $settings->isConfigured()) {
                return ['success' => false, 'error' => 'Channel is not configured.'];
            }

            $http = Http::connectTimeout(min(5, $timeout))->timeout($timeout);
            $response = $channel === 'slack'
                ? $http->post($settings->webhook_url, ['text' => $text])
                : $http->post(
                    'https://api.telegram.org/bot' . $settings->bot_token . '/sendMessage',
                    ['chat_id' => $settings->chat_id, 'text' => $text]
                );

            if ($response->successful()) {
                return ['success' => true, 'error' => null];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . Str::limit((string) $response->body(), 200),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send now; a failure goes to the queue so hosts running `telegram:send`
     * still get the job's retries. The short timeout matters on servers that
     * cannot hand the response back before the script ends (mod_php without
     * FastCGI), where the browser does wait for this.
     */
    protected function sendNowOrQueue(string $channel, string $text): void
    {
        $error = null;
        if (! self::$instantFailed) {
            $result = $this->send($channel, $text, self::INSTANT_TIMEOUT);
            if ($result['success']) {
                return;
            }
            self::$instantFailed = true;
            $error = $result['error'];
        }

        // With the sync driver there is no queue to retry from: "queueing"
        // would only send again on the spot and make the response wait twice.
        if (config('queue.default') === 'sync') {
            Log::warning(ucfirst($channel) . ' notification not sent: ' . ($error ?? 'an earlier send in this request failed'));

            return;
        }

        $this->queue($channel, $text);
    }

    protected function queue(string $channel, string $text): void
    {
        try {
            SendChannelNotificationJob::dispatch($channel, $text)->onQueue(self::queueFor($channel));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The message for a model event, from the row as it is now; a deleted row
     * is gone (or hidden by soft deletes), so what the event carried is used.
     */
    protected function format(string $event, ?Model $model, array $payload): string
    {
        if ($model) {
            $fresh = null;
            try {
                $fresh = $model->exists ? $model->fresh() : null;
            } catch (Throwable $e) {
                // keep the in-memory copy
            }
            $payload = Payload::fromModel($fresh ?? $model);
        }

        return ChannelMessage::format($event, $payload);
    }
}
