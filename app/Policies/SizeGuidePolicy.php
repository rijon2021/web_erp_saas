<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\SizeGuide;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SizeGuidePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user)
    {
        return $this->allowed($user);
    }

    public function create(User $user)
    {
        return $this->allowed($user);
    }

    public function update(User $user)
    {
        return $this->allowed($user);
    }

    public function delete(User $user)
    {
        return $this->allowed($user);
    }

    public function restore(User $user, SizeGuide $sizeGuide)
    {
        //
    }

    public function forceDelete(User $user, SizeGuide $sizeGuide)
    {
        //
    }

    private function allowed(User $user): bool
    {
        $permission = Permission::where('name', 'size_guides')->first();

        return $permission && $user->hasRole($permission->roles);
    }
}
