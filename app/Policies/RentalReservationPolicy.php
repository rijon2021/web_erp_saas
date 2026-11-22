<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RentalReservationPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'rnt_reservations_view');
    }

    /** Creating a hold. */
    public function create(User $user)
    {
        return $this->check($user, 'rnt_reservations_add');
    }

    /** Editing, cancelling and extending a hold. */
    public function update(User $user)
    {
        return $this->check($user, 'rnt_reservations_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'rnt_reservations_delete');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
