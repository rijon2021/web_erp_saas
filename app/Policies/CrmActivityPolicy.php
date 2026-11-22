<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** CRM activities & tasks (crm_activities_*). Row visibility: CrmActivityService::visible(). */
class CrmActivityPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'crm_activities_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'crm_activities_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'crm_activities_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'crm_activities_delete');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
