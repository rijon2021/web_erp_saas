<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RentalItemPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'rnt_items_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'rnt_items_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'rnt_items_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'rnt_items_delete');
    }

    /** The module dashboard has its own permission. */
    public function dashboard(User $user)
    {
        return $this->check($user, 'rnt_dashboard');
    }

    /** Availability timeline + pickups/returns calendar. */
    public function availability(User $user)
    {
        return $this->check($user, 'rnt_availability');
    }

    /** Maintenance jobs + preventive schedules. */
    public function maintenance(User $user)
    {
        return $this->check($user, 'rnt_maintenance');
    }

    /** Rental expenses. */
    public function expenses(User $user)
    {
        return $this->check($user, 'rnt_expenses');
    }

    /** Rental reports + customer statements. */
    public function reports(User $user)
    {
        return $this->check($user, 'rnt_reports');
    }

    /** Sending a reminder by hand. */
    public function reminders(User $user)
    {
        return $this->check($user, 'rnt_reminders');
    }

    /** Rental Settings page (prefixes, late fees, deposits…). */
    public function settings(User $user)
    {
        return $this->check($user, 'rnt_settings');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
