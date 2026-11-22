<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BookingPolicy
{
    use HandlesAuthorization;

    /**
     * Resolve the 'bookings' permission and check if the user has it.
     */
    protected function checkPermission(User $user): bool
    {
        $permission = Permission::where('name', 'bookings')->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }

    public function view(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function update(User $user): bool
    {
        return $this->checkPermission($user);
    }

    public function delete(User $user): bool
    {
        return $this->checkPermission($user);
    }

    // Booking Management upgrade: one ability per permission slug, checked as
    // $this->authorizeForUser($user, 'bookings_settings', Booking::class).

    /** Every slug of the module, for "any booking permission" checks. */
    public const PERMISSIONS = ['bookings', 'trays', 'bookings_settings', 'bookings_services', 'bookings_staff', 'bookings_reports'];

    public function bookings_settings(User $user): bool
    {
        return $this->granted($user, 'bookings_settings');
    }

    public function bookings_services(User $user): bool
    {
        return $this->granted($user, 'bookings_services');
    }

    public function bookings_staff(User $user): bool
    {
        return $this->granted($user, 'bookings_staff');
    }

    public function bookings_reports(User $user): bool
    {
        return $this->granted($user, 'bookings_reports');
    }

    /**
     * Any of the module's booking permissions (shared lookups such as the
     * business-type registry and free times). Trays alone is not enough: free
     * times show other bookings' references.
     */
    public function bookings_any(User $user): bool
    {
        foreach (array_diff(self::PERMISSIONS, ['trays']) as $name) {
            if ($this->granted($user, $name)) {
                return true;
            }
        }

        return false;
    }

    private function granted(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? (bool) $user->hasRole($permission->roles) : false;
    }
}












