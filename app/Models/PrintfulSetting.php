<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrintfulSetting extends Model
{
    public const SUBMIT_CONFIRMED = 'confirmed';
    public const SUBMIT_PAID = 'paid';
    public const SUBMIT_MANUAL = 'manual';

    protected $table = 'printful_settings';

    /** Same as the column defaults, so a row made by current() reads right before a reload. */
    protected $attributes = [
        'enabled' => false,
        'submit_on' => self::SUBMIT_CONFIRMED,
        'auto_confirm' => false,
        'sync_costs' => true,
        'update_prices' => false,
        'mark_shipped' => true,
    ];

    protected $fillable = [
        'enabled', 'api_token', 'store_id', 'store_name', 'currency', 'exchange_rate',
        'submit_on', 'auto_confirm', 'sync_costs', 'update_prices', 'mark_shipped',
        'category_id', 'auto_since', 'webhook_token', 'webhook_registered_at', 'last_sync_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'store_id' => 'integer',
        'exchange_rate' => 'float',
        'auto_confirm' => 'boolean',
        'sync_costs' => 'boolean',
        'update_prices' => 'boolean',
        'mark_shipped' => 'boolean',
        'category_id' => 'integer',
        'auto_since' => 'datetime',
        'webhook_registered_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    /**
     * The single settings row. A fixed id keeps two parallel first page loads
     * from creating two rows, and the webhook token exists from the start.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], ['webhook_token' => Str::random(48)]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->api_token);
    }

    /** Enabled, holding a token and a chosen store: orders and rates may use it. */
    public function isReady(): bool
    {
        return $this->enabled && $this->isConfigured() && ! empty($this->store_id);
    }

    /** Orders are sent without anyone pressing Send. */
    public function sendsAutomatically(): bool
    {
        return $this->enabled && ($this->submit_on ?: self::SUBMIT_CONFIRMED) !== self::SUBMIT_MANUAL;
    }

    /** The secret path segment Printful posts webhooks to, created on demand. */
    public function webhookToken(): string
    {
        if (empty($this->webhook_token)) {
            $this->webhook_token = Str::random(48);
            $this->save();
        }

        return $this->webhook_token;
    }

    public function webhookUrl(): string
    {
        return url('/api/printful/webhook/'.$this->webhookToken());
    }
}
