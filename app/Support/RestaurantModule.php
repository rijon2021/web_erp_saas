<?php

namespace App\Support;

use App\Models\RestaurantDishDetail;
use App\Models\RestaurantSetting;
use Illuminate\Support\Facades\DB;

/**
 * Whether the Restaurant module is usable on the current tenant: its plan has
 * the `restaurant` feature, the admin has not switched the module off
 * (Settings › Modules) and the migrations have run. Used by code outside the
 * module's own routes (product form, store, POS…), which the
 * tenant.feature:restaurant middleware does not cover.
 */
class RestaurantModule
{
    /** @var array<string, bool> tenant DB name → enabled (per request) */
    private static array $memo = [];

    public static function enabled(): bool
    {
        $key = (string) DB::connection()->getDatabaseName();

        return self::$memo[$key] ??= self::compute();
    }

    /** The module is on and the menu tables (phase 3) exist. */
    public static function menuReady(): bool
    {
        return self::enabled() && RestaurantDishDetail::tablesReady();
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    private static function compute(): bool
    {
        if (! RestaurantSetting::tablesReady()) {
            return false;
        }
        $raw = DB::table('settings')->whereNull('deleted_at')->value('module_flags');
        $flags = $raw ? json_decode($raw, true) : null;
        if (is_array($flags) && array_key_exists('restaurant', $flags) && $flags['restaurant'] === false) {
            return false;
        }

        return \App\Services\Crm\CrmCaptureService::planAllows('restaurant');
    }
}
