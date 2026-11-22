<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $dates = ['deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * Privilege fields (role_id, statut, is_all_warehouses, record_view) are
     * deliberately NOT mass assignable: a future create/update($request->all())
     * must never let a user change their own role, activation or data scope.
     * Set them explicitly ($user->statut = ...) as UserController and the
     * super-admin block/unblock (Central\Super\TenantUserController) do.
     *
     * @var array
     */
    protected $fillable = [
        'firstname', 'lastname', 'username', 'email', 'password', 'phone', 'avatar',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'pos_pin_hash',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'role_id' => 'integer',
        'statut' => 'integer',
        'is_all_warehouses' => 'integer',
        'record_view' => 'boolean',
    ];

    public function oauthAccessToken()
    {
        return $this->hasMany('\App\Models\OauthAccessToken');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function assignRole(Role $role)
    {
        return $this->roles()->save($role);
    }

    public function hasRole($role)
    {
        if (is_string($role)) {
            return $this->roles->contains('name', $role);
        }

        return (bool) $role->intersect($this->roles)->count();
    }

    public function assignedWarehouses()
    {
        return $this->belongsToMany('App\Models\Warehouse');
    }

    /**
     * Check if user has record_view permission (user-level boolean with backward compatibility)
     * 
     * @return bool
     */
    public function hasRecordView()
    {
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        if (isset($this->record_view)) {
            return (bool) $this->record_view;
        }

        // The role fallback costs queries and dashboards/reports ask many times
        // per request on the same Auth::user() instance — resolve it once.
        if ($this->recordViewFromRole === null) {
            $role = $this->roles()->first();
            $this->recordViewFromRole = $role ? (bool) $role->inRole('record_view') : false;
        }

        return $this->recordViewFromRole;
    }

    /** Per-instance memo for hasRecordView()'s role fallback. */
    protected ?bool $recordViewFromRole = null;
}
