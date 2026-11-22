<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Restaurant Management (plan feature `restaurant`). One ability per
 * permission slug, checked against RestaurantSetting::class:
 *   $this->authorizeForUser($user, 'rst_settings', RestaurantSetting::class)
 */
class RestaurantPolicy
{
    use HandlesAuthorization;

    public const PERMISSIONS = [
        'rst_dashboard', 'rst_floor', 'rst_orders_take', 'rst_orders_void', 'rst_discounts', 'rst_billing',
        'rst_menu', 'rst_reservations', 'rst_reports', 'rst_setup', 'rst_settings', 'rst_kitchen',
    ];

    public function rst_dashboard(User $user) { return $this->granted($user, 'rst_dashboard'); }

    public function rst_floor(User $user) { return $this->granted($user, 'rst_floor'); }

    public function rst_orders_take(User $user) { return $this->granted($user, 'rst_orders_take'); }

    public function rst_orders_void(User $user) { return $this->granted($user, 'rst_orders_void'); }

    public function rst_discounts(User $user) { return $this->granted($user, 'rst_discounts'); }

    public function rst_billing(User $user) { return $this->granted($user, 'rst_billing'); }

    public function rst_menu(User $user) { return $this->granted($user, 'rst_menu'); }

    public function rst_reservations(User $user) { return $this->granted($user, 'rst_reservations'); }

    public function rst_reports(User $user) { return $this->granted($user, 'rst_reports'); }

    public function rst_setup(User $user) { return $this->granted($user, 'rst_setup'); }

    public function rst_settings(User $user) { return $this->granted($user, 'rst_settings'); }

    public function rst_kitchen(User $user) { return $this->granted($user, 'rst_kitchen'); }

    private function granted(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? (bool) $user->hasRole($permission->roles) : false;
    }
}
