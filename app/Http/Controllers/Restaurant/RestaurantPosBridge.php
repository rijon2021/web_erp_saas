<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\PosController;
use App\Models\RestaurantSetting;
use Illuminate\Http\Request;

/**
 * Lets restaurant billing create its sales through the POS sale pipeline
 * (stock, cash register, payments, accounting, commissions, e-invoicing,
 * QuickBooks…) without the cashier needing the POS permission: the POS
 * "Sales_pos" check is answered by the restaurant's own "rst_billing".
 * Every other check (e.g. check_record) is the POS controller's own.
 */
class RestaurantPosBridge extends PosController
{
    public function createSale(Request $request)
    {
        return $this->createPosSale($request);
    }

    /**
     * The dishes were checked when they were ordered; a dish taken off the
     * menu (or deleted) since, and add-on ingredients kept "Not for sale"
     * (raw stock), must still be billable — refusing would leave the table
     * unpayable.
     */
    protected function unsellablePosLines(array $details): array
    {
        return [];
    }

    public function authorizeForUser($user, $ability, $arguments = [])
    {
        if ($ability === 'Sales_pos') {
            return parent::authorizeForUser($user, 'rst_billing', RestaurantSetting::class);
        }

        return parent::authorizeForUser($user, $ability, $arguments);
    }
}
