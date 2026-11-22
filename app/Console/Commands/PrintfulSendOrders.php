<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForFeatureTenants;
use App\Models\OnlineOrder;
use App\Models\PrintfulMapping;
use App\Models\PrintfulOrder;
use App\Models\PrintfulSetting;
use App\Services\Printful\OrderService;
use Illuminate\Console\Command;

/**
 * Safety net for Printful order sending, per tenant on a plan with
 * `printful`:
 *  - queued rows the queue never ran, and failed sends due a retry
 *    (network / rate limit), are sent here;
 *  - orders that met the store's trigger without passing the model hook
 *    (payment callbacks update rows in bulk) are queued — only those that
 *    reached the trigger after automatic sending was switched on, so orders
 *    the store handled itself before are never sent behind its back.
 */
class PrintfulSendOrders extends Command
{
    use RunsForFeatureTenants;

    protected $signature = 'printful:send-orders';

    protected $description = 'Send storefront orders that are due to Printful but were not queued (or never left the queue)';

    public function handle(): int
    {
        $this->forFeatureTenants('printful', function ($tenant) {
            [$sent, $queued] = $this->sweep();
            if ($sent || $queued) {
                $this->info("[{$tenant->id}] {$sent} order(s) sent to Printful, {$queued} queued.");
            }
        });

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} sent, queued */
    private function sweep(): array
    {
        if (! OrderService::enabled()) {
            return [0, 0];
        }
        $settings = PrintfulSetting::query()->first();
        if (! $settings || ! $settings->isReady()) {
            return [0, 0];
        }
        $service = OrderService::make($settings);
        $sent = 0;

        // Queued more than 10 minutes ago and still waiting, or a failed send
        // whose retry time has come: send now.
        $due = PrintfulOrder::whereNull('printful_order_id')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('status', PrintfulOrder::STATUS_QUEUED)->where('updated_at', '<', now()->subMinutes(10)))
                ->orWhere(fn ($w) => $w->where('status', PrintfulOrder::STATUS_ERROR)->whereNotNull('retry_at')->where('retry_at', '<=', now())))
            ->orderBy('id')
            ->limit(25)->get();
        foreach ($due as $po) {
            try {
                $service->submit($po);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! $settings->sendsAutomatically() || ! $settings->auto_since) {
            return [$sent, 0];
        }

        // Due by the trigger since automatic sending was switched on, never
        // queued, holding at least one Printful product.
        $orders = OnlineOrder::query()
            ->where('created_at', '>=', now()->subDays(14))
            ->where('updated_at', '>=', $settings->auto_since)
            ->whereNotIn('id', PrintfulOrder::query()->select('online_order_id'))
            ->where(fn ($q) => $q->whereNull('delivery_method')->orWhere('delivery_method', '!=', 'pickup'))
            ->whereHas('items', fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('product_variant_id')
                    ->whereIn('product_variant_id', PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_VARIANT)->select('local_id')))
                ->orWhere(fn ($w) => $w->whereNull('product_variant_id')
                    ->whereIn('product_id', PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_SINGLE)->select('local_id'))))
            ->when($settings->submit_on === PrintfulSetting::SUBMIT_PAID,
                fn ($q) => $q->where('payment_status', 'paid')->whereIn('status', ['pending', 'confirmed']),
                fn ($q) => $q->where('status', 'confirmed'))
            ->with('items')
            ->orderBy('id')
            ->limit(50)
            ->get();

        // queue() hands them to the job; a queue that never runs is caught
        // by the queued-row pass above on a later run.
        $queued = 0;
        foreach ($orders as $order) {
            try {
                if ($service->queue($order)) {
                    $queued++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [$sent, $queued];
    }
}
