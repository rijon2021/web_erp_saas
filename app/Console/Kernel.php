<?php

namespace App\Console;

use App\Jobs\Shopify\ShopifySyncJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        'App\Console\Commands\DatabaseBackUp',
        'App\Console\Commands\WooCommerceSync',
        'App\Console\Commands\ShopifySync',
        'App\Console\Commands\OpencartSync',
        'App\Console\Commands\SendMeetingReminders',
        'App\Console\Commands\ProcessScheduledCampaigns',
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        // Nightly central DB dump, newest 7 kept, written outside public/.
        // (This used to be `database:backup` with no frequency — a full dump of
        // the central DB every minute into storage/app/public.)
        $schedule->command('central:backup --keep=7')->dailyAt('03:00')->withoutOverlapping();

        // Plan-driven tenant backups and retention (Plan::RETENTION_FIELDS).
        // Hourly, but each tenant is only dumped when its plan's interval has
        // passed, so backups spread over the day. Both run in the background
        // so a long sweep never holds up the every-minute queue workers below.
        $schedule->command('tenants:backup')->hourly()->withoutOverlapping(180)->runInBackground();
        $schedule->command('tenants:prune-data')->dailyAt('04:00')->withoutOverlapping(180)->runInBackground();

        // The file cache never deletes entries nobody reads again.
        $schedule->command('cache:prune-expired-files')->dailyAt('04:30')->withoutOverlapping(60)->runInBackground();

        $schedule->command('subscriptions:check-expiry')->daily();
        // Add-ons: expire lapsed ones, email renewal links, drop unpaid purchases.
        $schedule->command('addons:check-expiry')->dailyAt('06:10')->withoutOverlapping(30);

        $schedule->command('tenant:cleanup-inactive')->daily();

        $schedule->command('assets:check-validation-due')->daily();

        // Fixed assets → journal: monthly depreciation as it accrues.
        $schedule->command('assets:post-depreciation')->dailyAt('02:15')->withoutOverlapping(60);

        $schedule->command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

        $schedule->command('marketing:process-scheduled')->everyMinute()->withoutOverlapping();

        // Telegram: queued notifications (failed sends being retried, events
        // raised outside a web request) for every tenant — the jobs table is
        // central and each job restores its tenant. A no-op while
        // QUEUE_CONNECTION=sync, where nothing is ever queued.
        $schedule->command('telegram:send')->everyMinute()->withoutOverlapping();

        // Shopify: work out which shops are due a scheduled sync and run those,
        // across every active tenant on a plan with the module. Five minutes is
        // the resolution of the per-store plan, not the sync rate — a store set
        // to sync hourly is still touched hourly; this is just how often the
        // question is asked. The command takes its own per-tenant lock, so a
        // catalogue push that runs long is never started twice.
        $schedule->command('shopify:sync')->everyFiveMinutes()->withoutOverlapping();

        // OpenCart: webhooks bring orders in as they happen; this catches what
        // they missed and reconciles stock hourly, across every tenant with it.
        $schedule->command('opencart:sync')->everyFiveMinutes()->withoutOverlapping();

        // Keep integration OAuth connections alive across ALL tenants: Salla
        // refresh tokens die after 1 month idle, Xero's after 60 days. A daily
        // authenticated ping refreshes them long before either cliff.
        $schedule->command('integrations:keep-alive')->daily()->withoutOverlapping();

        // UI Lib licence of the platform: refresh the signed token once a day
        // so it never reaches its 7-day offline expiry while ui-lib.com is reachable.
        $schedule->command('license:check')->daily()->withoutOverlapping();

        // Custom domains: the SSL badge turns active on its own once the
        // server/proxy serves a valid certificate (the platform issues none).
        $schedule->command('custom-domains:check-ssl')->hourly()->withoutOverlapping(30)->runInBackground();

        // Nightly Google Sheets snapshot for tenants that opted in (kept off
        // peak hours; full-tab rewrites can take a while on big datasets).
        $schedule->command('google-sheets:auto-export')->dailyAt('02:30')->withoutOverlapping();

        // Courier status sync: shipments still on the way are checked against
        // their courier (each tenant's Courier Settings interval decides which
        // are due). Webhooks are the fast path; this catches everything else.
        $schedule->command('couriers:sync')->everyFifteenMinutes()->withoutOverlapping(30);

        // Printful (tenants on a plan with `printful`): web orders that became
        // due without passing the model hook (payment callbacks update in
        // bulk), and queued sends the queue never ran.
        $schedule->command('printful:send-orders')->everyTenMinutes()->withoutOverlapping(20);

        // Angola AGT e-invoicing (tenants on a plan with `agt`): the API is
        // asynchronous — fetch the verdict of submitted invoices and re-send
        // the ones whose registration failed. AGT penalises documents
        // communicated more than 24h late, so keep this frequent.
        $schedule->command('agt:poll')->everyFiveMinutes()->withoutOverlapping(15);

        // Rental Management (tenants on a plan with `rental`): flip booked
        // items to rented as contracts start, issue due invoices, apply late
        // fees, open due maintenance — hourly; customer reminders once a day.
        $schedule->command('rental:sync-statuses')->hourly()->withoutOverlapping();
        $schedule->command('rental:send-reminders')->dailyAt('09:00')->withoutOverlapping();
        // CRM (tenants on a plan with `crm`): task reminders, then the daily
        // re-score + stale digest (+ Monday summary).
        $schedule->command('crm:send-reminders')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('crm:daily')->dailyAt('08:00')->withoutOverlapping();
        // Software License Keys: "your license expires in N days" emails.
        $schedule->command('licenses:send-reminders')->dailyAt('09:30')->withoutOverlapping();
        // Booking Management (tenants on a plan with `bookings`): reminders
        // before confirmed website bookings, each sent once.
        $schedule->command('bookings:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
        // …and website bookings whose deposit was not paid in time.
        $schedule->command('bookings:expire-deposits')->everyFiveMinutes()->withoutOverlapping();

        /**
         * Shared hosting friendly queue processing:
         * Each minute, drain pending jobs (including WooCommerce sync batches) until the
         * queues are empty or ~50s elapse, then exit so the next minute can resume.
         *
         * IMPORTANT: You must have a cron that runs `php artisan schedule:run` every minute.
         * The WooCommerce queues are listed explicitly because Woo sync batches re-dispatch
         * onto fixed queues (woocommerce-sync / woocommerce-stock); without them the sync
         * gets stuck at "queued_next_batch" when no Supervisor/systemd worker is running.
         *
         * For higher throughput, run a persistent worker via Supervisor instead (see
         * deploy/supervisor/stocky-queue-worker.conf). Both can coexist safely.
         */
        // 'webhooks' carries outgoing webhooks, Slack/Telegram notifications and
        // the Mailchimp/Xero auto-sync jobs — it must drain here too, or none of
        // them ever run on cron-only (no Supervisor) hosting.
        $schedule->command('queue:work database --stop-when-empty --max-time=50 --queue=woocommerce-sync,woocommerce-stock,opencart-stock,webhooks,default --sleep=1 --tries=1 --timeout='.((int) config('queue.worker_timeout')))
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

        // Shopify syncs started from the Sync Centre run here, one slice per job
        // (ShopifySyncJob re-queues itself until the run is done). A worker of its
        // own, as in Stocky, so a big catalogue sync never holds up webhooks and
        // a slice is never cut short by the shared worker's --max-time. Each job
        // carries its tenant (QueueTenancyBootstrapper). Without it a sync still
        // finishes, but only while the Sync Centre page stays open.
        $schedule->command('queue:work database --queue='.ShopifySyncJob::QUEUE.' --stop-when-empty --max-time=55 --sleep=1 --tries=1 --timeout=600')
            ->everyMinute()
            ->withoutOverlapping(15)
            ->evenInMaintenanceMode();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
