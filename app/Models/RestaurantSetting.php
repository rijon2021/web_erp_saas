<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The Restaurant module's single settings row. Always read it through
 * current(), which creates the row with the column defaults on first use.
 */
class RestaurantSetting extends Model
{
    protected $table = 'rst_settings';

    public const SERVICE_CHARGE_SCOPES = ['dine_in', 'all'];

    public const AFTER_PAYMENT_STATUSES = ['cleaning', 'free'];

    public const KITCHEN_ONLINE_ORDERS = ['off', 'on_place', 'on_accept'];

    public const ROUNDING_STEPS = [0, 0.05, 0.1, 0.25, 0.5, 1];

    public const DEFAULT_TIP_PRESETS = [5, 10, 15];

    protected $fillable = [
        'order_prefix', 'dine_in_enabled', 'takeaway_enabled', 'delivery_enabled', 'require_guest_count',
        'service_charge_percent', 'service_charge_scope', 'tips_enabled', 'tip_presets', 'bill_rounding',
        'max_discount_percent', 'default_warehouse_id', 'late_ticket_minutes', 'after_payment_table_status',
        'kitchen_online_orders', 'receipt_header', 'receipt_footer', 'menu_times',
        'reservation_minutes', 'reservation_hold_minutes', 'no_show_minutes', 'max_party_size',
        'qr_ordering_enabled', 'qr_auto_send', 'online_booking_enabled', 'booking_from', 'booking_to',
        'booking_slot_minutes', 'online_max_party', 'booking_notice_minutes', 'booking_days_ahead',
    ];

    protected $casts = [
        'dine_in_enabled' => 'boolean',
        'takeaway_enabled' => 'boolean',
        'delivery_enabled' => 'boolean',
        'require_guest_count' => 'boolean',
        'service_charge_percent' => 'float',
        'tips_enabled' => 'boolean',
        'tip_presets' => 'array',
        'bill_rounding' => 'float',
        'max_discount_percent' => 'float',
        'default_warehouse_id' => 'integer',
        'late_ticket_minutes' => 'integer',
        'menu_times' => 'array',
        'reservation_minutes' => 'integer',
        'reservation_hold_minutes' => 'integer',
        'no_show_minutes' => 'integer',
        'max_party_size' => 'integer',
        'qr_ordering_enabled' => 'boolean',
        'qr_auto_send' => 'boolean',
        'online_booking_enabled' => 'boolean',
        'booking_slot_minutes' => 'integer',
        'online_max_party' => 'integer',
        'booking_notice_minutes' => 'integer',
        'booking_days_ahead' => 'integer',
    ];

    /** Meal period windows (24 h "HH:MM"); a dish limited to periods is only offered inside them. */
    public const DEFAULT_MENU_TIMES = [
        'breakfast' => ['from' => '06:00', 'to' => '11:30'],
        'lunch' => ['from' => '11:30', 'to' => '16:00'],
        'dinner' => ['from' => '16:00', 'to' => '23:59'],
    ];

    /** @var array<string, bool> tenant DB name → table exists */
    private static array $ready = [];

    /** Whether the restaurant migrations have run on the current tenant DB. */
    public static function tablesReady(): bool
    {
        $key = (string) \DB::connection()->getDatabaseName();

        return self::$ready[$key] ??= Schema::hasTable('rst_settings');
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

    /** Tip buttons offered on the bill (percent), never empty while tips are on. */
    public function tipPresets(): array
    {
        $presets = collect($this->tip_presets ?: self::DEFAULT_TIP_PRESETS)
            ->filter(fn ($p) => is_numeric($p) && $p > 0 && $p <= 100)
            ->map(fn ($p) => round((float) $p, 2))
            ->unique()->sort()->values()->all();

        return $presets ?: self::DEFAULT_TIP_PRESETS;
    }

    /** Breakfast / lunch / dinner windows, saved values over the defaults. */
    public function menuTimes(): array
    {
        $saved = is_array($this->menu_times ?? null) ? $this->menu_times : [];
        $out = self::DEFAULT_MENU_TIMES;
        foreach ($out as $k => $v) {
            foreach (['from', 'to'] as $edge) {
                $t = $saved[$k][$edge] ?? null;
                if (is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                    $out[$k][$edge] = $t;
                }
            }
        }

        return $out;
    }

    /** Meal periods open at $time ("HH:MM", default now). A window may run past midnight. */
    public function periodsAt(?string $time = null): array
    {
        $now = $time ?? now()->format('H:i');
        $open = [];
        foreach ($this->menuTimes() as $period => $w) {
            $in = $w['from'] <= $w['to'] ? ($now >= $w['from'] && $now < $w['to']) : ($now >= $w['from'] || $now < $w['to']);
            if ($in || ($w['to'] === '23:59' && $now === '23:59')) {
                $open[] = $period;
            }
        }

        return $open;
    }

    /** Order types the restaurant takes (dine_in / takeaway / delivery). */
    public function orderTypes(): array
    {
        return array_keys(array_filter([
            'dine_in' => $this->dine_in_enabled,
            'takeaway' => $this->takeaway_enabled,
            'delivery' => $this->delivery_enabled,
        ]));
    }
}
