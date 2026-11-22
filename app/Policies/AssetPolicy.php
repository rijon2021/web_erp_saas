<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AssetPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        $permission = Permission::where('name', 'assets')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function create(User $user)
    {
        $permission = Permission::where('name', 'assets')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function update(User $user)
    {
        $permission = Permission::where('name', 'assets')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function delete(User $user)
    {
        $permission = Permission::where('name', 'assets')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    /** Depreciation + register / maintenance / custody reports. */
    public function reports(User $user)
    {
        $permission = Permission::where('name', 'asset_reports')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    /**
     * Shared select options (assets, warehouses, users) — every asset page
     * loads them, so any asset permission is enough.
     */
    public function workspace(User $user)
    {
        foreach (['assets', 'asset_assignments', 'asset_maintenance', 'asset_transfers', 'asset_reports'] as $slug) {
            $permission = Permission::where('name', $slug)->first();
            if ($permission && $user->hasRole($permission->roles)) {
                return true;
            }
        }

        return false;
    }
}





