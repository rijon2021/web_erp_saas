<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** Rental invoices + payments. */
class RentalInvoicePolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'rnt_billing_view');
    }

    /** Record a payment. */
    public function pay(User $user)
    {
        return $this->check($user, 'rnt_payments_add');
    }

    /** Manual charges, voiding invoices, waiving late fees. */
    public function manage(User $user)
    {
        return $this->check($user, 'rnt_billing_manage');
    }

    public function deletePayment(User $user)
    {
        return $this->check($user, 'rnt_payments_delete');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
