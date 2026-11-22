<?php

namespace App\Jobs\Printful;

use App\Models\PrintfulOrder;
use App\Models\PrintfulSetting;
use App\Services\Printful\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one queued online order to Printful. Settings and the plan / module
 * switch are re-read at run time, so switching Printful off stops queued
 * sends. submit() never throws: retryable failures get a retry_at that the
 * printful:send-orders sweep honours, so the job itself runs once.
 */
class SubmitPrintfulOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Above the client's worst case (45 s request, rate-limit waits, the duplicate lookup). */
    public int $timeout = 240;

    public function __construct(public int $printfulOrderId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        if (! OrderService::enabled()) {
            return;
        }
        $settings = PrintfulSetting::query()->first();
        if (! $settings || ! $settings->isReady()) {
            return;
        }

        $po = PrintfulOrder::find($this->printfulOrderId);
        if (! $po || $po->printful_order_id || ! in_array($po->status, [PrintfulOrder::STATUS_QUEUED, PrintfulOrder::STATUS_ERROR], true)) {
            return;
        }

        OrderService::make($settings)->submit($po);
    }
}
