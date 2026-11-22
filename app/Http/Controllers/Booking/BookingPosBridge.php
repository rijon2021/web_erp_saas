<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\PosController;
use App\Models\Booking;
use Illuminate\Http\Request;

/**
 * Lets "Complete & charge" create its sale through the POS sale pipeline
 * (cash register, payments, accounting, commissions, e-invoicing,
 * QuickBooks…) without the staff member needing the POS permission: the POS
 * "Sales_pos" check is answered by the Booking Management "bookings"
 * permission. Every other check is the POS controller's own.
 */
class BookingPosBridge extends PosController
{
    public function createSale(Request $request)
    {
        return $this->createPosSale($request);
    }

    /**
     * The service was chosen when the booking was made; one taken off sale
     * ("Not for sale") since must still be chargeable for the visit that
     * happened.
     */
    protected function unsellablePosLines(array $details): array
    {
        return [];
    }

    public function authorizeForUser($user, $ability, $arguments = [])
    {
        if ($ability === 'Sales_pos') {
            return parent::authorizeForUser($user, 'update', Booking::class);
        }

        return parent::authorizeForUser($user, $ability, $arguments);
    }
}
