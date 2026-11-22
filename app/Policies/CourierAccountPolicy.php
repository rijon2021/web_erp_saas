<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Courier integration abilities, one per permission slug:
 *   courier_settings  — manage courier accounts and courier settings
 *   courier_book      — book / cancel / print labels for shipments
 *   courier_shipments — see courier shipments, timelines, COD and returns
 *   courier_reports   — courier performance report (delivery / return rates, COD)
 */
class CourierAccountPolicy
{
    use HandlesAuthorization;

    protected function allowed(User $user, string $slug): bool
    {
        $permission = Permission::where('name', $slug)->first();

        // Tenants provisioned before the permission existed fall back to the
        // owner role (role id 1) so they are never locked out.
        return $permission ? $user->hasRole($permission->roles) : $user->roles->contains('id', 1);
    }

    public function courier_settings(User $user)
    {
        return $this->allowed($user, 'courier_settings');
    }

    public function courier_book(User $user)
    {
        return $this->allowed($user, 'courier_book');
    }

    public function courier_shipments(User $user)
    {
        return $this->allowed($user, 'courier_shipments');
    }

    public function courier_reports(User $user)
    {
        return $this->allowed($user, 'courier_reports');
    }
}
