<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\PaymentPurchaseReturns;
use App\Models\PaymentSaleReturns;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Http\Request;

/**
 * Public document links sent to customers and suppliers by email, SMS and
 * WhatsApp: GET /api/share/{type}/{token}.
 *
 * The id-based PDF routes require a login, so this is the only way in without
 * one. The document is looked up by its random share token alone (see
 * HasShareToken) and then rendered by the same handler the back office uses,
 * which recognises the request through the `shared_document` attribute.
 */
class DocumentShareController extends Controller
{
    /** type => [model, controller, PDF handler] */
    public const TYPES = [
        Sale::SHARE_TYPE => [Sale::class, SalesController::class, 'Sale_PDF'],
        Quotation::SHARE_TYPE => [Quotation::class, QuotationsController::class, 'Quotation_pdf'],
        Purchase::SHARE_TYPE => [Purchase::class, PurchasesController::class, 'Purchase_pdf'],
        Booking::SHARE_TYPE => [Booking::class, BookingController::class, 'booking_pdf'],
        PaymentSaleReturns::SHARE_TYPE => [PaymentSaleReturns::class, PaymentSaleReturnsController::class, 'payment_return'],
        PaymentPurchaseReturns::SHARE_TYPE => [PaymentPurchaseReturns::class, PaymentPurchaseReturnsController::class, 'payment_return'],
    ];

    public function show(Request $request, string $type, string $token)
    {
        if (! isset(self::TYPES[$type])) {
            abort(404);
        }

        [$model, $controller, $method] = self::TYPES[$type];

        $document = $model::where('share_token', $token)
            ->whereNull('deleted_at')
            ->first();

        if (! $document) {
            abort(404);
        }

        $request->attributes->set('shared_document', $document);

        return app($controller)->{$method}($request, $document->id);
    }
}
