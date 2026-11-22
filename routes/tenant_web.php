<?php

/**
 * Tenant web routes — Stocky app (dashboard, POS, store, portal, auth).
 * Loaded only when host is a tenant domain; runs with tenant DB.
 */

use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\RealEstateStoreController;
use App\Http\Controllers\StoreAuthController;
use App\Http\Controllers\StoreFrontController;
use App\Http\Controllers\Api\Store\AccountPagesController;
use App\Http\Controllers\Api\Store\CheckoutController;
use App\Http\Controllers\Api\Store\MessageController;
use App\Http\Controllers\Api\Store\CustomerReturnsController;
use App\Http\Controllers\Api\Store\CustomerReviewsController;
use App\Http\Controllers\Api\Store\MyOrdersApiController;
use App\Http\Controllers\Api\Store\QuoteRequestsController;
use App\Http\Controllers\Api\Store\VehicleLeadsController;
use App\Http\Controllers\Api\Store\NewsletterController;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('password/find/{token}', 'PasswordResetController@find');

// ------------------------------------------------------------------\\
// Per-tenant PWA manifests (public — fetched by the browser without auth).
// Served from /pwa/*.webmanifest so the web server doesn't shadow them with
// the legacy static public/manifest-*.webmanifest files.
Route::middleware('web')
    ->get('pwa/{type}.webmanifest', [\App\Http\Controllers\PwaManifestController::class, 'manifest'])
    ->where('type', 'app|store|customer-display|portal')
    ->name('pwa.manifest');

// Service-worker offline fallback, branded per tenant (replaces the static
// public/offline.html, which can only ever show the default Stocky logo).
Route::middleware('web')
    ->get('pwa/offline.html', [\App\Http\Controllers\PwaManifestController::class, 'offline'])
    ->name('pwa.offline');

// Session keepalive: API calls never renew the session, because the api group
// has no StartSession and only a web request re-issues the laravel_token cookie
// (CreateFreshApiToken). Without this, an active user is signed out a fixed
// session.lifetime after their last full page load. Above the storefront routes
// so root-domain mode cannot claim the path; 'session' is a reserved store path.
Route::middleware('auth:web')
    ->get('session/keepalive', function (\Illuminate\Http\Request $request) {
        // A deactivated/deleted user must not get a fresh laravel_token: end the
        // web session so the SPA's 401 handling lands on a real login page.
        if (\App\Support\Security\RevokeUserAccess::isDisabled($request->user())) {
            \Illuminate\Support\Facades\Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your account has been disabled.'], 401);
        }

        return response()->noContent()
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    })
    ->name('session.keepalive');

$installed = file_exists(base_path('storage/app/public/installed'));

// ------------------------------------------------------------------\\
// Online store (tenant)
if ($installed === true) {
    // The storefront answers at /online_store by default, or at the tenant's
    // custom base path (Online Store → Settings → Store URL path). Routes are
    // registered per request on the tenant host, so store_path() resolves the
    // tenant from the host (see app/Support/theme_helpers.php).
    //
    // Root-domain mode (Store URL → "Use root domain") makes store_path()
    // return '': the storefront then answers at the tenant host root and the
    // customer auth pages move under /customer/* so they never shadow the
    // staff /login and /logout. The reserved system prefixes (admin app, APIs,
    // billing, portal, …) keep belonging to the app — see store_reserved_paths().
    $storePath = store_path();
    $storeAuthPrefix = $storePath === '' ? 'customer' : '';

    if ($storePath !== 'online_store') {
        // Old links keep working: /online_store/... → /<custom>/...
        Route::middleware(['web'])->any('/online_store/{rest?}', function (HttpRequest $request, $rest = null) {
            $qs = $request->getQueryString();

            // Neutralize backslashes and collapse slashes so a crafted path
            // (e.g. /online_store/%5Cevil.com) can never produce a Location of
            // "//host" (protocol-relative open redirect) in root-domain mode.
            $rest = preg_replace('#/+#', '/', str_replace('\\', '/', (string) $rest));

            return redirect(store_base_url((string) $rest).($qs ? '?'.$qs : ''), 301);
        })->where('rest', '.*');
    }

    Route::middleware(['web', 'request.safety', 'store.enabled', 'tenant.feature:online_orders'])->group(function () use ($storePath, $storeAuthPrefix) {
        Route::prefix($storePath)->group(function () use ($storeAuthPrefix) {
            Route::get('/lang/{locale}', function ($locale) {
                $supported = array_map('basename', glob(resource_path('lang/*')));
                $chosen = in_array($locale, $supported, true) ? $locale : 'en';
                session(['locale' => $chosen]);
                Cookie::queue('locale', $chosen, 60 * 24 * 365, '/');
                return back();
            })->name('lang.switch');

            // Multi-Currency: the shopper's display currency. Validated in the
            // service (unknown id / module off leaves the base currency).
            Route::get('/currency/{id}', function ($id) {
                \App\Services\StoreCurrencyService::select($id);
                return back();
            })->whereNumber('id')->name('store.currency.switch');

            Route::get('/', [StoreFrontController::class, 'index'])->name('store.index');
            Route::get('/shop', [StoreFrontController::class, 'shop'])->name('store.shop');
            Route::get('/search/suggestions', [StoreFrontController::class, 'searchSuggestions'])->name('store.search.suggestions');
            // Cart clean-up: which cart products/variants are still for sale.
            Route::get('/cart-availability', [StoreFrontController::class, 'cartAvailability'])->name('store.cart.availability');
            Route::get('/contact', [StoreFrontController::class, 'contact'])->name('store.contact');
            // Courier parcel tracking (Courier Settings → tracking page)
            Route::get('/track', [\App\Http\Controllers\StoreTrackingController::class, 'show'])
                ->middleware('throttle:30,1,store-track')->name('store.track'); // own counter, not shared with other forms
            Route::post('/store/orders', [CheckoutController::class, 'store'])->name('store.orders.store');
            Route::post('/store/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('store.payment.intent');
            Route::post('/store/razorpay-order', [CheckoutController::class, 'createRazorpayOrder'])->name('store.razorpay.order');
            Route::post('/store/xpay-intent', [CheckoutController::class, 'createXpayIntent'])->name('store.xpay.intent');
            // PayFast ITN callback — public, verified by signature + server confirmation
            Route::post('/payfast/itn', [CheckoutController::class, 'payfastItn'])->name('store.payfast.itn');
            // LigdiCash callback — public, unsigned; re-verified via the confirm endpoint
            Route::post('/ligdicash/callback', [CheckoutController::class, 'ligdicashCallback'])->name('store.ligdicash.callback');
            // Dodo Payments webhook — public, verified by Standard Webhooks signature
            Route::post('/dodo/webhook', [CheckoutController::class, 'dodoWebhook'])->name('store.dodo.webhook');
            // WaafiPay webhook — public, verified by HMAC signature over the raw body
            Route::post('/waafipay/webhook', [CheckoutController::class, 'waafipayWebhook'])->name('store.waafipay.webhook');
            Route::get('/collections/{slug}', [StoreFrontController::class, 'collection'])->name('store.collection.show');

            // Full product detail page (by id)
            Route::get('/product/{id}', [StoreFrontController::class, 'productShow'])
                ->whereNumber('id')->name('store.product.show');

            // Flash sales, CMS pages, product reviews (public, approved only) and quote requests
            Route::get('/flash-sales', [StoreFrontController::class, 'flashSales'])->name('store.flash_sales');
            Route::get('/compare', [\App\Http\Controllers\PremiumElectronicsStoreController::class, 'compare'])->name('store.compare');
            Route::get('/pages/{slug}', [StoreFrontController::class, 'page'])->name('store.page');
            Route::get('/products/{id}/reviews', [CustomerReviewsController::class, 'productReviews'])
                ->whereNumber('id')->name('store.product.reviews');
            Route::post('/quote-request', [QuoteRequestsController::class, 'store'])
                ->middleware('throttle:8,1')->name('store.quote.request');
            // Car Dealership theme: enquiry / test drive / trade-in / finance / reservation
            Route::post('/vehicle-lead', [VehicleLeadsController::class, 'store'])
                ->middleware('throttle:8,1')->name('store.vehicle.lead');
            // Restaurant: order from the table's QR code, book a table online
            Route::get('/table/{token}', [\App\Http\Controllers\RestaurantStoreController::class, 'table'])
                ->middleware('throttle:120,1,rst-qr-page')->where('token', '[A-Za-z0-9]{20,40}')->name('store.restaurant.table');
            Route::get('/table/{token}/data', [\App\Http\Controllers\RestaurantStoreController::class, 'tableData'])
                ->middleware('throttle:240,1,rst-qr-data')->where('token', '[A-Za-z0-9]{20,40}')->name('store.restaurant.table.data');
            Route::post('/table/{token}/order', [\App\Http\Controllers\RestaurantStoreController::class, 'tableOrder'])
                ->middleware('throttle:30,1,rst-qr-order')->where('token', '[A-Za-z0-9]{20,40}')->name('store.restaurant.table.order');
            Route::post('/table/{token}/bill', [\App\Http\Controllers\RestaurantStoreController::class, 'tableBill'])
                ->middleware('throttle:20,1,rst-qr-bill')->where('token', '[A-Za-z0-9]{20,40}')->name('store.restaurant.table.bill');
            Route::get('/restaurant/booking-slots', [\App\Http\Controllers\RestaurantStoreController::class, 'bookingSlots'])
                ->middleware('throttle:60,1,rst-slots')->name('store.restaurant.slots');
            Route::post('/restaurant/book', [\App\Http\Controllers\RestaurantStoreController::class, 'book'])
                ->middleware('throttle:5,1,rst-book')->name('store.restaurant.book');
            // Rental theme: item pages, live quotes, availability calendar, booking requests
            Route::get('/rentals', [\App\Http\Controllers\RentalStoreController::class, 'catalogRedirect'])->name('store.rental.index');
            Route::post('/rentals/quote', [\App\Http\Controllers\RentalStoreController::class, 'quote'])
                ->middleware('throttle:120,1,rnt-quote')->name('store.rental.quote');
            Route::post('/rentals/book', [\App\Http\Controllers\RentalStoreController::class, 'book'])
                ->middleware('throttle:5,1,rnt-book')->name('store.rental.book');
            Route::get('/rentals/booking/{token}', [\App\Http\Controllers\RentalStoreController::class, 'booking'])
                ->middleware('throttle:60,1,rnt-status')->where('token', '[A-Za-z0-9]{20,64}')->name('store.rental.booking');
            Route::post('/rentals/booking/{token}/cancel', [\App\Http\Controllers\RentalStoreController::class, 'cancelBooking'])
                ->middleware('throttle:10,1,rnt-cancel')->where('token', '[A-Za-z0-9]{20,64}')->name('store.rental.booking.cancel');
            Route::get('/rentals/{id}/calendar', [\App\Http\Controllers\RentalStoreController::class, 'calendar'])
                ->middleware('throttle:120,1,rnt-calendar')->whereNumber('id')->name('store.rental.calendar');
            Route::get('/rentals/{key}', [\App\Http\Controllers\RentalStoreController::class, 'show'])
                ->where('key', '[A-Za-z0-9\-]*[0-9]')->name('store.rental.show');
            // Booking theme: booking steps, free times, booking, the customer's status page (/bookings/* is the admin's)
            Route::get('/book', [\App\Http\Controllers\BookingStoreController::class, 'book'])->name('store.booking.book');
            Route::get('/book/slots', [\App\Http\Controllers\BookingStoreController::class, 'slots'])->middleware('throttle:120,1,bkm-slots')->name('store.booking.slots');
            Route::get('/book/days', [\App\Http\Controllers\BookingStoreController::class, 'days'])->middleware('throttle:60,1,bkm-days')->name('store.booking.days');
            Route::post('/book', [\App\Http\Controllers\BookingStoreController::class, 'store'])->middleware('throttle:5,1,bkm-book')->name('store.booking.store');
            Route::get('/appointment/{token}', [\App\Http\Controllers\BookingStoreController::class, 'status'])->middleware('throttle:60,1,bkm-status')->where('token', '[A-Za-z0-9]{20,64}')->name('store.booking.status');
            Route::post('/appointment/{token}/cancel', [\App\Http\Controllers\BookingStoreController::class, 'cancel'])->middleware('throttle:10,1,bkm-cancel')->where('token', '[A-Za-z0-9]{20,64}')->name('store.booking.cancel');
            Route::post('/appointment/{token}/accept', [\App\Http\Controllers\BookingStoreController::class, 'acceptProposal'])->middleware('throttle:10,1,bkm-accept')->where('token', '[A-Za-z0-9]{20,64}')->name('store.booking.accept');
            Route::post('/appointment/{token}/decline-time', [\App\Http\Controllers\BookingStoreController::class, 'declineProposal'])->middleware('throttle:10,1,bkm-decline')->where('token', '[A-Za-z0-9]{20,64}')->name('store.booking.decline_time');
            Route::get('/appointment/{token}/calendar.ics', [\App\Http\Controllers\BookingStoreController::class, 'ics'])->middleware('throttle:30,1,bkm-ics')->where('token', '[A-Za-z0-9]{20,64}')->name('store.booking.ics');
            Route::post('/appointment/{token}/deposit', [\App\Http\Controllers\BookingStoreController::class, 'payDeposit'])->middleware('throttle:10,1,bkm-deposit')->where('token', '[A-Za-z0-9]{20,64}')->name('store.booking.deposit');
            Route::get('/appointment/{token}/deposit/{payment}/return', [\App\Http\Controllers\BookingStoreController::class, 'depositReturn'])->middleware('throttle:20,1,bkm-deposit-return')->where(['token' => '[A-Za-z0-9]{20,64}', 'payment' => '[0-9]+'])->name('store.booking.deposit_return');
            // Live checkout totals: shipping methods for the region, coupon, tax
            Route::post('/store/checkout-quote', [CheckoutController::class, 'quote'])->name('store.checkout.quote');

            // Real Estate theme storefront (active when StoreSetting->theme = real_estate)
            Route::get('/properties', [RealEstateStoreController::class, 'listings'])->name('store.realestate.listings');
            Route::post('/properties/inquiry', [RealEstateStoreController::class, 'inquiry'])->name('store.realestate.inquiry');
            Route::get('/properties/{slug}', [RealEstateStoreController::class, 'show'])->name('store.realestate.show');

            Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
            Route::post('/contact/send', [MessageController::class, 'store'])->name('store.contact.send');

            // Checkout + thank-you: signed-in customers, or anyone while guest
            // checkout is switched on (Store Settings → Guest checkout).
            Route::middleware(['web', 'store.checkout'])->group(function () {
                Route::view('/checkout', 'store.checkout')->name('checkout');
                Route::view('/thank-you', 'store.thank-you')->name('store.thankyou');
            });

            // Guest orders: reached through the secret link from the thank-you
            // page (no store login involved).
            Route::get('/order/{token}', [\App\Http\Controllers\Api\Store\GuestOrderController::class, 'show'])
                ->middleware('throttle:60,1')->name('store.guest.order');
            Route::get('/order/{token}/data', [\App\Http\Controllers\Api\Store\GuestOrderController::class, 'data'])
                ->middleware('throttle:60,1')->name('store.guest.order.data');
            Route::get('/order/{token}/payment-proofs', [\App\Http\Controllers\Api\Store\PaymentProofController::class, 'guestIndex'])
                ->middleware('throttle:60,1')->name('store.guest.order.proofs.index');
            Route::post('/order/{token}/payment-proofs', [\App\Http\Controllers\Api\Store\PaymentProofController::class, 'guestStore'])
                ->middleware('throttle:10,1')->name('store.guest.order.proofs.store');

            // Store e-mail verification link (signed, 1 hour; may be opened while signed out)
            Route::get('/account/verify-email/{id}/{hash}', [\App\Http\Controllers\Api\Store\StoreEmailVerificationController::class, 'verify'])
                ->whereNumber('id')->middleware('throttle:20,1')->name('store.verify_email');

            Route::middleware(['web', 'auth:store'])->group(function () {
                Route::get('/account', [AccountPagesController::class, 'account'])->name('account');
                Route::put('/account', [AccountPagesController::class, 'update'])->name('account.update');
                Route::get('/account/orders', [AccountPagesController::class, 'orders'])->name('account.orders');
                // Rental theme: the customer's booking requests and rental contracts
                Route::get('/account/rentals', [\App\Http\Controllers\RentalStoreController::class, 'myRentals'])->name('account.rentals');
                // Booking theme: the customer's bookings
                Route::get('/account/appointments', [\App\Http\Controllers\BookingStoreController::class, 'account'])->name('account.appointments');
                Route::get('/account/orders/{id}', function ($id) {
                    return view('store.order-show', ['id' => $id]);
                })->name('account.order.show');
                Route::get('/my/orders', [MyOrdersApiController::class, 'index'])->name('my_orders.index');
                Route::get('/my/orders/{id}', [MyOrdersApiController::class, 'show'])->name('my_orders.show');

                // Offline payments: the shopper's proof of a GCash / bank transfer.
                Route::get('/my/orders/{id}/payment-proofs', [\App\Http\Controllers\Api\Store\PaymentProofController::class, 'index'])
                    ->name('my_orders.proofs.index');
                Route::post('/my/orders/{id}/payment-proofs', [\App\Http\Controllers\Api\Store\PaymentProofController::class, 'store'])
                    ->name('my_orders.proofs.store');

                // Address book (shipping zones + tax rates resolve from it)
                Route::put('/account/address', [AccountPagesController::class, 'updateAddress'])->name('account.address.update');

                // Returns / cancellations (customer requests only; refunds are admin-approved)
                Route::get('/account/orders/{id}/return-eligibility', [CustomerReturnsController::class, 'eligibility'])
                    ->name('account.order.return.eligibility');
                Route::post('/account/orders/{id}/cancel', [CustomerReturnsController::class, 'cancel'])
                    ->name('account.order.cancel');
                Route::post('/account/orders/{id}/return', [CustomerReturnsController::class, 'requestReturn'])
                    ->name('account.order.return');
                Route::view('/account/returns', 'store.account-returns')->name('account.returns');

                // Customer Wallet: balance, history, redeem a gift card, ask for a withdrawal
                // Software License Keys: the shopper's keys + freeing an activation slot
                Route::get('/account/licenses', [\App\Http\Controllers\Api\Store\LicenseStoreController::class, 'page'])->name('account.licenses');
                Route::post('/my/email/verify', [\App\Http\Controllers\Api\Store\StoreEmailVerificationController::class, 'send'])->middleware('throttle:3,10,store-verify-email')->name('my_email.verify');
                Route::post('/my/licenses/{id}/activations/{activation}/deactivate', [\App\Http\Controllers\Api\Store\LicenseStoreController::class, 'deactivate'])
                    ->whereNumber('id')->whereNumber('activation')->middleware('throttle:20,1,license-deactivate')->name('my_licenses.deactivate');
                Route::get('/account/wallet', [\App\Http\Controllers\Api\Store\CustomerWalletStoreController::class, 'page'])->name('account.wallet');
                Route::get('/my/wallet', [\App\Http\Controllers\Api\Store\CustomerWalletStoreController::class, 'summary'])->name('my_wallet.summary');
                Route::get('/my/wallet/transactions', [\App\Http\Controllers\Api\Store\CustomerWalletStoreController::class, 'transactions'])->name('my_wallet.transactions');
                Route::post('/my/wallet/redeem', [\App\Http\Controllers\Api\Store\CustomerWalletStoreController::class, 'redeem'])->middleware('throttle:10,1,wallet-redeem')->name('my_wallet.redeem');
                Route::post('/my/wallet/withdraw', [\App\Http\Controllers\Api\Store\CustomerWalletStoreController::class, 'withdraw'])->middleware('throttle:5,1,wallet-withdraw')->name('my_wallet.withdraw');
                Route::get('/my/returns', [CustomerReturnsController::class, 'myReturns'])
                    ->name('my_returns.index');

                // Product reviews (verified purchase)
                Route::get('/account/orders/{id}/reviewable', [CustomerReviewsController::class, 'reviewable'])
                    ->name('account.order.reviewable');
                Route::post('/account/orders/{id}/review', [CustomerReviewsController::class, 'submit'])
                    ->name('account.order.review');
            });

            // Customer auth: under the store base path normally, under
            // /customer/* in root-domain mode (see $storeAuthPrefix above).
            Route::prefix($storeAuthPrefix)->middleware('guest:store')->group(function () {
                Route::get('/login', [StoreAuthController::class, 'showLogin'])->name('store.login.show');
                Route::post('/login', [StoreAuthController::class, 'login'])->name('store.login');
                Route::get('/register', [StoreAuthController::class, 'showRegister'])->name('store.register.show');
                Route::post('/register', [StoreAuthController::class, 'register'])->name('store.register');
            });

            Route::prefix($storeAuthPrefix)->post('/logout', [StoreAuthController::class, 'logout'])
                ->middleware('auth:store')->name('store.logout');
        });
    });
} else {
    Route::any('/online_store/{any?}', function () {
        abort(503, 'Store not available');
    })->where('any', '.*');
}

// ------------------------------------------------------------------\\
// Client Portal (tenant)
Route::get('/portal/{vue?}', function (\Illuminate\Http\Request $request, $vue = null) {
    // On tenant domains the tenancy filesystem bootstrapper changes disk roots,
    // so the "installed" marker must be checked via absolute path.
    $installed = file_exists(base_path('storage/app/public/installed'));
    if ($installed === false) {
        abort(503, 'Platform not ready');
    }
    $guestSegments = ['login', 'set-password'];
    $isGuestPath = in_array($vue, $guestSegments, true);
    if (! \Illuminate\Support\Facades\Auth::guard('portal')->check() && ! $isGuestPath) {
        return redirect('/portal/login');
    }
    return view('portal');
})->where('vue', '.*')->middleware(['web', 'portal.locale']);

// ------------------------------------------------------------------\\
// OAuth callbacks (tenant)
Route::group(['middleware' => ['web', 'auth:web', 'Is_Active']], function () {
    Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');
    Route::get('/salla/connect', [\App\Http\Controllers\Integrations\SallaOAuthController::class, 'connect'])->name('salla.connect');
    Route::get('/salla/callback', [\App\Http\Controllers\Integrations\SallaOAuthController::class, 'callback'])->name('salla.callback');
    Route::get('/xero/connect', [\App\Http\Controllers\Integrations\XeroOAuthController::class, 'connect'])->name('xero.connect');
    Route::get('/xero/callback', [\App\Http\Controllers\Integrations\XeroOAuthController::class, 'callback'])->name('xero.callback');
    Route::get('/google-sheets/connect', [\App\Http\Controllers\Integrations\GoogleSheetsOAuthController::class, 'connect'])->name('google_sheets.connect');
    Route::get('/google-sheets/callback', [\App\Http\Controllers\Integrations\GoogleSheetsOAuthController::class, 'callback'])->name('google_sheets.callback');
    Route::get('/google-calendar/connect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'connect'])->name('google_calendar.connect');
    Route::get('/google-calendar/callback', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'callback'])->name('google_calendar.callback');
    Route::get('/google-calendar/disconnect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'disconnect'])->name('google_calendar.disconnect');
});

// ------------------------------------------------------------------\\
// Billing / subscription (tenant — no active-subscription middleware to avoid redirect loop)
Route::middleware(['web', 'auth:web'])->prefix('billing')->name('billing.')->group(function () {
    Route::get('/plans', [\App\Http\Controllers\Tenant\BillingController::class, 'plans'])->name('plans');
    Route::get('/checkout/{plan}', [\App\Http\Controllers\Tenant\BillingController::class, 'checkout'])->name('checkout');
    Route::post('/checkout/{plan}', [\App\Http\Controllers\Tenant\BillingController::class, 'processPayment'])->name('process');
    Route::get('/callback/{gateway}', [\App\Http\Controllers\Tenant\BillingController::class, 'callback'])->name('callback');
    Route::get('/success', [\App\Http\Controllers\Tenant\BillingController::class, 'success'])->name('success');
    Route::get('/offline-submitted', [\App\Http\Controllers\Tenant\BillingController::class, 'offlineSubmitted'])->name('offline-submitted');
    Route::get('/failed', [\App\Http\Controllers\Tenant\BillingController::class, 'failed'])->name('failed');
    Route::get('/payment/{payment}/status', [\App\Http\Controllers\Tenant\BillingController::class, 'paymentStatus'])->name('payment-status');
    Route::post('/payment/{payment}/confirm', [\App\Http\Controllers\Tenant\BillingController::class, 'confirmPayment'])->middleware('throttle:10,1')->name('payment-confirm');
    Route::get('/history', [\App\Http\Controllers\Tenant\BillingController::class, 'history'])->name('history');
    Route::post('/retry/{payment}', [\App\Http\Controllers\Tenant\BillingController::class, 'retry'])->name('retry');
    Route::post('/payment/{payment}/cancel', [\App\Http\Controllers\Tenant\BillingController::class, 'cancelPayment'])->name('cancel-payment');
});

// ------------------------------------------------------------------\\
// CRM public pages (registered before the SPA catch-all).
// One-click unsubscribe for marketing emails sent to leads (tenant-bound
// HMAC link, see CrmLeadSegmentResolver::unsubscribeUrl). NOT plan-gated on
// purpose: links in emails already sent must keep working after a downgrade.
Route::get('crm/unsubscribe/{lead}', [\App\Http\Controllers\CrmUnsubscribeController::class, 'show'])
    ->whereNumber('lead')->middleware(['web', 'throttle:30,1'])->name('crm.unsubscribe');

// CRM web forms: public, embeddable lead capture (token in the URL, CSRF-exempt,
// throttled).
Route::get('lead-form/{token}', [\App\Http\Controllers\CrmWebFormPublicController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{20,64}')->middleware(['web', 'throttle:60,1,crmform-view', 'tenant.feature:crm'])->name('crm.form.show');
Route::post('lead-form/{token}', [\App\Http\Controllers\CrmWebFormPublicController::class, 'submit'])
    ->where('token', '[A-Za-z0-9]{20,64}')->middleware(['web', 'throttle:10,1,crmform-post', 'tenant.feature:crm'])->name('crm.form.submit');

// ------------------------------------------------------------------\\
// Tenant root — open the POS app (dashboard when authenticated, login
// for guests). The online store stays reachable at /online_store.
// Must be registered before the SPA catch-all.
//
// In root-domain mode the storefront claims '/' instead, so this route is not
// registered at all: Laravel's route collection is keyed by method + URI, and
// registering GET '/' twice would silently replace the storefront home with
// this redirect. When the store is off, EnsureStoreEnabled sends '/' here
// instead (see app/Http/Middleware/EnsureStoreEnabled.php).
if (! store_is_root_domain()) {
    Route::middleware(['web'])->get('/', function () {
        if (! file_exists(base_path('storage/app/public/installed'))) {
            abort(503, 'Platform not ready');
        }

        return Auth::guard('web')->check()
            ? redirect('/app/dashboard')
            : redirect()->guest(route('login'));
    });
}

// ------------------------------------------------------------------\\
// SPA billing pages (/subscription/…, legacy /app/billing/…) — the same shell
// as the catch-all below but WITHOUT tenant.subscribed, so a lapsed workspace
// can still see its plan, invoices and usage and renew. Registered first so
// it wins over the catch-all for these paths. The APIs behind these pages
// (/api/billing/*, /api/get_user_auth) are exempt as well.
Route::group(['middleware' => ['web', 'auth:web', 'Is_Active', 'request.safety', 'tenant.activity']], function () {
    Route::get('/{billingPage}', function () {
        if (! file_exists(base_path('storage/app/public/installed'))) {
            abort(503, 'Platform not ready');
        }
        return view('layouts.master');
    })->where('billingPage', '^(subscription|app/billing|app/subscription)(/.*)?$');
});

// ------------------------------------------------------------------\\
// SPA catch-all (tenant)
Route::group(['middleware' => ['web', 'auth:web', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.activity']], function () {
    Route::get('/{vue?}', function () {
        if (! file_exists(base_path('storage/app/public/installed'))) {
            abort(503, 'Platform not ready');
        }
        return view('layouts.master');
    })->where('vue', '^(?!api|password|online_store|customer-display|quickbooks|salla|xero|google-sheets|portal|login|logout|register|billing|impersonate).*$');
});

// Super-admin impersonation (tenant side). start: exchanges a single-use,
// short-lived token issued on the central domain for a normal web session.
// leave: ends the impersonated session and returns to the super panel.
Route::get('impersonate/{token}', [\App\Http\Controllers\ImpersonationController::class, 'start'])
    ->middleware('throttle:10,1')->name('impersonation.start');
Route::post('impersonate/leave', [\App\Http\Controllers\ImpersonationController::class, 'leave'])
    ->name('impersonation.leave');

// Auth (tenant)
Route::get('login', 'Auth\LoginController@showLoginForm')->name('login');
Route::get('login/preview', 'Auth\LoginController@preview')->name('login.preview');
Route::get('login/captcha', 'Auth\LoginController@captcha')->middleware('throttle:30,1')->name('login.captcha');
Route::post('login', 'Auth\LoginController@login');
Route::post('logout', 'Auth\LoginController@logout')->name('logout');
Route::get('password/reset', 'Auth\ForgotPasswordController@showLinkRequestForm')->name('password.request');
// Throttle password-reset email requests to stop mailbox flooding.
Route::post('password/email', 'Auth\ForgotPasswordController@sendResetLinkEmail')
    ->middleware('throttle:5,10,pw-email')->name('password.email');
Route::get('password/reset/{token}', 'Auth\ResetPasswordController@showResetForm')->name('password.reset');
Route::post('password/reset', 'Auth\ResetPasswordController@reset')
    ->middleware('throttle:5,10,pw-reset')->name('password.update');
Route::get('email/verify', 'Auth\VerificationController@show')->name('verification.notice');
Route::get('email/verify/{id}/{hash}', 'Auth\VerificationController@verify')->name('verification.verify');
Route::post('email/resend', 'Auth\VerificationController@resend')->name('verification.resend');

// Customer display (tenant)
Route::get('/customer-display', function (HttpRequest $request) {
    $token = $request->query('token');
    $expected = Cache::store(config('cache.default'))->get('customer_display_token');
    if (! $token || $token !== $expected) {
        abort(403, 'Unauthorized display access');
    }
    return view('customer_display');
})->middleware(['web']);
