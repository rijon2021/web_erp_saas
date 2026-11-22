<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RentalCustomerPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'rnt_customers_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'rnt_customers_add');
    }

    /** Also covers documents and the blacklist switch. */
    public function update(User $user)
    {
        return $this->check($user, 'rnt_customers_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'rnt_customers_delete');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
