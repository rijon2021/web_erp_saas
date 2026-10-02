<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'address', 'mobile', 'country', 'city', 'email', 'zip',
        'logo', 'invoice_footer',
    ];

    public function assignedUsers()
    {
        return $this->belongsToMany('App\Models\User');
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }
}
