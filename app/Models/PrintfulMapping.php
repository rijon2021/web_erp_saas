<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintfulMapping extends Model
{
    /** products.id <-> Printful sync product id */
    public const TYPE_PRODUCT = 'product';

    /** product_variants.id <-> Printful sync variant id */
    public const TYPE_VARIANT = 'variant';

    /** products.id (simple product) <-> its only Printful sync variant id */
    public const TYPE_SINGLE = 'single';

    protected $table = 'printful_mappings';

    protected $fillable = ['entity_type', 'local_id', 'printful_id', 'extra', 'synced_at'];

    protected $casts = [
        'local_id' => 'integer',
        'printful_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Upsert a mapping, merging `extra` into any existing row. A Printful
     * record links to one local record: an older link (e.g. to a product
     * deleted here and imported again) is dropped.
     */
    public static function put(string $type, int $localId, int $printfulId, ?array $extra = null): self
    {
        static::where('entity_type', $type)->where('printful_id', $printfulId)->where('local_id', '!=', $localId)->delete();

        $mapping = static::firstOrNew(['entity_type' => $type, 'local_id' => $localId]);
        $mapping->printful_id = $printfulId;
        if ($extra !== null) {
            $mapping->extra = array_merge($mapping->extra ?? [], $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    public static function localId(string $type, int $printfulId): ?int
    {
        $value = static::where('entity_type', $type)->where('printful_id', $printfulId)->value('local_id');

        return $value !== null ? (int) $value : null;
    }

    /**
     * The Printful sync variant behind an order / cart line, or null when
     * the line is not a Printful product (the store ships it itself).
     */
    public static function forLine(int $productId, ?int $variantId): ?self
    {
        return $variantId
            ? static::where('entity_type', self::TYPE_VARIANT)->where('local_id', $variantId)->first()
            : static::where('entity_type', self::TYPE_SINGLE)->where('local_id', $productId)->first();
    }
}
