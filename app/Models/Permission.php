<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $guarded = ['id'];

    protected $fillable = ['name', 'label', 'description'];

    /**
     * Restriction permissions: ticking one on a role TAKES AWAY access
     * (hide cost figures, block product exports). Grant-all paths for the
     * owner role (updaters) must skip these.
     */
    public const RESTRICTIONS = ['hide_product_cost', 'disable_product_export'];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Determine if the permission belongs to the role.
     *
     * @param  mixed  $role
     * @return bool
     */
    public function inRole($role)
    {
        if (is_string($role)) {
            return $this->roles->contains('name', $role);
        }

        return (bool) $role->intersect($this->roles)->count();
    }
}
