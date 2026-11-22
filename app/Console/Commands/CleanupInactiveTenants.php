<?php

namespace App\Console\Commands;

use App\Models\Central\GeneralSetting;
use App\Models\Central\TenantSubscription;
use App\Services\EmailNotificationService;
use App\Tenant;
use Illuminate\Console\Command;

class CleanupInactiveTenants extends Command
{
    protected $signature = 'tenant:cleanup-inactive
                            {--suspend-after-days= : Suspend tenants X days after subscription expires (default: Settings > Subscription Reminders; 0 = never)}
                            {--delete-after-days= : Delete tenants inactive for X days (default: Settings > Subscription Reminders; 0 = never)}
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Suspend tenants with expired subscriptions and delete long-inactive/abandoned tenants';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $settings = GeneralSetting::instance();
        $suspendDays = $this->option('suspend-after-days') !== null
            ? (int) $this->option('suspend-after-days')
            : $settings->suspendUnpaidAfterDays();
        $deleteDays = $this->option('delete-after-days') !== null
            ? (int) $this->option('delete-after-days')
            : $settings->deleteInactiveAfterDays();

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        if ($suspendDays > 0) {
            $this->suspendExpired($suspendDays, $dryRun);
        } else {
            $this->info('--- Auto-suspend is off ---');
        }

        if ($deleteDays > 0) {
            $this->deleteAbandoned($deleteDays, $dryRun);
        } else {
            $this->info('--- Auto-delete is off ---');
        }

        $this->info('Cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * Suspend active tenants whose subscription expired X days ago.
     */
    protected function suspendExpired(int $days, bool $dryRun): void
    {
        $this->info("--- Checking for tenants to suspend (expired > {$days} days) ---");

        // Judge ALL of the tenant's rows, not just "an expired one": every
        // converted trial leaves an old expired row behind, which used to
        // trigger a suspension on day 0 of a lapse (or while a cancelled
        // subscription was still inside its paid period).
        $cutoff = now()->subDays($days);
        $tenants = Tenant::where('status', Tenant::STATUS_ACTIVE)
            // Nothing grants access now — grace included.
            ->whereDoesntHave('subscriptions', fn ($q) => $q->grantingAccess())
            // It had a period that ended…
            ->whereHas('subscriptions', fn ($q) => $q->where(fn ($e) => $e->whereNotNull('ends_at')->orWhereNotNull('trial_ends_at')))
            // …and none of its periods ended within the last N days.
            ->whereDoesntHave('subscriptions', fn ($q) => $q->where(fn ($e) => $e->where('ends_at', '>=', $cutoff)->orWhere('trial_ends_at', '>=', $cutoff)))
            // A payment waiting for review (e.g. a bank proof) is not "unpaid".
            ->whereDoesntHave('payments', fn ($q) => $q->where('status', \App\Models\Central\TenantBillingPayment::STATUS_PENDING))
            ->with('domains')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('  No tenants to suspend.');
            return;
        }

        foreach ($tenants as $tenant) {
            $domain = $tenant->domains->first()?->domain ?? $tenant->id;
            $this->line("  Suspending: {$tenant->company_name} ({$domain})");

            if (! $dryRun) {
                // Snapshot pre-suspension status so an admin reactivate() restores the real prior state.
                $tenant->status_before_suspension = $tenant->status;
                $tenant->status = Tenant::STATUS_SUSPENDED;
                // Non-payment: the owner can still sign in and pay, and
                // paying lifts the suspension (Tenant::liftNonPaymentSuspension).
                $tenant->suspension_reason = Tenant::SUSPENDED_NON_PAYMENT;
                $tenant->save();
            }
        }

        $this->info("  " . ($dryRun ? 'Would suspend' : 'Suspended') . ": {$tenants->count()} tenant(s).");
    }

    /**
     * Delete tenants that are truly abandoned:
     * - Never had any activity (last_activity_at is null) and created > X days ago
     * - OR last activity was > X days ago AND subscription is expired/none
     */
    protected function deleteAbandoned(int $days, bool $dryRun): void
    {
        $this->info("--- Checking for abandoned tenants to delete (inactive > {$days} days) ---");

        $cutoff = now()->subDays($days);

        $tenants = Tenant::where(function ($q) use ($cutoff) {
                // Never used and created long ago
                $q->where(function ($q2) use ($cutoff) {
                    $q2->whereNull('last_activity_at')
                       ->where('created_at', '<', $cutoff);
                })
                // Or last activity was long ago
                ->orWhere('last_activity_at', '<', $cutoff);
            })
            // …AND it has been in this state that long: a prepaid tenant that
            // stopped using the app months ago and was suspended yesterday
            // must not be deleted on the next run. updated_at moves on the
            // suspension (and on any later subscription change), so it is a
            // safe lower bound for "suspended/cancelled/failed since".
            ->where('updated_at', '<', $cutoff)
            // Only delete suspended/cancelled/failed tenants, not active ones
            ->whereIn('status', [
                Tenant::STATUS_SUSPENDED,
                Tenant::STATUS_CANCELLED,
                Tenant::STATUS_FAILED,
            ])
            ->with('domains')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('  No abandoned tenants to delete.');
            return;
        }

        foreach ($tenants as $tenant) {
            $domain = $tenant->domains->first()?->domain ?? $tenant->id;
            $lastSeen = $tenant->last_activity_at ? $tenant->last_activity_at->diffForHumans() : 'never';
            $this->line("  Deleting: {$tenant->company_name} ({$domain}) — last seen: {$lastSeen}");

            if (! $dryRun) {
                // Clean up related records, then delete tenant (triggers DB drop via stancl/tenancy)
                $tenant->subscriptions()->delete();
                $tenant->payments()->delete();
                $tenant->domains()->delete();
                $tenant->delete();
            }
        }

        $this->info("  " . ($dryRun ? 'Would delete' : 'Deleted') . ": {$tenants->count()} tenant(s).");
    }
}
