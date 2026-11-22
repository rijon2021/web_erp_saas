<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpencartMapping extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_VARIANT = 'variant';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_ORDER = 'order';

    protected $table = 'opencart_mappings';

    protected $fillable = ['entity_type', 'local_id', 'opencart_id', 'extra', 'synced_at'];

    protected $casts = [
        'local_id' => 'integer',
        'opencart_id' => 'integer',
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Upsert a mapping, merging `extra` into any existing row. A link is one
     * to one, so another local row holding this OpenCart id gives it up first
     * (a product re-created under a new id, or linked by hand).
     */
    public static function put(string $type, int $localId, int $opencartId, ?array $extra = null): self
    {
        static::where('entity_type', $type)
            ->where('opencart_id', $opencartId)
            ->where('local_id', '!=', $localId)
            ->delete();

        $mapping = static::firstOrNew(['entity_type' => $type, 'local_id' => $localId]);
        $mapping->opencart_id = $opencartId;
        if ($extra !== null) {
            $mapping->extra = array_merge((array) $mapping->extra, $extra);
        }
        $mapping->synced_at = now();
        $mapping->save();

        return $mapping;
    }

    /** The `extra` stored on a local row's link, [] when not linked. */
    public static function extraOf(string $type, int $localId): array
    {
        $extra = static::where('entity_type', $type)->where('local_id', $localId)->value('extra');

        return is_array($extra) ? $extra : (array) json_decode((string) $extra, true);
    }

    public static function localId(string $type, int $opencartId): ?int
    {
        $id = static::where('entity_type', $type)->where('opencart_id', $opencartId)->value('local_id');

        return $id === null ? null : (int) $id;
    }

    public static function opencartId(string $type, int $localId): ?int
    {
        $id = static::where('entity_type', $type)->where('local_id', $localId)->value('opencart_id');

        return $id === null ? null : (int) $id;
    }
}
