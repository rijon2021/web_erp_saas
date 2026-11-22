<?php

namespace App\Models;

use App\Services\Couriers\CourierStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-wide courier behaviour: what happens to returned parcels, COD
 * remittances and courier charges, how often statuses are polled, which
 * status changes the customer is told about, the public tracking page and
 * the return-risk thresholds.
 */
class CourierSetting extends Model
{
    public const RETURN_ACTIONS = ['review', 'restock'];

    /** Statuses a customer can be notified about, and the ones on by default. */
    public const NOTIFY_STATUSES = [
        CourierStatus::BOOKED, CourierStatus::PICKED_UP, CourierStatus::IN_TRANSIT, CourierStatus::OUT_FOR_DELIVERY,
        CourierStatus::FAILED_ATTEMPT, CourierStatus::DELIVERED, CourierStatus::RETURNING,
    ];

    public const DEFAULT_NOTIFY = [
        CourierStatus::BOOKED, CourierStatus::OUT_FOR_DELIVERY, CourierStatus::FAILED_ATTEMPT, CourierStatus::DELIVERED,
    ];

    /** Placeholders: {contact_name} {business_name} {invoice_number} {courier} {tracking_number} {tracking_url} {cod_amount} {cod_note} */
    public const DEFAULT_TEMPLATES = [
        CourierStatus::BOOKED => 'Hi {contact_name}, your order {invoice_number} from {business_name} has been handed to {courier}. Tracking number: {tracking_number}. {tracking_url}',
        CourierStatus::PICKED_UP => 'Hi {contact_name}, {courier} has picked up your order {invoice_number}. {tracking_url}',
        CourierStatus::IN_TRANSIT => 'Hi {contact_name}, your order {invoice_number} is on its way. {tracking_url}',
        CourierStatus::OUT_FOR_DELIVERY => 'Hi {contact_name}, your order {invoice_number} is out for delivery today.{cod_note} {tracking_url}',
        CourierStatus::FAILED_ATTEMPT => 'Hi {contact_name}, {courier} could not deliver your order {invoice_number} today. Please contact {business_name} to arrange delivery.',
        CourierStatus::DELIVERED => 'Hi {contact_name}, your order {invoice_number} has been delivered. Thank you for shopping with {business_name}!',
        CourierStatus::RETURNING => 'Hi {contact_name}, your order {invoice_number} is being returned to {business_name}. Contact us if you still want it.',
    ];

    protected $table = 'courier_settings';

    protected $fillable = [
        'return_action', 'cod_auto_record', 'cod_account_id', 'cod_payment_method_id',
        'charges_as_expense', 'charges_expense_category_id', 'sync_interval_minutes',
        'notify_sms', 'notify_email', 'notify_whatsapp', 'notify_statuses', 'notify_templates',
        'tracking_page', 'risk_high_percent', 'risk_min_shipments',
    ];

    protected $casts = [
        'cod_auto_record' => 'boolean',
        'cod_account_id' => 'integer',
        'cod_payment_method_id' => 'integer',
        'charges_as_expense' => 'boolean',
        'charges_expense_category_id' => 'integer',
        'sync_interval_minutes' => 'integer',
        'notify_sms' => 'boolean',
        'notify_email' => 'boolean',
        'notify_whatsapp' => 'boolean',
        'notify_statuses' => 'array',
        'notify_templates' => 'array',
        'tracking_page' => 'boolean',
        'risk_high_percent' => 'integer',
        'risk_min_shipments' => 'integer',
    ];

    /** The single per-tenant settings row. */
    public static function current(): self
    {
        $row = static::query()->first();

        return $row ?: static::query()->create([
            'return_action' => 'review',
            'cod_auto_record' => true,
            'sync_interval_minutes' => 60,
        ]);
    }

    /** Phase 7 columns exist (the tenant has run the 2026_10_07 migration). */
    public static function hasPhase7Columns(): bool
    {
        // Only "yes" is remembered: a tenant that migrates mid-process is seen at once.
        static $has = [];
        $key = tenant('id') ?? 'central';

        return ! empty($has[$key]) || ($has[$key] = Schema::hasColumn('courier_settings', 'notify_sms'));
    }

    /** COD payments are recorded only when enabled AND an account is chosen. */
    public function recordsCodPayments(): bool
    {
        return $this->cod_auto_record && $this->cod_account_id;
    }

    public function notifies(): bool
    {
        return $this->notify_sms || $this->notify_email || $this->notify_whatsapp;
    }

    /** @return string[] */
    public function notifyStatuses(): array
    {
        $list = is_array($this->notify_statuses) ? $this->notify_statuses : self::DEFAULT_NOTIFY;

        return array_values(array_intersect(self::NOTIFY_STATUSES, $list));
    }

    public function template(string $status): string
    {
        $custom = trim((string) (($this->notify_templates ?? [])[$status] ?? ''));

        return $custom !== '' ? $custom : (self::DEFAULT_TEMPLATES[$status] ?? '');
    }

    public function trackingPageEnabled(): bool
    {
        return self::hasPhase7Columns() ? (bool) ($this->tracking_page ?? true) : false;
    }

    public function riskHighPercent(): int
    {
        return max(1, min(100, (int) ($this->risk_high_percent ?: 30)));
    }

    public function riskMinShipments(): int
    {
        return max(1, (int) ($this->risk_min_shipments ?: 3));
    }
}
