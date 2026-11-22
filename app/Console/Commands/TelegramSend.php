<?php

namespace App\Console\Commands;

use App\Services\Notifications\ChannelNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends the Telegram notifications waiting on the queue — the ones that
 * could not go out right after the request (a failed send being retried, or
 * an event raised by an artisan command / queued job). Drains only the
 * dedicated 'telegram' queue, so it can be given a cron entry of its own
 * without also running webhooks, syncs or emails.
 */
class TelegramSend extends Command
{
    protected $signature = 'telegram:send
        {--max-time=55 : Seconds to keep draining before exiting}';

    protected $description = 'Send queued Telegram notifications (retries and background events)';

    public function handle(): int
    {
        // Driven straight from cron there is no withoutOverlapping(), so a
        // slow Telegram API must not let two runs pile up on each other.
        $lock = Cache::lock('telegram:send', 120);
        if (! $lock->get()) {
            $this->line('Another telegram:send run is still working.');

            return self::SUCCESS;
        }

        // With the sync driver every message was already sent when queued.
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            $lock->release();
            $this->line('QUEUE_CONNECTION is sync — nothing is ever queued.');

            return self::SUCCESS;
        }

        try {
            Artisan::call('queue:work', [
                'connection' => $connection,
                '--queue' => ChannelNotifier::TELEGRAM_QUEUE,
                '--stop-when-empty' => true,
                '--max-time' => max(5, (int) $this->option('max-time')),
                '--sleep' => 1,
                '--timeout' => 30,
            ]);
            $this->line(trim(Artisan::output()) ?: 'Nothing waiting.');
        } catch (Throwable $e) {
            $this->warn($e->getMessage());
            report($e);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
