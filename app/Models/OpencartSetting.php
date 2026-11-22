<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OpencartSetting extends Model
{
    /** Which OpenCart field is the Stocky product code: the model, or one of the identifier fields. */
    public const CODE_SOURCE_MODEL = 'model';
    public const CODE_SOURCE_SKU = 'sku';

    /** The identifier fields the connector reports beside the model (connector 1.1.0 sends all; 1.0.0 the SKU only). */
    public const CODE_FIELDS = ['sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'];
    public const CODE_SOURCES = ['model', 'sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'];

    protected $table = 'opencart_settings';

    protected $fillable = [
        'enabled', 'store_url', 'secret', 'store_id', 'warehouse_id', 'code_source', 'connector_link',
        'auto_sync_products', 'auto_sync_stock', 'auto_import_orders',
        'commit_status_ids', 'paid_status_ids', 'cancel_status_ids',
        'webhook_token', 'orders_cursor', 'orders_retry', 'connector_version', 'opencart_version',
        'last_sync_at', 'last_webhook_at', 'last_stock_reconcile_at',
    ];

    protected $hidden = ['secret', 'webhook_token'];

    // The columns' defaults: firstOrCreate() hands back a row built here, not
    // read from the database, and the settings page saves what it was shown.
    protected $attributes = [
        'code_source' => self::CODE_SOURCE_MODEL,
        'auto_sync_stock' => true,
        'auto_import_orders' => true,
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'secret' => 'encrypted',
        'store_id' => 'integer',
        'warehouse_id' => 'integer',
        'auto_sync_products' => 'boolean',
        'auto_sync_stock' => 'boolean',
        'auto_import_orders' => 'boolean',
        'commit_status_ids' => 'array',
        'paid_status_ids' => 'array',
        'cancel_status_ids' => 'array',
        'orders_retry' => 'array',
        'last_sync_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'last_stock_reconcile_at' => 'datetime',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** The tenant's company name, which is what the connector is called. */
    public static function companyName(): string
    {
        $name = strip_tags((string) Setting::whereNull('deleted_at')->value('CompanyName'));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));

        return $name !== '' ? $name : 'POS';
    }

    public static function connectorName(): string
    {
        return self::companyName().' Connector';
    }

    /**
     * Where OpenCart's Installer page sends admins who click the connector's
     * name: the tenant's own website, or this platform when none is saved.
     */
    public function connectorLink(): string
    {
        $link = trim((string) $this->connector_link);

        return $link !== '' ? $link : rtrim(url('/'), '/');
    }

    /** 'model' (the default) or 'sku'; anything else saved falls back to model. */
    public function codeSource(): string
    {
        return in_array($this->code_source, self::CODE_SOURCES, true) ? $this->code_source : self::CODE_SOURCE_MODEL;
    }

    public function codeFromModel(): bool
    {
        return $this->codeSource() === self::CODE_SOURCE_MODEL;
    }

    /**
     * The remote values that may be the local code, preferred one first and
     * blanks dropped. Under the model setting: model, then SKU. Under any
     * other setting: the chosen field ALONE when it has a value (falling back
     * to the model would match a product to an old one whose code is a
     * category name), else model, then SKU.
     *
     * @param  array<string, string>  $codes  identifier fields as remoteCodes() returns them
     * @return string[]
     */
    public function codeCandidates(string $model, array $codes): array
    {
        $source = $this->codeSource();
        $model = trim($model);
        $sku = trim((string) ($codes['sku'] ?? ''));

        if ($source === self::CODE_SOURCE_MODEL) {
            $ordered = [$model, $sku];
        } else {
            $preferred = trim((string) ($codes[$source] ?? ''));
            $ordered = $preferred !== '' ? [$preferred] : [$model, $sku];
        }

        return array_values(array_unique(array_filter($ordered, fn ($c) => $c !== '')));
    }

    /** The local code for a remote product or variant: the preferred field, else the fallbacks, else ''. */
    public function codeOf(string $model, array $codes): string
    {
        return $this->codeCandidates($model, $codes)[0] ?? '';
    }

    /**
     * The identifiers in a connector product, variant or order line, every
     * field present: its `codes` map (connector 1.1.0), or the older `sku` key.
     *
     * @return array<string, string>
     */
    public static function remoteCodes(array $remote): array
    {
        $given = (array) ($remote['codes'] ?? []);
        $codes = [];
        foreach (self::CODE_FIELDS as $field) {
            $codes[$field] = trim((string) ($given[$field] ?? ''));
        }
        if ($codes['sku'] === '') {
            $codes['sku'] = trim((string) ($remote['sku'] ?? ''));
        }

        return $codes;
    }

    /**
     * The identifiers to push for a local code: the chosen field, or the SKU
     * under the model setting (the code has always been written there too).
     *
     * @return array<string, string>
     */
    public function pushCodes(string $code): array
    {
        if ($code === '') {
            return [];
        }

        return [$this->codeFromModel() ? self::CODE_SOURCE_SKU : $this->codeSource() => $code];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->store_url) && ! empty($this->secret);
    }

    /** The token in the webhook URL, created the first time it is needed. */
    public function webhookToken(): string
    {
        if (empty($this->webhook_token)) {
            $this->webhook_token = Str::random(48);
            $this->save();
        }

        return (string) $this->webhook_token;
    }
}
