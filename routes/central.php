<?php

/**
 * Central (SaaS) routes — loaded only when host is in tenancy.central_domains.
 * Setup, landing, signup, super-admin. No tenant context.
 */

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('password/find/{token}', 'PasswordResetController@find');

// WhatsApp Cloud API webhook (public, tenant-scoped). GET = Meta verification
// handshake, POST = delivery-status callbacks. Verified via the tenant's
// verify token / app secret inside the controller.
Route::get('/whatsapp/webhook/{tenant}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
Route::post('/whatsapp/webhook/{tenant}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'handle'])->name('whatsapp.webhook.handle');

// Public locale switcher (landing, register, login pages)
Route::post('/locale/{locale}', function (string $locale) {
    $available = \App\Models\Central\CentralLanguage::where('is_active', true)->pluck('locale')->toArray();
    if (in_array($locale, $available, true)) {
        session()->put('locale', $locale);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }
    return back();
})->name('central.locale');

$installed = Storage::disk('public')->exists('installed');

// ------------------------------------------------------------------\\
// Setup — GET /setup always registered; when installed, redirect to /
// (Re-check installed at request time; ServeSetupWhenNotInstalled may already have served the view.)
Route::get('/setup', function () {
    if (Storage::disk('public')->exists('installed')) {
        return redirect('/');
    }
    return app(\App\Http\Controllers\SetupController::class)->viewCheck();
})->name('setup');

if ($installed === false) {
    Route::get('/setup/step-1', ['uses' => 'SetupController@viewStep1']);
    Route::post('/setup/step-2', ['as' => 'setupStep1', 'uses' => 'SetupController@setupStep1']);
    // Licence step (between Application and Central database): activates with
    // ui-lib.com right away, persists in lastStep once the central DB exists.
    Route::get('/setup/license', ['uses' => 'SetupController@viewLicense']);
    Route::post('/setup/license', ['as' => 'setupLicense', 'uses' => 'SetupController@setupLicense']);
    Route::post('/setup/testDB', ['as' => 'testDB', 'uses' => 'TestDbController@testDB']);
    Route::get('/setup/step-2', ['uses' => 'SetupController@viewStep2']);
    Route::get('/setup/step-3', ['uses' => 'SetupController@viewStep3']);
    Route::get('/setup/finish', function () {
        return view('setup.finishedSetup');
    });
    Route::get('/setup/getNewAppKey', ['as' => 'getNewAppKey', 'uses' => 'SetupController@getNewAppKey']);
    Route::get('/setup/getPassport', ['as' => 'getPassport', 'uses' => 'SetupController@getPassport']);
    Route::get('/setup/getMegrate', ['as' => 'getMegrate', 'uses' => 'SetupController@getMegrate']);
    Route::post('/setup/step-3', ['as' => 'setupStep2', 'uses' => 'SetupController@setupStep2']);
    Route::post('/setup/step-4', ['as' => 'setupStep3', 'uses' => 'SetupController@setupStep3']);
    Route::post('/setup/step-5', ['as' => 'setupStep4', 'uses' => 'SetupController@setupStep4']);
    Route::post('/setup/lastStep', ['as' => 'lastStep', 'uses' => 'SetupController@lastStep']);
    Route::get('setup/lastStep', function () {
        return redirect('/setup', 301);
    });
} else {
    Route::any('/setup/{vue}', function () {
        abort(403);
    });
}

// ------------------------------------------------------------------\\
// Central landing — loads content from CMS. The named route is registered
// unconditionally: many central views (login, register, terms, …) link to
// central.welcome, so if the installed marker is missing (fresh clone —
// storage/app/public/installed is gitignored) they would all crash with
// "Route [central.welcome] not defined". Before install it forwards to setup.
Route::get('/', function (\Illuminate\Http\Request $request) {
    if (! Storage::disk('public')->exists('installed')) {
        return redirect('/setup');
    }
    return app(\App\Http\Controllers\Central\LandingPageController::class)->show($request);
})->name('central.welcome');

if ($installed) {
    Route::permanentRedirect('/landing-two', '/');
    Route::permanentRedirect('/landing-three', '/');
    Route::permanentRedirect('/landing-four', '/');
    Route::permanentRedirect('/landing-five', '/');
    Route::permanentRedirect('/landing-six', '/');
}

// Privacy policy (public)
Route::get('/privacy-policy', [\App\Http\Controllers\Central\LandingPageController::class, 'privacyPolicy'])->name('central.privacy-policy');

// Terms & conditions (public)
Route::get('/terms-conditions', [\App\Http\Controllers\Central\LandingPageController::class, 'termsConditions'])->name('central.terms-conditions');

// Blog (public, SEO)
Route::get('/blog', [\App\Http\Controllers\Central\BlogPageController::class, 'index'])->name('central.blog.index');
Route::get('/blog/{slug}', [\App\Http\Controllers\Central\BlogPageController::class, 'show'])->name('central.blog.show');

// Sitemap (public, SEO)
Route::get('/sitemap.xml', [\App\Http\Controllers\Central\SitemapController::class, 'index'])->name('central.sitemap');

// ------------------------------------------------------------------\\
// Tenant registration (central)
Route::get('/register', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'showRegistrationForm'])->name('central.register');
Route::post('/register', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'register'])->name('central.register.submit');

// Workspace sign-in (central) — asks for the workspace address and forwards
// to that tenant's own login page. Shown at / when the landing is disabled.
Route::get('/login', [\App\Http\Controllers\Central\WorkspaceLoginController::class, 'show'])->name('central.workspace.login');
Route::post('/login', [\App\Http\Controllers\Central\WorkspaceLoginController::class, 'redirect'])
    ->middleware('throttle:20,1')
    ->name('central.workspace.login.submit');

// Workspace provisioning UX (central)
Route::get('/workspace/{tenant}/preparing', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'preparing'])
    ->name('central.workspace.preparing');
Route::get('/workspace/{tenant}/status', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'status'])
    ->name('central.workspace.status');
Route::get('/workspace/{tenant}/ready', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'workspaceReady'])
    ->name('central.workspace.ready');
Route::get('/workspace/{tenant}/under-review', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'underReview'])
    ->name('central.workspace.under-review');

// Checkout for paid signups (central — before any tenant is created)
Route::get('/checkout/{token}', [\App\Http\Controllers\Central\CheckoutController::class, 'show'])
    ->name('central.checkout');
Route::post('/checkout/{token}', [\App\Http\Controllers\Central\CheckoutController::class, 'process'])
    ->name('central.checkout.process');
Route::get('/checkout/{token}/offline-submitted', [\App\Http\Controllers\Central\CheckoutController::class, 'offlineSubmitted'])
    ->name('central.checkout.offline.submitted');

// Paid signup: preparing / status polling (uses registration token, not tenant ID)
Route::get('/registration/{token}/preparing', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'preparingRegistration'])
    ->name('central.registration.preparing');
Route::get('/registration/{token}/status', [\App\Http\Controllers\Central\TenantRegistrationController::class, 'statusRegistration'])
    ->name('central.registration.status');

// XPay (embedded card element) — internal payment page for registrations and
// tenant billing payments. Links are signed + time-limited by XpayGateway.
Route::get('/xpay/pay/{ref}', [\App\Http\Controllers\Central\XpayCheckoutController::class, 'show'])
    ->where('ref', '(reg|pay)-[0-9]+')
    ->name('central.xpay.pay');
Route::post('/xpay/pay/{ref}/confirm', [\App\Http\Controllers\Central\XpayCheckoutController::class, 'confirm'])
    ->where('ref', '(reg|pay)-[0-9]+')
    ->middleware('throttle:30,1')
    ->name('central.xpay.confirm');

// Payment links — public pay page for a super-admin payment request. The
// random token is the credential. return/cancel accept POST (SSLCommerz posts
// back) and are CSRF-exempt in VerifyCsrfToken.
Route::prefix('pay/{token}')->where(['token' => '[A-Za-z0-9]{32,64}'])->name('central.pay-link.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Central\PaymentLinkCheckoutController::class, 'show'])->name('show');
    Route::post('/', [\App\Http\Controllers\Central\PaymentLinkCheckoutController::class, 'process'])->middleware('throttle:20,1')->name('process');
    Route::match(['get', 'post'], '/return', [\App\Http\Controllers\Central\PaymentLinkCheckoutController::class, 'handleReturn'])->middleware('throttle:60,1')->name('return');
    Route::match(['get', 'post'], '/cancel', [\App\Http\Controllers\Central\PaymentLinkCheckoutController::class, 'cancel'])->middleware('throttle:60,1')->name('cancel');
    Route::get('/status', [\App\Http\Controllers\Central\PaymentLinkCheckoutController::class, 'status'])->middleware('throttle:60,1')->name('status');
});

// ------------------------------------------------------------------\\
// Platform update — super admin only, accessible at /update (works during maintenance)
Route::middleware(['web', 'auth.central'])->prefix('update')->name('platform.update.')->group(function () {
    Route::get('/',                          [\App\Http\Controllers\UpdateController::class, 'show'])->name('index');
    Route::post('/start',                    [\App\Http\Controllers\UpdateController::class, 'start'])->name('start');
    Route::post('/run-central',              [\App\Http\Controllers\UpdateController::class, 'runCentral'])->name('run-central');
    Route::post('/run-tenant/{tenantId}',    [\App\Http\Controllers\UpdateController::class, 'runTenant'])->name('run-tenant');
    Route::post('/finish',                   [\App\Http\Controllers\UpdateController::class, 'finish'])->name('finish');
    Route::post('/abort',                    [\App\Http\Controllers\UpdateController::class, 'abort'])->name('abort');
});

// ------------------------------------------------------------------\\
// System Update (upload-based, resumable, backs up + migrates central and
// every tenant database) — super admin only. Every /super/system-update
// path is whitelisted in CheckForMaintenanceMode so the flow keeps working
// while the platform is down for updating.
Route::get('/super/system-update/ping', [\App\Http\Controllers\Central\Super\SystemUpdateController::class, 'ping'])
    ->middleware('throttle:60,1')->name('super.system-update.ping');
Route::middleware(['web', 'auth.central', 'central.permission:system_update'])->prefix('super/system-update')->name('super.system-update.')->group(function () {
    $c = \App\Http\Controllers\Central\Super\SystemUpdateController::class;
    Route::get('/',                  [$c, 'index'])->name('index');
    Route::get('/recovery',          [$c, 'recoveryConsole'])->name('recovery');
    Route::get('/status',            [$c, 'status'])->name('status');
    Route::post('/upload',           [$c, 'uploadChunk'])->name('upload');
    Route::post('/validate',         [$c, 'validatePackage'])->name('validate');
    Route::post('/start',            [$c, 'start'])->name('start');
    Route::post('/step',             [$c, 'step'])->name('step');
    Route::post('/resume',           [$c, 'resume'])->name('resume');
    Route::post('/rollback',         [$c, 'rollback'])->name('rollback');
    Route::post('/discard',          [$c, 'discard'])->name('discard');
    Route::delete('/package',        [$c, 'deletePackage'])->name('package.delete');
    Route::post('/restore-backup',   [$c, 'restoreBackup'])->name('restore-backup');
    Route::delete('/backups/{id}',   [$c, 'deleteBackup'])->where('id', '[A-Za-z0-9._-]+')->name('backups.delete');
    Route::post('/retention',        [$c, 'saveRetention'])->name('retention');
});

// ------------------------------------------------------------------\\
// One-off tool: attach old-POS product images to a tenant's products.
// Reads server paths, so it sits behind the same permission as System Update.
Route::middleware(['web', 'auth.central', 'central.permission:system_update'])->prefix('super/tools/legacy-images')->name('super.tools.legacy-images.')->group(function () {
    $c = \App\Http\Controllers\Central\Super\LegacyImageImportController::class;
    Route::get('/',                         [$c, 'index'])->name('index');
    Route::post('/check',                   [$c, 'check'])->name('check');
    Route::post('/sync',                    [$c, 'sync'])->name('sync');
    Route::get('/report/{token}/{type}',    [$c, 'report'])->where(['token' => '[A-Za-z0-9]{24}', 'type' => 'check|sync'])->name('report');
});

// ------------------------------------------------------------------\\
// Central (super admin) login — no auth required
Route::get('/super/login', [\App\Http\Controllers\Central\CentralLoginController::class, 'showLoginForm'])->name('central.login');
Route::post('/super/login', [\App\Http\Controllers\Central\CentralLoginController::class, 'login'])->name('central.login.submit');
Route::get('/super/login/captcha', [\App\Http\Controllers\Central\CentralLoginController::class, 'captcha'])->middleware('throttle:30,1')->name('central.login.captcha');
Route::post('/super/logout', [\App\Http\Controllers\Central\CentralLoginController::class, 'logout'])->name('central.logout');

// Super admin password reset
Route::get('/super/forgot-password', [\App\Http\Controllers\Central\CentralForgotPasswordController::class, 'showForgotForm'])->name('central.password.request');
// Throttled: stops mailbox flooding and token guessing.
Route::post('/super/forgot-password', [\App\Http\Controllers\Central\CentralForgotPasswordController::class, 'sendResetLink'])
    ->middleware('throttle:5,10,super-pw-email')->name('central.password.email');
Route::get('/super/reset-password/{token}', [\App\Http\Controllers\Central\CentralForgotPasswordController::class, 'showResetForm'])->name('central.password.reset');
Route::post('/super/reset-password', [\App\Http\Controllers\Central\CentralForgotPasswordController::class, 'resetPassword'])
    ->middleware('throttle:5,10,super-pw-reset')->name('central.password.update');

// ------------------------------------------------------------------\\
// Payment gateway webhooks (no auth, no CSRF — verified by signature)
// Throttled to 120/min per IP: gateways typically retry 3–5× per event;
// this blocks flood attacks while never dropping legitimate retries.
Route::post('/webhook/{gateway}', [\App\Http\Controllers\Central\WebhookController::class, 'handle'])
    ->where('gateway', 'stripe|paypal|paystack|flutterwave|mollie|razorpay|payfast|ligdicash|dodo|waafipay|bkash|sslcommerz|paymob|myfatoorah|moyasar|xpay')
    ->middleware('throttle:120,1')
    ->name('webhook.gateway');

// ------------------------------------------------------------------\\
// Super admin panel (auth.central required)
Route::middleware(['web', 'auth.central'])->prefix('super')->name('super.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Central\Super\DashboardController::class, 'index'])->name('dashboard');
    // Topbar global search (JSON; permission-filtered per area)
    Route::get('/search', [\App\Http\Controllers\Central\Super\SearchController::class, 'index'])->name('search');

    // Language switcher
    Route::post('/locale/{locale}', function (string $locale) {
        $available = \App\Models\Central\CentralLanguage::where('is_active', true)->pluck('locale')->toArray();
        if (in_array($locale, $available, true)) {
            session()->put('locale', $locale);
            cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
        }
        return back();
    })->name('locale');

    // ── Tenants ──
    Route::middleware('central.permission:tenants')->group(function () {
        Route::get('/tenants', [\App\Http\Controllers\Central\Super\TenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/create', [\App\Http\Controllers\Central\Super\TenantController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [\App\Http\Controllers\Central\Super\TenantController::class, 'store'])->name('tenants.store');
        Route::post('/tenants/test-connection-pre', [\App\Http\Controllers\Central\Super\TenantController::class, 'testConnectionPreSave'])->name('tenants.test-connection-pre');
        Route::post('/tenants/create-database-pre', [\App\Http\Controllers\Central\Super\TenantController::class, 'createDatabasePreSave'])->name('tenants.create-database-pre');
        Route::get('/tenants/{tenant}', [\App\Http\Controllers\Central\Super\TenantController::class, 'show'])->name('tenants.show');
        Route::get('/tenants/{tenant}/edit', [\App\Http\Controllers\Central\Super\TenantController::class, 'edit'])->name('tenants.edit');
        Route::put('/tenants/{tenant}', [\App\Http\Controllers\Central\Super\TenantController::class, 'update'])->name('tenants.update');
        Route::delete('/tenants/{tenant}', [\App\Http\Controllers\Central\Super\TenantController::class, 'destroy'])->name('tenants.destroy');
        Route::post('/tenants/{tenant}/suspend', [\App\Http\Controllers\Central\Super\TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenant}/reactivate', [\App\Http\Controllers\Central\Super\TenantController::class, 'reactivate'])->name('tenants.reactivate');
        Route::post('/tenants/{tenant}/cancel', [\App\Http\Controllers\Central\Super\TenantController::class, 'cancel'])->name('tenants.cancel');
        Route::post('/tenants/{tenant}/approve', [\App\Http\Controllers\Central\Super\TenantController::class, 'approve'])->name('tenants.approve');
        Route::post('/tenants/{tenant}/reject', [\App\Http\Controllers\Central\Super\TenantController::class, 'reject'])->name('tenants.reject');
        Route::post('/tenants/{tenant}/test-connection', [\App\Http\Controllers\Central\Super\TenantController::class, 'testConnection'])->name('tenants.test-connection');
        Route::get('/tenants/{tenant}/provisioning-status', [\App\Http\Controllers\Central\Super\TenantController::class, 'provisioningStatus'])->name('tenants.provisioning-status');
        Route::get('/tenants/{tenant}/payment-preparing', [\App\Http\Controllers\Central\Super\TenantController::class, 'paymentPreparing'])->name('tenants.payment-preparing');
        Route::get('/tenants/{tenant}/payment-status', [\App\Http\Controllers\Central\Super\TenantController::class, 'paymentStatusPoll'])->name('tenants.payment-status');
        Route::post('/tenants/{tenant}/create-database', [\App\Http\Controllers\Central\Super\TenantController::class, 'createDatabase'])->name('tenants.create-database');
        Route::post('/tenants/{tenant}/rerun-migrations', [\App\Http\Controllers\Central\Super\TenantController::class, 'rerunMigrations'])->name('tenants.rerun-migrations');
        Route::post('/tenants/{tenant}/reprovision', [\App\Http\Controllers\Central\Super\TenantController::class, 'reprovision'])->name('tenants.reprovision');
        Route::post('/tenants/{tenant}/reset-database', [\App\Http\Controllers\Central\Super\TenantController::class, 'resetDatabase'])->name('tenants.reset-database');
        Route::post('/tenants/{tenant}/reseed', [\App\Http\Controllers\Central\Super\TenantController::class, 'reseed'])->name('tenants.reseed');
        Route::put('/tenants/{tenant}/update-credentials', [\App\Http\Controllers\Central\Super\TenantController::class, 'updateCredentials'])->name('tenants.update-credentials');
        Route::post('/tenants/{tenant}/dedicated-db-user', [\App\Http\Controllers\Central\Super\TenantController::class, 'createDedicatedDatabaseUser'])->name('tenants.dedicated-db-user');
        Route::post('/tenants/{tenant}/billing-currency', [\App\Http\Controllers\Central\Super\PlatformFeaturesController::class, 'setTenantCurrency'])->name('tenants.billing-currency');

        // Tenant user management (block/unblock)
        Route::get('/tenants/{tenant}/users', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'index'])->name('tenants.users');
        Route::post('/tenants/{tenant}/users/{userId}/block', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'block'])->name('tenants.users.block');
        Route::post('/tenants/{tenant}/users/{userId}/unblock', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'unblock'])->name('tenants.users.unblock');
        Route::post('/tenants/{tenant}/users/{userId}/password', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'changePassword'])->name('tenants.users.password');
        Route::post('/tenants/{tenant}/users/{userId}/impersonate', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'impersonate'])->name('tenants.users.impersonate');
        Route::post('/tenants/{tenant}/users/{userId}/force-logout', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'forceLogoutUser'])->name('tenants.users.force-logout');
        Route::post('/tenants/{tenant}/force-logout', [\App\Http\Controllers\Central\Super\TenantUserController::class, 'forceLogoutAll'])->name('tenants.force-logout');

        Route::post('/tenants/{tenant}/domains', [\App\Http\Controllers\Central\Super\DomainController::class, 'store'])->name('tenants.domains.store');
        Route::delete('/tenants/{tenant}/domains/{domainId}', [\App\Http\Controllers\Central\Super\DomainController::class, 'destroy'])->name('tenants.domains.destroy');
        Route::post('/tenants/{tenant}/domains/{domainId}/toggle', [\App\Http\Controllers\Central\Super\DomainController::class, 'toggle'])->name('tenants.domains.toggle');

        // Custom domains (user-owned apex / subdomains pointed at this app via CNAME or A record)
        Route::post('/tenants/{tenant}/custom-domains', [\App\Http\Controllers\Central\Super\CustomDomainController::class, 'store'])->name('tenants.custom-domains.store');
        Route::post('/tenants/{tenant}/custom-domains/{id}/verify', [\App\Http\Controllers\Central\Super\CustomDomainController::class, 'verify'])->name('tenants.custom-domains.verify');
        Route::post('/tenants/{tenant}/custom-domains/{id}/check-ssl', [\App\Http\Controllers\Central\Super\CustomDomainController::class, 'checkSsl'])->name('tenants.custom-domains.check-ssl');
        Route::post('/tenants/{tenant}/custom-domains/{id}/primary',[\App\Http\Controllers\Central\Super\CustomDomainController::class, 'makePrimary'])->name('tenants.custom-domains.primary');
        Route::delete('/tenants/{tenant}/custom-domains/{id}', [\App\Http\Controllers\Central\Super\CustomDomainController::class, 'destroy'])->name('tenants.custom-domains.destroy');
    });

    // ── Plans ──
    Route::middleware('central.permission:plans')->group(function () {
        Route::resource('plans', \App\Http\Controllers\Central\Super\PlanController::class)->except(['show']);
    });

    // ── Add-ons (modules / premium themes sold on top of a plan) ──
    Route::middleware('central.permission:addons')->prefix('addons')->name('addons.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\AddonController::class, 'index'])->name('index');
        Route::put('/', [\App\Http\Controllers\Central\Super\AddonController::class, 'update'])->name('update');
        Route::post('/tenants/{tenant}/grant', [\App\Http\Controllers\Central\Super\AddonController::class, 'grant'])->name('grant');
        Route::post('/tenant-addons/{tenantAddon}/revoke', [\App\Http\Controllers\Central\Super\AddonController::class, 'revoke'])->name('revoke');
    });

    // ── Subscription Reminders ──
    Route::middleware('central.permission:reminders')->group(function () {
        Route::get('/subscriptions/reminders', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'reminders'])->name('subscriptions.reminders');
    });

    // ── Subscriptions ──
    Route::middleware('central.permission:subscriptions')->group(function () {
        Route::get('/subscriptions', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/{subscription}', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'show'])->name('subscriptions.show');
        Route::get('/subscriptions/{subscription}/edit', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'edit'])->name('subscriptions.edit');
        Route::put('/subscriptions/{subscription}', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'update'])->name('subscriptions.update');
        Route::post('/subscriptions/{subscription}/activate', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'activate'])->name('subscriptions.activate');
        Route::post('/subscriptions/{subscription}/suspend', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'suspend'])->name('subscriptions.suspend');
        Route::post('/subscriptions/{subscription}/cancel', [\App\Http\Controllers\Central\Super\TenantSubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

        // One-click actions from the tenant page's Subscription panel
        Route::post('/tenants/{tenant}/subscription/extend', [\App\Http\Controllers\Central\Super\TenantSubscriptionPanelController::class, 'extend'])->name('tenants.subscription.extend');
        Route::post('/tenants/{tenant}/subscription/change-plan', [\App\Http\Controllers\Central\Super\TenantSubscriptionPanelController::class, 'changePlan'])->name('tenants.subscription.change-plan');
    });

    // ── Payments ──
    Route::middleware('central.permission:payments')->prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\PaymentController::class, 'dashboard'])->middleware('central.permission:payments_overview')->name('dashboard');
        Route::get('/transactions', [\App\Http\Controllers\Central\Super\PaymentController::class, 'index'])->middleware('central.permission:payments_transactions')->name('index');
        Route::get('/invoices', [\App\Http\Controllers\Central\Super\PaymentController::class, 'invoices'])->middleware('central.permission:payments_invoices')->name('invoices');

        // Record a manual (cash / bank / cheque) payment (before {payment} wildcard)
        Route::get('/create', [\App\Http\Controllers\Central\Super\PaymentController::class, 'create'])->middleware('central.permission:payments_transactions')->name('create');
        Route::post('/', [\App\Http\Controllers\Central\Super\PaymentController::class, 'store'])->middleware('central.permission:payments_transactions')->name('store');

        // Pending offline registrations (must be before {payment} wildcard)
        Route::get('/pending-registrations', [\App\Http\Controllers\Central\Super\PaymentController::class, 'pendingRegistrations'])->middleware('central.permission:payments_pending')->name('pending-registrations');
        Route::post('/pending-registrations/{registration}/approve', [\App\Http\Controllers\Central\Super\PaymentController::class, 'approveRegistration'])->middleware('central.permission:payments_pending')->name('pending-registrations.approve');
        Route::post('/pending-registrations/{registration}/reject', [\App\Http\Controllers\Central\Super\PaymentController::class, 'rejectRegistration'])->middleware('central.permission:payments_pending')->name('pending-registrations.reject');

        Route::get('/{payment}', [\App\Http\Controllers\Central\Super\PaymentController::class, 'show'])->middleware('central.permission:payments_transactions')->name('show');
        Route::post('/{payment}/mark-paid', [\App\Http\Controllers\Central\Super\PaymentController::class, 'markPaid'])->middleware('central.permission:payments_transactions')->name('mark-paid');
        Route::post('/{payment}/mark-failed', [\App\Http\Controllers\Central\Super\PaymentController::class, 'markFailed'])->middleware('central.permission:payments_transactions')->name('mark-failed');
        Route::post('/{payment}/mark-refunded', [\App\Http\Controllers\Central\Super\PaymentController::class, 'markRefunded'])->middleware('central.permission:payments_transactions')->name('mark-refunded');
        Route::get('/{payment}/invoice-download', [\App\Http\Controllers\Central\Super\PaymentController::class, 'invoiceDownload'])->middleware('central.permission:payments_transactions')->name('invoice-download');
    });

    // ── Payment links (custom-amount payment requests sent to a tenant) ──
    Route::middleware('central.permission:payments_links')->prefix('payment-links')->name('payment-links.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'store'])->name('store');
        Route::get('/{paymentLink}', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'show'])->name('show');
        Route::post('/{paymentLink}/send-email', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'sendEmail'])->middleware('throttle:20,1')->name('send-email');
        Route::post('/{paymentLink}/send-whatsapp', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'sendWhatsapp'])->name('send-whatsapp');
        Route::post('/{paymentLink}/refresh', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'refresh'])->middleware('throttle:20,1')->name('refresh');
        Route::post('/{paymentLink}/cancel', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'cancel'])->name('cancel');
        Route::post('/{paymentLink}/reactivate', [\App\Http\Controllers\Central\Super\PaymentLinkController::class, 'reactivate'])->name('reactivate');
    });

    // ── Support Tickets ──
    Route::middleware('central.permission:support')->prefix('support')->name('support.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\SupportController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Central\Super\SupportController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Central\Super\SupportController::class, 'store'])->name('store');
        Route::get('/{ticket}', [\App\Http\Controllers\Central\Super\SupportController::class, 'show'])->name('show');
        Route::post('/{ticket}/reply', [\App\Http\Controllers\Central\Super\SupportController::class, 'reply'])->name('reply');
        Route::post('/{ticket}/note', [\App\Http\Controllers\Central\Super\SupportController::class, 'note'])->name('note');
        Route::post('/{ticket}/status', [\App\Http\Controllers\Central\Super\SupportController::class, 'updateStatus'])->name('status');
        Route::post('/{ticket}/assign', [\App\Http\Controllers\Central\Super\SupportController::class, 'assign'])->name('assign');
        Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\Central\Super\SupportController::class, 'downloadAttachment'])->name('attachment.download');
    });

    // ── Impersonation audit logs ──
    Route::middleware('central.permission:impersonation_logs')->group(function () {
        Route::get('/impersonation-logs', [\App\Http\Controllers\Central\Super\ImpersonationLogController::class, 'index'])->name('impersonation-logs.index');
        Route::get('/impersonation-logs/{log}', [\App\Http\Controllers\Central\Super\ImpersonationLogController::class, 'show'])->name('impersonation-logs.show');
    });

    // ── Reports ──
    Route::middleware('central.permission:reports')->group(function () {
        Route::get('/reports', [\App\Http\Controllers\Central\Super\ReportController::class, 'index'])->name('reports.index');
    });

    // How It Works (provisioning flow documentation)
    Route::middleware('central.permission:how_it_works')->group(function () {
        Route::get('/how-it-works', [\App\Http\Controllers\Central\Super\HowItWorksController::class, 'index'])->name('how-it-works');
    });

    // ── Knowledge Base ──
    Route::middleware('central.permission:knowledge_base')->prefix('knowledge-base')->name('knowledge-base.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'index'])->name('index');

        // Categories
        Route::get('/categories/create', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'createCategory'])->name('categories.create');
        Route::post('/categories', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'storeCategory'])->name('categories.store');
        Route::get('/categories/{category}/edit', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'editCategory'])->name('categories.edit');
        Route::put('/categories/{category}', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'destroyCategory'])->name('categories.destroy');

        // Articles
        Route::get('/articles/create', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'createArticle'])->name('articles.create');
        Route::post('/articles', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'storeArticle'])->name('articles.store');
        Route::get('/articles/{article}/edit', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'editArticle'])->name('articles.edit');
        Route::put('/articles/{article}', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'updateArticle'])->name('articles.update');
        Route::delete('/articles/{article}', [\App\Http\Controllers\Central\Super\KnowledgeBaseController::class, 'destroyArticle'])->name('articles.destroy');
    });

    // ── Blog ──
    // Param names {blogCategory}/{post} are deliberate: {category}/{article} are
    // globally bound to the KB models in RouteServiceProvider::boot().
    Route::middleware('central.permission:blog')->prefix('blog')->name('blog.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\BlogController::class, 'index'])->name('index');

        // Categories
        Route::get('/categories/create', [\App\Http\Controllers\Central\Super\BlogController::class, 'createCategory'])->name('categories.create');
        Route::post('/categories', [\App\Http\Controllers\Central\Super\BlogController::class, 'storeCategory'])->name('categories.store');
        Route::get('/categories/{blogCategory}/edit', [\App\Http\Controllers\Central\Super\BlogController::class, 'editCategory'])->name('categories.edit');
        Route::put('/categories/{blogCategory}', [\App\Http\Controllers\Central\Super\BlogController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{blogCategory}', [\App\Http\Controllers\Central\Super\BlogController::class, 'destroyCategory'])->name('categories.destroy');

        // Posts
        Route::get('/posts/create', [\App\Http\Controllers\Central\Super\BlogController::class, 'createPost'])->name('posts.create');
        Route::post('/posts', [\App\Http\Controllers\Central\Super\BlogController::class, 'storePost'])->name('posts.store');
        Route::get('/posts/{post}/edit', [\App\Http\Controllers\Central\Super\BlogController::class, 'editPost'])->name('posts.edit');
        Route::put('/posts/{post}', [\App\Http\Controllers\Central\Super\BlogController::class, 'updatePost'])->name('posts.update');
        Route::delete('/posts/{post}', [\App\Http\Controllers\Central\Super\BlogController::class, 'destroyPost'])->name('posts.destroy');
    });

    // ── Super Admins ──
    Route::middleware('central.permission:admins')->prefix('admins')->name('admins.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\AdminController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Central\Super\AdminController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Central\Super\AdminController::class, 'store'])->name('store');
        Route::get('/{admin}/edit', [\App\Http\Controllers\Central\Super\AdminController::class, 'edit'])->name('edit');
        Route::put('/{admin}', [\App\Http\Controllers\Central\Super\AdminController::class, 'update'])->name('update');
        Route::delete('/{admin}', [\App\Http\Controllers\Central\Super\AdminController::class, 'destroy'])->name('destroy');
    });

    // Profile (always accessible)
    Route::get('/profile', [\App\Http\Controllers\Central\Super\ProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [\App\Http\Controllers\Central\Super\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\Central\Super\ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('/profile/avatar', [\App\Http\Controllers\Central\Super\ProfileController::class, 'updateAvatar'])->name('profile.avatar');

    // ── Email Templates ──
    Route::middleware('central.permission:email_templates')->prefix('email-templates')->name('email-templates.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\EmailTemplateController::class, 'index'])->name('index');
        Route::get('/{template}/edit', [\App\Http\Controllers\Central\Super\EmailTemplateController::class, 'edit'])->name('edit');
        Route::put('/{template}', [\App\Http\Controllers\Central\Super\EmailTemplateController::class, 'update'])->name('update');
        Route::get('/{template}/preview', [\App\Http\Controllers\Central\Super\EmailTemplateController::class, 'preview'])->name('preview');
        Route::post('/{template}/send-test', [\App\Http\Controllers\Central\Super\EmailTemplateController::class, 'sendTest'])->name('send-test');
        Route::delete('/{template}/translations/{locale}', [\App\Http\Controllers\Central\Super\EmailTemplateController::class, 'destroyTranslation'])->name('translation.destroy');
    });

    // ── SMS Templates ──
    Route::middleware('central.permission:sms_templates')->prefix('sms-templates')->name('sms-templates.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\SmsTemplateController::class, 'index'])->name('index');
        Route::get('/{template}/edit', [\App\Http\Controllers\Central\Super\SmsTemplateController::class, 'edit'])->name('edit');
        Route::put('/{template}', [\App\Http\Controllers\Central\Super\SmsTemplateController::class, 'update'])->name('update');
        Route::post('/{template}/send-test', [\App\Http\Controllers\Central\Super\SmsTemplateController::class, 'sendTest'])->name('send-test');
        Route::delete('/{template}/translations/{locale}', [\App\Http\Controllers\Central\Super\SmsTemplateController::class, 'destroyTranslation'])->name('translation.destroy');
    });

    // ── Logs & System Health ──
    Route::middleware('central.permission:logs_health')->prefix('logs-health')->name('logs-health.')->group(function () {
        Route::get('/',                                       [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'index'])->name('index');
        Route::get('/tenant/{tenant}',                        [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'tenantHistory'])->name('tenant');
        Route::get('/{log}',                                  [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'show'])->name('show');
        Route::post('/{log}/resolve',                         [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'resolve'])->name('resolve');
        Route::post('/{log}/reopen',                          [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'reopen'])->name('reopen');
        Route::post('/{log}/recheck',                         [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'recheck'])->name('recheck');
        Route::delete('/{log}',                               [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'destroy'])->name('destroy');
        Route::post('/clear-resolved',                        [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'clearResolved'])->name('clear-resolved');
        Route::post('/clear',                                 [\App\Http\Controllers\Central\Super\LogsHealthController::class, 'clear'])->name('clear');
    });

    // ── Settings ──
    Route::middleware('central.permission:settings')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/general', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'index'])->middleware('central.permission:settings_general')->name('general');
        Route::put('/general', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'update'])->middleware('central.permission:settings_general')->name('general.update');
        Route::delete('/general/logo', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'removeLogo'])->middleware('central.permission:settings_general')->name('general.remove-logo');
        Route::delete('/general/favicon', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'removeFavicon'])->middleware('central.permission:settings_general')->name('general.remove-favicon');
        Route::delete('/general/tenant-logo', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'removeTenantLogo'])->middleware('central.permission:settings_general')->name('general.remove-tenant-logo');
        Route::delete('/general/tenant-favicon', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'removeTenantFavicon'])->middleware('central.permission:settings_general')->name('general.remove-tenant-favicon');
        Route::post('/general/apply-tenant-branding', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'applyTenantBranding'])->middleware('central.permission:settings_general')->name('general.apply-tenant-branding');
        Route::post('/general/clear-cache', [\App\Http\Controllers\Central\Super\GeneralSettingsController::class, 'clearCache'])->middleware('central.permission:settings_general')->name('general.clear-cache');

        // Settings → Features (optional platform features on/off) + multi-currency setup.
        Route::put('/features', [\App\Http\Controllers\Central\Super\PlatformFeaturesController::class, 'update'])->middleware('central.permission:settings_general')->name('features.update');
        Route::get('/currencies', [\App\Http\Controllers\Central\Super\PlatformFeaturesController::class, 'currencies'])->middleware('central.permission:settings_general')->name('currencies');
        Route::put('/currencies', [\App\Http\Controllers\Central\Super\PlatformFeaturesController::class, 'updateCurrencies'])->middleware('central.permission:settings_general')->name('currencies.update');

        // ── Backup (central DB) — secured: super permission + strict filename whitelist ──
        Route::get('/general/backup',                 [\App\Http\Controllers\Central\Super\BackupController::class, 'index'])->middleware('central.permission:settings_general')->name('general.backup.index');
        Route::post('/general/backup',                [\App\Http\Controllers\Central\Super\BackupController::class, 'store'])->middleware('central.permission:settings_general')->name('general.backup.store');
        Route::get('/general/backup/{filename}/download', [\App\Http\Controllers\Central\Super\BackupController::class, 'download'])
            ->where('filename', 'central-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql')
            ->middleware('central.permission:settings_general')->name('general.backup.download');
        Route::delete('/general/backup/{filename}',   [\App\Http\Controllers\Central\Super\BackupController::class, 'destroy'])
            ->where('filename', 'central-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql')
            ->middleware('central.permission:settings_general')->name('general.backup.destroy');

        Route::get('/payment-gateways', [\App\Http\Controllers\Central\Super\PaymentGatewaySettingsController::class, 'index'])->middleware('central.permission:settings_payment_gateways')->name('payment-gateways');
        Route::put('/payment-gateways/{gateway}', [\App\Http\Controllers\Central\Super\PaymentGatewaySettingsController::class, 'update'])->middleware('central.permission:settings_payment_gateways')->name('payment-gateways.update');

        Route::get('/mail', [\App\Http\Controllers\Central\Super\MailSettingsController::class, 'index'])->middleware('central.permission:settings_mail')->name('mail');
        Route::put('/mail', [\App\Http\Controllers\Central\Super\MailSettingsController::class, 'update'])->middleware('central.permission:settings_mail')->name('mail.update');
        Route::post('/mail/test', [\App\Http\Controllers\Central\Super\MailSettingsController::class, 'sendTest'])->middleware('central.permission:settings_mail')->name('mail.test');

        Route::get('/sms', [\App\Http\Controllers\Central\Super\SmsSettingsController::class, 'index'])->middleware('central.permission:settings_sms')->name('sms');
        Route::put('/sms', [\App\Http\Controllers\Central\Super\SmsSettingsController::class, 'update'])->middleware('central.permission:settings_sms')->name('sms.update');
        Route::post('/sms/test', [\App\Http\Controllers\Central\Super\SmsSettingsController::class, 'sendTest'])->middleware('central.permission:settings_sms')->name('sms.test');

        Route::get('/security', [\App\Http\Controllers\Central\Super\SecuritySettingsController::class, 'index'])->middleware('central.permission:settings_general')->name('security');
        Route::put('/security', [\App\Http\Controllers\Central\Super\SecuritySettingsController::class, 'update'])->middleware('central.permission:settings_general')->name('security.update');

        // UI Lib licence of the platform (activate / check / deactivate; server-side calls only)
        Route::get('/license', [\App\Http\Controllers\Central\Super\LicenseController::class, 'index'])->middleware('central.permission:license')->name('license');
        Route::post('/license/activate', [\App\Http\Controllers\Central\Super\LicenseController::class, 'activate'])->middleware('central.permission:license')->name('license.activate');
        Route::post('/license/check', [\App\Http\Controllers\Central\Super\LicenseController::class, 'check'])->middleware('central.permission:license')->name('license.check');
        Route::post('/license/reactivate', [\App\Http\Controllers\Central\Super\LicenseController::class, 'reactivate'])->middleware('central.permission:license')->name('license.reactivate');
        Route::post('/license/deactivate', [\App\Http\Controllers\Central\Super\LicenseController::class, 'deactivate'])->middleware('central.permission:license')->name('license.deactivate');

        // Angola AGT e-invoicing: software-producer profile shared by all tenants
        Route::get('/e-invoicing', [\App\Http\Controllers\Central\Super\EInvoicingSettingsController::class, 'index'])->middleware('central.permission:settings_general')->name('e-invoicing');
        Route::put('/e-invoicing', [\App\Http\Controllers\Central\Super\EInvoicingSettingsController::class, 'update'])->middleware('central.permission:settings_general')->name('e-invoicing.update');
        Route::post('/e-invoicing/generate-key', [\App\Http\Controllers\Central\Super\EInvoicingSettingsController::class, 'generateKey'])->middleware('central.permission:settings_general')->name('e-invoicing.generate-key');
        Route::post('/e-invoicing/refresh-overview', [\App\Http\Controllers\Central\Super\EInvoicingSettingsController::class, 'refreshOverview'])->middleware('central.permission:settings_general')->name('e-invoicing.refresh-overview');
        Route::put('/e-invoicing/provider/{provider}', [\App\Http\Controllers\Central\Super\EInvoicingSettingsController::class, 'updateProvider'])->where('provider', 'zatca|fbr|dgi')->middleware('central.permission:settings_general')->name('e-invoicing.provider.update');
    });

    // ── WhatsApp Settings ──
    Route::middleware('central.permission:whatsapp')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/whatsapp', [\App\Http\Controllers\Central\Super\WhatsappSettingsController::class, 'index'])->name('whatsapp');
        Route::put('/whatsapp', [\App\Http\Controllers\Central\Super\WhatsappSettingsController::class, 'update'])->name('whatsapp.update');
    });

    // ── Languages & translations (central_translations) ──
    Route::middleware('central.permission:languages')->prefix('languages')->name('languages.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\LanguageController::class, 'index'])->name('index');
        Route::post('/install/{locale}', [\App\Http\Controllers\Central\Super\LanguageController::class, 'install'])->where('locale', '[a-z]{2,5}')->name('install');
        Route::put('/{language:locale}', [\App\Http\Controllers\Central\Super\LanguageController::class, 'update'])->name('update');
        Route::delete('/{language:locale}', [\App\Http\Controllers\Central\Super\LanguageController::class, 'uninstall'])->name('uninstall');
        Route::post('/{language:locale}/default', [\App\Http\Controllers\Central\Super\LanguageController::class, 'makeDefault'])->name('default');
        Route::post('/{language:locale}/sync', [\App\Http\Controllers\Central\Super\LanguageController::class, 'sync'])->name('sync');
        Route::get('/{language:locale}/translations', [\App\Http\Controllers\Central\Super\LanguageController::class, 'translations'])->name('translations');
        Route::post('/{language:locale}/translations', [\App\Http\Controllers\Central\Super\LanguageController::class, 'saveTranslations'])->name('translations.save');
        Route::post('/{language:locale}/translations/reset', [\App\Http\Controllers\Central\Super\LanguageController::class, 'resetTranslation'])->name('translations.reset');
        Route::get('/{language:locale}/export', [\App\Http\Controllers\Central\Super\LanguageController::class, 'export'])->name('export');
        Route::post('/{language:locale}/import', [\App\Http\Controllers\Central\Super\LanguageController::class, 'import'])->name('import');
    });

    // ── CMS Landing Page ──
    Route::middleware('central.permission:cms')->prefix('cms')->name('cms.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'index'])->middleware('central.permission:cms_landing')->name('index');

        Route::get('/landing-pages', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'landingPages'])->middleware('central.permission:cms_landing_pages')->name('landing-pages');
        Route::put('/landing-pages', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'landingPagesUpdate'])->middleware('central.permission:cms_landing_pages')->name('landing-pages.update');

        Route::get('/hero', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'hero'])->middleware('central.permission:cms_hero')->name('hero');
        Route::put('/hero', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'heroUpdate'])->middleware('central.permission:cms_hero')->name('hero.update');

        Route::get('/features', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'features'])->middleware('central.permission:cms_features')->name('features');
        Route::post('/features/section', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featuresSectionUpdate'])->middleware('central.permission:cms_features')->name('features.section.update');
        Route::get('/features/create', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featureCreate'])->middleware('central.permission:cms_features')->name('features.create');
        Route::post('/features', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featureStore'])->middleware('central.permission:cms_features')->name('features.store');
        Route::get('/features/{feature}/edit', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featureEdit'])->middleware('central.permission:cms_features')->name('features.edit');
        Route::put('/features/{feature}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featureUpdate'])->middleware('central.permission:cms_features')->name('features.update');
        Route::delete('/features/{feature}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featureDestroy'])->middleware('central.permission:cms_features')->name('features.destroy');
        Route::post('/features/reorder', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'featureReorder'])->middleware('central.permission:cms_features')->name('features.reorder');

        Route::get('/pricing', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'pricing'])->middleware('central.permission:cms_pricing')->name('pricing');
        Route::put('/pricing', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'pricingUpdate'])->middleware('central.permission:cms_pricing')->name('pricing.update');

        Route::get('/stats', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'stats'])->middleware('central.permission:cms_stats')->name('stats');
        Route::get('/stats/create', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'statCreate'])->middleware('central.permission:cms_stats')->name('stats.create');
        Route::post('/stats', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'statStore'])->middleware('central.permission:cms_stats')->name('stats.store');
        Route::get('/stats/{stat}/edit', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'statEdit'])->middleware('central.permission:cms_stats')->name('stats.edit');
        Route::put('/stats/{stat}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'statUpdate'])->middleware('central.permission:cms_stats')->name('stats.update');
        Route::delete('/stats/{stat}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'statDestroy'])->middleware('central.permission:cms_stats')->name('stats.destroy');

        Route::get('/testimonials', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'testimonials'])->middleware('central.permission:cms_testimonials')->name('testimonials');
        Route::get('/testimonials/create', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'testimonialCreate'])->middleware('central.permission:cms_testimonials')->name('testimonials.create');
        Route::post('/testimonials', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'testimonialStore'])->middleware('central.permission:cms_testimonials')->name('testimonials.store');
        Route::get('/testimonials/{testimonial}/edit', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'testimonialEdit'])->middleware('central.permission:cms_testimonials')->name('testimonials.edit');
        Route::put('/testimonials/{testimonial}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'testimonialUpdate'])->middleware('central.permission:cms_testimonials')->name('testimonials.update');
        Route::delete('/testimonials/{testimonial}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'testimonialDestroy'])->middleware('central.permission:cms_testimonials')->name('testimonials.destroy');

        Route::get('/faq', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'faqs'])->middleware('central.permission:cms_faq')->name('faqs');
        Route::get('/faq/create', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'faqCreate'])->middleware('central.permission:cms_faq')->name('faq.create');
        Route::post('/faq', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'faqStore'])->middleware('central.permission:cms_faq')->name('faq.store');
        Route::get('/faq/{faq}/edit', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'faqEdit'])->middleware('central.permission:cms_faq')->name('faq.edit');
        Route::put('/faq/{faq}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'faqUpdate'])->middleware('central.permission:cms_faq')->name('faq.update');
        Route::delete('/faq/{faq}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'faqDestroy'])->middleware('central.permission:cms_faq')->name('faq.destroy');

        Route::get('/cta', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'cta'])->middleware('central.permission:cms_cta')->name('cta');
        Route::put('/cta', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'ctaUpdate'])->middleware('central.permission:cms_cta')->name('cta.update');

        Route::get('/footer', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'footer'])->middleware('central.permission:cms_footer')->name('footer');
        Route::put('/footer', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'footerUpdate'])->middleware('central.permission:cms_footer')->name('footer.update');

        Route::get('/seo', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'seo'])->middleware('central.permission:cms_seo')->name('seo');
        Route::put('/seo', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'seoUpdate'])->middleware('central.permission:cms_seo')->name('seo.update');

        Route::get('/privacy-policy', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'privacyPolicy'])->middleware('central.permission:cms_privacy')->name('privacy-policy');
        Route::put('/privacy-policy', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'privacyPolicyUpdate'])->middleware('central.permission:cms_privacy')->name('privacy-policy.update');

        Route::get('/terms-conditions', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'termsConditions'])->middleware('central.permission:cms_terms')->name('terms-conditions');
        Route::put('/terms-conditions', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'termsConditionsUpdate'])->middleware('central.permission:cms_terms')->name('terms-conditions.update');

        Route::get('/how-it-works', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorks'])->middleware('central.permission:cms_how_it_works')->name('how-it-works');
        Route::post('/how-it-works/section', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorksSectionUpdate'])->middleware('central.permission:cms_how_it_works')->name('how-it-works.section.update');
        Route::get('/how-it-works/create', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorksStepCreate'])->middleware('central.permission:cms_how_it_works')->name('how-it-works.create');
        Route::post('/how-it-works', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorksStepStore'])->middleware('central.permission:cms_how_it_works')->name('how-it-works.store');
        Route::get('/how-it-works/{step}/edit', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorksStepEdit'])->middleware('central.permission:cms_how_it_works')->name('how-it-works.edit');
        Route::put('/how-it-works/{step}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorksStepUpdate'])->middleware('central.permission:cms_how_it_works')->name('how-it-works.update');
        Route::delete('/how-it-works/{step}', [\App\Http\Controllers\Central\Super\LandingCmsController::class, 'howItWorksStepDestroy'])->middleware('central.permission:cms_how_it_works')->name('how-it-works.destroy');

        // CMS Translations
        Route::get('/translations', [\App\Http\Controllers\Central\Super\CmsTranslationController::class, 'index'])->middleware('central.permission:cms_translations')->name('translations');
        Route::put('/translations', [\App\Http\Controllers\Central\Super\CmsTranslationController::class, 'update'])->middleware('central.permission:cms_translations')->name('translations.update');
    });
});
