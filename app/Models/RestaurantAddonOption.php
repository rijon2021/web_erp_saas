<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One choice in an add-on group ("Extra cheese", +1.00). product_id links the
 * ingredient whose stock the choice uses (stock_qty units per dish ordered).
 */
class RestaurantAddonOption extends Model
{
    use SoftDeletes;

    protected $table = 'rst_addon_options';

    protected $fillable = ['group_id', 'name', 'price', 'product_id', 'stock_qty', 'is_default', 'is_available', 'sort_order'];

    protected $casts = [
        'group_id' => 'integer',
        'price' => 'float',
        'product_id' => 'integer',
        'stock_qty' => 'float',
        'is_default' => 'boolean',
        'is_available' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(RestaurantAddonGroup::class, 'group_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
