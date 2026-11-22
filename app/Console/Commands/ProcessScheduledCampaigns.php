<?php

namespace App\Console\Commands;

use App\Jobs\SendMarketingCampaign;
use App\Models\MarketingCampaign;
use App\Models\MarketingSetting;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledCampaigns extends Command
{
    protected $signature = 'marketing:process-scheduled';

    protected $description = 'Dispatch marketing campaigns whose scheduled send time has arrived';

    public function handle()
    {
        // Marketing tables live in each tenant DB — this must run per tenant,
        // never on the central connection. Only tenants whose plan has the
        // module are entered: this runs every minute.
        foreach (Tenant::activeWithFeature('marketing') as $tenant) {
            try {
                $tenant->run(fn () => $this->processTenant($tenant));
            } catch (\Illuminate\Database\QueryException $e) {
                // Tenant DB not migrated for the marketing feature yet — skip quietly.
                if (str_contains($e->getMessage(), 'Base table or view not found')) {
                    continue;
                }
                Log::warning("marketing:process-scheduled failed for tenant {$tenant->id}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                Log::warning("marketing:process-scheduled failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        return 0;
    }

    protected function processTenant(Tenant $tenant): void
    {
        $settings = MarketingSetting::current();

        if (! $settings->scheduling_enabled) {
            return;
        }

        // Only tenants whose plan has the module are swept, so a campaign that
        // came due while the plan lapsed waits here. Once more than a day late
        // it is cancelled, not sent: renewing must not blast every overdue
        // campaign — some weeks old — in the same minute.
        $staleBefore = Carbon::now()->subDay();
        $stale = MarketingCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', $staleBefore)
            ->get();
        foreach ($stale as $campaign) {
            $campaign->update(['status' => 'cancelled']);
            Log::info("marketing:process-scheduled cancelled overdue campaign #{$campaign->id}", ['tenant' => $tenant->id, 'scheduled_at' => (string) $campaign->scheduled_at]);
            $this->info("[{$tenant->id}] Cancelled campaign #{$campaign->id} — more than a day overdue.");
        }

        $campaigns = MarketingCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$staleBefore, Carbon::now()])
            ->get();

        foreach ($campaigns as $campaign) {
            SendMarketingCampaign::dispatch($campaign->id);
            $this->info("[{$tenant->id}] Queued campaign #{$campaign->id} ({$campaign->title}).");
        }

        if ($campaigns->isNotEmpty()) {
            $this->info(sprintf('[%s] Processed %d scheduled campaign(s).', $tenant->id, $campaigns->count()));
        }
    }
}
