<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class SizeGuide extends Model
{
    use SoftDeletes;

    protected $table = 'size_guides';

    protected $fillable = [
        'name', 'image', 'columns', 'rows', 'status',
    ];

    protected $casts = [
        'columns' => 'array',
        'rows' => 'array',
        'status' => 'boolean',
    ];

    /** @var array<string, bool> tenant DB name → table exists */
    private static array $ready = [];

    /**
     * Whether the size_guides migration has run on the current tenant DB.
     * Readers (product form, storefront) no-op until it has.
     */
    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('size_guides') && Schema::hasColumn('products', 'size_guide_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'size_guide_id');
    }

    /**
     * `columns` / `rows` as a real array, tolerating a value still held as a
     * JSON string (older rows, direct SQL edits, imports).
     */
    public function chart(string $part): array
    {
        $value = $this->{$part};

        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** A typed matrix needs both headers and at least one row to be useful. */
    public function hasTable(): bool
    {
        return count($this->chart('columns')) > 0 && count($this->chart('rows')) > 0;
    }

    /**
     * Whether there is anything to show a shopper. Many guides are just an
     * uploaded chart image with no typed matrix — that image IS the chart.
     */
    public function hasChart(): bool
    {
        return $this->hasTable() || (bool) $this->image;
    }

    /** Public URL of the chart image (tenant upload folder), or null. */
    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $this->image) || str_starts_with($this->image, '/')) {
            return $this->image;
        }

        return url(upload_path('size_guides').'/'.$this->image);
    }
}
