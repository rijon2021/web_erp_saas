<?php

namespace App\Providers;

use App\Models\Central\GeneralSetting;
use App\Models\Setting;
use App\Services\TenantLimitsService;
use App\Tenant;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TenantLimitsService::class);
        // One instance per request (settings + method id are memoised; the
        // POS and Create Sale ask it once per payment line).
        $this->app->scoped(\App\Services\CustomerWallet\CustomerWalletService::class);

        // UI Lib licence client (reference implementation, global namespace,
        // so outside the PSR-4 autoloader — required here because an app
        // update never runs dump-autoload). The licence is the platform's,
        // stored in the central DB; tenants never see it. Defaults repeated
        // in case a cached config predates config/uilib.php.
        require_once app_path('Support/UiLibLicenseClient.php');
        $this->app->singleton(\UiLibLicenseClient::class, fn () => new \UiLibLicenseClient(
            (string) config('uilib.license_endpoint', 'https://ui-lib.com/wp-json/ul-license/v1/'),
            (string) config('uilib.license_public_key', 'vh/l6RU6dvuxtofYIOUT0HT/mSRdGHbPDF5vVe9291U='),
            (string) config('uilib.license_product', 'stocky-saas'),
        ));
        $this->app->singleton(\App\Services\Licensing\LicenseService::class);

        // Settings > Audit Log: one recorder per request / queued job.
        $this->app->scoped(\App\Services\Audit\AuditRecorder::class);
        // Super admin > Languages: central_translations rows override the lang files.
        $this->app->extend('translation.loader', fn ($files) => new \App\Translation\DatabaseTranslationLoader($files));
        // Client Portal > Portal Languages: the tenant's portal_translations rows override portal.php.
        $this->app->extend('translation.loader', fn ($loader) => new \App\Translation\PortalTranslationLoader($loader));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        Schema::defaultStringLength(191);

        // store_variant_options(), used by every storefront Blade surface that
        // exposes variants. An app update ships the file but never regenerates
        // the composer autoloader, so require it here too (function_exists()-
        // guarded, double load is a no-op).
        require_once app_path('Support/variant_options.php');

        // A queue worker is one long-lived process: start every job with a
        // fresh read of the tenant's settings row (see Setting::current()).
        \Illuminate\Support\Facades\Queue::before(fn () => Setting::forgetCurrent());

        // Any write to a document the dashboard sums drops the tenant's cached
        // dashboards, so a just-finished sale shows up at once.
        foreach (\App\Services\DashboardCache::MODELS as $model) {
            $model::saved(fn () => \App\Services\DashboardCache::bump());
            $model::deleted(fn () => \App\Services\DashboardCache::bump());
        }

        // Serial / IMEI: a cancelled, downgraded or deleted sale gives its units back.
        \App\Models\Sale::observe(\App\Observers\SaleSerialObserver::class);

        // Commissions follow later changes to a sale: payments, returns, deletion.
        \App\Models\Sale::observe(\App\Observers\CommissionSaleObserver::class);
        \App\Models\SaleReturn::observe(\App\Observers\CommissionSaleReturnObserver::class);

        // Settings > Audit Log: who created / changed / deleted what.
        foreach (\App\Services\Audit\AuditRegistry::MODELS as [$model]) {
            $model::observe(\App\Observers\AuditObserver::class);
        }

        /* ADD THIS LINES */
        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        // Blade directives: @tenantFeature('pos') ... @endTenantFeature
        Blade::if('tenantFeature', function (string $feature) {
            return app(TenantLimitsService::class)->hasFeature($feature);
        });

        // Blade directive: @tenantCanCreate('max_products') ... @endTenantCanCreate
        Blade::if('tenantCanCreate', function (string $limitKey) {
            return ! app(TenantLimitsService::class)->hasReachedLimit($limitKey);
        });

        // Runs for every view and partial (a dashboard page renders 70+), so
        // the currency is resolved once per request and reused.
        $currency = null;
        View::composer('*', function ($view) use (&$currency) {
            if ($currency === null) {
                $currency = ['currencySymbol' => '$', 'currencyCode' => 'USD'];
                if (file_exists(base_path('storage/app/public/installed'))) {
                    try {
                        $currency = [
                            'currencySymbol' => GeneralSetting::currencySymbol(),
                            'currencyCode'   => GeneralSetting::currencyCode(),
                        ];
                    } catch (\Throwable) {
                        // Not installed yet / no central DB: keep the defaults.
                    }
                }
            }
            // A public page priced in a visitor-chosen currency (multi-currency
            // pricing) shows that currency's symbol in every partial.
            $view->with(\App\Support\DisplayCurrency::viewData() ?? $currency);
        });

        // Settings are read once per process; a long-running queue worker
        // must not keep a snapshot from before an admin changed them.
        Queue::before(fn () => GeneralSetting::forgetInstance());

        View::composer('central.super.layout', function ($view) {
            // A page that already loaded the pending list (the dashboard's
            // pending_tenants widget) passes it down instead of re-querying.
            if (($view->getData()['pendingTenants'] ?? null) instanceof \Illuminate\Support\Collection) {
                return;
            }
            try {
                $pendingTenants = Tenant::where('status', Tenant::STATUS_PENDING)->latest()->get();
                $view->with('pendingTenants', $pendingTenants);
            } catch (\Throwable) {
                $view->with('pendingTenants', collect());
            }
        });

        View::composer('*', function ($view) {
            if (! file_exists(base_path('storage/app/public/installed'))) {
                $view->with('app_settings', null);
                return;
            }

            $excluded = [
                'api',
                'setup',
                'update',
                'password',
                'online_store',
                'super',
                'register',
                'checkout',
                'workspace',
                'errors',
            ];

            // The storefront may live under a custom base path (empty in
            // root-domain mode, where the '/' request is excluded anyway).
            $storeSegment = explode('/', store_path())[0];
            if ($storeSegment !== '') {
                $excluded[] = $storeSegment;
            }

            $firstSegment = Request::segment(1);

            if (! $firstSegment || in_array($firstSegment, $excluded)) {
                $view->with('app_settings', null);
                return;
            }

            try {
                $view->with('app_settings', Setting::first());
            } catch (\Throwable) {
                $view->with('app_settings', null);
            }
        });
    }
}
