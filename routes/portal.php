<?php

/*
|--------------------------------------------------------------------------
| Client Portal API Routes (separate auth for clients)
|--------------------------------------------------------------------------
|
| - Auth: portal guard only (PortalClient model). Admin (web/api) and store
|   auth do NOT grant access; clients must log in via /portal/login.
| - Session-based; uses web middleware for session support.
| - All data scoped to the authenticated portal client's client_id only.
| - Loaded inside the tenant route group (RouteServiceProvider), so tenancy
|   is initialized before portal.locale reads the tenant's portal languages.
|
*/

use App\Http\Controllers\Api\Portal\PortalAppointmentsController;
use App\Http\Controllers\Api\Portal\PortalAuthController;
use App\Http\Controllers\Api\Portal\PortalBookingsController;
use App\Http\Controllers\Api\Portal\PortalContractsController;
use App\Http\Controllers\Api\Portal\PortalCrmRequestsController;
use App\Http\Controllers\Api\Portal\PortalDashboardController;
use App\Http\Controllers\Api\Portal\PortalInvoicePdfController;
use App\Http\Controllers\Api\Portal\PortalInvoicesController;
use App\Http\Controllers\Api\Portal\PortalKnowledgeBaseController;
use App\Http\Controllers\Api\Portal\PortalLocaleController;
use App\Http\Controllers\Api\Portal\PortalPaymentsController;
use App\Http\Controllers\Api\Portal\PortalProfileController;
use App\Http\Controllers\Api\Portal\PortalQuotationsController;
use App\Http\Controllers\Api\Portal\PortalRentalsController;
use App\Http\Controllers\Api\Portal\PortalRequestsController;
use App\Http\Controllers\Api\Portal\PortalStatementController;
use App\Http\Controllers\Api\Portal\PortalTwoFactorController;
use Illuminate\Support\Facades\Route;

// portal.locale runs before portal.auth so its 403 message is already translated.
Route::prefix('api/portal')->middleware(['web', 'portal.locale', 'portal.auth', 'throttle:60,1'])->group(function () {
    // Public - no auth (portal login / set password only)
    // Tighter than the group's 60/min: 10 password attempts per minute per IP.
    Route::post('login', [PortalAuthController::class, 'login'])->middleware('throttle:10,1,portal-login');
    // Code step for accounts with two-step verification.
    Route::post('login/two-factor', [PortalTwoFactorController::class, 'verify'])->middleware('throttle:10,1,portal-two-factor');
    Route::post('login/two-factor/resend', [PortalTwoFactorController::class, 'resend'])->middleware('throttle:5,1,portal-two-factor-resend');
    Route::get('validate-invite', [PortalAuthController::class, 'validateInviteToken']);
    Route::post('set-password', [PortalAuthController::class, 'setPassword']);

    // Language (public: the login / set-password pages carry a switcher too).
    // Messages: the tenant's portal_translations over resources/lang/{locale}/portal.php.
    Route::get('translations/{locale}', [PortalLocaleController::class, 'translations']);
    Route::get('locale', [PortalLocaleController::class, 'show']);
    Route::post('locale', [PortalLocaleController::class, 'update']);

    // Protected - portal guard only (separate from admin and store)
    Route::middleware('auth:portal')->post('logout', [PortalAuthController::class, 'logout']);

    // tenant.entitled pauses the portal while the workspace's own subscription
    // has lapsed (date-based, no cron needed). portal.feature applies Client
    // Portal → Portal Settings (global switch, then per-module / per-action
    // switches named on each group below) and the tenant's plan.
    Route::middleware(['auth:portal', 'tenant.entitled', 'portal.feature'])->group(function () {
        Route::get('me', [PortalAuthController::class, 'me']);

        Route::get('dashboard', [PortalDashboardController::class, 'index']);
        Route::get('notifications', [PortalDashboardController::class, 'notifications']);
        Route::middleware('portal.feature:invoices')->group(function () {
            Route::get('invoices', [PortalInvoicesController::class, 'index']);
            Route::get('invoices/{id}', [PortalInvoicesController::class, 'show']);
            Route::get('invoices/{id}/pdf', [PortalInvoicePdfController::class, 'download']);
        });
        Route::get('payments', [PortalPaymentsController::class, 'index'])->middleware('portal.feature:payments');
        Route::get('statement', [PortalStatementController::class, 'index'])->middleware('portal.feature:statement');
        // Software License Keys: the customer's keys (+ freeing an activation slot)
        Route::get('licenses/summary', [\App\Http\Controllers\Api\Portal\PortalLicensesController::class, 'summary']);
        Route::get('licenses', [\App\Http\Controllers\Api\Portal\PortalLicensesController::class, 'index']);
        Route::post('licenses/{id}/activations/{activation}/deactivate', [\App\Http\Controllers\Api\Portal\PortalLicensesController::class, 'deactivate'])
            ->whereNumber('id')->whereNumber('activation')->middleware('throttle:20,1,portal-license-deactivate');
        Route::get('profile', [PortalProfileController::class, 'show']);
        Route::put('profile/password', [PortalProfileController::class, 'updatePassword']);
        // Two-step verification (allowed from Portal Settings)
        Route::get('profile/two-factor', [PortalTwoFactorController::class, 'status']);
        Route::middleware('throttle:10,1,portal-two-factor')->group(function () {
            Route::post('profile/two-factor/setup', [PortalTwoFactorController::class, 'setup']);
            Route::post('profile/two-factor/resend', [PortalTwoFactorController::class, 'resendSetup']);
            Route::post('profile/two-factor/confirm', [PortalTwoFactorController::class, 'confirm']);
            Route::post('profile/two-factor/recovery-codes', [PortalTwoFactorController::class, 'regenerate']);
            Route::post('profile/two-factor/disable', [PortalTwoFactorController::class, 'disable']);
        });
        // Self-service account closure (deletes the portal login only).
        Route::delete('profile', [PortalProfileController::class, 'destroyAccount']);

        // Quotations (read + request)
        Route::middleware('portal.feature:quotations')->group(function () {
            Route::get('quotations', [PortalQuotationsController::class, 'index']);
            Route::get('quotations/{id}', [PortalQuotationsController::class, 'show']);
            Route::post('quotations', [PortalQuotationsController::class, 'store'])->middleware('portal.feature:quotation_requests');
        });

        // Protocol & Requests: complaints, requests, enquiries… with attachments
        Route::middleware('portal.feature:requests')->group(function () {
            Route::get('requests', [PortalRequestsController::class, 'index']);
            Route::post('requests', [PortalRequestsController::class, 'store']);
            Route::get('requests/{id}', [PortalRequestsController::class, 'show']);
            Route::post('requests/{id}/reply', [PortalRequestsController::class, 'reply']);
        });

        // CRM deals shared with the customer + messages with their rep.
        // (stocky serves these as portal/requests; that path is Protocol's here.)
        Route::middleware('portal.feature:deals')->group(function () {
            Route::get('deals/summary', [PortalCrmRequestsController::class, 'summary']);
            Route::get('deals', [PortalCrmRequestsController::class, 'index']);
            Route::get('deals/{id}', [PortalCrmRequestsController::class, 'show'])->whereNumber('id');
            Route::post('deals/{id}/messages', [PortalCrmRequestsController::class, 'message'])
                ->whereNumber('id')->middleware('throttle:15,1');
        });

        // Appointments (read + book)
        Route::middleware('portal.feature:appointments')->group(function () {
            Route::get('appointments', [PortalAppointmentsController::class, 'index']);
            Route::get('appointments/{id}', [PortalAppointmentsController::class, 'show']);
            Route::post('appointments', [PortalAppointmentsController::class, 'store'])->middleware('portal.feature:appointment_booking');
        });

        // Bookings (Booking Management): upcoming / past visits, cancel,
        // answer a suggested time; deposits are paid on the status page.
        Route::middleware('portal.feature:bookings')->group(function () {
            Route::get('bookings/summary', [PortalBookingsController::class, 'summary']);
            Route::get('bookings', [PortalBookingsController::class, 'index']);
            Route::get('bookings/{id}', [PortalBookingsController::class, 'show'])->whereNumber('id');
            Route::middleware('throttle:10,1')->group(function () {
                Route::post('bookings/{id}/cancel', [PortalBookingsController::class, 'cancel'])->whereNumber('id');
                Route::post('bookings/{id}/accept-time', [PortalBookingsController::class, 'acceptTime'])->whereNumber('id');
                Route::post('bookings/{id}/decline-time', [PortalBookingsController::class, 'declineTime'])->whereNumber('id');
            });
        });

        // Contracts (read + download)
        Route::middleware('portal.feature:contracts')->group(function () {
            Route::get('contracts', [PortalContractsController::class, 'index']);
            Route::get('contracts/{id}', [PortalContractsController::class, 'show']);
            Route::get('contracts/{id}/attachments/{attachmentId}/download', [PortalContractsController::class, 'downloadAttachment']);
        });

        // Rentals (Rental Management): contracts, online signing, invoices,
        // maintenance requests. Scoped to the client's rental profile.
        Route::middleware('portal.feature:rentals')->group(function () {
            Route::get('rentals/summary', [PortalRentalsController::class, 'summary']);
            Route::get('rentals/invoices', [PortalRentalsController::class, 'invoices']);
            Route::get('rentals/invoices/{id}/pdf', [PortalRentalsController::class, 'invoicePdf'])->whereNumber('id');
            Route::get('rentals/requests', [PortalRentalsController::class, 'requests']);
            Route::post('rentals/requests', [PortalRentalsController::class, 'storeRequest']);
            Route::get('rentals', [PortalRentalsController::class, 'index']);
            Route::get('rentals/{id}', [PortalRentalsController::class, 'show'])->whereNumber('id');
            Route::get('rentals/{id}/agreement', [PortalRentalsController::class, 'agreementPdf'])->whereNumber('id');
            Route::post('rentals/{id}/sign', [PortalRentalsController::class, 'sign'])->whereNumber('id');
        });

        // Knowledge base (read-only): the tenant's own public articles
        Route::middleware('portal.feature:help')->group(function () {
            Route::get('knowledge-base', [PortalKnowledgeBaseController::class, 'index']);
            Route::get('knowledge-base/{slug}', [PortalKnowledgeBaseController::class, 'show']);
        });
    });
});
