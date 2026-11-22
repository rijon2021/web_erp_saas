<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Kitchen station (Grill, Fryer, Cold…). Dishes are routed to one on the kitchen display. */
class RestaurantStation extends Model
{
    use SoftDeletes;

    protected $table = 'rst_stations';

    /** Offered by "Add the usual stations" on an empty list (no bar: non-alcoholic drinks only). */
    public const DEFAULTS = ['Grill', 'Fryer', 'Cold', 'Desserts', 'Drinks'];

    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];
}
