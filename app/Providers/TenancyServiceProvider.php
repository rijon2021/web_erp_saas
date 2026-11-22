<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

/**
 * Central (SaaS) tenancy: tenant DB creation, migrations, routes.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public static string $controllerNamespace = '';

    public function events(): array
    {
        return [
            Events\CreatingTenant::class => [],

            // Empty — ProvisionTenantWorkspace handles DB creation,
            // migrations, seeding, admin user, passport, and company config.
            Events\TenantCreated::class => [],

            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [
                JobPipeline::make([Jobs\DeleteDatabase::class])
                    ->send(fn (Events\TenantDeleted $event) => $event->tenant)
                    ->shouldBeQueued(false),
            ],
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],
            Events\InitializingTenancy::class => [
                // Fires while the OUTGOING context is still current, which is
                // where anything the Shopify stock watcher or the WooCommerce
                // automatic sync buffered belongs.
                \App\Listeners\ResetShopifyStockWatcher::class,
                \App\Listeners\ResetWooCommerceAutoSync::class,
                \App\Listeners\ResetOpencartAutoSync::class,
            ],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
                \App\Listeners\ApplyTenantTimezone::class,
                // Error Logs report: tag log records with the tenant id.
                \App\Listeners\TagLogsWithTenant::class,
            ],
            Events\EndingTenancy::class => [
                \App\Listeners\ResetShopifyStockWatcher::class,
                \App\Listeners\ResetWooCommerceAutoSync::class,
                \App\Listeners\ResetOpencartAutoSync::class,
            ],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
                \App\Listeners\RevertTenantTimezone::class,
                \App\Listeners\TagLogsWithTenant::class,
            ],
            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [
                \App\Listeners\ConfigureTenantDatabaseConnection::class,
                // Client portal strings come from the tenant DB (PortalTranslationLoader).
                \App\Listeners\ResetTranslatorOnTenancySwitch::class,
            ],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [
                \App\Listeners\ResetTranslatorOnTenancySwitch::class,
            ],
            Events\SyncedResourceSaved::class => [Listeners\UpdateSyncedResource::class],
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->mapRoutes();
        $this->makeTenancyMiddlewareHighestPriority();
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }
                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes(): void
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $kernel->prependToMiddlewarePriority($middleware);
        }
    }
}