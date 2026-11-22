<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Plan extends Model
{
    protected $connection = 'central';

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'slug',
        'price',
        'yearly_price',
        'billing_interval',
        'limits',
        'features',
        'retention',
        'is_active',
        'is_private',
        'is_trial',
        'trial_days',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'limits'       => 'array',
        'features'     => 'array',
        'retention'    => 'array',
        'is_active'    => 'boolean',
        'is_private'   => 'boolean',
        'is_trial'     => 'boolean',
        'trial_days'   => 'integer',
    ];

    /**
     * Add-on keys merged into this in-memory copy (withAddonFeatures), or
     * null on a real plan row. Such a copy must never be saved.
     *
     * @var string[]|null
     */
    public ?array $addonKeys = null;

    /** Set on a copy priced in another currency (PlanPricing::displayPlan). */
    public ?string $displayCurrency = null;

    protected static function booted(): void
    {
        // A tenant's effective plan (plan + bought add-ons) is a clone of the
        // shared plan row; saving it would grant the add-ons to every tenant
        // on that plan.
        static::saving(function (self $plan) {
            if ($plan->addonKeys !== null) {
                throw new \LogicException('An effective plan (plan + add-ons) cannot be saved.');
            }
            // A display copy priced in another currency (PlanPricing::displayPlan):
            // saving it would overwrite the main price with a converted one.
            if ($plan->displayCurrency !== null) {
                throw new \LogicException('A plan priced for display in another currency cannot be saved.');
            }
        });
    }

    /**
     * This plan as one tenant experiences it: its own features plus the
     * tenant's active add-ons. Returns $this unchanged when there are none.
     *
     * @param  string[]  $keys  add-on keys (feature keys or theme:<key>)
     * @param  array<string, int>  $limitBoosts  extra units per limit key (limit packs)
     */
    public function withAddonFeatures(array $keys, array $limitBoosts = []): static
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        $limitBoosts = array_filter(array_map('intval', $limitBoosts), fn ($n) => $n > 0);
        if ($keys === [] && $limitBoosts === []) {
            return $this;
        }

        $features = $this->features ?? [];
        // Map form (['pos' => true]) → list form, keeping only enabled keys.
        if (! array_is_list($features)) {
            $features = array_keys(array_filter($features, fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)));
        }

        $copy = clone $this;
        $copy->features = array_values(array_unique(array_merge($features, $keys)));
        $copy->addonKeys = array_values(array_diff($keys, $features));

        // Limit packs raise a capped limit; an unlimited one (-1 / unset) stays unlimited.
        if ($limitBoosts) {
            $limits = $this->limits ?? [];
            foreach ($limitBoosts as $limitKey => $units) {
                $base = $this->getLimit($limitKey);
                if ($base >= 0) {
                    $limits[$limitKey] = $base + $units;
                    $copy->addonLimits[$limitKey] = $units;
                }
            }
            $copy->limits = $limits;
        }

        return $copy;
    }

    /**
     * Extra units per limit key on this effective copy (limit packs).
     *
     * @var array<string, int>
     */
    public array $addonLimits = [];

    /** Whether a feature on this (effective) plan comes from a bought add-on. */
    public function featureFromAddon(string $key): bool
    {
        return in_array($key, $this->addonKeys ?? [], true);
    }

    /**
     * All configurable usage limits with their metadata.
     * Each key maps to a tenant-side Eloquent model for counting.
     *
     * `limits` vs `features` — the contract:
     *  - `limits` is a map of numeric quotas: ['max_products' => 500, ...].
     *    -1 (or a missing key) means unlimited. Anything countable or
     *    meterable belongs here (see getLimit()/hasReachedLimit()).
     *  - `features` is a flat list of enabled boolean flags: ['pos', 'hrm'].
     *    On/off capabilities belong there (see hasFeature()).
     *  WhatsApp is deliberately a quota (max_whatsapp_messages), not a
     *  feature flag: every plan may send, the plan only caps volume.
     */
    public const AVAILABLE_LIMITS = [
        'max_products'    => ['label' => 'Products',       'icon' => 'bi-box-seam',        'model' => \App\Models\Product::class],
        'max_users'       => ['label' => 'Users',          'icon' => 'bi-people',          'model' => \App\Models\User::class],
        'max_warehouses'  => ['label' => 'Warehouses',     'icon' => 'bi-building',        'model' => \App\Models\Warehouse::class],
        'max_customers'   => ['label' => 'Customers',      'icon' => 'bi-person-lines-fill', 'model' => \App\Models\Client::class],
        'max_suppliers'   => ['label' => 'Suppliers',      'icon' => 'bi-truck',           'model' => \App\Models\Provider::class],
        // Monthly-reset counter (not an all-time row count) — usage is tracked
        // via whatsapp_usages, so 'monthly' => true and no backing 'model'.
        'max_whatsapp_messages' => ['label' => 'WhatsApp Messages / month', 'icon' => 'bi-whatsapp', 'model' => null, 'monthly' => true],
        'max_courier_accounts' => ['label' => 'Courier Accounts', 'icon' => 'bi-truck', 'model' => \App\Models\CourierAccount::class],
        // Central table (custom_domains) — counted per tenant in TenantLimitsService, no tenant-DB model.
        'max_custom_domains' => ['label' => 'Custom Domains', 'icon' => 'bi-globe2', 'model' => null],
    ];

    /**
     * Backup and data-retention policy, stored in the `retention` JSON column.
     * A missing key takes its default, and the defaults change nothing: no
     * automatic backups and every record kept. Day values of 0 mean "keep
     * forever"; a non-zero value below 'min' is raised to it so a typo can
     * never wipe data that is still in use.
     *
     * What each data class covers is TenantDataPruner::CATEGORIES. Business
     * records — sales, purchases, payments, accounting, stock, customers,
     * tax-authority (ZATCA/FBR) submissions — belong to no class and are
     * never deleted.
     */
    public const BACKUP_FREQUENCIES = ['off', 'daily', 'weekly', 'monthly'];

    public const RETENTION_FIELDS = [
        'backup_frequency'       => ['default' => 'off'],
        'backup_retention_days'  => ['default' => 7, 'min' => 1, 'max' => 3650],
        'log_retention_days'     => ['default' => 0, 'min' => 7, 'max' => 3650],
        'audit_retention_days'   => ['default' => 0, 'min' => 30, 'max' => 3650],
        'message_retention_days' => ['default' => 0, 'min' => 7, 'max' => 3650],
        'temp_retention_days'    => ['default' => 0, 'min' => 1, 'max' => 3650],
    ];

    public const AVAILABLE_FEATURES = [
        'pos'                => ['label' => 'Point of Sale',          'icon' => 'bi-shop-window',      'description' => 'In-store POS terminal'],
        'online_orders'      => ['label' => 'Online Orders',          'icon' => 'bi-cart3',            'description' => 'E-commerce order management'],
        'hrm'                => ['label' => 'HR Management',          'icon' => 'bi-person-badge',     'description' => 'Employees, attendance, payroll'],
        'accounting'         => ['label' => 'Accounting',             'icon' => 'bi-calculator',       'description' => 'Financial accounting & bookkeeping'],
        'woocommerce'        => ['label' => 'WooCommerce',            'icon' => 'bi-bag-check',        'description' => 'WooCommerce store integration'],
        'shopify'            => ['label' => 'Shopify',                'icon' => 'bi-bag',              'description' => 'Shopify store integration'],
        'transfers'          => ['label' => 'Transfers',              'icon' => 'bi-arrow-left-right', 'description' => 'Stock transfers between warehouses'],
        'service_maintenance'=> ['label' => 'Service & Maintenance',  'icon' => 'bi-wrench',           'description' => 'Service & maintenance management'],
        'ai_reports'         => ['label' => 'AI Reports',             'icon' => 'bi-robot',            'description' => 'AI-powered analytics & reports'],
        'ai_assistant'       => ['label' => 'AI Assistant',           'icon' => 'bi-stars',            'description' => 'Chat with your business data, daily dashboard insights, AI product descriptions and supplier invoice scanning (tenant brings its own Claude, ChatGPT or Gemini API key)'],
        'promotions'         => ['label' => 'Promotions',             'icon' => 'bi-tag',              'description' => 'Discounts, promo codes & offers'],
        'contracts'          => ['label' => 'Contracts',              'icon' => 'bi-file-earmark-text','description' => 'Contracts, templates & renewals'],
        'projects'           => ['label' => 'Projects & Tasks',       'icon' => 'bi-kanban',           'description' => 'Projects, tasks & kanban boards'],
        'bookings'           => ['label' => 'Bookings',               'icon' => 'bi-calendar-check',   'description' => 'Appointments & booking calendar'],
        'assets'             => ['label' => 'Assets',                 'icon' => 'bi-pc-display',       'description' => 'Company assets tracking & maintenance'],
        'quotations'         => ['label' => 'Quotations',             'icon' => 'bi-file-earmark-ruled','description' => 'Customer quotations & estimates'],
        'commissions'        => ['label' => 'Commissions',            'icon' => 'bi-percent',          'description' => 'Sales agents & commission programs'],
        'recruitment'        => ['label' => 'Recruitment',            'icon' => 'bi-person-plus',      'description' => 'Jobs, candidates & interviews'],
        'meetings'           => ['label' => 'Meetings',               'icon' => 'bi-camera-video',     'description' => 'Meetings, calendar & attendance'],
        'marketing'          => ['label' => 'Marketing',              'icon' => 'bi-megaphone',        'description' => 'Campaigns, segments & activity'],
        'knowledge_base'     => ['label' => 'Knowledge Base',         'icon' => 'bi-journal-text',     'description' => 'Help articles & documentation'],
        'quickbooks'         => ['label' => 'QuickBooks',             'icon' => 'bi-cloud-arrow-up',   'description' => 'QuickBooks accounting sync'],
        'zapier'             => ['label' => 'Zapier',                 'icon' => 'bi-lightning',        'description' => 'Zapier automation (triggers & actions)'],
        'slack'              => ['label' => 'Slack',                  'icon' => 'bi-slack',            'description' => 'Slack notifications for sales, stock & payments'],
        'telegram'           => ['label' => 'Telegram',               'icon' => 'bi-telegram',         'description' => 'Telegram notifications for sales, stock & payments'],
        'zatca'              => ['label' => 'ZATCA E-Invoicing',      'icon' => 'bi-qr-code',          'description' => 'ZATCA Phase 2 (Fatoora) e-invoicing'],
        'fbr'                => ['label' => 'FBR Digital Invoicing',  'icon' => 'bi-receipt',          'description' => 'Pakistan FBR e-invoicing (PRAL DI)'],
        'agt'                => ['label' => 'AGT E-Invoicing (Angola)', 'icon' => 'bi-qr-code-scan',   'description' => 'Angola AGT electronic invoicing (series, FT/FR/NC, QR)'],
        'dgi'                => ['label' => 'DGI Facture Normalisée (DR Congo)', 'icon' => 'bi-file-earmark-check', 'description' => 'DR Congo DGI normalized invoice (NIF/RCCM, e-MCF)'],
        'webhooks'           => ['label' => 'Webhooks',               'icon' => 'bi-broadcast',        'description' => 'Outgoing webhooks & integrations'],
        'api'                => ['label' => 'REST API',               'icon' => 'bi-code-slash',       'description' => 'API keys for other systems: products, stock, purchases and sales lines'],
        'salla'              => ['label' => 'Salla',                  'icon' => 'bi-shop',             'description' => 'Salla store sync: products, stock & orders'],
        'xero'               => ['label' => 'Xero',                   'icon' => 'bi-calculator',       'description' => 'Xero accounting sync: contacts, invoices & payments'],
        'prestashop'         => ['label' => 'PrestaShop',             'icon' => 'bi-cart4',            'description' => 'PrestaShop store sync: products, stock & orders'],
        'opencart'           => ['label' => 'OpenCart',             'icon' => 'bi-cart3',            'description' => 'OpenCart store sync: products, stock & orders'],
        'google_sheets'      => ['label' => 'Google Sheets',          'icon' => 'bi-table',            'description' => 'Google Sheets exports: sales, products & customers'],
        'mailchimp'          => ['label' => 'Mailchimp',              'icon' => 'bi-envelope-heart',   'description' => 'Mailchimp audience sync for email marketing'],
        'couriers'           => ['label' => 'Courier Integration',    'icon' => 'bi-truck',            'description' => 'Book shipments with courier companies and track delivery, returns & COD'],
        'jumia'              => ['label' => 'Jumia',                  'icon' => 'bi-bag-check',        'description' => 'Jumia marketplace: price & stock push, order import'],
        'pos_terminals'      => ['label' => 'POS Devices (Card Terminals)', 'icon' => 'bi-credit-card-2-front', 'description' => 'Card terminals & fiscal cash registers (Ingenico IDE280) on the POS: charge, refund & void on the device'],
        'printful'           => ['label' => 'Printful',               'icon' => 'bi-printer',          'description' => 'Printful print-on-demand: product import, order fulfilment & tracking'],
        'documents'          => ['label' => 'Document Archive',       'icon' => 'bi-folder2-open',     'description' => 'Central store for business documents'],
        'manufacturing'      => ['label' => 'Manufacturing (MRP)',    'icon' => 'bi-gear-wide-connected', 'description' => 'Bills of materials, production orders, work centres, quality & planning'],
        'fleet'              => ['label' => 'Fleet Management',       'icon' => 'bi-truck',            'description' => 'Vehicles, maintenance, fuel logs & driver assignments'],
        'hospital'           => ['label' => 'Hospital Management',    'icon' => 'bi-hospital',         'description' => 'Patients, appointments, consultations, admissions, lab & billing'],
        'school'             => ['label' => 'School Management',      'icon' => 'bi-mortarboard',      'description' => 'Students, enrolment, attendance, timetable, exams & fees'],
        'protocol'           => ['label' => 'Protocol & Requests',    'icon' => 'bi-inbox',            'description' => 'Internal messaging, complaints, memos & desk-to-desk request tracking'],
        'audit_log'          => ['label' => 'Audit Log',              'icon' => 'bi-clipboard-check',  'description' => 'Who changed what and when — every create, edit, delete and sign-in, with before/after values'],
        'restaurant'         => ['label' => 'Restaurant Management',  'icon' => 'bi-cup-hot',          'description' => 'Floor plan & tables, waiter orders, kitchen tickets, add-ons, bills, split payments & tips'],
        'rental'             => ['label' => 'Rental Management',      'icon' => 'bi-key',              'description' => 'Rental items, contracts, reservations, invoicing, deposits, handovers & maintenance'],
        'crm'                => ['label' => 'CRM: Leads & Sales Pipeline', 'icon' => 'bi-funnel',      'description' => 'Leads, deals pipeline, activities, web forms, inbox, targets & sales reports'],
        'client_portal'      => ['label' => 'Client Portal',          'icon' => 'bi-person-workspace', 'description' => 'Customer self-service portal: invoices, payments, statements, requests & help centre'],
        'remove_branding'    => ['label' => 'White-label (Remove Branding)', 'icon' => 'bi-stars',    'description' => 'Replace the platform footer and "developed by" credit with your own'],
        'custom_domains'     => ['label' => 'Custom Domains',         'icon' => 'bi-globe2',           'description' => 'Serve the workspace, storefront and portal from the tenant\'s own domain (shop.example.com) with DNS verification'],
        'supplier_wallet'    => ['label' => 'Supplier Wallet',        'icon' => 'bi-wallet2',          'description' => 'Prepaid balances held with suppliers: advances, return credits, pay purchases from the wallet, wallet ledger'],
        'customer_wallet'    => ['label' => 'Customer Wallet',        'icon' => 'bi-wallet',           'description' => 'Stored-value balance per customer: top-ups, refunds to wallet, pay sales / POS / online orders from it, gift cards, withdrawal requests'],
        'license_keys'       => ['label' => 'Software License Keys',  'icon' => 'bi-key-fill',         'description' => 'Sell software licenses: upload third-party keys or generate your own with expiry, activation limits, domain/device locks and a licensing API'],
        'premium_themes'     => ['label' => 'Premium Store Themes',   'icon' => 'bi-gem',              'description' => 'Unlocks the premium online store templates (Electronics Premium: mega menu, daily deals, spec filters, compare, bundles)'],
    ];

    /**
     * AVAILABLE_LIMITS with labels resolved through the active locale.
     *
     * The constant above has to stay a plain literal (PHP constants cannot
     * call __()), so every display path goes through this helper instead of
     * reading the constant directly. Keys, icons and models are untouched —
     * only 'label' is swapped for its translation, falling back to the
     * English literal when a locale has no entry for that key.
     */
    public static function availableLimits(): array
    {
        $result = [];

        foreach (self::AVAILABLE_LIMITS as $key => $meta) {
            $labelKey = 'super.plans.limit_labels.' . $key;

            if (__($labelKey) !== $labelKey) {
                $meta['label'] = __($labelKey);
            }

            $result[$key] = $meta;
        }

        return $result;
    }

    /**
     * AVAILABLE_FEATURES with labels and descriptions resolved through the
     * active locale. See availableLimits() for why this helper exists.
     */
    public static function availableFeatures(): array
    {
        $result = [];

        foreach (self::AVAILABLE_FEATURES as $key => $meta) {
            $labelKey = 'super.plans.feature_labels.' . $key;
            $descKey  = 'super.plans.feature_descriptions.' . $key;

            if (__($labelKey) !== $labelKey) {
                $meta['label'] = __($labelKey);
            }

            if (__($descKey) !== $descKey) {
                $meta['description'] = __($descKey);
            }

            $result[$key] = $meta;
        }

        return $result;
    }

    /**
     * Per-request cache of "does this table have a deleted_at column?",
     * keyed by connection + table. Tenant databases all share the same
     * migrations, so the answer is safe to reuse across tenants.
     */
    protected static array $softDeleteColumnCache = [];

    /**
     * Current usage for a limit key, counted against the tenant database
     * that is currently connected.
     *
     * Tenant models soft-delete by hand — a nullable `deleted_at` column and
     * no SoftDeletes trait — so a plain ::count() keeps charging the tenant
     * for rows they already deleted (e.g. a deleted warehouse still filling
     * the max_warehouses quota). Deleted rows are excluded here.
     *
     * Returns 0 for keys with no backing model (monthly counters, unknown keys).
     */
    public static function countUsage(string $key): int
    {
        $meta = self::AVAILABLE_LIMITS[$key] ?? null;

        if (! $meta || empty($meta['model']) || ! class_exists($meta['model'])) {
            return 0;
        }

        $model = new $meta['model'];
        $query = $model->newQuery();

        if (self::tableHasSoftDeleteColumn($model)) {
            $query->whereNull($model->getTable() . '.deleted_at');
        }

        // A limit added with a new module counts 0 on tenants whose database
        // has not been migrated yet, instead of failing every usage figure.
        try {
            return $query->count();
        } catch (\Illuminate\Database\QueryException $e) {
            return 0;
        }
    }

    protected static function tableHasSoftDeleteColumn(Model $model): bool
    {
        $connection = $model->getConnectionName();
        $cacheKey = ($connection ?? 'default') . '.' . $model->getTable();

        if (! array_key_exists($cacheKey, self::$softDeleteColumnCache)) {
            try {
                self::$softDeleteColumnCache[$cacheKey] = Schema::connection($connection)
                    ->hasColumn($model->getTable(), 'deleted_at');
            } catch (\Throwable $e) {
                self::$softDeleteColumnCache[$cacheKey] = false;
            }
        }

        return self::$softDeleteColumnCache[$cacheKey];
    }

    public function tenantSubscriptions()
    {
        return $this->hasMany(TenantSubscription::class, 'plan_id');
    }

    /**
     * Scope: only plans visible to the public (active + not private).
     */
    public function scopePublic($query)
    {
        return $query->where('is_active', true)->where('is_private', false);
    }

    /**
     * Scope: plans a tenant may see and buy — public plans, plus the plan of
     * the tenant's latest real subscription even when it is private, so a
     * tenant placed on a private plan can still renew it after it expires.
     * Pending/failed rows are tenant-initiated requests and don't count.
     */
    public function scopeAvailableToTenant($query, $tenantId)
    {
        $ownPlanId = $tenantId
            ? TenantSubscription::where('tenant_id', $tenantId)
                ->whereNotIn('status', [TenantSubscription::STATUS_PENDING, TenantSubscription::STATUS_FAILED])
                ->latest()
                ->value('plan_id')
            : null;

        return $query->where('is_active', true)
            ->where(function ($q) use ($ownPlanId) {
                $q->where('is_private', false);
                if ($ownPlanId) {
                    $q->orWhere('id', $ownPlanId);
                }
            });
    }

    /**
     * A plan is "free" when both monthly and yearly prices are zero.
     */
    public function isFree(): bool
    {
        return (float) $this->price <= 0
            && (float) ($this->yearly_price ?? 0) <= 0;
    }

    /**
     * Whether this plan offers a free trial period.
     */
    public function hasTrial(): bool
    {
        return ! $this->isFree() && $this->is_trial && $this->getTrialDays() > 0;
    }

    /**
     * Get the number of trial days for this plan.
     * Falls back to global config if per-plan value is not set.
     */
    public function getTrialDays(): int
    {
        if ($this->trial_days > 0) {
            return $this->trial_days;
        }

        return (int) config('tenancy.trial_days', 0);
    }

    /**
     * Whether this plan requires payment before provisioning.
     */
    public function requiresPayment(): bool
    {
        return ! $this->isFree() && ! $this->hasTrial();
    }

    /**
     * Get a specific limit value, or the default if not set.
     * Returns -1 for "unlimited".
     */
    public function getLimit(string $key, int $default = -1): int
    {
        $limits = $this->limits ?? [];

        if (!isset($limits[$key]) || $limits[$key] === '' || $limits[$key] === null) {
            return $default;
        }

        return (int) $limits[$key];
    }

    /**
     * Check if a boolean feature flag is enabled on this plan.
     *
     * Canonical storage is a flat list of enabled keys (['pos', 'hrm']),
     * which is what the seeder and the super-admin plan form produce. The
     * map form (['pos' => true]) is tolerated so a manually edited or
     * imported row degrades gracefully instead of silently disabling
     * every feature. Numeric quotas do NOT belong here — use getLimit().
     */
    public function hasFeature(string $key): bool
    {
        $features = $this->features ?? [];

        if (in_array($key, $features, true)) {
            return true;
        }

        return array_key_exists($key, $features)
            && filter_var($features[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * One retention setting, with its default when the plan doesn't set it.
     * See RETENTION_FIELDS.
     */
    public function retentionSetting(string $key): int|string
    {
        $value = ($this->retention ?? [])[$key] ?? self::RETENTION_FIELDS[$key]['default'];

        return $key === 'backup_frequency' ? (string) $value : (int) $value;
    }

    /** True when the plan schedules automatic backups. */
    public function backsUpAutomatically(): bool
    {
        return in_array($this->retentionSetting('backup_frequency'), ['daily', 'weekly', 'monthly'], true);
    }

    /**
     * Normalise submitted retention settings: unknown keys dropped, frequency
     * whitelisted, days clamped to [min, max] with 0 kept as "forever"
     * (backups always keep at least one day).
     */
    public static function sanitizeRetention(array $input): array
    {
        $clean = [];

        foreach (self::RETENTION_FIELDS as $key => $meta) {
            if ($key === 'backup_frequency') {
                $value = (string) ($input[$key] ?? $meta['default']);
                $clean[$key] = in_array($value, self::BACKUP_FREQUENCIES, true) ? $value : 'off';

                continue;
            }

            $raw = $input[$key] ?? null;
            $days = ($raw === null || $raw === '') ? (int) $meta['default'] : max(0, (int) $raw);

            if ($days === 0 && $key === 'backup_retention_days') {
                $days = (int) $meta['default'];
            }
            if ($days !== 0) {
                $days = min((int) $meta['max'], max((int) $meta['min'], $days));
            }

            $clean[$key] = $days;
        }

        return $clean;
    }

    /**
     * Check if a specific limit is set (not unlimited).
     */
    public function hasLimit(string $key): bool
    {
        return $this->getLimit($key) > 0;
    }

    /**
     * Get all configured limits as a formatted array.
     */
    public function getFormattedLimits(): array
    {
        $result = [];

        foreach (self::availableLimits() as $key => $meta) {
            $value = $this->getLimit($key);
            $result[$key] = [
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'value' => $value,
                'display' => $value < 0 ? __('super.common.unlimited') : number_format($value),
            ];
        }

        return $result;
    }

    /**
     * Get active feature keys.
     */
    public function getActiveFeatures(): array
    {
        $result = [];

        foreach (self::availableFeatures() as $key => $meta) {
            if ($this->hasFeature($key)) {
                $result[$key] = $meta;
            }
        }

        return $result;
    }

    /**
     * Count how many limits are configured (not unlimited).
     */
    public function getConfiguredLimitsCount(): int
    {
        $count = 0;

        foreach (array_keys(self::AVAILABLE_LIMITS) as $key) {
            if ($this->getLimit($key) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the price for a given billing cycle.
     * Falls back to monthly * 12 if yearly_price is not set.
     */
    public function getPriceForCycle(string $cycle = 'monthly'): float
    {
        if ($cycle === 'yearly') {
            $yearly = (float) ($this->yearly_price ?? 0);

            // A paid plan with no yearly price is a misconfiguration, not a
            // free year. Fall back to 12x monthly exactly as a null does —
            // otherwise a crafted ?cycle=yearly buys a year for nothing.
            // A genuinely free plan still prices at 0 because price is 0.
            return $yearly > 0 ? $yearly : (float) $this->price * 12;
        }

        return (float) $this->price;
    }

    /**
     * Monthly savings percentage when paying yearly.
     */
    public function getYearlySavingsPercent(): int
    {
        $monthly12 = (float) $this->price * 12;
        $yearly = (float) ($this->yearly_price ?? $monthly12);

        if ($monthly12 <= 0) {
            return 0;
        }

        return (int) round((($monthly12 - $yearly) / $monthly12) * 100);
    }
}
