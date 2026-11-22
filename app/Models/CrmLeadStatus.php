<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A lead status. `kind` gives it meaning beyond its name: open (still being
 * worked), converted (became a client) or lost (dropped, with a reason).
 */
class CrmLeadStatus extends Model
{
    use SoftDeletes;

    public const KINDS = ['open', 'converted', 'lost'];

    protected $table = 'crm_lead_statuses';

    protected $fillable = ['name', 'color', 'kind', 'is_default', 'sort_order'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Status a new lead starts in: the flagged default, else the first open one. */
    public static function defaultId(): ?int
    {
        return static::query()->where('is_default', true)->orderBy('sort_order')->value('id')
            ?? static::query()->where('kind', 'open')->orderBy('sort_order')->value('id');
    }

    /** First status of a kind (used when a lead is marked lost / converted). */
    public static function firstOfKind(string $kind): ?self
    {
        return static::query()->where('kind', $kind)->orderBy('sort_order')->first();
    }
}
