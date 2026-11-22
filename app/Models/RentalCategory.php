<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalCategory extends Model
{
    use SoftDeletes;

    protected $table = 'rental_categories';

    /** Photo directory under upload_path(): images/tenants/{id}/rental_categories */
    public const IMAGE_DIR = 'rental_categories';

    protected $fillable = ['name', 'type', 'description', 'show_on_website', 'slug', 'image', 'sort_order'];

    protected $casts = [
        'show_on_website' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(RentalItem::class, 'rental_category_id', 'id');
    }

    public function imageUrl(): ?string
    {
        return $this->image ? url(upload_path(self::IMAGE_DIR).'/'.$this->image) : null;
    }

    /** Website path segment, like RentalItem::publicKey(). */
    public function publicKey(): string
    {
        $slug = $this->slug ?: \Illuminate\Support\Str::slug($this->name);

        return ($slug !== '' ? $slug.'-' : '').$this->id;
    }
}
