<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Add-on group a dish can offer: "Size" (choose exactly 1), "Extras"
 * (choose up to 3)… min_select 0 = optional; max_select ≥ min_select.
 */
class RestaurantAddonGroup extends Model
{
    use SoftDeletes;

    protected $table = 'rst_addon_groups';

    protected $fillable = ['name', 'min_select', 'max_select', 'sort_order', 'is_active'];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(RestaurantAddonOption::class, 'group_id')->orderBy('sort_order')->orderBy('id');
    }
}
