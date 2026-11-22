<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RentalContractPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'rnt_contracts_view');
    }

    /** Also covers renewing (which creates a contract). */
    public function create(User $user)
    {
        return $this->check($user, 'rnt_contracts_add');
    }

    /** Editing plus the lifecycle actions, signature and attachments. */
    public function update(User $user)
    {
        return $this->check($user, 'rnt_contracts_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'rnt_contracts_delete');
    }

    /** Check-out / check-in screens. */
    public function handover(User $user)
    {
        return $this->check($user, 'rnt_handover');
    }

    /** Collect, refund, apply or keep deposits. */
    public function deposits(User $user)
    {
        return $this->check($user, 'rnt_deposits');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
