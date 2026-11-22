<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StoreSettingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return mixed
     */
    public function viewAny(User $user)
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function view(User $user)
    {
        $permission = Permission::where('name', 'Store_settings_view')->first();

        return $user->hasRole($permission->roles);
    }

    /**
     * Determine whether the user can create models.
     *
     * @return mixed
     */
    public function create(User $user)
    {
        $permission = Permission::where('name', 'Store_settings_view')->first();

        return $user->hasRole($permission->roles);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function update(User $user)
    {
        $permission = Permission::where('name', 'Store_settings_view')->first();

        return $user->hasRole($permission->roles);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function delete(User $user)
    {
        $permission = Permission::where('name', 'Store_settings_view')->first();

        return $user->hasRole($permission->roles);
    }

    /*
     * One ability per Store submenu, named exactly like its permission slug.
     */
    public function store_visit(User $user)
    {
        return $this->granted($user, 'store_visit');
    }

    public function store_invite_codes(User $user)
    {
        return $this->granted($user, 'store_invite_codes');
    }

    public function store_pending_customers(User $user)
    {
        return $this->granted($user, 'store_pending_customers');
    }

    public function store_customers_without_login(User $user)
    {
        return $this->granted($user, 'store_customers_without_login');
    }

    public function store_customers_with_login(User $user)
    {
        return $this->granted($user, 'store_customers_with_login');
    }

    public function store_shipping_methods(User $user)
    {
        return $this->granted($user, 'store_shipping_methods');
    }

    public function store_tax_rates(User $user)
    {
        return $this->granted($user, 'store_tax_rates');
    }

    public function store_coupons(User $user)
    {
        return $this->granted($user, 'store_coupons');
    }

    public function store_flash_sales(User $user)
    {
        return $this->granted($user, 'store_flash_sales');
    }

    public function store_reviews(User $user)
    {
        return $this->granted($user, 'store_reviews');
    }

    public function store_popups(User $user)
    {
        return $this->granted($user, 'store_popups');
    }

    public function store_quote_requests(User $user)
    {
        return $this->granted($user, 'store_quote_requests');
    }

    public function store_returns(User $user)
    {
        return $this->granted($user, 'store_returns');
    }

    public function store_pages(User $user)
    {
        return $this->granted($user, 'store_pages');
    }

    public function store_menus(User $user)
    {
        return $this->granted($user, 'store_menus');
    }

    public function store_vehicle_leads(User $user)
    {
        return $this->granted($user, 'store_vehicle_leads');
    }

    /** A slug not seeded yet on this tenant denies instead of erroring. */
    private function granted(User $user, string $name): bool
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? (bool) $user->hasRole($permission->roles) : false;
    }

    public function check_record(User $user, $sale)
    {
        return $user->id === $sale->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function restore(User $user)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\Sale  $sale
     * @return mixed
     */
    public function forceDelete(User $user)
    {
        //
    }
}
