<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management's single settings row (bk_settings). Always read it
 * through current(), which creates the row with the column defaults on
 * first use.
 */
class BookingSetting extends Model
{
    protected $table = 'bk_settings';

    /** 0 = step by the service's own length. */
    public const SLOT_STEPS = [0, 10, 15, 20, 30, 60];

    public const REMINDER_HOURS = [0, 1, 2, 3, 6, 12, 24, 48];

    public const WINDOWS = ['morning', 'afternoon', 'evening'];

    /** Deposit kinds: nothing, a fixed amount, a percentage of the price, the whole price. */
    public const DEPOSIT_TYPES = ['none', 'fixed', 'percent', 'full'];

    public const DEFAULT_TIME_WINDOWS = [
        'morning' => ['enabled' => true, 'from' => '08:00', 'to' => '12:00'],
        'afternoon' => ['enabled' => true, 'from' => '12:00', 'to' => '16:00'],
        'evening' => ['enabled' => true, 'from' => '16:00', 'to' => '20:00'],
    ];

    protected $fillable = [
        'business_types', 'slot_booking', 'request_booking', 'slot_step_minutes', 'min_notice_minutes',
        'max_days_ahead', 'hold_minutes', 'auto_confirm', 'max_open_per_customer', 'customer_cancel',
        'cancel_notice_hours', 'form_fields', 'time_windows', 'service_areas', 'notify_owner',
        'notify_owner_email', 'notify_customer', 'reminder_hours', 'whatsapp_notify', 'hero_form',
        'show_team', 'terms', 'default_warehouse_id',
        // Phase 8
        'deposit_type', 'deposit_value', 'deposit_minutes', 'deposit_auto_cancel', 'deposit_on_requests',
        'deposit_note', 'deposit_gateways', 'crm_capture', 'crm_request_deals',
    ];

    protected $casts = [
        'business_types' => 'array',
        'slot_booking' => 'boolean',
        'request_booking' => 'boolean',
        'slot_step_minutes' => 'integer',
        'min_notice_minutes' => 'integer',
        'max_days_ahead' => 'integer',
        'hold_minutes' => 'integer',
        'auto_confirm' => 'boolean',
        'max_open_per_customer' => 'integer',
        'customer_cancel' => 'boolean',
        'cancel_notice_hours' => 'integer',
        'form_fields' => 'array',
        'time_windows' => 'array',
        'service_areas' => 'array',
        'notify_owner' => 'boolean',
        'notify_customer' => 'boolean',
        'reminder_hours' => 'integer',
        'whatsapp_notify' => 'boolean',
        'hero_form' => 'boolean',
        'show_team' => 'boolean',
        'default_warehouse_id' => 'integer',
        'deposit_value' => 'float',
        'deposit_minutes' => 'integer',
        'deposit_auto_cancel' => 'boolean',
        'deposit_on_requests' => 'boolean',
        'deposit_gateways' => 'array',
        'crm_capture' => 'boolean',
        'crm_request_deals' => 'boolean',
    ];

    /** @var array<string, bool> tenant DB name → migration ran */
    private static array $ready = [];

    /** Whether migration 2026_10_25_000003 has run on the current tenant DB. */
    public static function tablesReady(): bool
    {
        $key = (string) DB::connection()->getDatabaseName();

        if (! isset(self::$ready[$key])) {
            try {
                self::$ready[$key] = Schema::hasTable('bk_settings') && Schema::hasColumn('bookings', 'public_token');
            } catch (\Throwable $e) {
                self::$ready[$key] = false;
            }
        }

        return self::$ready[$key];
    }

    public static function forgetReady(): void
    {
        self::$ready = [];
    }

    public static function current(): self
    {
        $row = static::query()->orderBy('id')->first();
        if ($row) {
            return $row;
        }
        $row = static::create([]);

        return $row->fresh() ?? $row;
    }

    /** The three request windows with defaults filled in, always in WINDOWS order. */
    public function timeWindows(): array
    {
        return self::cleanTimeWindows($this->time_windows ?? []);
    }

    /** Posted / saved windows cut down to the known keys with valid "HH:MM" times. */
    public static function cleanTimeWindows($input): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];
        foreach (self::DEFAULT_TIME_WINDOWS as $key => $def) {
            $w = is_array($input[$key] ?? null) ? $input[$key] : [];
            $from = self::hhmm($w['from'] ?? null) ?? $def['from'];
            $to = self::hhmm($w['to'] ?? null) ?? $def['to'];
            $out[$key] = [
                'enabled' => array_key_exists('enabled', $w) ? (bool) filter_var($w['enabled'], FILTER_VALIDATE_BOOLEAN) : $def['enabled'],
                'from' => $from,
                'to' => $to,
            ];
        }

        return $out;
    }

    /** Request windows that are switched on. */
    public function enabledWindows(): array
    {
        return array_filter($this->timeWindows(), fn ($w) => $w['enabled']);
    }

    private static function hhmm($v): ?string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
    }
}
