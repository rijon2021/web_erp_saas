<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** CRM deals (crm_deals_*). "See everyone's" is crm_view_all, shared with leads. */
class CrmDealPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'crm_deals_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'crm_deals_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'crm_deals_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'crm_deals_delete');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
