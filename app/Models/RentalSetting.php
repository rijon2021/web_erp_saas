<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Rental module's single settings row. Always read it through current(),
 * which creates the row with the column defaults on first use.
 */
class RentalSetting extends Model
{
    protected $table = 'rental_settings';

    protected $fillable = [
        'item_code_prefix', 'contract_prefix', 'reservation_prefix', 'invoice_prefix',
        'grace_days', 'late_fee_type', 'late_fee_value', 'late_fee_frequency',
        'default_deposit', 'buffer_hours', 'default_hold_hours', 'expiring_soon_days', 'reminder_days_before', 'invoice_lead_days',
        'agreement_terms', 'checkout_checklist', 'checkin_checklist',
        'reminders_enabled', 'reminder_channels', 'overdue_reminder_every_days', 'expiry_reminder_days', 'reminder_templates',
        // Business type + website booking (migration 2026_10_25_000001).
        'business_types', 'website_booking', 'website_hold_hours', 'website_min_notice_hours', 'website_max_days_ahead',
        'website_require_login', 'website_fields', 'pickup_locations', 'website_terms',
    ];

    protected $casts = [
        'grace_days' => 'integer',
        'late_fee_value' => 'decimal:2',
        'default_deposit' => 'decimal:2',
        'buffer_hours' => 'integer',
        'default_hold_hours' => 'integer',
        'expiring_soon_days' => 'integer',
        'reminder_days_before' => 'integer',
        'invoice_lead_days' => 'integer',
        'checkout_checklist' => 'array',
        'checkin_checklist' => 'array',
        'reminders_enabled' => 'boolean',
        'reminder_channels' => 'array',
        'overdue_reminder_every_days' => 'integer',
        'expiry_reminder_days' => 'integer',
        'reminder_templates' => 'array',
        'business_types' => 'array',
        'website_booking' => 'boolean',
        'website_hold_hours' => 'integer',
        'website_min_notice_hours' => 'integer',
        'website_max_days_ahead' => 'integer',
        'website_require_login' => 'boolean',
        'website_fields' => 'array',
        'pickup_locations' => 'array',
    ];

    /** @var array<string, bool> per-tenant memo for websiteReady() */
    private static array $websiteReady = [];

    /** True once the business-type / website columns exist (migration 2026_10_25_000001). */
    public static function websiteReady(): bool
    {
        $key = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : '_';
        if (! isset(self::$websiteReady[$key])) {
            try {
                self::$websiteReady[$key] = \Illuminate\Support\Facades\Schema::hasColumn('rental_settings', 'business_types')
                    && \Illuminate\Support\Facades\Schema::hasColumn('rental_reservations', 'source')
                    && \Illuminate\Support\Facades\Schema::hasColumn('rental_items', 'specs');
            } catch (\Throwable $e) {
                self::$websiteReady[$key] = false;
            }
        }

        return self::$websiteReady[$key];
    }

    public static function forgetWebsiteReady(): void
    {
        self::$websiteReady = [];
    }

    public const DEFAULT_CHECKOUT_CHECKLIST = [
        'Customer ID checked', 'Item condition shown to the customer', 'Accessories and manuals included',
        'Deposit collected', 'Agreement signed',
    ];

    public const DEFAULT_CHECKIN_CHECKLIST = [
        'All parts and accessories returned', 'No new damage', 'Item cleaned', 'Meter reading recorded',
    ];

    /**
     * Default reminder wording. {placeholders}: customer_name, contract_number,
     * invoice_number, amount, due_date, days_late, end_date, items,
     * company_name, company_phone.
     */
    public const DEFAULT_REMINDER_TEMPLATES = [
        'rent_due' => [
            'subject' => 'Rent due on {due_date} — {contract_number}',
            'body' => "Hello {customer_name},\n\nThis is a reminder that {amount} for your rental {contract_number} (invoice {invoice_number}) is due on {due_date}.\n\nThank you,\n{company_name} {company_phone}",
        ],
        'rent_overdue' => [
            'subject' => 'Overdue payment — {invoice_number}',
            'body' => "Hello {customer_name},\n\nYour payment of {amount} for rental {contract_number} (invoice {invoice_number}) was due on {due_date} and is now {days_late} day(s) late. Please pay as soon as possible to avoid further late fees.\n\n{company_name} {company_phone}",
        ],
        'contract_expiring' => [
            'subject' => 'Your rental {contract_number} ends on {end_date}',
            'body' => "Hello {customer_name},\n\nYour rental {contract_number} ({items}) ends on {end_date}. Contact us if you would like to renew it.\n\n{company_name} {company_phone}",
        ],
        'return_due' => [
            'subject' => 'Return due tomorrow — {contract_number}',
            'body' => "Hello {customer_name},\n\nA reminder that {items} rented under {contract_number} must be returned by {end_date}.\n\nThank you,\n{company_name} {company_phone}",
        ],
    ];

    /** Saved template for a reminder type, falling back to the default per field. */
    public function reminderTemplate(string $type): array
    {
        $saved = (array) (($this->reminder_templates ?? [])[$type] ?? []);
        $default = self::DEFAULT_REMINDER_TEMPLATES[$type] ?? ['subject' => '', 'body' => ''];

        return [
            'subject' => trim((string) ($saved['subject'] ?? '')) ?: $default['subject'],
            'body' => trim((string) ($saved['body'] ?? '')) ?: $default['body'],
        ];
    }

    /** The configured list for a handover type, or the defaults. */
    public function checklistFor(string $type): array
    {
        $list = $type === 'checkout' ? $this->checkout_checklist : $this->checkin_checklist;

        return is_array($list) && $list ? array_values($list)
            : ($type === 'checkout' ? self::DEFAULT_CHECKOUT_CHECKLIST : self::DEFAULT_CHECKIN_CHECKLIST);
    }

    public static function current(): self
    {
        $setting = static::query()->orderBy('id')->first();

        if (! $setting) {
            $setting = static::create([]);
            $setting->refresh();
        }

        return $setting;
    }
}
