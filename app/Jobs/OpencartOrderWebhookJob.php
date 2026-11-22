<?php

namespace App\Jobs;

use App\Models\OpencartSetting;
use App\Services\Opencart\OrderSync;
use App\Services\Opencart\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs after the webhook has been answered, because the order is read back
 * from the store: a store still inside its checkout request should not have to
 * wait for that. A failure is logged and left to the scheduled pull.
 */
class OpencartOrderWebhookJob
{
    use Dispatchable, Queueable;

    public function __construct(private int $orderId)
    {
    }

    public function handle(): void
    {
        $settings = OpencartSetting::current();

        try {
            $result = OrderSync::make($settings)->syncOrder($this->orderId);
            SyncService::make($settings)->log('webhook.order', 'info', 'Order '.$this->orderId.': '.$result);
        } catch (\Throwable $e) {
            SyncService::make($settings)->log('webhook.order', 'error', 'Order '.$this->orderId.': '.$e->getMessage());
        }
    }
}
