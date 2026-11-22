<?php

use App\Http\Controllers\Api\Super\ApprovalsController;
use App\Http\Controllers\Api\Super\AuthController;
use App\Http\Controllers\Api\Super\DashboardController;
use App\Http\Controllers\Api\Super\PaymentLinksController;
use App\Http\Controllers\Api\Super\PaymentsController;
use App\Http\Controllers\Api\Super\SupportController;
use App\Http\Controllers\Api\Super\TenantsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super-admin mobile app API — /api/super/* on the platform (central) domain
|--------------------------------------------------------------------------
| Bearer token from /api/super/login (central_api_tokens), no cookies. Each
| section checks the same permission key as the web panel.
*/

Route::get('ping', [AuthController::class, 'ping'])->middleware('throttle:60,1');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth.central.api', 'throttle:240,1'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('central.api.permission:dashboard');

    Route::middleware('central.api.permission:tenants')->group(function () {
        Route::get('tenants', [TenantsController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantsController::class, 'show']);
        Route::post('tenants/{tenant}/suspend', [TenantsController::class, 'suspend']);
        Route::post('tenants/{tenant}/reactivate', [TenantsController::class, 'reactivate']);
        Route::post('tenants/{tenant}/users/{userId}/block', [TenantsController::class, 'blockUser'])->whereNumber('userId');
        Route::post('tenants/{tenant}/users/{userId}/unblock', [TenantsController::class, 'unblockUser'])->whereNumber('userId');
        Route::post('tenants/{tenant}/users/{userId}/password', [TenantsController::class, 'userPassword'])->whereNumber('userId');
        Route::post('tenants/{tenant}/users/{userId}/force-logout', [TenantsController::class, 'logoutUser'])->whereNumber('userId');
        Route::post('tenants/{tenant}/domains', [TenantsController::class, 'addDomain']);
        Route::post('tenants/{tenant}/domains/{domainId}/toggle', [TenantsController::class, 'toggleDomain'])->whereNumber('domainId');
        Route::delete('tenants/{tenant}/domains/{domainId}', [TenantsController::class, 'removeDomain'])->whereNumber('domainId');
        Route::post('tenants/{tenant}/custom-domains/{id}/verify', [TenantsController::class, 'verifyCustomDomain'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('tenants/{tenant}/custom-domains/{id}/check-ssl', [TenantsController::class, 'checkCustomDomainSsl'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('tenants/{tenant}/custom-domains/{id}/primary', [TenantsController::class, 'primaryCustomDomain'])->whereNumber('id');
        Route::delete('tenants/{tenant}/custom-domains/{id}', [TenantsController::class, 'removeCustomDomain'])->whereNumber('id');
        Route::post('approvals/tenants/{tenant}/approve', [ApprovalsController::class, 'approveTenant']);
        Route::post('approvals/tenants/{tenant}/reject', [ApprovalsController::class, 'rejectTenant']);
    });

    // Same permission as the web Subscription panel.
    Route::middleware('central.api.permission:subscriptions')->group(function () {
        Route::post('tenants/{tenant}/subscription/extend', [TenantsController::class, 'extend']);
        Route::post('tenants/{tenant}/subscription/change-plan', [TenantsController::class, 'changePlan']);
    });

    Route::get('plans', [TenantsController::class, 'plans'])
        ->middleware('central.api.permission:subscriptions,payments_transactions');

    Route::get('approvals', [ApprovalsController::class, 'index'])
        ->middleware('central.api.permission:tenants,payments_pending');

    Route::middleware('central.api.permission:payments_pending')->group(function () {
        Route::post('approvals/registrations/{registration}/approve', [ApprovalsController::class, 'approveRegistration']);
        Route::post('approvals/registrations/{registration}/reject', [ApprovalsController::class, 'rejectRegistration']);
    });

    Route::middleware('central.api.permission:payments_transactions')->group(function () {
        Route::get('payments', [PaymentsController::class, 'index']);
        Route::get('payments/options', [PaymentsController::class, 'options']);
        Route::post('payments', [PaymentsController::class, 'store']);
        Route::get('payments/{payment}', [PaymentsController::class, 'show']);
        Route::get('payments/{payment}/invoice', [PaymentsController::class, 'invoice']);
        Route::post('payments/{payment}/mark-paid', [PaymentsController::class, 'markPaid']);
        Route::post('payments/{payment}/mark-failed', [PaymentsController::class, 'markFailed']);
        Route::post('payments/{payment}/mark-refunded', [PaymentsController::class, 'markRefunded']);
    });

    Route::middleware('central.api.permission:payments_links')->group(function () {
        Route::get('payment-links', [PaymentLinksController::class, 'index']);
        Route::post('payment-links', [PaymentLinksController::class, 'store']);
        Route::get('payment-links/{paymentLink}', [PaymentLinksController::class, 'show']);
        Route::post('payment-links/{paymentLink}/send-email', [PaymentLinksController::class, 'sendEmail'])->middleware('throttle:20,1');
        Route::post('payment-links/{paymentLink}/cancel', [PaymentLinksController::class, 'cancel']);
    });

    Route::middleware('central.api.permission:support')->group(function () {
        Route::get('support', [SupportController::class, 'index']);
        Route::get('support/attachments/{attachment}', [SupportController::class, 'attachment']);
        Route::get('support/{ticket}', [SupportController::class, 'show']);
        Route::post('support/{ticket}/reply', [SupportController::class, 'reply']);
        Route::post('support/{ticket}/note', [SupportController::class, 'note']);
        Route::post('support/{ticket}/status', [SupportController::class, 'status']);
        Route::post('support/{ticket}/assign', [SupportController::class, 'assign']);
    });
});
