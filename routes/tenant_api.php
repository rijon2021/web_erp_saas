<?php

use App\Http\Controllers\Api\CustomerDisplayController;
use App\Http\Controllers\Api\PendingWorkController;
use App\Http\Controllers\Api\Store\BannersApiController;
use App\Http\Controllers\Api\Store\CollectionController;
use App\Http\Controllers\Api\Store\FlashSalesController;
use App\Http\Controllers\Api\Store\InviteCodesController;
use App\Http\Controllers\Api\Store\MessageController;
use App\Http\Controllers\Api\Store\OnlineOrdersApiController;
use App\Http\Controllers\Api\Store\PagesApiController;
use App\Http\Controllers\Api\Store\PendingCustomersController;
use App\Http\Controllers\Api\Store\ProductReviewsController;
use App\Http\Controllers\Api\Store\QuoteRequestsController;
use App\Http\Controllers\Api\Store\VehicleLeadsController;
use App\Http\Controllers\Api\Store\ReturnsController;
use App\Http\Controllers\Api\Store\SettingsApiController;
use App\Http\Controllers\Api\Store\ShippingMethodsController;
use App\Http\Controllers\Api\Store\StoreCouponsController;
use App\Http\Controllers\Api\Store\StorePopupsController;
use App\Http\Controllers\Api\Store\SubscriberController;
use App\Http\Controllers\Api\Store\TaxRatesController;
use App\Http\Controllers\KnowledgeBaseArticleController;
use App\Http\Controllers\KnowledgeBaseArticleGroupController;
use App\Http\Controllers\TeamKnowledgeBaseArticleController;
use App\Http\Controllers\TeamKnowledgeBaseArticleGroupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Lightweight reachability probe for SPA/offline detection.
// Public by design: returns no sensitive data.
Route::get('/ping', function () {
    return response()->json([
        'ok' => true,
        'ts' => now()->timestamp,
    ]);
});

// --------------------------- Reset Password  ---------------------------
// Legacy API reset (PasswordResetController) is disabled: nothing calls it,
// its e-mailed link opens a form that posts to Laravel's broker
// (password.update), which cannot accept these tokens, and every request
// overwrote the broker's real reset token for that e-mail. Staff resets use
// the web flow: password/reset → password/email → password/reset/{token}.
// Route::group([
//     'prefix' => 'password',
//     'middleware' => 'throttle:5,10,legacy-password-reset',
// ], function () {
//     Route::post('create', 'PasswordResetController@create');
//     Route::post('reset', 'PasswordResetController@reset');
// });

Route::post('getAccessToken', 'AuthController@getAccessToken')->middleware('throttle:10,1');

Route::get('/get-logo-setting', function () {
    $setting = \App\Models\Setting::first();

    return response()->json([
        'logo' => $setting->logo ?? null,
    ]);
});

Route::get('/translations/{locale}', function (\Illuminate\Http\Request $request, $locale) {
    $translations = \DB::table('translations')
        ->where('locale', $locale)
        ->pluck('value', 'key');

    // ETag over the body: a reload whose copy is current gets an empty 304
    // instead of the whole locale again. "no-cache" makes the browser always
    // revalidate, so an edited translation still shows on the next load, and
    // "private" keeps one tenant's strings out of any shared cache.
    $response = response()->json($translations);
    $response->setEtag(md5($response->getContent()));
    $response->headers->set('Cache-Control', 'private, no-cache');
    $response->isNotModified($request);

    return $response;
});

Route::get('/languages', 'LanguageController@load_language');

// Incoming webhooks (public, HMAC-signature verified per source).
// Namespaced under /webhooks/incoming/{source} — never collides with existing routes.
Route::post('/webhooks/incoming/{source}', [\App\Http\Controllers\Webhooks\IncomingWebhooksController::class, 'handle'])
    ->where('source', '[A-Za-z0-9_-]{1,64}')
    ->middleware('throttle:120,1,incoming-webhooks'); // own counter, not shared with login

// Zapier integration (server-to-server; authenticated by the tenant's Zapier API key)
Route::prefix('zapier')->middleware(['zapier.auth', 'tenant.entitled', 'tenant.feature:zapier'])->group(function () {
    Route::get('me', [\App\Http\Controllers\Zapier\ZapierController::class, 'me']);
    Route::post('hooks', [\App\Http\Controllers\Zapier\ZapierController::class, 'subscribe']);
    Route::delete('hooks/{id}', [\App\Http\Controllers\Zapier\ZapierController::class, 'unsubscribe']);
    Route::get('samples/{event}', [\App\Http\Controllers\Zapier\ZapierController::class, 'samples']);
    Route::post('actions/customers', [\App\Http\Controllers\Zapier\ZapierController::class, 'createCustomer']);
    Route::post('actions/products', [\App\Http\Controllers\Zapier\ZapierController::class, 'createProduct']);
    Route::get('search/customers', [\App\Http\Controllers\Zapier\ZapierController::class, 'findCustomer']);
    Route::get('search/products', [\App\Http\Controllers\Zapier\ZapierController::class, 'findProduct']);
});
// Public licensing API for the tenant's own software (generated license keys).
// No login: the license key authenticates the call; answers are Ed25519-signed.
Route::prefix('licensing/v1')->middleware(['tenant.entitled', 'tenant.feature:license_keys', 'throttle:60,1,licensing-api'])->group(function () {
    Route::post('activate', [\App\Http\Controllers\Api\LicensingApiController::class, 'activate']);
    Route::post('validate', [\App\Http\Controllers\Api\LicensingApiController::class, 'validateLicense']);
    Route::post('deactivate', [\App\Http\Controllers\Api\LicensingApiController::class, 'deactivate']);
    Route::post('status', [\App\Http\Controllers\Api\LicensingApiController::class, 'status']);
});
// Public REST API v1 (Settings > API keys; plan feature `api`). Write calls may
// send Idempotency-Key; 120 calls / minute per key user.
Route::prefix('v1')->middleware(['apikey.auth', 'tenant.entitled', 'tenant.feature:api', 'throttle:120,1', 'api.idempotent'])->group(function () {
    Route::get('me', [\App\Http\Controllers\Api\V1\MetaApiController::class, 'me']);
    Route::get('warehouses', [\App\Http\Controllers\Api\V1\MetaApiController::class, 'warehouses']);
    Route::get('suppliers', [\App\Http\Controllers\Api\V1\MetaApiController::class, 'suppliers']);
    Route::get('products', [\App\Http\Controllers\Api\V1\ProductsApiController::class, 'index']);
    Route::get('products/{code}', [\App\Http\Controllers\Api\V1\ProductsApiController::class, 'show'])->where('code', '.+');
    Route::put('products/{code}', [\App\Http\Controllers\Api\V1\ProductsApiController::class, 'upsert'])->where('code', '.+');
    Route::get('stock', [\App\Http\Controllers\Api\V1\StockApiController::class, 'index']);
    Route::post('stock/adjustments', [\App\Http\Controllers\Api\V1\StockApiController::class, 'adjust']);
    Route::post('purchases', [\App\Http\Controllers\Api\V1\PurchasesApiController::class, 'store']);
    Route::get('sales/lines', [\App\Http\Controllers\Api\V1\SalesApiController::class, 'lines']);
    // Anything else under v1: a JSON 404, not the HTML error page.
    Route::any('{any}', fn () => \App\Http\Middleware\ApiKeyAuth::error('not_found', 'No such API endpoint.', 404))->where('any', '.*');
});

// ---------------- Shopify webhooks (public) ----------------
// Deliberately outside the authenticated group: Shopify posts with no session
// and no CSRF token. The request proves itself with an HMAC signature over the
// raw body, verified in ShopifyWebhookController against the store's secret.
//
// Which tenant it lands in is decided by the host, like every other tenant
// route — the URL registered with Shopify is the tenant's own (see
// WebhookManager::callbackUrl) — so the shop is looked up in that tenant's
// database and no store id is needed in the path.
Route::post('shopify/webhook', [\App\Http\Controllers\ShopifyWebhookController::class, 'handle']);

// Salla webhooks (public endpoint; authenticated by the X-Salla-Signature HMAC)
Route::post('/salla/webhook', [\App\Http\Controllers\Integrations\SallaWebhookController::class, 'handle']);
// Courier status pushes (AfterShip, 17TRACK, courier APIs); the token identifies the account.
Route::post('/couriers/webhook/{token}', [\App\Http\Controllers\Couriers\CourierWebhookController::class, 'handle']);
// Printful webhooks (public endpoint; the secret token in the path authenticates it)
Route::post('/printful/webhook/{token}', [\App\Http\Controllers\Integrations\PrintfulWebhookController::class, 'handle']);
// OpenCart order notices (public endpoint; token in the path + HMAC signature)
Route::post('/opencart/webhook/{token}', [\App\Http\Controllers\Integrations\OpencartWebhookController::class, 'handle']);

// bKash / SSLCommerz storefront callbacks. These live on the SESSIONLESS api
// stack on purpose: SSLCommerz POSTs the shopper's browser back cross-site,
// so the SameSite=Lax session cookie is not sent — through the web stack,
// StartSession would answer with a FRESH session cookie that overwrites the
// customer's store login mid-payment (they would land on the login page
// instead of thank-you). The handlers never read the session: the pending
// order is resolved by the unguessable gateway id, and paid is only written
// after a server-side execute/validate. They also stay reachable when the
// store or the online_orders feature is toggled off — a payment already in
// flight must still complete (bKash has no IPN to recover it later).
Route::get('/store/bkash/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'bkashReturn']);
Route::match(['get', 'post'], '/store/sslcommerz/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzReturn']);
Route::match(['get', 'post'], '/store/sslcommerz/fail', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzFailed']);
Route::match(['get', 'post'], '/store/sslcommerz/cancel', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzFailed']);
// SSLCommerz IPN (server-to-server backstop; verify_sign + validation API)
Route::post('/store/sslcommerz/ipn', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'sslcommerzIpn']);

// Paymob / MyFatoorah / Moyasar storefront callbacks — same sessionless-stack
// rationale as above: return legs never read the session (order resolved by
// the unguessable stored reference, paid only after server-side re-verify).
Route::get('/store/paymob/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'paymobReturn']);
// Paymob transaction processed callback (server-to-server backstop; HMAC query param)
Route::post('/store/paymob/webhook', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'paymobWebhook']);
Route::get('/store/myfatoorah/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'myfatoorahReturn']);
Route::get('/store/moyasar/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'moyasarReturn']);
// XPay webhook (server-to-server backstop; X-Signature HMAC, re-verified by intent re-fetch)
Route::post('/store/xpay/webhook', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'xpayWebhook']);

// PayPal / Paystack / Flutterwave storefront callbacks — same sessionless-stack
// rationale as above: the return leg resolves the order by the unguessable
// stored reference and only marks it paid after a server-side capture/verify.
Route::get('/store/paypal/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'paypalReturn']);
Route::get('/store/paypal/cancel', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'paypalCancel']);
Route::get('/store/paystack/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'paystackReturn']);
Route::get('/store/flutterwave/return', [\App\Http\Controllers\Api\Store\CheckoutController::class, 'flutterwaveReturn']);
// Server-to-server backstops for the three above (PayPal verify-webhook-signature
// API / Paystack HMAC-SHA512 / Flutterwave verif-hash Secret Hash).
Route::post('/store/webhooks/paypal', [\App\Http\Controllers\Api\Store\WebhookController::class, 'paypal']);
Route::post('/store/webhooks/paystack', [\App\Http\Controllers\Api\Store\WebhookController::class, 'paystack']);
Route::post('/store/webhooks/flutterwave', [\App\Http\Controllers\Api\Store\WebhookController::class, 'flutterwave']);

// ---------------- Mobile app (Flutter) ----------------
// The app authenticates with a Passport personal access token issued by
// mobile/login and sent as a Bearer header — no cookies involved. ping and
// login are public: ping validates a workspace address typed into the app
// before any credentials exist, and login is throttled per IP. Like
// get_user_auth, none of this is behind tenant.subscribed: a lapsed workspace
// must still reach it to be told why the app is locked.
Route::prefix('mobile')->group(function () {
    Route::get('ping', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'ping']);
    Route::post('login', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.activity'])->group(function () {
        Route::get('me', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'me']);
        Route::post('logout', [\App\Http\Controllers\Api\Mobile\MobileAuthController::class, 'logout']);
    });
});

// The signed-in user's profile/permissions — the SPA router loads it before
// every page, including the /subscription billing pages a lapsed workspace
// must still reach to renew. So it is NOT behind tenant.subscribed.
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.activity'])->group(function () {
    Route::get('get_user_auth', 'UserController@GetUserAuth');
});

Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])->group(function () {

    // ------------------------------- WhatsApp --------------------------------\\
    Route::get('/whatsapp/settings', [\App\Http\Controllers\WhatsAppSettingsController::class, 'show']);
    Route::post('/whatsapp/settings', [\App\Http\Controllers\WhatsAppSettingsController::class, 'update']);
    Route::post('/whatsapp/test', [\App\Http\Controllers\WhatsAppSettingsController::class, 'test']);
    Route::get('/whatsapp/logs', [\App\Http\Controllers\WhatsAppLogController::class, 'index']);
    Route::delete('/whatsapp/logs/{id}', [\App\Http\Controllers\WhatsAppLogController::class, 'destroy']);
    Route::apiResource('whatsapp/templates', \App\Http\Controllers\WhatsAppTemplateController::class);

    // ---------------------- Online Orders (feature-gated) ----------------------\\
    Route::middleware('tenant.feature:online_orders')->group(function () {
        Route::get('/admin/store/settings', [SettingsApiController::class, 'show']);
        Route::post('/admin/store/settings', [SettingsApiController::class, 'update']);
        Route::get('/settings/calendar', [SettingsApiController::class, 'showCalendar']);
        Route::patch('/settings/calendar', [SettingsApiController::class, 'updateCalendar']);

        Route::get('/store/orders', [OnlineOrdersApiController::class, 'index']);
        Route::get('/store/orders/{id}', [OnlineOrdersApiController::class, 'show']);
        Route::patch('/store/orders/{id}', [OnlineOrdersApiController::class, 'update']);
        // Offline payments: verify a shopper-uploaded GCash / bank-transfer proof.
        Route::post('/store/orders/{id}/payment-proofs/{proofId}/review', [OnlineOrdersApiController::class, 'reviewProof']);

        // Cash on pickup: the branches offered for collection.
        Route::get('/store/pickup-branches', [\App\Http\Controllers\Api\Store\PickupBranchesController::class, 'index']);
        Route::post('/store/pickup-branches', [\App\Http\Controllers\Api\Store\PickupBranchesController::class, 'save']);

        // Kitchen display
        Route::get('/kitchen/orders', [\App\Http\Controllers\KitchenOrderController::class, 'index']);
        Route::get('/kitchen/orders/poll', [\App\Http\Controllers\KitchenOrderController::class, 'poll']);
        Route::get('/kitchen/warehouses', [\App\Http\Controllers\KitchenOrderController::class, 'warehouses']);
        Route::get('/kitchen/orders/{id}', [\App\Http\Controllers\KitchenOrderController::class, 'show']);
        Route::post('/kitchen/orders', [\App\Http\Controllers\KitchenOrderController::class, 'store']);
        Route::patch('/kitchen/orders/{id}/status', [\App\Http\Controllers\KitchenOrderController::class, 'updateStatus']);
        Route::patch('/kitchen/orders/{id}/assign', [\App\Http\Controllers\KitchenOrderController::class, 'assign']);
        Route::patch('/kitchen/orders/{id}/dispatch', [\App\Http\Controllers\KitchenOrderController::class, 'dispatchOrder']);

        Route::get('/store/pages', [PagesApiController::class, 'index']);
        Route::post('/store/pages', [PagesApiController::class, 'store']);
        Route::get('/store/pages/{id}', [PagesApiController::class, 'show']);
        Route::put('/store/pages/{id}', [PagesApiController::class, 'update']);
        Route::delete('/store/pages/{id}', [PagesApiController::class, 'destroy']);

        Route::get('/store/banners', [BannersApiController::class, 'index']);
        Route::post('/store/banners', [BannersApiController::class, 'store']);
        Route::get('/store/banners/{id}', [BannersApiController::class, 'show']);
        Route::put('/store/banners/{id}', [BannersApiController::class, 'update']);
        Route::delete('/store/banners/{id}', [BannersApiController::class, 'destroy']);

        Route::get('/store/subscribers', [SubscriberController::class, 'index']);
        Route::delete('/store/subscribers/{id}', [SubscriberController::class, 'destroy']);

        // Invite Codes
        Route::get('/store/invite-codes', [InviteCodesController::class, 'index']);
        Route::post('/store/invite-codes', [InviteCodesController::class, 'store']);
        Route::get('/store/invite-codes/{id}', [InviteCodesController::class, 'show']);
        Route::put('/store/invite-codes/{id}', [InviteCodesController::class, 'update']);
        Route::delete('/store/invite-codes/{id}', [InviteCodesController::class, 'destroy']);
        Route::post('/store/invite-codes/batch', [InviteCodesController::class, 'generateBatch']);

        // Pending Customers (admin approval)
        Route::get('/store/pending-customers', [PendingCustomersController::class, 'index']);
        Route::post('/store/pending-customers/{id}/approve', [PendingCustomersController::class, 'approve']);
        Route::post('/store/pending-customers/{id}/reject', [PendingCustomersController::class, 'reject']);
        Route::post('/store/pending-customers/approve-all', [PendingCustomersController::class, 'approveAll']);

        Route::get('/store/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::get('/store/messages/{id}', [MessageController::class, 'show'])->name('messages.show');
        Route::patch('/store/messages/{id}/toggle-read', [MessageController::class, 'toggleRead']);
        Route::delete('/store/messages/{id}', [MessageController::class, 'destroy'])->name('messages.destroy');

        // Collections (manual)
        Route::get('/admin/store/collections', [CollectionController::class, 'index']);
        Route::post('/admin/store/collections', [CollectionController::class, 'store']);
        Route::get('/admin/store/collections/{collection}', [CollectionController::class, 'show']);
        Route::put('/admin/store/collections/{collection}', [CollectionController::class, 'update']);
        Route::delete('/admin/store/collections/{collection}', [CollectionController::class, 'destroy']);
        Route::post('/admin/store/collections/{collection}/products', [CollectionController::class, 'syncProducts']);

        Route::get('/admin/store/products', [CollectionController::class, 'searchProducts']);
        Route::get('/admin/products', [CollectionController::class, 'searchProducts']);

        // Storefront menus editor (header / footer link lists)
        Route::get('/admin/store/menus', [SettingsApiController::class, 'menus']);
        Route::post('/admin/store/menus', [SettingsApiController::class, 'updateMenus']);

        // Shipping Methods
        Route::get('/store/shipping-methods', [ShippingMethodsController::class, 'index']);
        Route::post('/store/shipping-methods', [ShippingMethodsController::class, 'store']);
        Route::put('/store/shipping-methods/{id}', [ShippingMethodsController::class, 'update']);
        Route::delete('/store/shipping-methods/{id}', [ShippingMethodsController::class, 'destroy']);

        // Tax Rates
        Route::get('/store/tax-rates', [TaxRatesController::class, 'index']);
        Route::post('/store/tax-rates', [TaxRatesController::class, 'store']);
        Route::put('/store/tax-rates/{id}', [TaxRatesController::class, 'update']);
        Route::delete('/store/tax-rates/{id}', [TaxRatesController::class, 'destroy']);

        // Returns / cancellations (admin review + refund approval)
        Route::get('/store/returns', [ReturnsController::class, 'index']);
        Route::post('/store/returns/{id}/approve', [ReturnsController::class, 'approve']);
        Route::post('/store/returns/{id}/reject', [ReturnsController::class, 'reject']);

        // Coupons
        Route::get('/store/coupons', [StoreCouponsController::class, 'index']);
        Route::post('/store/coupons', [StoreCouponsController::class, 'store']);
        Route::put('/store/coupons/{id}', [StoreCouponsController::class, 'update']);
        Route::delete('/store/coupons/{id}', [StoreCouponsController::class, 'destroy']);

        // Quote Requests
        Route::get('/store/quote-requests', [QuoteRequestsController::class, 'index']);
        Route::post('/store/quote-requests/{id}/status', [QuoteRequestsController::class, 'setStatus']);
        Route::delete('/store/quote-requests/{id}', [QuoteRequestsController::class, 'destroy']);
        // Vehicle leads (Car Dealership theme)
        Route::get('/store/vehicle-leads', [VehicleLeadsController::class, 'index']);
        Route::post('/store/vehicle-leads/{id}', [VehicleLeadsController::class, 'update'])->whereNumber('id');
        Route::post('/store/vehicle-leads/{id}/crm', [VehicleLeadsController::class, 'toCrm'])->whereNumber('id');
        Route::delete('/store/vehicle-leads/{id}', [VehicleLeadsController::class, 'destroy'])->whereNumber('id');

        // Popup Messages
        Route::get('/store/popups', [StorePopupsController::class, 'index']);
        Route::post('/store/popups', [StorePopupsController::class, 'store']);
        Route::post('/store/popups/{id}', [StorePopupsController::class, 'update']); // POST for multipart update
        Route::delete('/store/popups/{id}', [StorePopupsController::class, 'destroy']);

        // Product Reviews (admin moderation)
        Route::get('/store/reviews', [ProductReviewsController::class, 'index']);
        Route::post('/store/reviews/{id}/status', [ProductReviewsController::class, 'setStatus']);
        Route::delete('/store/reviews/{id}', [ProductReviewsController::class, 'destroy']);

        // Flash Sales
        Route::get('/store/flash-sales', [FlashSalesController::class, 'index']);
        Route::get('/store/flash-sales/search-products', [FlashSalesController::class, 'searchProducts']);
        Route::get('/store/flash-sales/{id}', [FlashSalesController::class, 'show']);
        Route::post('/store/flash-sales', [FlashSalesController::class, 'store']);
        Route::put('/store/flash-sales/{id}', [FlashSalesController::class, 'update']);
        Route::delete('/store/flash-sales/{id}', [FlashSalesController::class, 'destroy']);
    });

    // Everything awaiting staff action: the topbar bell list + sidebar badges.
    Route::get('pending-work', [PendingWorkController::class, 'index']);

    // Knowledge Base (feature-gated). Two sources sit side by side here:
    //   /knowledge-base/*        the super admin's central help centre — read-only,
    //                            tenants can only view + give feedback
    //   /knowledge-base/team/*   the tenant's own articles, written by their staff
    //                            and stored tenant-side; full CRUD behind the
    //                            knowledge_base_view permission (see the policies)
    // The two id spaces are unrelated, hence the separate prefix.
    Route::middleware('tenant.feature:knowledge_base')->group(function () {
    Route::get('/knowledge-base/groups/for-filter', [KnowledgeBaseArticleGroupController::class, 'forFilter']);
    Route::get('/knowledge-base/articles', [KnowledgeBaseArticleController::class, 'index']);
    Route::get('/knowledge-base/articles/{id}', [KnowledgeBaseArticleController::class, 'show']);
    Route::post('/knowledge-base/articles/{id}/feedback', [KnowledgeBaseArticleController::class, 'submitFeedback']);

    Route::get('/knowledge-base/team/groups', [TeamKnowledgeBaseArticleGroupController::class, 'index']);
    Route::post('/knowledge-base/team/groups', [TeamKnowledgeBaseArticleGroupController::class, 'store']);
    Route::put('/knowledge-base/team/groups/{id}', [TeamKnowledgeBaseArticleGroupController::class, 'update']);
    Route::delete('/knowledge-base/team/groups/{id}', [TeamKnowledgeBaseArticleGroupController::class, 'destroy']);
    Route::get('/knowledge-base/team/articles', [TeamKnowledgeBaseArticleController::class, 'index']);
    Route::post('/knowledge-base/team/articles', [TeamKnowledgeBaseArticleController::class, 'store']);
    Route::get('/knowledge-base/team/articles/{id}', [TeamKnowledgeBaseArticleController::class, 'show']);
    Route::put('/knowledge-base/team/articles/{id}', [TeamKnowledgeBaseArticleController::class, 'update']);
    Route::delete('/knowledge-base/team/articles/{id}', [TeamKnowledgeBaseArticleController::class, 'destroy']);
    });

    // Support Tickets (tenant <-> super admin). Available to all authenticated
    // tenant users; tickets are stored centrally and scoped by tenant_id.
    Route::get('/support/tickets', [\App\Http\Controllers\Tenant\SupportController::class, 'index']);
    Route::post('/support/tickets', [\App\Http\Controllers\Tenant\SupportController::class, 'store']);
    Route::get('/support/tickets/{id}', [\App\Http\Controllers\Tenant\SupportController::class, 'show']);
    Route::post('/support/tickets/{id}/reply', [\App\Http\Controllers\Tenant\SupportController::class, 'reply']);

    Route::get('dashboard_data', 'DashboardController@dashboard_data');
    Route::get('today_summary', 'TodaySummaryController@index');
    Route::get('real_time_sales_counter_data', 'DashboardController@real_time_sales_counter_data');
    Route::get('sales_3d_dashboard_data', 'Sales3DDashboardController@data');

    Route::get('/retrieve-customer', 'StripeController@retrieveCustomer');
    Route::post('/update-customer-stripe', 'StripeController@updateCustomer');

    Route::get('/languages_setting', 'LanguageController@index');
    Route::post('/languages_setting', 'LanguageController@store');
    Route::put('/languages_setting/{language}', 'LanguageController@update');
    Route::delete('/languages_setting/{language}', 'LanguageController@destroy');
    Route::post('/languages_setting/{id}/set-default', 'LanguageController@setDefault');
    Route::post('/languages_setting/{id}/set-active', 'LanguageController@setLocaleActive');
    Route::post('/languages_setting/set-default/{locale}', 'LanguageController@setDefaultByLocale');

    // Sync Vue i18n locale to Laravel session for Blade PDFs (no translations_settings permission required)
    Route::post('/sync-locale', 'LocaleSyncController@sync');
    
    Route::get('/translations_setting/{locale}', 'LanguageController@get_translate');
    Route::put('/translations_setting/{locale}', 'LanguageController@update_translate');
    Route::put('/translations_setting/{locale}', 'LanguageController@updateOrInsert');
    Route::delete('/translations_setting/{locale}/{key}', 'LanguageController@delete_translate');

    // -------------------------- Clear Cache ---------------------------

    Route::get('clear_cache', 'SettingsController@Clear_Cache');

    // ------------------------------- subscriptions ------------------------\\

    Route::resource('subscriptions', 'SubscriptionController');
    Route::put('/subscriptions/{id}/status', 'SubscriptionController@updateStatus');

    // ------------------------------- error_logs ------------------------\\

    Route::get('/error-logs', 'ErrorLogController@index')->name('error_logs.index');

    // -------------------------- Reports ---------------------------

    Route::get('report/client', 'ReportController@Client_Report');
    Route::get('report/client/{id}', 'ReportController@Client_Report_detail');
    Route::get('report/client_sales', 'ReportController@Sales_Client');
    Route::get('report/client_payments', 'ReportController@Payments_Client');
    Route::get('report/client_quotations', 'ReportController@Quotations_Client');
    Route::get('report/client_returns', 'ReportController@Returns_Client');
    Route::get('report/provider', 'ReportController@Providers_Report');
    Route::get('report/provider/{id}', 'ReportController@Provider_Report_detail');
    Route::get('report/provider_purchases', 'ReportController@Purchases_Provider');
    Route::get('report/provider_payments', 'ReportController@Payments_Provider');
    Route::get('report/provider_returns', 'ReportController@Returns_Provider');
    Route::get('report/sales', 'ReportController@Report_Sales');
    Route::get('report/purchases', 'ReportController@Report_Purchases');
    Route::get('report/get_last_sales', 'ReportController@Get_last_Sales');
    Route::get('report/warehouse_report', 'ReportController@Warehouse_Report');
    Route::get('report/internal_location_report', 'ReportController@Internal_Location_Report');
    Route::get('report/sales_warehouse', 'ReportController@Sales_Warehouse');
    Route::get('report/purchases_warehouse', 'ReportController@Purchases_Warehouse');
    Route::get('report/quotations_warehouse', 'ReportController@Quotations_Warehouse');
    Route::get('report/returns_sale_warehouse', 'ReportController@Returns_Sale_Warehouse');
    Route::get('report/returns_purchase_warehouse', 'ReportController@Returns_Purchase_Warehouse');
    Route::get('report/expenses_warehouse', 'ReportController@Expenses_Warehouse');
    Route::get('report/warhouse_count_stock', 'ReportController@Warhouse_Count_Stock');
    Route::get('report/count_quantity_alert', 'ReportController@count_quantity_alert');
    Route::get('report/profit_and_loss', 'ReportController@ProfitAndLoss');
    // Profit analysis by dimension: product|category|unit|customer|date|warehouse
    Route::get('report/profit/{dimension}', 'ProfitReportController@index');
    Route::get('report/top_products', 'ReportController@report_top_products');
    Route::get('report/top_customers', 'ReportController@report_top_customers');
    Route::get('report/product_report', 'ReportController@product_report');
    Route::get('report/sale_products_details', 'ReportController@sale_products_details');
    Route::get('report/product_sales_report', 'ReportController@product_sales_report');
    Route::get('report/products_sold_summary', 'ReportController@products_sold_summary');
    Route::get('report/product_purchases_report', 'ReportController@product_purchases_report');

    Route::get('report/users', 'ReportController@users_Report');
    Route::get('report/stock', 'ReportController@stock_Report');
    Route::get('report/get_sales_by_user', 'ReportController@get_sales_by_user');
    Route::get('report/get_quotations_by_user', 'ReportController@get_quotations_by_user');
    Route::get('report/get_sales_return_by_user', 'ReportController@get_sales_return_by_user');
    Route::get('report/get_purchases_by_user', 'ReportController@get_purchases_by_user');
    Route::get('report/get_purchase_return_by_user', 'ReportController@get_purchase_return_by_user');
    Route::get('report/get_transfer_by_user', 'ReportController@get_transfer_by_user');
    Route::get('report/get_adjustment_by_user', 'ReportController@get_adjustment_by_user');

    Route::get('report/get_sales_by_product', 'ReportController@get_sales_by_product');
    Route::get('report/get_quotations_by_product', 'ReportController@get_quotations_by_product');

    Route::get('report/get_sales_return_by_product', 'ReportController@get_sales_return_by_product');
    Route::get('report/get_purchases_by_product', 'ReportController@get_purchases_by_product');
    Route::get('report/get_purchase_return_by_product', 'ReportController@get_purchase_return_by_product');
    Route::get('report/get_transfer_by_product', 'ReportController@get_transfer_by_product');
    Route::get('report/get_adjustment_by_product', 'ReportController@get_adjustment_by_product');
    Route::get('report/client_pdf/{id}', 'ReportController@download_report_client_pdf');
    Route::get('report/provider_pdf/{id}', 'ReportController@download_report_provider_pdf');
    Route::get('report/analytics_summary', 'ReportController@analyticsSummary');

    Route::get('report/inventory_valuation_summary', 'ReportController@inventory_valuation_summary');
    Route::get('report/stock_inventory_valuation', 'ReportController@stock_inventory_valuation');
    Route::get('report/expenses_report', 'ReportController@expenses_report');
    Route::get('report/deposits_report', 'ReportController@deposits_report');
    Route::get('report/report_transactions', 'ReportController@report_transactions');
    Route::get('report/sales_by_category_report', 'ReportController@sales_by_category_report');
    Route::get('report/sales_by_brand_report', 'ReportController@sales_by_brand_report');
    Route::get('report/seller_report', 'ReportController@seller_report');
    Route::get('report/attendance_summary', 'ReportController@attendance_summary');
    Route::get('report/inactive_customers', 'ReportController@inactiveCustomers');
    Route::get('report/zero_sales_products', 'ReportController@zeroSalesProducts');
    Route::get('report/dead_stock', 'ReportController@deadStock');
    Route::get('report/expiry', 'ReportController@expiryReport');
    // Pharmacy batch reports — register (cross-batch list) + history (per-batch movement log).
    Route::get('report/batches/register', 'BatchReportController@register');
    Route::get('report/batches/{id}/history', 'BatchReportController@history');
    // ------------------------------- Serial / IMEI tracking ------------------------\\
    // Purely additive module: opt-in per product (is_imei) + global show_serial_tracking.
    Route::get('serial_numbers/available', 'SerialNumberController@available');
    Route::get('serial_numbers/for_sale', 'SerialNumberController@forSale');
    Route::get('serial_numbers/for_purchase', 'SerialNumberController@forPurchase');
    Route::post('serial_numbers/{id}/status', 'SerialNumberController@changeStatus');
    Route::resource('serial_numbers', 'SerialNumberController')->only(['index', 'show']);

    // Serial / IMEI reports
    Route::get('report/serials/available', 'SerialNumberReportController@available');
    Route::get('report/serials/sold', 'SerialNumberReportController@sold');
    Route::get('report/serials/movements', 'SerialNumberReportController@movements');
    Route::get('report/serials/inventory', 'SerialNumberReportController@inventory');

    Route::get('report/draft_invoices', 'ReportController@draftInvoices');
    Route::get('report/discount_summary', 'ReportController@discountSummary');
    Route::get('report/due_collection', 'ReportController@dueCollection');
    Route::get('report/pos_sales', 'ReportController@posSales');
    Route::get('report/tax_summary', 'ReportController@taxSummary');
    Route::get('report/stock_aging', 'ReportController@stockAging');
    Route::get('report/stock_aging/filters', 'ReportController@stockAgingFilters');
    Route::get('report/cash_flow_report', 'ReportController@cash_flow_report');
    Route::get('report/return_ratio_report', 'ReportController@return_ratio_report');
    Route::get('report/stock_transfer', 'ReportController@stockTransferReport');
    Route::get('report/stock_adjustment', 'ReportController@stockAdjustmentReport');
    Route::get('report/top_suppliers', 'ReportController@topSuppliersReport');
    Route::get('report/customer_loyalty_points', 'ReportController@customerLoyaltyPoints');
    Route::get('get_product_detail/{id}', 'ProductsController@Get_Products_Details');

    // Negative Stock
    Route::get('report/negative_stock', 'ReportController@negative_stock_report');

    // AI Reports (feature-gated)
    Route::middleware('tenant.feature:ai_reports')->group(function () {
        Route::get('report-questions', 'ReportQuestionController@index');
        Route::post('report-questions/run', 'ReportQuestionController@run');
    });

    // AI Assistant — Claude chat, dashboard insights, product writer, invoice scanner (feature-gated)
    Route::middleware('tenant.feature:ai_assistant')->group(function () {
        Route::get('ai/status', 'AiAssistantController@status');
        Route::get('ai/conversations', 'AiAssistantController@conversations');
        Route::get('ai/conversations/{id}', 'AiAssistantController@show');
        Route::delete('ai/conversations/{id}', 'AiAssistantController@destroy');
        Route::post('ai/ask', 'AiAssistantController@ask');
        Route::get('ai/insights', 'AiAssistantController@insights');
        Route::post('ai/insights', 'AiAssistantController@generateInsights');
        Route::get('ai/settings', 'AiAssistantController@settings');
        Route::put('ai/settings', 'AiAssistantController@updateSettings');
        Route::post('ai/settings/test', 'AiAssistantController@test');
        Route::get('ai/product-writer/options', 'AiProductWriterController@options');
        Route::post('ai/product-writer/generate', 'AiProductWriterController@generate');
        Route::post('ai/product-writer/apply', 'AiProductWriterController@apply');
        Route::post('ai/invoice-scan', 'AiInvoiceScanController@scan');
    });

    // ------------------------------- Service & Maintenance (feature-gated) ------------------------\\
    Route::middleware('tenant.feature:service_maintenance')->group(function () {
        Route::resource('service_jobs', 'ServiceJobController');
        Route::resource('service_technicians', 'ServiceTechnicianController')->only(['index', 'store', 'update', 'destroy']);

        // Repair workflow actions
        Route::post('service_jobs/{id}/approve_quote', 'ServiceJobController@approveQuote');
        Route::post('service_jobs/{id}/decline_quote', 'ServiceJobController@declineQuote');
        Route::post('service_jobs/{id}/mark_delivered', 'ServiceJobController@markDelivered');
        Route::post('service_jobs/{id}/create_quotation', 'ServiceJobController@createQuotation');

        // Service job payments
        Route::get('service_jobs/{service_job}/payments', 'ServiceJobPaymentController@index');
        Route::post('service_jobs/{service_job}/payments', 'ServiceJobPaymentController@store');
        Route::put('service_jobs/{service_job}/payments/{id}', 'ServiceJobPaymentController@update');
        Route::delete('service_jobs/{service_job}/payments/{id}', 'ServiceJobPaymentController@destroy');

        // Service job photos
        Route::get('service_jobs/{service_job}/photos', 'ServiceJobPhotoController@index');
        Route::post('service_jobs/{service_job}/photos', 'ServiceJobPhotoController@store');
        Route::delete('service_jobs/{service_job}/photos/{id}', 'ServiceJobPhotoController@destroy');

        Route::get('service_checklist/categories', 'ServiceChecklistController@categoriesIndex');
        Route::post('service_checklist/categories', 'ServiceChecklistController@categoriesStore');
        Route::put('service_checklist/categories/{id}', 'ServiceChecklistController@categoriesUpdate');
        Route::delete('service_checklist/categories/{id}', 'ServiceChecklistController@categoriesDestroy');

        Route::get('service_checklist/items', 'ServiceChecklistController@itemsIndex');
        Route::post('service_checklist/items', 'ServiceChecklistController@itemsStore');
        Route::put('service_checklist/items/{id}', 'ServiceChecklistController@itemsUpdate');
        Route::delete('service_checklist/items/{id}', 'ServiceChecklistController@itemsDestroy');

        Route::get('service_checklist/options', 'ServiceChecklistController@options');

        Route::get('report/service_jobs', 'ServiceReportController@serviceJobs');
        Route::get('report/service_checklist_completion', 'ServiceReportController@checklistCompletion');
        Route::get('report/customer_maintenance_history', 'ServiceReportController@customerMaintenanceHistory');
    });

    // ------------------------------- payment_methods ------------------------\\
    // ------------------------------------------------------------------\\
    Route::put('payment_methods/{id}/active', 'PaymentMethodController@setActive');
    Route::put('payment_methods/{id}/cash', 'PaymentMethodController@setCash');
    Route::resource('payment_methods', 'PaymentMethodController');

    // ---------------------- HR Management (feature-gated) ----------------------\\

    Route::middleware('tenant.feature:hrm')->group(function () {
        Route::resource('employees', 'hrm\EmployeesController');
        Route::post('employees/import/csv', 'hrm\EmployeesController@import_employees');
        Route::post('employees/delete/by_selection', 'hrm\EmployeesController@delete_by_selection');
        Route::get('get_employees_by_department', "hrm\EmployeesController@Get_employees_by_department");
        Route::put('update_social_profile/{id}', "hrm\EmployeesController@update_social_profile");
        Route::get('get_experiences_by_employee', "hrm\EmployeesController@get_experiences_by_employee");
        Route::get('get_accounts_by_employee', "hrm\EmployeesController@get_accounts_by_employee");
        Route::get('Get_employees_by_company', "hrm\EmployeesController@Get_employees_by_company");

        Route::resource('work_experience', 'hrm\EmployeeExperienceController');
        Route::resource('employee_account', 'hrm\EmployeeAccountController');

        Route::resource('company', 'hrm\CompanyController');
        Route::get('get_all_company', "hrm\CompanyController@Get_all_Company");
        Route::post('company/delete/by_selection', "hrm\CompanyController@delete_by_selection");

        Route::resource('departments', 'hrm\DepartmentsController');
        Route::get('get_all_departments', "hrm\DepartmentsController@Get_all_Departments");
        Route::get('get_departments_by_company', "hrm\DepartmentsController@Get_departments_by_company")->name('Get_departments_by_company');
        Route::post('departments/delete/by_selection', "hrm\DepartmentsController@delete_by_selection");

        Route::resource('designations', 'hrm\DesignationsController');
        Route::get('get_designations_by_department', "hrm\DesignationsController@Get_designations_by_department");
        Route::post('designations/delete/by_selection', "hrm\DesignationsController@delete_by_selection");

        Route::resource('office_shift', 'hrm\OfficeShiftController');
        Route::post('office_shift/delete/by_selection', "hrm\OfficeShiftController@delete_by_selection");

        Route::resource('attendances', 'hrm\AttendancesController');
        Route::get('daily_attendance', "hrm\AttendancesController@daily_attendance")->name('daily_attendance');
        Route::post('attendance_by_employee/{id}', 'hrm\EmployeeSessionController@attendance_by_employee')->name('attendance_by_employee.post');
        Route::post('attendances/delete/by_selection', "hrm\AttendancesController@delete_by_selection");

        Route::resource('leave', 'hrm\LeaveController');
        Route::resource('leave_type', 'hrm\LeaveTypeController');
        Route::post('leave/delete/by_selection', "hrm\LeaveController@delete_by_selection");
        Route::post('leave_type/delete/by_selection', "hrm\LeaveTypeController@delete_by_selection");

        Route::resource('holiday', 'hrm\HolidayController');
        Route::post('holiday/delete/by_selection', "hrm\HolidayController@delete_by_selection");

        Route::resource('payroll', 'hrm\PayrollController');

        // ------------------------------- Payroll Documents --------------------------\\
        Route::get('payroll/{id}/documents', 'hrm\PayrollController@getDocuments');
        Route::post('payroll/{id}/documents', 'hrm\PayrollController@uploadDocuments');
        Route::get('payroll/documents/{id}/download', 'hrm\PayrollController@downloadDocument');
        Route::delete('payroll/documents/{id}', 'hrm\PayrollController@deleteDocument');

        Route::prefix('core')->group(function () {
            Route::get('get_departments_by_company', "hrm\CoreController@Get_departments_by_company");
            Route::get('get_designations_by_department', "hrm\CoreController@Get_designations_by_department");
            Route::get('get_office_shift_by_company', "hrm\CoreController@Get_office_shift_by_company");
            Route::get('get_employees_by_company', "hrm\CoreController@Get_employees_by_company");
        });
    });

    // ------------------------------- CLIENTS --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('clients', 'ClientController')->middleware('tenant.limit:max_customers');
    Route::post('customers/import', 'ClientController@import');
    Route::get('get_clients_without_paginate', 'ClientController@Get_Clients_Without_Paginate');
    Route::post('clients/delete/by_selection', 'ClientController@delete_by_selection');
    Route::post('clients_pay_due', 'ClientController@clients_pay_due');
    Route::post('clients_pay_return_due', 'ClientController@pay_sale_return_due');
    Route::get('get_client_store_data/{id}', 'ClientController@get_client_store_data');
    Route::get('get_points_client/{id}', 'ClientController@getPoints');
    Route::post('customers/{id}/update-points', 'ClientController@updatePoints');
    Route::post('customers/{id}/adjust-opening-balance', 'ClientController@adjustOpeningBalance');

    // Customer Ledger (separate endpoints)
    Route::get('/sales_client', 'ClientController@salesByClient');
    Route::get('/payments_client', 'ClientController@paymentsByClient');
    Route::get('/quotations_client', 'ClientController@quotationsByClient');
    Route::get('/returns_client', 'ClientController@returnsByClient');
    Route::get('/payment_returns_client', 'ClientController@paymentReturnsByClient');

    // Basic client info for header (optional but recommended)
    Route::get('clients/{id}/brief', 'ClientController@clientBrief');

    // Client Portal is a plan feature: every admin endpoint below needs it.
    Route::middleware('tenant.feature:client_portal')->group(function () {
        // Client Portal admin controls (enable/disable)
        Route::get('clients/{id}/portal-status', [\App\Http\Controllers\Api\Portal\PortalAdminController::class, 'getStatus']);
        Route::post('clients/{id}/portal-enable', [\App\Http\Controllers\Api\Portal\PortalAdminController::class, 'enable']);
        Route::post('clients/{id}/portal-disable', [\App\Http\Controllers\Api\Portal\PortalAdminController::class, 'disable']);
        // Client Portal sidebar section (dashboard, accounts, deletion log, settings)
        Route::get('client-portal/dashboard', 'ClientPortalController@dashboard');
        Route::get('client-portal/accounts', 'ClientPortalController@accounts');
        Route::get('client-portal/accounts/available-clients', 'ClientPortalController@availableClients');
        Route::post('client-portal/accounts', 'ClientPortalController@store');
        Route::post('client-portal/accounts/status', 'ClientPortalController@setStatus');
        Route::post('client-portal/accounts/invite', 'ClientPortalController@invite');
        Route::post('client-portal/accounts/{id}/two-factor/reset', 'ClientPortalController@resetTwoFactor')->whereNumber('id');
        Route::put('client-portal/accounts/{id}', 'ClientPortalController@update')->whereNumber('id');
        Route::get('client-portal/deletions', 'ClientPortalController@deletions');
        Route::get('client-portal/settings', 'ClientPortalController@getSettings');
        Route::put('client-portal/settings', 'ClientPortalController@updateSettings');

        // Client Portal → Portal Languages / Translations (the tenant's portal_languages / portal_translations)
        Route::get('/admin/portal/languages', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'index']);
        Route::get('/admin/portal/languages/available', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'available']);
        Route::post('/admin/portal/languages/install/{locale}', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'install']);
        Route::put('/admin/portal/languages/{id}', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'update'])->whereNumber('id');
        Route::post('/admin/portal/languages/{id}/toggle-active', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'toggleActive'])->whereNumber('id');
        Route::post('/admin/portal/languages/{id}/set-default', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'setDefault'])->whereNumber('id');
        Route::delete('/admin/portal/languages/{id}', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'destroy'])->whereNumber('id');
        Route::get('/admin/portal/translations/{locale}', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'translations']);
        Route::put('/admin/portal/translations/{locale}', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'saveTranslation']);
        Route::post('/admin/portal/translations/{locale}/reset', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'resetTranslation']);
        Route::post('/admin/portal/translations/{locale}/delete', [\App\Http\Controllers\Api\Portal\PortalLanguagesController::class, 'deleteTranslation']);
    });
    Route::get('/client_ledger_pdf', 'ClientController@export');

    // ------------------------------- CLIENTS Ecommerce--------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('clients_without_ecommerce', 'ClientsEcommerceController');
    Route::get('ecommerce_clients', 'ClientsEcommerceController@accounts');
    Route::put('ecommerce_clients/{id}', 'ClientsEcommerceController@updateAccount');
    Route::delete('ecommerce_clients/{id}', 'ClientsEcommerceController@destroyAccount');
    // ------------------------------- Providers --------------------------\\
    // --------------------------------------------------------------------\\

    Route::resource('providers', 'ProvidersController')->middleware('tenant.limit:max_suppliers');
    Route::post('suppliers/import', 'ProvidersController@import');

    Route::post('providers/delete/by_selection', 'ProvidersController@delete_by_selection');
    Route::post('providers/{id}/adjust-opening-balance', 'ProvidersController@adjustOpeningBalance');
    Route::post('pay_supplier_due', 'ProvidersController@pay_supplier_due');
    Route::post('pay_purchase_return_due', 'ProvidersController@pay_purchase_return_due');

    // Supplier Wallet (plan feature `supplier_wallet` + Settings > Features switch).
    // `summary` stays outside the feature gate: payment forms ask it whether to
    // offer the wallet tender at all, and must get {enabled:false} not a 403.
    Route::get('supplier_wallets/{id}/summary', 'SupplierWalletController@summary')->whereNumber('id');
    Route::middleware('tenant.feature:supplier_wallet')->group(function () {
        Route::get('supplier_wallets', 'SupplierWalletController@index');
        Route::get('supplier_wallets/transactions', 'SupplierWalletController@transactions');
        Route::get('supplier_wallets/{id}', 'SupplierWalletController@show')->whereNumber('id');
        Route::get('supplier_wallets/{id}/transactions', 'SupplierWalletController@providerTransactions')->whereNumber('id');
        Route::post('supplier_wallets/{id}/top-up', 'SupplierWalletController@topUp')->whereNumber('id');
        Route::post('supplier_wallets/{id}/refund', 'SupplierWalletController@refund')->whereNumber('id');
        Route::post('supplier_wallets/{id}/adjust', 'SupplierWalletController@adjust')->whereNumber('id');
        Route::post('supplier_wallets/{id}/status', 'SupplierWalletController@setStatus')->whereNumber('id');
    });

    // Customer Wallet (plan feature `customer_wallet` + Settings > Features switch).
    // `summary` stays outside the feature gate: payment forms ask it whether to
    // offer the wallet tender at all, and must get {enabled:false} not a 403.
    Route::get('customer_wallets/{id}/summary', 'CustomerWalletController@summary')->whereNumber('id');
    Route::middleware('tenant.feature:customer_wallet')->group(function () {
        Route::get('customer_wallets', 'CustomerWalletController@index');
        Route::get('customer_wallets/dashboard', 'CustomerWalletController@dashboard');
        Route::get('customer_wallets/transactions', 'CustomerWalletController@transactions');
        Route::get('customer_wallets/settings', 'CustomerWalletController@settings');
        Route::post('customer_wallets/settings', 'CustomerWalletController@updateSettings');
        Route::get('customer_wallets/{id}', 'CustomerWalletController@show')->whereNumber('id');
        Route::get('customer_wallets/{id}/transactions', 'CustomerWalletController@clientTransactions')->whereNumber('id');
        Route::post('customer_wallets/{id}/top-up', 'CustomerWalletController@topUp')->whereNumber('id');
        Route::post('customer_wallets/{id}/refund', 'CustomerWalletController@refund')->whereNumber('id');
        Route::post('customer_wallets/{id}/adjust', 'CustomerWalletController@adjust')->whereNumber('id');
        Route::post('customer_wallets/{id}/status', 'CustomerWalletController@setStatus')->whereNumber('id');
        Route::post('customer_wallets/{id}/redeem', 'CustomerWalletController@redeem')->whereNumber('id');
        Route::get('customer_wallet_withdrawals', 'CustomerWalletController@withdrawals');
        Route::post('customer_wallet_withdrawals/{id}/approve', 'CustomerWalletController@approveWithdrawal')->whereNumber('id');
        Route::post('customer_wallet_withdrawals/{id}/reject', 'CustomerWalletController@rejectWithdrawal')->whereNumber('id');
        Route::post('customer_wallet_withdrawals/{id}/paid', 'CustomerWalletController@markWithdrawalPaid')->whereNumber('id');
        Route::get('gift_cards', 'CustomerWalletController@giftCards');
        Route::post('gift_cards', 'CustomerWalletController@storeGiftCard');
        Route::put('gift_cards/{id}', 'CustomerWalletController@updateGiftCard')->whereNumber('id');
        Route::delete('gift_cards/{id}', 'CustomerWalletController@destroyGiftCard')->whereNumber('id');
    });

    // Software License Keys (plan feature `license_keys`): uploaded key stock,
    // generated licenses, delivery + licensing API settings.
    Route::middleware('tenant.feature:license_keys')->group(function () {
        Route::get('license-keys/dashboard', 'LicenseKeysController@dashboard');
        Route::get('license-keys/products', 'LicenseKeysController@products');
        Route::get('license-keys/backorders', 'LicenseKeysController@backorders');
        Route::post('license-keys/sales/{id}/sync', 'LicenseKeysController@syncSale')->whereNumber('id');
        Route::get('license-keys/settings', 'LicenseKeysController@settings');
        Route::post('license-keys/settings', 'LicenseKeysController@updateSettings');
        Route::post('license-keys/settings/rotate-key', 'LicenseKeysController@rotateSigningKey');
        Route::get('license-keys/export', 'LicenseKeysController@export');
        Route::post('license-keys/preview', 'LicenseKeysController@preview');
        Route::post('license-keys/import', 'LicenseKeysController@import');
        Route::post('license-keys/issue', 'LicenseKeysController@issue');
        Route::get('license-keys', 'LicenseKeysController@index');
        Route::get('license-keys/{id}', 'LicenseKeysController@show')->whereNumber('id');
        Route::post('license-keys/{id}/reveal', 'LicenseKeysController@reveal')->whereNumber('id');
        Route::post('license-keys/{id}/restock', 'LicenseKeysController@restock')->whereNumber('id');
        Route::post('license-keys/{id}/void', 'LicenseKeysController@void')->whereNumber('id');
        Route::delete('license-keys/{id}', 'LicenseKeysController@destroy')->whereNumber('id');
        Route::post('license-keys/{id}/extend', 'LicenseKeysController@extend')->whereNumber('id');
        Route::post('license-keys/{id}/state', 'LicenseKeysController@state')->whereNumber('id');
        Route::post('license-keys/{id}/reset-activations', 'LicenseKeysController@resetActivations')->whereNumber('id');
        Route::post('license-keys/{id}/resend', 'LicenseKeysController@resend')->whereNumber('id');
    });

    // ------------------------------- Custom Fields --------------------------\\
    // --------------------------------------------------------------------\\

    // Specific routes must come before resource route to avoid conflicts
    Route::get('custom-field-values', 'CustomFieldController@getValues');
    Route::post('custom-field-values', 'CustomFieldController@saveValues');
    Route::resource('custom-fields', 'CustomFieldController');

    // ---------------------- POS Devices (card terminals) ----------------------\\
    Route::middleware('tenant.feature:pos_terminals')->group(function () {
        Route::get('pos-terminals', 'PosTerminalsController@index');
        Route::post('pos-terminals', 'PosTerminalsController@store');
        Route::get('pos-terminals/available', 'PosTerminalsController@available');
        Route::get('pos-terminals/transactions', 'PosTerminalsController@transactions');
        Route::get('pos-terminals/transactions/{id}', 'PosTerminalsController@show')->whereNumber('id');
        Route::post('pos-terminals/transactions/{id}/bridge-result', 'PosTerminalsController@bridgeResult')->whereNumber('id');
        Route::post('pos-terminals/transactions/{id}/cancel', 'PosTerminalsController@cancel')->whereNumber('id');
        Route::post('pos-terminals/transactions/{id}/dispatch', 'PosTerminalsController@send')->whereNumber('id');
        Route::post('pos-terminals/transactions/{id}/void', 'PosTerminalsController@void')->whereNumber('id');
        Route::post('pos-terminals/transactions/{id}/refund', 'PosTerminalsController@refund')->whereNumber('id');
        Route::put('pos-terminals/{id}', 'PosTerminalsController@update')->whereNumber('id');
        Route::delete('pos-terminals/{id}', 'PosTerminalsController@destroy')->whereNumber('id');
        Route::post('pos-terminals/{id}/test', 'PosTerminalsController@test')->whereNumber('id');
        Route::post('pos-terminals/{id}/test-result', 'PosTerminalsController@testResult')->whereNumber('id');
        Route::post('pos-terminals/{id}/charge', 'PosTerminalsController@charge')->whereNumber('id');
    });

    // ---------------------- POS (point of sales) ----------------------\\
    // ------------------------------------------------------------------\\

    Route::middleware('tenant.feature:pos')->group(function () {
        Route::post('pos/create_pos', 'PosController@CreatePOS');
        // Customer Wallet balance hint for the POS payment modal ({enabled:false} when off)
        Route::get('pos/wallet-balance/{client}', 'PosController@walletBalance')->whereNumber('client');
        // Sales > Offline Sales (sales replayed from tills' offline queues)
        Route::get('pos/offline_sales', 'PosOfflineSalesController@index');
        Route::post('pos/offline_sales/statuses', 'PosOfflineSalesController@statuses');
        Route::get('pos/offline_sales/{id}', 'PosOfflineSalesController@show')->whereNumber('id');
        Route::post('pos/offline_sales/{id}/retry', 'PosOfflineSalesController@retry')->whereNumber('id');
        Route::post('pos/offline_sales/{id}/discard', 'PosOfflineSalesController@discard')->whereNumber('id');
        // POS manager approval for removing items + Reports > POS Voided Items
        Route::get('pos/voids/approvers', 'PosVoidsController@approvers');
        Route::post('pos/voids/approve', 'PosVoidsController@approve');
        Route::post('pos/voids/log', 'PosVoidsController@log');
        Route::get('pos/voids', 'PosVoidsController@index');
        Route::get('pos/approval-pin', 'PosVoidsController@myPin');
        Route::post('pos/approval-pin', 'PosVoidsController@setMyPin');
        Route::get('pos/get_products_pos', 'PosController@GetProductsByParametre');
        Route::get('pos/get_products_pos_changes', 'PosController@GetProductsChanges');
        Route::post('pos/favorites/toggle', 'PosController@toggleFavorite');
        Route::get('pos/data_create_pos', 'PosController@GetELementPos');

        // ----------------------Draft -------------------------------------\\
        Route::post('pos/create_draft', 'PosController@CreateDraft');
        Route::get('get_draft_sales', 'PosController@get_draft_sales');
        Route::delete('remove_draft_sale/{id}', 'PosController@remove_draft_sale');
        Route::get('pos/data_draft_convert_sale/{id}', 'PosController@data_draft_convert_sale');
        Route::post('pos/submit_sale_from_draft', 'PosController@submit_sale_from_draft');

        // ---------------------- Cash Registers (optional module) ----------------------\\
        Route::post('cash-registers/open', 'CashRegisterController@openRegister');
        Route::post('cash-registers/close', 'CashRegisterController@closeRegister');
        Route::get('cash-registers/current/{user_id}', 'CashRegisterController@getCurrentRegister');
        Route::post('cash-registers/cash-move', 'CashRegisterController@cashInOut');
        Route::get('report/cash_registers', 'CashRegisterController@report');
    });
    Route::get('report/warranty_guarantee', 'ReportController@warrantyGuaranteeReport');
    // Mobile app: the quantity-alerts report lives with the products.
    Route::get('report/stock_alert', 'ProductsController@Products_Alert');

    // ------------------------------- Contracts (feature-gated) -----------------------\\
    Route::middleware('tenant.feature:contracts')->group(function () {
    Route::get('contracts/dashboard', 'ContractController@dashboard');
    Route::get('contracts/merge-fields', 'ContractController@mergeFields');
    Route::get('contracts/{id}/pdf', 'ContractController@pdf');
    Route::get('contracts/{id}/attachments', 'ContractController@attachmentsIndex');
    Route::post('contracts/{id}/attachments', 'ContractController@attachmentsStore');
    Route::delete('contracts/{contractId}/attachments/{attachmentId}', 'ContractController@attachmentsDestroy');
    Route::get('contracts/{contractId}/attachments/{attachmentId}/download', 'ContractController@attachmentsDownload');
    Route::get('contracts/{id}/comments', 'ContractController@commentsIndex');
    Route::post('contracts/{id}/comments', 'ContractController@commentsStore');
    Route::delete('contracts/{contractId}/comments/{commentId}', 'ContractController@commentsDestroy');
    Route::get('contracts/{id}/renewals', 'ContractController@renewalsIndex');
    Route::post('contracts/{id}/renewals', 'ContractController@renewalsStore');
    Route::get('contracts/{id}/tasks', 'ContractController@tasksIndex');
    Route::post('contracts/{id}/tasks', 'ContractController@tasksStore');
    Route::put('contracts/{contractId}/tasks/{taskId}', 'ContractController@tasksUpdate');
    Route::delete('contracts/{contractId}/tasks/{taskId}', 'ContractController@tasksDestroy');
    Route::get('contracts/{id}/notes', 'ContractController@notesIndex');
    Route::post('contracts/{id}/notes', 'ContractController@notesStore');
    Route::delete('contracts/{contractId}/notes/{noteId}', 'ContractController@notesDestroy');
    Route::get('contracts-templates', 'ContractController@templatesIndex');
    Route::post('contracts-templates', 'ContractController@templatesStore');
    Route::get('contracts-templates/{templateId}', 'ContractController@templatesShow');
    Route::put('contracts-templates/{templateId}', 'ContractController@templatesUpdate');
    Route::delete('contracts-templates/{templateId}', 'ContractController@templatesDestroy');
    Route::get('contracts/{contractId}/templates/{templateId}/render', 'ContractController@templateRender');
    Route::get('contracts/{contractId}/templates/{templateId}/pdf', 'ContractController@templatePdf');
    Route::post('contracts/delete/by_selection', 'ContractController@delete_by_selection');
    Route::resource('contracts', 'ContractController');
    });

    // ------------------------------- Project (feature-gated) -----------------------\\
    // ----------------------------------------------------------------\\
    Route::middleware('tenant.feature:projects')->group(function () {
    // --------------------- PROJECTS MANAGEMENT (workspace) ---------------\
    // Declared BEFORE Route::resource('projects') — the resource's {project}
    // wildcard would otherwise swallow "dashboard", "meta" and "reports".
    Route::get('projects/dashboard', 'ProjectWorkspaceController@dashboard');
    Route::get('projects/meta', 'ProjectWorkspaceController@meta');
    Route::get('projects/reports/delivery', 'ProjectWorkspaceController@projectReport');
    Route::get('projects/reports/workload', 'ProjectWorkspaceController@workloadReport');
    Route::get('projects/reports/milestones', 'ProjectWorkspaceController@milestoneReport');

    Route::post('project_milestones/delete/by_selection', 'ProjectMilestoneController@deleteBySelection');
    Route::post('project_milestones/{id}/status', 'ProjectMilestoneController@setStatus');
    Route::get('project_milestones', 'ProjectMilestoneController@index');
    Route::post('project_milestones', 'ProjectMilestoneController@store');
    Route::put('project_milestones/{id}', 'ProjectMilestoneController@update');
    Route::delete('project_milestones/{id}', 'ProjectMilestoneController@destroy');

    Route::post('project_time_logs/delete/by_selection', 'ProjectTimeLogController@deleteBySelection');
    Route::get('project_time_logs', 'ProjectTimeLogController@index');
    Route::post('project_time_logs', 'ProjectTimeLogController@store');
    Route::put('project_time_logs/{id}', 'ProjectTimeLogController@update');
    Route::delete('project_time_logs/{id}', 'ProjectTimeLogController@destroy');

    Route::resource('projects', 'ProjectController');

    Route::post('projects/delete/by_selection', 'ProjectController@delete_by_selection');
    Route::post('project_discussions', 'ProjectController@Create_project_discussions');
    Route::delete('project_discussions/{id}', 'ProjectController@destroy_project_discussion');

    Route::post('project_issues', 'ProjectController@Create_project_issues');
    Route::put('project_issues/{id}', 'ProjectController@Update_project_issues');
    Route::delete('project_issues/{id}', 'ProjectController@destroy_project_issues');

    Route::post('project_documents', 'ProjectController@Create_project_documents');
    Route::delete('project_documents/{id}', 'ProjectController@destroy_project_documents');

    // ------------------------------- Task (feature-gated with projects) -----------------------\\
    // ----------------------------------------------------------------\\

    Route::resource('tasks', 'TaskController');
    Route::put('update_task_status/{id}', 'TaskController@update_task_status');

    Route::post('tasks/delete/by_selection', 'TaskController@delete_by_selection');
    Route::get('tasks_kanban', 'TaskController@tasks_kanban')->name('tasks_kanban');
    Route::post('task_change_status', 'TaskController@task_change_status')->name('task_change_status');

    Route::post('task_discussions', 'TaskController@Create_task_discussions');
    Route::delete('task_discussions/{id}', 'TaskController@destroy_task_discussion');

    Route::post('task_documents', 'TaskController@Create_task_documents');
    Route::delete('task_documents/{id}', 'TaskController@destroy_task_documents');
    });

    // ------------------------------- Bookings (feature-gated) -----------------------\\
    // ------------------------------------------------------------------------\\
    Route::middleware('tenant.feature:bookings')->group(function () {
    // Booking Management settings + business types (before bookings/{id})
    Route::get('bookings/settings', 'BookingSettingsController@show');
    Route::put('bookings/settings', 'BookingSettingsController@update');
    Route::get('bookings/types', 'BookingSettingsController@types');
    // Booking Management › Services (booking details of service products)
    Route::get('bookings/services', 'BookingServicesController@index');
    Route::post('bookings/services/bulk', 'BookingServicesController@bulk');
    Route::get('bookings/services/suggested-categories', 'BookingServicesController@suggestions');
    Route::post('bookings/services/suggested-categories', 'BookingServicesController@createSuggested');
    Route::put('bookings/services/{id}', 'BookingServicesController@update')->where('id', '[0-9]+');
    Route::delete('bookings/services/{id}', 'BookingServicesController@destroy')->where('id', '[0-9]+');
    // Booking Management › Staff & working hours + free times
    Route::get('bookings/staff', 'BookingStaffController@index');
    Route::post('bookings/staff', 'BookingStaffController@store');
    Route::post('bookings/staff/{id}/update', 'BookingStaffController@update')->where('id', '[0-9]+');
    Route::delete('bookings/staff/{id}', 'BookingStaffController@destroy')->where('id', '[0-9]+');
    Route::put('bookings/staff/{id}/hours', 'BookingStaffController@hours')->where('id', '[0-9]+');
    Route::get('bookings/staff/{id}/shift-hours', 'BookingStaffController@shiftHours')->where('id', '[0-9]+');
    Route::post('bookings/staff/{id}/time-off', 'BookingStaffController@storeTimeOff')->where('id', '[0-9]+');
    Route::delete('bookings/staff/{id}/time-off/{offId}', 'BookingStaffController@destroyTimeOff')->where(['id' => '[0-9]+', 'offId' => '[0-9]+']);
    Route::put('bookings/staff/{id}/services', 'BookingStaffController@services')->where('id', '[0-9]+');
    Route::get('bookings/availability', 'BookingAvailabilityController@slots');
    Route::get('bookings/availability/days', 'BookingAvailabilityController@days');
    Route::get('bookings/availability/check', 'BookingAvailabilityController@check');
    // Booking Management workflow: Requests inbox, staff day view, status steps, charge
    Route::get('bookings/requests', 'BookingWorkflowController@requests');
    Route::get('bookings/day', 'BookingWorkflowController@day');
    // Dashboard & reports
    Route::get('bookings/dashboard', 'BookingReportController@dashboard');
    // Set-up guide (phase 8)
    Route::get('bookings/guide', 'BookingGuideController@index');
    Route::get('bookings/reports/{type}', 'BookingReportController@report')
        ->whereIn('type', ['bookings', 'services', 'staff', 'times', 'website', 'lost', 'customers']);
    Route::get('bookings/{id}/details', 'BookingWorkflowController@details')->where('id', '[0-9]+');
    Route::post('bookings/{id}/confirm', 'BookingWorkflowController@confirm')->where('id', '[0-9]+');
    Route::post('bookings/{id}/decline', 'BookingWorkflowController@decline')->where('id', '[0-9]+');
    Route::post('bookings/{id}/cancel', 'BookingWorkflowController@cancel')->where('id', '[0-9]+');
    Route::post('bookings/{id}/reschedule', 'BookingWorkflowController@reschedule')->where('id', '[0-9]+');
    Route::post('bookings/{id}/arrive', 'BookingWorkflowController@arrive')->where('id', '[0-9]+');
    Route::post('bookings/{id}/no-show', 'BookingWorkflowController@noShow')->where('id', '[0-9]+');
    Route::post('bookings/{id}/complete', 'BookingWorkflowController@complete')->where('id', '[0-9]+');
    Route::post('bookings/{id}/restore', 'BookingWorkflowController@restore')->where('id', '[0-9]+');
    Route::post('bookings/{id}/propose', 'BookingWorkflowController@propose')->where('id', '[0-9]+');
    // Deposit (phase 8)
    Route::post('bookings/{id}/deposit/waive', 'BookingWorkflowController@depositWaive')->where('id', '[0-9]+');
    Route::post('bookings/{id}/deposit/refunded', 'BookingWorkflowController@depositRefunded')->where('id', '[0-9]+');
    Route::post('bookings/{id}/deposit/request', 'BookingWorkflowController@depositRequest')->where('id', '[0-9]+');
    Route::get('bookings/{id}/charge', 'BookingWorkflowController@chargeQuote')->where('id', '[0-9]+');
    Route::post('bookings/{id}/charge', 'BookingWorkflowController@charge')->where('id', '[0-9]+');
    Route::get('bookings', 'BookingController@index');
    Route::post('bookings', 'BookingController@store');
    Route::get('bookings/create', 'BookingController@create');
    Route::get('bookings/{id}', 'BookingController@show');
    Route::get('bookings/{id}/edit', 'BookingController@edit');
    Route::put('bookings/{id}', 'BookingController@update');
    Route::delete('bookings/{id}', 'BookingController@destroy');
    Route::put('bookings/{id}/status', 'BookingController@changeStatus');
    Route::post('bookings_send_email', 'BookingController@Send_Email');

    // Trays (booking containers)
    Route::post('trays/delete/by_selection', 'TraysController@delete_by_selection');
    Route::resource('trays', 'TraysController');
    });

    // ------------------------------- Assets (feature-gated) --------------------------\\
    // ------------------------------------------------------------------\\
    Route::middleware('tenant.feature:assets')->group(function () {
    Route::get('assets/due', 'AssetController@due');
    Route::get('assets/schedule-info', 'AssetController@scheduleInfo');
    Route::post('assets/run-validation-due', 'AssetController@runValidationDue');
    Route::resource('assets', 'AssetController');
    Route::post('assets/delete/by_selection', 'AssetController@delete_by_selection');
    Route::get('assets_warehouses', 'AssetController@warehouses');

    // ------------------------------- Asset Management --------------------------\\

    Route::get('assets/workspace/dashboard', 'AssetWorkspaceController@dashboard');
    Route::get('assets/workspace/meta', 'AssetWorkspaceController@meta');
    Route::get('assets/workspace/details/{id}', 'AssetWorkspaceController@details');
    Route::post('assets/workspace/dispose/{id}', 'AssetWorkspaceController@dispose');
    Route::get('assets/workspace/report/register', 'AssetWorkspaceController@registerReport');
    Route::get('assets/workspace/report/maintenance', 'AssetWorkspaceController@maintenanceReport');
    Route::get('assets/workspace/report/custody', 'AssetWorkspaceController@custodyReport');

    Route::post('asset_assignments/delete/by_selection', 'AssetAssignmentController@deleteBySelection');
    Route::post('asset_assignments/{id}/checkin', 'AssetAssignmentController@checkin');
    Route::get('asset_assignments', 'AssetAssignmentController@index');
    Route::post('asset_assignments', 'AssetAssignmentController@store');
    Route::put('asset_assignments/{id}', 'AssetAssignmentController@update');
    Route::delete('asset_assignments/{id}', 'AssetAssignmentController@destroy');

    Route::post('asset_maintenances/delete/by_selection', 'AssetMaintenanceController@deleteBySelection');
    Route::post('asset_maintenances/{id}/status', 'AssetMaintenanceController@setStatus');
    Route::get('asset_maintenances', 'AssetMaintenanceController@index');
    Route::post('asset_maintenances', 'AssetMaintenanceController@store');
    Route::put('asset_maintenances/{id}', 'AssetMaintenanceController@update');
    Route::delete('asset_maintenances/{id}', 'AssetMaintenanceController@destroy');

    Route::post('asset_transfers/delete/by_selection', 'AssetTransferController@deleteBySelection');
    Route::get('asset_transfers', 'AssetTransferController@index');
    Route::post('asset_transfers', 'AssetTransferController@store');
    Route::delete('asset_transfers/{id}', 'AssetTransferController@destroy');

    // ------------------------------- Assets Category --------------------------\\
    // ------------------------------------------------------------------\\
    Route::resource('assets_category', 'CategoryAssetController');
    });

    // ------------------------------- PRODUCTS --------------------------\\
    // ------------------------------------------------------------------\\

    // Typeahead for product pickers (any visible product). Before the resource,
    // or its products/{product} route would answer instead.
    Route::get('products/search-basic', 'ProductsController@search_products_basic');
    // Before the resource: GET products/{product} would swallow it.
    Route::get('products/generate-code', 'ProductsController@generate_code')->middleware('auth:api');
    // Customer price-check tablet: name + price only, any signed-in user.
    Route::get('price-check', 'ProductsController@price_check')->middleware('auth:api');
    Route::resource('products', 'ProductsController')->middleware('tenant.limit:max_products');
    Route::post('products/{id}/duplicate', 'ProductsController@duplicate')->middleware('tenant.limit:max_products');
    Route::post('products/warehouse_locations', 'ProductsController@storeWarehouseLocation');
    Route::post('products/import/single', 'ProductsController@import_single_products')->middleware('auth:api');
    Route::post('products/import/variants', 'ProductsController@import_variant_products')->middleware('auth:api');
    Route::post('products/import/service', 'ProductsController@import_service_products')->middleware('auth:api');
    Route::post('products/import/update-only', 'ProductsController@import_update_only')->middleware('auth:api');
    Route::post('products/import/barcodes', 'ProductsController@import_barcodes')->middleware('auth:api');
    // Different prices per store: spreadsheet export / import
    Route::get('products/warehouse-prices/meta', 'WarehousePricesController@meta')->middleware('auth:api');
    Route::get('products/warehouse-prices/export', 'WarehousePricesController@export')->middleware('auth:api');
    Route::post('products/import/warehouse-prices', 'WarehousePricesController@import')->middleware('auth:api');

    Route::get('get_Products_by_warehouse/{id}', 'ProductsController@Products_by_Warehouse');
    Route::get('get_product_detail_api/{id}', 'ProductsController@Get_Products_Details');
    Route::get('get_products_stock_alerts', 'ProductsController@Products_Alert');
    Route::get('barcode_create_page', 'ProductsController@Get_element_barcode');
    Route::post('products/delete/by_selection', 'ProductsController@delete_by_selection');
    Route::get('show_product_data/{id}/{variant_id}', 'ProductsController@show_product_data');
    Route::get('show_product_data/{id}/{variant_id}/{warehouse_id}', 'ProductsController@show_product_data');
    Route::get('get_products_materiels', 'ProductsController@get_products_materiels')->name('get_products_materiels');

    Route::get('opening-stock/import/meta', 'ProductsController@opening_stock_meta');
    Route::post('opening-stock/import/single', 'ProductsController@opening_stock_import_single');
    Route::post('opening-stock/import/variants', 'ProductsController@opening_stock_import_variants');

    // ---- count stock ----------
    Route::get('count_stock', 'ProductsController@count_stock_list');
    Route::post('store_count_stock', 'ProductsController@store_count_stock');
    Route::post('count_stock/upload', 'ProductsController@upload_counted_stock');
    Route::get('count_stock/{id}/download', 'ProductsController@download_count_stock');

    // ------------------------------- Category --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('categories', 'CategorieController')->parameters(['categories' => 'id']);
    Route::post('categories/delete/by_selection', 'CategorieController@delete_by_selection');

    // Product Subcategories
    Route::resource('subcategories', 'SubCategoryController');
    Route::post('subcategories/delete/by_selection', 'SubCategoryController@delete_by_selection');
    Route::get('subcategories/by-category/{category_id}', 'SubCategoryController@getByCategory');

    // ------------------------------- Units --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('units', 'UnitsController');
    Route::get('get_sub_units_by_base', 'UnitsController@Get_Units_SubBase');
    Route::get('get_units', 'UnitsController@Get_sales_units');
    Route::get('unit_factors', 'UnitsController@factors');

    // ------------------------------- Brands--------------------------\\
    // ------------------------------------------------------------------\\
    Route::resource('brands', 'BrandsController');
    Route::post('brands/delete/by_selection', 'BrandsController@delete_by_selection');

    //------------------------------- Size guides (size charts) ------------------
    Route::get('size_guides/list', 'SizeGuideController@list');
    Route::post('size_guides/delete/by_selection', 'SizeGuideController@delete_by_selection');
    Route::resource('size_guides', 'SizeGuideController')->only(['index', 'store', 'update', 'destroy']);

    // ------------------------------- Currencies --------------------------\\
    // ------------------------------------------------------------------\\

    // Lightweight list for the Multi-Currency document pickers (no `currency`
    // permission — cashiers and salespeople need it too).
    Route::get('currencies_list', 'CurrencyController@Get_Currencies');
    Route::post('currencies/{id}/set-default', 'CurrencyController@setDefault');
    Route::resource('currencies', 'CurrencyController');
    Route::post('currencies/delete/by_selection', 'CurrencyController@delete_by_selection');

    // ------------------------------- WAREHOUSES --------------------------\\

    Route::resource('warehouses', 'WarehouseController')->middleware('tenant.limit:max_warehouses');
    Route::post('warehouses/delete/by_selection', 'WarehouseController@delete_by_selection');

    // ------------------------------- WAREHOUSE LOCATIONS (Rack/Location) --------------------------\\
    Route::resource('warehouse_locations', 'WarehouseLocationController');
    Route::get('warehouse_locations/by_warehouse/{id}', 'WarehouseLocationController@by_warehouse');

    // ------------------------------- PRODUCT BATCHES (Pharmacy mode) --------------------------\\
    Route::get('product_batches', 'ProductBatchController@index');
    Route::put('product_batches/{id}', 'ProductBatchController@update');
    Route::post('product_batches/{id}/writeoff', 'ProductBatchController@writeOff');
    Route::delete('product_batches/{id}', 'ProductBatchController@destroy');
    // Opening Batch & Expiry: label pre-tracking stock with batches (no stock movement)
    Route::get('products/{id}/opening_batches', 'ProductBatchController@openingMeta');
    Route::post('products/{id}/opening_batches', 'ProductBatchController@storeOpening');

    // ------------------------------- PURCHASES --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('purchases', 'PurchasesController');
    Route::get('purchases/{id}/barcodes', 'PurchasesController@get_barcode_products');
    Route::get('get_payments_by_purchase/{id}', 'PurchasesController@Get_Payments');
    Route::post('purchase_send_email', 'PurchasesController@Send_Email');
    Route::post('purchase_send_sms', 'PurchasesController@Send_SMS');
    Route::post('purchases_delete_by_selection', 'PurchasesController@delete_by_selection');
    Route::get('get_Products_by_purchase/{id}', 'PurchasesController@get_Products_by_purchase');
    Route::post('purchase_send_whatsapp', 'PurchasesController@purchase_send_whatsapp');

    Route::get('get_import_purchases', 'PurchasesController@get_import_purchases');
    Route::post('preview_import_purchases', 'PurchasesController@preview_import_purchases');
    Route::post('store_import_purchases', 'PurchasesController@store_import_purchases');
    
    // ------------------------------- Purchase Documents --------------------------\\
    Route::get('purchases/{id}/documents', 'PurchasesController@getDocuments');
    Route::post('purchases/{id}/documents', 'PurchasesController@uploadDocuments');
    Route::get('purchases/documents/{id}/download', 'PurchasesController@downloadDocument');
    Route::delete('purchases/documents/{id}', 'PurchasesController@deleteDocument');

    // ------------------------------- Payments  Purchases --------------------------\\
    // ------------------------------------------------------------------------------\\

    Route::resource('payment_purchase', 'PaymentPurchasesController');
    Route::get('payment_purchase_get_number', 'PaymentPurchasesController@getNumberOrder');
    Route::post('payment_purchase_send_email', 'PaymentPurchasesController@SendEmail');
    Route::post('payment_purchase_send_sms', 'PaymentPurchasesController@Send_SMS');

    // -------------------------------  Promotions --------------------------\\
    // ------------------------------------------------------------------\\

    Route::middleware('tenant.feature:promotions')->group(function () {
        Route::post('promotions/preview_warehouses', 'PromotionsController@previewWarehouses');
        Route::post('promotions/applicable', 'PromotionsController@applicable');
        Route::post('promotions/validate_code', 'PromotionsController@validateCode');
        Route::get('promotions/usages', 'PromotionsController@usages');
        Route::get('promotions/usages_summary', 'PromotionsController@usageSummary');
        Route::post('promotions/{id}/toggle', 'PromotionsController@toggle');
        Route::resource('promotions', 'PromotionsController');
    });

    // ---------------------------  Document Archive --------------------------\
    // ------------------------------------------------------------------\

    Route::middleware('tenant.feature:documents')->group(function () {
        // Literal segments come first: `documents/stats` would otherwise be eaten
        // by the {id} wildcard below.
        Route::get('documents/stats', 'DocumentArchiveController@stats');
        Route::get('documents/folders', 'DocumentArchiveController@folders');
        Route::post('documents/folders', 'DocumentArchiveController@storeFolder');
        Route::put('documents/folders/{id}', 'DocumentArchiveController@updateFolder');
        Route::delete('documents/folders/{id}', 'DocumentArchiveController@destroyFolder');
        Route::post('documents/delete/by_selection', 'DocumentArchiveController@deleteBySelection');
        Route::post('documents/move/by_selection', 'DocumentArchiveController@moveBySelection');
        // No preview route: files are served directly from public/, so the UI
        // reads `url` off the row. download() only forces the attachment.
        Route::get('documents/{id}/download', 'DocumentArchiveController@download');
        Route::post('documents/{id}/star', 'DocumentArchiveController@toggleStar');
        Route::post('documents/{id}/versions', 'DocumentArchiveController@storeVersion');
        Route::get('documents/{id}/versions/{versionId}/download', 'DocumentArchiveController@downloadVersion');
        Route::post('documents/{id}/versions/{versionId}/restore', 'DocumentArchiveController@restoreVersion');
        // Metadata updates arrive as PUT/JSON; new files always go through POST
        // (multipart), which PHP cannot parse on a PUT body.
        Route::get('documents', 'DocumentArchiveController@index');
        Route::post('documents', 'DocumentArchiveController@store');
        Route::get('documents/{id}', 'DocumentArchiveController@show');
        Route::put('documents/{id}', 'DocumentArchiveController@update');
        Route::delete('documents/{id}', 'DocumentArchiveController@destroy');
    });

    // -------------------------  Manufacturing (MRP) ------------------------\
    // ------------------------------------------------------------------\

    Route::middleware('tenant.feature:manufacturing')->group(function () {
        Route::get('mrp/meta', 'MrpBomController@meta');
        Route::get('mrp/products', 'MrpBomController@products');
        Route::get('mrp/dashboard', 'MrpPlanningController@dashboard');

        // Work centres
        Route::get('mrp/work-centers', 'MrpBomController@workCenters');
        Route::post('mrp/work-centers', 'MrpBomController@storeWorkCenter');
        Route::put('mrp/work-centers/{id}', 'MrpBomController@updateWorkCenter');
        Route::delete('mrp/work-centers/{id}', 'MrpBomController@destroyWorkCenter');

        // Bills of materials — literal segments before {id}
        Route::post('mrp/boms/{id}/duplicate', 'MrpBomController@duplicate');
        Route::get('mrp/boms/{id}/explode', 'MrpBomController@explode');
        Route::get('mrp/boms', 'MrpBomController@index');
        Route::post('mrp/boms', 'MrpBomController@store');
        Route::get('mrp/boms/{id}', 'MrpBomController@show');
        Route::put('mrp/boms/{id}', 'MrpBomController@update');
        Route::delete('mrp/boms/{id}', 'MrpBomController@destroy');

        // Shop floor
        Route::get('mrp/work-orders', 'MrpProductionController@workOrders');
        Route::post('mrp/work-orders/{id}/start', 'MrpProductionController@startWorkOrder');
        Route::post('mrp/work-orders/{id}/finish', 'MrpProductionController@finishWorkOrder');

        // Quality
        Route::get('mrp/quality-checks', 'MrpProductionController@qualityChecks');
        Route::post('mrp/quality-checks', 'MrpProductionController@storeQualityCheck');
        Route::delete('mrp/quality-checks/{id}', 'MrpProductionController@destroyQualityCheck');

        // Planning
        Route::post('mrp/planning/run', 'MrpPlanningController@run');
        Route::get('mrp/planning/runs', 'MrpPlanningController@runs');
        Route::get('mrp/planning/suggestions', 'MrpPlanningController@suggestions');
        Route::post('mrp/planning/suggestions/accept-all', 'MrpPlanningController@acceptAll');
        Route::post('mrp/planning/suggestions/{id}/accept', 'MrpPlanningController@acceptSuggestion');
        Route::post('mrp/planning/suggestions/{id}/dismiss', 'MrpPlanningController@dismissSuggestion');

        // Reports
        Route::get('mrp/reports/cost', 'MrpPlanningController@costReport');
        Route::get('mrp/reports/efficiency', 'MrpPlanningController@efficiencyReport');
        Route::get('mrp/reports/material', 'MrpPlanningController@materialReport');
        Route::get('mrp/reports/quality', 'MrpPlanningController@qualityReport');

        // Production orders — actions before the {id} routes
        Route::post('mrp/production-orders/{id}/release', 'MrpProductionController@release');
        Route::post('mrp/production-orders/{id}/complete', 'MrpProductionController@complete');
        Route::post('mrp/production-orders/{id}/cancel', 'MrpProductionController@cancel');
        Route::get('mrp/production-orders/{id}/barcodes', 'MrpProductionController@barcodes');
        Route::get('mrp/production-orders', 'MrpProductionController@index');
        Route::post('mrp/production-orders', 'MrpProductionController@store');
        Route::get('mrp/production-orders/{id}', 'MrpProductionController@show');
        Route::put('mrp/production-orders/{id}', 'MrpProductionController@update');
        Route::delete('mrp/production-orders/{id}', 'MrpProductionController@destroy');
    });

    // ----------------------- Vehicle & Fleet Management ---------------------\
    // ------------------------------------------------------------------\

    Route::middleware('tenant.feature:fleet')->group(function () {
        // Literal segments first — the {id} routes would otherwise swallow them.
        Route::get('fleet/dashboard', 'FleetVehicleController@dashboard');
        Route::get('fleet/meta', 'FleetVehicleController@meta');

        Route::get('fleet/reports/costs', 'FleetReportController@costs');
        Route::get('fleet/reports/fuel', 'FleetReportController@fuel');
        Route::get('fleet/reports/maintenance', 'FleetReportController@maintenance');
        Route::get('fleet/reports/drivers', 'FleetReportController@drivers');

        Route::post('fleet/maintenances/delete/by_selection', 'FleetMaintenanceController@deleteBySelection');
        Route::get('fleet/maintenances', 'FleetMaintenanceController@index');
        Route::post('fleet/maintenances', 'FleetMaintenanceController@store');
        Route::put('fleet/maintenances/{id}', 'FleetMaintenanceController@update');
        Route::delete('fleet/maintenances/{id}', 'FleetMaintenanceController@destroy');

        Route::post('fleet/fuel-logs/delete/by_selection', 'FleetFuelLogController@deleteBySelection');
        Route::get('fleet/fuel-logs', 'FleetFuelLogController@index');
        Route::post('fleet/fuel-logs', 'FleetFuelLogController@store');
        Route::put('fleet/fuel-logs/{id}', 'FleetFuelLogController@update');
        Route::delete('fleet/fuel-logs/{id}', 'FleetFuelLogController@destroy');

        Route::post('fleet/assignments/delete/by_selection', 'FleetAssignmentController@deleteBySelection');
        Route::post('fleet/assignments/{id}/close', 'FleetAssignmentController@close');
        Route::get('fleet/assignments', 'FleetAssignmentController@index');
        Route::post('fleet/assignments', 'FleetAssignmentController@store');
        Route::put('fleet/assignments/{id}', 'FleetAssignmentController@update');
        Route::delete('fleet/assignments/{id}', 'FleetAssignmentController@destroy');

        // Photos arrive as multipart, which PHP cannot parse from a PUT body —
        // the edit form therefore POSTs to /update.
        Route::post('fleet/vehicles/delete/by_selection', 'FleetVehicleController@deleteBySelection');
        Route::get('fleet/vehicles/{id}/edit', 'FleetVehicleController@edit');
        Route::post('fleet/vehicles/{id}/update', 'FleetVehicleController@update');
        Route::get('fleet/vehicles', 'FleetVehicleController@index');
        Route::post('fleet/vehicles', 'FleetVehicleController@store');
        Route::get('fleet/vehicles/{id}', 'FleetVehicleController@show');
        Route::delete('fleet/vehicles/{id}', 'FleetVehicleController@destroy');
    });

    // Hospital Management — every endpoint under one plan feature; literal
    // segments are registered before {id} routes so they are never captured.
    Route::middleware('tenant.feature:hospital')->group(function () {
        Route::get('hospital/dashboard', 'HospitalDashboardController@dashboard');
        Route::get('hospital/meta', 'HospitalDashboardController@meta');
        Route::get('hospital/available-beds', 'HospitalDashboardController@availableBeds');
        Route::get('hospital/search/patients', 'HospitalDashboardController@searchPatients');
        Route::get('hospital/search/medicines', 'HospitalDashboardController@searchMedicines');

        Route::get('hospital/reports/doctors', 'HospitalReportController@doctors');
        Route::get('hospital/reports/revenue', 'HospitalReportController@revenue');
        Route::get('hospital/reports/occupancy', 'HospitalReportController@occupancy');
        Route::get('hospital/reports/patients', 'HospitalReportController@patients');
        Route::get('hospital/reports/collections', 'HospitalReportController@collections');

        Route::post('hospital/departments/delete/by_selection', 'HospitalDepartmentController@deleteBySelection');
        Route::get('hospital/departments', 'HospitalDepartmentController@index');
        Route::post('hospital/departments', 'HospitalDepartmentController@store');
        Route::put('hospital/departments/{id}', 'HospitalDepartmentController@update');
        Route::delete('hospital/departments/{id}', 'HospitalDepartmentController@destroy');

        // Photos arrive as multipart, which PHP cannot parse from a PUT body —
        // the edit forms therefore POST to /update.
        Route::post('hospital/doctors/delete/by_selection', 'HospitalDoctorController@deleteBySelection');
        Route::get('hospital/doctors/{id}/schedule', 'HospitalDoctorController@schedule');
        Route::post('hospital/doctors/{id}/update', 'HospitalDoctorController@update');
        Route::get('hospital/doctors', 'HospitalDoctorController@index');
        Route::post('hospital/doctors', 'HospitalDoctorController@store');
        Route::get('hospital/doctors/{id}', 'HospitalDoctorController@show');
        Route::delete('hospital/doctors/{id}', 'HospitalDoctorController@destroy');

        Route::post('hospital/patients/delete/by_selection', 'HospitalPatientController@deleteBySelection');
        Route::get('hospital/patients/{id}/timeline', 'HospitalPatientController@timeline');
        Route::get('hospital/patients/{id}/edit', 'HospitalPatientController@edit');
        Route::post('hospital/patients/{id}/update', 'HospitalPatientController@update');
        Route::get('hospital/patients', 'HospitalPatientController@index');
        Route::post('hospital/patients', 'HospitalPatientController@store');
        Route::get('hospital/patients/{id}', 'HospitalPatientController@show');
        Route::delete('hospital/patients/{id}', 'HospitalPatientController@destroy');

        Route::get('hospital/appointments/board', 'HospitalAppointmentController@board');
        Route::post('hospital/appointments/delete/by_selection', 'HospitalAppointmentController@deleteBySelection');
        Route::post('hospital/appointments/{id}/status', 'HospitalAppointmentController@setStatus');
        Route::get('hospital/appointments', 'HospitalAppointmentController@index');
        Route::post('hospital/appointments', 'HospitalAppointmentController@store');
        Route::put('hospital/appointments/{id}', 'HospitalAppointmentController@update');
        Route::delete('hospital/appointments/{id}', 'HospitalAppointmentController@destroy');

        Route::post('hospital/visits/delete/by_selection', 'HospitalVisitController@deleteBySelection');
        Route::get('hospital/visits/from-appointment/{appointmentId}', 'HospitalVisitController@fromAppointment');
        Route::get('hospital/visits', 'HospitalVisitController@index');
        Route::post('hospital/visits', 'HospitalVisitController@store');
        Route::get('hospital/visits/{id}', 'HospitalVisitController@show');
        Route::put('hospital/visits/{id}', 'HospitalVisitController@update');
        Route::delete('hospital/visits/{id}', 'HospitalVisitController@destroy');

        Route::get('hospital/beds', 'HospitalWardController@beds');
        Route::post('hospital/beds', 'HospitalWardController@storeBed');
        Route::put('hospital/beds/{id}', 'HospitalWardController@updateBed');
        Route::delete('hospital/beds/{id}', 'HospitalWardController@destroyBed');
        Route::get('hospital/wards', 'HospitalWardController@index');
        Route::post('hospital/wards', 'HospitalWardController@store');
        Route::put('hospital/wards/{id}', 'HospitalWardController@update');
        Route::delete('hospital/wards/{id}', 'HospitalWardController@destroy');

        Route::post('hospital/admissions/{id}/discharge', 'HospitalAdmissionController@discharge');
        Route::post('hospital/admissions/{id}/transfer', 'HospitalAdmissionController@transfer');
        Route::get('hospital/admissions', 'HospitalAdmissionController@index');
        Route::post('hospital/admissions', 'HospitalAdmissionController@store');
        Route::put('hospital/admissions/{id}', 'HospitalAdmissionController@update');
        Route::delete('hospital/admissions/{id}', 'HospitalAdmissionController@destroy');

        Route::get('hospital/lab-tests', 'HospitalLabController@tests');
        Route::post('hospital/lab-tests', 'HospitalLabController@storeTest');
        Route::put('hospital/lab-tests/{id}', 'HospitalLabController@updateTest');
        Route::delete('hospital/lab-tests/{id}', 'HospitalLabController@destroyTest');
        Route::post('hospital/lab-orders/delete/by_selection', 'HospitalLabController@deleteBySelection');
        Route::post('hospital/lab-orders/{id}/results', 'HospitalLabController@saveResults');
        Route::get('hospital/lab-orders', 'HospitalLabController@orders');
        Route::post('hospital/lab-orders', 'HospitalLabController@storeOrder');
        Route::get('hospital/lab-orders/{id}', 'HospitalLabController@showOrder');
        Route::put('hospital/lab-orders/{id}', 'HospitalLabController@updateOrder');
        Route::delete('hospital/lab-orders/{id}', 'HospitalLabController@destroyOrder');

        Route::get('hospital/payments', 'HospitalBillingController@payments');
        Route::post('hospital/invoices/draft-from', 'HospitalBillingController@draftFrom');
        Route::post('hospital/invoices/{id}/payments', 'HospitalBillingController@storePayment');
        Route::delete('hospital/invoices/{invoiceId}/payments/{paymentId}', 'HospitalBillingController@destroyPayment');
        Route::get('hospital/invoices', 'HospitalBillingController@index');
        Route::post('hospital/invoices', 'HospitalBillingController@store');
        Route::get('hospital/invoices/{id}/pdf', 'HospitalBillingController@invoicePdf');
        Route::get('hospital/invoices/{id}', 'HospitalBillingController@show');
        Route::put('hospital/invoices/{id}', 'HospitalBillingController@update');
        Route::delete('hospital/invoices/{id}', 'HospitalBillingController@destroy');
    });

    // School Management — every endpoint under one plan feature; literal
    // segments are registered before {id} routes so they are never captured.
    Route::middleware('tenant.feature:school')->group(function () {
        Route::get('school/dashboard', 'SchoolDashboardController@dashboard');
        Route::get('school/meta', 'SchoolDashboardController@meta');
        Route::get('school/search/students', 'SchoolDashboardController@searchStudents');

        Route::get('school/reports/enrollment', 'SchoolReportController@enrollment');
        Route::get('school/reports/attendance', 'SchoolReportController@attendance');
        Route::get('school/reports/absentees', 'SchoolReportController@absentees');
        Route::get('school/reports/performance', 'SchoolReportController@performance');
        Route::get('school/reports/fees', 'SchoolReportController@fees');
        Route::get('school/reports/defaulters', 'SchoolReportController@defaulters');
        Route::get('school/reports/teachers', 'SchoolReportController@teachers');

        Route::get('school/years', 'SchoolAcademicController@years');
        Route::post('school/years', 'SchoolAcademicController@storeYear');
        Route::put('school/years/{id}', 'SchoolAcademicController@updateYear');
        Route::delete('school/years/{id}', 'SchoolAcademicController@destroyYear');
        Route::get('school/classes', 'SchoolAcademicController@classes');
        Route::post('school/classes', 'SchoolAcademicController@storeClass');
        Route::put('school/classes/{id}', 'SchoolAcademicController@updateClass');
        Route::delete('school/classes/{id}', 'SchoolAcademicController@destroyClass');
        Route::get('school/sections', 'SchoolAcademicController@sections');
        Route::post('school/sections', 'SchoolAcademicController@storeSection');
        Route::put('school/sections/{id}', 'SchoolAcademicController@updateSection');
        Route::delete('school/sections/{id}', 'SchoolAcademicController@destroySection');
        Route::get('school/subjects', 'SchoolAcademicController@subjects');
        Route::post('school/subjects', 'SchoolAcademicController@storeSubject');
        Route::put('school/subjects/{id}', 'SchoolAcademicController@updateSubject');
        Route::delete('school/subjects/{id}', 'SchoolAcademicController@destroySubject');

        // Photos arrive as multipart, which PHP cannot parse from a PUT body —
        // the edit forms therefore POST to /update.
        Route::post('school/teachers/delete/by_selection', 'SchoolTeacherController@deleteBySelection');
        Route::get('school/teachers/{id}/timetable', 'SchoolTeacherController@timetable');
        Route::post('school/teachers/{id}/update', 'SchoolTeacherController@update');
        Route::get('school/teachers', 'SchoolTeacherController@index');
        Route::post('school/teachers', 'SchoolTeacherController@store');
        Route::delete('school/teachers/{id}', 'SchoolTeacherController@destroy');

        Route::post('school/students/delete/by_selection', 'SchoolStudentController@deleteBySelection');
        Route::get('school/students/{id}/timeline', 'SchoolStudentController@timeline');
        Route::get('school/students/{id}/edit', 'SchoolStudentController@edit');
        Route::post('school/students/{id}/update', 'SchoolStudentController@update');
        Route::get('school/students', 'SchoolStudentController@index');
        Route::post('school/students', 'SchoolStudentController@store');
        Route::get('school/students/{id}', 'SchoolStudentController@show');
        Route::delete('school/students/{id}', 'SchoolStudentController@destroy');
        Route::post('school/enrollments/promote', 'SchoolStudentController@promote');
        Route::get('school/enrollments', 'SchoolStudentController@enrollments');
        Route::post('school/enrollments', 'SchoolStudentController@storeEnrollment');
        Route::put('school/enrollments/{id}', 'SchoolStudentController@updateEnrollment');
        Route::delete('school/enrollments/{id}', 'SchoolStudentController@destroyEnrollment');

        Route::get('school/attendance/register', 'SchoolAttendanceController@register');
        Route::post('school/attendance/register', 'SchoolAttendanceController@save');
        Route::get('school/attendance/summary', 'SchoolAttendanceController@summary');

        Route::get('school/timetable', 'SchoolTimetableController@index');
        Route::post('school/timetable', 'SchoolTimetableController@store');
        Route::put('school/timetable/{id}', 'SchoolTimetableController@update');
        Route::delete('school/timetable/{id}', 'SchoolTimetableController@destroy');

        Route::get('school/exams/{examId}/report-card', 'SchoolExamController@reportCard');
        Route::post('school/exams/{examId}/papers/generate', 'SchoolExamController@generatePapers');
        Route::get('school/exams/{examId}/papers/{paperId}/sheet', 'SchoolExamController@sheet');
        Route::post('school/exams/{examId}/papers/{paperId}/results', 'SchoolExamController@saveResults');
        Route::get('school/exams/{examId}/papers', 'SchoolExamController@papers');
        Route::post('school/exams/{examId}/papers', 'SchoolExamController@storePaper');
        Route::put('school/exams/{examId}/papers/{paperId}', 'SchoolExamController@updatePaper');
        Route::delete('school/exams/{examId}/papers/{paperId}', 'SchoolExamController@destroyPaper');
        Route::get('school/exams', 'SchoolExamController@index');
        Route::post('school/exams', 'SchoolExamController@store');
        Route::put('school/exams/{id}', 'SchoolExamController@update');
        Route::delete('school/exams/{id}', 'SchoolExamController@destroy');

        Route::get('school/fee-structures', 'SchoolFeeController@structures');
        Route::post('school/fee-structures', 'SchoolFeeController@storeStructure');
        Route::put('school/fee-structures/{id}', 'SchoolFeeController@updateStructure');
        Route::delete('school/fee-structures/{id}', 'SchoolFeeController@destroyStructure');
        Route::get('school/fee-payments', 'SchoolFeeController@payments');
        Route::post('school/fee-invoices/generate', 'SchoolFeeController@generateInvoices');
        Route::post('school/fee-invoices/{id}/payments', 'SchoolFeeController@storePayment');
        Route::delete('school/fee-invoices/{invoiceId}/payments/{paymentId}', 'SchoolFeeController@destroyPayment');
        Route::get('school/fee-invoices', 'SchoolFeeController@invoices');
        Route::post('school/fee-invoices', 'SchoolFeeController@storeInvoice');
        Route::get('school/fee-invoices/{id}', 'SchoolFeeController@showInvoice');
        Route::put('school/fee-invoices/{id}', 'SchoolFeeController@updateInvoice');
        Route::delete('school/fee-invoices/{id}', 'SchoolFeeController@destroyInvoice');
    });

    // Restaurant Management — every endpoint under one plan feature.
    Route::middleware('tenant.feature:restaurant')->group(function () {
        Route::get('restaurant/settings', 'RestaurantSettingsController@show');
        Route::put('restaurant/settings', 'RestaurantSettingsController@update');
        // Floor setup (areas + tables)
        Route::get('restaurant/setup/floor', 'RestaurantFloorSetupController@index');
        Route::post('restaurant/setup/areas/reorder', 'RestaurantFloorSetupController@reorderAreas');
        Route::post('restaurant/setup/areas', 'RestaurantFloorSetupController@storeArea');
        Route::put('restaurant/setup/areas/{id}', 'RestaurantFloorSetupController@updateArea')->whereNumber('id');
        Route::delete('restaurant/setup/areas/{id}', 'RestaurantFloorSetupController@destroyArea')->whereNumber('id');
        Route::post('restaurant/setup/tables/bulk', 'RestaurantFloorSetupController@bulkTables');
        Route::post('restaurant/setup/tables/reorder', 'RestaurantFloorSetupController@reorderTables');
        Route::post('restaurant/setup/tables', 'RestaurantFloorSetupController@storeTable');
        Route::put('restaurant/setup/tables/{id}', 'RestaurantFloorSetupController@updateTable')->whereNumber('id');
        Route::delete('restaurant/setup/tables/{id}', 'RestaurantFloorSetupController@destroyTable')->whereNumber('id');
        Route::get('restaurant/setup/qr-codes', 'RestaurantFloorSetupController@qrCodes');
        Route::post('restaurant/setup/tables/{id}/qr/renew', 'RestaurantFloorSetupController@renewQr')->whereNumber('id');
        // Menu: dishes, add-on groups, kitchen stations, meal times
        Route::get('restaurant/menu/setup', 'RestaurantMenuController@setup');
        Route::get('restaurant/menu/dishes', 'RestaurantMenuController@dishes');
        Route::put('restaurant/menu/dishes/{productId}', 'RestaurantMenuController@saveDish')->whereNumber('productId');
        Route::post('restaurant/menu/dishes/{productId}/sold-out', 'RestaurantMenuController@soldOut')->whereNumber('productId');
        Route::post('restaurant/menu/stations/defaults', 'RestaurantMenuController@defaultStations');
        Route::post('restaurant/menu/stations/reorder', 'RestaurantMenuController@reorderStations');
        Route::post('restaurant/menu/stations', 'RestaurantMenuController@storeStation');
        Route::put('restaurant/menu/stations/{id}', 'RestaurantMenuController@updateStation')->whereNumber('id');
        Route::delete('restaurant/menu/stations/{id}', 'RestaurantMenuController@destroyStation')->whereNumber('id');
        Route::post('restaurant/menu/addon-groups', 'RestaurantMenuController@storeGroup');
        Route::put('restaurant/menu/addon-groups/{id}', 'RestaurantMenuController@updateGroup')->whereNumber('id');
        Route::delete('restaurant/menu/addon-groups/{id}', 'RestaurantMenuController@destroyGroup')->whereNumber('id');
        Route::post('restaurant/menu/options/{id}/availability', 'RestaurantMenuController@optionAvailability')->whereNumber('id');
        Route::put('restaurant/menu/times', 'RestaurantMenuController@saveTimes');
        // Waiter order screen
        Route::get('restaurant/pos/menu', 'RestaurantOrderController@menu');
        Route::get('restaurant/orders', 'RestaurantOrderController@index');
        Route::post('restaurant/orders', 'RestaurantOrderController@store');
        Route::get('restaurant/orders/{id}', 'RestaurantOrderController@show')->whereNumber('id');
        Route::put('restaurant/orders/{id}', 'RestaurantOrderController@update')->whereNumber('id');
        Route::post('restaurant/orders/{id}/items', 'RestaurantOrderController@addItems')->whereNumber('id');
        Route::put('restaurant/orders/{id}/items/{itemId}', 'RestaurantOrderController@updateItem')->whereNumber('id')->whereNumber('itemId');
        Route::delete('restaurant/orders/{id}/items/{itemId}', 'RestaurantOrderController@removeItem')->whereNumber('id')->whereNumber('itemId');
        Route::post('restaurant/orders/{id}/items/{itemId}/void', 'RestaurantOrderController@voidItem')->whereNumber('id')->whereNumber('itemId');
        Route::post('restaurant/orders/{id}/send', 'RestaurantOrderController@send')->whereNumber('id');
        Route::post('restaurant/orders/{id}/cancel', 'RestaurantOrderController@cancel')->whereNumber('id');
        Route::post('restaurant/tables/{tableId}/order', 'RestaurantOrderController@openTable')->whereNumber('tableId');
        Route::post('restaurant/orders/{id}/served', 'RestaurantOrderController@served')->whereNumber('id');
        // Kitchen display
        Route::get('restaurant/kitchen', 'RestaurantKitchenController@index');
        Route::post('restaurant/kitchen/tickets/{id}/{action}', 'RestaurantKitchenController@ticketAction')
            ->whereNumber('id')->whereIn('action', ['start', 'bump', 'served', 'recall']);
        Route::post('restaurant/kitchen/items/{id}/toggle', 'RestaurantKitchenController@toggleItem')->whereNumber('id');
        // Bill & payment (each payment is a POS sale)
        // Guide (set-up checklist)
        Route::get('restaurant/guide', 'RestaurantGuideController@show');
        // Dashboard & reports
        Route::get('restaurant/dashboard', 'RestaurantReportController@dashboard');
        Route::get('restaurant/reports/{type}', 'RestaurantReportController@report')
            ->whereIn('type', ['sales', 'dishes', 'voids', 'staff', 'kitchen', 'reservations']);
        Route::get('restaurant/billing/meta', 'RestaurantBillingController@meta');
        Route::get('restaurant/billing/clients', 'RestaurantBillingController@clients');
        Route::get('restaurant/orders/{id}/bill', 'RestaurantBillingController@show')->whereNumber('id');
        Route::post('restaurant/orders/{id}/bill/quote', 'RestaurantBillingController@quote')->whereNumber('id');
        Route::post('restaurant/orders/{id}/bill/pay', 'RestaurantBillingController@pay')->whereNumber('id');
        // Reservations & waitlist
        Route::get('restaurant/reservations', 'RestaurantReservationController@index');
        Route::get('restaurant/reservations/search', 'RestaurantReservationController@search');
        Route::get('restaurant/reservations/availability', 'RestaurantReservationController@availability');
        Route::post('restaurant/reservations', 'RestaurantReservationController@store');
        Route::put('restaurant/reservations/{id}', 'RestaurantReservationController@update')->whereNumber('id');
        Route::post('restaurant/reservations/{id}/{action}', 'RestaurantReservationController@action')
            ->whereNumber('id')->whereIn('action', ['confirm', 'cancel', 'no_show', 'restore', 'seat']);
        Route::post('restaurant/waitlist', 'RestaurantReservationController@waitStore');
        Route::put('restaurant/waitlist/{id}', 'RestaurantReservationController@waitUpdate')->whereNumber('id');
        Route::post('restaurant/waitlist/{id}/{action}', 'RestaurantReservationController@waitAction')
            ->whereNumber('id')->whereIn('action', ['notify', 'seat', 'left', 'back']);
        // Floor plan (live tables)
        Route::get('restaurant/floor', 'RestaurantFloorController@index');
        Route::post('restaurant/floor/tables/{id}/{action}', 'RestaurantFloorController@action')
            ->whereNumber('id')->whereIn('action', \App\Http\Controllers\RestaurantFloorController::ACTIONS);
    });

    // Rental Management — every endpoint under one plan feature.
    Route::middleware('tenant.feature:rental')->group(function () {
        // Literal segments first — the {id} routes would otherwise swallow them.
        Route::get('rental/dashboard', 'RentalDashboardController@dashboard');
        Route::get('rental/settings', 'RentalDashboardController@settings');
        Route::put('rental/settings', 'RentalDashboardController@updateSettings');
        // Business types registry (item form fields, category / filter lists).
        Route::get('rental/types', 'RentalDashboardController@types');

        Route::post('rental/categories/delete/by_selection', 'RentalCategoryController@deleteBySelection');
        Route::get('rental/categories', 'RentalCategoryController@index');
        Route::post('rental/categories', 'RentalCategoryController@store');
        Route::post('rental/categories/suggested', 'RentalCategoryController@suggested');
        // Multipart (photo) saves POST here; PUT stays for JSON saves.
        Route::post('rental/categories/{id}/update', 'RentalCategoryController@update');
        Route::put('rental/categories/{id}', 'RentalCategoryController@update');
        Route::delete('rental/categories/{id}', 'RentalCategoryController@destroy');

        // Photos arrive as multipart, which PHP cannot parse from a PUT body — the
        // edit form therefore POSTs to /update.
        Route::get('rental/contracts/{id}/handover-meta', 'RentalHandoverController@meta');
        Route::get('rental/contracts/{id}/handovers', 'RentalHandoverController@contractHandovers');
        Route::post('rental/contracts/{id}/checkout', 'RentalHandoverController@checkout');
        Route::post('rental/contracts/{id}/checkin', 'RentalHandoverController@checkin');
        Route::post('rental/contracts/{id}/deposits', 'RentalHandoverController@deposit');
        Route::delete('rental/deposits/{id}', 'RentalHandoverController@destroyDeposit');

        Route::get('rental/maintenance/meta', 'RentalMaintenanceController@meta');
        Route::get('rental/maintenance/schedules', 'RentalMaintenanceController@schedules');
        Route::post('rental/maintenance/schedules', 'RentalMaintenanceController@storeSchedule');
        Route::put('rental/maintenance/schedules/{id}', 'RentalMaintenanceController@updateSchedule');
        Route::delete('rental/maintenance/schedules/{id}', 'RentalMaintenanceController@destroySchedule');
        Route::post('rental/maintenance/{id}/start', 'RentalMaintenanceController@start');
        Route::post('rental/maintenance/{id}/complete', 'RentalMaintenanceController@complete');
        Route::post('rental/maintenance/{id}/cancel', 'RentalMaintenanceController@cancel');
        Route::post('rental/maintenance/{id}/update', 'RentalMaintenanceController@update');
        Route::get('rental/maintenance', 'RentalMaintenanceController@index');
        Route::post('rental/maintenance', 'RentalMaintenanceController@store');
        Route::get('rental/maintenance/{id}', 'RentalMaintenanceController@show');
        Route::delete('rental/maintenance/{id}', 'RentalMaintenanceController@destroy');

        Route::get('rental/expenses/meta', 'RentalExpenseController@meta');
        Route::get('rental/expenses', 'RentalExpenseController@index');
        Route::post('rental/expenses', 'RentalExpenseController@store');
        Route::post('rental/expenses/{id}/update', 'RentalExpenseController@update');
        Route::delete('rental/expenses/{id}', 'RentalExpenseController@destroy');
        Route::get('rental/items/{id}/financials', 'RentalExpenseController@itemFinancials');

        Route::get('rental/reports/occupancy', 'RentalReportController@occupancy');
        Route::get('rental/reports/profit', 'RentalReportController@profit');
        Route::get('rental/reports/aging', 'RentalReportController@aging');
        Route::get('rental/reports/deposits', 'RentalReportController@deposits');
        Route::get('rental/reports/expenses', 'RentalReportController@expenses');
        Route::get('rental/reports/expiring', 'RentalReportController@expiring');
        Route::get('rental/reports/reminders', 'RentalReportController@reminders');
        Route::post('rental/reminders/send', 'RentalReportController@sendReminder');
        Route::get('rental/customers/{id}/statement', 'RentalReportController@statement');
        Route::get('rental/customers/{id}/statement/pdf', 'RentalReportController@statementPdf');

        Route::get('rental/invoices', 'RentalBillingController@invoices');
        Route::post('rental/invoices/charge', 'RentalBillingController@charge');
        Route::get('rental/invoices/{id}', 'RentalBillingController@invoice');
        Route::post('rental/invoices/{id}/void', 'RentalBillingController@void');
        Route::post('rental/invoices/{id}/waive-late-fee', 'RentalBillingController@waiveLateFee');
        Route::get('rental/invoices/{id}/pdf', 'RentalBillingController@invoicePdf');
        Route::get('rental/payments/meta', 'RentalBillingController@paymentMeta');
        Route::get('rental/payments', 'RentalBillingController@payments');
        Route::post('rental/payments', 'RentalBillingController@storePayment');
        Route::delete('rental/payments/{id}', 'RentalBillingController@destroyPayment');
        Route::get('rental/contracts/{id}/billing', 'RentalBillingController@contractBilling');

        Route::get('rental/availability/timeline', 'RentalAvailabilityController@timeline');
        Route::get('rental/availability/calendar', 'RentalAvailabilityController@calendar');

        Route::post('rental/reservations/{id}/cancel', 'RentalReservationController@cancel');
        Route::post('rental/reservations/{id}/extend-hold', 'RentalReservationController@extendHold');
        Route::post('rental/reservations/{id}/convert', 'RentalReservationController@convert');
        Route::post('rental/reservations/{id}/confirm', 'RentalReservationController@confirm');
        Route::get('rental/reservations', 'RentalReservationController@index');
        Route::post('rental/reservations', 'RentalReservationController@store');
        Route::get('rental/reservations/{id}', 'RentalReservationController@show');
        Route::put('rental/reservations/{id}', 'RentalReservationController@update');
        Route::delete('rental/reservations/{id}', 'RentalReservationController@destroy');

        Route::get('rental/contracts/meta', 'RentalContractController@meta');
        Route::get('rental/contracts/customer-search', 'RentalContractController@customerSearch');
        Route::post('rental/contracts/calculate', 'RentalContractController@calculate');
        Route::post('rental/contracts/{id}/activate', 'RentalContractController@activate');
        Route::post('rental/contracts/{id}/extend', 'RentalContractController@extend');
        Route::post('rental/contracts/{id}/renew', 'RentalContractController@renew');
        Route::post('rental/contracts/{id}/terminate', 'RentalContractController@terminate');
        Route::post('rental/contracts/{id}/end', 'RentalContractController@end');
        Route::post('rental/contracts/{id}/cancel', 'RentalContractController@cancel');
        Route::post('rental/contracts/{id}/sign', 'RentalContractController@sign');
        Route::delete('rental/contracts/{id}/sign', 'RentalContractController@unsign');
        Route::post('rental/contracts/{id}/attachments', 'RentalContractController@storeAttachment');
        Route::delete('rental/contracts/{id}/attachments/{attachmentId}', 'RentalContractController@destroyAttachment');
        Route::get('rental/contracts/{id}/pdf', 'RentalContractController@pdf');
        Route::get('rental/contracts', 'RentalContractController@index');
        Route::post('rental/contracts', 'RentalContractController@store');
        Route::get('rental/contracts/{id}', 'RentalContractController@show');
        Route::put('rental/contracts/{id}', 'RentalContractController@update');
        Route::delete('rental/contracts/{id}', 'RentalContractController@destroy');

        Route::get('rental/agreement-templates', 'RentalAgreementTemplateController@index');
        Route::post('rental/agreement-templates', 'RentalAgreementTemplateController@store');
        Route::put('rental/agreement-templates/{id}', 'RentalAgreementTemplateController@update');
        Route::delete('rental/agreement-templates/{id}', 'RentalAgreementTemplateController@destroy');

        Route::get('rental/customers/client-search', 'RentalCustomerController@clientSearch');
        Route::post('rental/customers/delete/by_selection', 'RentalCustomerController@deleteBySelection');
        Route::post('rental/customers/{id}/update', 'RentalCustomerController@update');
        Route::post('rental/customers/{id}/blacklist', 'RentalCustomerController@blacklist');
        Route::post('rental/customers/{id}/documents', 'RentalCustomerController@storeDocument');
        Route::delete('rental/customers/{id}/documents/{documentId}', 'RentalCustomerController@destroyDocument');
        Route::get('rental/customers', 'RentalCustomerController@index');
        Route::post('rental/customers', 'RentalCustomerController@store');
        Route::get('rental/customers/{id}', 'RentalCustomerController@show');
        Route::delete('rental/customers/{id}', 'RentalCustomerController@destroy');

        Route::get('rental/items/meta', 'RentalItemController@meta');
        Route::post('rental/items/delete/by_selection', 'RentalItemController@deleteBySelection');
        Route::get('rental/items/{id}/edit', 'RentalItemController@edit');
        Route::post('rental/items/{id}/update', 'RentalItemController@update');
        Route::get('rental/items', 'RentalItemController@index');
        Route::post('rental/items', 'RentalItemController@store');
        Route::get('rental/items/{id}', 'RentalItemController@show');
        Route::delete('rental/items/{id}', 'RentalItemController@destroy');
    });

    // CRM: Leads & Sales Pipeline — every endpoint under one plan feature.
    Route::middleware('tenant.feature:crm')->group(function () {
        // Literal segments are declared before every {id} route.
        Route::get('crm/dashboard', 'CrmDashboardController@index');
        Route::get('crm/settings', 'CrmSettingsController@show');
        Route::put('crm/settings', 'CrmSettingsController@update');
        Route::post('crm/settings/{type}/reorder', 'CrmSettingsController@reorder')->where('type', 'sources|statuses|lost-reasons|tags');
        Route::post('crm/settings/{type}', 'CrmSettingsController@storeLookup')->where('type', 'sources|statuses|lost-reasons|tags');
        Route::put('crm/settings/{type}/{id}', 'CrmSettingsController@updateLookup')->where('type', 'sources|statuses|lost-reasons|tags')->whereNumber('id');
        Route::delete('crm/settings/{type}/{id}', 'CrmSettingsController@destroyLookup')->where('type', 'sources|statuses|lost-reasons|tags')->whereNumber('id');
        Route::get('crm/pipelines', 'CrmPipelineController@index');
        Route::post('crm/pipelines', 'CrmPipelineController@store');
        Route::put('crm/pipelines/{id}', 'CrmPipelineController@update')->whereNumber('id');
        Route::delete('crm/pipelines/{id}', 'CrmPipelineController@destroy')->whereNumber('id');
        Route::post('crm/pipelines/{id}/stages', 'CrmPipelineController@storeStage')->whereNumber('id');
        Route::post('crm/pipelines/{id}/stages/reorder', 'CrmPipelineController@reorderStages')->whereNumber('id');
        Route::put('crm/pipeline-stages/{id}', 'CrmPipelineController@updateStage')->whereNumber('id');
        Route::delete('crm/pipeline-stages/{id}', 'CrmPipelineController@destroyStage')->whereNumber('id');
        Route::get('crm/reports/meta', 'CrmReportController@meta');
        Route::get('crm/reports/{section}', 'CrmReportController@show')->where('section', 'overview|winloss|forecast|reps|activities|sources');
        Route::get('crm/targets', 'CrmReportController@targets');
        Route::put('crm/targets', 'CrmReportController@saveTargets');
        Route::post('crm/targets/copy', 'CrmReportController@copyTargets');
        Route::get('crm/automation', 'CrmAutomationController@show');
        Route::put('crm/automation', 'CrmSettingsController@updateAutomation');
        Route::post('crm/assignment-rules/reorder', 'CrmAutomationController@reorderRules');
        Route::post('crm/assignment-rules', 'CrmAutomationController@storeRule');
        Route::put('crm/assignment-rules/{id}', 'CrmAutomationController@updateRule');
        Route::delete('crm/assignment-rules/{id}', 'CrmAutomationController@destroyRule');
        Route::post('crm/scoring-rules/recalculate', 'CrmAutomationController@recalculate');
        Route::post('crm/scoring-rules', 'CrmAutomationController@storeScoring');
        Route::put('crm/scoring-rules/{id}', 'CrmAutomationController@updateScoring');
        Route::delete('crm/scoring-rules/{id}', 'CrmAutomationController@destroyScoring');
        Route::get('crm/web-forms', 'CrmWebFormController@index');
        Route::post('crm/web-forms', 'CrmWebFormController@store');
        Route::put('crm/web-forms/{id}', 'CrmWebFormController@update');
        Route::delete('crm/web-forms/{id}', 'CrmWebFormController@destroy');
        Route::post('crm/web-forms/{id}/regenerate', 'CrmWebFormController@regenerate');
        Route::get('crm/messages/meta', 'CrmMessageController@meta');
        Route::get('crm/messages/inbox', 'CrmMessageController@inbox');
        Route::post('crm/messages/read', 'CrmMessageController@markRead');
        Route::post('crm/messages/render', 'CrmMessageController@render');
        Route::get('crm/messages', 'CrmMessageController@thread');
        Route::post('crm/messages', 'CrmMessageController@send');
        Route::get('crm/customers', 'CrmCustomerController@index');
        Route::get('crm/customers/{id}', 'CrmCustomerController@show')->whereNumber('id');
        Route::post('crm/customers/{id}/contacts', 'CrmCustomerController@storeContact')->whereNumber('id');
        Route::put('crm/contacts/{id}', 'CrmCustomerController@updateContact')->whereNumber('id');
        Route::delete('crm/contacts/{id}', 'CrmCustomerController@destroyContact')->whereNumber('id');
        Route::get('crm/leads/{id}/convert', 'CrmConversionController@convertMeta')->whereNumber('id');
        Route::post('crm/leads/{id}/convert', 'CrmConversionController@convert')->whereNumber('id');
        Route::get('crm/deals/{id}/quotation', 'CrmConversionController@quotationMeta')->whereNumber('id');
        Route::post('crm/deals/{id}/quotation', 'CrmConversionController@quotation')->whereNumber('id');
        Route::get('crm/activities/meta', 'CrmActivityController@meta');
        Route::get('crm/activities/calendar', 'CrmActivityController@calendar');
        Route::get('crm/activities/my-day', 'CrmActivityController@myDay');
        Route::get('crm/activities', 'CrmActivityController@index');
        Route::post('crm/activities', 'CrmActivityController@store');
        Route::get('crm/activities/{id}', 'CrmActivityController@show')->whereNumber('id');
        Route::put('crm/activities/{id}', 'CrmActivityController@update')->whereNumber('id');
        Route::delete('crm/activities/{id}', 'CrmActivityController@destroy')->whereNumber('id');
        Route::post('crm/activities/{id}/complete', 'CrmActivityController@complete')->whereNumber('id');
        Route::post('crm/activities/{id}/reopen', 'CrmActivityController@reopen')->whereNumber('id');
        Route::get('crm/deals/meta', 'CrmDealController@meta');
        Route::get('crm/deals/board', 'CrmDealController@board');
        Route::get('crm/deals/products', 'CrmDealController@products');
        Route::get('crm/deals/clients', 'CrmDealController@clients');
        Route::post('crm/deals/delete/by_selection', 'CrmDealController@deleteBySelection');
        Route::get('crm/deals', 'CrmDealController@index');
        Route::post('crm/deals', 'CrmDealController@store');
        Route::get('crm/deals/{id}', 'CrmDealController@show')->whereNumber('id');
        Route::put('crm/deals/{id}', 'CrmDealController@update')->whereNumber('id');
        Route::delete('crm/deals/{id}', 'CrmDealController@destroy')->whereNumber('id');
        Route::post('crm/deals/{id}/move', 'CrmDealController@move')->whereNumber('id');
        Route::post('crm/deals/{id}/won', 'CrmDealController@won')->whereNumber('id');
        Route::post('crm/deals/{id}/lost', 'CrmDealController@lost')->whereNumber('id');
        Route::post('crm/deals/{id}/reopen', 'CrmDealController@reopen')->whereNumber('id');
        Route::post('crm/deals/{id}/portal', 'CrmDealController@portal')->whereNumber('id');
        Route::get('crm/leads/meta', 'CrmLeadController@meta');
        Route::get('crm/leads/duplicates', 'CrmLeadController@duplicates');
        Route::get('crm/leads/export', 'CrmLeadController@export');
        Route::get('crm/leads/import/template', 'CrmLeadImportController@template');
        Route::post('crm/leads/import/preview', 'CrmLeadImportController@preview');
        Route::post('crm/leads/import', 'CrmLeadImportController@import');
        Route::post('crm/leads/bulk', 'CrmLeadController@bulk');
        Route::post('crm/leads/delete/by_selection', 'CrmLeadController@deleteBySelection');
        Route::get('crm/leads', 'CrmLeadController@index');
        Route::post('crm/leads', 'CrmLeadController@store');
        Route::get('crm/leads/{id}', 'CrmLeadController@show')->whereNumber('id');
        Route::put('crm/leads/{id}', 'CrmLeadController@update')->whereNumber('id');
        Route::delete('crm/leads/{id}', 'CrmLeadController@destroy')->whereNumber('id');
        Route::post('crm/leads/{id}/status', 'CrmLeadController@changeStatus')->whereNumber('id');
        Route::post('crm/leads/{id}/lost', 'CrmLeadController@markLost')->whereNumber('id');
        Route::post('crm/leads/{id}/reopen', 'CrmLeadController@reopen')->whereNumber('id');
        Route::post('crm/leads/{id}/marketing', 'CrmLeadController@marketing')->whereNumber('id');
    });

    // -------------------------------  Sales --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('sales', 'SalesController');
    Route::get('get_import_sales', 'SalesController@get_import_sales');
    Route::post('store_import_sales', 'SalesController@store_import_sales');
    Route::post('preview_import_sales', 'SalesController@preview_import_sales');
    Route::get('batches_for_sale/{product_id}/{warehouse_id}/{variant_id?}', 'SalesController@batches_for_sale');
    Route::get('convert_to_sale_data/{id}', 'SalesController@Elemens_Change_To_Sale');
    Route::get('get_payments_by_sale/{id}', 'SalesController@Payments_Sale');
    Route::post('sales_send_email', 'SalesController@Send_Email');
    Route::post('sales_send_sms', 'SalesController@Send_SMS');
    Route::post('sales_delete_by_selection', 'SalesController@delete_by_selection');
    Route::get('get_Products_by_sale/{id}', 'SalesController@get_Products_by_sale');
    Route::post('sales/{id}/serials', 'SalesController@assignSerials')->whereNumber('id');

    // ------------------------------- Sales Documents --------------------------\\
    Route::get('sales/{id}/documents', 'SalesController@getDocuments');
    Route::post('sales/{id}/documents', 'SalesController@uploadDocuments');
    Route::get('sales/documents/{id}/download', 'SalesController@downloadDocument');
    Route::delete('sales/documents/{id}', 'SalesController@deleteDocument');
    Route::post('sales_send_whatsapp', 'SalesController@sales_send_whatsapp');
    Route::get('get_today_sales', 'SalesController@get_today_sales');

    // -------------------------------  Shipments --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('shipments', 'ShipmentController');

    // ------------------------------- Payments  Sales --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('payment_sale', 'PaymentSalesController');
    Route::get('payment_sale_get_number', 'PaymentSalesController@getNumberOrder');
    Route::post('payment_sale_send_email', 'PaymentSalesController@SendEmail');
    Route::post('payment_sale_send_sms', 'PaymentSalesController@Send_SMS');

    // ------------------------------- Commission Program (feature-gated) --------------------------\\
    Route::middleware('tenant.feature:commissions')->group(function () {
    Route::resource('commission_programs', 'Commission\CommissionProgramController');
    Route::resource('sales_agents', 'Commission\SalesAgentController');
    Route::get('sales_agents_list_for_select', 'Commission\SalesAgentController@listForSelect');
    Route::resource('commission_rules', 'Commission\CommissionRuleController');
    Route::get('commission_receipts', 'Commission\CommissionReceiptController@index');
    Route::post('commission_receipts', 'Commission\CommissionReceiptController@store');
    Route::get('commission_receipts/new_ref', 'Commission\CommissionReceiptController@getNewRef');
    Route::get('commission_receipts/accounts', 'Commission\CommissionReceiptController@accounts');
    Route::get('commission_receipts/{id}', 'Commission\CommissionReceiptController@show');
    Route::get('commission_report', 'Commission\CommissionReportController@index');
    Route::get('commission_report/summary', 'Commission\CommissionReportController@summary');
    Route::get('commission_report/by_agent', 'Commission\CommissionReportController@byAgent');
    Route::post('commissions/calculate_for_sale/{saleId}', 'Commission\CommissionController@calculateForSale');
    Route::post('commissions/approve', 'Commission\CommissionController@approve');
    Route::post('commissions/cancel', 'Commission\CommissionController@cancel');
    Route::post('commissions/recalculate', 'Commission\CommissionController@recalculate');
    });

    // ------------------------------- Expenses --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('expenses', 'ExpensesController');
    Route::post('expenses_delete_by_selection', 'ExpensesController@delete_by_selection');
    // ------------------------------- Expense Documents --------------------------\\
    Route::get('expenses/{id}/documents', 'ExpensesController@getDocuments');
    Route::post('expenses/{id}/documents', 'ExpensesController@uploadDocuments');
    Route::get('expenses/documents/{id}/download', 'ExpensesController@downloadDocument');
    Route::delete('expenses/documents/{id}', 'ExpensesController@deleteDocument');

    // ------------------------------- Expenses Category--------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('expenses_category', 'CategoryExpenseController');
    Route::post('expenses_category_delete_by_selection', 'CategoryExpenseController@delete_by_selection');

    // ------------------------------- Accounts --------------------------\\
    // ------------------------------------------------------------------\\

    // Declared before the resource so accounts/{id}/statement is not swallowed by accounts/{account}.
    Route::get('accounts/{id}/statement', 'AccountController@statement');
    Route::resource('accounts', 'AccountController');
    Route::post('accounts_delete_by_selection', 'AccountController@delete_by_selection');

    // ------------------------------- TransferMoneyController --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('transfer_money', 'TransferMoneyController');

    // ------------------------------- Deposits --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('deposits', 'DepositsController');
    Route::post('deposits_delete_by_selection', 'DepositsController@delete_by_selection');

    // ------------------------------- deposits Category--------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('deposits_category', 'CategoryDepositController');
    Route::post('deposits_category_delete_by_selection', 'CategoryDepositController@delete_by_selection');

    // ------------------------------- Quotations (feature-gated) --------------------------\\
    // ------------------------------------------------------------------\\
    Route::middleware('tenant.feature:quotations')->group(function () {
    Route::resource('quotations', 'QuotationsController');
    Route::post('quotations_send_email', 'QuotationsController@SendEmail');
    Route::post('quotations_send_sms', 'QuotationsController@Send_SMS');
    Route::post('quotations_delete_by_selection', 'QuotationsController@delete_by_selection');
    Route::post('quotation_send_whatsapp', 'QuotationsController@quotation_send_whatsapp');
    Route::get('batches_for_quotation/{product_id}/{warehouse_id}/{variant_id?}', 'QuotationsController@batches_for_quotation');
    });

    // ------------------------------- Sales Return --------------------------\\
    // ------------------------------------------------------------------\\

    // Declared before the resource so this single-segment GET is not captured
    // by the resource's show route (returns/sale/{sale}).
    Route::get('returns/sale/pos_search_sales', 'SalesReturnController@pos_search_sales');
    Route::resource('returns/sale', 'SalesReturnController');
    Route::post('returns/sale/send/email', 'SalesReturnController@Send_Email');
    Route::post('returns/sale/send/sms', 'SalesReturnController@Send_SMS');
    Route::get('returns/sale/payment/{id}', 'SalesReturnController@Payment_Returns');
    Route::post('returns/sale/delete/by_selection', 'SalesReturnController@delete_by_selection');
    Route::get('returns/sale/create_sell_return/{id}', 'SalesReturnController@create_sell_return');
    Route::get('returns/sale/edit_sell_return/{id}/{sale_id}', 'SalesReturnController@edit_sell_return');

    // ------------------------------- Purchases Return --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('returns/purchase', 'PurchasesReturnController');
    Route::post('returns/purchase/send/email', 'PurchasesReturnController@Send_Email');
    Route::post('returns/purchase/send/sms', 'PurchasesReturnController@Send_SMS');
    Route::get('returns/purchase/payment/{id}', 'PurchasesReturnController@Payment_Returns');
    Route::post('returns/purchase/delete/by_selection', 'PurchasesReturnController@delete_by_selection');
    Route::get('returns/purchase/create_purchase_return/{id}', 'PurchasesReturnController@create_purchase_return');
    Route::get('returns/purchase/edit_purchase_return/{id}/{purchase_id}', 'PurchasesReturnController@edit_purchase_return');

    // ------------------------------- Payment Sale Returns --------------------------\\
    // --------------------------------------------------------------------------------\\

    Route::resource('payment/returns_sale', 'PaymentSaleReturnsController');
    Route::get('payment/returns_sale/Number/order', 'PaymentSaleReturnsController@getNumberOrder');
    Route::post('payment/returns_sale/send/email', 'PaymentSaleReturnsController@SendEmail');
    Route::post('payment/returns_sale/send/sms', 'PaymentSaleReturnsController@Send_SMS');

    // ------------------------------- Payments Purchase Returns --------------------------\\
    // ---------------------------------------------------------------------------------------\\

    Route::resource('payment/returns_purchase', 'PaymentPurchaseReturnsController');
    Route::get('payment/returns_purchase/Number/Order', 'PaymentPurchaseReturnsController@getNumberOrder');
    Route::post('payment/returns_purchase/send/email', 'PaymentPurchaseReturnsController@SendEmail');
    Route::post('payment/returns_purchase/send/sms', 'PaymentPurchaseReturnsController@Send_SMS');

    // ------------------------------- Adjustments --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('adjustments', 'AdjustmentController');
    Route::get('adjustments/detail/{id}', 'AdjustmentController@Adjustment_detail');
    // Line-item exports (one row per product line, with code + category) for the document lists.
    Route::post('document-lines/{type}', 'DocumentLinesExportController@lines');
    Route::post('adjustments/delete/by_selection', 'AdjustmentController@delete_by_selection');
    Route::get('batches_for_adjustment/{product_id}/{warehouse_id}/{variant_id?}', 'AdjustmentController@batches_for_adjustment');

    // ------------------------------- Damages --------------------------\\
    // ------------------------------------------------------------------\\

    Route::resource('damages', 'DamageController');
    Route::get('damages/detail/{id}', 'DamageController@Damage_detail');
    Route::post('damages/delete/by_selection', 'DamageController@delete_by_selection');
    Route::get('batches_for_damage/{product_id}/{warehouse_id}/{variant_id?}', 'DamageController@batches_for_damage');

    // ------------------------------- Transfers (feature-gated) --------------------------\\
    Route::middleware('tenant.feature:transfers')->group(function () {
        Route::resource('transfers', 'TransferController');
        Route::post('transfers/delete/by_selection', 'TransferController@delete_by_selection');
        Route::post('transfers/{id}/approve', 'TransferController@approve');
        Route::post('transfers/{id}/reject', 'TransferController@reject');
        Route::get('batches_for_transfer/{product_id}/{warehouse_id}/{variant_id?}', 'TransferController@batches_for_transfer');
        Route::get('serials_for_transfer', 'TransferController@serials_for_transfer');
    });

    // ------------------------------- Users --------------------------\\
    // ------------------------------------------------------------------\\

    // get_user_auth is registered above, outside tenant.subscribed.
    Route::get('users_list_for_select', 'UserController@listForSelect');
    Route::resource('users', 'UserController')->middleware('tenant.limit:max_users');
    Route::put('users_switch_activated/{id}', 'UserController@IsActivated');
    Route::get('Get_user_profile', 'UserController@GetInfoProfile');
    Route::put('update_user_profile/{id}', 'UserController@updateProfile');

    // ------------------------------- Permission Groups user -----------\\
    // ------------------------------------------------------------------\\

    Route::get('roles/available_permissions', 'PermissionsController@availablePermissions');
    Route::resource('roles', 'PermissionsController');
    Route::resource('roles/check/create_page', 'PermissionsController@Check_Create_Page');
    Route::post('roles/delete/by_selection', 'PermissionsController@delete_by_selection');

    // ------------------------------- Settings ------------------------\\
    // ------------------------------------------------------------------\\
    Route::get('settings/dark-mode', 'SettingsController@getDarkMode');
    Route::put('settings/dark-mode', 'SettingsController@updateDarkMode');
    // Settings → Modules: business module toggles + feature switches
    Route::get('module_flags', 'SettingsController@getModuleFlags');
    Route::put('module_flags', 'SettingsController@updateModuleFlags');
    Route::delete('module_flags', 'SettingsController@resetModuleFlags');
    Route::get('get_mobile_settings', 'SettingsController@get_mobile_settings');
    Route::post('update_mobile_settings', 'SettingsController@update_mobile_settings');
    Route::get('feature_settings', 'SettingsController@getFeatureSettings');
    Route::put('feature_settings', 'SettingsController@updateFeatureSettings');
    Route::resource('settings', 'SettingsController');
    Route::get('get_Settings_data_api', 'SettingsController@get_Settings_data_api');
    Route::get('get_Settings_data', 'SettingsController@getSettings');
    Route::put('settings/dashboard-grid-layout', 'SettingsController@updateDashboardGridLayout');
    // Dedicated Dark Mode endpoints (independent from other settings APIs)
    Route::put('pos_settings/{id}', 'SettingsController@update_pos_settings');
    Route::get('get_pos_Settings', 'SettingsController@get_pos_Settings');
    Route::get('get_pos_Settings_api', 'SettingsController@get_pos_Settings_api');

    // ------------------------------- Security Settings (additive) ------------------------\\
    // Active login sessions (Passport tokens) + logout endpoints
    Route::get('security/sessions', 'SecuritySettingsController@sessions');
    Route::delete('security/sessions/{tokenId}', 'SecuritySettingsController@logoutSession');
    Route::post('security/sessions/logout-other', 'SecuritySettingsController@logoutAllOtherDevices');
    Route::get('security/login-activity-report', 'SecuritySettingsController@loginActivityReport');
    Route::get('security/login-security', 'SecuritySettingsController@getLoginSecurity');
    Route::put('security/login-security', 'SecuritySettingsController@updateLoginSecurity');
    Route::get('security/login-attempts', 'SecuritySettingsController@loginAttempts');
    Route::post('security/login-attempts/unlock', 'SecuritySettingsController@unlockLogin');
    Route::delete('security/login-attempts', 'SecuritySettingsController@clearLoginAttempts');

    // ------------------------------- appearance_settings ------------------------\\
    // ------------------------------------------------------------------\\

    Route::get('get_appearance_settings', 'SettingsController@get_appearance_settings');
    Route::put('update_appearance_settings/{id}', 'SettingsController@update_appearance_settings');
    Route::put('update_login_page_settings/{id}', 'SettingsController@update_login_page_settings');

    // ------------------------------- Invoice PDF templates ------------------------\\
    Route::get('pdf_templates/{type}', 'PdfTemplateController@show');
    Route::post('pdf_templates/{type}', 'PdfTemplateController@update');

    // ------------------------------- PWA Settings ------------------------\\

    Route::get('get_pwa_settings', 'SettingsController@get_pwa_settings');
    Route::post('update_pwa_settings', 'SettingsController@update_pwa_settings');

    // ------------------------------- Profile Password ------------------------\\
    Route::post('update_user_password', 'UserController@updatePassword');

    // ------------------------------- Mail Settings ------------------------\\

    Route::put('update_config_mail/{id}', 'MailSettingsController@update_config_mail');
    Route::get('get_config_mail', 'MailSettingsController@get_config_mail');
    Route::post('test_config_mail', 'MailSettingsController@test_config_mail');

    // ------------------------------- SMS Settings ------------------------\\

    Route::get('get_sms_config', 'Sms_SettingsController@get_sms_config');
    Route::get('get_sms_config_ws', 'Sms_SettingsController@get_sms_config_ws');
    Route::post('update_twilio_config', 'Sms_SettingsController@update_twilio_config');
    Route::post('update_nexmo_config', 'Sms_SettingsController@update_nexmo_config');
    Route::post('update_infobip_config', 'Sms_SettingsController@update_infobip_config');
    Route::post('update_termi_config', 'Sms_SettingsController@update_termi_config');
    Route::post('update_custom_config', 'Sms_SettingsController@update_custom_config');

    Route::put('update_Default_SMS', 'Sms_SettingsController@update_Default_SMS');

    // notifications_template
    Route::get('get_sms_template', 'Notifications_Template@get_sms_template');
    Route::put('update_sms_body', 'Notifications_Template@update_sms_body');

    Route::get('get_emails_template', 'Notifications_Template@get_emails_template');
    Route::put('update_custom_email', 'Notifications_Template@update_custom_email');

    // ------------------------------- Payment_gateway Settings ------------------------\\

    Route::post('payment_gateway', 'Payment_gateway_SettingsController@Update_payment_gateway');
    Route::get('get_payment_gateway', 'Payment_gateway_SettingsController@Get_payment_gateway');
    Route::get('get_payment_gateway_ws', 'Payment_gateway_SettingsController@get_payment_gateway_ws');

    // ------------------------------- Update Settings ------------------------\\
    // No tenant-facing update endpoints: an update replaces the shared
    // codebase and takes every tenant into maintenance mode. Platform updates
    // run from the super-admin panel only (routes/central.php: System Update,
    // and the migration-only /update page).

    // ------------------------------- Backup --------------------------\\
    // ------------------------------------------------------------------\\

    Route::get('get_backup', 'BackupController@Get_Backup');
    Route::get('generate_new_backup', 'BackupController@Generate_Backup');
    Route::delete('delete_backup/{name}', 'BackupController@Delete_Backup');
    Route::get('download_backup/{name}', 'BackupController@Download_Backup');

    // ------------------------------- System Health --------------------------\\
    Route::get('system_health', 'SystemHealthController@index');
    Route::get('system_health/pdf', 'SystemHealthController@pdf');

    // ------------------------------- Audit Log ------------------------------\\
    Route::get('audit_logs', 'AuditLogController@index')->middleware('tenant.feature:audit_log');

    // ------------------------------- Module Settings ------------------------\\

    Route::get('get_modules_info', 'ModuleSettingsController@get_modules_info');
    Route::post('update_status_module', 'ModuleSettingsController@update_status_module');
    Route::post('upload_module', 'ModuleSettingsController@upload_module');

    // ---------------- WooCommerce Sync (feature-gated) ----------------
    Route::middleware('tenant.feature:woocommerce')->group(function () {
        Route::get('woocommerce/settings', 'WooCommerceSyncController@getSettings');
        Route::post('woocommerce/settings', 'WooCommerceSyncController@saveSettings');
        Route::post('woocommerce/test-connection', 'WooCommerceSyncController@connectStore');
        Route::post('woocommerce/sync/products', 'WooCommerceSyncController@syncProducts');
        Route::post('woocommerce/sync/stock', 'WooCommerceSyncController@syncStock');
        Route::post('woocommerce/sync/products/stop', 'WooCommerceSyncController@stopProductsSync');
        Route::post('woocommerce/sync/stock/stop', 'WooCommerceSyncController@stopStockSync');
        Route::get('sync/status/{id}', 'SyncJobController@status');
        Route::post('sync/{id}/cancel', 'SyncJobController@cancel');
        Route::get('woo-sync/latest', 'SyncJobController@latest');
        Route::get('woo-sync/status/{id}', 'SyncJobController@status');
        Route::post('woo-sync/{id}/cancel', 'SyncJobController@cancel');
        Route::post('woocommerce/sync-stock', 'WooCommerceSyncController@syncStock');
        Route::get('woocommerce/sync/products/progress', 'WooCommerceSyncController@syncProductsProgress');
        Route::get('woocommerce/sync-products/progress', 'WooCommerceSyncController@syncProductsProgress');
        Route::get('woocommerce/sync/stock/progress', 'WooCommerceSyncController@syncStockProgress');
        Route::get('woocommerce/sync-stock/progress', 'WooCommerceSyncController@syncStockProgress');
        Route::get('woocommerce/stock-metrics', 'WooCommerceSyncController@stockMetrics');
        Route::post('woocommerce/sync/orders', 'WooCommerceSyncController@syncOrders');
        Route::get('woocommerce/orders', 'WooCommerceSyncController@getWooCommerceOrders');
        Route::get('woocommerce/orders/imported', 'WooCommerceSyncController@getImportedWooOrders');
        Route::get('woocommerce/orders/imported/stats', 'WooCommerceSyncController@getImportedWooOrdersStats');
        Route::post('woocommerce/sync/categories', 'WooCommerceSyncController@syncCategories');
        Route::post('woocommerce/sync/brands', 'WooCommerceSyncController@syncBrands');
        Route::get('woocommerce/brands/unsynced-count', 'WooCommerceSyncController@unsyncedBrandsCount');
        Route::post('woocommerce/reset-brands-sync', 'WooCommerceSyncController@resetBrandsSync');
        Route::post('woocommerce/categories/map', 'WooCommerceSyncController@mapCategories');
        Route::delete('woocommerce/logs', 'WooCommerceSyncController@clearLogs');
        Route::get('woocommerce/logs', 'WooCommerceSyncController@logs');
        Route::get('woocommerce/unsynced-count', 'WooCommerceSyncController@unsyncedCount');
        Route::get('woocommerce/products/pull-stats', 'WooCommerceSyncController@getProductsPullStats');
        Route::get('woocommerce/categories/pull-stats', 'WooCommerceSyncController@getCategoriesPullStats');
        Route::get('woocommerce/brands/pull-stats', 'WooCommerceSyncController@getBrandsPullStats');
        Route::get('woocommerce/categories/unsynced-count', 'WooCommerceSyncController@unsyncedCategoriesCount');
        Route::get('woocommerce/customers/unsynced-count', 'WooCommerceSyncController@unsyncedCustomersCount');
        Route::get('woocommerce/customers/stats', 'WooCommerceSyncController@getCustomersStats');
        Route::get('woocommerce/customers', 'WooCommerceSyncController@getWooCommerceCustomers');
        Route::get('woocommerce/customers/sync-issues', 'WooCommerceSyncController@getCustomerSyncIssues');
        Route::post('woocommerce/customers/sync-issues/{id}/resolve', 'WooCommerceSyncController@resolveCustomerSyncIssue');
        Route::post('woocommerce/customers/sync-issues/{id}/link', 'WooCommerceSyncController@manualLinkCustomerSyncIssue');
        Route::post('woocommerce/sync/customers', 'WooCommerceSyncController@syncCustomers');
        Route::post('woocommerce/reset-customers-sync', 'WooCommerceSyncController@resetCustomersSync');
        Route::post('woocommerce/reset-sync', 'WooCommerceSyncController@resetSync');
        Route::post('woocommerce/reset-products-sync', 'WooCommerceSyncController@resetProductsSync');
        Route::post('woocommerce/reset-categories-sync', 'WooCommerceSyncController@resetCategoriesSync');
        Route::post('woocommerce/products/fix-categories', 'WooCommerceSyncController@fixProductCategories');
        Route::post('woocommerce/products/auto-link', 'WooCommerceSyncController@autoLinkProductsBySku');
        Route::get('woocommerce/products/unmapped-report', 'WooCommerceSyncController@getUnmappedItemsReport');
        Route::post('woocommerce/reset-stock-sync', 'WooCommerceSyncController@resetStockSync');
    });

    // ---------------- Shopify Integration (feature-gated, multi-store) ----------------
    Route::middleware('tenant.feature:shopify')->group(function () {
        // Literal segments before the {id} routes.
        Route::get('shopify/meta', 'ShopifyStoreController@meta');
        Route::get('shopify/dashboard', 'ShopifySyncController@dashboard');

        Route::get('shopify/sync/runs', 'ShopifySyncController@runs');
        Route::get('shopify/sync/latest', 'ShopifySyncController@latest');
        Route::post('shopify/sync/start', 'ShopifySyncController@start');
        Route::get('shopify/sync/status/{id}', 'ShopifySyncController@status');
        Route::post('shopify/sync/cancel/{id}', 'ShopifySyncController@cancel');

        // Pair a product with a listing that already exists in a shop.
        Route::get('shopify/product-links', 'ShopifyProductLinkController@stores');
        Route::get('shopify/product-links/search', 'ShopifyProductLinkController@search');
        Route::post('shopify/product-links', 'ShopifyProductLinkController@link');
        Route::delete('shopify/product-links', 'ShopifyProductLinkController@unlink');

        Route::get('shopify/mappings', 'ShopifySyncController@mappings');
        Route::post('shopify/mappings', 'ShopifySyncController@link');
        Route::delete('shopify/mappings/{id}', 'ShopifySyncController@unlink');

        Route::get('shopify/logs', 'ShopifySyncController@logs');
        Route::delete('shopify/logs', 'ShopifySyncController@clearLogs');

        Route::get('shopify/webhook-events', 'ShopifyWebhookController@events');
        Route::post('shopify/webhook-events/{id}/replay', 'ShopifyWebhookController@replay');

        Route::get('shopify/stores/{id}/overview', 'ShopifyStoreController@overview');
        Route::get('shopify/stores/{id}/locations', 'ShopifyStoreController@locations');
        Route::post('shopify/stores/{id}/test', 'ShopifyStoreController@testConnection');
        Route::get('shopify/stores/{id}/webhooks', 'ShopifyStoreController@webhooks');
        Route::post('shopify/stores/{id}/webhooks', 'ShopifyStoreController@registerWebhooks');
        Route::delete('shopify/stores/{id}/webhooks/{webhookId}', 'ShopifyStoreController@deleteWebhook');
        Route::get('shopify/stores', 'ShopifyStoreController@index');
        Route::post('shopify/stores', 'ShopifyStoreController@store');
        Route::get('shopify/stores/{id}', 'ShopifyStoreController@show');
        Route::put('shopify/stores/{id}', 'ShopifyStoreController@update');
        Route::delete('shopify/stores/{id}', 'ShopifyStoreController@destroy');
    });

    // Customer Display: secure token generation
    Route::post('customer-display/generate', [CustomerDisplayController::class, 'generate']);
    // Cart push from the logged-in POS to the customer display
    Route::post('pos/customer-display/broadcast', [CustomerDisplayController::class, 'broadcastCart']);

    // ------------------------------- QuickBooks Integration (feature-gated) ------------------------\\
    // ------------------------------------------------------------------\\
    Route::middleware('tenant.feature:quickbooks')->group(function () {
    Route::get('quickbooks/status', 'QuickBooksController@status');
    Route::post('quickbooks/disconnect', 'QuickBooksController@disconnect');
    Route::get('quickbooks/settings', 'QuickBooksController@quickbookgetSettings');
    Route::post('quickbooks/settings', 'QuickBooksController@saveSettings');
    Route::get('quickbooks/audits', 'QuickBooksController@audits');
    Route::get('quickbooks/clients-stats', 'QuickBooksController@clientsStats');
    Route::get('quickbooks/clients-unsynced', 'QuickBooksController@clientsUnsynced');
    Route::post('quickbooks/sync-clients', 'QuickBooksController@syncClients');
    });

    // ------------------------------- ZATCA Phase 2 (Fatoora, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:zatca')->group(function () {
    Route::get('zatca/settings', 'ZatcaController@settings');
    Route::post('zatca/settings', 'ZatcaController@saveSettings');
    Route::post('zatca/onboard', 'ZatcaController@onboard');
    Route::post('zatca/csr/regenerate', 'ZatcaController@regenerateCsr');
    Route::get('zatca/documents', 'ZatcaController@documents');
    Route::get('zatca/documents/{id}/xml', 'ZatcaController@downloadXml');
    Route::post('zatca/sales/{id}/submit', 'ZatcaController@submitSale');
    Route::post('zatca/sale_returns/{id}/submit', 'ZatcaController@submitSaleReturn');
    Route::get('zatca/logs', 'ZatcaController@logs');
    });

    // ------------------------------- FBR Digital Invoicing (Pakistan, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:fbr')->group(function () {
    Route::get('fbr/settings', 'FbrController@settings');
    Route::post('fbr/settings', 'FbrController@saveSettings');
    Route::post('fbr/test-connection', 'FbrController@testConnection');
    Route::get('fbr/documents', 'FbrController@documents');
    Route::post('fbr/sales/{id}/submit', 'FbrController@submitSale');
    Route::get('fbr/logs', 'FbrController@logs');
    Route::get('fbr/reference/{type}', 'FbrController@reference');
    });

    // ------------------------------- AGT E-Invoicing (Angola, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:agt')->group(function () {
    Route::get('agt/settings', 'AgtController@settings');
    Route::post('agt/settings', 'AgtController@saveSettings');
    Route::post('agt/test-connection', 'AgtController@testConnection');
    Route::get('agt/series', 'AgtController@series');
    Route::post('agt/series', 'AgtController@storeSeries');
    Route::post('agt/series/request', 'AgtController@requestSeries');
    Route::put('agt/series/{id}', 'AgtController@updateSeries');
    Route::get('agt/documents', 'AgtController@documents');
    Route::get('agt/documents/{id}', 'AgtController@document');
    Route::post('agt/documents/{id}/retry', 'AgtController@retry');
    Route::post('agt/documents/{id}/check', 'AgtController@check');
    Route::post('agt/sales/{id}/submit', 'AgtController@submitSale');
    Route::post('agt/returns/{id}/submit', 'AgtController@submitReturn');
    Route::get('agt/logs', 'AgtController@logs');
    });

    // ------------------------------- DGI Facture Normalisée (DR Congo, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:dgi')->group(function () {
    Route::get('dgi/settings', 'DgiController@settings');
    Route::post('dgi/settings', 'DgiController@saveSettings');
    Route::get('dgi/documents', 'DgiController@documents');
    });

    // ------------------------------- REST API keys (Settings > API) ------------------------\\
    Route::middleware('tenant.feature:api')->group(function () {
        Route::get('api-keys', 'ApiKeysController@index');
        Route::post('api-keys', 'ApiKeysController@store');
        Route::delete('api-keys/{id}', 'ApiKeysController@destroy');
    });
    // ------------------------------- Custom domains (Settings > Custom Domain, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:custom_domains')->group(function () {
        Route::get('custom-domains', 'CustomDomainsController@index');
        Route::post('custom-domains', 'CustomDomainsController@store');
        // DNS lookups are slow and external — cap re-checks per user.
        Route::post('custom-domains/{id}/verify', 'CustomDomainsController@verify')->middleware('throttle:10,1');
        Route::post('custom-domains/{id}/primary', 'CustomDomainsController@makePrimary');
        Route::delete('custom-domains/{id}', 'CustomDomainsController@destroy');
    });
    // ------------------------------- Zapier (settings, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:zapier')->group(function () {
        Route::get('zapier/settings', [\App\Http\Controllers\Zapier\ZapierSettingsController::class, 'show']);
        Route::put('zapier/settings', [\App\Http\Controllers\Zapier\ZapierSettingsController::class, 'update']);
        Route::post('zapier/settings/generate-key', [\App\Http\Controllers\Zapier\ZapierSettingsController::class, 'generateKey']);
        Route::post('zapier/settings/revoke-key', [\App\Http\Controllers\Zapier\ZapierSettingsController::class, 'revokeKey']);
    });

    // ------------------------------- Slack (settings, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:slack')->group(function () {
        Route::get('slack/settings', [\App\Http\Controllers\Integrations\SlackSettingsController::class, 'show']);
        Route::put('slack/settings', [\App\Http\Controllers\Integrations\SlackSettingsController::class, 'update']);
        Route::post('slack/settings/test', [\App\Http\Controllers\Integrations\SlackSettingsController::class, 'test']);
    });

    // ------------------------------- Telegram (settings, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:telegram')->group(function () {
        Route::get('telegram/settings', [\App\Http\Controllers\Integrations\TelegramSettingsController::class, 'show']);
        Route::put('telegram/settings', [\App\Http\Controllers\Integrations\TelegramSettingsController::class, 'update']);
        Route::post('telegram/settings/test', [\App\Http\Controllers\Integrations\TelegramSettingsController::class, 'test']);
    });

    // ------------------------------- Salla (ecommerce sync, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:salla')->group(function () {
        Route::get('salla/settings', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'settings']);
        Route::post('salla/settings', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'saveSettings']);
        Route::post('salla/disconnect', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'disconnect']);
        Route::post('salla/test-connection', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'testConnection']);
        Route::get('salla/stats', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'stats']);
        Route::post('salla/reset-mappings', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'resetMappings']);
        Route::post('salla/sync/products', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'syncProducts']);
        Route::post('salla/sync/inventory', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'syncInventory']);
        Route::post('salla/sync/orders', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'syncOrders']);
        Route::get('salla/logs', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'logs']);
        Route::delete('salla/logs', [\App\Http\Controllers\Integrations\SallaSyncController::class, 'clearLogs']);
    });

    // ------------------------------- Xero (accounting sync, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:xero')->group(function () {
        Route::get('xero/settings', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'settings']);
        Route::post('xero/settings', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'saveSettings']);
        Route::post('xero/disconnect', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'disconnect']);
        Route::post('xero/test-connection', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'testConnection']);
        Route::get('xero/stats', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'stats']);
        Route::post('xero/reset-mappings', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'resetMappings']);
        Route::post('xero/sync/contacts', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'syncContacts']);
        Route::post('xero/sync/invoices', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'syncInvoices']);
        Route::get('xero/logs', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'logs']);
        Route::delete('xero/logs', [\App\Http\Controllers\Integrations\XeroSyncController::class, 'clearLogs']);
    });

    // ------------------------------- PrestaShop (ecommerce sync, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:prestashop')->group(function () {
        Route::get('prestashop/settings', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'settings']);
        Route::post('prestashop/settings', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'saveSettings']);
        Route::post('prestashop/test-connection', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'testConnection']);
        Route::get('prestashop/stats', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'stats']);
        Route::post('prestashop/reset-mappings', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'resetMappings']);
        Route::post('prestashop/sync/products', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'syncProducts']);
        Route::post('prestashop/sync/inventory', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'syncInventory']);
        Route::post('prestashop/sync/orders', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'syncOrders']);
        Route::get('prestashop/logs', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'logs']);
        Route::delete('prestashop/logs', [\App\Http\Controllers\Integrations\PrestashopSyncController::class, 'clearLogs']);
    });

    // ------------------------------- OpenCart (ecommerce sync, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:opencart')->group(function () {
        Route::get('opencart/settings', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'settings']);
        Route::post('opencart/settings', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'saveSettings']);
        Route::post('opencart/test-connection', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'testConnection']);
        Route::get('opencart/connector', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'downloadConnector']);
        Route::get('opencart/stats', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'stats']);
        Route::post('opencart/reset-mappings', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'resetMappings']);
        Route::post('opencart/sync/products', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'syncProducts']);
        Route::post('opencart/sync/inventory', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'syncInventory']);
        Route::post('opencart/sync/orders', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'syncOrders']);
        Route::get('opencart/logs', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'logs']);
        Route::get('opencart/logs/summary', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'logsSummary']);
        Route::get('opencart/logs/export', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'exportLogs']);
        Route::delete('opencart/logs', [\App\Http\Controllers\Integrations\OpencartSyncController::class, 'clearLogs']);
    });

    // ------------------------------- Google Sheets (exports, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:google_sheets')->group(function () {
        Route::get('google-sheets/settings', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'settings']);
        Route::post('google-sheets/settings', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'saveSettings']);
        Route::post('google-sheets/disconnect', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'disconnect']);
        Route::post('google-sheets/test-connection', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'testConnection']);
        Route::post('google-sheets/create-spreadsheet', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'createSpreadsheet']);
        Route::get('google-sheets/stats', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'stats']);
        Route::post('google-sheets/export/sales', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'exportSales']);
        Route::post('google-sheets/export/products', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'exportProducts']);
        Route::post('google-sheets/export/customers', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'exportCustomers']);
        Route::get('google-sheets/logs', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'logs']);
        Route::delete('google-sheets/logs', [\App\Http\Controllers\Integrations\GoogleSheetsSyncController::class, 'clearLogs']);
    });

    // ------------------------------- Mailchimp (audience sync, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:mailchimp')->group(function () {
        Route::get('mailchimp/settings', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'settings']);
        Route::post('mailchimp/settings', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'saveSettings']);
        Route::post('mailchimp/test-connection', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'testConnection']);
        Route::get('mailchimp/lists', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'lists']);
        Route::get('mailchimp/stats', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'stats']);
        Route::post('mailchimp/sync/customers', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'syncCustomers']);
        Route::get('mailchimp/logs', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'logs']);
        Route::delete('mailchimp/logs', [\App\Http\Controllers\Integrations\MailchimpSettingsController::class, 'clearLogs']);
    });

    // ------------------------------- Jumia (marketplace sync, feature-gated) ------------------------\\
    Route::middleware('tenant.feature:jumia')->group(function () {
        Route::get('jumia/settings', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'settings']);
        Route::post('jumia/settings', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'saveSettings']);
        Route::post('jumia/test-connection', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'testConnection']);
        Route::get('jumia/stats', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'stats']);
        Route::post('jumia/sync/price-stock', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'syncPriceStock']);
        Route::post('jumia/sync/orders', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'syncOrders']);
        Route::get('jumia/logs', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'logs']);
        Route::delete('jumia/logs', [\App\Http\Controllers\Integrations\JumiaSyncController::class, 'clearLogs']);
    });

    // ------------------------------- Printful (print-on-demand, feature-gated) ------------------\\
    Route::middleware('tenant.feature:printful')->group(function () {
        Route::get('printful/settings', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'settings']);
        Route::post('printful/settings', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'saveSettings']);
        Route::post('printful/test-connection', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'testConnection']);
        Route::get('printful/webhooks', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'webhookStatus']);
        Route::post('printful/webhooks', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'registerWebhooks']);
        Route::get('printful/stats', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'stats']);
        Route::post('printful/sync/products', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'syncProducts']);
        Route::get('printful/orders', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'orders']);
        Route::post('printful/orders/send/{onlineOrderId}', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'sendOrder']);
        Route::post('printful/orders/{id}/confirm', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'confirmOrder']);
        Route::post('printful/orders/{id}/cancel', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'cancelOrder']);
        Route::post('printful/orders/{id}/refresh', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'refreshOrder']);
        Route::get('printful/logs', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'logs']);
        Route::delete('printful/logs', [\App\Http\Controllers\Integrations\PrintfulSyncController::class, 'clearLogs']);
    });

    // ------------------------------- Courier integration (feature-gated) ------------------------\\
    // Courier accounts + settings (phase 1). Booking, tracking and webhooks join this group later.
    Route::middleware('tenant.feature:couriers')->prefix('couriers')->group(function () {
        Route::get('catalog', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'catalog']);
        Route::get('accounts', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'index']);
        Route::post('accounts', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'store'])->middleware('tenant.limit:max_courier_accounts');
        Route::put('accounts/{id}', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'update']);
        Route::delete('accounts/{id}', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'destroy']);
        Route::post('accounts/{id}/test', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'test']);
        Route::post('accounts/{id}/default', [\App\Http\Controllers\Couriers\CourierAccountsController::class, 'makeDefault']);
        Route::get('settings', [\App\Http\Controllers\Couriers\CourierSettingsController::class, 'show']);
        Route::put('settings', [\App\Http\Controllers\Couriers\CourierSettingsController::class, 'update']);
        Route::get('reports', [\App\Http\Controllers\Couriers\CourierReportsController::class, 'index']);
        Route::get('logs', [\App\Http\Controllers\Couriers\CourierLogsController::class, 'index']);
        Route::get('logs/{id}', [\App\Http\Controllers\Couriers\CourierLogsController::class, 'show'])->whereNumber('id');
        Route::delete('logs', [\App\Http\Controllers\Couriers\CourierLogsController::class, 'destroy']);
        Route::get('guide', [\App\Http\Controllers\Couriers\CourierGuideController::class, 'show']);
        // Booking from sales
        Route::get('sales/{saleId}', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'saleShipment']);
        Route::get('sales/{saleId}/booking', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'bookingForm']);
        Route::post('sales/{saleId}/book', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'book']);
        Route::post('sales/{saleId}/rates', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'rates']);
        Route::post('bulk/prepare', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'bulkPrepare']);
        Route::post('bulk/book', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'bulkBook']);
        Route::get('accounts/{id}/cities', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'cities']);
        // Shipments, timeline, labels, returns queue
        Route::get('shipments', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'index']);
        Route::post('shipments/labels', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'labelData']);
        Route::get('shipments/{id}', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'show']);
        Route::get('shipments/{id}/label', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'label']);
        Route::post('shipments/{id}/refresh', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'refresh']);
        Route::post('shipments/{id}/cancel', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'cancel']);
        Route::post('shipments/{id}/status', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'updateStatus']);
        Route::post('shipments/{id}/cod', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'updateCod']);
        Route::post('shipments/{id}/return/restock', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'restockReturn']);
        Route::post('shipments/{id}/return/dismiss', [\App\Http\Controllers\Couriers\CourierShipmentsController::class, 'dismissReturn']);
    });

    // ------------------------------- Webhooks (outgoing, feature-gated) ------------------------\\
    // Fully additive module: manages outgoing webhooks, logs, and delivery retries.
    Route::middleware('tenant.feature:webhooks')->group(function () {
    Route::get('webhooks/available-events', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'availableEvents']);
    Route::get('webhooks/deliveries', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'deliveries']);
    Route::get('webhooks/deliveries/{id}', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'deliveryShow']);
    Route::get('webhooks/incoming-logs', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'incomingLogs']);
    Route::get('webhooks/incoming-logs/{id}', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'incomingLogShow']);
    Route::get('webhooks/{id}/deliveries', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'deliveries']);
    Route::post('webhooks/{id}/test', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'test']);
    Route::post('webhooks/{id}/regenerate-secret', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'regenerateSecret']);
    Route::post('webhooks/{id}/toggle', [\App\Http\Controllers\Webhooks\WebhooksController::class, 'toggle']);
    Route::apiResource('webhooks', \App\Http\Controllers\Webhooks\WebhooksController::class);
    });

});

// Accounting V2 (feature-gated)
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.feature:accounting'])->group(function () {
    Route::prefix('accounting/v2')->group(function () {
        // Dashboard
        Route::get('dashboard', 'AccountingV2\\DashboardController@summary');
        // Chart of Accounts
        Route::get('coa', 'AccountingV2\\ChartOfAccountsController@index');
        Route::post('coa', 'AccountingV2\\ChartOfAccountsController@store');
        Route::post('coa/import', 'AccountingV2\\ChartOfAccountsController@import');
        Route::put('coa/{id}', 'AccountingV2\\ChartOfAccountsController@update');
        Route::delete('coa/{id}', 'AccountingV2\\ChartOfAccountsController@destroy');

        // Journal Entries
        Route::get('journal-entries', 'AccountingV2\\JournalEntriesController@index');
        Route::get('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@show');
        Route::post('journal-entries', 'AccountingV2\\JournalEntriesController@store');
        Route::post('journal-entries/{id}/post', 'AccountingV2\\JournalEntriesController@post');
        Route::put('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@update');
        Route::patch('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@update');
        Route::delete('journal-entries/{id}', 'AccountingV2\\JournalEntriesController@destroy');

        // Reports
        Route::get('reports/trial-balance', 'AccountingV2\\ReportsController@trialBalance');
        Route::get('reports/profit-loss', 'AccountingV2\\ReportsController@profitAndLoss');
        Route::get('reports/balance-sheet', 'AccountingV2\\ReportsController@balanceSheet');
        Route::post('reports/inventory-true-up', 'AccountingV2\\ReportsController@inventoryTrueUp');
        Route::get('reports/account-ledger', 'AccountingV2\\ReportsController@accountLedger');
        Route::get('reports/tax-summary', 'AccountingV2\\ReportsController@taxSummary');
        Route::get('reports/reconciliation', 'AccountingV2\\ReportsController@reconciliation');
        Route::get('reports/reconciliation/{accountId}', 'AccountingV2\\ReportsController@reconciliationDetail')->where('accountId', '[0-9]+');
    });
});

// Subscription billing (tenant-side, reads/writes central DB)
// No tenant.subscribed here, so tenants without a subscription can still reach
// billing. Is_Active only refuses deactivated/deleted staff users (statut) — it
// has nothing to do with the subscription, and a disabled user must not be
// able to buy or cancel plans for the workspace.
Route::middleware(['auth:api', 'Is_Active'])->prefix('billing')->group(function () {
    Route::get('/current-plan', [\App\Http\Controllers\Api\BillingApiController::class, 'currentPlan']);
    Route::get('/plans', [\App\Http\Controllers\Api\BillingApiController::class, 'plans']);
    Route::get('/checkout-data/{plan}', [\App\Http\Controllers\Api\BillingApiController::class, 'checkoutData']);
    Route::post('/checkout', [\App\Http\Controllers\Api\BillingApiController::class, 'checkout']);
    Route::post('/offline-payment', [\App\Http\Controllers\Api\BillingApiController::class, 'offlinePayment']);
    Route::post('/request-free-plan', [\App\Http\Controllers\Api\BillingApiController::class, 'requestFreePlan']);
    Route::get('/history', [\App\Http\Controllers\Api\BillingApiController::class, 'history']);
    Route::get('/payment/{id}', [\App\Http\Controllers\Api\BillingApiController::class, 'paymentDetail']);
    Route::post('/retry/{id}', [\App\Http\Controllers\Api\BillingApiController::class, 'retryPayment']);
    Route::post('/paypal/capture', [\App\Http\Controllers\Api\BillingApiController::class, 'capturePaypal'])->middleware('throttle:10,1');
    Route::post('/cancel', [\App\Http\Controllers\Api\BillingApiController::class, 'cancelSubscription']);
    Route::post('/resume', [\App\Http\Controllers\Api\BillingApiController::class, 'resumeSubscription']);
    Route::post('/cancel-upgrade', [\App\Http\Controllers\Api\BillingApiController::class, 'cancelPendingUpgrade']);
    Route::get('/invoices', [\App\Http\Controllers\Api\BillingApiController::class, 'invoices']);
    Route::get('/invoices/{id}/download', [\App\Http\Controllers\Api\BillingApiController::class, 'downloadInvoice']);
    Route::get('/plan-usage', [\App\Http\Controllers\Api\BillingApiController::class, 'planUsage']);

    // Add-ons: modules / premium themes bought on top of the plan.
    Route::get('/addons', [\App\Http\Controllers\Api\BillingAddonsController::class, 'index']);
    Route::post('/addons/purchase', [\App\Http\Controllers\Api\BillingAddonsController::class, 'purchase'])->middleware('throttle:20,1');
    Route::post('/addons/{id}/renew', [\App\Http\Controllers\Api\BillingAddonsController::class, 'renew'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/addons/trial', [\App\Http\Controllers\Api\BillingAddonsController::class, 'trial'])->middleware('throttle:10,1');
});

// Customer display polling: no login (unattended screen); lastCart() requires
// the display access token issued by customer-display/generate.
Route::get('pos/customer-display/last-cart', [CustomerDisplayController::class, 'lastCart']);

// -------------------------------  Print & PDF ------------------------\\
// ------------------------------------------------------------------\\
// These render full documents (invoices, quotations, payment receipts) that
// carry customer names, addresses, tax numbers and line items, so they run
// behind the same stack as every other authenticated endpoint in this file.
// The SPA fetches them over XHR (lib/http.js `download`/`raw`), which sends
// the Passport `laravel_token` cookie plus the `X-XSRF-TOKEN` header, so the
// token guard authenticates them exactly like the JSON endpoints.
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'token.timeout', 'tenant.subscribed', 'tenant.activity'])->group(function () {

    Route::get('sale_pdf/{id}', 'SalesController@Sale_PDF');
    Route::get('sale_print_html/{id}', 'SalesController@Sale_PDF_Inline');
    Route::get('quote_pdf/{id}', 'QuotationsController@Quotation_pdf');
    Route::get('quote_print_html/{id}', 'QuotationsController@Quotation_PDF_Inline');
    Route::get('booking_pdf/{id}', 'BookingController@booking_pdf');
    Route::get('service_job_pdf/{id}', 'ServiceJobController@service_job_pdf');
    Route::get('service_quote_pdf/{id}', 'ServiceJobController@service_quote_pdf');
    Route::get('purchase_pdf/{id}', 'PurchasesController@Purchase_pdf');
    Route::get('purchase_print_html/{id}', 'PurchasesController@Purchase_PDF_Inline');
    Route::get('return_sale_pdf/{id}', 'SalesReturnController@Return_pdf');
    Route::get('return_purchase_pdf/{id}', 'PurchasesReturnController@Return_pdf');
    Route::get('payment_purchase_pdf/{id}', 'PaymentPurchasesController@Payment_purchase_pdf');
    Route::get('payment_return_sale_pdf/{id}', 'PaymentSaleReturnsController@payment_return');
    Route::get('payment_return_purchase_pdf/{id}', 'PaymentPurchaseReturnsController@payment_return');
    Route::get('payment_sale_pdf/{id}', 'PaymentSalesController@payment_sale');
    Route::get('sales_print_invoice/{id}', 'SalesController@Print_Invoice_POS');
    Route::post('direct_network_print/{id}', 'SalesController@Direct_Network_Print_POS');
    Route::get('transfer_pdf/{id}', 'TransferController@transfer_pdf');
    Route::get('adjustment_pdf/{id}', 'AdjustmentController@adjustment_pdf');
    Route::get('damage_pdf/{id}', 'DamageController@damage_pdf');

});

// Links sent to customers/suppliers by email, SMS and WhatsApp. No login: the
// random per-document token is the only key (see DocumentShareController).
Route::get('share/{type}/{token}', 'DocumentShareController@show')
    ->where(['type' => '[a-z_]+', 'token' => '[A-Za-z0-9]{48}'])
    ->middleware('throttle:30,1');

// Route::get('/available-modules', 'ModuleSettingsController@get_modules_enabled');

// ======================== RECRUIT ========================
// Available to all subscribed tenants, gated by user permissions (no plan feature gate).
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.feature:recruitment'])->prefix('recruit')->group(function () {

    // Dashboard & Reports
    Route::get('dashboard', 'RecruitController@dashboard');
    Route::get('reports', 'RecruitController@reports');

    // Job Categories
    Route::get('categories', 'RecruitController@categories_index');
    Route::get('categories_all', 'RecruitController@categories_all');
    Route::post('categories', 'RecruitController@categories_store');
    Route::put('categories/{id}', 'RecruitController@categories_update');
    Route::delete('categories/{id}', 'RecruitController@categories_destroy');
    Route::post('categories/delete/by_selection', 'RecruitController@categories_delete_by_selection');

    // Jobs
    Route::get('jobs', 'RecruitController@jobs_index');
    Route::get('jobs_all', 'RecruitController@jobs_all');
    Route::post('jobs', 'RecruitController@jobs_store');
    Route::put('jobs/{id}', 'RecruitController@jobs_update');
    Route::delete('jobs/{id}', 'RecruitController@jobs_destroy');
    Route::post('jobs/delete/by_selection', 'RecruitController@jobs_delete_by_selection');

    // Candidates
    Route::get('candidates', 'RecruitController@candidates_index');
    Route::get('candidates_all', 'RecruitController@candidates_all');
    Route::post('candidates', 'RecruitController@candidates_store');
    Route::put('candidates/{id}', 'RecruitController@candidates_update');
    Route::delete('candidates/{id}', 'RecruitController@candidates_destroy');
    Route::post('candidates/delete/by_selection', 'RecruitController@candidates_delete_by_selection');

    // Applications
    Route::get('applications', 'RecruitController@applications_index');
    Route::get('applications_all', 'RecruitController@applications_all');
    Route::post('applications', 'RecruitController@applications_store');
    Route::put('applications/{id}', 'RecruitController@applications_update');
    Route::put('applications/{id}/stage', 'RecruitController@applications_update_stage');
    Route::delete('applications/{id}', 'RecruitController@applications_destroy');
    Route::post('applications/delete/by_selection', 'RecruitController@applications_delete_by_selection');

    // Interviews
    Route::get('interviews', 'RecruitController@interviews_index');
    Route::post('interviews', 'RecruitController@interviews_store');
    Route::put('interviews/{id}', 'RecruitController@interviews_update');
    Route::delete('interviews/{id}', 'RecruitController@interviews_destroy');
    Route::post('interviews/delete/by_selection', 'RecruitController@interviews_delete_by_selection');
});

// ======================== REAL ESTATE ========================
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'tenant.subscribed'])->prefix('realestate')->group(function () {

    // Dashboard
    Route::get('dashboard', 'RealEstateController@dashboard');

    // Properties
    Route::get('properties', 'RealEstateController@properties_index');
    Route::get('properties/{id}', 'RealEstateController@properties_show');
    Route::post('properties', 'RealEstateController@properties_store');
    Route::post('properties/{id}', 'RealEstateController@properties_update'); // POST for multipart (images) updates
    Route::put('properties/{id}', 'RealEstateController@properties_update');
    Route::delete('properties/{id}', 'RealEstateController@properties_destroy');
    Route::post('properties/delete/by_selection', 'RealEstateController@properties_delete_by_selection');

    // Categories
    Route::get('categories', 'RealEstateController@categories_index');
    Route::get('categories_all', 'RealEstateController@categories_all');
    Route::post('categories', 'RealEstateController@categories_store');
    Route::post('categories/{id}', 'RealEstateController@categories_update'); // POST for multipart (image) updates
    Route::put('categories/{id}', 'RealEstateController@categories_update');
    Route::delete('categories/{id}', 'RealEstateController@categories_destroy');

    // Inquiries
    Route::get('inquiries', 'RealEstateController@inquiries_index');
    Route::get('inquiries/{id}', 'RealEstateController@inquiries_show');
    Route::put('inquiries/{id}/status', 'RealEstateController@inquiries_update_status');
    Route::delete('inquiries/{id}', 'RealEstateController@inquiries_destroy');
    Route::post('inquiries/delete/by_selection', 'RealEstateController@inquiries_delete_by_selection');
});

// ======================== MEETING ========================
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.feature:meetings'])->prefix('meeting')->group(function () {

    // Dashboard, Calendar & Reports
    Route::get('dashboard', 'MeetingController@dashboard');
    Route::get('calendar', 'MeetingController@calendar');
    Route::get('reports', 'MeetingController@reports');

    // Meetings
    Route::get('meetings', 'MeetingController@meetings_index');
    Route::get('meetings_all', 'MeetingController@meetings_all');
    Route::get('meetings/{id}', 'MeetingController@meetings_show');
    Route::post('meetings', 'MeetingController@meetings_store');
    Route::put('meetings/{id}', 'MeetingController@meetings_update');
    Route::put('meetings/{id}/status', 'MeetingController@meetings_update_status');
    Route::delete('meetings/{id}', 'MeetingController@meetings_destroy');
    Route::post('meetings/delete/by_selection', 'MeetingController@meetings_delete_by_selection');

    // Invitations
    Route::post('meetings/{id}/invitations', 'MeetingController@meetings_send_invitations');

    // Attendance
    Route::post('meetings/{id}/attendance', 'MeetingController@attendance_update');

    // Notes / Minutes / Action items
    Route::post('notes', 'MeetingController@notes_store');
    Route::put('notes/{id}', 'MeetingController@notes_update');
    Route::delete('notes/{id}', 'MeetingController@notes_destroy');

    // Attachments
    Route::post('attachments', 'MeetingController@attachments_store');
    Route::delete('attachments/{id}', 'MeetingController@attachments_destroy');
});

// ======================== PROTOCOL & REQUESTS ========================
// Internal messaging / administrative protocol / ticketing: requests move
// desk to desk with a full history. Customers submit through the portal
// (routes/portal.php).
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.feature:protocol'])->prefix('protocol')->group(function () {
    Route::get('meta', 'ProtocolController@meta');
    Route::get('dashboard', 'ProtocolController@dashboard');
    Route::get('clients', 'ProtocolController@clients');

    Route::get('requests', 'ProtocolController@index');
    Route::post('requests', 'ProtocolController@store');
    Route::get('requests/{id}', 'ProtocolController@show');
    Route::put('requests/{id}', 'ProtocolController@update');
    Route::delete('requests/{id}', 'ProtocolController@destroy');
    // Desk actions: receive, acknowledge, note, respond, forward, reassign, return, approve, reject, close, reopen, attach
    Route::post('requests/{id}/action', 'ProtocolController@action');
    Route::delete('attachments/{id}', 'ProtocolController@attachmentDestroy');

    Route::get('desks', 'ProtocolController@desksIndex');
    Route::post('desks', 'ProtocolController@desksStore');
    Route::put('desks/{id}', 'ProtocolController@desksUpdate');
    Route::delete('desks/{id}', 'ProtocolController@desksDestroy');
});

// ======================== MARKETING ========================
Route::middleware(['auth:api', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.feature:marketing'])->prefix('marketing')->group(function () {

    // Dashboard, Reports & Activity
    Route::get('dashboard', 'MarketingController@dashboard');
    Route::get('reports', 'MarketingController@reports');
    Route::get('activity_logs', 'MarketingController@activity_logs');
    Route::get('guide', 'MarketingController@guide');

    // Campaigns
    Route::get('campaigns', 'MarketingController@campaigns_index');
    Route::get('campaigns/{id}', 'MarketingController@campaigns_show');
    Route::post('campaigns', 'MarketingController@campaigns_store');
    Route::post('campaigns/{id}', 'MarketingController@campaigns_update'); // POST for multipart (attachment) updates
    Route::put('campaigns/{id}', 'MarketingController@campaigns_update');
    Route::put('campaigns/{id}/status', 'MarketingController@campaigns_update_status');
    Route::post('campaigns/{id}/send', 'MarketingController@campaigns_send');
    Route::delete('campaigns/{id}', 'MarketingController@campaigns_destroy');
    Route::post('campaigns/delete/by_selection', 'MarketingController@campaigns_delete_by_selection');

    // Segments
    Route::get('segments', 'MarketingController@segments_index');
    Route::get('segments_all', 'MarketingController@segments_all');
    Route::post('segments', 'MarketingController@segments_store');
    Route::put('segments/{id}', 'MarketingController@segments_update');
    Route::delete('segments/{id}', 'MarketingController@segments_destroy');
    Route::post('segments/preview', 'MarketingController@segments_preview');
    // CRM lead audience lookups (answers available=false without the CRM)
    Route::get('segments/lead-meta', 'MarketingController@segments_lead_meta');

    // Templates
    Route::get('templates', 'MarketingController@templates_index');
    Route::get('templates_all', 'MarketingController@templates_all');
    Route::post('templates', 'MarketingController@templates_store');
    Route::put('templates/{id}', 'MarketingController@templates_update');
    Route::post('templates/{id}/duplicate', 'MarketingController@templates_duplicate');
    Route::delete('templates/{id}', 'MarketingController@templates_destroy');

    // Settings
    Route::get('settings', 'MarketingController@settings_show');
    Route::post('settings', 'MarketingController@settings_update');
});
