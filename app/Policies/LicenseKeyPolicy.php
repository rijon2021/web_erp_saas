<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Software License Keys (plan feature `license_keys`).
 *
 *  license_keys_view     dashboard, key lists (masked), license details
 *  license_keys_manage   add / import keys, restock, write off, delete, issue, resend, settings
 *  license_keys_control  extend, suspend, revoke, reactivate, reset activations
 *  license_keys_reveal   show / copy / export full keys
 */
class LicenseKeyPolicy
{
    use HandlesAuthorization;

    private function allowed(?User $user, string $slug): bool
    {
        if (! $user) {
            return false;
        }
        $permission = Permission::where('name', $slug)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function license_keys_view(?User $user)
    {
        return $this->allowed($user, 'license_keys_view');
    }

    public function license_keys_manage(?User $user)
    {
        return $this->allowed($user, 'license_keys_manage');
    }

    public function license_keys_control(?User $user)
    {
        return $this->allowed($user, 'license_keys_control');
    }

    public function license_keys_reveal(?User $user)
    {
        return $this->allowed($user, 'license_keys_reveal');
    }
}
