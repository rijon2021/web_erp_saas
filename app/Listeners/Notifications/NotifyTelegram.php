<?php

namespace App\Listeners\Notifications;

use App\Listeners\Webhooks\DispatchWebhooks;
use App\Services\Notifications\ChannelNotifier;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Telegram's own listener for the webhook domain events. Deliberately NOT
 * queued (unlike DispatchWebhooks): it runs inside the request that raised the
 * event, so ChannelNotifier can send right after the response — no queue
 * worker or cron needed for anything saved in the app.
 */
class NotifyTelegram
{
    public function __construct(protected ChannelNotifier $notifier)
    {
    }

    public function handle(object $event): void
    {
        try {
            $eventName = DispatchWebhooks::EVENT_MAP[get_class($event)] ?? null;
            if (! $eventName) {
                return;
            }

            $model = null;
            foreach (get_object_vars($event) as $value) {
                if ($value instanceof Model) {
                    $model = $value;
                    break;
                }
            }

            $this->notifier->notifyTelegram(
                $eventName,
                $model,
                $model ? [] : ['attributes' => (array) $event]
            );
        } catch (Throwable $e) {
            // Never break the originating flow.
            report($e);
        }
    }
}
